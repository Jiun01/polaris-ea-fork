<?php
require '/var/www/html/vendor/autoload.php';
$tenant_id = $argv[1] ?? 'aaaaaaaa-0000-0000-0000-000000000001';
$payload = [
    'iss'       => 'polaris',
    'aud'       => 'ea-fork',
    'tenant_id' => $tenant_id,
    'exp'       => time() + 3600,
];
echo Firebase\JWT\JWT::encode($payload, getenv('POLARIS_EA_JWT_SECRET'), 'HS256') . PHP_EOL;
