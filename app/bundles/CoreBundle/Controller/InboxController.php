<?php

declare(strict_types=1);

namespace Mautic\CoreBundle\Controller;

use Mautic\CoreBundle\MailQueue\InboxStore;
use Mautic\CoreBundle\MailQueue\NeteaseImapClient;
use Mautic\CoreBundle\MailQueue\QueueStore;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class InboxController extends CommonController
{
    public function indexAction(Request $request, QueueStore $queue, InboxStore $inbox, NeteaseImapClient $imap): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        $profiles = array_filter($queue->profiles(), static fn (array $profile): bool => in_array($profile['type'] ?? '', ['163', '126'], true));
        $notice = $request->getSession()->getFlashBag()->get('yxhk_inbox');
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('yxhk_inbox', $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException('页面已过期，请刷新重试。');
            }
            $action = $request->request->getString('action');
            $id = $request->request->getString('id');
            if ('detail' === $action) {
                try {
                    $message = $inbox->read()['messages'][$id] ?? null;
                    $profile = $profiles[$message['profile_id'] ?? ''] ?? null;
                    $parts = explode(':', $id);
                    if (null === $message || null === $profile || 3 !== count($parts)
                        || $parts[0] !== $message['profile_id'] || !ctype_digit($parts[1]) || !ctype_digit($parts[2])
                        || empty($profile['username']) || empty($profile['password'])) {
                        throw new \InvalidArgumentException('这条邮件记录或邮箱配置不存在。');
                    }
                    $body = $imap->messageText($profile['type'], $profile['username'], $profile['password'], $parts[1], $parts[2]);

                    return $this->json(['ok' => true, 'body' => $body], Response::HTTP_OK, ['Cache-Control' => 'no-store']);
                } catch (\InvalidArgumentException|\RuntimeException $exception) {
                    return $this->json(['ok' => false, 'message' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY, ['Cache-Control' => 'no-store']);
                }
            }
            $error = null;
            try {
                if ('account' === $action) {
                    if (!isset($profiles[$id])) {
                        throw new \InvalidArgumentException('邮箱账号不存在。');
                    }
                    $enabled = $request->request->getBoolean('enabled');
                    if ($enabled && (empty($profiles[$id]['username']) || empty($profiles[$id]['password']))) {
                        throw new \InvalidArgumentException('这个邮箱没有完整的账号和授权码。');
                    }
                    $inbox->transaction(static function (array &$data) use ($id, $enabled): void {
                        $account = $data['accounts'][$id] ?? [];
                        if ($enabled && !empty($account['enabled'])) {
                            return;
                        }
                        $account['enabled'] = $enabled;
                        $account['request_pending'] = false;
                        $account['next_at'] = 0;
                        $account['status'] = $enabled ? '已启用，等待手动检查' : '已关闭';
                        $data['accounts'][$id] = $account;
                    });
                    $notice[] = $enabled ? '已启用此账号。点击“检查收件”时才会连接邮箱。' : '已关闭此账号的收件检查。';
                } elseif ('request' === $action) {
                    if ('all' !== $id && !isset($profiles[$id])) {
                        throw new \InvalidArgumentException('邮箱账号不存在。');
                    }
                    $selected = 'all' === $id ? array_keys($profiles) : [$id];
                    $queued = $inbox->transaction(static function (array &$data) use ($selected): int {
                        $count = 0;
                        $now = time();
                        foreach ($selected as $accountId) {
                            if (empty($data['accounts'][$accountId]['enabled'])) {
                                continue;
                            }
                            $account = &$data['accounts'][$accountId];
                            $account['request_pending'] = true;
                            $account['next_at'] = max($now, ((int) ($account['last_attempt'] ?? 0)) + 900);
                            $account['status'] = $account['next_at'] > $now ? '已排队，等待登录间隔' : '已排队，等待检查';
                            ++$count;
                            unset($account);
                        }

                        return $count;
                    });
                    if (0 === $queued) {
                        throw new \InvalidArgumentException('没有已启用的收件账号。');
                    }
                    $notice[] = '已安排 '.$queued.' 个账号各检查一次，后台每分钟最多检查一个账号。';
                } elseif ('handled' === $action) {
                    $inbox->transaction(static function (array &$data) use ($id, $request): void {
                        if (!isset($data['messages'][$id])) {
                            throw new \InvalidArgumentException('这条邮件记录不存在。');
                        }
                        $data['messages'][$id]['handled'] = $request->request->getBoolean('handled');
                    });
                    $notice[] = '处理状态已保存。';
                } else {
                    throw new \InvalidArgumentException('操作无效。');
                }
            } catch (\InvalidArgumentException|\RuntimeException $exception) {
                $error = $exception->getMessage();
                $notice[] = $error;
            }
            if ('handled' === $action && $request->isXmlHttpRequest()) {
                $message = $inbox->read()['messages'][$id] ?? null;

                return $this->json([
                    'ok' => null === $error,
                    'handled' => (bool) ($message['handled'] ?? false),
                    'message' => end($notice),
                ], null === $error ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            if (in_array($action, ['account', 'request'], true) && $request->isXmlHttpRequest()) {
                $states = $inbox->read()['accounts'];
                $state = $states[$id] ?? [];

                return $this->json([
                    'ok' => null === $error,
                    'enabled' => !empty($state['enabled']),
                    'status' => $state['status'] ?? '未启用',
                    'statuses' => array_map(static fn (array $account): string => (string) ($account['status'] ?? '未启用'), $states),
                    'message' => end($notice),
                ], null === $error ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            foreach ($notice as $message) {
                $request->getSession()->getFlashBag()->add('yxhk_inbox', $message);
            }

            return $this->redirectToRoute('yxhk_inbox');
        }
        $data = $inbox->read();
        $accounts = [];
        foreach ($profiles as $id => $profile) {
            $accounts[$id] = [
                'label' => $profile['label'] ?: $profile['username'],
                'email' => $profile['username'],
                'type' => $profile['type'],
                'configured' => !empty($profile['username']) && !empty($profile['password']),
                'state' => $data['accounts'][$id] ?? ['enabled' => false, 'status' => '未启用'],
            ];
        }
        $messages = array_filter($data['messages'], static fn (array $message): bool => isset($accounts[$message['profile_id']]));
        uasort($messages, static fn (array $a, array $b): int => $b['received_at'] <=> $a['received_at']);

        return $this->delegateView([
            'contentTemplate' => '@MauticCore/MailQueue/inbox.html.twig',
            'viewParameters' => [
                'accounts' => $accounts,
                'messages' => array_slice($messages, 0, 100, true),
                'notice' => $notice,
            ],
            'passthroughVars' => ['mauticContent' => 'yxhkInbox'],
        ]);
    }
}
