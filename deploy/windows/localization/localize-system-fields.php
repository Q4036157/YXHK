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
    $parameters['db_user'], $parameters['db_password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);
$root = dirname(__DIR__, 3);
$english = parse_ini_file($root.'/app/bundles/InstallBundle/Translations/en_US/fixtures.ini');
$chinese = parse_ini_file(__DIR__.'/zh_CN/InstallBundle/fixtures.ini');
$fieldTable = '`'.$prefix.'lead_fields`';
$roleTable = '`'.$prefix.'roles`';
$rows = $pdo->query('SELECT id, alias, label FROM '.$fieldTable)->fetchAll(PDO::FETCH_ASSOC);
$selected = array_values(array_filter($rows, static function (array $row) use ($english, $chinese): bool {
    $key = 'mautic.lead.field.'.$row['alias'];

    return isset($english[$key], $chinese[$key]) && $row['label'] === $english[$key];
}));
$roles = $pdo->query('SELECT id, name, description FROM '.$roleTable.' WHERE is_admin = 1')->fetchAll(PDO::FETCH_ASSOC);
$roles = array_values(array_filter($roles, static fn (array $row): bool => $row['name'] === $english['mautic.user.role.admin.name']));
if ([] !== $selected || [] !== $roles) {
    $backup = $runtime.'/system-field-labels-'.date('Ymd-His').'.json';
    if (false === file_put_contents($backup, json_encode(['fields' => $selected, 'roles' => $roles], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR))) {
        throw new RuntimeException('Cannot save system label backup');
    }
}
$pdo->beginTransaction();
try {
    $update = $pdo->prepare('UPDATE '.$fieldTable.' SET label = ? WHERE id = ? AND alias = ? AND label = ?');
    $count = 0;
    foreach ($selected as $row) {
        $update->execute([$chinese['mautic.lead.field.'.$row['alias']], $row['id'], $row['alias'], $row['label']]);
        $count += $update->rowCount();
    }
    $roleUpdate = $pdo->prepare('UPDATE '.$roleTable.' SET name = ?, description = ? WHERE id = ? AND name = ? AND is_admin = 1');
    foreach ($roles as $row) {
        $description = $row['description'] === $english['mautic.user.role.admin.description'] ? $chinese['mautic.user.role.admin.description'] : $row['description'];
        $roleUpdate->execute([$chinese['mautic.user.role.admin.name'], $description, $row['id'], $row['name']]);
    }
    $pdo->commit();
    echo json_encode(['status' => 'system_field_labels_localized', 'tenant' => $tenant, 'fields' => $count, 'roles' => count($roles)]).PHP_EOL;
} catch (Throwable $error) {
    $pdo->rollBack();
    throw $error;
}
