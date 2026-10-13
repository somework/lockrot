// Reads every union of types/lockrot-report-2.known.d.ts through the TypeScript compiler API and asserts that
//   1. each union's literal members equal its schema node's `enum` or `x-known-values`, and an open set (and only an open
//      set) also admits `string & {}` (`number & {}` for an integer set);
//   2. every `enum` and `x-known-values` node of report-2 (`$ref`s resolved, relation `allOf` and `not` excluded) is one
//      of those unions' pointers, so no closed or open set is left typed as a bare string.
// The probes (../ts/probes.generated.ts, compiled by dts_check.js) hold the shapes to the unions. This holds the unions to
// the schema.
'use strict';
const fs = require('fs');
const path = require('path');
const ts = require('typescript');
const { REPO } = require('./documents');

const schema = JSON.parse(fs.readFileSync(path.join(REPO, 'resources', 'lockrot-report-2.schema.json'), 'utf8'));
const map = JSON.parse(fs.readFileSync(path.join(REPO, 'tools', 'schema', 'ts', 'known-map.json'), 'utf8'));
const file = process.argv[2] || path.join(REPO, 'types', 'lockrot-report-2.known.d.ts');
const program = ts.createProgram([file], { strict: true, noEmit: true, target: ts.ScriptTarget.ES2020 });
const checker = program.getTypeChecker();
const source = program.getSourceFile(file);
const failures = [];

const aliases = {};
ts.forEachChild(source, (n) => { if (ts.isTypeAliasDeclaration(n)) aliases[n.name.text] = n; });

function members(alias) {
  const t = checker.getTypeAtLocation(alias.name);
  const parts = t.isUnion() ? t.types : [t];
  const lits = [];
  let openString = false, openNumber = false;
  for (const p of parts) {
    if (p.isStringLiteral() || p.isNumberLiteral()) lits.push(p.value);
    else if (p.flags & ts.TypeFlags.BooleanLiteral) lits.push(checker.typeToString(p) === 'true');
    else if (p.flags & ts.TypeFlags.Null) lits.push(null);
    else if (p.flags & ts.TypeFlags.Boolean) lits.push(true, false);
    else if (p.isIntersection() && p.types.some((x) => x.flags & ts.TypeFlags.String)) openString = true;
    else if (p.isIntersection() && p.types.some((x) => x.flags & ts.TypeFlags.Number)) openNumber = true;
    else failures.push(`${alias.name.text}: a member that is neither a literal nor an open marker (${checker.typeToString(p)})`);
  }
  return { lits, openString, openNumber };
}

const same = (a, b) => a.length === b.length && [...a].sort().join('\u0000') === [...b].sort().join('\u0000');
let unions = 0;
let closed = 0;
for (const k of map) {
  const alias = aliases[k.name];
  if (!alias) { failures.push(`${k.name}: no such union`); continue; }
  unions++;
  if (!k.open) closed++;
  const m = members(alias);
  if (!same(m.lits.map(String), k.values.map(String))) failures.push(`${k.name}: members ${JSON.stringify(m.lits)} != schema ${JSON.stringify(k.values)} at ${k.pointer}`);
  if (k.open !== (m.openString || m.openNumber)) failures.push(`${k.name}: open in the schema ${k.open}, in the d.ts ${m.openString || m.openNumber}`);
  if (k.open && k.integer !== m.openNumber) failures.push(`${k.name}: an integer set must be open as (number & {})`);
}

const byPointer = {};
for (const k of map) for (const p of k.pointers) byPointer[p] = k;
const seen = new Set();
let targets = 0;
function resolve(node, ptr) {
  let n = 0;
  while (node && node.$ref && n++ < 64) { const name = node.$ref.split('/').pop(); node = schema.definitions[name]; ptr = '#/definitions/' + name; }
  return [node, ptr];
}
function walk(node, ptr) {
  [node, ptr] = resolve(node, ptr);
  if (!node || typeof node !== 'object' || seen.has(ptr)) return;
  seen.add(ptr);
  const vals = Array.isArray(node.enum) ? node.enum : Array.isArray(node['x-known-values']) ? node['x-known-values'] : null;
  if (vals && vals.every((v) => typeof v === 'string' || typeof v === 'number')) {
    targets++;
    const k = byPointer[ptr];
    if (!k) failures.push(`${ptr}: a schema set no union types`);
    else if (!same(k.values.map(String), vals.map(String))) failures.push(`${ptr}: union ${k.name} != the schema node`);
  }
  for (const [k, sub] of Object.entries(node.properties || {})) walk(sub, `${ptr}/properties/${k}`);
  for (const [k, sub] of Object.entries(node.patternProperties || {})) walk(sub, `${ptr}/patternProperties/${k}`);
  if (node.additionalProperties && typeof node.additionalProperties === 'object') walk(node.additionalProperties, `${ptr}/additionalProperties`);
  if (node.items && typeof node.items === 'object' && !Array.isArray(node.items)) walk(node.items, `${ptr}/items`);
  for (const kw of ['oneOf', 'anyOf']) (node[kw] || []).forEach((b, i) => walk(b, `${ptr}/${kw}/${i}`));
}
walk(schema, '#');
console.log(JSON.stringify({ unions, closed, open: unions - closed, schema_sets: targets, failures: failures.slice(0, 20), ok: failures.length === 0 }));
process.exit(failures.length ? 1 : 0);
