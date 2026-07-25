# Schema validation gate

Every ability exposes an `input_schema` and `output_schema`. When the plugin
answers `tools/list`, those become each MCP tool's `inputSchema` / `outputSchema`.
The Anthropic API validates each one against **JSON Schema draft 2020-12** before
accepting the tool. If any is invalid, the API rejects the **entire** tools array:

```
400 tools.N.custom.input_schema: JSON schema is invalid. It must match JSON Schema draft 2020-12
```

`N` is the tool's index in the client's *merged* array (all connected MCP servers
plus native tools), so it does **not** identify the offending tool — and because a
single bad schema poisons the whole array, one broken tool takes down every
conversation in that client session, even ones using a different, healthy server.

## The classic bug

A PHP "any type" property written as an empty array:

```php
'properties' => [ 'value' => [] ],   // ❌ json_encode -> "value": []
```

`json_encode([])` emits `[]`, but a JSON Schema value must be an **object or
boolean**, never an array. The fix is to give it a body:

```php
'properties' => [ 'value' => [ 'description' => '…' ] ],   // ✅ "value": {"description": "…"}
```

## Why validate the source, not a live site

Each site only exposes tools for the plugins it has active (no WooCommerce → no
`tropk-wc-*` tools). Validating one live server therefore can't cover everything.
`dump-schemas.php` loads **every** ability from source — both the
`AbstractAbility` subclasses and the procedural `src/Extras` registrations —
without a running WordPress, so all abilities are checked regardless of which
plugins a site happens to run.

## Run it

```bash
php tests/schema/dump-schemas.php | python3 tests/schema/validate-schemas.py
```

Exit code is non-zero if any schema is invalid. Runs in CI on every push/PR that
touches `src/**` (see `.github/workflows/validate-schemas.yml`).

## Debugging a live server out-of-band

To check what a *running* site actually serves without loading its tools into an
MCP client (which would crash on the bad schema), call the endpoint directly and
validate the bytes:

```bash
# initialize -> capture Mcp-Session-Id -> tools/list, then meta-schema check each inputSchema.
# Use a browser User-Agent; some hosts (Cloudflare) block default curl/python UAs.
```
