<?php

declare(strict_types=1);

namespace Mautic\CoreBundle\MailQueue;

use Mautic\CoreBundle\Helper\PathsHelper;

final class QueueStore
{
    public function __construct(private readonly PathsHelper $paths)
    {
    }

    public function directory(): string
    {
        $path = dirname(dirname($this->paths->getLocalConfigurationFile())).'/mail-queue';
        if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
            throw new \RuntimeException('无法创建邮件队列目录。');
        }

        return $path;
    }

    public function profiles(): array
    {
        $file = $this->directory().'/profiles.json';

        return is_file($file) ? json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR) : [];
    }

    public function updateProfile(string $id, callable $update): void
    {
        $this->transaction(function (array &$state) use ($id, $update): void {
            foreach ($state['jobs'] as $job) {
                if ('running' === $job['status'] || in_array('sending', array_column($job['recipients'], 'status'), true)) {
                    throw new \RuntimeException('请先暂停批次，并等待正在投递的一封结束，再修改发件账号。');
                }
            }
            $profiles = $this->profiles();
            $profiles[$id] = $update($profiles[$id] ?? []);
            $this->write($this->directory().'/profiles.json', $profiles);
        });
    }

    public function refreshToken(string $id, string $previous, string $refreshed): void
    {
        $this->transaction(function (array &$state) use ($id, $previous, $refreshed): void {
            $profiles = $this->profiles();
            if (($profiles[$id]['oauth_refresh_token'] ?? null) === $previous) {
                $profiles[$id]['oauth_refresh_token'] = $refreshed;
                $this->write($this->directory().'/profiles.json', $profiles);
            }
        });
    }

    public function transaction(callable $callback): mixed
    {
        $lock = fopen($this->directory().'/state.lock', 'c');
        if (false === $lock || !flock($lock, LOCK_EX)) {
            throw new \RuntimeException('无法锁定邮件队列。');
        }
        try {
            $file = $this->directory().'/state.json';
            $state = is_file($file) ? json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR)
                : ['interval' => 20, 'last_attempt' => 0, 'cursor' => 0, 'jobs' => [], 'heartbeat' => 0];
            $before = $state;
            $result = $callback($state);
            if (!is_file($file) || $before !== $state) {
                $this->write($file, $state);
            }

            return $result;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function state(): array
    {
        return $this->transaction(fn (array &$state) => $state);
    }

    private function write(string $file, array $data): void
    {
        $temp = $file.'.'.bin2hex(random_bytes(6)).'.tmp';
        try {
            if (false === file_put_contents($temp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR))) {
                throw new \RuntimeException('无法写入邮件队列。');
            }
            chmod($temp, 0600);
            if (!rename($temp, $file)) {
                throw new \RuntimeException('无法保存邮件队列。');
            }
        } finally {
            if (is_file($temp)) {
                unlink($temp);
            }
        }
    }
}
