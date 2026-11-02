<?php

declare(strict_types=1);

namespace Invoist\Tests\Support;

use Invoist\Transport\NetworkException;
use Invoist\Transport\Transport;
use Invoist\Transport\TransportRequest;
use Invoist\Transport\TransportResponse;

/** Replays queued responses (or network failures) in order and records every request. */
final class FakeTransport implements Transport
{
    /** @var list<TransportRequest> */
    public array $requests = [];

    /** @var list<TransportResponse|NetworkException> */
    private array $queue;

    public function __construct(TransportResponse|NetworkException ...$queue)
    {
        $this->queue = array_values($queue);
    }

    /**
     * A response with a JSON body.
     *
     * @param array<string, string> $headers
     */
    public static function json(int $status, mixed $body, array $headers = []): TransportResponse
    {
        return new TransportResponse($status, $headers + ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
    }

    public function push(TransportResponse|NetworkException ...$responses): void
    {
        array_push($this->queue, ...$responses);
    }

    public function send(TransportRequest $request): TransportResponse
    {
        $this->requests[] = $request;
        $next = array_shift($this->queue) ?? throw new \LogicException("FakeTransport: no response queued for {$request->method} {$request->url}");
        if ($next instanceof NetworkException) {
            throw $next;
        }

        return $next;
    }

    public function last(): TransportRequest
    {
        return $this->requests[array_key_last($this->requests) ?? throw new \LogicException('no requests')];
    }
}
