<?php

declare(strict_types=1);

try {
    $tenant = $argv[1] ?? '';
    if (!preg_match('/^[a-z][a-z0-9-]{0,31}$/D', $tenant)) {
        throw new RuntimeException('Invalid tenant.');
    }
    $directory = 'E:/pxy-runtime/YXHK/tenants/'.$tenant.'/cache';
    $config = 'E:/pxy-deploy/YXHK/tools/php-8.3.35/extras/ssl/openssl.cnf';
    $keyPath = $directory.'/saml_default.key';
    $certPath = $directory.'/saml_default.crt';
    if (file_exists($keyPath) || file_exists($certPath)) {
        if (!file_exists($keyPath) || !file_exists($certPath) || !openssl_x509_check_private_key(file_get_contents($certPath), file_get_contents($keyPath))) {
            throw new RuntimeException('Existing SAML credentials are incomplete or invalid; refusing to overwrite.');
        }
        echo "Existing SAML credentials are valid.\n";
        exit(0);
    }
    if (!is_file($config) || !is_dir($directory)) {
        throw new RuntimeException('Missing OpenSSL configuration or customer cache directory.');
    }
    $options = ['config' => $config, 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'digest_alg' => 'sha256'];
    $key = openssl_pkey_new($options);
    if (!$key) {
        throw new RuntimeException('Private key generation failed.');
    }
    $csr = openssl_csr_new(['commonName' => 'Mautic dummy cert'], $key, $options);
    $cert = $csr ? openssl_csr_sign($csr, null, $key, 365, $options) : false;
    if (!$cert || !openssl_pkey_export($key, $keyPem, '', $options) || !openssl_x509_export($cert, $certPem)) {
        throw new RuntimeException('Certificate generation failed.');
    }
    if (file_put_contents($keyPath, $keyPem, LOCK_EX) === false || file_put_contents($certPath, $certPem, LOCK_EX) === false) {
        throw new RuntimeException('Could not save private SAML credentials.');
    }
    echo "Customer SAML credentials prepared.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage().PHP_EOL);
    exit(1);
}
