<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
$errors = [];
$entries = 0;
$files = 0;

function placeholders(string $text): array
{
    preg_match_all('/%[A-Za-z0-9_.-]+%|\{[A-Za-z0-9_.=|:-]+\}|\|[A-Z_]+\|/', $text, $matches);
    $tokens = $matches[0];
    sort($tokens);

    return $tokens;
}

foreach (['app/bundles', 'plugins'] as $base) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$base, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        $path = str_replace('\\', '/', $file->getPathname());
        if (!preg_match('~([^/]+Bundle)/Translations/en_US/([^/]+)\.ini$~', $path, $match)) {
            continue;
        }
        $english = parse_ini_file($path, false, INI_SCANNER_NORMAL);
        $chinesePath = __DIR__.'/zh_CN/'.$match[1].'/'.$match[2].'.ini';
        $chinese = is_file($chinesePath) ? parse_ini_file($chinesePath, false, INI_SCANNER_NORMAL) : false;
        ++$files;
        if (false === $english || false === $chinese) {
            $errors[] = 'Invalid or missing catalogue: '.$chinesePath;
            continue;
        }
        foreach ($english as $key => $value) {
            ++$entries;
            if (!array_key_exists($key, $chinese) || ('' !== $value && '' === $chinese[$key])) {
                $errors[] = 'Missing translation: '.$key;
            } elseif (placeholders($value) !== placeholders($chinese[$key])) {
                $errors[] = 'Placeholder mismatch: '.$key;
            }
        }
    }
}

echo json_encode(['files' => $files, 'entries' => $entries, 'errors' => $errors], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT).PHP_EOL;
exit([] === $errors ? 0 : 1);
