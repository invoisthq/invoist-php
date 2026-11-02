<?php

declare(strict_types=1);

namespace Invoist\Transport;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Sends requests through any PSR-18 HTTP client (Guzzle, Symfony
 * HttpClient, a framework's own), built with PSR-17 factories.
 *
 * PSR-18 has no per-request timeout, so the client's own configuration
 * decides it; set one there. A timeout is recognised from the exception's
 * message, so it may surface as `network_error` rather than `timeout` with
 * some clients.
 */
final readonly class Psr18Transport implements Transport
{
    public function __construct(
        private ClientInterface $client,
        private RequestFactoryInterface $requestFactory,
        private StreamFactoryInterface $streamFactory,
    ) {
    }

    public function send(TransportRequest $request): TransportResponse
    {
        $psrRequest = $this->requestFactory->createRequest($request->method, $request->url);
        foreach ($request->headers as $name => $value) {
            $psrRequest = $psrRequest->withHeader($name, $value);
        }
        if ($request->body !== null) {
            $psrRequest = $psrRequest->withBody($this->streamFactory->createStream($request->body));
        }

        try {
            $response = $this->client->sendRequest($psrRequest);
        } catch (ClientExceptionInterface $e) {
            $timedOut = preg_match('/timed? ?out|timeout/i', $e->getMessage()) === 1;

            throw new NetworkException($e->getMessage(), $timedOut, $e);
        }

        $headers = [];
        foreach ($response->getHeaders() as $name => $values) {
            $headers[strtolower((string) $name)] = implode(', ', $values);
        }

        return new TransportResponse($response->getStatusCode(), $headers, (string) $response->getBody());
    }
}
