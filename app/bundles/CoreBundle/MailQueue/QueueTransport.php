<?php

declare(strict_types=1);

namespace Mautic\CoreBundle\MailQueue;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Auth\XOAuth2Authenticator;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

final class QueueTransport implements TransportInterface
{
    public function __construct(private readonly QueueStore $store, private readonly SenderContext $context,
        private readonly UnsubscribeClient $unsubscribe)
    {
    }

    public static function configured(array $profile): bool
    {
        $authorized = 'oauth2' === ($profile['auth'] ?? 'password')
            ? !empty($profile['oauth_client_id']) && !empty($profile['oauth_refresh_token']) && in_array($profile['type'] ?? '', ['google', 'outlook'], true)
            : !empty($profile['password']);

        return !empty($profile['enabled']) && !empty($profile['host']) && !empty($profile['username'])
            && $authorized && false !== filter_var($profile['from'] ?? '', FILTER_VALIDATE_EMAIL);
    }

    public static function smtp(array $profile, ?string $accessToken = null): EsmtpTransport
    {
        $transport = new EsmtpTransport($profile['host'], (int) $profile['port'], 'smtps' === $profile['security']);
        $transport->setAutoTls(true);
        $transport->setRequireTls(true);
        $transport->setUsername($profile['username']);
        if ('oauth2' === ($profile['auth'] ?? 'password')) {
            $transport->setAuthenticators([new XOAuth2Authenticator()]);
            $transport->setPassword($accessToken ?? '');
        } else {
            $transport->setPassword($profile['password']);
        }
        $transport->getStream()->setTimeout(15);

        return $transport;
    }

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        $profiles = $this->store->profiles();
        $id = $this->context->profile ?? array_key_first(array_filter($profiles, self::configured(...))) ?? '';
        $profile = $profiles[$id] ?? [];
        if (!self::configured($profile) || !$message instanceof Email) {
            throw new TransportException('发件账号尚未配置，或邮件格式不支持。');
        }
        $message = clone $message;
        if (null !== $this->context->recipient) {
            // 在连接 SMTP 之前最后检查，所有轮询账号执行同一条规则。
            try {
                $blocked = $this->unsubscribe->blocked($this->context->recipient);
            } catch (UnsubscribeUnavailable $exception) {
                $this->context->unsubscribeUnavailable = true;
                throw $exception;
            }
            if ($blocked) {
                $this->context->unsubscribed = true;

                return null;
            }
            $url = $this->context->unsubscribeUrl;
            if (null === $url) {
                throw new UnsubscribeUnavailable('未生成公开退订链接，批次已暂停。');
            }
            $headers = $message->getHeaders();
            $headers->remove('List-Unsubscribe');
            $headers->remove('List-Unsubscribe-Post');
            $headers->addTextHeader('List-Unsubscribe', '<'.$url.'>');
            $headers->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
            $html = $message->getHtmlBody();
            if (null !== $html) {
                $tracking = $this->store->tracking();
                if (null !== $tracking) {
                    $prefix = $tracking['source_url'].'/email/';
                    $html = preg_replace_callback(
                        '~'.preg_quote($prefix, '~').'([A-Za-z0-9_-]{16,128})\.gif(?=[?"\'])~',
                        static fn (array $match): string => $tracking['public_url'].'/'.$match[1].'.gif',
                        $html
                    );
                }
                if (!str_contains($html, $url)) {
                    $html .= '<p><a href="'.htmlspecialchars($url, ENT_QUOTES).'">退订营销邮件</a></p>';
                }
                $message->html($html);
            }
            $text = $message->getTextBody();
            if (null !== $text && !str_contains($text, $url)) {
                $message->text($text."\n\n退订营销邮件：".$url);
            }
        }
        $sender = new Address($profile['from'], $profile['name'] ?? '');
        $message->from($sender)->sender($sender)->returnPath($sender)->replyTo($sender);
        $envelope = new Envelope($sender, ($envelope ?? Envelope::create($message))->getRecipients());
        $transport = self::smtp($profile, 'oauth2' === ($profile['auth'] ?? 'password') ? $this->accessToken($id, $profile) : null);
        try {
            $sent = $transport->send($message, $envelope);
            if (null !== $sent) {
                ++$this->context->accepted;
            }

            return $sent;
        } catch (\Throwable) {
            // Never expose authentication material in application logs or queue status.
            throw new TransportException('SMTP 投递失败，请检查账号授权、配额和收件地址。');
        } finally {
            try {
                $transport->stop();
            } catch (\Throwable) {
            }
        }
    }

    public function __toString(): string
    {
        return 'yxhk://default';
    }

    private function accessToken(string $id, array $profile): string
    {
        $endpoint = 'google' === $profile['type'] ? 'https://oauth2.googleapis.com/token' : 'https://login.microsoftonline.com/common/oauth2/v2.0/token';
        $body = ['grant_type' => 'refresh_token', 'client_id' => $profile['oauth_client_id'], 'refresh_token' => $profile['oauth_refresh_token']];
        if (!empty($profile['oauth_client_secret'])) {
            $body['client_secret'] = $profile['oauth_client_secret'];
        }
        if ('outlook' === $profile['type']) {
            $body['scope'] = 'https://outlook.office.com/SMTP.Send offline_access';
        }
        $curl = curl_init($endpoint);
        try {
            curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($body), CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
            $result = curl_exec($curl);
            $code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        } finally {
            curl_close($curl);
        }
        $tokens = is_string($result) ? json_decode($result, true) : null;
        if (200 !== $code || empty($tokens['access_token'])) {
            throw new TransportException('OAuth 授权刷新失败，请重新授权该邮箱。');
        }
        if (!empty($tokens['refresh_token']) && $tokens['refresh_token'] !== $profile['oauth_refresh_token']) {
            $this->store->refreshToken($id, $profile['oauth_refresh_token'], $tokens['refresh_token']);
        }

        return $tokens['access_token'];
    }
}
