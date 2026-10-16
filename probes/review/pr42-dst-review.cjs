const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require('node:path').resolve(__dirname, '../../resources/views/portal/show.blade.php'), 'utf8');
const assignment = source.match(/body\.requested_start_at\s*=\s*[^;]+;/)?.[0];
if (!assignment) throw new Error('Requested-time assignment not found; review probe needs updating.');
const context = {body: {}, f: {dataset: {eventDate: '2026-11-01', eventOffset: '-05:00'}, requested_time: {value: '03:30'}}};
vm.runInNewContext(assignment, context);
const actualHoustonTime = new Intl.DateTimeFormat('en-GB', {timeZone: 'America/Chicago', hour: '2-digit', minute: '2-digit', hourCycle: 'h23'}).format(new Date(context.body.requested_start_at));
console.log(JSON.stringify({partyDate: context.f.dataset.eventDate, originalPartyOffset: context.f.dataset.eventOffset,
    clientRequestedLocalTime: context.f.requested_time.value, transmitted: context.body.requested_start_at, actualHoustonTime}, null, 2));
if (actualHoustonTime !== context.f.requested_time.value) process.exitCode = 1;
