<?php
/**
 * Dump every ability's input_schema and output_schema as JSON, WITHOUT a
 * running WordPress and WITHOUT Composer's autoload — so this script's
 * capturing wp_register_ability() wins over vendor/wordpress/abilities-api.
 *
 * Covers BOTH registration paths:
 *   1. AbstractAbility subclasses under src/Abilities.
 *   2. Procedural wp_register_ability() calls in src/Extras.
 *
 * Crucially it also covers abilities that any single live site never
 * exposes because the underlying plugin (WooCommerce, Elementor, ACF,
 * Rank Math, ...) isn't active there. Output feeds validate-schemas.py.
 *
 * Usage:  php tests/schema/dump-schemas.php > /tmp/schemas.json
 */
declare(strict_types=1);
error_reporting(E_ERROR | E_PARSE);

$ROOT = dirname(__DIR__, 2);
if (!defined('ABSPATH'))            define('ABSPATH', $ROOT . '/');
if (!defined('TROPK_MCP_DIR'))      define('TROPK_MCP_DIR', $ROOT . '/');
if (!defined('TROPK_MCP_VERSION'))  define('TROPK_MCP_VERSION', '0.0.0-test');
if (!defined('TROPK_MCP_FILE'))     define('TROPK_MCP_FILE', $ROOT . '/mcp-for-wordpress.php');

// Plugin-only PSR-4 autoloader (no Composer -> our WP stubs are authoritative).
spl_autoload_register(function ($class) use ($ROOT) {
    $prefix = 'Tropk\\Mcp\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) return;
    $file = $ROOT . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) require $file;
});

// ---- Minimal WordPress stubs used at schema-definition time ----------
$GLOBALS['__captured'] = [];
$GLOBALS['__actions']  = [];
function __($t, $d = null) { return $t; }
function esc_html__($t, $d = null) { return $t; }
function esc_attr__($t, $d = null) { return $t; }
function _x($t, $c, $d = null) { return $t; }
function _doing_it_wrong($f, $m, $v) {}
function apply_filters($h, $v, ...$a) { return $v; }
function add_action($h, $cb, $p = 10, $n = 1) { $GLOBALS['__actions'][$h][] = $cb; return true; }
function add_filter(...$a) { return true; }
function did_action($h) { return 0; }
function wp_json_encode($d, $o = 0, $depth = 512) { return json_encode($d, $o, $depth); }
function sanitize_key($k) { return strtolower((string) $k); }
function wp_parse_args($a, $d = []) { return array_merge($d, (array) $a); }
function trailingslashit($s) { return rtrim((string) $s, '/\\') . '/'; }
function untrailingslashit($s) { return rtrim((string) $s, '/\\'); }
function wp_normalize_path($p) { return str_replace('\\', '/', (string) $p); }
function esc_html($s) { return $s; }
function esc_attr($s) { return $s; }
function absint($n) { return abs((int) $n); }
function register_rest_route(...$a) { return true; }
function current_user_can(...$a) { return true; }
function get_option($k, $d = false) { return $d; }
function __return_true() { return true; }
function __return_false() { return false; }
function wp_register_ability($name, $args = []) {
    $GLOBALS['__captured'][(string) $name] = [
        'input'  => $args['input_schema']  ?? null,
        'output' => $args['output_schema'] ?? null,
    ];
    return null;
}

$out = [];

// ---- 1) AbstractAbility subclasses -----------------------------------
require $ROOT . '/src/Abilities/AbstractAbility.php';
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($ROOT . '/src/Abilities', FilesystemIterator::SKIP_DOTS));
foreach ($rii as $file) {
    if ($file->getExtension() === 'php') require_once $file->getPathname();
}
foreach (get_declared_classes() as $cls) {
    if (!is_subclass_of($cls, 'Tropk\\Mcp\\Abilities\\AbstractAbility')) continue;
    $rc = new ReflectionClass($cls);
    if ($rc->isAbstract()) continue;
    try {
        $obj = $rc->newInstanceWithoutConstructor();
        $sm = $rc->getMethod('slug');          $sm->setAccessible(true);
        $im = $rc->getMethod('input_schema');  $im->setAccessible(true);
        $om = $rc->getMethod('output_schema'); $om->setAccessible(true);
        $out['tropk-core-' . $sm->invoke($obj)] = [
            'input'  => $im->invoke($obj),
            'output' => $om->invoke($obj),
            'src'    => 'class',
            'file'   => str_replace($ROOT . '/', '', $rc->getFileName()),
        ];
    } catch (\Throwable $e) {
        $out['ERR:' . $cls] = ['error' => $e->getMessage()];
    }
}

// ---- 2) Procedural Extras registrations ------------------------------
foreach (glob($ROOT . '/src/Extras/*.php') as $file) {
    try { require_once $file; } catch (\Throwable $e) { $out['ERR:extras:' . basename($file)] = ['error' => $e->getMessage()]; }
}
foreach (($GLOBALS['__actions']['wp_abilities_api_init'] ?? []) as $i => $cb) {
    try { $cb(); } catch (\Throwable $e) { $out['ERR:extras_cb_' . $i] = ['error' => $e->getMessage()]; }
}
foreach ($GLOBALS['__captured'] as $name => $sch) {
    $out[$name] = ['input' => $sch['input'], 'output' => $sch['output'], 'src' => 'extras', 'file' => 'src/Extras/*'];
}

$classes = count(array_filter($out, fn($v) => ($v['src'] ?? '') === 'class'));
$extras  = count(array_filter($out, fn($v) => ($v['src'] ?? '') === 'extras'));
$errs    = count(array_filter(array_keys($out), fn($k) => str_starts_with($k, 'ERR')));
fwrite(STDERR, "dumped: classes=$classes extras=$extras errors=$errs total=" . count($out) . "\n");
echo json_encode($out);
