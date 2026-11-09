<?php

declare(strict_types=1);

namespace FinFactory\Payments\Service;

use FinFactory\Payments\Collection;
use FinFactory\Payments\Resource\Dispute;

/** Chargebacks on card payments. Read-only: respond from the Merchant Dashboard. */
final class DisputeService extends AbstractService
{
    /** @param array<string, mixed> $opts */
    public function retrieve(string $id, array $opts = []): Dispute
    {
        /** @var Dispute */
        return $this->client->request('GET', '/v1/disputes/' . self::id($id, 'dispute'), null, null, $opts, as: Dispute::class);
    }

    /**
     * GET /v1/disputes: one page, by payment_intent or status if you like.
     *
     * @param array{limit?: int, cursor?: string, payment_intent?: string, status?: 'open'|'under_review'|'won'|'lost'|'accepted'} $params
     * @param array<string, mixed> $opts
     * @return Collection<Dispute>
     */
    public function list(array $params = [], array $opts = []): Collection
    {
        /** @var Collection */
        return $this->client->request('GET', '/v1/disputes', $params, null, $opts, as: Collection::class);
    }

    /**
     * @param array{limit?: int, cursor?: string, payment_intent?: string, status?: string} $params
     * @param array<string, mixed> $opts
     * @return \Generator<int, Dispute>
     */
    public function all(array $params = [], array $opts = []): \Generator
    {
        return $this->client->paginate('/v1/disputes', $params, $opts);
    }
}
