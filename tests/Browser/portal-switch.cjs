// Actual portal layout and form scripts; every request intercepted, no real token/account/provider.
const {test,before,after}=require('node:test');
const assert=require('node:assert/strict');
const {execFileSync}=require('node:child_process');
const {chromium}=require('playwright');
let browser;
before(async()=>{browser=await chromium.launch({headless:true,executablePath:process.env.WAOO_BROWSER_EXECUTABLE||undefined,args:['--no-sandbox']});});
after(async()=>{await browser?.close();});

for(const lang of ['es','en'])for(const target of ['details','quote','invalid']) {
  test(`${lang} opening ${target} link replaces the old page without sending its forms`,async()=>{
    const html=execFileSync('docker',['exec','waoo-phase1-runtime','php','tests/Browser/render-same-visit.php',lang,'fixed'],{encoding:'utf8'});
    const page=await browser.newPage({viewport:{width:390,height:844}});
    const token='A'.repeat(43),posts=[],errors=[];let reads=0;
    page.on('pageerror',e=>errors.push(e.message));
    await page.route('**/*',async route=>{
      const request=route.request(),url=new URL(request.url());
      if(request.method()==='POST') {
        posts.push({path:url.pathname,body:request.postDataJSON()});
        assert.equal(url.pathname,'/portal/api/session');
        assert.deepEqual(request.postDataJSON(),{token});
        assert.equal(await page.evaluate(()=>location.hash),'');
        assert.equal(await page.locator('.p-main').isVisible(),false);
        if(target==='invalid')return route.fulfill({status:403,contentType:'application/json',body:JSON.stringify({message:'Expired synthetic link'})});
        return route.fulfill({contentType:'application/json',body:JSON.stringify({scopes:target==='quote'?['quote.read']:['details.submit'],csrf_token:'synthetic-new-csrf'})});
      }
      if(url.pathname==='/portal'||url.pathname==='/portal/quote') {
        reads++;return route.fulfill({contentType:'text/html',body:reads===1?html:'<!doctype html><meta charset="utf-8"><h1>Celebración de Lucía</h1>'});
      }
      return route.abort();
    });
    await page.goto(`https://crm.example.test/portal?lang=${lang}#token=${token}`);
    if(target==='invalid') {
      await page.waitForFunction(()=>document.querySelector('.p-main')?.style.visibility==='');
      assert.equal(await page.locator('.p-main form').count(),0);
      assert.equal(await page.locator('.p-main h1').count(),1);
      assert.notEqual(await page.locator('.p-main h1').textContent(),'');
      assert.equal(reads,1);
    }else {
      await page.waitForURL(url=>url.pathname===(target==='quote'?'/portal/quote':'/portal')&&!url.hash&&url.searchParams.get('lang')===lang);
      await page.getByRole('heading',{name:'Celebración de Lucía'}).waitFor();
      assert.equal(reads,2);
    }
    assert.equal(posts.length,1);assert.deepEqual(errors,[]);assert.equal(new URL(page.url()).hash,'');
    await page.close();
  });
}

test('an already open portal switches when a new link changes only its fragment',async()=>{
  const html=execFileSync('docker',['exec','waoo-phase1-runtime','php','tests/Browser/render-same-visit.php','es','fixed'],{encoding:'utf8'});
  const page=await browser.newPage();const token='B'.repeat(43);let reads=0,posts=0;
  try {
    await page.route('**/*',route=>{
      const request=route.request(),url=new URL(request.url());
      if(request.method()==='POST') {
        posts++;assert.equal(url.pathname,'/portal/api/session');assert.deepEqual(request.postDataJSON(),{token});
        return route.fulfill({contentType:'application/json',body:JSON.stringify({scopes:['details.submit'],csrf_token:'synthetic-new-csrf'})});
      }
      if(url.pathname==='/portal') {
        reads++;return route.fulfill({contentType:'text/html',body:reads===1?html:'<!doctype html><meta charset="utf-8"><h1>Celebración de Lucía</h1>'});
      }
      return route.abort();
    });
    await page.goto('https://crm.example.test/portal?lang=es');
    assert.equal(posts,0);
    const exchanged=page.waitForResponse(response=>new URL(response.url()).pathname==='/portal/api/session',{timeout:2000});
    await page.evaluate(value=>{location.hash='token='+value;},token);
    await exchanged;
    await page.getByRole('heading',{name:'Celebración de Lucía'}).waitFor({timeout:3000});
    assert.equal(posts,1);assert.equal(reads,2);assert.equal(new URL(page.url()).hash,'');
  } finally {await page.close();}
});

test('rapid link changes serialize exchanges, use the returned CSRF and open only the latest party',async()=>{
  const html=execFileSync('docker',['exec','waoo-phase1-runtime','php','tests/Browser/render-same-visit.php','es','fixed'],{encoding:'utf8'});
  const page=await browser.newPage();page.setDefaultTimeout(5000);
  const tokens=['A'.repeat(43),'B'.repeat(43),'C'.repeat(43)],posts=[];let reads=0,releaseFirst;
  const gate=new Promise(resolve=>{releaseFirst=resolve;});
  try {
    await page.route('**/*',async route=>{
      const request=route.request(),url=new URL(request.url());
      if(request.method()==='POST') {
        const n=posts.length;posts.push({path:url.pathname,body:request.postDataJSON(),csrf:request.headers()['x-csrf-token']});
        assert.equal(url.pathname,'/portal/api/session');
        if(n===0)await gate;
        return route.fulfill({contentType:'application/json',body:JSON.stringify({scopes:n===0?['details.submit']:['quote.read'],csrf_token:n===0?'synthetic-csrf-A':'synthetic-csrf-C'})});
      }
      if(url.pathname==='/portal'||url.pathname==='/portal/quote') {
        reads++;return route.fulfill({contentType:'text/html',body:reads===1?html:'<!doctype html><meta charset="utf-8"><h1>Celebración de Lucía</h1>'});
      }
      return route.abort();
    });
    await page.goto('https://crm.example.test/portal?lang=es');
    const first=page.waitForRequest(request=>request.method()==='POST');
    await page.evaluate(value=>{location.hash='token='+value;},tokens[0]);await first;
    await page.evaluate(values=>{for(const value of values)location.hash='token='+value;},tokens.slice(1));
    await page.waitForFunction(()=>location.hash==='');
    assert.equal(posts.length,1);assert.equal(await page.locator('.p-main').isVisible(),false);
    releaseFirst();
    await page.waitForURL(url=>url.pathname==='/portal/quote'&&!url.hash);
    await page.getByRole('heading',{name:'Celebración de Lucía'}).waitFor();
    assert.equal(posts.length,2);assert.deepEqual(posts.map(p=>p.body),[{token:tokens[0]},{token:tokens[2]}]);
    assert.equal(posts[1].csrf,'synthetic-csrf-A');assert.equal(reads,2);
  } finally {releaseFirst();await page.close();}
});

test('after an invalid exchange another link can open without restoring an old form',async()=>{
  const html=execFileSync('docker',['exec','waoo-phase1-runtime','php','tests/Browser/render-same-visit.php','en','fixed'],{encoding:'utf8'});
  const page=await browser.newPage();page.setDefaultTimeout(5000);let reads=0,posts=0;
  try {
    await page.route('**/*',route=>{
      const request=route.request(),url=new URL(request.url());
      if(request.method()==='POST') {
        posts++;assert.equal(url.pathname,'/portal/api/session');
        return route.fulfill({status:posts===1?403:200,contentType:'application/json',body:JSON.stringify({scopes:['details.submit'],csrf_token:'synthetic-current-csrf'})});
      }
      if(url.pathname==='/portal') {
        reads++;return route.fulfill({contentType:'text/html',body:reads===1?html:'<!doctype html><meta charset="utf-8"><h1>Celebración de Lucía</h1>'});
      }
      return route.abort();
    });
    await page.goto('https://crm.example.test/portal?lang=en#token='+'A'.repeat(43));
    await page.waitForFunction(()=>document.querySelector('.p-main')?.style.visibility==='');
    assert.equal(await page.locator('.p-main form').count(),0);
    await page.evaluate(()=>{location.hash='token='+'C'.repeat(43);});
    await page.getByRole('heading',{name:'Celebración de Lucía'}).waitFor();
    assert.equal(posts,2);assert.equal(reads,2);assert.equal(new URL(page.url()).hash,'');
  } finally {await page.close();}
});

test('a malformed latest fragment prevents an earlier in-flight link from reopening the old party',async()=>{
  const html=execFileSync('docker',['exec','waoo-phase1-runtime','php','tests/Browser/render-same-visit.php','en','fixed'],{encoding:'utf8'});
  const page=await browser.newPage();page.setDefaultTimeout(5000);let reads=0,posts=0,release;
  const gate=new Promise(resolve=>{release=resolve;});
  try {
    await page.route('**/*',async route=>{
      const request=route.request(),url=new URL(request.url());
      if(request.method()==='POST') {
        posts++;await gate;return route.fulfill({contentType:'application/json',body:JSON.stringify({scopes:['details.submit'],csrf_token:'synthetic-current-csrf'})});
      }
      if(url.pathname==='/portal') {reads++;return route.fulfill({contentType:'text/html',body:html});}
      return route.abort();
    });
    await page.goto('https://crm.example.test/portal?lang=en');
    const first=page.waitForRequest(request=>request.method()==='POST');
    await page.evaluate(()=>{location.hash='token='+'A'.repeat(43);});await first;
    await page.evaluate(()=>{location.hash='token=malformed';});
    await page.waitForFunction(()=>location.hash==='');
    const returned=page.waitForResponse(response=>new URL(response.url()).pathname==='/portal/api/session');
    release();await returned;await page.waitForLoadState('networkidle');
    assert.equal(posts,1);assert.equal(reads,1);assert.equal(await page.locator('.p-main form').count(),0);
    assert.equal(await page.locator('.p-main').isVisible(),true);
  } finally {release();await page.close();}
});

for(const lang of ['es','en'])for(const mode of ['landing','tip']) {
  test(`${lang} ${mode} access keeps one exchange and the correct token namespace`,async()=>{
    const html=execFileSync('docker',['exec','waoo-phase1-runtime','php','tests/Browser/render-portal-access.php',lang,mode],{encoding:'utf8'});
    const page=await browser.newPage();page.setDefaultTimeout(5000);
    const token='D'.repeat(43),expected=mode==='tip'?'/portal/tip/session':'/portal/api/session';let reads=0,posts=0;
    try {
      await page.route('**/*',route=>{
        const request=route.request(),url=new URL(request.url());
        if(request.method()==='POST') {
          posts++;assert.equal(url.pathname,expected);assert.deepEqual(request.postDataJSON(),{token});
          return route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,scopes:['details.submit'],csrf_token:'synthetic-current-csrf'})});
        }
        if(url.pathname==='/portal'||url.pathname==='/portal/tip') {
          reads++;return route.fulfill({contentType:'text/html',body:reads===1?html:'<!doctype html><meta charset="utf-8"><h1>Celebración de Lucía</h1>'});
        }
        return route.abort();
      });
      const root=mode==='tip'?'/portal/tip':'/portal';
      await page.goto(`https://crm.example.test${root}?lang=${lang}#${mode==='tip'?'':'token='}${token}`);
      await page.getByRole('heading',{name:'Celebración de Lucía'}).waitFor();
      assert.equal(posts,1);assert.equal(reads,2);assert.equal(new URL(page.url()).hash,'');
      assert.equal(new URL(page.url()).pathname,root);
    } finally {await page.close();}
  });
}
