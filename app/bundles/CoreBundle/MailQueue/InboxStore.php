<?php

declare(strict_types=1);

namespace Mautic\CoreBundle\MailQueue;

final class InboxStore
{
    public function __construct(private readonly QueueStore $queue)
    {
    }

    public function read(): array
    {
        return $this->transaction(static fn (array &$data): array => $data);
    }

    public function transaction(callable $callback): mixed
    {
        $directory = $this->queue->directory();
        $lock = fopen($directory.'/inbox.lock', 'c');
        if (false === $lock || !flock($lock, LOCK_EX)) {
            throw new \RuntimeException('无法锁定客户回复记录。');
        }
        try {
            $file = $directory.'/inbox.json';
            $data = is_file($file) ? json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR)
                : ['accounts' => [], 'messages' => []];
            $before = $data;
            $result = $callback($data);
            if ($data !== $before) {
                $temporary = $file.'.'.bin2hex(random_bytes(6)).'.tmp';
                try {
                    if (false === file_put_contents($temporary, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), LOCK_EX)) {
                        throw new \RuntimeException('无法写入客户回复记录。');
                    }
                    chmod($temporary, 0600);
                    if (!rename($temporary, $file)) {
                        throw new \RuntimeException('无法保存客户回复记录。');
                    }
                } finally {
                    if (is_file($temporary)) {
                        unlink($temporary);
                    }
                }
            }

            return $result;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
