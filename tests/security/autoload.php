<?php
/** Verify the shipped loaders without a test PSR-4 loader masking stale maps. */
declare(strict_types=1);
$root = dirname(__DIR__, 2);
function add_action(...$args) {}
if (($argv[1] ?? 'jetpack') === 'composer') {
    require $root . '/vendor/autoload.php';
} else {
    foreach (['version-selector', 'version-loader', 'php-autoloader'] as $file) {
        require $root . '/vendor/jetpack-autoloader/class-' . $file . '.php';
    }
    $namespace = 'Automattic\\Jetpack\\Autoloader\\jpe1d0ab5189c3bd390fd15808cbbb2adc\\al5_0_20\\';
    $selector = $namespace . 'Version_Selector';
    $loader = $namespace . 'Version_Loader';
    $autoloader = $namespace . 'PHP_Autoloader';
    $map = require $root . '/vendor/composer/jetpack_autoload_classmap.php';
    (new $autoloader())->register_autoloader(new $loader(new $selector(), $map, [], []));
}
if (!class_exists(Tropk\Mcp\OAuth\ScopeGuard::class)) {
    throw new RuntimeException('Shipped autoloader cannot resolve OAuth ScopeGuard');
}
(new Tropk\Mcp\OAuth\ScopeGuard())->register();
echo 'PASS: shipped ' . ($argv[1] ?? 'jetpack') . " autoloader resolves and registers ScopeGuard\n";
