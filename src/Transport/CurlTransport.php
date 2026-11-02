<?php

declare(strict_types=1);

namespace Invoist\Transport;

/**
 * The default transport: ext-curl, and nothing else.
 *
 * One curl handle is reused across requests, so consecutive calls share a
 * kept-alive connection. Redirects are not followed and only http(s) URLs
 * are allowed. Responses are decompressed when the server compresses them.
 */
final class CurlTransport implements Transport
{
    private ?\CurlHandle $handle = null;

    public function __construct()
    {
        if (!\extension_loaded('curl')) {
            throw new \LogicException(
                'The Invoist SDK needs the curl extension, or a PSR-18 client passed as httpClient.',
            );
        }
    }

    public function send(TransportRequest $request): TransportResponse
    {
        $handle = $this->handle ??= curl_init();
        curl_reset($handle);

        $headers = [];
        $headerLines = ['Expect:']; // no 100-continue round trip before an upload
        foreach ($request->headers as $name => $value) {
            $headerLines[] = "$name: $value";
        }

        $options = [
            CURLOPT_URL => $request->url,
            CURLOPT_CUSTOMREQUEST => $request->method,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_ENCODING => '',
            CURLOPT_TIMEOUT_MS => max(1, (int) ceil($request->timeout * 1000)),
            CURLOPT_HEADERFUNCTION => static function (\CurlHandle $_, string $line) use (&$headers): int {
                if (str_starts_with($line, 'HTTP/')) {
                    $headers = []; // a new response (after 100 Continue): forget the last one's headers
                } elseif (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $name = strtolower(trim($name));
                    $value = trim($value);
                    $headers[$name] = isset($headers[$name]) ? $headers[$name] . ', ' . $value : $value;
                }

                return \strlen($line);
            },
        ];
        if ($request->body !== null) {
            $options[CURLOPT_POSTFIELDS] = $request->body;
        } elseif ($request->method === 'POST') {
            $options[CURLOPT_POSTFIELDS] = ''; // sends Content-Length: 0
        }
        curl_setopt_array($handle, $options);

        $body = curl_exec($handle);
        $errno = curl_errno($handle);
        if ($errno !== 0 || !\is_string($body)) {
            $message = curl_error($handle);
            $this->handle = null; // don't reuse a connection in an unknown state

            throw new NetworkException(
                $message !== '' ? $message : 'The request failed without a response.',
                $errno === CURLE_OPERATION_TIMEDOUT,
            );
        }

        return new TransportResponse(curl_getinfo($handle, CURLINFO_RESPONSE_CODE), $headers, $body);
    }
}
