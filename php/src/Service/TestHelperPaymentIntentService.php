<?php

declare(strict_types=1);

namespace FinFactory\Payments\Service;

use FinFactory\Payments\Resource\PaymentIntent;

final class TestHelperPaymentIntentService extends AbstractService
{
    /**
     * POST /v1/test_helpers/payment_intents/{id}/simulate: give a test payment its outcome.
     * 'paid' and 'expired' for QR Ph and cash; 'approved', 'declined', 'three_d_secure_passed',
     * 'three_d_secure_failed' and 'abandoned' for card. Test keys only.
     *
     * @param array{outcome: string, decline_reason?: 'insufficient_funds'|'do_not_honor'|'expired_card'|null} $params
     * @param array<string, mixed> $opts
     */
    public function simulate(string $id, array $params, array $opts = []): PaymentIntent
    {
        /** @var PaymentIntent */
        return $this->client->request('POST', '/v1/test_helpers/payment_intents/' . self::id($id, 'payment intent') . '/simulate', null, $params, $opts, idempotent: true, as: PaymentIntent::class);
    }
}
