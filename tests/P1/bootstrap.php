<?php

/**
 * P1 harness bootstrap.
 *
 * P1_DIAGNOSTICS=legacy (default) reports PHP diagnostics without failing tests:
 * the application code relies on dynamic properties, which PHP 8.2 raises as
 * E_DEPRECATED; PHPUnit converts that into a test error before any assertion runs.
 * P1_DIAGNOSTICS=strict restores the default error_reporting so every diagnostic
 * is converted into a test error again.
 */
if (getenv('P1_DIAGNOSTICS') === 'strict') {
    error_reporting(E_ALL);
} else {
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
}

$autoload = getenv('P1_VENDOR_AUTOLOAD') ?: dirname(__DIR__, 2) . '/vendor/autoload.php';
if (!is_file($autoload)) throw new RuntimeException('Set P1_VENDOR_AUTOLOAD to a Laravel 8 / PHPUnit 9 vendor/autoload.php.');
require $autoload;
$sourceRoot = getenv('P1_SOURCE_ROOT') ?: dirname(__DIR__, 2);
$overrideRoot = getenv('P1_OVERRIDE_ROOT');
spl_autoload_register(function ($class) use ($sourceRoot, $overrideRoot) {
    if (strpos($class, 'App\\') === 0) {
        foreach (array_filter([$overrideRoot, $sourceRoot]) as $root) {
            $file = $root . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
            if (is_file($file)) { require $file; return; }
        }
    }
}, true, true);
require __DIR__ . '/Environment.php';
require __DIR__ . '/PaymentHttpFixture.php';
