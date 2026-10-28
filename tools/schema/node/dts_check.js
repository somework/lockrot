// The TypeScript contract of report-2 under tsc --strict:
//   1. types/lockrot-report-2.d.ts with ../ts/probes.generated.ts compiles clean;
//   2. tests/fixtures/bundle.generated.ts and every report-2 fixture document compile, each written as an object
//      literal `satisfies Report2`. A literal keeps each string's literal type, so a value outside a closed set fails.
//      A JSON import widens every string and cannot catch it.
//   3. each shape of the hand-kept file equals the shape of the same name that `emit_dts.py --prototype` gives from the
//      schema, except the shapes of HAND_KEPT;
//   4. five planted regressions fail: a closed set typed `string` in the shapes, a union name that does not exist in
//      the shapes, a known value dropped from a union, a value outside a closed set in a document, and a property
//      dropped from a shape.
'use strict';
const fs = require('fs');
const os = require('os');
const path = require('path');
const { execFileSync } = require('child_process');
const { REPO, FIXTURES, DOCUMENTS, report2, read } = require('./documents');

const TYPES = path.join(REPO, 'types');
const PROBES = path.join(REPO, 'tools', 'schema', 'ts', 'probes.generated.ts');
const BUNDLE = path.join(FIXTURES, 'bundle.generated.ts');
const TSC = path.join(__dirname, 'node_modules', '.bin', 'tsc');
// No --skipLibCheck: it skips every .d.ts file, the shapes under test included.
const OPTS = ['--noEmit', '--strict', '--target', 'es2020'];
const SHARD = 12;

// The shapes that the prototype gives less exactly than the hand-kept file, each with its reason.
const HAND_KEPT = {
  TargetFix: 'the prototype writes one branch per `check` of the schema\'s oneOf and types `lock_platform` as unknown[]',
};

// The errors of one tsc run. A failed run without an `error TS` line is an error too: a crash never reads as clean.
function tsc(list) {
  try { execFileSync(TSC, [...OPTS, ...list], { encoding: 'utf8', maxBuffer: 64 << 20 }); return []; }
  catch (e) {
    const lines = ((e.stdout || '') + (e.stderr || '')).split('\n');
    const errors = lines.filter((l) => /error TS/.test(l));
    return errors.length > 0 ? errors : [`tsc failed with status ${e.status}: ${lines.filter(Boolean).slice(0, 3).join(' | ')}`];
  }
}

// The names of the shapes in $shapes (a copy of the hand-kept file beside the known unions) that differ from the
// prototype's shape of the same name.
function drift(prototype, shapes) {
  const dir = path.dirname(shapes);
  const hand = JSON.stringify('./' + path.basename(shapes, '.d.ts'));
  const lines = fs.readFileSync(prototype, 'utf8').split('\n').map((line) => {
    const name = (line.match(/^export type ([A-Za-z0-9]+) = /) || [])[1];
    return name in HAND_KEPT ? `export type ${name} = import(${hand}).${name};` : line;
  });
  fs.writeFileSync(path.join(dir, 'prototype.d.ts'), lines.join('\n'));
  const names = (file) => fs.readFileSync(file, 'utf8').split('\n').map((l) => (l.match(/^export type ([A-Za-z0-9]+) = /) || [])[1]).filter(Boolean);
  const own = new Set(names(shapes));
  const shared = names(prototype).filter((n) => own.has(n) && !(n in HAND_KEPT));
  const probe = path.join(dir, 'drift.ts');
  fs.writeFileSync(probe, [
    "import type * as P from './prototype';",
    `import type * as R from ${hand};`,
    'type Equal<X, Y> = (<T>() => T extends X ? 1 : 2) extends (<T>() => T extends Y ? 1 : 2) ? true : false;',
    ...shared.map((n) => `export const ${n}: Equal<P.${n}, R.${n}> = true;`),
  ].join('\n'));
  const errors = tsc([probe]);
  const at = errors.map((e) => Number((e.match(/drift\.ts\((\d+),/) || [])[1]) - 4);
  return at.every((i) => i >= 0 && i < shared.length) ? at.map((i) => shared[i]) : errors;
}

const work = fs.mkdtempSync(path.join(os.tmpdir(), 'lockrot-dts-'));
const out = {};
try {
  out.shapes = tsc([path.join(TYPES, 'lockrot-report-2.d.ts'), PROBES]);
  const prototype = path.join(work, 'prototype.generated.d.ts');
  execFileSync('python3', [path.join(REPO, 'tools', 'schema', 'ts', 'emit_dts.py'), '--prototype', prototype], { encoding: 'utf8' });
  const shapesDir = path.join(work, 'shapes');
  fs.mkdirSync(shapesDir);
  fs.copyFileSync(path.join(TYPES, 'lockrot-report-2.known.d.ts'), path.join(shapesDir, 'lockrot-report-2.known.d.ts'));
  fs.copyFileSync(path.join(TYPES, 'lockrot-report-2.d.ts'), path.join(shapesDir, 'lockrot-report-2.d.ts'));
  out.drift = drift(prototype, path.join(shapesDir, 'lockrot-report-2.d.ts'));
  out.bundle = fs.existsSync(BUNDLE) ? tsc([BUNDLE]) : ['tests/fixtures/bundle.generated.ts is missing'];

  const shapes = JSON.stringify(path.join(TYPES, 'lockrot-report-2'));
  const write = (name, json) => {
    const f = path.join(work, `${name}.ts`);
    fs.writeFileSync(f, `import type { Report2 } from ${shapes};\nexport const d = ${json.trim()} satisfies Report2;\n`);
    return f;
  };
  const docs = report2();
  const errors = [];
  for (let i = 0; i < docs.length; i += SHARD) {
    const batch = docs.slice(i, i + SHARD).map((f, j) => write(`d${i + j}`, read(f)));
    errors.push(...tsc(batch).map((e) => e.slice(0, 300)));
  }
  out.documents = { files: docs.length, errors: errors.length, first: errors.slice(0, 5) };

  const planted = {};
  {
    const dts = fs.readFileSync(path.join(TYPES, 'lockrot-report-2.d.ts'), 'utf8');
    const edited = dts.replace(/(\breach: )K\.[A-Za-z0-9]+/, '$1string');
    fs.writeFileSync(path.join(work, 'lockrot-report-2.d.ts'), edited);
    fs.copyFileSync(path.join(TYPES, 'lockrot-report-2.known.d.ts'), path.join(work, 'lockrot-report-2.known.d.ts'));
    const probes = fs.readFileSync(PROBES, 'utf8').replace(/'\.\.\/\.\.\/\.\.\/types\//g, "'./");
    fs.writeFileSync(path.join(work, 'probes.ts'), probes);
    planted.closed_set_typed_string = edited !== dts && tsc([path.join(work, 'lockrot-report-2.d.ts'), path.join(work, 'probes.ts')]).length > 0;
    const missing = dts.replace(/(\breach: )K\.[A-Za-z0-9]+/, '$1K.NoSuchUnion');
    fs.writeFileSync(path.join(work, 'lockrot-report-2.d.ts'), missing);
    planted.union_that_does_not_exist = missing !== dts && tsc([path.join(work, 'lockrot-report-2.d.ts')]).some((e) => /TS2694/.test(e));
  }
  {
    const doc = JSON.parse(read(path.join(DOCUMENTS, 'cases', 'A.json')));
    doc.findings[0].reach = 'indirect';
    planted.document_value_outside_a_closed_set = tsc([write('planted', JSON.stringify(doc))]).length > 0;
  }
  {
    const known = fs.readFileSync(path.join(TYPES, 'lockrot-report-2.known.d.ts'), 'utf8');
    const map = JSON.parse(fs.readFileSync(path.join(REPO, 'tools', 'schema', 'ts', 'known-map.json'), 'utf8'));
    const victim = map.find((k) => k.open && k.values.length > 2 && typeof k.values[0] === 'string');
    if (!victim) throw new Error('no open string union with three or more values to plant a dropped value in');
    const edited = known.replace(`export type ${victim.name} = ${JSON.stringify(victim.values[0])} | `, `export type ${victim.name} = `);
    const copy = path.join(work, 'known.d.ts');
    fs.writeFileSync(copy, edited);
    let failed = false;
    try { execFileSync('node', [path.join(__dirname, 'union_parity.js'), copy], { encoding: 'utf8' }); } catch (e) { failed = true; }
    planted.known_value_dropped = edited !== known && failed;
  }
  {
    const file = path.join(shapesDir, 'lockrot-report-2.d.ts');
    const dts = fs.readFileSync(file, 'utf8');
    const edited = dts.replace(/(export type Envelope = \{ )version: string; /, '$1');
    fs.writeFileSync(file, edited);
    planted.shape_property_dropped = edited !== dts && drift(prototype, file).includes('Envelope');
  }
  out.planted = planted;
} finally {
  fs.rmSync(work, { recursive: true, force: true });
}
out.ok = out.shapes.length === 0 && out.drift.length === 0 && out.bundle.length === 0 && out.documents.errors === 0 && Object.values(out.planted).every(Boolean);
console.log(JSON.stringify(out));
process.exit(out.ok ? 0 : 1);
