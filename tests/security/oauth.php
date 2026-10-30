<?php
/** Regression harness: real WP_Ability execution with minimal WordPress shims. */
declare(strict_types=1);
$root = dirname(__DIR__, 2);
spl_autoload_register(static function ($name) use ($root) {
    $prefix = 'Tropk\\Mcp\\';
    if (str_starts_with($name, $prefix)) require $root . '/src/' . str_replace('\\', '/', substr($name, strlen($prefix))) . '.php';
});
function wp_parse_args($a, $b = []) { return array_merge($b, $a); }
function __($text, $domain = '') { return $text; }
function esc_html__($text) { return $text; }
function esc_html($text) { return $text; }
function is_wp_error($v) { return $v instanceof WP_Error; }
function add_action($hook, $callback, $priority = 10, $args = 1) { $GLOBALS['hooks'][$hook][] = $callback; }
function do_action($hook, ...$args) { foreach ($GLOBALS['hooks'][$hook] ?? [] as $cb) $cb(...$args); }
function wp_get_ability($name) { return $GLOBALS['abilities'][$name] ?? null; }
function rest_url($path) { return 'https://example.test/wp-json/' . $path; }
function _doing_it_wrong(...$args) { throw new RuntimeException('Unexpected WordPress warning'); }
class WP_Error {
    public function __construct(public $code, public $message = '', public $data = []) {}
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
}
require getenv('WP_ABILITY_FILE') ?: $root . '/vendor/wordpress/abilities-api/includes/abilities-api/class-wp-ability.php';
use Tropk\Mcp\OAuth\{Scopes, ScopeGuard, BearerAuthenticator};
(new ScopeGuard())->register();
$count = 0;
function check($condition, $label) {
    global $count;
    if (!$condition) throw new RuntimeException('FAIL: ' . $label);
    ++$count;
}
function ability($name, $annotations, $callback = null, $permission = true) {
    $a = new WP_Ability($name, [
        'label' => 'Test', 'description' => 'Test ability', 'category' => 'test',
        'permission_callback' => static fn() => $permission,
        'execute_callback' => $callback ?? static function() { ++$GLOBALS['writes']; return 'ran'; },
        'meta' => ['annotations' => $annotations],
    ]);
    return $GLOBALS['abilities'][$name] = $a;
}
function token($scope) { $GLOBALS[BearerAuthenticator::ATTR_TOKEN] = ['scope' => $scope]; }
function denied($callback, $label) {
    $before = $GLOBALS['writes'];
    try { $callback(); throw new LogicException('Execution unexpectedly allowed'); }
    catch (RuntimeException $e) { check($e instanceof LogicException === false, $label); }
    check($before === $GLOBALS['writes'], $label . ': no side effects');
}
$GLOBALS['writes'] = 0;
$read = ability('test/read', ['readonly' => true]);
$write = ability('test/write', ['readonly' => false, 'destructive' => false]);
$delete = ability('test/delete', ['destructive' => true]);
$unknown = ability('test/unknown', []);
$dispatcher = ability('test/dispatcher', ['readonly' => true], static fn() => wp_get_ability('test/delete')->execute());
token('mcp:read');
check($read->execute() === 'ran', 'read allowed even with WP default destructive hint');
denied(static fn() => $write->execute(), 'read token cannot write');
denied(static fn() => $delete->execute(), 'read token cannot delete');
denied(static fn() => $dispatcher->execute(), 'nested dispatch cannot bypass scope');
denied(static fn() => $unknown->execute(), 'missing annotations fail closed');
foreach (['tropk-core/db-execute-select', 'tropk-db/execute-select'] as $name) {
    $sql = ability($name, ['readonly' => true]);
    token('mcp:read mcp:write mcp:destructive mcp:admin');
    denied(static fn() => $sql->execute(), 'raw SQL blocked with all scopes');
}
token('mcp:write');
check($write->execute() === 'ran', 'write scope allows additive write');
denied(static fn() => $delete->execute(), 'write scope cannot delete');
token('mcp:destructive');
check($delete->execute() === 'ran', 'destructive scope works without unrelated read scope');
$no_permission = ability('test/forbidden', ['readonly' => true], null, false);
check(is_wp_error($no_permission->execute()), 'WordPress permissions still enforced');
unset($GLOBALS[BearerAuthenticator::ATTR_TOKEN]);
check($write->execute() === 'ran', 'non-OAuth execution preserved');
foreach (['mcp:read mcp:write', 'mcp:read unknown', 'mcp:admin', '', '  '] as $scope) {
    check(Scopes::narrow($scope, 'mcp:read') === null, 'reject invalid refresh: ' . $scope);
}
check(Scopes::narrow(' mcp:read mcp:read ', 'mcp:read mcp:write') === 'mcp:read', 'normalize narrower grant');
check(Scopes::narrow('mcp:read mcp:write', 'mcp:read mcp:write') === 'mcp:read mcp:write', 'same grant accepted');
$auth = new BearerAuthenticator();
token('mcp:read');
foreach (['/wp/v2/posts/1', '/wp/v2/abilities/test/delete/run', '/tropk-mcp-evil/v1/mcp'] as $route) {
    $request = new class($route) { public function __construct(private $route) {} public function get_route() { return $this->route; } };
    check(is_wp_error($auth->restrict_token_routes(null, null, $request)), 'block token outside MCP: ' . $route);
}
$request = new class { public function get_route() { return '/tropk-mcp/v1/mcp'; } };
check($auth->restrict_token_routes(null, null, $request) === null, 'allow canonical MCP route');
check($auth->enforce_scope_and_audience(null) === null, 'write-only scope not rejected at authentication');
$GLOBALS[BearerAuthenticator::ATTR_TOKEN]['resource'] = 'https://other.test/mcp';
check(is_wp_error($auth->enforce_scope_and_audience(null)), 'reject wrong audience');

// Exercise the actual refresh endpoint and token issuer against a recording DB.
define('ARRAY_A', 'ARRAY_A');
function current_time($type, $gmt = false) { return gmdate('Y-m-d H:i:s'); }
class WP_REST_Request {
    public function __construct(private $params) {}
    public function get_body_params() { return $this->params; }
    public function get_params() { return $this->params; }
    public function get_header($name) { return ''; }
}
class WP_REST_Response {
    public function __construct(public $data, public $status) {}
    public function header(...$args) {}
}
$wpdb = new class {
    public $prefix = 'wp_';
    public $updates = 0;
    public $inserts = [];
    public $original = 'mcp:read';
    public function prepare($query, ...$args) { return $query; }
    public function get_row($query, $mode) {
        if (str_contains($query, 'mcp_oauth_clients')) return [
            'client_id' => 'client', 'client_type' => 'public', 'redirect_uris' => '[]',
            'grant_types' => 'refresh_token', 'metadata' => '{}',
        ];
        return ['client_id' => 'client', 'user_id' => 1, 'scope' => $this->original,
            'revoked' => 0, 'refresh_token_hash' => 'hash',
            'refresh_expires_at' => gmdate('Y-m-d H:i:s', time() + 3600)];
    }
    public function update(...$args) { ++$this->updates; return 1; }
    public function insert($table, $data, $formats) { $this->inserts[] = $data; return 1; }
};
$endpoint = new Tropk\Mcp\OAuth\Endpoints\TokenEndpoint();
foreach (['mcp:read mcp:write', 'mcp:read unknown', ''] as $scope) {
    $result = $endpoint->handle(new WP_REST_Request([
        'grant_type' => 'refresh_token', 'client_id' => 'client', 'refresh_token' => 'refresh', 'scope' => $scope,
    ]));
    check(is_wp_error($result) && $result->code === 'invalid_scope', 'endpoint rejects refresh escalation');
    check($wpdb->updates === 0 && $wpdb->inserts === [], 'rejection does not revoke or mint tokens');
}
foreach ([null, 'mcp:read'] as $scope) {
    $params = ['grant_type' => 'refresh_token', 'client_id' => 'client', 'refresh_token' => 'refresh'];
    if ($scope !== null) $params['scope'] = $scope;
    $result = $endpoint->handle(new WP_REST_Request($params));
    check($result instanceof WP_REST_Response && $result->data['scope'] === 'mcp:read', 'refresh keeps approved scope');
}
$wpdb->original = 'mcp:read mcp:write';
$result = $endpoint->handle(new WP_REST_Request([
    'grant_type' => 'refresh_token', 'client_id' => 'client', 'refresh_token' => 'refresh', 'scope' => 'mcp:read',
]));
check($result instanceof WP_REST_Response && $result->data['scope'] === 'mcp:read', 'endpoint permits narrowing');
check(end($wpdb->inserts)['scope'] === 'mcp:read', 'issuer persists narrowed scope');
echo "PASS: $count OAuth security assertions\n";

