import { chromium } from 'playwright-core';
import { readFile } from 'node:fs/promises';
const pw = JSON.parse((await readFile(process.env.TEMP.replace(/\\/g, '/') + '/opencode/seed_passwords.json', 'utf8')).replace(/^\uFEFF/, ''));
const auth = await (await fetch('http://127.0.0.1:8080/api/v2/auth/login', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ login: 'admin', password: pw.admin }) })).json();
const b = await chromium.launch({ channel: 'msedge' });
const ctx = await b.newContext({ viewport: { width: 1440, height: 950 } });
await ctx.addInitScript(([t, u]) => { localStorage.setItem('crm_token', t); localStorage.setItem('crm_user', u); }, [auth.data.token, JSON.stringify(auth.data.user)]);
const p = await ctx.newPage();
for (const [name, route] of [['requests', 'requests'], ['clients', 'clients'], ['staff', 'staff']]) {
  await p.goto('http://localhost:4173/#/' + route, { waitUntil: 'domcontentloaded' });
  await p.waitForTimeout(1800);
  if (name === 'staff') { await p.getByRole('button', { name: /Замещения/ }).first().click(); await p.waitForTimeout(800); }
  await p.getByLabel(/Вид и фильтры/).first().click();
  await p.waitForTimeout(700);
  const labels = await p.locator('.filters-form .filter-label, .filters-form .filter-field > span').allInnerTexts();
  console.log(name, '->', labels.map((t) => t.trim()).filter(Boolean).join(' | '));
  await p.screenshot({ path: 'artifacts/filters-' + name + '.png' });
  await p.keyboard.press('Escape');
  await p.waitForTimeout(400);
}
await b.close();
