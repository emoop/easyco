// A REAL-BROWSER run of MAIL-2: the product pictures of the order-confirmation mail. NOT part of `php artisan test`: run by hand against the dev site.
//
// It builds the confirmation of an EXISTING dev order that has three lines (one with a variant picture, one with only its product's picture, one with no picture) through the real
// OrderConfirmationContent (no mail is sent, no mail_log row is written, no setting is changed), writes the HTML to a file, checks that every image URL answers 200 `image/jpeg`
// and screenshots the mail at 600 px and 360 px wide (the rows must be aligned: look at the screenshots). At the end it DELETES the thumbnails it created (the ones that were not there before).
//
//   ORDER_ID=123 PLAYWRIGHT_DIR=<dir> [CHROMIUM=<path>] [LOCALE=bg] [PHP=php] OUT=<folder> node tests/Browser/mail-2.mjs
// The image URLs are APP_URL + /storage/mail-thumbs/...: APP_URL must be the dev site and `storage:link` must exist. Prints a JSON report; exit code 1 when a check failed.
import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
import { mkdirSync, readFileSync, writeFileSync, rmSync, mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const ORDER = process.env.ORDER_ID;
const OUT = process.env.OUT;
const LOCALE = process.env.LOCALE ?? 'bg';
const PHP = process.env.PHP ?? 'php';
const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..');

if (! ORDER || ! OUT || ! process.env.PLAYWRIGHT_DIR) {
    console.error('usage: ORDER_ID=... OUT=<folder> PLAYWRIGHT_DIR=... node tests/Browser/mail-2.mjs');
    process.exit(2);
}

mkdirSync(OUT, { recursive: true });

const steps = [];
const check = (name, ok, detail = '') => { steps.push({ name, ok: !! ok, detail: String(detail).slice(0, 300) }); if (! ok) console.error('FAIL ' + name + ' ' + detail); };

// ---- 1. build the mail with the application itself ------------------------------------------------------------------------------------
const work = mkdtempSync(join(tmpdir(), 'mail2-'));
const htmlFile = join(OUT, 'confirmation.html');
const script = join(work, 'build.php');
writeFileSync(script, `<?php
$root = ${JSON.stringify(ROOT.replaceAll('\\', '/'))};
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
$disk = Illuminate\\Support\\Facades\\Storage::disk('public');
$before = $disk->files('mail-thumbs');
$mail = $app->make(App\\Mail\\OrderConfirmationContent::class)->build((object) ['related_id' => ${JSON.stringify(ORDER)}, 'locale' => ${JSON.stringify(LOCALE)}]);
if ($mail === null) { fwrite(STDERR, "no such order\\n"); exit(3); }
file_put_contents(${JSON.stringify(htmlFile.replaceAll('\\', '/'))}, $mail->html);
echo json_encode(['created' => array_values(array_diff($disk->files('mail-thumbs'), $before)), 'all' => $disk->files('mail-thumbs'), 'root' => $disk->path('')]);
`);

let created = [];
let diskRoot = '';

try {
    const out = execFileSync(PHP, [script], { cwd: ROOT, encoding: 'utf8' });
    const built = JSON.parse(out.trim().split('\n').pop());
    created = built.created;
    diskRoot = built.root;
    check('the mail was built', true);
} catch (e) {
    check('the mail was built', false, e.message);
    console.log(JSON.stringify({ ok: false, steps }, null, 2));
    process.exit(1);
}

const html = readFileSync(htmlFile, 'utf8');
const urls = [...html.matchAll(/<img [^>]*src="([^"]+)"/g)].map((m) => m[1].replaceAll('&amp;', '&'));

check('three lines, a picture column (heading + 3 cells)', (html.match(/<t[dh] width="72"/g) ?? []).length === 4, (html.match(/<t[dh] width="72"/g) ?? []).length);
check('two pictures (variant + product), the third row is an empty cell', urls.length === 2 && html.includes('<td width="72" valign="top" style="width:72px;"></td>'), `${urls.length} images`);
check('every picture has width, height, alt and border=0', [...html.matchAll(/<img [^>]*>/g)].every((m) => /width="\d+"/.test(m[0]) && /height="\d+"/.test(m[0]) && /alt="/.test(m[0]) && /border="0"/.test(m[0])));
check('no webp, svg, srcset, background or data URI', ! /\.webp|\.svg|srcset|background|data:/i.test(html));

// ---- 2. every image answers 200 image/jpeg ---------------------------------------------------------------------------------------------
for (const url of urls) {
    try {
        const res = await fetch(url, { redirect: 'follow' });
        const type = res.headers.get('content-type') ?? '';
        const bytes = new Uint8Array(await res.arrayBuffer());
        check(`200 image/jpeg: ${url}`, res.status === 200 && type.startsWith('image/jpeg') && bytes[0] === 0xff && bytes[1] === 0xd8, `${res.status} ${type}`);
    } catch (e) {
        check(`200 image/jpeg: ${url}`, false, e.message);
    }
}

// ---- 3. the screenshots at 600 px and 360 px ---------------------------------------------------------------------------------------------
const { chromium } = createRequire(join(process.env.PLAYWRIGHT_DIR, 'x.js'))('playwright');
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM || undefined, args: ['--ignore-certificate-errors'] });
const shots = [];

try {
    for (const width of [600, 360]) {
        const ctx = await browser.newContext({ viewport: { width, height: 900 }, ignoreHTTPSErrors: true });
        const page = await ctx.newPage();
        await page.goto('file://' + htmlFile.replaceAll('\\', '/'), { waitUntil: 'networkidle' });
        const broken = await page.evaluate(() => [...document.images].filter((i) => ! i.complete || i.naturalWidth === 0).length);
        check(`no broken image at ${width} px`, broken === 0, broken);
        const overflow = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth);
        check(`no horizontal scroll at ${width} px`, ! overflow);
        const path = join(OUT, `confirmation-${width}.png`);
        await page.screenshot({ path, fullPage: true });
        shots.push(path);
        await ctx.close();
    }
} finally {
    await browser.close();

    // ---- 4. clean up: only the files THIS run created ----------------------------------------------------------------------------------
    for (const file of created) {
        try { rmSync(join(diskRoot, file)); } catch { /* already gone */ }
    }
    rmSync(work, { recursive: true, force: true });
}

const failed = steps.filter((s) => ! s.ok).length;
console.log(JSON.stringify({ ok: failed === 0, failed, created, html: htmlFile, shots, steps }, null, 2));
process.exit(failed === 0 ? 0 : 1);
