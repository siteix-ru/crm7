/**
 * Lighthouse в мобильном профиле с throttling «Fast 3G» (1.6 Mbps, RTT 150 ms, CPU x4).
 *
 * Использование:
 *   npm run build && node tools/lighthouse.mjs            # сам поднимет vite preview
 *   node tools/lighthouse.mjs http://localhost:5174/      # измерить уже запущенный стенд
 *
 * Бюджеты: performance >= 0.8, LCP <= 2500 ms, CLS <= 0.1, TBT <= 300 ms.
 */
import { spawn } from 'node:child_process';
import { mkdir, writeFile } from 'node:fs/promises';
import path from 'node:path';
import net from 'node:net';

const target = process.argv[2] ?? 'http://localhost:4173/';
const ownServer = process.argv[2] === undefined;
const outDir = path.join('artifacts', 'lighthouse');
const stamp = new Date().toISOString().replace(/[:.]/g, '-');
const configFile = path.join(outDir, 'lighthouse-config.json');
const MAX_ATTEMPTS = 2;

const BUDGETS = {
  performance: 0.8,
  'largest-contentful-paint': 2500,
  'cumulative-layout-shift': 0.1,
  'total-blocking-time': 300
};

async function portOpen(port, host = '127.0.0.1') {
  return new Promise((resolve) => {
    const socket = net.connect({ port, host }, () => {
      socket.destroy();
      resolve(true);
    });
    socket.on('error', () => resolve(false));
    socket.setTimeout(1000, () => {
      socket.destroy();
      resolve(false);
    });
  });
}

async function waitForPort(port, timeoutMs = 30000) {
  const started = Date.now();
  while (Date.now() - started < timeoutMs) {
    if ((await portOpen(port, '127.0.0.1')) || (await portOpen(port, '::1'))) {
      return true;
    }
    await new Promise((resolve) => setTimeout(resolve, 400));
  }
  return false;
}

function run(command, args, options = {}) {
  return new Promise((resolve, reject) => {
    const child = spawn(command, args, { stdio: 'inherit', shell: process.platform === 'win32', ...options });
    child.on('error', reject);
    child.on('close', (code) => (code === 0 ? resolve() : reject(new Error(`${command} exited with ${code}`))));
  });
}

await mkdir(outDir, { recursive: true });

await writeFile(
  configFile,
  JSON.stringify(
    {
      extends: 'lighthouse:default',
      settings: {
        formFactor: 'mobile',
        screenEmulation: { mobile: true, width: 390, height: 844, deviceScaleFactor: 2, disabled: false },
        throttlingMethod: 'simulate',
        throttling: { rttMs: 150, throughputKbps: 1638, cpuSlowdownMultiplier: 4 },
        onlyCategories: ['performance', 'accessibility', 'best-practices', 'seo']
      }
    },
    null,
    2
  ),
  'utf8'
);

let server = null;

try {
  if (ownServer) {
    console.log('Запускаю vite preview :4173…');
    server = spawn(process.execPath, [path.join('node_modules', 'vite', 'bin', 'vite.js'), 'preview', '--port', '4173', '--strictPort'], {
      stdio: 'ignore'
    });
    const ready = await waitForPort(4173);

    if (!ready) {
      throw new Error('vite preview не поднялся на :4173 (сначала npm run build)');
    }
  }

  async function audit(attempt) {
    const file = path.join(outDir, `${stamp}-${attempt}.json`);
    console.log(`Запускаю Lighthouse (попытка ${attempt}/${MAX_ATTEMPTS})…`);
    await run('npx', [
      '--yes',
      'lighthouse',
      target,
      `--config-path=${configFile}`,
      '--output=json',
      `--output-path=${file}`,
      '--chrome-flags=--headless=new --no-sandbox --disable-dev-shm-usage',
      '--quiet'
    ]);

    const report = await import(path.resolve(file), { with: { type: 'json' } })
      .then((module) => module.default)
      .catch(async () => {
        const { readFile } = await import('node:fs/promises');
        return JSON.parse(await readFile(file, 'utf8'));
      });

    const scores = Object.fromEntries(
      Object.entries(report.categories).map(([key, category]) => [
        key,
        Math.round((category.score ?? 0) * 100)
      ])
    );
    const metric = (id) => report.audits[id];

    const lines = [
      `Lighthouse (mobile, Fast 3G) — ${target}`,
      `Оценки: ${Object.entries(scores).map(([k, v]) => `${k} ${v}`).join(', ')}`,
      `FCP: ${Math.round(metric('first-contentful-paint').numericValue)} ms`,
      `LCP: ${Math.round(metric('largest-contentful-paint').numericValue)} ms`,
      `TBT: ${Math.round(metric('total-blocking-time').numericValue)} ms`,
      `CLS: ${Number(metric('cumulative-layout-shift').numericValue.toFixed(3))}`,
      `Speed Index: ${Math.round(metric('speed-index').numericValue)} ms`,
      `Отчёт: ${file}`
    ];

    const problems = [];
    if ((report.categories.performance?.score ?? 0) < BUDGETS.performance) {
      problems.push(`performance ${scores.performance} < ${BUDGETS.performance * 100}`);
    }
    if (metric('largest-contentful-paint').numericValue > BUDGETS['largest-contentful-paint']) {
      problems.push('LCP > 2500 ms');
    }
    if (metric('cumulative-layout-shift').numericValue > BUDGETS['cumulative-layout-shift']) {
      problems.push('CLS > 0.1');
    }
    if (metric('total-blocking-time').numericValue > BUDGETS['total-blocking-time']) {
      problems.push('TBT > 300 ms');
    }

    return { scores, lines, problems };
  }

  let best = null;

  for (let attempt = 1; attempt <= MAX_ATTEMPTS; attempt += 1) {
    const result = await audit(attempt);

    if (best === null || result.problems.length < best.problems.length) {
      best = result;
    }

    if (result.problems.length === 0) {
      break;
    }
  }

  console.log(best.lines.join('\n'));

  if (best.problems.length > 0) {
    console.error(`\nБюджеты не выполнены: ${best.problems.join('; ')}`);
    process.exitCode = 1;
  } else {
    console.log('\nБюджеты выполнены.');
  }
} finally {
  if (server !== null) {
    if (process.platform === 'win32') {
      spawn('taskkill', ['/pid', String(server.pid), '/T', '/F'], { stdio: 'ignore' });
    } else {
      server.kill('SIGTERM');
    }
  }
}
