// The repository paths the checks read, and the documents under them (`.json` and `.json.gz`).
'use strict';
const fs = require('fs');
const path = require('path');
const zlib = require('zlib');

const REPO = path.resolve(__dirname, '..', '..', '..');
const FIXTURES = path.join(REPO, 'tests', 'fixtures');
const DOCUMENTS = path.join(FIXTURES, 'schema', 'documents');
const SKIPPED = ['EXPECT.json', 'provenance.json'];

// Every document below dir. A missing or empty directory throws, so a renamed directory cannot pass with 0 files.
function files(dir) {
  const out = walk(dir);
  if (out.length === 0) throw new Error(`no document in ${path.relative(REPO, dir)}`);
  return out;
}

function walk(dir) {
  if (!fs.existsSync(dir)) return [];
  const out = [];
  for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
    const p = path.join(dir, e.name);
    if (e.isDirectory()) out.push(...walk(p));
    else if (/\.json(\.gz)?$/.test(e.name) && !SKIPPED.includes(e.name)) out.push(p);
  }
  return out.sort();
}

function read(file) {
  const bytes = fs.readFileSync(file);
  return (file.endsWith('.gz') ? zlib.gunzipSync(bytes) : bytes).toString('utf8');
}

// The documents of the baselined scenarios whose path matches pattern, below tests/fixtures/baselined/.
function baselined(pattern) {
  const out = files(path.join(FIXTURES, 'baselined')).filter((f) => pattern.test(path.relative(path.join(FIXTURES, 'baselined'), f)));
  if (out.length === 0) throw new Error(`no baselined document matches ${pattern}`);
  return out;
}

// The report-2 documents that the d.ts and the second validator hold: every fixture document, the contract bundle and
// the baselined reports.
function report2() {
  return [
    ...files(path.join(DOCUMENTS, 'cases')),
    ...files(path.join(DOCUMENTS, 'variants')),
    ...files(path.join(DOCUMENTS, 'corpus')),
    ...files(path.join(DOCUMENTS, 'coverage')),
    ...files(path.join(FIXTURES, 'contract', 'report-2')),
    ...files(path.join(FIXTURES, 'contract', 'model-only')),
    ...baselined(/^[^/]+\/(t0\/report|check\/report|check\/gates-[^/.]+|check\/target-[^/.]+|regenerate\/report)\.json$/),
  ];
}

module.exports = { REPO, FIXTURES, DOCUMENTS, report2, baselined, files, read };
