<?php

namespace Tests;

use CI_Controller;
use EA_Controller;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

/**
 * Parent test case sharing common test functionality.
 */
class TestCase extends PHPUnitTestCase {
    /**
     * @var EA_Controller|CI_Controller|null
     */
    private static EA_Controller|CI_Controller|null $CI = null;

    /**
     * Load the framework instance (null in unit-test bootstrap; real instance
     * in integration tests that bootstrap a full CI3 request).
     *
     * @return void
     */
    public static function setUpBeforeClass(): void
    {
        $instance = get_instance();
        self::$CI = $instance;
    }
}
