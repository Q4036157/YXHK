<?php

declare(strict_types=1);

namespace Mautic\CoreBundle\MailQueue;

use Doctrine\Persistence\ManagerRegistry;
use Mautic\LeadBundle\Entity\DoNotContact as DNC;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Model\DoNotContact;

final class UnsubscribeSync
{
    private int $lastPoll = 0;

    public function __construct(private readonly QueueStore $store, private readonly UnsubscribeClient $client,
        private readonly ManagerRegistry $doctrine, private readonly DoNotContact $dnc)
    {
    }

    public function block(string $email): void
    {
        // 同一邮箱可能对应多个历史联系人，全部标记；重新导入仍由 204 挡住。
        $connection = $this->doctrine->getConnection();
        $ids = $connection->fetchFirstColumn('SELECT id FROM '.MAUTIC_TABLE_PREFIX.'leads WHERE LOWER(TRIM(email)) = :email', ['email' => strtolower(trim($email))]);
        foreach ($ids as $id) {
            $this->dnc->addDncForContact((int) $id, 'email', DNC::UNSUBSCRIBED, '客户通过 204 退订营销邮件');
        }
    }

    public function tick(int $now): bool
    {
        if (!$this->client->configured() || $now - $this->lastPoll < 5) {
            return true;
        }
        $this->lastPoll = $now;
        try {
            foreach ($this->doctrine->getConnections() as $connection) {
                $connection->close();
            }
            $cursor = (int) ($this->store->state()['unsubscribe_cursor'] ?? 0);
            $result = $this->client->request('/internal/events?after='.$cursor);
            if (!isset($result['events'], $result['cursor']) || !is_array($result['events']) || !is_int($result['cursor']) || $result['cursor'] < $cursor) {
                throw new \RuntimeException();
            }
            foreach ($result['events'] as $event) {
                if (!isset($event['email'], $event['id']) || !is_string($event['email']) || !is_int($event['id']) || $event['id'] <= $cursor) {
                    throw new \RuntimeException();
                }
                $this->block($event['email']);
            }
            $this->store->transaction(function (array &$state) use ($result, $now): void {
                $state['unsubscribe_cursor'] = $result['cursor'];
                $state['unsubscribe_sync'] = ['status' => 'ok', 'time' => $now, 'error' => ''];
            });
            $this->doctrine->getManager()->clear();

            return true;
        } catch (\Throwable) {
            $this->store->transaction(function (array &$state) use ($now): void {
                $error = '204 退订同步失败，批次已暂停；连接恢复后可继续。';
                $state['unsubscribe_sync'] = ['status' => 'error', 'time' => $now, 'error' => $error];
                foreach ($state['jobs'] as &$job) {
                    if ('running' === $job['status']) {
                        $job['status'] = 'paused';
                        $job['error'] = $error;
                    }
                }
            });

            return false;
        }
    }
}
