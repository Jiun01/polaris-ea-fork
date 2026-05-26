<?php
$salt = bin2hex(random_bytes(16));
$password = 'admin123';
$half = (int)(strlen($salt) / 2);
$hash = hash('sha256', substr($salt, 0, $half) . $password . substr($salt, $half));
for ($i = 0; $i < 100000; $i++) {
    $hash = hash('sha256', $hash);
}
echo $salt . '|' . $hash . PHP_EOL;
