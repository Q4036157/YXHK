<?php

declare(strict_types=1);

try {
    $tenant = $argv[1] ?? '';
    $mode = $argv[2] ?? 'verify';
    if (!preg_match('/^[a-z][a-z0-9-]{0,31}$/D', $tenant) || !in_array($mode, ['prepare', 'verify'], true)) {
        throw new RuntimeException('Invalid database check arguments.');
    }
    $config = 'E:/pxy-runtime/YXHK/tenants/'.$tenant.'/config/local.php';
    $parameters = [];
    require $config;
    $pdo = new PDO('mysql:host='.$parameters['db_host'].';port='.$parameters['db_port'].';dbname='.$parameters['db_name'].';charset=utf8mb4', $parameters['db_user'], $parameters['db_password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    if ($mode === 'prepare' && !$tables) {
        if (!empty($parameters['site_url'])) {
            $backup = $config.'.before-install-'.bin2hex(random_bytes(6));
            if (!copy($config, $backup)) {
                throw new RuntimeException('Could not back up private configuration.');
            }
            $parameters['site_url'] = '';
            if (false === file_put_contents($config, "<?php\n\$parameters = ".var_export($parameters, true).";\n", LOCK_EX)) {
                throw new RuntimeException('Could not repair installation marker.');
            }
        }
        echo "Empty customer database ready for installation.\n";
        exit(0);
    }
    $usersTable = ($parameters['db_table_prefix'] ?? '').'users';
    if (!in_array($usersTable, $tables, true) || !$pdo->query('SELECT COUNT(*) FROM `'.str_replace('`', '``', $usersTable).'`')->fetchColumn() || empty($parameters['site_url'])) {
        throw new RuntimeException('Database installation is incomplete; existing tables and configuration preserved.');
    }
    echo json_encode(['status' => 'database_installed', 'table_count' => count($tables), 'administrator_present' => true], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (PDOException $exception) {
    fwrite(STDERR, "Customer database connection or schema check failed.\n");
    exit(1);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage().PHP_EOL);
    exit(1);
}
