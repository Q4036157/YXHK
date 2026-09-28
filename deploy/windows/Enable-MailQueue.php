<?php

declare(strict_types=1);

$file = $argv[1] ?? '';
if (!is_file($file)) {
    throw new RuntimeException('Missing tenant configuration');
}
require $file;
if (!isset($parameters) || !is_array($parameters)) {
    throw new RuntimeException('Invalid tenant configuration');
}
$profilesFile = dirname(dirname($file)).'/mail-queue/profiles.json';
$profiles = json_decode(file_get_contents($profilesFile), true, 512, JSON_THROW_ON_ERROR);
$primary = current(array_filter($profiles, fn ($profile) => !empty($profile['enabled']) && !empty($profile['host'])));
if (!$primary) {
    throw new RuntimeException('No enabled SMTP account');
}
if ('yxhk://default' !== ($parameters['mailer_dsn'] ?? '')) {
    if (!copy($file, $file.'.before-mailqueue-'.date('Ymd-His'))) {
        throw new RuntimeException('Cannot back up tenant configuration');
    }
}
$parameters['mailer_dsn'] = 'yxhk://default';
$parameters['messenger_dsn_email'] = 'sync://';
$parameters['mailer_from_email'] = $primary['from'];
$parameters['mailer_from_name'] = $primary['name'] ?? '';
if (false === file_put_contents($file, "<?php\n\$parameters = ".var_export($parameters, true).";\n", LOCK_EX)) {
    throw new RuntimeException('Cannot write tenant configuration');
}
echo "Tenant mail queue transport enabled; credentials remain private.\n";
