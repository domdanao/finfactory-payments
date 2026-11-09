<?php

declare(strict_types=1);

namespace FinFactory\Payments\Service;

use FinFactory\Payments\Collection;
use FinFactory\Payments\Resource\PaymentIntent;

/** Payments, whatever the method. */
final class PaymentIntentService extends AbstractService
{
    /**
     * POST /v1/payment_intents. Without a method, the customer chooses one at checkout: send them
     * to $intent->redirectUrl(). Pass ['idempotency_key' => 'order-1042-1'] as $opts.
     *
     * @param array{amount: int, currency: string, description?: string, payment_method_data?: array{type: string}, payment_method_types?: list<string>, metadata?: array<string, string>, confirm?: bool, return_url?: string, cancel_url?: string, customer_email?: string, checkout_mode?: 'hosted'|'embedded', risk_signals?: array{ip_address?: string, device_id?: string, email?: string}} $params
     * @param array<string, mixed> $opts
     */
    public function create(array $params, array $opts = []): PaymentIntent
    {
        /** @var PaymentIntent */
        return $this->client->request('POST', '/v1/payment_intents', null, $params, $opts, idempotent: true, as: PaymentIntent::class);
    }

    /**
     * GET /v1/payment_intents/{id}. Read this before you fulfil an order.
     *
     * @param array<string, mixed> $opts
     */
    public function retrieve(string $id, array $opts = []): PaymentIntent
    {
        /** @var PaymentIntent */
        return $this->client->request('GET', '/v1/payment_intents/' . self::id($id, 'payment intent'), null, null, $opts, as: PaymentIntent::class);
    }

    /**
     * POST /v1/payment_intents/{id}/confirm, with the method the customer chose. return_url is required for card.
     *
     * @param array{payment_method_data?: array{type: string}, return_url?: string, risk_signals?: array<string, string>} $params
     * @param array<string, mixed> $opts
     */
    public function confirm(string $id, array $params = [], array $opts = []): PaymentIntent
    {
        /** @var PaymentIntent */
        return $this->client->request('POST', '/v1/payment_intents/' . self::id($id, 'payment intent') . '/confirm', null, $params, $opts, idempotent: true, as: PaymentIntent::class);
    }

    /**
     * POST /v1/payment_intents/{id}/cancel. Not once it is processing or succeeded.
     *
     * @param array{cancellation_reason?: 'requested_by_customer'|'duplicate'|'fraudulent'|'abandoned'} $params
     * @param array<string, mixed> $opts
     */
    public function cancel(string $id, array $params = [], array $opts = []): PaymentIntent
    {
        /** @var PaymentIntent */
        return $this->client->request('POST', '/v1/payment_intents/' . self::id($id, 'payment intent') . '/cancel', null, $params, $opts, idempotent: true, as: PaymentIntent::class);
    }

    /**
     * GET /v1/payment_intents: one page.
     *
     * @param array{limit?: int, cursor?: string} $params
     * @param array<string, mixed> $opts
     * @return Collection<PaymentIntent>
     */
    public function list(array $params = [], array $opts = []): Collection
    {
        /** @var Collection */
        return $this->client->request('GET', '/v1/payment_intents', $params, null, $opts, as: Collection::class);
    }

    /**
     * Every payment intent, page after page: foreach ($finfactory->paymentIntents->all() as $intent).
     *
     * @param array{limit?: int, cursor?: string} $params
     * @param array<string, mixed> $opts
     * @return \Generator<int, PaymentIntent>
     */
    public function all(array $params = [], array $opts = []): \Generator
    {
        return $this->client->paginate('/v1/payment_intents', $params, $opts);
    }
}
