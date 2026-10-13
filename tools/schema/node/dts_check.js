// The TypeScript contract of report-2 under tsc --strict:
//   1. types/lockrot-report-2.d.ts with ../ts/probes.generated.ts compiles clean;
//   2. tests/fixtures/bundle.generated.ts and every report-2 fixture document, written as an object literal
//      `satisfies Report2`, compile (a literal keeps each string's literal type, so a value outside a closed set fails;
//      a JSON import widens every string and would not catch it);
//   3. three planted regressions fail: a closed set typed `string` in the shapes (the probes catch it), a known value
//      dropped from a union (union_parity.js catches it), a value outside a closed set in a document.
'use strict';
const fs = require('fs');
const os = require('os');
const path = require('path');
const { execFileSync } = require('child_process');
const { REPO, FIXTURES, DOCUMENTS, REPORT2, files, read } = require('./documents');

const TYPES = path.join(REPO, 'types');
const PROBES = path.join(REPO, 'tools', 'schema', 'ts', 'probes.generated.ts');
const BUNDLE = path.join(FIXTURES, 'bundle.generated.ts');
const TSC = path.join(__dirname, 'node_modules', '.bin', 'tsc');
const OPTS = ['--noEmit', '--strict', '--target', 'es2020', '--skipLibCheck'];
const SHARD = 12;

function tsc(list) {
  try { execFileSync(TSC, [...OPTS, ...list], { encoding: 'utf8', maxBuffer: 64 << 20 }); return []; }
  catch (e) { return ((e.stdout || '') + (e.stderr || '')).split('\n').filter((l) => /error TS/.test(l)); }
}

const work = fs.mkdtempSync(path.join(os.tmpdir(), 'lockrot-dts-'));
const out = {};
try {
  out.shapes = tsc([path.join(TYPES, 'lockrot-report-2.d.ts'), PROBES]);
  out.bundle = fs.existsSync(BUNDLE) ? tsc([BUNDLE]) : ['tests/fixtures/bundle.generated.ts is missing'];

  const shapes = JSON.stringify(path.join(TYPES, 'lockrot-report-2'));
  const write = (name, json) => {
    const f = path.join(work, `${name}.ts`);
    fs.writeFileSync(f, `import type { Report2 } from ${shapes};\nexport const d = ${json.trim()} satisfies Report2;\n`);
    return f;
  };
  const docs = REPORT2.flatMap(files);
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
    const edited = known.replace(`export type ${victim.name} = ${JSON.stringify(victim.values[0])} | `, `export type ${victim.name} = `);
    const copy = path.join(work, 'known.d.ts');
    fs.writeFileSync(copy, edited);
    let failed = false;
    try { execFileSync('node', [path.join(__dirname, 'union_parity.js'), copy], { encoding: 'utf8' }); } catch (e) { failed = true; }
    planted.known_value_dropped = edited !== known && failed;
  }
  out.planted = planted;
} finally {
  fs.rmSync(work, { recursive: true, force: true });
}
out.ok = out.shapes.length === 0 && out.bundle.length === 0 && out.documents.errors === 0 && Object.values(out.planted).every(Boolean);
console.log(JSON.stringify(out));
process.exit(out.ok ? 0 : 1);
