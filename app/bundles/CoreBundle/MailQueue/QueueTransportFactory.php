<?php

declare(strict_types=1);

namespace Mautic\CoreBundle\MailQueue;

use Symfony\Component\Mailer\Transport\AbstractTransportFactory;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\TransportInterface;

final class QueueTransportFactory extends AbstractTransportFactory
{
    public function __construct(private readonly QueueStore $store, private readonly SenderContext $context,
        private readonly UnsubscribeClient $unsubscribe)
    {
        parent::__construct();
    }

    public function create(Dsn $dsn): TransportInterface
    {
        return new QueueTransport($this->store, $this->context, $this->unsubscribe);
    }

    protected function getSupportedSchemes(): array
    {
        return ['yxhk'];
    }
}
