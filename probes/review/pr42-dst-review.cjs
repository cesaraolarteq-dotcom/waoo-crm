// Regression diagnostic: the browser must send wall time only; the server owns date/DST resolution.
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const source = fs.readFileSync(require('node:path').resolve(__dirname, '../../resources/views/portal/show.blade.php'), 'utf8');
const assignment = source.match(/body\.requested_local_time\s*=\s*[^;]+;/)?.[0];
assert.ok(assignment, 'The requested local-time assignment must exist.');
const context = {body: {}, f: {requested_time: {value: '03:30'}}};
vm.runInNewContext(assignment, context);
assert.equal(context.body.requested_local_time, '03:30');
assert.equal(source.includes('f.dataset.eventOffset'), false, 'Do not append the original event offset.');
assert.equal(source.includes('body.requested_start_at ='), false, 'The server must resolve the instant.');
console.log('PASS: requested 03:30 remains Houston wall time; server DST regressions are in QuickDetailsFlowTest.');
