<?php

declare(strict_types=1);

namespace Mautic\CoreBundle\MailQueue;

final class NeteaseImapClient
{
    public function recentHeaders(string $type, string $username, string $password): array
    {
        $host = '163' === $type ? 'imap.163.com' : 'imap.126.com';
        $socket = @stream_socket_client('ssl://'.$host.':993', $errorCode, $errorMessage, 8, STREAM_CLIENT_CONNECT,
            stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $host]]));
        if (false === $socket) {
            throw new \RuntimeException('无法连接网易 IMAP 服务器。');
        }
        stream_set_timeout($socket, 8);
        try {
            $greeting = fgets($socket, 8192);
            if (false === $greeting || !str_starts_with($greeting, '* OK')) {
                throw new \RuntimeException('网易 IMAP 服务器未接受连接。');
            }
            if (!$this->command($socket, 'LOGIN '.$this->quote($username).' '.$this->quote($password))) {
                throw new \InvalidArgumentException('IMAP 登录失败。');
            }
            // 网易要求第三方 IMAP 客户端在登录后报告身份信息。
            if (!$this->command($socket, 'ID ("name" "YXHK" "version" "1.0" "vendor" "YXHK")')) {
                throw new \InvalidArgumentException('网易服务器未接受 IMAP 客户端身份信息。');
            }
            $select = $this->command($socket, 'EXAMINE INBOX');
            if (false === $select) {
                throw new \RuntimeException('无法以只读方式打开收件箱。');
            }
            preg_match('/\* (\d+) EXISTS/i', $select, $countMatch);
            preg_match('/\[UIDVALIDITY (\d+)\]/i', $select, $validityMatch);
            if (!isset($countMatch[1], $validityMatch[1])) {
                throw new \RuntimeException('网易 IMAP 未返回完整的收件箱状态。');
            }
            $count = (int) ($countMatch[1] ?? 0);
            $validity = (string) ($validityMatch[1] ?? '0');
            $headers = [];
            for ($sequence = max(1, $count - 19); $sequence <= $count; ++$sequence) {
                $response = $this->command($socket, 'FETCH '.$sequence.' (UID BODY.PEEK[HEADER.FIELDS (FROM SUBJECT DATE)])');
                if (false === $response || !preg_match('/\bUID\s+(\d+)\b/i', $response, $uidMatch)
                    || !preg_match('/\{(\d+)\}\r\n/', $response, $literalMatch, PREG_OFFSET_CAPTURE)) {
                    continue;
                }
                $length = (int) $literalMatch[1][0];
                $start = $literalMatch[0][1] + strlen($literalMatch[0][0]);
                $raw = substr($response, $start, $length);
                $decoded = iconv_mime_decode_headers($raw, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8') ?: [];
                $headers[] = [
                    'uid' => $uidMatch[1],
                    'validity' => $validity,
                    'from' => trim((string) ($decoded['From'] ?? $decoded['from'] ?? '')),
                    'subject' => trim((string) ($decoded['Subject'] ?? $decoded['subject'] ?? '')),
                    'date' => (string) ($decoded['Date'] ?? $decoded['date'] ?? ''),
                ];
                $last = count($headers) - 1;
                if (HardBounceDetector::isCandidate($headers[$last]['from'], $headers[$last]['subject'])) {
                    $bodyResponse = $this->command($socket, 'FETCH '.$sequence.' BODY.PEEK[TEXT]<0.32768>');
                    if (false !== $bodyResponse && preg_match('/\{(\d+)\}\r\n/', $bodyResponse, $bodyMatch, PREG_OFFSET_CAPTURE)) {
                        $bodyStart = $bodyMatch[0][1] + strlen($bodyMatch[0][0]);
                        $headers[$last]['bounce_body'] = substr($bodyResponse, $bodyStart, (int) $bodyMatch[1][0]);
                    }
                }
            }
            $this->command($socket, 'LOGOUT');

            return $headers;
        } finally {
            fclose($socket);
        }
    }

    private function quote(string $value): string
    {
        if (str_contains($value, "\r") || str_contains($value, "\n") || str_contains($value, "\0")) {
            throw new \InvalidArgumentException('IMAP 账号信息包含无效字符。');
        }

        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }

    private function command($socket, string $request): string|false
    {
        static $sequence = 0;
        $tag = sprintf('A%04d', ++$sequence);
        if (false === fwrite($socket, $tag.' '.$request."\r\n")) {
            throw new \RuntimeException('IMAP 命令发送失败。');
        }
        $response = '';
        while (!feof($socket) && strlen($response) < 262144) {
            $line = fgets($socket, 8192);
            if (false === $line) {
                break;
            }
            $response .= $line;
            if (preg_match('/\{(\d+)\}\r\n$/', $line, $match)) {
                $remaining = (int) $match[1];
                if ($remaining > 65536) {
                    throw new \RuntimeException('IMAP 邮件头超过读取上限。');
                }
                while ($remaining > 0) {
                    $part = fread($socket, $remaining);
                    if (false === $part || '' === $part) {
                        throw new \RuntimeException('IMAP 邮件头读取中断。');
                    }
                    $response .= $part;
                    $remaining -= strlen($part);
                }
            }
            if (str_starts_with($line, $tag.' ')) {
                return str_starts_with($line, $tag.' OK') ? $response : false;
            }
        }

        throw new \RuntimeException('IMAP 服务器响应超时或过长。');
    }
}
