/*
 * Browser acceptance of the routing-rules editor (change add-web-ui, Gate 2
 * round 1, findings 1 and 2). A WebTestCase can post any field it likes; only a
 * browser can say whether the controls a person actually operates produce the
 * document that gets stored.
 *
 * Three things are checked, each end to end:
 *   rows   — add two rules, remove the first, add a third, save: exactly the
 *            rules still on the screen are the rules stored, with no index
 *            collision between the survivors and the new row;
 *   json   — switch to the JSON view, type a document, save: that document is
 *            stored (with the fields view chosen it must not be);
 *   reopen — a stored document the rows cannot hold comes back as JSON.
 *
 * Run it against the dev stack, like the other acceptance script:
 *
 *   npm install --no-save --prefix /tmp/lb-acceptance puppeteer-core
 *   NODE_PATH=/tmp/lb-acceptance/node_modules \
 *     node tests/Acceptance/rules-editor.mjs --base=http://localhost:8082
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
const email = `rules-${Date.now()}@example.com`;
const password = 'correct-horse-battery-staple';

const browser = await puppeteer.launch({ executablePath, headless: 'new', args: ['--no-sandbox', '--disable-gpu'] });
const page = await browser.newPage();
const settle = () => new Promise((resolve) => setTimeout(resolve, 300));

await page.goto(`${base}/login`, { waitUntil: 'load' });
const registered = await page.evaluate(
    async (url, body) => (await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })).status,
    `${base}/api/v1/auth/register`,
    { email, password },
);
if (registered !== 201) {
    throw new Error(`registration answered ${registered}; 429 means the per-IP auth limit — wait a minute`);
}
await page.type('input[name=email]', email);
await page.type('input[name=password]', password);
await page.$eval('input[name=email]', (field) => field.form.querySelector('button[type=submit]').click());
await page.waitForFunction(() => window.location.pathname === '/');

// a link to edit
await page.goto(`${base}/links/new`, { waitUntil: 'load' });
await page.type('#link_targetUrl', 'https://example.com/acceptance');
await page.$eval('#link_targetUrl', (field) => field.form.querySelector('button[type=submit]').click());
await page.waitForFunction(() => /^\/links\/[0-9a-f-]{36}$/.test(window.location.pathname));
const linkPath = new URL(page.url()).pathname;

const results = {};

/** The rules the link page shows as stored. */
async function stored() {
    await page.goto(`${base}${linkPath}`, { waitUntil: 'load' });
    const text = await page.$eval('pre code', (node) => node.textContent).catch(() => null);
    return text ? JSON.parse(text) : null;
}

async function openEditor() {
    await page.goto(`${base}${linkPath}/edit`, { waitUntil: 'load' });
    await page.waitForFunction(() => typeof window.Turbo === 'object');
    await page.$eval('details', (element) => { element.open = true; });
    await settle();
}

async function fillRow(index, key, values, target) {
    // assignment rather than typing: the fields sit inside a <details>, and a
    // headless keyboard does not always reach an element it cannot focus
    await page.select(`#link_rules_rows_${index}_matchKey`, key);
    await page.$eval(`#link_rules_rows_${index}_values`, (field, value) => { field.value = value; }, values);
    await page.$eval(`#link_rules_rows_${index}_target`, (field, value) => { field.value = value; }, target);
}

async function save() {
    await page.$eval('#link_targetUrl', (field) => field.form.querySelector('button[type=submit]').click());
    await page.waitForFunction((path) => window.location.pathname === path, { timeout: 15000 }, linkPath).catch(async () => {
        const alerts = await page.$$eval('[role=alert]', (nodes) => nodes.map((node) => node.textContent.trim()));
        throw new Error(`the save did not land on the link page — at ${page.url()}, alerts: ${JSON.stringify(alerts)}`);
    });
    await settle();
}

// 1. rows: add two, remove the first, add a third
{
    await openEditor();
    // DOM clicks, not pointer clicks: the controls live inside a <details> and a
    // headless pointer click can land on the wrong element while it opens
    const add = () => page.$eval('button[data-action="rules-editor#addRow"]', (button) => button.click());
    await add();
    await add();
    await settle();
    const indices = await page.$$eval('[data-rules-editor-target=row] select', (nodes) => nodes.map((node) => node.id.replace(/\D+/g, '')));
    await fillRow(indices[0], 'device', 'smartphone', 'https://example.com/first');
    await fillRow(indices[1], 'country', 'DE, AT', 'https://example.com/second');

    // drop the first, then add another: the new row must not reuse an index
    await page.$eval('[data-rules-editor-target=row] button', (button) => button.click());
    await settle();
    await add();
    await settle();
    const left = await page.$$eval('[data-rules-editor-target=row] select', (nodes) => nodes.map((node) => node.id.replace(/\D+/g, '')));
    await fillRow(left[left.length - 1], 'os', 'iOS', 'https://example.com/third');
    const shown = await page.$$eval('[data-rules-editor-target=row]', (rows) =>
        rows.map((row) => ({
            match: row.querySelector('select').value,
            values: row.querySelector('input[id$=_values]').value,
            target: row.querySelector('input[id$=_target]').value,
        })),
    );
    await save();

    results.rows = { shown, stored: await stored() };
}

// 2. json: the document typed into the chosen view is the one stored
{
    await openEditor();
    await page.$eval('input[name="link[rules][mode]"][value=raw]', (radio) => radio.click());
    await settle();
    const structuredHidden = await page.$eval('[data-rules-editor-target=structured]', (node) => node.hidden);
    await page.$eval(
        '#link_rules_raw',
        (field, document) => { field.value = document; },
        '{"version":1,"variants":[{"name":"a","weight":60,"target":"https://example.com/a"},{"name":"b","weight":40,"target":"https://example.com/b"}]}',
    );
    await save();

    results.json = { structuredHidden, stored: await stored() };
}

// 3. reopen: a document the rows cannot hold comes back as JSON
{
    await openEditor();
    results.reopen = {
        mode: await page.$eval('input[name="link[rules][mode]"]:checked', (radio) => radio.value),
        rawHasVariants: await page.$eval('#link_rules_raw', (field) => field.value.includes('variants')),
        rawVisible: await page.$eval('[data-rules-editor-target=raw]', (node) => !node.hidden),
    };
}

await browser.close();
console.log(JSON.stringify(results, null, 2));
