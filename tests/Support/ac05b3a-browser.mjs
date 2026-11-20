import {spawn,spawnSync} from 'node:child_process';
import {once} from 'node:events';
import {readFile,writeFile,mkdir} from 'node:fs/promises';
import path from 'node:path';
const root=process.cwd(),php='E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe',chrome='C:/Program Files/Google/Chrome/Application/chrome.exe';
const out=path.join(root,'storage/app/private/ac05b3a-browser-'+Date.now());await mkdir(out,{recursive:true});
const infoProcess=spawnSync(php,['-d','display_errors=0','-d','zend.exception_ignore_args=1','tests/Support/ac04b2b4c-browser-info.php'],{cwd:root,encoding:'utf8',windowsHide:true,timeout:15000});
if(infoProcess.status!==0)throw Error('Read-only preflight unavailable.');const info=JSON.parse(infoProcess.stdout);
let credential=(await readFile(path.join(root,'tests/Feature/PilotDemoHttpExecutionTest.php'),'utf8')).match(/private const PASSWORD='([^']+)'/u)?.[1];
if(!credential)throw Error('Existing synthetic login fixture unavailable.');
const pause=ms=>new Promise(r=>setTimeout(r,ms));let server,browser,ws,session,next=0;const pending=new Map(),stats=[];
const call=(method,params={},sid=session)=>new Promise((resolve,reject)=>{const id=++next;pending.set(id,{resolve,reject});ws.send(JSON.stringify({id,method,params,...(sid?{sessionId:sid}:{})}));});
const evaluate=async expression=>(await call('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true})).result.value;
const wait=async expression=>{for(let i=0;i<80;i++){if(await evaluate(expression))return;await pause(100);}throw Error('UI not ready.');};
const shot=async(name,width=1366,height=900)=>{
    await call('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:false});await pause(180);
    await writeFile(path.join(out,name+'.png'),Buffer.from((await call('Page.captureScreenshot',{format:'png',captureBeyondViewport:true})).data,'base64'));
    stats.push({name,width,height,...await evaluate('({viewport:innerWidth,document:document.documentElement.scrollWidth})')});
};
let rejected=0;
try{
    try{await fetch('http://127.0.0.1:8778/__report-demo/login');throw Error('Port already occupied.');}catch(e){if(e.message==='Port already occupied.')throw e;}
    server=spawn(php,['-d','display_errors=0','-d','zend.exception_ignore_args=1','-S','127.0.0.1:8778','-t',path.join(root,'public'),path.join(root,'tests/Support/report-demo-router.php')],{cwd:root,stdio:'ignore',windowsHide:true});
    for(let i=0;i<40;i++){try{if((await fetch('http://127.0.0.1:8778/__report-demo/login')).status===200)break;}catch{}await pause(100);}
    browser=spawn(chrome,['--headless=new','--incognito','--remote-debugging-address=127.0.0.1','--remote-debugging-port=0','--user-data-dir='+path.join(out,'profile'),
        '--no-first-run','--no-default-browser-check','--disable-background-networking','--disable-component-update','--disable-sync','--disable-extensions',
        '--disable-default-apps','--disable-breakpad','--host-resolver-rules=MAP * ~NOTFOUND, EXCLUDE 127.0.0.1','about:blank'],{stdio:['ignore','ignore','pipe'],windowsHide:true});
    const endpoint=await new Promise((resolve,reject)=>{let text='';const timer=setTimeout(()=>reject(Error('CDP unavailable.')),15000);browser.stderr.on('data',c=>{text+=c.toString();const m=text.match(/DevTools listening on (ws:\/\/127\.0\.0\.1:[^\s]+)/u);if(m){clearTimeout(timer);resolve(m[1]);}});browser.once('error',reject);});
    ws=new WebSocket(endpoint);await once(ws,'open');ws.addEventListener('message',e=>{const m=JSON.parse(e.data);if(m.id){const p=pending.get(m.id);if(p){pending.delete(m.id);m.error?p.reject(Error(m.error.message)):p.resolve(m.result);}}
        if(m.method==='Fetch.requestPaused'){const req=m.params.request,u=new URL(req.url);const allowed=u.origin==='http://127.0.0.1:8778'&&u.pathname.startsWith('/__report-demo')&&
            (req.method==='GET'||['/__report-demo/login','/__report-demo/compare','/__report-demo/logout'].includes(u.pathname));if(!allowed)rejected++;
            call(allowed?'Fetch.continueRequest':'Fetch.failRequest',{requestId:m.params.requestId,...(!allowed?{errorReason:'BlockedByClient'}:{})},m.sessionId).catch(()=>{});}});
    const target=await call('Target.createTarget',{url:'about:blank'},null);session=(await call('Target.attachToTarget',{targetId:target.targetId,flatten:true},null)).sessionId;
    await call('Page.enable');await call('Runtime.enable');await call('Fetch.enable',{patterns:[{urlPattern:'*'}]});
    await call('Page.navigate',{url:'http://127.0.0.1:8778/__report-demo/login'});await wait('!!document.querySelector("#password")');await shot('01-login');
    await evaluate(`document.querySelector('#email').value=${JSON.stringify(info.email)};document.querySelector('#password').value=${JSON.stringify(credential)};document.querySelector('form[action$="/login"]').requestSubmit();true`);credential=null;
    await wait('!!document.querySelector("#source")');
    for(const [name,caseName,report] of [['02-resultados','overview','result'],['03-balance','overview','balance'],['04-cierre','closing','balance'],['05-cobertura','incomplete','result']]){
        await evaluate(`document.documentElement.dataset.waiting='1';document.querySelector('#source').value='offline';document.querySelector('#case').value=${JSON.stringify(caseName)};document.querySelector('#report').value=${JSON.stringify(report)};document.querySelector('form[action$="/compare"]').requestSubmit();true`);
        await wait('!document.documentElement.dataset.waiting&&!!document.querySelector("#comparison-result")&&document.readyState==="complete"');await shot(name);
        if(name==='03-balance'){await shot(name+'-1920',1920,1080);await shot(name+'-1280',1280,800);}
    }
    await evaluate("document.querySelector('#month').value='2030-11';document.querySelector('form[action$=\"/compare\"]').requestSubmit();true");
    await wait('!!document.querySelector("#error-summary")');await shot('06-error');
    await call('Network.clearBrowserCookies');await call('Browser.close',{},null).catch(()=>{});await Promise.race([once(browser,'exit'),pause(3000)]);
    if(browser.exitCode===null)throw Error('Browser did not stop.');server.kill();await once(server,'exit');
    const result={browser:'Chrome headless real / CDP',review:'automated, not manual',screens:stats,financial_writes:0,nonallowlisted_requests_blocked:rejected,processes_stopped:true};
    await writeFile(path.join(out,'result.json'),JSON.stringify(result,null,2));console.log(JSON.stringify({directory:out,...result}));
}finally{credential=null;if(ws?.readyState===1)ws.close();if(browser&&browser.exitCode===null)browser.kill();if(server&&server.exitCode===null)server.kill();}
