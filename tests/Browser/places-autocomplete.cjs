// Synthetic browser checks, no Google requests/keys/accounts or CRM data.
// Requires Playwright; optionally set WAOO_BROWSER_EXECUTABLE to a system Chromium.
const {test, before, after} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {chromium} = require('playwright');
let browser;
before(async () => { browser = await chromium.launch({headless:true,
  executablePath:process.env.WAOO_BROWSER_EXECUTABLE || undefined, args:['--no-sandbox']}); });
after(async () => { await browser?.close(); });

async function pageFor(options = {}) {
  const page = await browser.newPage({viewport:options.mobile ? {width:390,height:844} : {width:1280,height:900}, hasTouch:!!options.mobile});
  page.errors = [];
  page.on('pageerror', e => page.errors.push(e.message));
  await page.route('**/*', route => {
    if (!route.request().url().startsWith('https://maps.googleapis.com/')) return route.abort();
    return route.fulfill({contentType:'application/javascript', body:`
      window.google={maps:{importLibrary:async()=>({AutocompleteSessionToken:class{},AutocompleteSuggestion:{
        fetchAutocompleteSuggestions:async(request)=>{window.requested=request.input;
          await new Promise(r=>setTimeout(r,${options.delay || 0}));
          ${options.failure ? "throw new Error('Synthetic provider failure');" : ''}
          return {suggestions:[{placePrediction:{mainText:{text:'123 Oak Street'},secondaryText:{text:'Houston, TX'},
            toPlace:()=>({formattedAddress:'123 Oak Street, Houston, TX, USA',
              addressComponents:${options.noZip ? '[]' : "[{types:['postal_code'],longText:'77002'}]"},fetchFields:async()=>{}})}}]};
        }
      }})}};window.waooPlacesReady();`});
  });
  await page.setContent(`<style>${fs.readFileSync(path.join(__dirname,'../../public/css/waoo-admin.css'),'utf8')}
    body{margin:24px}input{height:36px;width:100%;box-sizing:border-box}.clip{height:40px;overflow:hidden;position:relative}
    </style><div wire:id="synthetic-owner"><div class="clip"><input data-places="address" aria-label="Dirección"></div>
    <input data-places="zip" value="77493" aria-label="ZIP"><button data-places="quote">Calcular</button></div><button id="elsewhere">Otro campo</button>`);
  await page.evaluate(() => {
    window.sets=[];window.quoted=0;
    window.Livewire={find:()=>({$set:async(key,value)=>window.sets.push([key,value])})};
    document.querySelector('[data-places="quote"]').onclick=()=>window.quoted++;
  });
  // A local non-key activates the script; every network request is intercepted above.
  await page.addScriptTag({content:fs.readFileSync(path.join(__dirname,'../../public/js/waoo-places.js'),'utf8')
    .replace('document.currentScript, key = me && me.dataset.key','document.currentScript, key = "synthetic-not-a-provider-key"')});
  return page;
}

test('list escapes the clipped wrapper, is hit-test visible, and selection sets address+ZIP before quote', async () => {
  const page=await pageFor();
  try {
    await page.getByLabel('Dirección').fill('123 Oak');
    const option=page.getByRole('option');await option.waitFor();
    assert.deepEqual(await option.evaluate(el=>{const r=el.getBoundingClientRect();return {
      body:el.parentElement.parentElement===document.body,
      visible:el.contains(document.elementFromPoint(r.x+r.width/2,r.y+r.height/2))};}),{body:true,visible:true});
    await option.click();
    await page.waitForFunction(()=>window.quoted===1);
    assert.deepEqual(await page.evaluate(()=>window.sets),[['data.address','123 Oak Street, Houston, TX'],['data.postal_code','77002']]);
    assert.deepEqual(page.errors,[]);
  } finally { await page.close(); }
});

test('mobile tap selects the same prediction without a native Google widget', async () => {
  const page=await pageFor({mobile:true});
  try {
    await page.getByLabel('Dirección').fill('123 Oak');
    await page.getByRole('option').tap();
    await page.waitForFunction(()=>window.quoted===1);
    assert.equal(await page.getByLabel('ZIP',{exact:true}).inputValue(),'77002');
  } finally { await page.close(); }
});

test('keyboard selection and missing ZIP clear the old ZIP without calculating another ZIPs fee', async () => {
  const page=await pageFor({noZip:true});
  try {
    const input=page.getByLabel('Dirección');await input.fill('123 Oak');
    await page.getByRole('option').waitFor();await input.press('ArrowDown');await input.press('Enter');
    await page.waitForFunction(()=>window.sets.length===2);
    assert.equal(await page.getByLabel('ZIP',{exact:true}).inputValue(),'');
    assert.equal(await page.evaluate(()=>window.quoted),0);
    assert.deepEqual(await page.evaluate(()=>window.sets.at(-1)),['data.postal_code','']);
  } finally { await page.close(); }
});

test('a late provider result cannot reopen suggestions after Escape or blur', async () => {
  for(const cancel of ['escape','blur']) {
    const page=await pageFor({delay:350});
    try {
      const input=page.getByLabel('Dirección');await input.fill('123 Oak');
      await page.waitForFunction(()=>window.requested==='123 Oak');
      if(cancel==='escape') await input.press('Escape');else await page.locator('#elsewhere').click();
      await page.waitForTimeout(500);
      assert.equal(await page.getByRole('option').count(),0,cancel+' reopened stale suggestions');
      assert.equal(await page.evaluate(()=>window.quoted),0);
    } finally { await page.close(); }
  }
});

test('provider error leaves manual address editing available without a page exception', async () => {
  const page=await pageFor({failure:true});
  try {
    await page.getByLabel('Dirección').fill('123 Oak');
    await page.waitForFunction(()=>window.requested==='123 Oak');await page.waitForTimeout(150);
    assert.equal(await page.getByRole('option').count(),0);
    assert.equal(await page.getByLabel('Dirección').inputValue(),'123 Oak');
    assert.deepEqual(page.errors,[]);
  } finally { await page.close(); }
});
