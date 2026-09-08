import { chromium } from 'playwright'
const browser = await chromium.launch({ headless: true })
const context = await browser.newContext({ storageState: 'tests/e2e/.auth/planner.json' })
const page = await context.newPage()
await page.setViewportSize({ width: 320, height: 640 })
await page.goto('http://localhost:8081/apps/dutycheck/', { waitUntil: 'domcontentloaded' })
await page.locator('#dc-main-content').waitFor({ state: 'visible', timeout: 30000 })
await page.waitForTimeout(1000)
const info = await page.evaluate(() => {
  const main = document.getElementById('dc-main-content')
  const doc = document.documentElement
  const offenders = []
  const walk = (root) => {
    for (const el of root.querySelectorAll('*')) {
      const r = el.getBoundingClientRect()
      if (r.width <= 0) continue
      if (r.right > window.innerWidth + 1 || el.scrollWidth > el.clientWidth + 2) {
        offenders.push({
          tag: el.tagName, id: el.id,
          cls: String(el.className||'').slice(0,120),
          w: Math.round(r.width), right: Math.round(r.right),
          sw: el.scrollWidth, cw: el.clientWidth,
          text: (el.innerText||'').slice(0,40).replace(/\s+/g,' ')
        })
      }
    }
  }
  walk(document.body)
  return {
    win: window.innerWidth,
    docOverflow: doc.scrollWidth - doc.clientWidth,
    mainOverflow: main ? main.scrollWidth - main.clientWidth : null,
    mainSW: main?.scrollWidth, mainCW: main?.clientWidth,
    offenders: offenders.slice(0, 25),
  }
})
console.log(JSON.stringify(info, null, 2))
await page.screenshot({ path: '/home/alex/Development/nextcloud-dev/documentation/dutycheck/qa-report/screenshots/web/overflow-320-light.png', fullPage: true })
await browser.close()
