<?php

declare(strict_types=1);

namespace Mautic\CoreBundle\MailQueue;

final class SenderContext
{
    public ?string $profile = null;
    public int $accepted = 0;
    public ?string $recipient = null;
    public ?string $unsubscribeUrl = null;
    public bool $unsubscribed = false;
    public bool $suppressed = false;
    public bool $unsubscribeUnavailable = false;
}
