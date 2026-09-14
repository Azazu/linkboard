/*
 * Browser acceptance of the routing-rules editor (change add-web-ui, Gate 2
 * round 1, findings 1 and 2). A WebTestCase can post any field it likes; only a
 * browser can say whether the controls a person actually operates produce the
 * document that gets stored.
 *
 * Four things are checked, each end to end:
 *   rows   — add two rules, remove the first, add a third, save: exactly the
 *            rules still on the screen are the rules stored, with no index
 *            collision between the survivors and the new row;
 *   json   — switch to the JSON view, type a document, save: that document is
 *            stored (with the fields view chosen it must not be);
 *   reopen — a stored document the rows cannot hold comes back as JSON;
 *   invalid — invalid JSON typed into the JSON view is refused in that view,
 *            with the error and the typed text still there and nothing stored,
 *            and a correction on that same page saves.
 *
 * Every case reports what it observed; the run's output is recorded with the
 * commit, because CI has no browser and cannot repeat it.
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

/* The forms carry several submit buttons — add a rule, switch view, save — so
   every click here names the button it means. */
const clickButton = (label) => page.$$eval('button', (buttons, text) => {
    const button = buttons.find((candidate) => candidate.textContent.trim() === text);
    if (!button) {
        throw new Error(`no button labelled ${text}`);
    }
    button.click();
}, label);

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
await page.$eval('#link_targetUrl', (field) => { field.value = 'https://example.com/acceptance'; });
await clickButton('Create link');
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

const rowIndices = () => page.$$eval('[data-rules-editor-target=row] select', (nodes) => nodes.map((node) => node.id.replace(/\D+/g, '')));
const shownRows = () => page.$$eval('[data-rules-editor-target=row]', (rows) =>
    rows.map((row) => ({
        match: row.querySelector('select').value,
        values: row.querySelector('input[id$=_values]').value,
        target: row.querySelector('input[id$=_target]').value,
    })),
);
const addRow = () => page.$eval('button[data-action="rules-editor#addRow"]', (button) => button.click());
async function save(expect = 'saved') {
    await clickButton('Save changes');
    if (expect === 'saved') {
        await page.waitForFunction((path) => window.location.pathname === path, { timeout: 15000 }, linkPath).catch(async () => {
            const alerts = await page.$$eval('[role=alert]', (nodes) => nodes.map((node) => node.textContent.trim()));
            throw new Error(`the save did not land on the link page — at ${page.url()}, alerts: ${JSON.stringify(alerts)}`);
        });
    } else {
        await page.waitForSelector('[role=alert]', { timeout: 15000 });
        // the link page carries flash alerts of its own, so an alert alone does
        // not mean the submission was refused — staying on the form does
        if (new URL(page.url()).pathname === linkPath) {
            throw new Error('the save was accepted: it landed on the link page, where a refusal was expected');
        }
    }
    await settle();
}

// 1. rows: add two, remove the first, add a third, and keep them across an
//    invalid submission — the case a re-render used to break (finding 2)
{
    await openEditor();
    await addRow();
    await settle();
    let indices = await rowIndices();
    await fillRow(indices[0], 'device', 'smartphone', 'https://example.com/first');
    await fillRow(indices[1], 'country', 'DE, AT', 'https://example.com/second');

    // drop the first, then add another: the new row must not reuse an index
    await page.$eval('[data-rules-editor-target=row] button', (button) => button.click());
    await settle();
    await addRow();
    await settle();
    indices = await rowIndices();
    await fillRow(indices[indices.length - 1], 'os', 'iOS', 'not-a-url');

    // an invalid target: the page comes back with the rules still on it
    await save('refused');
    results.afterInvalidSubmission = { rows: await shownRows(), url: new URL(page.url()).pathname };

    // correct it, add one more row through the re-rendered page, and save
    await page.$eval('details', (element) => { element.open = true; });
    indices = await rowIndices();
    await fillRow(indices[indices.length - 1], 'os', 'iOS', 'https://example.com/third');
    await addRow();
    await settle();
    indices = await rowIndices();
    await fillRow(indices[indices.length - 1], 'language', 'de', 'https://example.com/fourth');
    const shown = await shownRows();
    await save();

    results.rows = { shown, stored: await stored() };
}

// 2. switching carries the document across, in both directions
{
    await openEditor();
    await clickButton('Edit as JSON');
    await page.waitForSelector('#link_rules_raw');
    await page.$eval('details', (element) => { element.open = true; });
    results.switchToJson = { document: JSON.parse(await page.$eval('#link_rules_raw', (field) => field.value)) };

    await clickButton('Edit as fields');
    await page.waitForSelector('[data-rules-editor-target=row]');
    await page.$eval('details', (element) => { element.open = true; });
    results.switchBackToFields = { rows: await shownRows() };
}

// 3. a document the fields cannot hold refuses the switch instead of dropping it
{
    await openEditor();
    await clickButton('Edit as JSON');
    await page.waitForSelector('#link_rules_raw');
    await page.$eval('details', (element) => { element.open = true; });
    await page.$eval(
        '#link_rules_raw',
        (field, document) => { field.value = document; },
        '{"version":1,"variants":[{"name":"a","weight":60,"target":"https://example.com/a"},{"name":"b","weight":40,"target":"https://example.com/b"}]}',
    );
    await save();
    results.variantsStored = await stored();

    await openEditor();
    await clickButton('Edit as fields');
    await page.waitForSelector('[role=alert]');
    results.refusedSwitch = {
        message: await page.$eval('[role=alert]', (node) => node.textContent.trim()),
        stillJson: await page.$('#link_rules_raw') !== null,
        stored: await stored(),
    };
}

// 4. invalid JSON in the JSON view: the refusal stays in that view, shows the
//    parse error, keeps what was typed, and stores nothing (finding 1 named
//    this case; only a browser can say which view comes back and with what in
//    it). The link still holds the variants document from case 3.
{
    const invalid = '{"version":1,"rules":[{"match":{"country":["DE"]},"target":"https://example.com/de"}';

    await openEditor();
    await page.waitForSelector('#link_rules_raw');
    await page.$eval('#link_rules_raw', (field, document) => { field.value = document; }, invalid);
    await save('refused');

    // read what is stored without leaving the refused page — a second tab
    // shares the session, so the page under test keeps its state
    const inspector = await browser.newPage();
    await inspector.goto(`${base}${linkPath}`, { waitUntil: 'load' });
    const storedWhileRefused = await inspector.$eval('pre code', (node) => JSON.parse(node.textContent)).catch(() => null);
    await inspector.close();

    results.invalidRaw = {
        url: new URL(page.url()).pathname,
        stillJson: (await page.$('#link_rules_raw')) !== null,
        keptText: (await page.$eval('#link_rules_raw', (field) => field.value)) === invalid,
        alerts: await page.$$eval('[role=alert]', (nodes) => nodes.map((node) => node.textContent.trim())),
        stored: storedWhileRefused,
    };

    // correct it on the page that came back, and save
    await page.$eval('#link_rules_raw', (field, document) => { field.value = document; }, `${invalid}]}`);
    await save();
    results.afterCorrection = await stored();
}

await browser.close();
console.log(JSON.stringify(results, null, 2));
