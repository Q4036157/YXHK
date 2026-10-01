<?php

declare(strict_types=1);

namespace Mautic\CoreBundle\MailQueue;

final class ImapMessageText
{
    public static function decode(string $raw): string
    {
        $truncated = strlen($raw) >= 65536;
        $text = self::part($raw, 0);
        if (null === $text || '' === trim($text)) {
            return '未能解析正文。请到原邮箱查看这封邮件。';
        }

        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? $text;

        return mb_substr(trim($text), 0, 30000).($truncated || mb_strlen($text) > 30000 ? "\n\n（正文已截断，请到原邮箱查看全文）" : '');
    }

    private static function part(string $raw, int $depth): ?string
    {
        if ($depth > 5 || !preg_match('/\r?\n\r?\n/', $raw, $separator, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $offset = $separator[0][1];
        $headers = substr($raw, 0, $offset);
        $body = substr($raw, $offset + strlen($separator[0][0]));
        $type = self::header($headers, 'Content-Type') ?? 'text/plain';
        $disposition = self::header($headers, 'Content-Disposition') ?? '';
        if (preg_match('/\battachment\b/i', $disposition)) {
            return null;
        }
        if (preg_match('/^multipart\//i', $type)) {
            if (!preg_match('/\bboundary\s*=\s*(?:"([^"]+)"|([^;\s]+))/i', $type, $match)) {
                return null;
            }
            $boundary = $match[1] ?: $match[2];
            $chunks = preg_split('/^--'.preg_quote($boundary, '/'). '(?:--)?[ \t]*\r?$/m', $body) ?: [];
            $html = null;
            foreach (array_slice($chunks, 1) as $chunk) {
                $chunk = ltrim($chunk, "\r\n");
                if ('' === $chunk || str_starts_with($chunk, '--')) {
                    continue;
                }
                $decoded = self::part($chunk, $depth + 1);
                if (null === $decoded) {
                    continue;
                }
                if (preg_match('/^text\/plain\b/i', self::header($chunk, 'Content-Type') ?? '')) {
                    return $decoded;
                }
                $html ??= $decoded;
            }

            return $html;
        }
        if (!preg_match('/^text\/(plain|html)\b/i', $type, $kind)) {
            return null;
        }
        $encoding = strtolower(trim(self::header($headers, 'Content-Transfer-Encoding') ?? ''));
        if ('base64' === $encoding) {
            $body = base64_decode($body, false) ?: '';
        } elseif ('quoted-printable' === $encoding) {
            $body = quoted_printable_decode($body);
        }
        if (preg_match('/\bcharset\s*=\s*(?:"([^"]+)"|([^;\s]+))/i', $type, $match)) {
            $charset = $match[1] ?: $match[2];
            $converted = @iconv($charset, 'UTF-8//IGNORE', $body);
            if (false !== $converted) {
                $body = $converted;
            }
        }
        if ('html' === strtolower($kind[1])) {
            $body = preg_replace('/<(br|\/p|\/div|\/li|\/tr)\b[^>]*>/i', "\n", $body) ?? $body;
            $body = html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return $body;
    }

    private static function header(string $headers, string $name): ?string
    {
        $unfolded = preg_replace('/\r?\n[ \t]+/', ' ', $headers) ?? $headers;
        if (!preg_match('/^'.preg_quote($name, '/').':\s*(.+)$/im', $unfolded, $match)) {
            return null;
        }

        return trim($match[1]);
    }
}
