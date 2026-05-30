<?php defined('BASEPATH') or exit('No direct script access allowed');

// Add custom values by settings them to the $config array.
// Example: $config['smtp_host'] = 'smtp.gmail.com';
// @link https://codeigniter.com/user_guide/libraries/email.html

$config['useragent'] = 'Polaris Calendar';
$config['protocol'] = 'mail'; // or 'smtp'
$config['mailtype'] = 'html'; // or 'text'
// $config['smtp_debug'] = '0'; // or '1'
// $config['smtp_auth'] = TRUE; //or FALSE for anonymous relay.
// $config['smtp_host'] = '';
// $config['smtp_user'] = '';
// $config['smtp_pass'] = '';
// $config['smtp_crypto'] = 'ssl'; // or 'tls'
// $config['smtp_port'] = 25;

// Polaris install sender defaults (B23 — Phase 3). The tenant's
// business_email setting overrides these at runtime; these are the fallback
// when no tenant-level override has been written yet.
$config['from_name'] = 'Polaris';
$config['from_address'] = 'noreply@getpolaris.my';
$config['reply_to'] = 'support@getpolaris.my';
$config['crlf'] = "\r\n";
$config['newline'] = "\r\n";
