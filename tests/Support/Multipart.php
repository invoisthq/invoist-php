<?php

declare(strict_types=1);

namespace Invoist\Tests\Support;

/** Parses a multipart/form-data body the way a server would, for assertions. */
final class Multipart
{
    /**
     * @return list<array{name: string, filename: string, type: string, bytes: string}>
     */
    public static function parse(string $contentType, string $body): array
    {
        if (preg_match('/boundary=(\S+)/', $contentType, $m) !== 1) {
            throw new \UnexpectedValueException("no boundary in $contentType");
        }
        $parts = [];
        $chunks = explode('--' . $m[1], $body);
        if (array_pop($chunks) !== "--\r\n") {
            throw new \UnexpectedValueException('the body does not end with the closing boundary');
        }
        foreach (\array_slice($chunks, 1) as $chunk) {
            [$head, $bytes] = explode("\r\n\r\n", substr($chunk, 2), 2);
            preg_match('/ name="([^"]*)"/', $head, $name);
            preg_match('/filename="([^"]*)"/', $head, $filename);
            preg_match('/Content-Type: (.+)/', $head, $type);
            $parts[] = [
                'name' => $name[1] ?? '',
                'filename' => $filename[1] ?? '',
                'type' => trim($type[1] ?? ''),
                'bytes' => substr($bytes, 0, -2),
            ];
        }

        return $parts;
    }
}
