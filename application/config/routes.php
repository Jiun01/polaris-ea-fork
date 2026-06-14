<?php defined('BASEPATH') or exit('No direct script access allowed');

/*
| -------------------------------------------------------------------------
| URI ROUTING
| -------------------------------------------------------------------------
| This file lets you re-map URI requests to specific controller functions.
|
| Typically there is a one-to-one relationship between a URL string
| and its corresponding controller class/method. The segments in a
| URL normally follow this pattern:
|
|	example.com/class/method/id/
|
| In some instances, however, you may want to remap this relationship
| so that a different class/function is called than the one
| corresponding to the URL.
|
| Please see the user guide for complete details:
|
|	https://codeigniter.com/userguide3/general/routing.html
|
| -------------------------------------------------------------------------
| RESERVED ROUTES
| -------------------------------------------------------------------------
|
| There are three reserved routes:
|
|	$route['default_controller'] = 'welcome';
|
| This route indicates which controller class should be loaded if the
| URI contains no data. In the above example, the "welcome" class
| would be loaded.
|
|	$route['404_override'] = 'errors/page_missing';
|
| This route will tell the Router which controller/method to use if those
| provided in the URL cannot be matched to a valid route.
|
|	$route['translate_uri_dashes'] = FALSE;
|
| This is not exactly a route, but allows you to automatically route
| controller and method names that contain dashes. '-' isn't a valid
| class or method name character, so it requires translation.
| When you set this option to TRUE, it will replace ALL dashes with
| underscores in the controller and method URI segments.
|
| Examples:	my-controller/index	-> my_controller/index
|		my-controller/my-method	-> my_controller/my_method
*/

require_once __DIR__ . '/../helpers/routes_helper.php';

// Root URL → marketing redirect (handled by Home controller).
$route['default_controller'] = 'home';

// Polaris public booking path: /book/{slug} → Booking::slug($slug).
// Pattern: lowercase letter + up to 98 alphanumeric/hyphen chars = valid slug.
// MUST appear before any catch-all routes.
$route['book/([a-z][a-z0-9-]{0,98})'] = 'booking/slug/$1';

// Polaris: stable /admin alias for the staff back-office.
// EA 1.6 has no `/admin` route — the old monolithic Backend controller was split
// into Calendar/Login/Customers/… so a bare /admin 404s ("Page Not Found").
// Map it to the Login controller: unauthenticated visitors see the staff login,
// and Login::index() bounces an already-authenticated session to /calendar.
// Keeps the historical https://calendar.getpolaris.my/admin link working.
$route['admin'] = 'login';

$route['404_override'] = '';

$route['translate_uri_dashes'] = false;

/*
| -------------------------------------------------------------------------
| FRAME OPTIONS HEADERS
| -------------------------------------------------------------------------
| Set the appropriate headers so that iframe control and permissions are 
| properly configured.
|
| This prevents clickjacking attacks by disabling embedding in iframes.
|
| Options:
|
|   - DENY 
|   - SAMEORIGIN 
|
*/

header('X-Frame-Options: SAMEORIGIN');

/*
| -------------------------------------------------------------------------
| SECURITY HEADERS
| -------------------------------------------------------------------------
| Additional security headers to protect against common web attacks.
|
*/

// Prevent MIME type sniffing
header('X-Content-Type-Options: nosniff');

// Enable XSS filtering in older browsers
header('X-XSS-Protection: 1; mode=block');

// Referrer Policy - only send referrer for same-origin requests
header('Referrer-Policy: strict-origin-when-cross-origin');

// Permissions Policy - restrict browser features
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');

/*
| -------------------------------------------------------------------------
| CORS HEADERS
| -------------------------------------------------------------------------
| Set the appropriate headers so that CORS requirements are met and any 
| incoming preflight options request succeeds. 
|
| IMPORTANT: For production, restrict this to your specific trusted domains.
|
*/

// Polaris: restrict CORS to the single trusted Polaris origin only.
// Do NOT reflect arbitrary origins — combined with Allow-Credentials that would
// let any site make credentialed cross-origin API calls. We populate the same
// $allowed_origins / $request_origin vars the upstream preflight block below
// reads, so the OPTIONS handler enforces this single-origin policy too.
$_polaris_allowed_origin = getenv('POLARIS_CORS_ORIGIN') ?: 'https://getpolaris.my';
$allowed_origins = [$_polaris_allowed_origin];
$request_origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if ($request_origin !== '' && in_array($request_origin, $allowed_origins, true)) {
    header('Access-Control-Allow-Origin: ' . $request_origin);
    header('Access-Control-Allow-Credentials: true');
}
unset($_polaris_allowed_origin);

if (
    isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD']) &&
    !empty($request_origin) &&
    (empty($allowed_origins) || in_array($request_origin, $allowed_origins, true))
) {
    // May also be using PUT, PATCH, HEAD etc
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS, HEAD');
}

if (
    isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']) &&
    !empty($request_origin) &&
    (empty($allowed_origins) || in_array($request_origin, $allowed_origins, true))
) {
    // Only allow safe headers
    $allowed_headers = ['Content-Type', 'Authorization', 'X-Requested-With', 'Accept', 'Origin', 'X-CSRF'];
    $requested_headers = array_map('trim', explode(',', $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']));
    $safe_headers = array_filter($requested_headers, function ($h) use ($allowed_headers) {
        return in_array(trim($h), $allowed_headers, true);
    });
    if (!empty($safe_headers)) {
        header('Access-Control-Allow-Headers: ' . implode(', ', $safe_headers));
    }
}

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

/*
| -------------------------------------------------------------------------
| REST API ROUTING
| -------------------------------------------------------------------------
| Define the API resource routes using the routing helper function. By 
| default, each resource will have by default the following actions: 
| 
|   - index [GET]
|
|   - show/:id [GET]
|
|   - store [POST]
|
|   - update [PUT]
|
|   - destroy [DELETE]
|
| Some resources like the availabilities and the settings do not follow this 
| pattern and are explicitly defined. 
|
*/

route_api_resource($route, 'appointments', 'api/v1/');

route_api_resource($route, 'admins', 'api/v1/');

route_api_resource($route, 'service_categories', 'api/v1/');

route_api_resource($route, 'customers', 'api/v1/');

route_api_resource($route, 'providers', 'api/v1/');

route_api_resource($route, 'secretaries', 'api/v1/');

route_api_resource($route, 'services', 'api/v1/');

route_api_resource($route, 'unavailabilities', 'api/v1/');

route_api_resource($route, 'webhooks', 'api/v1/');

route_api_resource($route, 'blocked_periods', 'api/v1/');

$route['api/v1/settings']['get'] = 'api/v1/settings_api_v1/index';

$route['api/v1/settings/(:any)']['get'] = 'api/v1/settings_api_v1/show/$1';

$route['api/v1/settings/(:any)']['put'] = 'api/v1/settings_api_v1/update/$1';

$route['api/v1/availabilities']['get'] = 'api/v1/availabilities_api_v1/get';

$route['api/v1/internal/tenants/sync']['post'] = 'api/v1/internal_api_v1/tenants_sync';

/*
| -------------------------------------------------------------------------
| CUSTOM ROUTING
| -------------------------------------------------------------------------
| You can add custom routes to the following section to define URL patterns
| that are later mapped to the available controllers in the filesystem. 
|
*/

/* End of file routes.php */
/* Location: ./application/config/routes.php */
