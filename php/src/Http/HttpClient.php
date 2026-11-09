<?php

declare(strict_types=1);

namespace FinFactory\Payments\Http;

use FinFactory\Payments\Exception\ConnectionException;

/**
 * Sends one HTTP request. The SDK ships CurlHttpClient; implement this to use Guzzle, a PSR-18
 * client, or a fake in your tests.
 */
interface HttpClient
{
    /**
     * @param array<string, string> $headers
     * @param float $timeout Seconds.
     *
     * @throws ConnectionException When no answer was received (TimeoutException on a timeout).
     */
    public function send(string $method, string $url, array $headers, ?string $body, float $timeout): HttpResponse;
}
