<?php

declare(strict_types=1);

namespace Mautic\CoreBundle\MailQueue;

final class CsvRecipients
{
    public static function read(string $file, int $limit): array
    {
        if ($limit < 1 || $limit > 20000 || filesize($file) > 10 * 1024 * 1024) {
            throw new \InvalidArgumentException('CSV 最大 10 MB，每批最多 20000 人。');
        }
        $stream = fopen($file, 'rb');
        if (false === $stream) {
            throw new \InvalidArgumentException('无法读取 CSV。');
        }
        try {
            $header = fgetcsv($stream, 0, ',', '"', '');
            if (!$header) {
                throw new \InvalidArgumentException('CSV 为空。');
            }
            $header = array_map(fn ($value) => strtolower(trim(ltrim($value, "\xEF\xBB\xBF"))), $header);
            if (!in_array('email', $header, true) || count(array_unique($header)) !== count($header)) {
                throw new \InvalidArgumentException('CSV 需要唯一的 email 列，可选 firstname、nickname、role、user_id 列。');
            }
            $rows = [];
            $blocked = [];
            while (false !== ($values = fgetcsv($stream, 0, ',', '"', ''))) {
                if ([null] === $values) {
                    continue;
                }
                if (count($values) !== count($header) || !mb_check_encoding(implode('', $values), 'UTF-8')) {
                    throw new \InvalidArgumentException('CSV 行格式有误，请使用 UTF-8 编码。');
                }
                $row = array_combine($header, $values);
                $row['email'] = strtolower(trim($row['email']));
                $rows[] = $row;
                if (in_array(strtolower(trim($row['role'] ?? '')), ['群主', '管理员', 'owner', 'admin', 'administrator'], true)) {
                    $blocked['email:'.$row['email']] = true;
                    if (!empty($row['user_id'])) {
                        $blocked['user:'.trim($row['user_id'])] = true;
                    }
                }
                if (count($rows) > 50000) {
                    throw new \InvalidArgumentException('CSV 原始记录最多 50000 行。');
                }
            }
            $unique = [];
            $counts = ['total' => count($rows), 'excluded' => 0, 'invalid' => 0, 'duplicates' => 0];
            foreach ($rows as $row) {
                if (isset($blocked['email:'.$row['email']]) || isset($blocked['user:'.trim($row['user_id'] ?? '')])) {
                    ++$counts['excluded'];
                } elseif (false === filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
                    ++$counts['invalid'];
                } elseif (isset($unique[$row['email']])) {
                    ++$counts['duplicates'];
                } else {
                    $unique[$row['email']] = ['email' => $row['email'], 'firstname' => mb_substr(trim(preg_replace('/[\r\n\t]/', ' ', $row['firstname'] ?? $row['nickname'] ?? '')), 0, 100), 'status' => 'pending'];
                }
            }
            $counts['eligible'] = count($unique);
            $recipients = array_slice(array_values($unique), 0, $limit);
            if ([] === $recipients) {
                throw new \InvalidArgumentException('清理后没有可发送的邮箱。');
            }

            return ['recipients' => $recipients, 'counts' => $counts];
        } finally {
            fclose($stream);
        }
    }
}
