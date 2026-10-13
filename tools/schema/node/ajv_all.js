// Every fixture document and the contract bundle through a second, standard validator (ajv 8, draft-04 dialect), so a
// published contract cannot hold a rule that only justinrainbow/json-schema accepts. `date-time` is checked with an
// RFC 3339 pattern. Every negative fixture must be rejected, except a strict-only one: only the strict twin of
// JsonSchemaConformanceTest judges it.
//   node ajv_all.js            exit 1 on an invalid document or an accepted negative
'use strict';
const fs = require('fs');
const path = require('path');
const Ajv4 = require('ajv-draft-04');
const Ajv7 = require('ajv');
const { REPO, FIXTURES, DOCUMENTS, REPORT2, files, read } = require('./documents');

const DATE_TIME = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})$/;
const NEGATIVE = path.join(FIXTURES, 'schema', 'negative');
const CONTRACT = path.join(FIXTURES, 'contract');
const SARIF = path.join(FIXTURES, 'sarif');

const sets = [
  { name: 'report-2', schema: path.join(REPO, 'resources', 'lockrot-report-2.schema.json'), documents: REPORT2, negative: path.join(NEGATIVE, 'report-2') },
  { name: 'explain-2', schema: path.join(REPO, 'resources', 'lockrot-explain-2.schema.json'), documents: [path.join(DOCUMENTS, 'explain'), path.join(CONTRACT, 'explain-2')], negative: path.join(NEGATIVE, 'explain-2') },
  { name: 'baseline-2', schema: path.join(REPO, 'resources', 'lockrot-baseline-2.schema.json'), documents: [path.join(DOCUMENTS, 'baseline'), path.join(CONTRACT, 'baseline-2')], negative: path.join(NEGATIVE, 'baseline-2'), skip: /regenerate-from-baseline-1/ },
  { name: 'config-2', schema: path.join(REPO, 'resources', 'lockrot-config-2.schema.json'), documents: [path.join(DOCUMENTS, 'config')], negative: path.join(NEGATIVE, 'config-2') },
  { name: 'sarif-properties-2', schema: path.join(SARIF, 'lockrot-sarif-properties-2.schema.json'), documents: [path.join(CONTRACT, 'sarif')], negative: path.join(SARIF, 'negative') },
];

function strictOnly(file, doc) {
  if (file.endsWith('.strict.json')) return true;
  return Boolean(doc.$expect && doc.$expect.strict);
}

const summary = { documents: {}, negatives: {}, failures: [] };
const fail = (file, errors) => summary.failures.push({ file: path.relative(REPO, file), errors });
for (const set of sets) {
  const ajv = new Ajv4({ strict: false, allErrors: true });
  ajv.addFormat('date-time', DATE_TIME);
  const validate = ajv.compile(JSON.parse(fs.readFileSync(set.schema, 'utf8')));
  const c = { files: 0, valid: 0 };
  for (const f of set.documents.flatMap(files)) {
    if (set.skip && set.skip.test(f)) continue;
    c.files++;
    if (validate(JSON.parse(read(f)))) c.valid++;
    else fail(f, validate.errors.slice(0, 4).map((e) => `${e.instancePath} ${e.message}`));
  }
  summary.documents[set.name] = c;
  const n = { files: 0, rejected: 0, strict_only: 0 };
  for (const f of files(set.negative)) {
    const doc = JSON.parse(read(f));
    const strict = strictOnly(f, doc);
    delete doc.$expect;
    n.files++;
    const valid = validate(doc);
    if (strict) n.strict_only++;
    else if (!valid) n.rejected++;
    else fail(f, ['a negative is accepted']);
  }
  summary.negatives[set.name] = n;
}
{
  // The recorded SARIF logs against SARIF 2.1.0's own schema (draft-07).
  const ajv = new Ajv7({ strict: false, allErrors: true });
  ajv.addFormat('date-time', DATE_TIME);
  ajv.addFormat('uri', /^[a-z][a-z0-9+.-]*:/i);
  ajv.addFormat('uri-reference', /^\S*$/);
  const sarif = JSON.parse(fs.readFileSync(path.join(SARIF, 'sarif-schema-2.1.0.json'), 'utf8'));
  delete sarif.$schema;
  const validate = ajv.compile(sarif);
  const c = { files: 0, valid: 0 };
  for (const f of files(path.join(CONTRACT, 'sarif'))) {
    c.files++;
    if (validate(JSON.parse(read(f)))) c.valid++;
    else fail(f, validate.errors.slice(0, 4).map((e) => `${e.instancePath} ${e.message}`));
  }
  summary.documents['sarif-2.1.0'] = c;
}
summary.ok = summary.failures.length === 0;
console.log(JSON.stringify(summary, null, 1));
process.exit(summary.ok ? 0 : 1);
