<?php

declare(strict_types=1);

$tenant = $argv[1] ?? 'owner';
if (!preg_match('/^[a-z][a-z0-9-]{0,31}$/', $tenant)) {
    throw new InvalidArgumentException('Invalid tenant');
}
$runtime = 'E:/pxy-runtime/YXHK/tenants/'.$tenant;
require $runtime.'/config/local.php';
$prefix = $parameters['db_table_prefix'] ?? '';
if (!preg_match('/^[a-zA-Z0-9_]*$/', $prefix)) {
    throw new RuntimeException('Invalid table prefix');
}
$pdo = new PDO(
    'mysql:host='.$parameters['db_host'].';port='.$parameters['db_port'].';dbname='.$parameters['db_name'].';charset=utf8mb4',
    $parameters['db_user'],
    $parameters['db_password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);
$labels = [
    'Visits published Pages' => ['page.hits', '已发布着陆页访问'],
    'Downloads of all Assets' => ['asset.downloads', '全部资源下载'],
    'Submissions of published Forms' => ['form.submissions', '已发布表单提交'],
    'All Emails' => ['email.stats', '全部邮件'],
    'Leads and Points' => ['lead.pointlog', '联系人与积分'],
];
$table = '`'.$prefix.'reports`';
$rows = $pdo->query('SELECT id, name, source FROM '.$table.' WHERE `system` = 1')->fetchAll(PDO::FETCH_ASSOC);
$selected = array_values(array_filter($rows, static fn (array $row): bool => isset($labels[$row['name']]) && $labels[$row['name']][0] === $row['source']));
if ([] !== $selected) {
    $backup = $runtime.'/system-report-labels-'.date('Ymd-His').'.json';
    if (false === file_put_contents($backup, json_encode($selected, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR))) {
        throw new RuntimeException('Cannot save report label backup');
    }
}
$pdo->beginTransaction();
try {
    $update = $pdo->prepare('UPDATE '.$table.' SET name = ? WHERE id = ? AND name = ? AND source = ? AND `system` = 1');
    $count = 0;
    foreach ($selected as $row) {
        $update->execute([$labels[$row['name']][1], $row['id'], $row['name'], $row['source']]);
        $count += $update->rowCount();
    }
    $pdo->commit();
    echo json_encode(['status' => 'system_report_labels_localized', 'tenant' => $tenant, 'updated' => $count]).PHP_EOL;
} catch (Throwable $error) {
    $pdo->rollBack();
    throw $error;
}
