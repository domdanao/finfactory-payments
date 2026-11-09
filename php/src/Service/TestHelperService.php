<?php

declare(strict_types=1);

namespace FinFactory\Payments\Service;

use FinFactory\Payments\FinFactory;

/** Calls that only work with a test key. */
final class TestHelperService extends AbstractService
{
    public readonly TestHelperPaymentIntentService $paymentIntents;

    public function __construct(FinFactory $client)
    {
        parent::__construct($client);
        $this->paymentIntents = new TestHelperPaymentIntentService($client);
    }
}
