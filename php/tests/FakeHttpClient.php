<?php

declare(strict_types=1);

namespace FinFactory\Payments\Tests;

use FinFactory\Payments\Http\HttpClient;
use FinFactory\Payments\Http\HttpResponse;

/** Answers from a queue (the last answer repeats, or a callable decides), and records each request. */
final class FakeHttpClient implements HttpClient
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string}> */
    public array $requests = [];

    /** @var list<HttpResponse|\Throwable|callable> */
    private array $queue;

    public function __construct(HttpResponse|\Throwable|callable ...$responses)
    {
        $this->queue = $responses;
    }

    public function send(string $method, string $url, array $headers, ?string $body, float $timeout): HttpResponse
    {
        $request = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
        $this->requests[] = $request;
        $next = \count($this->queue) > 1 ? array_shift($this->queue) : $this->queue[0];
        if (\is_callable($next)) {
            $next = $next($request);
        }
        if ($next instanceof \Throwable) {
            throw $next;
        }
        return $next;
    }

    /** @param array<string, mixed> $body */
    public static function json(int $status, array $body, array $headers = []): HttpResponse
    {
        return new HttpResponse($status, $headers + ['content-type' => 'application/json'], json_encode($body));
    }

    public static function error(int $status, string $type, string $code, array $headers = [], ?string $param = null): HttpResponse
    {
        return self::json($status, ['error' => ['type' => $type, 'code' => $code, 'message' => "{$code}!", 'param' => $param, 'request_id' => 'req_9']], $headers);
    }
}
