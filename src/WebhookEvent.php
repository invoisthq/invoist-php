<?php

declare(strict_types=1);

namespace Invoist;

/**
 * A verified webhook event, as Webhooks::verify() returns it.
 *
 * $type is one of the Webhooks event constants (more may be added: ignore
 * types you don't handle). $data depends on the type; for extraction events
 * it carries `document_id` and `status`.
 */
final readonly class WebhookEvent implements \JsonSerializable
{
    /** @param array<string, mixed> $data */
    public function __construct(
        /** A unique id; the same event delivered twice keeps it, so use it to ignore repeats. */
        public string $id,
        /** The event type, e.g. `extraction.completed`. */
        public string $type,
        /** When the event happened, RFC 3339 in UTC. */
        public string $created_at,
        /** The event's own fields. */
        public array $data,
    ) {
    }

    /**
     * @param array<array-key, mixed> $data
     * @throws \UnexpectedValueException when a field is missing or has the wrong type
     */
    public static function fromArray(array $data): self
    {
        $d = new Internal\Decode($data, 'WebhookEvent');

        return new self($d->string('id'), $d->string('type'), $d->string('created_at'), $d->optMap('data') ?? []);
    }

    /** @return array{id: string, type: string, created_at: string, data: array<string, mixed>} */
    public function jsonSerialize(): array
    {
        return ['id' => $this->id, 'type' => $this->type, 'created_at' => $this->created_at, 'data' => $this->data];
    }
}
