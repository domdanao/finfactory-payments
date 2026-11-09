<?php

declare(strict_types=1);

namespace FinFactory\Payments\Http;

use FinFactory\Payments\Exception\ConnectionException;
use FinFactory\Payments\Exception\TimeoutException;

/** The default HttpClient, on ext-curl. Reuses one handle so connections are kept alive. */
final class CurlHttpClient implements HttpClient
{
    private ?\CurlHandle $handle = null;

    /** @param array<int, mixed> $curlOptions Extra CURLOPT_* options, such as a proxy. */
    public function __construct(
        private readonly float $connectTimeout = 10.0,
        private readonly array $curlOptions = [],
    ) {
        if (!\extension_loaded('curl')) {
            throw new \LogicException('FinFactory: the curl extension is required, or pass your own HttpClient.');
        }
    }

    public function send(string $method, string $url, array $headers, ?string $body, float $timeout): HttpResponse
    {
        $this->handle ??= curl_init();
        $ch = $this->handle;
        curl_reset($ch);

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }
        $headerLines[] = 'Expect:';

        $responseHeaders = [];
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT_MS => (int) ceil($timeout * 1000),
            CURLOPT_CONNECTTIMEOUT_MS => (int) ceil(min($timeout, $this->connectTimeout) * 1000),
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (\count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return \strlen($line);
            },
        ] + $this->curlOptions;

        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        } elseif ($method !== 'GET') {
            $options[CURLOPT_POSTFIELDS] = '';
        }

        curl_setopt_array($ch, $options);
        $result = curl_exec($ch);

        if ($result === false) {
            $errno = curl_errno($ch);
            $message = sprintf('Could not reach the API: %s %s: %s', $method, $url, curl_error($ch));
            if ($errno === CURLE_OPERATION_TIMEDOUT) {
                throw new TimeoutException($message, $errno);
            }
            throw new ConnectionException($message, $errno);
        }

        return new HttpResponse((int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), $responseHeaders, (string) $result);
    }
}
