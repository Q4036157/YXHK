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
            $processed = count(array_filter($job['recipients'], fn (array $recipient): bool => 'pending' !== $recipient['status']));
            if (isset($job['pause_after']) && $processed >= $job['pause_after']) {
                $job['status'] = $processed < count($job['recipients']) ? 'paused' : 'completed';
                unset($job['pause_after']);

                continue;
            }
            foreach ($job['recipients'] as $index => &$recipient) {
                if ('pending' !== $recipient['status']) {
                    continue;
                }
                $cursor = $job['sender_cursor'] ?? count(array_filter(
                    $job['recipients'], fn (array $previous): bool => !empty($previous['sender'])
                ));
                $sender = $job['senders'][$cursor % count($job['senders'])];
                $recipient['status'] = 'sending';
                $recipient['sender'] = $sender;
                $recipient['attempted'] = $now;
                $state['last_attempt'] = $now;
                $job['sender_cursor'] = $cursor + 1;

                return ['job_id' => $id, 'index' => $index, 'job' => $job, 'recipient' => $recipient, 'sender' => $sender];
            }
            $job['status'] = 'completed';
        }

        return null;
    }
}
