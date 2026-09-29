<?php

declare(strict_types=1);

namespace Mautic\CoreBundle\MailQueue;

final class UnsubscribeClient
{
    public function __construct(private readonly QueueStore $store)
    {
    }

    public function configured(): bool
    {
        return is_file($this->store->directory().'/unsubscribe.json');
    }

    public function request(string $path, ?array $body = null): array
    {
        try {
            if (!$this->configured()) {
                throw new \RuntimeException();
            }
            $config = json_decode(file_get_contents($this->store->directory().'/unsubscribe.json'), true, 512, JSON_THROW_ON_ERROR);
            $base = rtrim($config['internal_url'], '/');
            if (!preg_match('#^https?://[^/]+$#', $base) || strlen($config['api_key']) < 32) {
                throw new \RuntimeException();
            }
            $curl = curl_init($base.$path);
            try {
                curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 5,
                    CURLOPT_HTTPHEADER => ['Authorization: Bearer '.$config['api_key'], 'Content-Type: application/json'],
                    CURLOPT_FOLLOWLOCATION => false]);
                if (null !== $body) {
                    curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body, JSON_THROW_ON_ERROR)]);
                }
                $raw = curl_exec($curl);
                if (false === $raw || 200 !== curl_getinfo($curl, CURLINFO_HTTP_CODE)) {
                    throw new \RuntimeException();
                }
                $result = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($result)) {
                    throw new \RuntimeException();
                }

                return $result;
            } finally {
                curl_close($curl);
            }
        } catch (\Throwable) {
            throw new UnsubscribeUnavailable('204 退订服务无法确认状态，批次已暂停；连接恢复后可继续。');
        }
    }

    public function prepare(string $email): array
    {
        $result = $this->checkResult($this->request('/internal/prepare', ['email' => $email]));
        if (!$result['blocked']) {
            $url = $result['url'] ?? '';
            if (!is_string($url) || !str_starts_with($url, 'https://') || false === filter_var($url, FILTER_VALIDATE_URL)) {
                throw new UnsubscribeUnavailable('204 未返回有效的公开退订网址，批次已暂停。');
            }
        }

        return $result;
    }

    public function blocked(string $email): bool
    {
        return $this->checkResult($this->request('/internal/check', ['email' => $email]))['blocked'];
    }

    private function checkResult(array $result): array
    {
        if (!isset($result['blocked']) || !is_bool($result['blocked'])) {
            throw new UnsubscribeUnavailable('204 退订服务返回无效状态，批次已暂停。');
        }

        return $result;
    }
}
