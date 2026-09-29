<?php

declare(strict_types=1);

namespace Mautic\CoreBundle\Controller;

use Doctrine\Persistence\ManagerRegistry;
use Mautic\CoreBundle\MailQueue\CsvRecipients;
use Mautic\CoreBundle\MailQueue\QueueService;
use Mautic\CoreBundle\MailQueue\QueueStore;
use Mautic\CoreBundle\MailQueue\QueueTransport;
use Mautic\EmailBundle\Entity\Email;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class MailQueueController extends AbstractController
{
    public function indexAction(Request $request, QueueStore $store, QueueService $queue, ManagerRegistry $doctrine): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        $notice = $request->getSession()->getFlashBag()->get('yxhk_queue');
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('yxhk_queue', $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException('页面已过期，请刷新重试。');
            }
            try {
                $action = $request->request->getString('action');
                if ('settings' === $action) {
                    $interval = $request->request->getInt('interval');
                    if ($interval < 1 || $interval > 86400) {
                        throw new \InvalidArgumentException('间隔需要在 1 至 86400 秒之间。');
                    }
                    $store->transaction(function (array &$state) use ($interval): void {
                        $state['interval'] = $interval;
                    });
                    $notice[] = '发送间隔已保存，下次投递按新间隔执行。';
                } elseif ('profile' === $action) {
                    $profiles = $store->profiles();
                    $id = $request->request->getString('profile_id');
                    if ('' === $id) {
                        $id = bin2hex(random_bytes(8));
                    } elseif (!isset($profiles[$id])) {
                        throw new \InvalidArgumentException('发件账号不存在。');
                    }
                    $type = $request->request->getString('type');
                    $defaults = ['qq' => ['smtp.qq.com', 465], 'google' => ['smtp.gmail.com', 465],
                        '163' => ['smtp.163.com', 465], '126' => ['smtp.126.com', 465], 'outlook' => ['smtp.office365.com', 587]];
                    if (!isset($defaults[$type])) {
                        throw new \InvalidArgumentException('邮箱类型无效。');
                    }
                    $store->updateProfile($id, function (array $profile) use ($request, $type, $defaults): array {
                        $profile['type'] = $type;
                        $profile['label'] = mb_substr(trim($request->request->getString('label')), 0, 100);
                        $profile['host'] = trim($request->request->getString('host')) ?: $defaults[$type][0];
                        $profile['port'] = $request->request->getInt('port') ?: $defaults[$type][1];
                        $profile['security'] = $request->request->getString('security');
                        $profile['username'] = trim($request->request->getString('username'));
                        $profile['from'] = trim($request->request->getString('from'));
                        $profile['name'] = trim($request->request->getString('name'));
                        $profile['auth'] = $request->request->getString('auth', 'password');
                        $profile['enabled'] = $request->request->getBoolean('enabled');
                        foreach (['password', 'oauth_client_id', 'oauth_client_secret', 'oauth_refresh_token'] as $secret) {
                            $value = trim($request->request->getString($secret));
                            if ('' !== $value) {
                                $profile[$secret] = $value;
                            }
                        }
                        if (!preg_match('/^[a-zA-Z0-9.-]+$/D', $profile['host']) || $profile['port'] < 1 || $profile['port'] > 65535
                            || !in_array($profile['security'], ['smtps', 'starttls'], true)
                            || !in_array($profile['auth'], ['password', 'oauth2'], true)
                            || false === filter_var($profile['from'], FILTER_VALIDATE_EMAIL)) {
                            throw new \InvalidArgumentException('请检查 SMTP 主机、端口、加密方式和发件地址。');
                        }
                        if ($profile['enabled'] && !QueueTransport::configured($profile)) {
                            throw new \InvalidArgumentException('启用前需要填写完整的授权信息。');
                        }

                        return $profile;
                    });
                    $notice[] = '发件账号已保存。授权码留空时保留原值。';
                } elseif ('create' === $action) {
                    $upload = $request->files->get('csv');
                    if (!$upload instanceof \Symfony\Component\HttpFoundation\File\UploadedFile || !$upload->isValid()) {
                        throw new \InvalidArgumentException('请选择有效的 CSV 或 TXT 文件。');
                    }
                    if (!$request->request->getBoolean('confirmed')) {
                        throw new \InvalidArgumentException('请先确认本批名单和邮件正文。');
                    }
                    $import = CsvRecipients::read($upload->getPathname(), $request->request->getInt('limit', 10), strtolower($upload->getClientOriginalExtension()));
                    $queue->create(trim($request->request->getString('name')) ?: '邮件批次',
                        $request->request->getInt('email_id'), $import, $request->request->all('senders'), $this->getUser()->getId());
                    $notice[] = '名单已清理并创建暂停批次，请检查名单后点击开始。';
                } elseif (in_array($action, ['start', 'pause', 'stop'], true)) {
                    $queue->action($request->request->getString('job_id'), $action);
                    $notice[] = '队列状态已更新；已进入 SMTP 的一封可能仍会完成。';
                } else {
                    throw new \InvalidArgumentException('操作无效。');
                }
            } catch (\InvalidArgumentException|\RuntimeException $exception) {
                $notice[] = $exception->getMessage();
            }
            foreach ($notice as $message) {
                $request->getSession()->getFlashBag()->add('yxhk_queue', $message);
            }

            return $this->redirectToRoute('yxhk_mail_queue');
        }
        $profiles = $store->profiles();
        $safeProfiles = [];
        foreach ($profiles as $id => $profile) {
            $safeProfiles[$id] = array_intersect_key($profile, array_flip(['type', 'label', 'host', 'port', 'security', 'username', 'from', 'name', 'enabled', 'auth']));
            $safeProfiles[$id]['configured'] = QueueTransport::configured($profile);
        }
        $state = $store->state();
        $jobs = [];
        foreach (array_reverse($state['jobs'], true) as $job) {
            $job['progress'] = array_count_values(array_column($job['recipients'], 'status'));
            $job['total'] = count($job['recipients']);
            $job['preview'] = array_slice($job['recipients'], 0, 10);
            unset($job['recipients']);
            $jobs[] = $job;
        }

        return $this->render('@MauticCore/MailQueue/index.html.twig', ['profiles' => $safeProfiles, 'state' => $state,
            'jobs' => $jobs, 'notice' => $notice, 'online' => time() - $state['heartbeat'] < 20,
            'emails' => $doctrine->getRepository(Email::class)->findBy(['variantParent' => null], ['id' => 'DESC'])]);
    }
}
