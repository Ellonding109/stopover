<?php
// ─── Application Form Module ───
if (strpos($_SERVER['REQUEST_URI'], '/actions/') === 0) {
    require dirname(__DIR__) . '/modules/bootstrap.php';
    exit;
}
// ─── Payment Routes ───
if (strpos($_SERVER['REQUEST_URI'], '/payment/') === 0) {
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $file = dirname(__DIR__) . $path . '.php';
    if (file_exists($file)) {
        require $file;
        exit;
    }
}
// ─── API Routes ───
if (strpos($_SERVER['REQUEST_URI'], '/api/') === 0) {
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $file = dirname(__DIR__) . $path . '.php';
    if (file_exists($file)) {
        require $file;
        exit;
    }
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