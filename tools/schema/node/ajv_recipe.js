// Runs the Ajv recipe of docs/schema.md, its one `js` block, for each published -2
// schema and one valid document of it. A recipe that does not compile a schema, or that refuses the document, fails.
//   node ajv_recipe.js         exit 1 on a failure
'use strict';
const fs = require('fs');
const path = require('path');
const vm = require('vm');
const { REPO, DOCUMENTS, report2, files, read } = require('./documents');

const DOCS = path.join(REPO, 'docs', 'schema.md');
// The paths that the recipe names: its schema copy and its report.
const SCHEMA_COPY = 'ci/lockrot-report.schema.json';
const REPORT = 'lockrot.json';

function recipe() {
  const blocks = [...fs.readFileSync(DOCS, 'utf8').matchAll(/^```js\n([\s\S]*?)^```$/gm)].map((m) => m[1]);
  if (blocks.length !== 1) throw new Error(`docs/schema.md holds ${blocks.length} js blocks, not one`);
  return blocks[0];
}

function run(code, schemaFile, documentFile) {
  const paths = { [SCHEMA_COPY]: schemaFile, [REPORT]: documentFile };
  const stubFs = {
    readFileSync(name) {
      if (!(name in paths)) throw new Error(`the recipe reads ${name}`);
      return read(paths[name]);
    },
  };
  const errors = [];
  const sandbox = {
    require: (name) => (name === 'fs' ? stubFs : require(name)),
    console: { error: (...args) => errors.push(args.map((a) => JSON.stringify(a)).join(' ')) },
    process: { exit: (code) => { throw new Error(`exit ${code}: ${errors.join('; ')}`); } },
  };
  vm.runInNewContext(code, sandbox, { filename: 'docs/schema.md' });
}

const code = recipe();
const failures = [];
for (const doc of ['report', 'explain', 'baseline', 'config']) {
  const schema = path.join(REPO, 'resources', `lockrot-${doc}-2.schema.json`);
  const sample = doc === 'report' ? report2()[0] : files(path.join(DOCUMENTS, doc))[0];
  try {
    run(code, schema, sample);
  } catch (e) {
    failures.push(`${doc}-2: ${e.message}`);
  }
}
if (failures.length > 0) {
  console.error(failures.join('\n'));
  process.exit(1);
}
console.log('the Ajv recipe of docs/schema.md compiles report-2, explain-2, baseline-2 and config-2');
