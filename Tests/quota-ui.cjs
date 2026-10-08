'use strict';
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
async function main(){
    const handlers={}, status={textContent:''}, refresh={dataset:{url:'/s/mail-connections?usage=1',pending:'Pending',done:'Updated',error:'Unavailable'},disabled:false,addEventListener:(name,fn)=>{handlers[name]=fn;}};
    const history={replaceChildren(){},appendChild(){}};
    const periods=Object.fromEntries(['hourly','daily','monthly'].map(period=>[period,{dataset:{quotaPeriod:period},count:{textContent:''},progress:{style:{}},querySelector(selector){return selector==='[data-quota-count]'?this.count:this.progress;}}]));
    const fields={status:{dataset:{labelReady:'Ready',labelLimited:'Limited'}},accepted:{dataset:{label:'Accepted'}},retry:{dataset:{label:'Retry'}}};
    const row={dataset:{mailConnection:'a'.repeat(32),hourlyHistory:'[]'},querySelectorAll(selector){return selector==='[data-quota-period]'?Object.values(periods):[];},querySelector(selector){return fields[selector.slice(12,-1)];}};
    let payload={checked_at:'2026-10-08T12:00:00Z',connections:[{id:'a'.repeat(32),hourly:{status:'limited',used:2,limit:0,accepted:1,retry_at:'2026-10-09T00:00:00Z',history:[],periods:{hourly:{used:2,limit:0,status:'ready'},daily:{used:100,limit:100,status:'limited'},monthly:{used:10,limit:3000,status:'ready'}}}}]};
    let calls=0;
    const document={readyState:'complete',querySelectorAll:()=>[row],querySelector:()=>null,createElement:()=>({appendChild(){}}),getElementById(id){return {'mail-usage-refresh':refresh,'mail-usage-status':status,'mail-history-connection':{value:'none',addEventListener(){}},'mail-history-body':history,'mail-hourly-history':{dataset:{unlimited:'Unlimited'}}}[id]||null;}};
    const context={document,window:{setTimeout,clearTimeout},AbortController,Date,fetch:async()=>{++calls;return{ok:true,json:async()=>payload};}};
    vm.runInNewContext(fs.readFileSync(__dirname+'/../Assets/js/connections.js','utf8'),context);
    await handlers.click();
    assert.equal(periods.hourly.count.textContent,'2 / Unlimited');assert.equal(periods.daily.count.textContent,'100 / 100');assert.equal(periods.monthly.count.textContent,'10 / 3000');
    assert.equal(periods.daily.progress.style.width,'100%');assert.match(periods.daily.progress.className,/warning/);assert.equal(fields.status.textContent,'Limited');assert.equal(fields.retry.hidden,false);assert.equal(refresh.disabled,false);
    delete payload.connections[0].hourly.periods.monthly;
    await handlers.click();assert.equal(status.textContent,'Unavailable');assert.equal(refresh.disabled,false);assert.equal(calls,2);
    console.log('PASS: quota refresh updates all periods, unlimited labels, capped badge and retry, rejects incomplete data and releases controls; no browser/network/database');
}
main().catch(e=>{console.error(e.message);process.exitCode=1;});
