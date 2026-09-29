<?php

declare(strict_types=1);

// 验证客户端失败关闭和不投递已退订邮箱，不使用真实 SMTP。
require $argv[1];
$source = dirname(__DIR__, 2);
foreach (['SenderContext', 'QueueStore', 'UnsubscribeUnavailable', 'UnsubscribeClient', 'QueueTransport'] as $class) {
    require_once $source.'/app/bundles/CoreBundle/MailQueue/'.$class.'.php';
}
require_once $source.'/app/bundles/CoreBundle/EventListener/QueueUnsubscribeSubscriber.php';
$directory = sys_get_temp_dir().'/yxhk-unsubscribe-test-'.bin2hex(random_bytes(6));
$paths = new class($directory) extends \Mautic\CoreBundle\Helper\PathsHelper {
    public function __construct(private readonly string $directory) {}
    public function getLocalConfigurationFile(): string { return $this->directory.'/config/local.php'; }
};
$store = new \Mautic\CoreBundle\MailQueue\QueueStore($paths);
$client = new \Mautic\CoreBundle\MailQueue\UnsubscribeClient($store);
function verify(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
}
try {
    try {
        $client->prepare('test@example.invalid');
        throw new RuntimeException('Missing configuration did not block');
    } catch (\Mautic\CoreBundle\MailQueue\UnsubscribeUnavailable) {}
    file_put_contents($store->directory().'/unsubscribe.json', json_encode(['internal_url' => $argv[2], 'api_key' => str_repeat('a', 48)]));
    $recipient = 'test-'.bin2hex(random_bytes(6)).'@example.invalid';
    $prepared = $client->prepare($recipient);
    verify(false === $prepared['blocked'], 'Fresh address blocked');
    $token = parse_url($prepared['url'], PHP_URL_QUERY);
    $curl = curl_init($argv[2].'/marketing/unsubscribe?'.$token);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true]);
    curl_exec($curl);
    curl_close($curl);
    verify(!$client->blocked($recipient), 'GET should not unsubscribe');
    $curl = curl_init($argv[2].'/marketing/unsubscribe?'.$token);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => 'List-Unsubscribe=One-Click']);
    curl_exec($curl);
    curl_close($curl);
    verify($client->blocked(strtoupper($recipient)), 'Unsubscribe did not persist');
    $store->updateProfile('test', fn () => ['type' => '163', 'enabled' => true, 'host' => '127.0.0.1', 'port' => 1,
        'username' => 'sender@example.invalid', 'password' => 'dummy', 'security' => 'smtps', 'from' => 'sender@example.invalid']);
    $context = new \Mautic\CoreBundle\MailQueue\SenderContext();
    $context->profile = 'test';
    $context->recipient = $recipient;
    $context->unsubscribeUrl = $prepared['url'];
    $message = (new \Symfony\Component\Mime\Email())->from('sender@example.invalid')->to($recipient)->subject('test')->text('test');
    $transport = new \Mautic\CoreBundle\MailQueue\QueueTransport($store, $context, $client);
    verify(null === $transport->send($message) && $context->unsubscribed && 0 === $context->accepted, 'Blocked recipient reached SMTP');
    $event = new \Mautic\EmailBundle\Event\EmailSendEvent(null, ['internalSend' => false]);
    $subscriber = new \Mautic\CoreBundle\EventListener\QueueUnsubscribeSubscriber($context);
    $subscriber->replaceUnsubscribe($event);
    verify($event->getTokens()['{unsubscribe_url}'] === $prepared['url'], 'Template token not replaced');
    file_put_contents($store->directory().'/unsubscribe.json', json_encode(['internal_url' => 'http://127.0.0.1:1', 'api_key' => str_repeat('a',48)]));
    try {
        $client->blocked($recipient);
        throw new RuntimeException('Unavailable service did not block');
    } catch (\Mautic\CoreBundle\MailQueue\UnsubscribeUnavailable) {}
    echo "PASS: missing config, GET confirmation, One-Click, normalized suppression, no SMTP for blocked recipient, template tokens, outage fail closed.\n";
} finally {
    foreach (glob($store->directory().'/*') as $file) { unlink($file); }
    rmdir($store->directory());
    rmdir($directory);
}
