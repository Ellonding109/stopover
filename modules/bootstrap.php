<?php
/**
 * Bootstrap for the Application Form module
 * 
 * Drop this at: /modules/bootstrap.php
 * 
 * Include it from web/index.php to intercept /actions/* requests.
 */
spl_autoload_register(function (string $class) {
    $prefix = 'modules\\';
    $baseDir = __DIR__ . '/';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) return;
    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
    if (file_exists($file)) require $file;
});

if (class_exists(\modules\applicationform\ApplicationFormModule::class)) {
    \modules\applicationform\ApplicationFormModule::getInstance()->init();
}
