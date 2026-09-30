<?php

declare(strict_types=1);

namespace Mautic\CoreBundle\MailQueue;

final class InboxPoller
{
    private const INTERVAL = 900;

    public function __construct(private readonly QueueStore $queue, private readonly InboxStore $store, private readonly NeteaseImapClient $imap)
    {
    }

    public function pollOne(): string
    {
        $profiles = $this->queue->profiles();
        $now = time();
        $selected = $this->store->transaction(static function (array &$data) use ($profiles, $now): ?string {
            foreach ($data['accounts'] as $id => &$account) {
                if (empty($account['enabled']) || !isset($profiles[$id]) || !in_array($profiles[$id]['type'] ?? '', ['163', '126'], true)
                    || ($account['next_at'] ?? 0) > $now) {
                    continue;
                }
                $account['next_at'] = $now + self::INTERVAL;
                $account['last_attempt'] = $now;

                return (string) $id;
            }

            return null;
        });
        if (null === $selected) {
            return '暂无到期的收件账号。';
        }

        $profile = $profiles[$selected];
        try {
            $headers = $this->imap->recentHeaders((string) $profile['type'], (string) $profile['username'], (string) ($profile['password'] ?? ''));
            $messages = [];
            foreach ($headers as $item) {
                $messages[] = [
                    'key' => $selected.':'.$item['validity'].':'.$item['uid'],
                    'profile_id' => $selected,
                    'from' => mb_substr($item['from'], 0, 300),
                    'subject' => mb_substr($item['subject'], 0, 300),
                    'received_at' => strtotime($item['date']) ?: $now,
                    'handled' => false,
                ];
            }
            $this->store->transaction(static function (array &$data) use ($selected, $now, $messages): void {
                $account = &$data['accounts'][$selected];
                $account['status'] = '正常';
                $account['last_success'] = $now;
                foreach ($messages as $message) {
                    $data['messages'][$message['key']] ??= $message;
                }
                uasort($data['messages'], static fn (array $a, array $b): int => $b['received_at'] <=> $a['received_at']);
                $data['messages'] = array_slice($data['messages'], 0, 1000, true);
            });

            return '已收取 '.count($messages).' 条最近邮件头。';
        } catch (\InvalidArgumentException $exception) {
            $this->store->transaction(static function (array &$data) use ($selected, $now): void {
                $account = &$data['accounts'][$selected];
                $account['enabled'] = false;
                $account['status'] = '登录或客户端身份验证失败，已暂停。请检查 IMAP 设置与授权码。';
                $account['next_at'] = 0;
                $account['last_error_at'] = $now;
            });

            return '账号登录失败，已暂停自动收取。';
        } catch (\Throwable $exception) {
            $this->store->transaction(static function (array &$data) use ($selected, $now): void {
                $account = &$data['accounts'][$selected];
                $account['status'] = '读取失败，30 分钟后重试。';
                $account['next_at'] = $now + 1800;
                $account['last_error_at'] = $now;
            });

            return '收取失败，已延后重试。';
        }
    }
}
