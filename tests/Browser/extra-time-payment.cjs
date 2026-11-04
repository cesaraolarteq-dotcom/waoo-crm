// Real portal script; all HTTP endpoints are intercepted. Nothing is charged or sent.
const {test,before,after}=require('node:test');
const assert=require('node:assert/strict');
const {execFileSync}=require('node:child_process');
const fs=require('node:fs'),path=require('node:path');
const {chromium}=require('playwright');
let browser;
before(async()=>{browser=await chromium.launch({headless:true,executablePath:process.env.WAOO_BROWSER_EXECUTABLE||undefined,args:['--no-sandbox']});});
after(async()=>{await browser?.close();});
for(const full of [true,false])for(const lang of ['es','en'])for(const theme of ['light','dark'])for(const width of [390,1280])test(`${full?'full':'half'} payment ${lang} ${theme} ${width}`,async()=>{
 const html=execFileSync('docker',['exec','-e','APP_PATH='+(process.env.WAOO_APP_PATH||'/app'),'waoo-phase1-runtime','php','tests/Browser/render-extra-time-payment.php',lang,full?'full':'half'],{encoding:'utf8'});
 const page=await browser.newPage({viewport:{width,height:844},colorScheme:theme});
 const errors=[];page.on('pageerror',e=>errors.push(e.message));let posted;
 await page.route('**/*',route=>{
  const u=new URL(route.request().url());
  if(u.pathname==='/portal/api/quick-payment/checkout'){posted=route.request().postDataJSON();return route.fulfill({status:422,contentType:'application/json',body:JSON.stringify({message:'Synthetic response only'})});}
  if(u.pathname==='/')return route.fulfill({contentType:'text/html',body:html});
  const root=path.resolve(__dirname,'../../public'),file=path.resolve(root,'.'+decodeURIComponent(u.pathname));
  if(file.startsWith(root+'/')&&fs.existsSync(file)&&fs.statSync(file).isFile()){
   const mime={'.css':'text/css','.js':'application/javascript','.png':'image/png','.svg':'image/svg+xml','.woff2':'font/woff2'}[path.extname(file)];
   if(mime)return route.fulfill({contentType:mime,body:fs.readFileSync(file)});
  }
  return route.abort();
 });
 await page.goto('https://crm.example.test/');
 await page.evaluate(t=>document.documentElement.dataset.theme=t,theme);
 assert.equal(await page.locator('#quick-stripe input:checked').inputValue(),full?'100':'50');
 assert.equal(await page.locator('#quick-stripe input[value="50"]').count(),full?0:1);
 assert.equal(await page.locator('#quick-zelle input[name="amount"]').inputValue(),full?'525.00':'262.50');
 assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false);
 const button=page.locator('#quick-stripe button');assert.ok((await button.boundingBox()).height>=44);
 await button.focus();assert.ok(await button.evaluate(el=>{const s=getComputedStyle(el);return s.outlineStyle!=='none'||s.boxShadow!=='none';}));
 await button.press('Enter');
 await page.waitForFunction(()=>document.querySelector('#quick-payment-error').textContent==='Synthetic response only');
 assert.equal(posted.deposit_percent,full?100:50);assert.deepEqual(errors,[]);
 if(process.env.WAOO_BROWSER_SCREENSHOT)await page.screenshot({path:`${process.env.WAOO_BROWSER_SCREENSHOT}-${full?'full':'half'}-${lang}-${theme}-${width}.png`,fullPage:true});
 await page.close();
});
