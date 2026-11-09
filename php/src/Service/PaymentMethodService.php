<?php

declare(strict_types=1);

namespace FinFactory\Payments\Service;

use FinFactory\Payments\Collection;
use FinFactory\Payments\Resource\PaymentMethod;

/** What your account can take, and why a method cannot take a payment now. */
final class PaymentMethodService extends AbstractService
{
    /**
     * GET /v1/payment_methods: one page.
     *
     * @param array{limit?: int, cursor?: string} $params
     * @param array<string, mixed> $opts
     * @return Collection<PaymentMethod>
     */
    public function list(array $params = [], array $opts = []): Collection
    {
        /** @var Collection */
        return $this->client->request('GET', '/v1/payment_methods', $params, null, $opts, as: Collection::class);
    }

    /**
     * @param array{limit?: int, cursor?: string} $params
     * @param array<string, mixed> $opts
     * @return \Generator<int, PaymentMethod>
     */
    public function all(array $params = [], array $opts = []): \Generator
    {
        return $this->client->paginate('/v1/payment_methods', $params, $opts);
    }

    /** @param array<string, mixed> $opts */
    public function retrieve(string $id, array $opts = []): PaymentMethod
    {
        /** @var PaymentMethod */
        return $this->client->request('GET', '/v1/payment_methods/' . self::id($id, 'payment method'), null, null, $opts, as: PaymentMethod::class);
    }
}
