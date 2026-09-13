/*
 * Browser acceptance for the one claim a WebTestCase cannot reach: after a new
 * API key is shown, going back to that page must not put the plaintext on the
 * screen again (spec web-ui, design decision 9a of add-web-ui).
 *
 * Two restorations, because two different mechanisms govern them:
 *   turbo  — a Turbo Drive visit away and back, where Turbo's snapshot and its
 *            turbo-cache-control meta tag decide what is repainted;
 *   full   — a full document load away and Back, where the browser may restore
 *            the document it kept (the back-forward cache) and only the
 *            pageshow handler can clear the value. The run reports whether a
 *            persisted restoration actually happened: a refetch has not
 *            exercised that guard and is reported as `exercised: false`, never
 *            as a pass.
 *
 * Not part of `make check`: CI has no browser. Run it against the dev stack:
 *
 *   npm install --no-save --prefix /tmp/lb-acceptance puppeteer-core
 *   NODE_PATH=/tmp/lb-acceptance/node_modules \
 *     node tests/Acceptance/api-key-back-navigation.mjs --base=http://localhost:8082
 *
 * Options: --base=<url>, --chromium=<path>, --keep (do not exit non-zero on a
 * leak, for the negative runs that are supposed to leak).
 */

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
const executablePath = options.chromium ?? '/usr/bin/chromium-browser';

// One account for the whole run, registered once: /login and /register are
// rate limited per client IP (10 a minute, FR-AUTH-4), and a script that
// registered per case would trip that limit and look like a failure of the
// thing under test.
const email = `acceptance-${Date.now()}@example.com`;
const password = 'correct-horse-battery-staple';

// a warm-up request: the first one after a cache clear compiles the container,
// and that time should not count against a wait for the DOM
await fetch(`${base}/login`).catch(() => {});

const browser = await puppeteer.launch({
    executablePath,
    headless: 'new',
    args: ['--no-sandbox', '--disable-gpu'],
});

/**
 * Registers, signs in and creates a key; returns the page and the plaintext.
 * Each case gets its own browser context — its own cookie jar and its own
 * fresh key — so one case cannot inherit the other's session or secret.
 */
async function newKeyPage() {
    const context = await browser.createBrowserContext();
    const page = await context.newPage();
    await page.evaluateOnNewDocument(() => {
        window.addEventListener('pageshow', (event) => {
            window.__lbPersisted = event.persisted;
        });
    });

    // Turbo intercepts form submissions, so nothing waits on a navigation event
    // here: what a person sees is the DOM changing, and that is what is awaited.
    await page.goto(`${base}/login`, { waitUntil: 'load' });
    await page.type('input[name=email]', email);
    await page.type('input[name=password]', password);
    await page.$eval('input[name=email]', (field) => field.form.querySelector('button[type=submit]').click());
    await page.waitForFunction(() => window.location.pathname === '/', { timeout: 15000 }).catch(async () => {
        const alerts = await page.$$eval('[role=alert]', (nodes) => nodes.map((node) => node.textContent.trim()));
        throw new Error(`the sign-in did not land on the dashboard — at ${page.url()}, alerts: ${JSON.stringify(alerts)}`);
    });

    await page.goto(`${base}/api-keys`, { waitUntil: 'load' });
    await page.type('#api_key_name', `acceptance ${Date.now()}`);
    // the create form's own button: the header carries a logout form whose
    // button would otherwise match first
    await page.$eval('#api_key_name', (field) => field.form.querySelector('button[type=submit]').click());
    const secret = await page
        .waitForSelector('[data-secret-target=value]', { timeout: 20000 })
        .then((node) => node.evaluate((element) => element.textContent.trim()))
        .catch(() => null);
    if (!secret) {
        // say what the page actually shows; a silent timeout teaches nothing
        const alerts = await page.$$eval('[role=alert]', (nodes) => nodes.map((node) => node.textContent.trim()));
        throw new Error(`no secret on ${page.url()} — alerts: ${JSON.stringify(alerts)}`);
    }

    return { page, secret, context };
}

async function shows(page, secret) {
    const html = await page.content();
    return html.includes(secret);
}

// the account both cases sign in with
{
    const page = await browser.newPage();
    await page.goto(`${base}/login`, { waitUntil: 'load' });
    const status = await page.evaluate(
        async (url, body) => {
            const response = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
            return response.status;
        },
        `${base}/api/v1/auth/register`,
        { email, password },
    );
    if (status !== 201) {
        throw new Error(`registration answered ${status}; 429 means the per-IP auth limit — wait a minute and run again`);
    }
    await page.close();
}

const results = {};

const settle = () => new Promise((resolve) => setTimeout(resolve, 400));

// 1. a Turbo visit away and back — no full navigation happens, so the waits are
// on the location Turbo pushes
{
    const { page, secret, context } = await newKeyPage();
    await page.click('header a[href$="/links"]');
    await page.waitForFunction(() => window.location.pathname === '/links');
    await page.evaluate(() => window.history.back());
    await page.waitForFunction(() => window.location.pathname === '/api-keys');
    await settle();
    results.turbo = { leaked: await shows(page, secret), exercised: true };
    await context.close();
}

// 2. a full document load away and Back: the browser may restore the document
// it kept, which is the only case the pageshow guard covers
{
    const { page, secret, context } = await newKeyPage();
    await page.goto(`${base}/links`, { waitUntil: 'load' });
    await page.goBack({ waitUntil: 'load' });
    await settle();
    const persisted = await page.evaluate(() => window.__lbPersisted === true);
    results.full = { leaked: await shows(page, secret), exercised: persisted };
    await context.close();
}

await browser.close();

console.log(JSON.stringify(results));
const leaked = Object.values(results).some((result) => result.leaked);
process.exit(options.keep === 'true' ? 0 : leaked ? 1 : 0);
