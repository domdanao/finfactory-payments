<?php

declare(strict_types=1);

namespace FinFactory\Payments\Service;

use FinFactory\Payments\Collection;
use FinFactory\Payments\Resource\WebhookEndpoint;

/** HTTPS addresses events are sent to. */
final class WebhookEndpointService extends AbstractService
{
    /**
     * POST /v1/webhook_endpoints. The answer's secret is shown here only: store it.
     *
     * This call takes no Idempotency-Key, so the SDK does not retry it after a network failure or a
     * 5xx: a second create would make a second endpoint. If it fails that way, list() your
     * endpoints, and rotateSecret() the one that was made to read its secret.
     *
     * @param array{url: string, enabled_events: list<string>} $params
     * @param array<string, mixed> $opts
     */
    public function create(array $params, array $opts = []): WebhookEndpoint
    {
        /** @var WebhookEndpoint */
        return $this->client->request('POST', '/v1/webhook_endpoints', null, $params, $opts, retryable: false, as: WebhookEndpoint::class);
    }

    /**
     * @param array{limit?: int, cursor?: string} $params
     * @param array<string, mixed> $opts
     * @return Collection<WebhookEndpoint>
     */
    public function list(array $params = [], array $opts = []): Collection
    {
        /** @var Collection */
        return $this->client->request('GET', '/v1/webhook_endpoints', $params, null, $opts, as: Collection::class);
    }

    /**
     * @param array{limit?: int, cursor?: string} $params
     * @param array<string, mixed> $opts
     * @return \Generator<int, WebhookEndpoint>
     */
    public function all(array $params = [], array $opts = []): \Generator
    {
        return $this->client->paginate('/v1/webhook_endpoints', $params, $opts);
    }

    /**
     * POST /v1/webhook_endpoints/{id}. Set status 'enabled' to turn a disabled endpoint back on.
     *
     * @param array{url?: string, enabled_events?: list<string>, status?: 'enabled'|'disabled'} $params
     * @param array<string, mixed> $opts
     */
    public function update(string $id, array $params, array $opts = []): WebhookEndpoint
    {
        /** @var WebhookEndpoint */
        return $this->client->request('POST', '/v1/webhook_endpoints/' . self::id($id, 'webhook endpoint'), null, $params, $opts, as: WebhookEndpoint::class);
    }

    /** @param array<string, mixed> $opts */
    public function disable(string $id, array $opts = []): WebhookEndpoint
    {
        /** @var WebhookEndpoint */
        return $this->client->request('POST', '/v1/webhook_endpoints/' . self::id($id, 'webhook endpoint') . '/disable', null, null, $opts, as: WebhookEndpoint::class);
    }

    /**
     * POST /v1/webhook_endpoints/{id}/rotate_secret. The new secret is in this answer only. For 24
     * hours deliveries are signed with both: verify with [$newSecret, $oldSecret].
     *
     * @param array<string, mixed> $opts
     */
    public function rotateSecret(string $id, array $opts = []): WebhookEndpoint
    {
        /** @var WebhookEndpoint */
        return $this->client->request('POST', '/v1/webhook_endpoints/' . self::id($id, 'webhook endpoint') . '/rotate_secret', null, null, $opts, as: WebhookEndpoint::class);
    }
}
