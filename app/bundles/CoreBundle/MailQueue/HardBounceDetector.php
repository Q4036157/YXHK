<?php

declare(strict_types=1);

namespace Mautic\CoreBundle\MailQueue;

final class HardBounceDetector
{
    public static function isCandidate(string $from, string $subject): bool
    {
        return (bool) preg_match('/postmaster|mailer-daemon|mail delivery subsystem/i', $from)
            && (bool) preg_match('/退信|投递失败|undeliver|delivery status notification|failure notice/i', $subject);
    }

    public static function failedAddresses(string $body, array $sentAddresses): array
    {
        $decoded = quoted_printable_decode($body);
        $decoded .= "\n".html_entity_decode(strip_tags($decoded), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (!preg_match('/收件人邮箱地址不存在或不正确|收件人不存在|用户不存在|邮箱地址不存在|no such user|user unknown|recipient address rejected.*user unknown|5\.1\.1/i', $decoded)) {
            return [];
        }
        preg_match_all('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $decoded, $matches);
        $found = array_fill_keys(array_map('strtolower', $matches[0] ?? []), true);

        return array_values(array_filter($sentAddresses, static fn (string $email): bool => isset($found[strtolower($email)])));
    }
}
