// Real Blade/form JavaScript, synthetic API only. Run with a local Playwright installation; never sends a message.
const {test,before,after}=require('node:test');
const assert=require('node:assert/strict');
const {execFileSync}=require('node:child_process');
const fs=require('node:fs'),path=require('node:path');
const {chromium}=require('playwright');
let browser;
before(async()=>{browser=await chromium.launch({headless:true,executablePath:process.env.WAOO_BROWSER_EXECUTABLE||undefined,args:['--no-sandbox']});});
after(async()=>{await browser?.close();});
for(const lang of ['es','en'])for(const theme of ['light','dark'])for(const width of [390,1280])test(`${lang} ${theme} ${width}: corporate details, ZIP and optional company fields`,async()=>{
 const html=execFileSync('docker',['exec','-e','APP_PATH='+(process.env.WAOO_APP_PATH||'/app'),'waoo-phase1-runtime','php','tests/Browser/render-night-audit.php',lang,'corporate'],{encoding:'utf8'});
 const page=await browser.newPage({viewport:{width,height:844},colorScheme:theme});
 const errors=[];page.on('pageerror',e=>errors.push(e.message));let payload;
 await page.route('**/*',r=>{
  const u=new URL(r.request().url());
  if(u.pathname==='/portal/api/details'){payload=r.request().postDataJSON();return r.fulfill({status:422,contentType:'application/json',body:JSON.stringify({message:'Synthetic response only'})});}
  if(u.pathname==='/')return r.fulfill({contentType:'text/html',body:html});
  const root=path.resolve(__dirname,'../../public'),file=path.resolve(root,'.'+decodeURIComponent(u.pathname));
  if(file.startsWith(root+'/')&&fs.existsSync(file)&&fs.statSync(file).isFile()){
   const mime={'.css':'text/css','.js':'application/javascript','.png':'image/png','.svg':'image/svg+xml','.woff2':'font/woff2'}[path.extname(file)];
   if(mime)return r.fulfill({contentType:mime,body:fs.readFileSync(file)});
  }
  return r.abort();
 });
 await page.goto('https://crm.example.test/');
 const text=await page.locator('body').innerText();
 assert.ok(text.includes(lang==='es'
  ? 'a $70 la hora, por el mismo tiempo de tu paquete.'
  : 'at $70 per hour, for the same length as your package.'));
 assert.ok(!text.includes(lang==='es'?'mínimo 2 horas':'2-hour minimum'));
 await page.evaluate(t=>document.documentElement.dataset.theme=t,theme);
 assert.equal(await page.locator('[name="child_age"]').count(),0);
 assert.equal(await page.locator('[name="child_name"]').getAttribute('required'),null);
 await page.locator('[name="company_enabled"]').uncheck();
 assert.equal(await page.locator('#p-company').isVisible(),false);
 await page.locator('[name="company_enabled"]').check();
 assert.equal(await page.locator('#p-company').isVisible(),true);
 await page.locator('[name="company_name"]').fill('Fundación Ejemplo');
 for(const [name,value]of Object.entries({first_name:'María',last_name:'González',phone:'346-555-0100',email:'maria@example.test',event_address:'123 Example St, Katy, TX 77493',child_name:'',children_count:'0'}))await page.locator(`[name="${name}"]`).fill(value);
 await page.locator('[name="event_address"]').press('Tab');
 assert.equal(await page.locator('[name="event_postal_code"]').inputValue(),'77493');
 await page.locator('[name="event_language"][value="es"]').check();
 await page.locator('[name="setup_area"][value="indoor"]').check();
 assert.equal(await page.locator('#details-form').evaluate(f=>f.checkValidity()),true);
 assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false);
 assert.ok((await page.locator('#details-form button[type="submit"]').boundingBox()).height>=44);
 await page.locator('#details-form button[type="submit"]').click();
 await page.waitForFunction(()=>document.querySelector('#details-error').textContent==='Synthetic response only');
 assert.equal(payload.child_name,null);assert.equal(payload.child_age,null);assert.equal(payload.children_count,0);
 assert.equal(payload.billing_company.name,'Fundación Ejemplo');assert.equal(payload.locale,lang);
 assert.deepEqual(errors,[]);
 if(process.env.WAOO_BROWSER_SCREENSHOT)await page.screenshot({path:`${process.env.WAOO_BROWSER_SCREENSHOT}-${lang}-${theme}-${width}.png`,fullPage:true});
 await page.close();
});
