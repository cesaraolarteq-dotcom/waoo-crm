// Actual retry/form JavaScript and Blade. Every request is intercepted; no provider/server writes.
const {test,before,after}=require('node:test');
const assert=require('node:assert/strict');
const {execFileSync}=require('node:child_process');
const fs=require('node:fs');
const path=require('node:path');
const {chromium}=require('playwright');
let browser;
before(async()=>{browser=await chromium.launch({headless:true,executablePath:process.env.WAOO_BROWSER_EXECUTABLE||undefined,args:['--no-sandbox']});});
after(async()=>{await browser?.close();});
for(const lang of ['es','en']) {
  test(`${lang} retry pins versions and UUID, keeps saved details on failure, refreshes explicitly after conflict`,async()=>{
    const html=execFileSync('docker',['exec','waoo-phase1-runtime','php','tests/Browser/render-same-visit.php',lang,'retry'],{encoding:'utf8'});
    const page=await browser.newPage({viewport:{width:390,height:844},hasTouch:true});
    const requests=[],errors=[];let reads=0;
    page.on('pageerror',e=>errors.push(e.message));
    await page.route('**/*',route=>{
      const request=route.request(),url=new URL(request.url());
      if(request.method()==='POST') {
        requests.push({path:url.pathname,body:request.postDataJSON(),key:request.headers()['idempotency-key']});
        return route.fulfill({status:requests.length<3?503:409,contentType:'application/json',body:JSON.stringify({message:requests.length<3?'Saved; try again':'Source changed; review again'})});
      }
      if(url.pathname==='/'||url.pathname==='/portal'){reads++;return route.fulfill({contentType:'text/html',body:html});}
      if(['/css/waoo-tokens.css','/css/waoo-portal.css'].includes(url.pathname))return route.fulfill({contentType:'text/css',body:fs.readFileSync(path.join(__dirname,'../../public',url.pathname),'utf8')});
      if(['/img/waoo-logo.png','/img/favicon.png'].includes(url.pathname))return route.fulfill({contentType:'image/png',body:fs.readFileSync(path.join(__dirname,'../../public',url.pathname))});
      return route.abort();
    });
    await page.goto('https://crm.example.test/');
    assert.equal(requests.length,0);assert.equal(await page.locator('#details-form').count(),0);
    const button=page.locator('#prepare-contract-form button[type=submit]');
    for(let attempt=0;attempt<3;attempt++){
      await button.tap();await page.waitForFunction(n=>document.querySelector('#prepare-contract-error').textContent===(n<3?'Saved; try again':'Source changed; review again'),attempt+1);
      if(attempt<2)await button.waitFor({state:'visible'});
    }
    assert.equal(requests.length,3);
    for(const request of requests){assert.equal(request.path,'/portal/api/details/contract');assert.deepEqual(request.body,{expected_event_version:7,expected_revision:3});assert.equal(request.key,requests[0].key);}
    assert.match(requests[0].key,/^[0-9a-f]{8}-[0-9a-f-]{27}$/);
    assert.equal(await button.isDisabled(),true);assert.equal(await page.locator('#prepare-contract-refresh').isVisible(),true);
    assert.equal(reads,1);await page.locator('#prepare-contract-refresh').tap();await page.waitForURL(/\/portal\?lang=/);
    assert.equal(reads,2);assert.equal(requests.length,3);assert.deepEqual(errors,[]);await page.close();
  });
  test(`${lang} agreed address and ZIP remain readable and cannot be edited`,async()=>{
    const html=execFileSync('docker',['exec','waoo-phase1-runtime','php','tests/Browser/render-same-visit.php',lang,'fixed'],{encoding:'utf8'});
    const page=await browser.newPage({viewport:{width:390,height:844}});await page.route('**/*',route=>route.request().url()==='https://crm.example.test/'?route.fulfill({contentType:'text/html',body:html}):route.abort());
    await page.goto('https://crm.example.test/');
    for(const [name,value]of Object.entries({event_address:'123 Example Lane',event_postal_code:'77001'})){
      const input=page.locator(`[name="${name}"]`);assert.equal(await input.isEditable(),false);assert.equal(await input.inputValue(),value);
      await input.focus();await page.keyboard.type('changed');assert.equal(await input.inputValue(),value);
    }
    assert.equal(await page.locator('[name="first_name"]').isEditable(),true);await page.close();
  });
  test(`${lang} explicit detail correction saves once and removes the review query before continuing`,async()=>{
    const html=execFileSync('docker',['exec','waoo-phase1-runtime','php','tests/Browser/render-same-visit.php',lang,'fixed'],{encoding:'utf8'});
    const page=await browser.newPage({viewport:{width:390,height:844}});let posts=0,reads=0,payload;const errors=[];
    page.on('pageerror',e=>errors.push(e.message));
    await page.route('**/*',route=>{
      const request=route.request(),url=new URL(request.url());
      if(url.pathname==='/portal/api/details'&&request.method()==='POST'){
        posts++;payload=request.postDataJSON();return route.fulfill({status:201,contentType:'application/json',body:JSON.stringify({contract_preparation:{status:'ready'}})});
      }
      if(url.pathname==='/'){reads++;return route.fulfill({contentType:'text/html',body:html});}
      return route.abort();
    });
    await page.goto(`https://crm.example.test/?review_details=1&lang=${lang}`);
    for(const [name,value]of Object.entries({first_name:'María',last_name:'González',phone:'+17135550126',email:'maria@example.test',child_name:'Sofía',child_age:'7',children_count:'12'}))await page.locator(`[name="${name}"]`).fill(value);
    await page.locator('[name="event_language"][value="es"]').check();await page.locator('[name="setup_area"][value="indoor"]').check();
    await page.locator('#details-form button[type=submit]').click();
    await page.waitForURL(url=>!url.searchParams.has('review_details'));
    assert.equal(posts,1);assert.equal(reads,2);assert.equal(payload.expected_event_version,7);assert.equal(payload.expected_revision,3);
    assert.equal(payload.event_address,'123 Example Lane');assert.equal(payload.event_postal_code,'77001');assert.deepEqual(errors,[]);
    await page.close();
  });
}
