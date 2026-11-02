<?php

declare(strict_types=1);

namespace Invoist;

/** A downloaded original: its bytes exactly as stored, and what they are. */
final readonly class DownloadedFile
{
    public function __construct(
        /** The file's bytes, exactly as uploaded. */
        public string $bytes,
        /** The stored content type, e.g. `application/pdf`. */
        public string $contentType,
        /** The filename from Content-Disposition, when the API sent one. */
        public ?string $filename = null,
    ) {
    }

    /**
     * Writes the bytes to a file.
     *
     * @throws \RuntimeException when the file can't be written
     */
    public function saveTo(string $path): void
    {
        if (file_put_contents($path, $this->bytes) !== \strlen($this->bytes)) {
            throw new \RuntimeException("Cannot write $path.");
        }
    }

    public function size(): int
    {
        return \strlen($this->bytes);
    }

    /** @internal builds the value from a response's headers */
    public static function fromResponse(string $bytes, ?string $contentType, ?string $disposition): self
    {
        $filename = null;
        if ($disposition !== null) {
            if (preg_match("/filename\\*\\s*=\\s*UTF-8''([^;]+)/i", $disposition, $m) === 1) {
                $filename = rawurldecode(trim($m[1]));
            } elseif (preg_match('/filename\s*=\s*"((?:[^"\\\\]|\\\\.)*)"/i', $disposition, $m) === 1) {
                $filename = stripslashes($m[1]);
            } elseif (preg_match('/filename\s*=\s*([^;\s]+)/i', $disposition, $m) === 1) {
                $filename = $m[1];
            }
        }

        return new self($bytes, $contentType ?? 'application/octet-stream', $filename !== '' ? $filename : null);
    }
}
