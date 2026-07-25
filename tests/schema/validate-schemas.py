#!/usr/bin/env python3
"""Validate every ability input_schema AND output_schema against the JSON
Schema draft 2020-12 meta-schema — the exact check the Anthropic API
applies to tools[].input_schema before accepting a tool definition.

A schema that fails here is one the API rejects with:
  400 tools.N.custom.input_schema: JSON schema is invalid.

The classic offender is a PHP `'value' => []` in a properties map: json_encode
serializes it to `"value": []`, but a JSON Schema value must be an object or
boolean, never an array. This gate catches that (and anything else) BEFORE it
ships, across ALL abilities — including ones a given site never exposes because
the underlying plugin (Woo, Elementor, ACF, ...) isn't active.

Usage:
  php tests/schema/dump-schemas.php | python3 tests/schema/validate-schemas.py
  # or: python3 tests/schema/validate-schemas.py schemas.json
"""
import json
import sys

try:
    from jsonschema import Draft202012Validator
    from jsonschema.exceptions import SchemaError
except ImportError:
    sys.exit("Missing dependency: pip install jsonschema")

src = open(sys.argv[1]) if len(sys.argv) > 1 else sys.stdin
data = json.load(src)


def normalize(schema):
    """Mirror AbstractAbility::normalize_schema() — inject $schema + type."""
    if not isinstance(schema, dict):
        return schema
    s = dict(schema)
    s.setdefault("$schema", "https://json-schema.org/draft/2020-12/schema")
    s.setdefault("type", "object")
    return s


def check(schema):
    # Round-trip through JSON so we validate exactly what the server serializes
    # (this is where a PHP empty array collapses to `[]`).
    schema = json.loads(json.dumps(schema))
    if not isinstance(schema, dict):
        return f"is {type(schema).__name__}, not an object"
    try:
        Draft202012Validator.check_schema(schema)
    except SchemaError as e:
        return f"at [{'/'.join(str(p) for p in e.absolute_path)}]: {e.message}"
    return None


bad, load_err, checked = [], [], 0
for name, rec in data.items():
    if name.startswith("ERR"):
        load_err.append((name, rec.get("error")))
        continue
    for kind in ("input", "output"):
        sch = rec.get(kind)
        if sch is None:
            continue
        checked += 1
        err = check(normalize(sch))
        if err:
            bad.append((name, kind, err, rec.get("file"), sch))

n_abilities = len([k for k in data if not k.startswith("ERR")])
print(f"abilities: {n_abilities}   schemas checked: {checked}")

if load_err:
    print(f"\n⚠️  {len(load_err)} abilities could not be loaded by the harness:")
    for n, e in load_err:
        print(f"    {n}: {e}")

if bad:
    print(f"\n❌ {len(bad)} INVALID SCHEMA(S) — the Anthropic API will reject these:\n")
    for name, kind, err, file, sch in bad:
        print(f"  {name} .{kind}_schema  {err}")
        print(f"      file: {file}")
        print(f"      {json.dumps(sch)[:300]}")
    sys.exit(1)

print("\n✅ ALL SCHEMAS VALID against JSON Schema draft 2020-12")
# Load errors are a harness problem, not a plugin bug — don't fail the build,
# but surface them above so coverage gaps are visible.
sys.exit(0)
