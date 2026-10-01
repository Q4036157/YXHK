<?php

declare(strict_types=1);

namespace Mautic\CoreBundle\Command;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;
use Mautic\CoreBundle\MailQueue\QueueService;
use Mautic\CoreBundle\MailQueue\QueueStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'yxhk:mail-queue:control', description: '查看及控制邮件批次，不直接投递邮件')]
final class MailQueueControlCommand extends Command
{
    public function __construct(private readonly QueueStore $store, private readonly QueueService $queue,
        private readonly ManagerRegistry $doctrine)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('action', InputArgument::OPTIONAL, 'status, start, pause, stop, interval, suppress', 'status')
            ->addOption('job', null, InputOption::VALUE_REQUIRED, '批次 ID')
            ->addOption('email', null, InputOption::VALUE_REQUIRED, '已确认退信的收件地址')
            ->addOption('reason', null, InputOption::VALUE_REQUIRED, '退信原因')
            ->addOption('seconds', null, InputOption::VALUE_REQUIRED, '发送间隔（秒）')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, '本次最多处理的收件记录数，仅 start 可用')
            ->addOption('recipients', null, InputOption::VALUE_NONE, '包含全部收件明细与打开追踪');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $action = (string) $input->getArgument('action');
        try {
            if (in_array($action, ['start', 'pause', 'stop'], true)) {
                $job = (string) $input->getOption('job');
                if ('' === $job) {
                    throw new \InvalidArgumentException('开始、暂停、停止时必须提供 --job。');
                }
                $limit = $input->getOption('limit');
                if (null !== $limit && 'start' !== $action) {
                    throw new \InvalidArgumentException('--limit 仅用于 start。');
                }
                $count = null === $limit ? null : filter_var($limit, FILTER_VALIDATE_INT);
                if (false === $count) {
                    throw new \InvalidArgumentException('--limit 必须是整数。');
                }
                $this->queue->action($job, $action, $count);
            } elseif ('interval' === $action) {
                $seconds = filter_var($input->getOption('seconds'), FILTER_VALIDATE_INT);
                if (false === $seconds || $seconds < 1 || $seconds > 86400) {
                    throw new \InvalidArgumentException('请用 --seconds 指定 1 至 86400 秒。');
                }
                $this->store->transaction(static function (array &$state) use ($seconds): void {
                    $state['interval'] = $seconds;
                });
            } elseif ('suppress' === $action) {
                $email = (string) $input->getOption('email');
                $reason = (string) ($input->getOption('reason') ?: '收件地址不存在或不正确');
                $this->store->suppress($email, $reason, '管理员确认的退信');
            } elseif ('status' !== $action) {
                throw new \InvalidArgumentException('未知操作。');
            }
            $state = $this->store->state();
            $profiles = $this->store->profiles();
            $jobs = [];
            foreach (array_reverse($state['jobs'], true) as $id => $job) {
                if ($input->getOption('job') && $id !== $input->getOption('job')) {
                    continue;
                }
                $recipients = $job['recipients'];
                $item = [
                    'id' => $id,
                    'name' => $job['name'],
                    'status' => $job['status'],
                    'total' => count($recipients),
                    'counts' => array_count_values(array_column($recipients, 'status')),
                    'error' => $job['error'] ?? '',
                ];
                if ($input->getOption('recipients')) {
                    $opened = $this->opened($job);
                    $item['recipients'] = [];
                    foreach ($recipients as $recipient) {
                        $address = strtolower($recipient['email']);
                        $item['recipients'][] = [
                            'email' => $recipient['email'],
                            'status' => $recipient['status'],
                            'sender' => $profiles[$recipient['sender'] ?? '']['from'] ?? '',
                            'attempted' => $recipient['attempted'] ?? null,
                            'finished' => $recipient['finished'] ?? null,
                            'skip_reason' => $recipient['skip_reason'] ?? null,
                            'bounce_reason' => $recipient['bounce_reason'] ?? null,
                            'opened_at' => 'sent' === $recipient['status'] ? ($opened[$address] ?? null) : null,
                        ];
                    }
                }
                $jobs[] = $item;
            }
            $output->writeln(json_encode([
                'interval_seconds' => $state['interval'],
                'worker_online' => time() - ($state['heartbeat'] ?? 0) < 20,
                'jobs' => $jobs,
                'suppressed_count' => count($state['suppressed'] ?? []),
                'open_tracking_note' => '打开记录来自同一邮件模板与批次创建时间后的追踪，仅供参考；未检测到不等于未阅读。',
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return Command::SUCCESS;
        } catch (\Throwable $exception) {
            $output->writeln('<error>'.$exception->getMessage().'</error>');

            return Command::FAILURE;
        }
    }

    private function opened(array $job): array
    {
        $addresses = array_values(array_unique(array_map('strtolower', array_column($job['recipients'], 'email'))));
        $opened = [];
        if ([] === $addresses) {
            return $opened;
        }
        $connection = $this->doctrine->getConnection();
        foreach (array_chunk($addresses, 500) as $chunk) {
            $rows = $connection->executeQuery(
                'SELECT LOWER(email_address) AS address, MAX(date_read) AS opened_at FROM '.MAUTIC_TABLE_PREFIX.'email_stats'
                .' WHERE email_id = ? AND date_sent >= ? AND is_read = 1 AND LOWER(email_address) IN (?) GROUP BY LOWER(email_address)',
                [(int) $job['email_id'], date('Y-m-d H:i:s', (int) $job['created']), $chunk],
                [\Doctrine\DBAL\ParameterType::INTEGER, \Doctrine\DBAL\ParameterType::STRING, ArrayParameterType::STRING]
            )->fetchAllAssociative();
            foreach ($rows as $row) {
                $opened[$row['address']] = $row['opened_at'];
            }
        }

        return $opened;
    }
}
