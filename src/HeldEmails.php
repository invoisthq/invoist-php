<?php

declare(strict_types=1);

namespace Invoist;

use Invoist\Generated\HeldEmail;
use Invoist\Generated\ListHeldEmailsResponse;
use Invoist\Internal\Http;

/**
 * Bills forwarded to a household's address that wait for a person to
 * approve their sender, or for a plan, as `$invoist->heldEmails`. Read-only:
 * approving a sender is done in the app.
 */
final readonly class HeldEmails
{
    /** @internal the client makes this; use $invoist->heldEmails */
    public function __construct(private Http $http)
    {
    }

    /**
     * The waiting emails, oldest first.
     *
     * @return list<HeldEmail>
     * @throws InvoistError
     */
    public function list(): array
    {
        return $this->http->json('GET', '/v1/held-emails', ListHeldEmailsResponse::fromArray(...))->held_emails;
    }
}
