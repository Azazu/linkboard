/*
 * The architecture diagram is Mermaid in the repository (change
 * harden-quality-and-docs, design decision 3), so "it renders" is a claim a
 * browser has to settle — the npm build of Mermaid needs a DOM and cannot
 * parse headlessly. This loads the block into the same headless Chromium the
 * other acceptance scripts use and asks Mermaid to render it.
 *
 *   npm install --no-save --prefix /tmp/lb-acceptance puppeteer-core mermaid
 *   NODE_PATH=/tmp/lb-acceptance/node_modules \
 *     node tests/Acceptance/mermaid-check.mjs docs/explanation/architecture.md
 */

import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';

const require = createRequire('/tmp/lb-acceptance/');
const puppeteer = require('puppeteer-core');

const page_path = process.argv[2] ?? 'docs/explanation/architecture.md';
const source = readFileSync(page_path, 'utf8');
const blocks = source.split('```mermaid').slice(1).map((part) => part.split('```')[0].trim());
if (blocks.length === 0) {
    throw new Error(`${page_path} carries no mermaid block`);
}

const mermaidSource = readFileSync('/tmp/lb-acceptance/node_modules/mermaid/dist/mermaid.min.js', 'utf8');
const browser = await puppeteer.launch({
    executablePath: process.env.CHROMIUM ?? '/usr/bin/chromium-browser',
    headless: 'new',
    args: ['--no-sandbox', '--disable-gpu'],
});
const page = await browser.newPage();
await page.setContent('<!doctype html><html><body></body></html>');
await page.addScriptTag({ content: mermaidSource });

const results = [];
for (const [index, block] of blocks.entries()) {
    results.push(await page.evaluate(async (definition, id) => {
        try {
            window.mermaid.initialize({ startOnLoad: false });
            const { svg } = await window.mermaid.render(`check-${id}`, definition);
            return { ok: true, nodes: (svg.match(/<g class="node/g) ?? []).length, bytes: svg.length };
        } catch (error) {
            return { ok: false, error: String(error && error.message ? error.message : error) };
        }
    }, block, index));
}

await browser.close();
console.log(JSON.stringify({ page: page_path, blocks: results }, null, 2));
if (results.some((result) => !result.ok)) {
    process.exit(1);
}
