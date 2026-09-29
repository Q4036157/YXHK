<?php

declare(strict_types=1);

namespace Mautic\CoreBundle\MailQueue;

use Doctrine\Persistence\ManagerRegistry;
use Mautic\EmailBundle\Entity\Email;
use Mautic\EmailBundle\Model\EmailModel;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Model\LeadModel;

final class QueueService
{
    public function __construct(
        private readonly QueueStore $store,
        private readonly SenderContext $context,
        private readonly ManagerRegistry $doctrine,
        private readonly EmailModel $emails,
        private readonly LeadModel $leads,
        private readonly UnsubscribeClient $unsubscribe,
        private readonly UnsubscribeSync $unsubscribeSync,
    ) {
    }

    public static function fingerprint(Email $email): string
    {
        return hash('sha256', json_encode([$email->getSubject(), $email->getCustomHtml(), $email->getPlainText()]));
    }

    public function create(string $name, int $emailId, array $import, array $senders, int $owner): string
    {
        $email = $this->emails->getEntity($emailId);
        if (!$email instanceof Email || !$email->getSubject() || (!$email->getCustomHtml() && !$email->getPlainText())) {
            throw new \InvalidArgumentException('请选择已有主题和正文的邮件模板。');
        }
        $profiles = $this->store->profiles();
        if ([] === $senders || count(array_unique($senders)) !== count($senders)) {
            throw new \InvalidArgumentException('请选择至少一个不重复的发件账号。');
        }
        foreach ($senders as $id) {
            if (!QueueTransport::configured($profiles[$id] ?? [])) {
                throw new \InvalidArgumentException('所选发件账号未完整配置或未启用。');
            }
        }
        $id = bin2hex(random_bytes(8));
        $this->store->transaction(function (array &$state) use ($id, $name, $emailId, $email, $import, $senders, $owner): void {
            $state['jobs'][$id] = ['id' => $id, 'name' => mb_substr($name, 0, 100), 'email_id' => $emailId,
                'subject' => $email->getSubject(), 'fingerprint' => self::fingerprint($email), 'owner' => $owner,
                'senders' => $senders, 'recipients' => $import['recipients'], 'counts' => $import['counts'],
                'status' => 'paused', 'created' => time(), 'error' => ''];
        });

        return $id;
    }

    public function action(string $id, string $action): void
    {
        $this->store->transaction(function (array &$state) use ($id, $action): void {
            if (!isset($state['jobs'][$id])) {
                throw new \InvalidArgumentException('找不到发送批次。');
            }
            $job = &$state['jobs'][$id];
            if (!in_array($action, ['start', 'pause', 'stop'], true)) {
                throw new \InvalidArgumentException('操作无效。');
            }
            if ('start' === $action) {
                if (!in_array($job['status'], ['paused'], true) || !array_filter($job['recipients'], fn ($r) => 'pending' === $r['status'])) {
                    throw new \InvalidArgumentException('此批次没有可启动的待发送记录。');
                }
                foreach ($state['jobs'] as $otherId => $other) {
                    if ($otherId !== $id && 'running' === $other['status']) {
                        throw new \InvalidArgumentException('请先暂停当前批次。');
                    }
                }
                $job['status'] = 'running';
                $job['error'] = '';
            } else {
                $job['status'] = 'stop' === $action ? 'stopped' : 'paused';
            }
        });
    }

    public function recover(): void
    {
        $this->store->transaction(function (array &$state): void {
            foreach ($state['jobs'] as &$job) {
                foreach ($job['recipients'] as &$recipient) {
                    if ('sending' === $recipient['status']) {
                        $recipient['status'] = 'uncertain';
                        $job['status'] = 'paused';
                        $job['error'] = '进程上次在投递中退出，该条结果待人工核对，不会自动重发。';
                    }
                }
            }
        });
    }

    public function tick(int $now): void
    {
        if (!$this->unsubscribeSync->tick($now)) {
            return;
        }
        $task = $this->store->transaction(function (array &$state) use ($now): ?array {
            return QueueSchedule::claim($state, $now);
        });
        if (null === $task) {
            return;
        }
        $status = 'failed';
        $error = '';
        $this->context->profile = $task['sender'];
        $this->context->accepted = 0;
        $this->context->recipient = $task['recipient']['email'];
        $this->context->unsubscribeUrl = null;
        $this->context->unsubscribed = false;
        $this->context->unsubscribeUnavailable = false;
        try {
            // MySQL can expire connections while this worker is idle.
            foreach ($this->doctrine->getConnections() as $connection) {
                $connection->close();
            }
            $email = $this->emails->getEntity($task['job']['email_id']);
            if ($email instanceof Email) {
                $this->doctrine->getManager()->refresh($email);
            }
            if (!$email instanceof Email || self::fingerprint($email) !== $task['job']['fingerprint']) {
                throw new \RuntimeException('邮件模板已修改，请创建新批次确认正文。');
            }
            $lead = $this->doctrine->getRepository(Lead::class)->findOneBy(['email' => $task['recipient']['email']]);
            if (!$lead instanceof Lead) {
                $lead = $this->leads->getEntity();
                $lead->imported = true;
                $lead->setEmail($task['recipient']['email']);
                if ('' !== $task['recipient']['firstname']) {
                    $lead->addUpdatedField('firstname', $task['recipient']['firstname']);
                }
                $lead->setDateIdentified(new \DateTime());
                $lead->setOwner($this->doctrine->getRepository(\Mautic\UserBundle\Entity\User::class)->find($task['job']['owner']));
                $this->leads->saveEntity($lead);
            }
            $dnc = $this->emails->getRepository()->getDoNotEmailList([$lead->getId()]);
            if ([] !== $dnc) {
                $status = 'skipped';
            } else {
                $prepared = $this->unsubscribe->prepare($lead->getEmail());
                if ($prepared['blocked']) {
                    $this->unsubscribeSync->block($lead->getEmail());
                    $status = 'skipped';
                } else {
                    $this->context->unsubscribeUrl = $prepared['url'];
                    $profile = $lead->getProfileFields();
                    $profile['id'] = $lead->getId();
                    $profile['email'] = $lead->getEmail();
                    $success = $this->emails->sendEmail($email, $profile, ['allowResends' => false, 'ignoreDNC' => false, 'dnc_as_error' => true]);
                    if ($this->context->unsubscribeUnavailable) {
                        throw new UnsubscribeUnavailable('204 退订检查失败，批次已暂停；连接恢复后可继续。');
                    }
                    if ($this->context->unsubscribed) {
                        $this->unsubscribeSync->block($lead->getEmail());
                        $status = 'skipped';
                    } elseif (true === $success && 1 === $this->context->accepted) {
                        $status = 'sent';
                    } elseif (0 === $this->context->accepted && true === $success) {
                        $status = 'skipped';
                    } else {
                        throw new \RuntimeException('SMTP 未确认接收邮件，批次已暂停。请核对发送账号和原生日志。');
                    }
                }
            }
        } catch (UnsubscribeUnavailable $exception) {
            $status = 'pending';
            $error = $exception->getMessage();
        } catch (\Throwable $exception) {
            $error = '发送处理失败，请检查模板、SMTP 授权和配额。不会自动重发此条记录。';
            $status = $this->context->accepted > 0 ? 'uncertain' : 'failed';
            if ($this->context->unsubscribeUnavailable && 0 === $this->context->accepted) {
                $status = 'pending';
                $error = '204 退订检查失败，批次已暂停；连接恢复后可继续。';
            }
        } finally {
            $this->context->profile = null;
        }
        $this->store->transaction(function (array &$state) use ($task, $status, $error): void {
            $job = &$state['jobs'][$task['job_id']];
            $recipient = &$job['recipients'][$task['index']];
            $recipient['status'] = $status;
            $recipient['finished'] = time();
            if (in_array($status, ['failed', 'uncertain', 'pending'], true)) {
                $job['status'] = 'paused';
                $job['error'] = $error;
            } elseif (!array_filter($job['recipients'], fn ($r) => in_array($r['status'], ['pending', 'sending'], true))) {
                $job['status'] = 'completed';
            }
        });
        $this->doctrine->getManager()->clear();
        $this->context->recipient = null;
        $this->context->unsubscribeUrl = null;
    }
}
