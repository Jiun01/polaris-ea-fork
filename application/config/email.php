<?php defined('BASEPATH') or exit('No direct script access allowed');

// Email configuration — env-driven (Polaris fork).
//
// PHP-FPM runs with clear_env=no (docker/php-fpm/zz-polaris-env.conf), so the
// EA_SMTP_* values arrive as container env vars and getenv() can read them here.
//
// When EA_SMTP_HOST is set the app sends via SMTP (PHPMailer, see
// Email_messages::get_php_mailer). Otherwise it falls back to PHP mail(), which
// has NO MTA in the php-fpm image and fails with "Could not instantiate mail
// function." — so prod MUST pass EA_SMTP_HOST (compose ea-php-fpm wires it from
// the shared .env EMAIL_SERVER_* values).
//
// @link https://codeigniter.com/user_guide/libraries/email.html

$config['useragent'] = 'Polaris Calendar';
$config['mailtype']  = 'html'; // or 'text'

$smtp_host = getenv('EA_SMTP_HOST') ?: '';

if ($smtp_host !== '') {
    $smtp_port = (int) (getenv('EA_SMTP_PORT') ?: 587);

    $config['protocol']  = 'smtp';
    $config['smtp_host'] = $smtp_host;
    $config['smtp_port'] = $smtp_port;
    $config['smtp_user'] = getenv('EA_SMTP_USER') ?: '';
    $config['smtp_pass'] = getenv('EA_SMTP_PASS') ?: '';
    $config['smtp_auth'] = ($config['smtp_user'] !== '');
    // 465 = implicit SSL; 587/25 = STARTTLS. Override with EA_SMTP_CRYPTO; set it
    // to 'none' for a plain (unencrypted) relay such as Mailpit in the E2E stack.
    $crypto = getenv('EA_SMTP_CRYPTO');
    if ($crypto === false || $crypto === '') {
        $crypto = ($smtp_port === 465 ? 'ssl' : 'tls');
    }
    $config['smtp_crypto'] = ($crypto === 'none') ? '' : $crypto;
} else {
    $config['protocol'] = 'mail';
}

// Polaris install sender defaults (B23 — Phase 3). The tenant's business_email
// setting overrides these at runtime; these are the fallback when no tenant-level
// override has been written yet. From address must be a verified sender on the
// SMTP provider (Zoho: any address on the verified getpolaris.my domain).
$config['from_name']    = getenv('EA_SMTP_FROM_NAME') ?: 'Polaris';
$config['from_address'] = getenv('EA_SMTP_FROM_ADDRESS') ?: 'noreply@getpolaris.my';
$config['reply_to']     = 'support@getpolaris.my';
$config['crlf']    = "\r\n";
$config['newline'] = "\r\n";
