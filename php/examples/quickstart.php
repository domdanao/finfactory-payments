<?php

// End to end in test mode: a QR Ph payment and a card payment with a refund.
//
//   FINFACTORY_CLIENT_ID=ci_test_... FINFACTORY_CLIENT_SECRET=sk_test_... php examples/quickstart.php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use FinFactory\Payments\Exception\ApiErrorException;
use FinFactory\Payments\FinFactory;

$finfactory = new FinFactory();
if ($finfactory->isLivemode() !== false) {
    fwrite(STDERR, "Use a test key (ci_test_...): this example simulates payments.\n");
    exit(1);
}

$run = 'quickstart-' . time();

try {
    // 1. QR Ph: create and confirm in one call, then play the customer.
    $qr = $finfactory->paymentIntents->create([
        'amount' => 10000,
        'currency' => 'php',
        'description' => 'Quickstart QR Ph',
        'payment_method_data' => ['type' => 'qrph'],
        'confirm' => true,
        'metadata' => ['run' => $run],
    ], ['idempotency_key' => "{$run}-qr"]);
    echo "QR Ph intent {$qr->id} {$qr->status} {$qr->next_action?->type}\n";

    $paid = $finfactory->testHelpers->paymentIntents->simulate($qr->id, ['outcome' => 'paid'], ['idempotency_key' => "{$run}-qr-paid"]);
    echo "after simulate: {$paid->status}, received {$paid->amount_received}\n";

    // 2. Card: confirm with a return_url, approve it, then refund it in full.
    $card = $finfactory->paymentIntents->create([
        'amount' => 25000,
        'currency' => 'php',
        'description' => 'Quickstart card',
        'payment_method_data' => ['type' => 'card'],
        'confirm' => true,
        'return_url' => 'https://shop.example.com/return',
    ], ['idempotency_key' => "{$run}-card"]);
    echo "card intent {$card->id} {$card->status} {$card->next_action?->type}\n";

    $finfactory->testHelpers->paymentIntents->simulate($card->id, ['outcome' => 'approved'], ['idempotency_key' => "{$run}-card-approved"]);
    echo 'card status: ', $finfactory->paymentIntents->retrieve($card->id)->status, "\n";

    $refund = $finfactory->refunds->create(['payment_intent' => $card->id, 'reason' => 'requested_by_customer'], ['idempotency_key' => "{$run}-refund"]);
    echo "refund {$refund->id} {$refund->status} {$refund->amount}\n";

    // 3. The five most recent intents.
    foreach ($finfactory->paymentIntents->list(['limit' => 5]) as $pi) {
        echo "  {$pi->id} {$pi->status} {$pi->amount} {$pi->currency}\n";
    }
} catch (ApiErrorException $e) {
    fwrite(STDERR, sprintf("%s: %s/%s param=%s request=%s\n%s\n", $e::class, $e->getType(), $e->getErrorCode(), $e->getParam() ?? '-', $e->getRequestId() ?? '-', $e->getMessage()));
    exit(1);
}
