<?php

declare(strict_types=1);

namespace Mautic\CoreBundle\EventListener;

use Mautic\CoreBundle\MailQueue\SenderContext;
use Mautic\EmailBundle\EmailEvents;
use Mautic\EmailBundle\Event\EmailSendEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class QueueUnsubscribeSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly SenderContext $context)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [EmailEvents::EMAIL_ON_SEND => ['replaceUnsubscribe', -10000]];
    }

    public function replaceUnsubscribe(EmailSendEvent $event): void
    {
        if (null === $this->context->unsubscribeUrl || $event->isInternalSend()) {
            return;
        }
        $url = $this->context->unsubscribeUrl;
        $event->addTokens(['{unsubscribe_url}' => $url, '{unsubscribe_text}' => '<a href="'.htmlspecialchars($url, ENT_QUOTES).'">退订营销邮件</a>']);
    }
}
