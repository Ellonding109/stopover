<?php
// ─── Application Form Module ───
if (strpos($_SERVER['REQUEST_URI'], '/actions/') === 0) {
    require dirname(__DIR__) . '/modules/bootstrap.php';
    exit;
}
// ─── End ───

/**
 * Craft web bootstrap file
 */

// Load shared bootstrap
require dirname(__DIR__) . '/bootstrap.php';

// Load and run Craft
/** @var craft\web\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/web.php';
$app->run();
?>