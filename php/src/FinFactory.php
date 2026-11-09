<?php

declare(strict_types=1);

namespace FinFactory\Payments;

use FinFactory\Payments\Exception\ApiErrorException;
use FinFactory\Payments\Exception\AuthenticationException;
use FinFactory\Payments\Exception\AuthorizationException;
use FinFactory\Payments\Exception\ConflictException;
use FinFactory\Payments\Exception\ConnectionException;
use FinFactory\Payments\Exception\DependencyFailureException;
use FinFactory\Payments\Exception\FinFactoryException;
use FinFactory\Payments\Exception\InProgressException;
use FinFactory\Payments\Exception\InvariantViolationException;
use FinFactory\Payments\Exception\NotFoundException;
use FinFactory\Payments\Exception\OAuthException;
use FinFactory\Payments\Exception\PolicyRejectionException;
use FinFactory\Payments\Exception\RateLimitException;
use FinFactory\Payments\Exception\ValidationException;
use FinFactory\Payments\Http\CurlHttpClient;
use FinFactory\Payments\Http\HttpClient;
use FinFactory\Payments\Http\HttpResponse;
use FinFactory\Payments\Service\AccessTokenService;
use FinFactory\Payments\Service\DisputeService;
use FinFactory\Payments\Service\PaymentIntentService;
use FinFactory\Payments\Service\PaymentMethodService;
use FinFactory\Payments\Service\RefundService;
use FinFactory\Payments\Service\TestHelperService;
use FinFactory\Payments\Service\WebhookEndpointService;
use FinFactory\Payments\TokenCache\InMemoryTokenCache;
use FinFactory\Payments\TokenCache\TokenCache;

/**
 * The FinFactory Payments Merchant API client.
 *
 *     $finfactory = new FinFactory([
 *         'client_id' => getenv('FINFACTORY_CLIENT_ID'),
 *         'client_secret' => getenv('FINFACTORY_CLIENT_SECRET'),
 *     ]);
 *     $intent = $finfactory->paymentIntents->create(['amount' => 10000, 'currency' => 'php']);
 *
 * Options:
 *  - client_id, client_secret: your API key. Default: FINFACTORY_CLIENT_ID, FINFACTORY_CLIENT_SECRET.
 *  - access_token: a token you manage yourself (string or callable), instead of the key.
 *  - base_url: your platform's API host. Default: FINFACTORY_BASE_URL, then https://api.payments.finfactory.dev.
 *  - timeout: seconds per attempt. Default 30.
 *  - max_retries: retries of a request that is safe to retry. Default 2.
 *  - token_cache: a TokenCache, to share tokens across requests. Default: in memory.
 *  - http_client: an HttpClient. Default: CurlHttpClient.
 *  - default_headers: headers sent on every request.
 */
final class FinFactory
{
    public const VERSION = '0.1.0';
    public const DEFAULT_BASE_URL = 'https://api.payments.finfactory.dev';

    private const TOKEN_REFRESH_MARGIN = 60;
    private const MAX_RETRY_AFTER = 60;

    private const ERROR_CLASSES = [
        'validation_failure' => ValidationException::class,
        'authentication_failure' => AuthenticationException::class,
        'authorization_failure' => AuthorizationException::class,
        'not_found' => NotFoundException::class,
        'conflict' => ConflictException::class,
        'timeout_or_in_progress' => InProgressException::class,
        'policy_rejection' => PolicyRejectionException::class,
        'invariant_violation' => InvariantViolationException::class,
        'rate_limited' => RateLimitException::class,
        'dependency_failure' => DependencyFailureException::class,
    ];

    private const STATUS_TYPES = [
        400 => 'validation_failure',
        401 => 'authentication_failure',
        403 => 'authorization_failure',
        404 => 'not_found',
        409 => 'conflict',
        422 => 'policy_rejection',
        429 => 'rate_limited',
        503 => 'dependency_failure',
    ];

    public readonly string $baseUrl;
    public readonly float $timeout;
    public readonly int $maxRetries;

    public readonly PaymentIntentService $paymentIntents;
    public readonly PaymentMethodService $paymentMethods;
    public readonly RefundService $refunds;
    public readonly DisputeService $disputes;
    public readonly WebhookEndpointService $webhookEndpoints;
    public readonly TestHelperService $testHelpers;
    public readonly AccessTokenService $accessTokens;

    private readonly ?string $clientId;
    private readonly ?string $clientSecret;
    /** @var string|callable|null */
    private $staticToken;
    private readonly HttpClient $http;
    private readonly TokenCache $tokenCache;
    /** @var array<string, string> */
    private readonly array $defaultHeaders;
    /** @var \Closure(float): void */
    private \Closure $sleeper;

    /** @param array<string, mixed> $options See the class docblock. */
    public function __construct(array $options = [])
    {
        $this->clientId = $options['client_id'] ?? self::env('FINFACTORY_CLIENT_ID');
        $this->clientSecret = $options['client_secret'] ?? self::env('FINFACTORY_CLIENT_SECRET');
        $this->staticToken = $options['access_token'] ?? null;

        if ($this->staticToken === null && ($this->clientId === null || $this->clientSecret === null)) {
            throw new FinFactoryException('FinFactory: give client_id and client_secret (or set FINFACTORY_CLIENT_ID and FINFACTORY_CLIENT_SECRET), or an access_token.');
        }

        $this->baseUrl = rtrim((string) ($options['base_url'] ?? self::env('FINFACTORY_BASE_URL') ?? self::DEFAULT_BASE_URL), '/');
        $this->timeout = (float) ($options['timeout'] ?? 30);
        $this->maxRetries = (int) ($options['max_retries'] ?? 2);
        $this->http = $options['http_client'] ?? new CurlHttpClient();
        $this->tokenCache = $options['token_cache'] ?? new InMemoryTokenCache();
        $this->defaultHeaders = $options['default_headers'] ?? [];
        $this->sleeper = static function (float $seconds): void {
            if ($seconds > 0) {
                usleep((int) ($seconds * 1_000_000));
            }
        };

        $this->paymentIntents = new PaymentIntentService($this);
        $this->paymentMethods = new PaymentMethodService($this);
        $this->refunds = new RefundService($this);
        $this->disputes = new DisputeService($this);
        $this->webhookEndpoints = new WebhookEndpointService($this);
        $this->testHelpers = new TestHelperService($this);
        $this->accessTokens = new AccessTokenService($this);
    }

    /** True for a live key (ci_live_...), false for a test key, null when unknown. */
    public function isLivemode(): ?bool
    {
        return match (true) {
            str_starts_with((string) $this->clientId, 'ci_live_') => true,
            str_starts_with((string) $this->clientId, 'ci_test_') => false,
            default => null,
        };
    }

    /** @internal For tests: replace how the SDK waits between retries. */
    public function setSleeper(\Closure $sleeper): void
    {
        $this->sleeper = $sleeper;
    }

    // -----------------------------------------------------------------------------------------
    // Access Tokens
    // -----------------------------------------------------------------------------------------

    private function tokenCacheKey(): string
    {
        return 'finfactory:access_token:' . $this->baseUrl . ':' . $this->clientId;
    }

    /** An Access Token for the next call: the cached one while it has more than a minute left, else a new one. */
    public function getAccessToken(): string
    {
        if ($this->staticToken !== null) {
            return \is_callable($this->staticToken) ? (string) ($this->staticToken)() : (string) $this->staticToken;
        }
        $cached = $this->tokenCache->get($this->tokenCacheKey());
        if ($cached !== null && $cached['expires_at'] - self::TOKEN_REFRESH_MARGIN > time()) {
            return $cached['access_token'];
        }
        $token = $this->requestAccessToken();
        $this->tokenCache->set($this->tokenCacheKey(), [
            'access_token' => (string) $token->access_token,
            'expires_at' => time() + (int) $token->expires_in,
        ]);
        return (string) $token->access_token;
    }

    /** Forget the cached token, so the next call fetches a new one. */
    public function clearAccessToken(): void
    {
        $this->tokenCache->delete($this->tokenCacheKey());
    }

    /**
     * POST /oauth/token: exchange the client ID and secret for a new Access Token. Not cached.
     *
     * @param array<string, mixed> $opts
     */
    public function requestAccessToken(array $opts = []): ApiObject
    {
        if ($this->clientId === null || $this->clientSecret === null) {
            throw new FinFactoryException('FinFactory: client_id and client_secret are needed to get an Access Token.');
        }
        $basic = base64_encode($this->clientId . ':' . $this->clientSecret);
        $response = $this->send(
            'POST',
            $this->baseUrl . '/oauth/token',
            static fn (): array => [
                'Authorization' => 'Basic ' . $basic,
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'grant_type=client_credentials',
            $opts,
            retryable: true,
            oauth: true,
        );
        return ApiObject::constructFrom(self::decode($response), self::info($response, null));
    }

    // -----------------------------------------------------------------------------------------
    // Requests
    // -----------------------------------------------------------------------------------------

    /**
     * Send any request to the API, authenticated, with retries. The services use this; you can
     * too, for an operation this SDK does not have a method for yet.
     *
     * @param array<string, mixed>|null $query
     * @param array<string, mixed>|null $body
     * @param array{idempotency_key?: string, timeout?: float, max_retries?: int, headers?: array<string, string>} $opts
     * @param bool $idempotent The operation takes an Idempotency-Key header.
     * @param bool $retryable Whether it may be sent again after a network failure or a 5xx.
     * @param class-string<ApiObject>|null $as The class to return; by default chosen from the `object` field.
     */
    public function request(string $method, string $path, ?array $query = null, ?array $body = null, array $opts = [], bool $idempotent = false, bool $retryable = true, ?string $as = null): ApiObject
    {
        $url = $this->baseUrl . $path;
        $query = array_filter($query ?? [], static fn ($v) => $v !== null);
        if ($query !== []) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        $idempotencyKey = $idempotent ? (string) ($opts['idempotency_key'] ?? self::uuid()) : null;
        $encoded = null;
        if ($body !== null) {
            $encoded = json_encode($body === [] ? new \stdClass() : $body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $response = $this->send(
            $method,
            $url,
            function () use ($encoded, $idempotencyKey): array {
                $headers = ['Authorization' => 'Bearer ' . $this->getAccessToken()];
                if ($encoded !== null) {
                    $headers['Content-Type'] = 'application/json';
                }
                if ($idempotencyKey !== null) {
                    $headers['Idempotency-Key'] = $idempotencyKey;
                }
                return $headers;
            },
            $encoded,
            $opts,
            retryable: $retryable,
        );

        return ($as ?? ApiObject::class)::constructFrom(self::decode($response), self::info($response, $idempotencyKey));
    }

    /**
     * Page through a list, one object at a time, fetching the next page as needed.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $opts
     * @return \Generator<int, ApiObject>
     */
    public function paginate(string $path, array $params = [], array $opts = []): \Generator
    {
        while (true) {
            $page = $this->request('GET', $path, $params, null, $opts);
            foreach ($page->data ?? [] as $item) {
                yield $item;
            }
            if (!$page->has_more || $page->next_cursor === null) {
                return;
            }
            $params['cursor'] = $page->next_cursor;
        }
    }

    /**
     * @param \Closure(): array<string, string> $headersFor
     * @param array<string, mixed> $opts
     */
    private function send(string $method, string $url, \Closure $headersFor, ?string $body, array $opts, bool $retryable, bool $oauth = false): HttpResponse
    {
        $maxRetries = (int) ($opts['max_retries'] ?? $this->maxRetries);
        $timeout = (float) ($opts['timeout'] ?? $this->timeout);
        $refreshedToken = false;

        for ($attempt = 0; ; $attempt++) {
            $headers = array_merge(
                ['Accept' => 'application/json', 'User-Agent' => 'finfactory-payments-php/' . self::VERSION . ' PHP/' . PHP_VERSION],
                $this->defaultHeaders,
                $headersFor(),
                $opts['headers'] ?? [],
            );

            try {
                $response = $this->http->send($method, $url, $headers, $body, $timeout);
            } catch (ConnectionException $e) {
                if ($retryable && $attempt < $maxRetries) {
                    ($this->sleeper)(self::backoff($attempt));
                    continue;
                }
                throw $e;
            }

            $status = $response->statusCode;
            if ($status >= 200 && $status < 300) {
                return $response;
            }

            if ($oauth) {
                if ($attempt < $maxRetries && ($status === 429 || $status >= 500) && ($delay = self::retryDelay($response, $attempt)) !== null) {
                    ($this->sleeper)($delay);
                    continue;
                }
                $decoded = self::decode($response);
                throw new OAuthException((string) ($decoded['error'] ?? 'unknown'), (string) ($decoded['error_description'] ?? ''), $status, $response->headers);
            }

            $error = self::apiError($response);

            // An expired or revoked token: get a new one and send the call again, once.
            if ($status === 401 && $error->getErrorCode() === 'invalid_access_token' && $this->staticToken === null && !$refreshedToken) {
                $refreshedToken = true;
                $this->clearAccessToken();
                $attempt--;
                continue;
            }

            if ($attempt < $maxRetries && self::shouldRetry($error, $retryable) && ($delay = self::retryDelay($response, $attempt)) !== null) {
                ($this->sleeper)($delay);
                continue;
            }
            throw $error;
        }
    }

    private static function shouldRetry(ApiErrorException $error, bool $retryable): bool
    {
        // Refused before any work was done: always safe to send again.
        if ($error->getStatusCode() === 429) {
            return true;
        }
        if (!$retryable) {
            return false;
        }
        if ($error->getStatusCode() >= 500) {
            return true;
        }
        // The first request with this Idempotency-Key is still running.
        return $error->getStatusCode() === 409 && $error->getErrorCode() === 'idempotency_key_in_use';
    }

    /** Seconds to wait before the next attempt, or null when Retry-After asks for too long. */
    private static function retryDelay(HttpResponse $response, int $attempt): ?float
    {
        $retryAfter = $response->header('retry-after');
        if ($retryAfter === null || !is_numeric($retryAfter)) {
            return self::backoff($attempt);
        }
        $seconds = max(0.0, (float) $retryAfter);
        return $seconds > self::MAX_RETRY_AFTER ? null : $seconds;
    }

    /** Exponential backoff with jitter: about 0.5s, 1s, 2s ... up to 8s. */
    private static function backoff(int $attempt): float
    {
        $base = min(8.0, 0.5 * (2 ** $attempt));
        return $base / 2 + (mt_rand() / mt_getrandmax()) * ($base / 2);
    }

    private static function apiError(HttpResponse $response): ApiErrorException
    {
        $decoded = self::decode($response);
        $envelope = \is_array($decoded['error'] ?? null) ? $decoded['error'] : [];
        $status = $response->statusCode;
        $type = (string) ($envelope['type'] ?? self::STATUS_TYPES[$status] ?? ($status >= 500 ? 'dependency_failure' : 'unknown'));
        $class = self::ERROR_CLASSES[$type] ?? ApiErrorException::class;

        return new $class(
            (string) ($envelope['message'] ?? "The API answered HTTP {$status}."),
            $status,
            $type,
            (string) ($envelope['code'] ?? 'unknown'),
            isset($envelope['param']) ? (string) $envelope['param'] : null,
            isset($envelope['request_id']) ? (string) $envelope['request_id'] : $response->header('x-request-id'),
            $response->headers,
            $decoded,
        );
    }

    /** @return array<string, mixed> */
    private static function decode(HttpResponse $response): array
    {
        if ($response->body === '') {
            return [];
        }
        $decoded = json_decode($response->body, true);
        return \is_array($decoded) ? $decoded : ['raw' => $response->body];
    }

    private static function info(HttpResponse $response, ?string $idempotencyKey): ResponseInfo
    {
        return new ResponseInfo(
            $response->statusCode,
            $response->headers,
            $response->header('x-request-id'),
            $response->header('idempotent-replayed') === 'true',
            $idempotencyKey,
        );
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    private static function env(string $name): ?string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);
        return \is_string($value) && $value !== '' ? $value : null;
    }
}
