<?php

require dirname(__DIR__).'/bootstrap.php';
define('SALON_SYNTHETIC_DRAFT_HARNESS', Tests\Support\SyntheticPresentationHarness::CONTRACT);
Tests\Support\SyntheticPresentationHarness::preflight(Tests\Support\SyntheticPresentationHarness::connect(), 91);
register_shutdown_function(static function () { putenv('SALON_TEST_DB_RESET_ALLOWED=0'); });
