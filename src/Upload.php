<?php

declare(strict_types=1);

namespace Invoist;

use Psr\Http\Message\StreamInterface;

/**
 * A file to upload: its bytes, a filename and a content type.
 *
 * The methods that take a file accept an Upload, a \SplFileInfo (read from
 * disk), an open stream resource, a PSR-7 stream, or a string of raw bytes.
 * Make one yourself to choose the filename Invoist shows:
 *
 * ```php
 * $invoist->extract(Upload::fromPath('scans/march.pdf'));
 * $invoist->extract(Upload::fromString($bytes, 'march.pdf'));
 * ```
 *
 * The bytes are held in memory, so a retry can send them again.
 */
final readonly class Upload
{
    private const TYPES = [
        'pdf' => 'application/pdf',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
    ];

    public function __construct(
        public string $bytes,
        public string $filename,
        public string $contentType,
    ) {
    }

    /**
     * Raw bytes. The content type is taken from the filename's extension, or
     * from the bytes themselves (PDF, PNG, JPEG) when the name says nothing.
     */
    public static function fromString(string $bytes, string $filename = 'upload', ?string $contentType = null): self
    {
        return new self($bytes, $filename, $contentType ?? self::guessType($filename, $bytes));
    }

    /**
     * A file on disk. The filename defaults to the file's own name.
     *
     * @throws \InvalidArgumentException when the file can't be read
     */
    public static function fromPath(string $path, ?string $filename = null, ?string $contentType = null): self
    {
        $bytes = is_file($path) && is_readable($path) ? file_get_contents($path) : false;
        if ($bytes === false) {
            throw new \InvalidArgumentException("Cannot read the file $path.");
        }

        return self::fromString($bytes, $filename ?? basename($path), $contentType);
    }

    /**
     * An open stream resource or a PSR-7 stream, read from its current
     * position to the end. The filename defaults to the stream's own path
     * when it has one.
     *
     * @param resource|StreamInterface $stream
     * @throws \InvalidArgumentException when the stream can't be read
     */
    public static function fromStream(mixed $stream, ?string $filename = null, ?string $contentType = null): self
    {
        if ($stream instanceof StreamInterface) {
            $uri = $stream->getMetadata('uri');

            return self::fromString(
                $stream->getContents(),
                $filename ?? (\is_string($uri) && !str_starts_with($uri, 'php://') ? basename($uri) : 'upload'),
                $contentType,
            );
        }
        if (!\is_resource($stream)) {
            throw new \InvalidArgumentException('Expected a stream resource or a PSR-7 stream.');
        }
        $bytes = stream_get_contents($stream);
        if ($bytes === false) {
            throw new \InvalidArgumentException('Cannot read the stream.');
        }
        $uri = stream_get_meta_data($stream)['uri'] ?? null;
        $name = \is_string($uri) && !str_starts_with($uri, 'php://') ? basename($uri) : 'upload';

        return self::fromString($bytes, $filename ?? $name, $contentType);
    }

    /**
     * Turns anything the upload methods accept into an Upload.
     *
     * @param Upload|\SplFileInfo|StreamInterface|resource|string $file
     * @throws \InvalidArgumentException for anything else, or a file that can't be read
     */
    public static function from(mixed $file, string $defaultName = 'upload'): self
    {
        return match (true) {
            $file instanceof self => $file,
            $file instanceof \SplFileInfo => self::fromPath($file->getPathname()),
            \is_string($file) => self::fromString($file, $defaultName),
            $file instanceof StreamInterface, \is_resource($file) => self::fromStream($file),
            default => throw new \InvalidArgumentException(
                'A file must be an Upload, an SplFileInfo, a stream or a string of bytes, not ' . get_debug_type($file) . '.',
            ),
        };
    }

    private static function guessType(string $filename, string $bytes): string
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return self::TYPES[$extension] ?? match (true) {
            str_starts_with($bytes, '%PDF-') => 'application/pdf',
            str_starts_with($bytes, "\x89PNG\r\n\x1a\n") => 'image/png',
            str_starts_with($bytes, "\xFF\xD8\xFF") => 'image/jpeg',
            default => 'application/octet-stream',
        };
    }
}
