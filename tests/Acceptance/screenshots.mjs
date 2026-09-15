/*
 * The README's screenshots (change harden-quality-and-docs, design decision 4).
 * Captured from a seeded stack with the same headless Chromium the other
 * acceptance scripts drive, so a page that changes is one command away from a
 * current picture rather than a redraw.
 *
 *   docker compose exec php bin/console app:demo:seed --clicks=50000 --reset
 *   npm install --no-save --prefix /tmp/lb-acceptance puppeteer-core
 *   NODE_PATH=/tmp/lb-acceptance/node_modules node tests/Acceptance/screenshots.mjs \
 *     --base=http://localhost:8082 --email=demo@example.com --password='<printed by the seed>'
 *
 * The password is never stored here: the seed prints it once and it is passed
 * on the command line.
 */

import { mkdirSync } from 'node:fs';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const puppeteer = require('puppeteer-core');

const options = Object.fromEntries(
    process.argv.slice(2).map((arg) => {
        const [key, value = 'true'] = arg.replace(/^--/, '').split('=');
        return [key, value];
    }),
);
const base = options.base ?? 'http://localhost:8082';
const email = options.email ?? 'demo@example.com';
const password = options.password;
const out = options.out ?? 'docs/images';
if (!password) {
    throw new Error('--password is required: app:demo:seed prints it once');
}

mkdirSync(out, { recursive: true });

const browser = await puppeteer.launch({
    executablePath: options.chromium ?? '/usr/bin/chromium-browser',
    headless: 'new',
    args: ['--no-sandbox', '--disable-gpu'],
});
const page = await browser.newPage();
await page.setViewport({ width: 1280, height: 900, deviceScaleFactor: 2 });

const settle = () => new Promise((resolve) => setTimeout(resolve, 900));

await page.goto(`${base}/login`, { waitUntil: 'load' });
await page.type('input[name=email]', email);
await page.type('input[name=password]', password);
await page.$eval('input[name=email]', (field) => field.form.querySelector('button[type=submit]').click());
await page.waitForFunction(() => window.location.pathname === '/');

const shots = [];

await page.goto(`${base}/dashboard`, { waitUntil: 'load' });
await settle(); // the chart is drawn by Chart.js after the import map runs
shots.push(await capture('dashboard.png'));

await page.goto(`${base}/links`, { waitUntil: 'load' });
const link = await page.$eval('tbody tr a', (anchor) => anchor.getAttribute('href'));
await page.goto(`${base}${link}/stats`, { waitUntil: 'load' });
await settle();
shots.push(await capture('link-stats.png'));

await page.goto(`${base}/api/docs`, { waitUntil: 'load' });
await page.waitForSelector('.swagger-ui .opblock', { timeout: 20000 });
await settle();
shots.push(await capture('api-docs.png'));

await browser.close();
console.log(JSON.stringify(shots, null, 2));

async function capture(name) {
    const path = `${out}/${name}`;
    await page.screenshot({ path, fullPage: false });
    return { path, url: page.url() };
}
