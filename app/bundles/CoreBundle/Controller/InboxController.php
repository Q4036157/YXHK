<?php

declare(strict_types=1);

namespace Mautic\CoreBundle\Controller;

use Mautic\CoreBundle\MailQueue\InboxStore;
use Mautic\CoreBundle\MailQueue\QueueStore;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class InboxController extends CommonController
{
    public function indexAction(Request $request, QueueStore $queue, InboxStore $inbox): Response
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
                        $account['next_at'] = $enabled ? max(time(), ($account['last_attempt'] ?? 0) + 900) : 0;
                        $account['status'] = $enabled ? '等待首次检查' : '已关闭';
                        $data['accounts'][$id] = $account;
                    });
                    $notice[] = $enabled ? '已启用收件监控，后台每分钟最多检查一个账号。' : '已关闭此账号的自动收取。';
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
            if ('account' === $action && $request->isXmlHttpRequest()) {
                $state = $inbox->read()['accounts'][$id] ?? [];

                return $this->json([
                    'ok' => null === $error,
                    'enabled' => !empty($state['enabled']),
                    'status' => $state['status'] ?? '未启用',
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
