// Chrome real por CDP nativo; sin dependencias ni confirmación financiera.
import {spawn,spawnSync} from 'node:child_process';
import {readFile,mkdir,writeFile} from 'node:fs/promises';
import path from 'node:path';
import net from 'node:net';
import {once} from 'node:events';

const root=process.cwd(), php='E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe';
const out=path.join(root,'storage/app/private/ac04b2b4c-visual');
const chrome='C:/Program Files/Google/Chrome/Application/chrome.exe';
const pause=ms=>new Promise(r=>setTimeout(r,ms));
await mkdir(out,{recursive:true});
const env={};for(const k of ['SystemRoot','WINDIR','PATH','SYSTEMDRIVE'])if(process.env[k])env[k]=process.env[k];
env.TEMP=env.TMP=out;env.SALON_TEST_DB_RESET_ALLOWED='0';
const boot=spawnSync(php,['-d','display_errors=0','-d','zend.exception_ignore_args=1',path.join(root,'tests/Support/ac04b2b4c-browser-info.php')],{cwd:root,env,encoding:'utf8',timeout:15000,windowsHide:true});
if(boot.status!==0)throw Error('Preflight de navegador no disponible.');
const info=JSON.parse(boot.stdout);
const source=await readFile(path.join(root,'tests/Feature/PilotDemoHttpExecutionTest.php'),'utf8');
let credential=source.match(/private const PASSWORD='([^']+)'/u)?.[1];
if(!credential)throw Error('Fixture de acceso ausente.');
const busy=await new Promise(resolve=>{const s=net.connect({host:'127.0.0.1',port:8775});s.once('connect',()=>{s.destroy();resolve(true)});s.once('error',()=>resolve(false));});
if(busy)throw Error('Puerto ocupado; no se toca el proceso existente.');
let server,browser,ws,session,id=0;const pending=new Map(),stats=[];let blocked=0,financialBlocked=0;
const check=(condition,text)=>{if(!condition)throw Error(text);};
const call=(method,params={},sid=session)=>new Promise((resolve,reject)=>{
    const key=++id;const timer=setTimeout(()=>{pending.delete(key);reject(Error('Timeout CDP: '+method));},10000);
    pending.set(key,{resolve:value=>{clearTimeout(timer);resolve(value)},reject:error=>{clearTimeout(timer);reject(error)}});
    ws.send(JSON.stringify({id:key,method,params,...(sid?{sessionId:sid}:{})}));
});
const evaluate=async expression=>{const r=await call('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true});if(r.exceptionDetails)throw Error('Evaluación DOM no disponible.');return r.result.value;};
const wait=async expression=>{for(let i=0;i<70;i++){if(await evaluate(expression))return;await pause(100);}throw Error('Página no disponible.');};
const screenshot=async(name,width,height)=>{
    await call('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:false});await pause(150);
    const r=await call('Page.captureScreenshot',{format:'png',captureBeyondViewport:false});await writeFile(path.join(out,name+'.png'),Buffer.from(r.data,'base64'));
    const size=await evaluate('({viewport:innerWidth,document:document.documentElement.scrollWidth})');stats.push({name,width,height,...size});
};
try {
    server=spawn(php,['-d','display_errors=0','-d','zend.exception_ignore_args=1','-S','127.0.0.1:8775','-t',path.join(root,'public'),path.join(root,'tests/Support/pilot-demo-http-router.php')],{cwd:root,env,stdio:'ignore',windowsHide:true});
    for(let i=0;i<40;i++){try{if((await fetch('http://127.0.0.1:8775/__pilot-demo/login')).status===200)break;}catch{}await pause(100);}
    browser=spawn(chrome,['--headless=new','--incognito','--remote-debugging-address=127.0.0.1','--remote-debugging-port=0','--user-data-dir='+path.join(out,'profile'),'--no-first-run','--no-default-browser-check','--disable-background-networking','--disable-component-update','--disable-sync','--disable-extensions','--disable-default-apps','--disable-breakpad','--disable-features=OptimizationHints,MediaRouter,AutofillServerCommunication','--host-resolver-rules=MAP * ~NOTFOUND, EXCLUDE 127.0.0.1','about:blank'],{env,stdio:['ignore','ignore','pipe'],windowsHide:true});
    const endpoint=await new Promise((resolve,reject)=>{let text='';const timer=setTimeout(()=>reject(Error('Chrome no inició CDP.')),15000);browser.stderr.on('data',chunk=>{text+=chunk.toString();const match=text.match(/DevTools listening on (ws:\/\/127\.0\.0\.1:[^\s]+)/u);if(match){clearTimeout(timer);resolve(match[1]);}});browser.once('error',reject);});
    ws=new WebSocket(endpoint);await once(ws,'open');
    ws.addEventListener('message',event=>{const message=JSON.parse(event.data);if(message.id){const p=pending.get(message.id);if(p){pending.delete(message.id);message.error?p.reject(Error(message.error.message)):p.resolve(message.result);}}
        if(message.method==='Fetch.requestPaused'){
            const req=message.params.request;const url=new URL(req.url);const finance=req.method==='POST'&&url.pathname==='/__pilot-demo/confirm';
            const allowed=url.origin==='http://127.0.0.1:8775'&&url.pathname.startsWith('/__pilot-demo');
            if(finance)financialBlocked++;if(!allowed)blocked++;
            call(finance||!allowed?'Fetch.failRequest':'Fetch.continueRequest',{requestId:message.params.requestId,...(finance||!allowed?{errorReason:'BlockedByClient'}:{})},message.sessionId).catch(()=>{});
        }
    });
    const target=await call('Target.createTarget',{url:'about:blank'},null);session=(await call('Target.attachToTarget',{targetId:target.targetId,flatten:true},null)).sessionId;
    await call('Page.enable');await call('Runtime.enable');await call('Fetch.enable',{patterns:[{urlPattern:'*'}]});
    await call('Page.navigate',{url:'http://127.0.0.1:8775/__pilot-demo/login'});await wait('!!document.querySelector("#password")');
    await screenshot('01-login',1366,900);
    await evaluate(`document.querySelector('#email').value=${JSON.stringify(info.email)};document.querySelector('#password').value=${JSON.stringify(credential)};document.querySelector('form[action$="/login"]').requestSubmit();true`);credential=null;
    await wait('!!document.querySelector("#authorization_id")');
    for(const [w,h] of [[1280,800],[1366,900],[1920,1080]])await screenshot('02-index-'+w,w,h);
    await call('Page.navigate',{url:'http://127.0.0.1:8775/__pilot-demo/operations/'+info.uuid});await wait('document.body.innerText.includes("Asiento original #")');
    check(await evaluate('document.body.innerText.includes("Correspondencias originales")'),'Sin correspondencias');
    await screenshot('03-confirmed-1366',1366,900);await screenshot('03-confirmed-1920',1920,1080);
    await call('Page.navigate',{url:'http://127.0.0.1:8775/__pilot-demo'});await wait('!!document.querySelector("#description")');
    await evaluate(`document.querySelector('#reference').value='REFERENCIA SINTETICA LARGA '.repeat(3);document.querySelector('#description').value='Descripcion extensa de comprobacion visual sin envio financiero. '.repeat(4);document.querySelector('#reference').scrollIntoView();true`);
    await screenshot('04-long-controls',1366,900);
    const uuid=await evaluate('document.querySelector("#uuid").value');
    await evaluate(`const f=document.querySelector('form[action$="/review"]');document.querySelector('#authorization_id').value='';f.noValidate=true;f.requestSubmit();true`);
    await wait('document.readyState==="complete"&&!!document.querySelector("#error-summary")&&!!document.querySelector("#uuid")');check(await evaluate('document.querySelector("#uuid").value')===uuid,'UUID cambió tras error');
    await screenshot('05-validation',1366,900);
    check(financialBlocked===0,'Se intentó una confirmación financiera no autorizada.');
    await call('Network.clearBrowserCookies');await call('Browser.close',{},null).catch(()=>{});
    await Promise.race([once(browser,'exit'),pause(3000)]);check(browser.exitCode!==null,'Chrome no finalizó.');
    server.kill();await once(server,'exit');
    const result={browser:'Chrome headless real / CDP nativo',mode:'consulta autenticada y validacion sin habilitacion',actor_id:info.actor_id,stats,financial_requests:0,blocked_non_allowlisted_requests:blocked,processes_stopped:true};
    await writeFile(path.join(out,'result.json'),JSON.stringify(result,null,2));console.log(JSON.stringify(result));
} finally {
    credential=null;
    if(ws?.readyState===WebSocket.OPEN)await call('Browser.close',{},null).catch(()=>{});
    if(browser&&browser.exitCode===null)browser.kill();if(server&&server.exitCode===null)server.kill();
}
