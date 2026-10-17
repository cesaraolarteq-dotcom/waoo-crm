// Execute the actual Alpine expression from the shared panel with fake clipboard implementations.
// No browser, OS app, provider or real client URL is exercised.
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');

const template = fs.readFileSync(path.resolve(__dirname, '../../../resources/views/filament/pages/partials/link-panel.blade.php'), 'utf8');
const match = template.match(/x-data="([\s\S]*?)">/);
assert.ok(match, 'Shared Alpine component exists');
const expression = match[1].replace("@js($panel['locale'] ?? 'es')", "'es'");
const settle = () => new Promise(resolve => setImmediate(resolve));

(async () => {
    let written;
    const success = vm.runInNewContext('(' + expression + ')', {
        navigator: { clipboard: { writeText: async text => { written = text; } } }, Promise,
    });
    success.copy('fictional-link-only', 'link');
    assert.equal(success.copied, '', 'No success claim before the promise resolves');
    await settle();
    assert.equal(written, 'fictional-link-only');
    assert.equal(success.copied, 'link');
    assert.equal(success.manual, false);

    const denied = vm.runInNewContext('(' + expression + ')', {
        navigator: { clipboard: { writeText: async () => { throw new Error('Fixture permission denied'); } } }, Promise,
    });
    denied.copy('fictional-link-only', 'msg');
    await settle();
    assert.equal(denied.copied, '');
    assert.equal(denied.manual, true);

    const missing = vm.runInNewContext('(' + expression + ')', { navigator: {}, Promise });
    missing.copy('fictional-link-only', 'link');
    await settle();
    assert.equal(missing.copied, '');
    assert.equal(missing.manual, true);
    console.log('Clipboard success, rejection and missing API passed (actual shared expression; JavaScript only).');
})().catch(error => { console.error(error); process.exitCode = 1; });
