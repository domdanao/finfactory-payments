<?php

declare(strict_types=1);

namespace FinFactory\Payments\Tests;

use FinFactory\Payments\ApiObject;
use FinFactory\Payments\Collection;
use FinFactory\Payments\Exception\ConnectionException;
use FinFactory\Payments\Exception\DependencyFailureException;
use FinFactory\Payments\Exception\FinFactoryException;
use FinFactory\Payments\Exception\InProgressException;
use FinFactory\Payments\Exception\ConflictException;
use FinFactory\Payments\Exception\NotFoundException;
use FinFactory\Payments\Exception\OAuthException;
use FinFactory\Payments\Exception\RateLimitException;
use FinFactory\Payments\Exception\ValidationException;
use FinFactory\Payments\FinFactory;
use FinFactory\Payments\Http\HttpResponse;
use FinFactory\Payments\Resource\PaymentIntent;
use FinFactory\Payments\Resource\Refund;
use FinFactory\Payments\TokenCache\InMemoryTokenCache;
use PHPUnit\Framework\TestCase;

final class FinFactoryTest extends TestCase
{
    private const PI = [
        'id' => 'pi_1',
        'object' => 'payment_intent',
        'amount' => 10000,
        'amount_received' => 0,
        'currency' => 'php',
        'payment_method_types' => ['card', 'qrph'],
        'channel' => 'ecommerce',
        'status' => 'requires_action',
        'next_action' => ['type' => 'redirect_to_url', 'redirect_to_url' => ['url' => 'https://checkout.example.com/pay/x', 'return_url' => null]],
        'last_payment_error' => null,
        'cancellation_reason' => null,
        'canceled_at' => null,
        'description' => 'Order 1042',
        'metadata' => ['order_id' => '1042'],
        'livemode' => false,
        'created' => 1790411400,
    ];

    private static function token(string $value = 'at_test_1'): HttpResponse
    {
        return FakeHttpClient::json(200, ['access_token' => $value, 'token_type' => 'Bearer', 'expires_in' => 900]);
    }

    private static function client(FakeHttpClient $http, array $extra = []): FinFactory
    {
        $client = new FinFactory($extra + ['client_id' => 'ci_test_a', 'client_secret' => 'sk_test_b', 'http_client' => $http]);
        $client->setSleeper(static function (float $s): void {});
        return $client;
    }

    public function testGetsATokenThenCallsWithBearer(): void
    {
        $http = new FakeHttpClient(self::token(), FakeHttpClient::json(201, self::PI, ['x-request-id' => 'req_1']));
        $intent = self::client($http)->paymentIntents->create(['amount' => 10000, 'currency' => 'php'], ['idempotency_key' => 'order-1']);

        [$tokenCall, $createCall] = $http->requests;
        self::assertSame('https://api.payments.finfactory.dev/oauth/token', $tokenCall['url']);
        self::assertSame('Basic ' . base64_encode('ci_test_a:sk_test_b'), $tokenCall['headers']['Authorization']);
        self::assertSame('grant_type=client_credentials', $tokenCall['body']);
        self::assertSame('https://api.payments.finfactory.dev/v1/payment_intents', $createCall['url']);
        self::assertSame('Bearer at_test_1', $createCall['headers']['Authorization']);
        self::assertSame('order-1', $createCall['headers']['Idempotency-Key']);
        self::assertSame(['amount' => 10000, 'currency' => 'php'], json_decode($createCall['body'], true));

        self::assertInstanceOf(PaymentIntent::class, $intent);
        self::assertSame('pi_1', $intent->id);
        self::assertSame('pi_1', $intent['id']);
        self::assertSame('https://checkout.example.com/pay/x', $intent->redirectUrl());
        self::assertSame('https://checkout.example.com/pay/x', $intent->next_action->redirect_to_url->url);
        self::assertSame('1042', $intent->metadata->order_id);
        self::assertNull($intent->a_field_added_later);
        self::assertSame('req_1', $intent->getLastResponse()->requestId);
        self::assertSame(201, $intent->getLastResponse()->statusCode);
        self::assertSame(self::PI, $intent->toArray());
        self::assertSame(self::PI, json_decode(json_encode($intent), true));
    }

    public function testNestedObjectsAreNotGivenTheParentClass(): void
    {
        $intent = PaymentIntent::constructFrom(self::PI);
        self::assertSame(ApiObject::class, $intent->next_action::class);
    }

    public function testObjectsAreReadOnly(): void
    {
        $this->expectException(\LogicException::class);
        $intent = PaymentIntent::constructFrom(self::PI);
        $intent->status = 'succeeded';
    }

    public function testReusesTheToken(): void
    {
        $http = new FakeHttpClient(fn ($r) => str_ends_with($r['url'], '/oauth/token') ? self::token() : FakeHttpClient::json(200, self::PI));
        $client = self::client($http);
        $client->paymentIntents->retrieve('pi_1');
        $client->paymentIntents->retrieve('pi_2');
        self::assertCount(1, array_filter($http->requests, fn ($r) => str_ends_with($r['url'], '/oauth/token')));
    }

    public function testASharedTokenCacheIsReadBeforeFetching(): void
    {
        $cache = new InMemoryTokenCache();
        $http = new FakeHttpClient(fn ($r) => str_ends_with($r['url'], '/oauth/token') ? self::token() : FakeHttpClient::json(200, self::PI));
        self::client($http, ['token_cache' => $cache])->paymentIntents->retrieve('pi_1');
        self::client($http, ['token_cache' => $cache])->paymentIntents->retrieve('pi_1');
        self::assertCount(1, array_filter($http->requests, fn ($r) => str_ends_with($r['url'], '/oauth/token')));
    }

    public function testAnExpiredTokenIsReplacedAndTheCallSentAgain(): void
    {
        $http = new FakeHttpClient(
            self::token(),
            FakeHttpClient::error(401, 'authentication_failure', 'invalid_access_token'),
            self::token('at_test_2'),
            FakeHttpClient::json(200, self::PI),
        );
        self::assertSame('pi_1', self::client($http)->paymentIntents->retrieve('pi_1')->id);
        self::assertSame('Bearer at_test_2', $http->requests[3]['headers']['Authorization']);
    }

    public function testAutoIdempotencyKeyIsKeptAcrossRetriesOfA503(): void
    {
        $http = new FakeHttpClient(self::token(), FakeHttpClient::error(503, 'dependency_failure', 'service_unavailable'), FakeHttpClient::json(201, self::PI));
        $intent = self::client($http)->paymentIntents->create(['amount' => 100, 'currency' => 'php']);
        $keys = array_map(fn ($r) => $r['headers']['Idempotency-Key'], \array_slice($http->requests, 1));
        self::assertCount(2, $keys);
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $keys[0]);
        self::assertSame($keys[0], $keys[1]);
        self::assertSame($keys[0], $intent->getLastResponse()->idempotencyKey);
    }

    public function testGivesUpAfterMaxRetries(): void
    {
        $http = new FakeHttpClient(self::token(), FakeHttpClient::error(503, 'dependency_failure', 'service_unavailable'));
        try {
            self::client($http, ['max_retries' => 1])->paymentIntents->retrieve('pi_1');
            self::fail('expected an exception');
        } catch (DependencyFailureException $e) {
            self::assertSame('service_unavailable', $e->getErrorCode());
            self::assertSame('dependency_failure', $e->getType());
            self::assertSame('req_9', $e->getRequestId());
            self::assertSame(503, $e->getStatusCode());
        }
        self::assertCount(3, $http->requests);
    }

    public function testDoesNotRetryAWebhookEndpointCreate(): void
    {
        $http = new FakeHttpClient(self::token(), FakeHttpClient::error(503, 'dependency_failure', 'service_unavailable'));
        try {
            self::client($http)->webhookEndpoints->create(['url' => 'https://x.example/wh', 'enabled_events' => ['*']]);
            self::fail('expected an exception');
        } catch (DependencyFailureException) {
        }
        self::assertCount(2, $http->requests);
        self::assertArrayNotHasKey('Idempotency-Key', $http->requests[1]['headers']);
    }

    public function testValidationFailureIsNotRetried(): void
    {
        $http = new FakeHttpClient(self::token(), FakeHttpClient::error(400, 'validation_failure', 'invalid_parameter', [], 'amount'));
        try {
            self::client($http)->refunds->create(['payment_intent' => 'pi_1', 'amount' => -1]);
            self::fail('expected an exception');
        } catch (ValidationException $e) {
            self::assertSame('amount', $e->getParam());
        }
        self::assertCount(2, $http->requests);
    }

    public function testRateLimitWaitsRetryAfter(): void
    {
        $waited = [];
        $http = new FakeHttpClient(self::token(), FakeHttpClient::error(429, 'rate_limited', 'rate_limited', ['retry-after' => '3']), FakeHttpClient::json(200, self::PI));
        $client = self::client($http);
        $client->setSleeper(function (float $s) use (&$waited): void { $waited[] = $s; });
        $client->paymentIntents->retrieve('pi_1');
        self::assertSame([3.0], $waited);
    }

    public function testALongRetryAfterIsThrown(): void
    {
        $http = new FakeHttpClient(self::token(), FakeHttpClient::error(429, 'rate_limited', 'rate_limited', ['retry-after' => '120']));
        try {
            self::client($http)->paymentIntents->retrieve('pi_1');
            self::fail('expected an exception');
        } catch (RateLimitException $e) {
            self::assertSame(120.0, $e->getRetryAfter());
        }
    }

    public function testRetriesIdempotencyKeyInUseButNotOtherConflicts(): void
    {
        $http = new FakeHttpClient(self::token(), FakeHttpClient::error(409, 'timeout_or_in_progress', 'idempotency_key_in_use'), FakeHttpClient::json(200, self::PI));
        self::assertSame('pi_1', self::client($http)->paymentIntents->confirm('pi_1', ['payment_method_data' => ['type' => 'qrph']])->id);

        $http = new FakeHttpClient(self::token(), FakeHttpClient::error(409, 'conflict', 'intent_state'));
        try {
            self::client($http)->paymentIntents->cancel('pi_1');
            self::fail('expected an exception');
        } catch (ConflictException $e) {
            self::assertSame('intent_state', $e->getErrorCode());
        }
        self::assertCount(2, $http->requests);
        self::assertSame('{}', $http->requests[1]['body'], 'an empty body is sent as a JSON object');

        $http = new FakeHttpClient(self::token(), FakeHttpClient::error(409, 'timeout_or_in_progress', 'refund_in_progress'));
        $this->expectException(InProgressException::class);
        self::client($http)->refunds->create(['payment_intent' => 'pi_1']);
    }

    public function testNetworkFailureIsRetriedThenThrown(): void
    {
        $http = new FakeHttpClient(self::token(), new ConnectionException('down'));
        try {
            self::client($http, ['max_retries' => 1])->disputes->retrieve('dp_1');
            self::fail('expected an exception');
        } catch (ConnectionException) {
        }
        self::assertCount(3, $http->requests);
    }

    public function testTokenRefusalIsOAuthException(): void
    {
        $http = new FakeHttpClient(FakeHttpClient::json(401, ['error' => 'invalid_client', 'error_description' => 'Client authentication failed.']));
        try {
            self::client($http)->paymentIntents->list();
            self::fail('expected an exception');
        } catch (OAuthException $e) {
            self::assertSame('invalid_client', $e->getError());
            self::assertSame(401, $e->getStatusCode());
        }
    }

    public function testNotFound(): void
    {
        $http = new FakeHttpClient(self::token(), FakeHttpClient::error(404, 'not_found', 'resource_missing'));
        $this->expectException(NotFoundException::class);
        self::client($http)->refunds->retrieve('re_x');
    }

    public function testListAndAutoPagination(): void
    {
        $http = new FakeHttpClient(
            self::token(),
            FakeHttpClient::json(200, ['object' => 'list', 'data' => [['id' => 're_1', 'object' => 'refund'], ['id' => 're_2', 'object' => 'refund']], 'has_more' => true, 'next_cursor' => 'c2']),
            FakeHttpClient::json(200, ['object' => 'list', 'data' => [['id' => 're_3', 'object' => 'refund']], 'has_more' => false, 'next_cursor' => null]),
        );
        $ids = [];
        foreach (self::client($http)->refunds->all(['limit' => 2, 'payment_intent' => 'pi_1']) as $refund) {
            self::assertInstanceOf(Refund::class, $refund);
            $ids[] = $refund->id;
        }
        self::assertSame(['re_1', 're_2', 're_3'], $ids);
        self::assertSame('https://api.payments.finfactory.dev/v1/refunds?limit=2&payment_intent=pi_1', $http->requests[1]['url']);
        self::assertStringContainsString('cursor=c2', $http->requests[2]['url']);

        $page = FakeHttpClient::json(200, ['object' => 'list', 'data' => [['id' => 'pm_1', 'object' => 'payment_method']], 'has_more' => false, 'next_cursor' => null]);
        $list = self::client(new FakeHttpClient(self::token(), $page))->paymentMethods->list();
        self::assertInstanceOf(Collection::class, $list);
        self::assertCount(1, $list);
        self::assertFalse($list->hasMore());
        foreach ($list as $method) {
            self::assertSame('pm_1', $method->id);
        }
    }

    public function testEveryEndpointHasTheRightMethodAndPath(): void
    {
        $empty = FakeHttpClient::json(200, ['object' => 'list', 'data' => [], 'has_more' => false, 'next_cursor' => null]);
        $http = new FakeHttpClient(fn ($r) => str_ends_with($r['url'], '/oauth/token') ? self::token() : $empty);
        $client = self::client($http, ['base_url' => 'https://api.platform.example/']);
        $client->paymentIntents->list(['limit' => 5]);
        $client->paymentIntents->cancel('pi_1', ['cancellation_reason' => 'duplicate']);
        $client->paymentMethods->list();
        $client->paymentMethods->retrieve('pm_1');
        $client->refunds->retrieve('re_1');
        $client->disputes->list(['status' => 'open']);
        $client->webhookEndpoints->list();
        $client->webhookEndpoints->update('we_1', ['status' => 'enabled']);
        $client->webhookEndpoints->disable('we_1');
        $client->webhookEndpoints->rotateSecret('we_1');
        $client->testHelpers->paymentIntents->simulate('pi_1', ['outcome' => 'paid']);

        $seen = array_map(fn ($r) => $r['method'] . ' ' . substr($r['url'], \strlen('https://api.platform.example')), \array_slice($http->requests, 1));
        self::assertSame([
            'GET /v1/payment_intents?limit=5',
            'POST /v1/payment_intents/pi_1/cancel',
            'GET /v1/payment_methods',
            'GET /v1/payment_methods/pm_1',
            'GET /v1/refunds/re_1',
            'GET /v1/disputes?status=open',
            'GET /v1/webhook_endpoints',
            'POST /v1/webhook_endpoints/we_1',
            'POST /v1/webhook_endpoints/we_1/disable',
            'POST /v1/webhook_endpoints/we_1/rotate_secret',
            'POST /v1/test_helpers/payment_intents/pi_1/simulate',
        ], $seen);
        self::assertArrayHasKey('Idempotency-Key', $http->requests[11]['headers']);
        self::assertNull($http->requests[9]['body']);
    }

    public function testStaticAccessTokenSkipsOauth(): void
    {
        $http = new FakeHttpClient(FakeHttpClient::json(200, self::PI));
        (new FinFactory(['access_token' => 'at_test_static', 'http_client' => $http]))->paymentIntents->retrieve('pi_1');
        self::assertCount(1, $http->requests);
        self::assertSame('Bearer at_test_static', $http->requests[0]['headers']['Authorization']);
    }

    public function testRequiresCredentials(): void
    {
        $this->expectException(FinFactoryException::class);
        new FinFactory(['client_id' => null, 'http_client' => new FakeHttpClient(self::token())]);
    }

    public function testAnEmptyIdIsRefusedBeforeAnyRequest(): void
    {
        $http = new FakeHttpClient(self::token());
        try {
            self::client($http)->paymentIntents->retrieve('');
            self::fail('expected an exception');
        } catch (\InvalidArgumentException) {
        }
        self::assertCount(0, $http->requests);
    }
}
