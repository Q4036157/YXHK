<?php

declare(strict_types=1);

namespace Mautic\CoreBundle\Command;

use Mautic\CoreBundle\MailQueue\InboxPoller;
use Mautic\CoreBundle\MailQueue\QueueStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'yxhk:inbox:work', description: '运行独立的客户回复收件服务')]
final class InboxWorkerCommand extends Command
{
    public function __construct(private readonly QueueStore $queue, private readonly InboxPoller $poller)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $lock = fopen($this->queue->directory().'/inbox-worker.lock', 'c');
        if (false === $lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            $output->writeln('客户回复收件服务已有后台进程。');

            return Command::FAILURE;
        }
        try {
            while (true) {
                try {
                    $output->writeln($this->poller->pollOne());
                } catch (\Throwable $exception) {
                    $output->writeln('客户回复检查发生内部错误，1 分钟后重试。');
                }
                sleep(60);
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
