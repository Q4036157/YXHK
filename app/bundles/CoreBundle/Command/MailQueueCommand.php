<?php

declare(strict_types=1);

namespace Mautic\CoreBundle\Command;

use Mautic\CoreBundle\MailQueue\QueueService;
use Mautic\CoreBundle\MailQueue\QueueStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'yxhk:mail-queue:work', description: '运行邮件轮询队列')]
final class MailQueueCommand extends Command
{
    public function __construct(private readonly QueueStore $store, private readonly QueueService $queue)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $lock = fopen($this->store->directory().'/worker.lock', 'c');
        if (false === $lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            $output->writeln('邮件队列已有后台进程。');

            return Command::FAILURE;
        }
        try {
            $this->queue->recover();
            while (true) {
                $this->queue->tick(time());
                sleep(1);
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
