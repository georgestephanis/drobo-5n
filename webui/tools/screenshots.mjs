// Take the README screenshots of a running drobo-webui with headless Chrome (DevTools protocol,
// Node's built-in WebSocket; no dependencies).
//
//   DROBO_URL=https://drobo5n.local DROBO_PASSWORD=... node tools/screenshots.mjs
//
// IPv6 addresses are masked before each capture: SLAAC addresses contain the board's MAC.
import { spawn } from 'node:child_process';
import { mkdtempSync, writeFileSync, mkdirSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const URL = process.env.DROBO_URL || 'http://localhost:8080';
const PASSWORD = process.env.DROBO_PASSWORD;
const CHROME = process.env.CHROME || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const OUT = join(dirname(fileURLToPath(import.meta.url)), '..', 'screenshots');
if (!PASSWORD) throw new Error('set DROBO_PASSWORD');

const SHOTS = [
  { file: 'login.png', page: '', width: 1100, height: 640, loggedOut: true },
  { file: 'status.png', page: 'status', width: 1280, height: 980 },
  { file: 'status-bay.png', page: 'status', width: 1280, height: 980, click: '.fp-bay:nth-of-type(1)' },
  { file: 'status-dark.png', page: 'status', width: 1280, height: 980, dark: true },
  { file: 'disks.png', page: 'disks', width: 1280, height: 760 },
  { file: 'system.png', page: 'system', width: 1280, height: 760 },
  { file: 'settings.png', page: 'settings', width: 1280, height: 2300 },
  { file: 'logs.png', page: 'logs', width: 1280, height: 760 },
  { file: 'status-mobile.png', page: 'status', width: 390, height: 1500, mobile: true },
];

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const profile = mkdtempSync(join(tmpdir(), 'drobo-shots-'));
const chrome = spawn(CHROME, ['--headless=new', '--remote-debugging-port=9333', `--user-data-dir=${profile}`,
  '--hide-scrollbars', '--ignore-certificate-errors', '--no-first-run', '--no-default-browser-check', 'about:blank'], { stdio: 'ignore' });

let ws;
let seq = 0;
const pending = new Map();
function send(method, params = {}) {
  const id = ++seq;
  ws.send(JSON.stringify({ id, method, params }));
  return new Promise((resolve, reject) => pending.set(id, { resolve, reject }));
}
async function evaluate(expression) {
  const r = await send('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true });
  if (r.exceptionDetails) throw new Error(r.exceptionDetails.text + ' ' + (r.exceptionDetails.exception?.description || ''));
  return r.result.value;
}

const MASK = `(() => {
  const re = /\\b[0-9a-f]{1,4}(:[0-9a-f]{0,4}){2,7}\\/\\d+/gi;  // with a prefix length, so clock times are left alone
  const w = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
  let n, i = 0;
  while ((n = w.nextNode())) if (re.test(n.nodeValue)) { re.lastIndex = 0; n.nodeValue = n.nodeValue.replace(re, () => 'fd00:db8::' + (++i) + '/64'); }
  return i;
})()`;

try {
  let target;
  for (let i = 0; i < 50 && !target; i++) {
    try {
      const list = await (await fetch('http://127.0.0.1:9333/json/list')).json();
      target = list.find((t) => t.type === 'page');
    } catch { await sleep(200); }
  }
  ws = new WebSocket(target.webSocketDebuggerUrl);
  await new Promise((r) => ws.addEventListener('open', r, { once: true }));
  ws.addEventListener('message', (e) => {
    const m = JSON.parse(e.data);
    if (m.id && pending.has(m.id)) {
      const p = pending.get(m.id);
      pending.delete(m.id);
      m.error ? p.reject(new Error(m.error.message)) : p.resolve(m.result);
    }
  });
  await send('Page.enable');
  await send('Runtime.enable');
  mkdirSync(OUT, { recursive: true });

  const login = async () => {
    await evaluate(`(async () => {
      const s = await (await fetch('api.php?r=session')).json();
      const r = await fetch('api.php?r=login', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': s.csrf },
        body: JSON.stringify({ password: ${JSON.stringify(PASSWORD)} }) });
      return r.status;
    })()`);
  };
  const logout = () => evaluate(`(async () => {
    const s = await (await fetch('api.php?r=session')).json();
    await fetch('api.php?r=logout', { method: 'POST', headers: { 'X-CSRF-Token': s.csrf } });
  })()`);

  await send('Page.navigate', { url: URL + '/' });
  await sleep(1500);

  for (const s of SHOTS) {
    await send('Emulation.setDeviceMetricsOverride', { width: s.width, height: s.height, deviceScaleFactor: 2, mobile: !!s.mobile });
    await send('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-color-scheme', value: s.dark ? 'dark' : 'light' }] });
    if (s.loggedOut) await logout(); else await login();
    await send('Page.navigate', { url: `${URL}/#/${s.page}` });
    await evaluate('location.reload(), 1');
    await sleep(s.page === 'logs' ? 5000 : 4500);
    for (let i = 0; i < 30 && await evaluate(`/Loading|Reading the Drobo/.test(document.body.innerText)`); i++) await sleep(500);
    if (s.click) await evaluate(`document.querySelector(${JSON.stringify(s.click)}).dispatchEvent(new MouseEvent('click', { bubbles: true })), 1`);
    await sleep(500);
    await evaluate(MASK);
    const shot = await send('Page.captureScreenshot', { format: 'png' });
    writeFileSync(join(OUT, s.file), Buffer.from(shot.data, 'base64'));
    console.log('wrote', s.file);
  }
} finally {
  ws?.close();
  chrome.kill();
  await sleep(500);
  rmSync(profile, { recursive: true, force: true });
}
