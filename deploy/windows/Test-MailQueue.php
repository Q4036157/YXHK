<?php

declare(strict_types=1);

// This focused regression test needs no database or SMTP credentials.
$root = dirname(__DIR__, 2);
foreach (['CsvRecipients', 'QueueSchedule'] as $class) {
    require_once $root.'/app/bundles/CoreBundle/MailQueue/'.$class.'.php';
}
use Mautic\CoreBundle\MailQueue\CsvRecipients;
use Mautic\CoreBundle\MailQueue\QueueSchedule;

function check(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException($label);
    }
}

$file = tempnam(sys_get_temp_dir(), 'yxhk-queue-');
try {
    file_put_contents($file, "\xEF\xBB\xBFemail,role,user_id,nickname\nADMIN@example.com,成员,1,甲\nadmin@example.com,管理员,1,甲\na@example.com,成员,2,乙\nA@example.com,成员,2,乙\nb@example.com,成员,3,丙\ninvalid,成员,4,丁\n");
    $import = CsvRecipients::read($file, 10);
    check(2 === count($import['recipients']), 'CSV cleaning count');
    check(2 === $import['counts']['excluded'], 'Exclude admins in every duplicate role');
    check(1 === $import['counts']['invalid'] && 1 === $import['counts']['duplicates'], 'Invalid and duplicate emails');
    check(1 === count(CsvRecipients::read($file, 1)['recipients']), 'Batch limit');
    $state = ['interval' => 20, 'last_attempt' => 0, 'cursor' => 0, 'heartbeat' => 0,
        'jobs' => ['test' => ['status' => 'running', 'senders' => ['qq-1', 'google-1', 'qq-2', '163-1', 'outlook-1'],
            'recipients' => array_fill(0, 7, ['email' => 'test@example.com', 'status' => 'pending'])]]];
    $order = [];
    for ($i = 0; $i < 6; ++$i) {
        $task = QueueSchedule::claim($state, 1000 + $i * 20);
        check(null !== $task, 'Scheduled claim');
        $order[] = $task['sender'];
        check(null === QueueSchedule::claim($state, 1019 + $i * 20), 'Never send early');
        $state['jobs']['test']['recipients'][$task['index']]['status'] = 'sent';
    }
    check(['qq-1', 'google-1', 'qq-2', '163-1', 'outlook-1', 'qq-1'] === $order, 'Multi-account round robin');
    $state['interval'] = 60;
    check(null === QueueSchedule::claim($state, 1120), 'Changed interval enforced');
    $state['jobs']['test']['status'] = 'paused';
    check(null === QueueSchedule::claim($state, 1160), 'Pause enforced');
    $state['jobs']['test']['status'] = 'running';
    check(6 === QueueSchedule::claim($state, 1160)['index'], 'Restart does not resend sent records');
    echo "PASS: CSV cleaning, limits, 20/60-second spacing, multi-account rotation, pause and resume.\n";
} finally {
    unlink($file);
}
