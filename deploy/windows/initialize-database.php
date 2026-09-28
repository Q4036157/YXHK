<?php

declare(strict_types=1);

// Administrator credentials arrive on stdin and are never persisted.
try {
    $input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    $tenant = $input['tenant'] ?? '';
    if (!preg_match('/^[a-z][a-z0-9-]{0,31}$/D', $tenant)) {
        throw new RuntimeException('Invalid tenant identifier.');
    }
    $runtime = 'E:/pxy-runtime/YXHK/tenants/'.$tenant;
    $config = $runtime.'/config/local.php';
    if (file_exists($config)) {
        throw new RuntimeException('Customer configuration already exists; refusing to overwrite.');
    }
    $database = 'yxhk_'.str_replace('-', '_', $tenant);
    $username = 'yxhk_'.substr(str_replace('-', '_', $tenant), 0, 16).'_'.substr(hash('sha256', $tenant), 0, 8);
    $pdo = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', $input['user'], $input['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    unset($input['password']);
    $query = $pdo->prepare('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
    $query->execute([$database]);
    if ($query->fetchColumn()) {
        throw new RuntimeException('Customer database already exists; refusing to reuse or overwrite.');
    }
    $query = $pdo->prepare('SELECT User FROM mysql.user WHERE User = ?');
    $query->execute([$username]);
    if ($query->fetchColumn()) {
        throw new RuntimeException('Customer database account already exists.');
    }
    $databasePassword = bin2hex(random_bytes(24));
    $account = $pdo->quote($username)."@'127.0.0.1'";
    $pdo->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $pdo->exec('CREATE USER '.$account.' IDENTIFIED BY '.$pdo->quote($databasePassword));
    $pdo->exec('GRANT ALL PRIVILEGES ON `'.$database.'`.* TO '.$account);
    $adminPassword = bin2hex(random_bytes(16));
    $parameters = [
        'db_driver' => 'pdo_mysql',
        'db_host' => '127.0.0.1',
        'db_port' => 3306,
        'db_name' => $database,
        'db_user' => $username,
        'db_password' => $databasePassword,
        'db_table_prefix' => '',
        'secret_key' => bin2hex(random_bytes(32)),
        // Mautic uses site_url as the installed marker, before checking schema.
        'site_url' => '',
        'cache_path' => $runtime.'/cache',
        'log_path' => $runtime.'/logs',
        'tmp_path' => $runtime.'/tmp',
        'import_leads_dir' => $runtime.'/imports',
        'import_campaigns_dir' => $runtime.'/imports',
        'admin_username' => 'owner',
        'admin_email' => $input['admin_email'],
        'admin_password' => $adminPassword,
        'mailer_dsn' => 'null://null',
        'mailer_from_name' => 'YXHK',
        'mailer_from_email' => $input['admin_email'],
        'locale' => 'zh_CN',
        'default_timezone' => 'Asia/Shanghai',
        'api_enabled' => false,
        'api_enable_basic_auth' => false,
    ];
    if (false === file_put_contents($config, "<?php\n\$parameters = ".var_export($parameters, true).";\n", LOCK_EX)) {
        throw new RuntimeException('Could not save private customer configuration.');
    }
    $access = ['site_url' => $input['site_url'], 'username' => 'owner', 'password' => $adminPassword];
    if (false === file_put_contents($runtime.'/config/initial-access.json', json_encode($access, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), LOCK_EX)) {
        throw new RuntimeException('Could not save private initial login.');
    }
    echo json_encode(['status' => 'database_created', 'tenant' => $tenant, 'database' => $database], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (PDOException $exception) {
    // Driver messages can contain identifiers; do not expose raw exceptions.
    fwrite(STDERR, 'Database setup failed: check local administrator login and CREATE DATABASE/USER privileges.'.PHP_EOL);
    exit(1);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage().PHP_EOL);
    exit(1);
}
