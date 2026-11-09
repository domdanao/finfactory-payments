<?php

declare(strict_types=1);

namespace FinFactory\Payments\Service;

use FinFactory\Payments\Collection;
use FinFactory\Payments\Resource\Refund;

/** Return money from a payment that succeeded, in full or in part. */
final class RefundService extends AbstractService
{
    /**
     * POST /v1/refunds. Leave amount out to refund what remains. Pass ['idempotency_key' => ...] as $opts.
     *
     * @param array{payment_intent: string, amount?: int, reason?: 'requested_by_customer'|'duplicate'|'fraudulent', metadata?: array<string, string>} $params
     * @param array<string, mixed> $opts
     */
    public function create(array $params, array $opts = []): Refund
    {
        /** @var Refund */
        return $this->client->request('POST', '/v1/refunds', null, $params, $opts, idempotent: true, as: Refund::class);
    }

    /** @param array<string, mixed> $opts */
    public function retrieve(string $id, array $opts = []): Refund
    {
        /** @var Refund */
        return $this->client->request('GET', '/v1/refunds/' . self::id($id, 'refund'), null, null, $opts, as: Refund::class);
    }

    /**
     * GET /v1/refunds: one page. Pass payment_intent for one payment's refunds.
     *
     * @param array{limit?: int, cursor?: string, payment_intent?: string} $params
     * @param array<string, mixed> $opts
     * @return Collection<Refund>
     */
    public function list(array $params = [], array $opts = []): Collection
    {
        /** @var Collection */
        return $this->client->request('GET', '/v1/refunds', $params, null, $opts, as: Collection::class);
    }

    /**
     * @param array{limit?: int, cursor?: string, payment_intent?: string} $params
     * @param array<string, mixed> $opts
     * @return \Generator<int, Refund>
     */
    public function all(array $params = [], array $opts = []): \Generator
    {
        return $this->client->paginate('/v1/refunds', $params, $opts);
    }
}
