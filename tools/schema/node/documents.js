// The repository paths the checks read, and the documents under them (`.json` and `.json.gz`).
'use strict';
const fs = require('fs');
const path = require('path');
const zlib = require('zlib');

const REPO = path.resolve(__dirname, '..', '..', '..');
const FIXTURES = path.join(REPO, 'tests', 'fixtures');
const DOCUMENTS = path.join(FIXTURES, 'schema', 'documents');
const SKIPPED = ['EXPECT.json', 'provenance.json'];

function files(dir) {
  if (!fs.existsSync(dir)) return [];
  const out = [];
  for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
    const p = path.join(dir, e.name);
    if (e.isDirectory()) out.push(...files(p));
    else if (/\.json(\.gz)?$/.test(e.name) && !SKIPPED.includes(e.name)) out.push(p);
  }
  return out.sort();
}

function read(file) {
  const bytes = fs.readFileSync(file);
  return (file.endsWith('.gz') ? zlib.gunzipSync(bytes) : bytes).toString('utf8');
}

// The report-2 documents that the d.ts and the second validator hold: every fixture document and the contract bundle.
const REPORT2 = [
  path.join(DOCUMENTS, 'cases'),
  path.join(DOCUMENTS, 'variants'),
  path.join(DOCUMENTS, 'corpus'),
  path.join(DOCUMENTS, 'coverage'),
  path.join(FIXTURES, 'contract', 'report-2'),
];

module.exports = { REPO, FIXTURES, DOCUMENTS, REPORT2, files, read };
