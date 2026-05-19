<?php

/**
 * PHPUnit bootstrap for the EA fork test suite.
 *
 * Initialises CI3 core class definitions (CI_Model, CI_Controller, EA_Model)
 * WITHOUT running the request dispatcher. Tests that need a live DB connection
 * are integration tests and must bring their own setup; unit tests use
 * hand-rolled stubs (e.g. FakeDb in MyModelTest).
 */

// ---------------------------------------------------------------------------
// Composer (PHPUnit, firebase/php-jwt, etc.)
// ---------------------------------------------------------------------------
require_once dirname(__DIR__) . '/vendor/autoload.php';

// ---------------------------------------------------------------------------
// CI3 path constants — mirrors what index.php would set
// ---------------------------------------------------------------------------
define('ENVIRONMENT', 'testing');
define('SELF',        'phpunit');

$fcpath      = realpath(dirname(__DIR__));
$system_path = $fcpath . DIRECTORY_SEPARATOR . 'system'      . DIRECTORY_SEPARATOR;
$app_path    = $fcpath . DIRECTORY_SEPARATOR . 'application' . DIRECTORY_SEPARATOR;

define('FCPATH',   $fcpath . DIRECTORY_SEPARATOR);
define('BASEPATH', $system_path);
define('SYSDIR',   'system');
define('APPPATH',  $app_path);
define('VIEWPATH', $app_path . 'views' . DIRECTORY_SEPARATOR);

// ---------------------------------------------------------------------------
// CI3 global functions (load_class, is_loaded, log_message …)
// ---------------------------------------------------------------------------
require_once BASEPATH . 'core/Common.php';

// get_instance() is normally defined in system/core/CodeIgniter.php which
// runs the full request dispatch. We define a minimal version here so that
// Tests\TestCase::setUpBeforeClass() (and model __get magic) can resolve it
// without triggering a 404 response.
if (!function_exists('get_instance')) {
    function &get_instance(): mixed
    {
        return CI_Controller::get_instance();
    }
}

// ---------------------------------------------------------------------------
// CI3 base classes — definitions only, no instantiation
// ---------------------------------------------------------------------------
require_once BASEPATH . 'core/Model.php';       // CI_Model
require_once BASEPATH . 'core/Controller.php';  // CI_Controller

// ---------------------------------------------------------------------------
// EA core overrides
// ---------------------------------------------------------------------------
require_once APPPATH . 'core/EA_Model.php';

// ---------------------------------------------------------------------------
// Application helpers used by existing unit tests
// ---------------------------------------------------------------------------
$helpers = ['array', 'validation'];
foreach ($helpers as $h) {
    $path = APPPATH . 'helpers/' . $h . '_helper.php';
    if (file_exists($path)) {
        require_once $path;
    }
}
