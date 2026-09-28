<?php

declare(strict_types=1);

namespace Mautic\CoreBundle\MailQueue;

final class SenderContext
{
    public ?string $profile = null;
    public int $accepted = 0;
}
