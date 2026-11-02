<?php

declare(strict_types=1);

namespace Invoist;

use Invoist\Generated\Document;
use Invoist\Generated\Extraction;

/** What extractAndWait() returns: the document as it stands now, and its finished reading. */
final readonly class ExtractAndWaitResult implements \JsonSerializable
{
    public function __construct(
        public Document $document,
        /** Its status is `done` or `failed`: a failed reading is an answer, not an exception. */
        public Extraction $extraction,
    ) {
    }

    /** @return array{document: Document, extraction: Extraction} */
    public function jsonSerialize(): array
    {
        return ['document' => $this->document, 'extraction' => $this->extraction];
    }
}
