<?php defined('BASEPATH') or exit('No direct script access allowed');

/*
|--------------------------------------------------------------------------
| App Configuration
|--------------------------------------------------------------------------
|
| Declare some of the global config values of Easy!Appointments.
|
*/

$config['version'] = '1.6.0'; // This must be changed manually.

$config['url'] = Config::BASE_URL;

$config['debug'] = Config::DEBUG_MODE;

// Fallback cache-buster for plain local `docker build`. In prod every EA image
// build auto-stamps the unique image tag here at build time (EA_ASSET_TOKEN arg,
// see Dockerfile + polaris/infra/docker-bake.hcl), so all asset URLs bust per
// release without touching this. Only bump by hand for a non-bake local image.
$config['cache_busting_token'] = 'TSJ85';
