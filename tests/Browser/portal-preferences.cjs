// Real Blade form + real submit script, synthetic VM/API only. No server, provider or CRM data writes.
const {test,before,after}=require('node:test');
const assert=require('node:assert/strict');
const {execFileSync}=require('node:child_process');
const fs=require('node:fs');
const path=require('node:path');
const {chromium}=require('playwright');
let browser;
before(async()=>{browser=await chromium.launch({headless:true,executablePath:process.env.WAOO_BROWSER_EXECUTABLE||undefined,args:['--no-sandbox']});});
after(async()=>{await browser?.close();});
for(const lang of ['es','en'])test(`large ${lang} party choices preserve required validation, keyboard selection and exact API values on mobile`,async()=>{
  const html=execFileSync('docker',['exec','waoo-phase1-runtime','php','tests/Browser/render-portal-preferences.php',lang],{encoding:'utf8'});
  const page=await browser.newPage({viewport:{width:390,height:844},hasTouch:true});
  const errors=[];page.on('pageerror',e=>errors.push(e.message));
  let payload;
  await page.route('**/*',route=>{
    const url=new URL(route.request().url());
    if(url.pathname==='/portal/api/details'){
      payload=route.request().postDataJSON();return route.fulfill({status:422,contentType:'application/json',body:JSON.stringify({message:'Synthetic response only'})});
    }
    if(url.pathname==='/')return route.fulfill({contentType:'text/html',body:html});
    if(['/css/waoo-tokens.css','/css/waoo-portal.css'].includes(url.pathname))return route.fulfill({contentType:'text/css',body:fs.readFileSync(path.join(__dirname,'../../public',url.pathname),'utf8')});
    if(['/img/waoo-logo.png','/img/favicon.png'].includes(url.pathname))return route.fulfill({contentType:'image/png',body:fs.readFileSync(path.join(__dirname,'../../public',url.pathname))});
    return route.abort();
  });
  await page.goto('https://crm.example.test/');
  for(const [name,value]of Object.entries({first_name:'María',last_name:'González',phone:'346-555-0100',email:'maria@example.test',event_address:'123 Oak Street',event_postal_code:'77493',child_name:'Lucía',child_age:'7',children_count:'15'}))await page.locator(`[name="${name}"]`).fill(value);
  assert.equal(await page.locator('select[name="event_language"],select[name="setup_area"]').count(),0);
  assert.equal(await page.locator('#details-form').evaluate(f=>f.checkValidity()),false);
  const language=page.locator('.p-chip').filter({has:page.locator('[name="event_language"][value="es"]')});
  assert.ok((await language.boundingBox()).height>=48);
  await language.tap();
  const selected=page.locator('[name="event_language"][value="es"]');
  await selected.focus();await page.keyboard.press('ArrowRight');
  assert.equal(await page.locator('#details-form').evaluate(f=>f.event_language.value),'en');
  const outdoor=page.locator('.p-chip').filter({has:page.locator('[name="setup_area"][value="outdoor"]')});
  await outdoor.tap();
  assert.equal(await page.locator('#details-form').evaluate(f=>f.checkValidity()),true);
  await page.locator('#details-form button[type="submit"]').tap();
  await page.waitForFunction(()=>document.querySelector('#details-error').textContent==='Synthetic response only');
  assert.equal(payload.event_language,'en');assert.equal(payload.setup_area,'outdoor');assert.equal(payload.children_count,15);
  assert.equal(payload.expected_event_version,1);assert.equal(payload.expected_revision,0);assert.equal(payload.locale,lang);
  assert.equal(payload.start_time_confirmed,true);assert.equal(payload.billing_address,payload.event_address);
  assert.deepEqual(errors,[]);
  if(process.env.WAOO_BROWSER_SCREENSHOT)await page.screenshot({path:`${process.env.WAOO_BROWSER_SCREENSHOT}-${lang}.png`,fullPage:true});
  await page.close();
});
