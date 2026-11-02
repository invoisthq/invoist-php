<?php

declare(strict_types=1);

namespace Invoist\Tests;

use Invoist\Tests\Support\Multipart;
use Invoist\Transport\CurlTransport;
use Invoist\Transport\NetworkException;
use Invoist\Transport\TransportRequest;
use Invoist\Transport\TransportResponse;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

/** The real curl transport against PHP's built-in web server on localhost. */
#[RequiresPhpExtension('curl')]
final class CurlTransportTest extends TestCase
{
    /** @var resource|null */
    private static $server = null;

    private static string $base = '';

    public static function setUpBeforeClass(): void
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($probe);
        $address = (string) stream_socket_get_name($probe, false);
        fclose($probe);
        self::$base = "http://$address";

        $process = proc_open(
            [PHP_BINARY, '-d', 'enable_post_data_reading=0', '-S', $address, __DIR__ . '/Support/echo-server.php'],
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        self::$server = $process;
        $port = (int) explode(':', $address)[1];
        for ($i = 0; $i < 100; $i++) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.05);
            if ($socket !== false) {
                fclose($socket);

                return;
            }
            usleep(20_000);
        }
        self::fail('the built-in server did not start');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$server !== null) {
            proc_terminate(self::$server);
            proc_close(self::$server);
            self::$server = null;
        }
    }

    /** @return array<string, mixed> */
    private static function echoed(TransportResponse $response): array
    {
        $data = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        /** @var array<string, mixed> $data */
        return $data;
    }

    public function testSendsMethodHeadersAndBody(): void
    {
        $transport = new CurlTransport();
        $response = $transport->send(new TransportRequest(
            'POST',
            self::$base . '/v1/webhook-endpoints?x=1',
            ['Authorization' => 'Bearer k', 'Content-Type' => 'application/json', 'User-Agent' => 'invoist-php/test'],
            '{"url":"https://example.test"}',
            5.0,
        ));

        self::assertSame(200, $response->status);
        self::assertSame('req_echo', $response->header('X-Request-Id'));
        $echo = self::echoed($response);
        self::assertSame('POST', $echo['method']);
        self::assertSame('/v1/webhook-endpoints?x=1', $echo['uri']);
        self::assertSame(base64_encode('{"url":"https://example.test"}'), $echo['body']);
        self::assertIsArray($echo['headers']);
        self::assertSame('Bearer k', $echo['headers']['authorization']);
        self::assertSame('invoist-php/test', $echo['headers']['user-agent']);

        // The handle is reused: a second request on the same transport works too.
        $again = self::echoed($transport->send(new TransportRequest('DELETE', self::$base . '/x', [], null, 5.0)));
        self::assertSame('DELETE', $again['method']);
        self::assertSame('', $again['body']);
        self::assertIsArray($again['headers']);
        self::assertArrayNotHasKey('authorization', $again['headers']);
    }

    public function testAMultipartUploadArrivesAsFiles(): void
    {
        $boundary = 'b0undary';
        $body = "--$boundary\r\nContent-Disposition: form-data; name=\"files\"; filename=\"a.pdf\"\r\nContent-Type: application/pdf\r\n\r\n%PDF-a\r\n"
            . "--$boundary\r\nContent-Disposition: form-data; name=\"files\"; filename=\"b.png\"\r\nContent-Type: image/png\r\n\r\n\x89PNG\x00\r\n"
            . "--$boundary--\r\n";
        $echo = self::echoed((new CurlTransport())->send(new TransportRequest(
            'POST',
            self::$base . '/v1/extract/batch',
            ['Content-Type' => "multipart/form-data; boundary=$boundary"],
            $body,
            5.0,
        )));

        self::assertIsString($echo['body']);
        self::assertIsArray($echo['headers']);
        self::assertSame("multipart/form-data; boundary=$boundary", $echo['headers']['content-type']);
        self::assertSame($body, base64_decode($echo['body'], true));
        self::assertSame(['a.pdf', 'b.png'], array_column(Multipart::parse("boundary=$boundary", $body), 'filename'));
    }

    public function testBinaryBodiesAndErrorStatusesComeBackAsTheyAre(): void
    {
        $transport = new CurlTransport();
        $file = $transport->send(new TransportRequest('GET', self::$base . '/file', [], null, 5.0));
        self::assertSame("%PDF-1.4\x00\x01\xff", $file->body);
        self::assertSame('application/pdf', $file->header('content-type'));
        self::assertSame('attachment; filename="bill.pdf"', $file->header('Content-Disposition'));

        $error = $transport->send(new TransportRequest('GET', self::$base . '/error', [], null, 5.0));
        self::assertSame(429, $error->status);
        self::assertSame('0', $error->header('retry-after'));
        self::assertSame('{"error":{"code":"rate_limited","message":"too many requests"}}', $error->body);

        $empty = $transport->send(new TransportRequest('DELETE', self::$base . '/empty', [], null, 5.0));
        self::assertSame(204, $empty->status);
        self::assertSame('', $empty->body);
    }

    public function testATimeoutIsReportedAsOne(): void
    {
        try {
            (new CurlTransport())->send(new TransportRequest('GET', self::$base . '/slow', [], null, 0.3));
            self::fail('expected a NetworkException');
        } catch (NetworkException $e) {
            self::assertTrue($e->timedOut);
        }
    }

    public function testARefusedConnectionIsANetworkException(): void
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($probe);
        $address = (string) stream_socket_get_name($probe, false);
        fclose($probe);
        try {
            (new CurlTransport())->send(new TransportRequest('GET', "http://$address/", [], null, 2.0));
            self::fail('expected a NetworkException');
        } catch (NetworkException $e) {
            self::assertFalse($e->timedOut);
        }
    }
}
