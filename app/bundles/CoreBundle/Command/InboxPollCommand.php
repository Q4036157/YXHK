<?php

declare(strict_types=1);

namespace Mautic\CoreBundle\Command;

use Mautic\CoreBundle\MailQueue\InboxPoller;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'yxhk:inbox:poll', description: '低频检查一个已启用的客户回复邮箱')]
final class InboxPollCommand extends Command
{
    public function __construct(private readonly InboxPoller $poller)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln($this->poller->pollOne());

        return Command::SUCCESS;
    }
}
