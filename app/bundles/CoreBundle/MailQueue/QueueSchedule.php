<?php

declare(strict_types=1);

namespace Mautic\CoreBundle\MailQueue;

final class QueueSchedule
{
    public static function claim(array &$state, int $now): ?array
    {
        if ($now - $state['heartbeat'] >= 5) {
            $state['heartbeat'] = $now;
        }
        if ($now < $state['last_attempt'] + $state['interval']) {
            return null;
        }
        foreach ($state['jobs'] as $id => &$job) {
            if ('running' !== $job['status']) {
                continue;
            }
            foreach ($job['recipients'] as $index => &$recipient) {
                if ('pending' !== $recipient['status']) {
                    continue;
                }
                $sender = $job['senders'][$state['cursor'] % count($job['senders'])];
                $recipient['status'] = 'sending';
                $recipient['sender'] = $sender;
                $recipient['attempted'] = $now;
                $state['last_attempt'] = $now;
                ++$state['cursor'];

                return ['job_id' => $id, 'index' => $index, 'job' => $job, 'recipient' => $recipient, 'sender' => $sender];
            }
            $job['status'] = 'completed';
        }

        return null;
    }
}
