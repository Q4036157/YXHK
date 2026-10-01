<?php

declare(strict_types=1);

namespace Mautic\CoreBundle\MailQueue;

final class RecipientDomainCheck
{
    private const CACHE_SECONDS = 3600;

    private array $cache = [];

    public function rejectReason(string $email): ?string
    {
        $domain = strtolower(substr(strrchr($email, '@') ?: '', 1));
        if ('' === $domain) {
            return '收件地址格式无效';
        }
        $now = time();
        if (isset($this->cache[$domain]) && $this->cache[$domain]['expires'] > $now) {
            return $this->cache[$domain]['reason'];
        }

        $mx = @dns_get_record($domain, DNS_MX);
        if (false === $mx) {
            throw new RecipientCheckUnavailable('暂时无法查询收件域名，请稍后继续发送。');
        }
        if ([] !== $mx) {
            $reason = 1 === count($mx) && '' === rtrim((string) ($mx[0]['target'] ?? ''), '.')
                ? '收件域名明确声明不接收邮件（Null MX）' : null;
        } else {
            // SMTP permits A/AAAA delivery when a domain has no MX record.
            $addresses = @dns_get_record($domain, DNS_A | DNS_AAAA);
            if (false === $addresses) {
                throw new RecipientCheckUnavailable('暂时无法查询收件域名，请稍后继续发送。');
            }
            $reason = [] === $addresses ? '收件域名没有 MX 或 A/AAAA 邮件路由' : null;
        }
        $this->cache[$domain] = ['reason' => $reason, 'expires' => $now + self::CACHE_SECONDS];

        return $reason;
    }
}
