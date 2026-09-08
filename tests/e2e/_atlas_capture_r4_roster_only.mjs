import { chromium } from 'playwright'
import { copyFileSync, mkdirSync, readFileSync, writeFileSync, existsSync } from 'node:fs'
import { createHash } from 'node:crypto'
import { join, dirname } from 'node:path'
import { fileURLToPath } from 'node:url'
import { setUserTheme } from './helpers/theming.js'

const outAtlas = '/home/alex/Development/nextcloud-dev/nextcloud/apps/dutycheck/docs/atlas/screenshots/web'
const outQa = '/home/alex/Development/nextcloud-dev/documentation/dutycheck/qa-report/screenshots/web'
mkdirSync(outAtlas,{recursive:true}); mkdirSync(outQa,{recursive:true})
const authPath = '/home/alex/Development/nextcloud-dev/nextcloud/apps/dutycheck/tests/e2e/.auth/planner.json'
const md5 = p => createHash('md5').update(readFileSync(p)).digest('hex')
const sha = p => createHash('sha256').update(readFileSync(p)).digest('hex').slice(0,16)

const PEOPLE = [
  {id:1,name:'Anna Weber'},{id:2,name:'Ben Richter'},{id:3,name:'Clara Hofmann'},
  {id:4,name:'David Keller'},{id:5,name:'Elena Braun'},{id:6,name:'Felix Neumann'},
]
const assignments=[]
let n=1
const patterns=[['06:00','14:00'],['08:00','16:00'],['12:00','20:00'],['14:00','22:00']]
for (let pi=0;pi<PEOPLE.length;pi++){
  for (let day=1;day<=30;day++){
    if ((day+pi)%3!==0) continue
    const [s,e]=patterns[(pi+day)%patterns.length]
    assignments.push({id:n++,periodId:90,employeeId:PEOPLE[pi].id,employeeName:PEOPLE[pi].name,locationId:9,locationName:'Zentrale',dutyDate:`2026-11-${String(day).padStart(2,'0')}`,startTime:s,endTime:e,breakMinutes:30,templateName:'Tag',note:''})
  }
}
const periods=[{id:90,startDate:'2026-11-01',endDate:'2026-11-30',status:'open',createdBy:'dc_atlas_planner',createdAt:'2026-09-07 12:00:00',publishedAt:null,closedAt:null},{id:89,startDate:'2026-10-01',endDate:'2026-10-31',status:'published',createdBy:'dc_atlas_planner',createdAt:'2026-09-01 12:00:00',publishedAt:'2026-09-02 12:00:00',closedAt:null}]
const roster={ok:true,data:{periods,selectedPeriodId:90,selectedPeriodStatus:'open',canCreateAssignments:true,calendarYearMonth:'2026-11',employees:PEOPLE.map(p=>({id:p.id,displayName:p.name,name:p.name,active:true})),locations:[{id:9,name:'Zentrale',active:true}],assignments,conflicts:[],absenceBlocks:[],defaultBreakMinutes:30}}

async function forceDark(page){
  await page.evaluate(()=>{
    document.documentElement.classList.add('theme--dark'); document.body?.classList.add('theme--dark')
    document.documentElement.setAttribute('data-theme-global','dark'); document.body?.setAttribute('data-theme-global','dark')
    let s=document.getElementById('dc-atlas-r4-dark-force'); if(!s){s=document.createElement('style');s.id='dc-atlas-r4-dark-force';document.head.appendChild(s)}
    s.textContent=`html,body,#content,#app-content,.dc-app{color-scheme:dark!important;--color-main-background:#171717!important;--color-main-text:#ededed!important;background:#171717!important;color:#ededed!important}
    .dc-roster-grid,.dc-roster-grid-scroller,.dc-roster-grid-wrap,.dc-roster-grid__row,.dc-roster-grid__cell,.dc-roster-grid__colhead,.dc-card,.dc-panel{background:#1e1e1e!important;color:#ededed!important;border-color:#3a3a3a!important}
    #dc-roster-grid{--dc-roster-day-min:1.85rem!important}
    .dc-roster-grid__colhead,.dc-roster-grid__cell{min-width:1.85rem!important;max-width:2.4rem!important;padding-inline:0.15rem!important;font-size:0.72rem!important}
    .dc-roster-grid__shift{font-size:0.65rem!important;padding:0.1rem 0.15rem!important}`
  })
}

const browser=await chromium.launch({headless:true,args:['--lang=de-DE','--force-dark-mode']})
const context=await browser.newContext({storageState:authPath,viewport:{width:1440,height:1100},locale:'de-DE',timezoneId:'Europe/Berlin',colorScheme:'dark'})
const page=await context.newPage()
await page.route('**/apps/dutycheck/api/swaps**', r=>r.fulfill({status:200,contentType:'application/json',body:JSON.stringify({ok:true,data:{swaps:[],items:[]}})}))
await page.route('**/apps/dutycheck/api/**/claim**', r=>r.fulfill({status:200,contentType:'application/json',body:JSON.stringify({ok:true,data:{claims:[],items:[]}})}))
await page.route('**/apps/dutycheck/api/roster/signals**', r=>r.fulfill({status:200,contentType:'application/json',body:JSON.stringify({ok:true,data:{preferences:[],blackouts:[]}})}))
await page.route('**/apps/dutycheck/api/roster**', async route=>{
  if(route.request().url().includes('/signals')) return route.fulfill({status:200,contentType:'application/json',body:JSON.stringify({ok:true,data:{preferences:[],blackouts:[]}})})
  if(route.request().method()!=='GET') return route.fallback()
  await route.fulfill({status:200,contentType:'application/json',body:JSON.stringify(roster)})
})
await page.route('**/apps/dutycheck/api/periods**', async route=>{
  const url=route.request().url(); const m=route.request().method()
  if(m==='POST'&&url.includes('ensure-calendar-month')) return route.fulfill({status:200,contentType:'application/json',body:JSON.stringify({ok:true,data:{period:periods[0],created:false}})})
  if(url.includes('publish-readiness')) return route.fulfill({status:200,contentType:'application/json',body:JSON.stringify({ok:true,data:{ready:true,blockers:[]}})})
  if(m!=='GET') return route.fallback()
  await route.fulfill({status:200,contentType:'application/json',body:JSON.stringify({ok:true,data:{periods}})})
})
await page.route('**/apps/dutycheck/api/**/snapshots**', r=>r.fulfill({status:200,contentType:'application/json',body:JSON.stringify({ok:true,data:{snapshots:[]}})}))

await page.goto('http://localhost:8081/apps/dutycheck/roster?periodId=90',{waitUntil:'domcontentloaded',timeout:90000})
try{await setUserTheme(page,'dark')}catch(e){console.warn('theme',e.message)}
await forceDark(page)
await page.waitForSelector('#dc-roster-grid',{timeout:45000})
await page.waitForFunction(()=>document.querySelectorAll('#dc-roster-grid .dc-roster-grid__colhead').length>=28,{timeout:45000})
// empty swaps UI
await page.evaluate(()=>{
  const list=document.getElementById('dc-swap-list'); if(list) list.replaceChildren()
  const empty=document.getElementById('dc-swap-empty'); if(empty){empty.hidden=false; empty.textContent='Keine ausstehenden Tauschanfragen.'}
  document.querySelectorAll('body *').forEach(el=>{
    if(el.children && el.children.length>6) return
    const tx=el.textContent||''
    if(/Play Review|atlas-os-|dc\.review/i.test(tx)){el.setAttribute('hidden',''); if(el.style) el.style.display='none'}
  })
  document.querySelectorAll('.toastify,.toast,[role="alert"]').forEach(el=>el.remove())
  const label=document.getElementById('dc-roster-month-current'); if(label) label.textContent='November 2026'
})
await forceDark(page)
await page.evaluate(()=>{
  document.querySelectorAll('[id*="quickstart"], .dc-empty--quickstart').forEach(el=>el.setAttribute('hidden',''))
  document.querySelectorAll('button').forEach(b=>{ if(/hide tips|tipps aus|nicht mehr|schließen/i.test(b.textContent||'')) b.click() })
})
await page.evaluate(()=>{
  const el=document.querySelector('#dc-roster-grid-wrap, #dc-roster-grid')
  const pane=document.querySelector('#app-content')||document.scrollingElement
  if(el&&pane){ const top=el.getBoundingClientRect().top + pane.scrollTop - 64; pane.scrollTop=Math.max(0,top) }
})
await page.waitForTimeout(500)
const heads=await page.locator('#dc-roster-grid .dc-roster-grid__colhead').count()
console.log('heads',heads)
const rosterPath=join(outAtlas,'atlas-visual-r4-web-roster.png')
const rosterQa=join(outQa,'atlas-visual-r4-web-roster.png')
await page.screenshot({path:rosterQa,fullPage:false}); copyFileSync(rosterQa,rosterPath)
console.log('roster',sha(rosterPath),md5(rosterPath))

await page.setViewportSize({width:2200,height:1100})
await forceDark(page)
await page.evaluate(()=>{
  const scroller=document.querySelector('.dc-roster-grid-scroller'); if(scroller) scroller.scrollLeft=0
  const grid=document.getElementById('dc-roster-grid'); if(grid) grid.style.setProperty('--dc-roster-day-min','1.7rem')
})
await page.waitForTimeout(300)
const grid=page.locator('#dc-roster-grid-wrap, .dc-roster-grid-scroller').first()
const monthQa=join(outQa,'atlas-visual-r4-web-roster-month-grid.png')
const monthPath=join(outAtlas,'atlas-visual-r4-web-roster-month-grid.png')
await grid.screenshot({path:monthQa}); copyFileSync(monthQa,monthPath)
console.log('month',sha(monthPath),md5(monthPath), 'size note')

// periods clean
await page.setViewportSize({width:1440,height:1100})
await page.goto('http://localhost:8081/apps/dutycheck/periods',{waitUntil:'domcontentloaded',timeout:90000})
await forceDark(page)
await page.waitForTimeout(800)
await page.evaluate(()=>{
  document.querySelectorAll('.toastify,.toast,[role="alert"]').forEach(el=>el.remove())
  const start=document.getElementById('dc-period-start'); const end=document.getElementById('dc-period-end')
  if(start){start.value='2026-12-01'; start.placeholder=''}
  if(end){end.value='2026-12-31'; end.placeholder=''}
  document.querySelectorAll('.dc-date-locale-hint').forEach(h=>{h.hidden=true;h.textContent=''})
})
const perQa=join(outQa,'atlas-visual-r4-web-periods.png'); const perPath=join(outAtlas,'atlas-visual-r4-web-periods.png')
await page.screenshot({path:perQa,fullPage:false}); copyFileSync(perQa,perPath)
console.log('periods',sha(perPath),md5(perPath))
await browser.close()
console.log('ROSTER_ONLY_OK')
