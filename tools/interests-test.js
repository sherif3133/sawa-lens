/* Silent end-to-end test of "Find English content you'll love" on the local WordPress Playground,
   at phone and computer sizes (muted, speech stubbed). Run from language-lens/tools (for puppeteer):
   node ../../sawa-lens/tools/interests-test.js <outdir> */
const puppeteer = require(require.resolve('puppeteer', { paths: [process.cwd()] }));
const B = 'http://127.0.0.1:9410';
const OUT = process.argv[2] || '.';
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
let bad = 0;
const ok = (c, m) => { console.log((c ? 'ok   ' : 'FAIL ') + m); if (!c) bad++; };

async function run(br, size) {
	const p = await br.newPage();
	const phone = size === 'phone';
	await p.setViewport(phone ? { width: 390, height: 844, isMobile: true, hasTouch: true } : { width: 1366, height: 900 });
	await p.evaluateOnNewDocument(() => { window.speechSynthesis = { speak() {}, cancel() {}, getVoices: () => [], onvoiceschanged: null }; HTMLMediaElement.prototype.play = () => Promise.resolve(); });
	const errs = [];
	p.on('pageerror', (e) => errs.push(e.message));
	p.on('dialog', (d) => d.accept());
	const shot = (n) => p.screenshot({ path: `${OUT}/${size}-${n}.png`, fullPage: true });
	const noScroll = () => p.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1);
	const click = async (sel) => { await p.waitForSelector(sel, { timeout: 15000 }); await p.click(sel); await sleep(500); };

	await p.goto(B + '/app/?as=1#interests-cats', { waitUntil: 'networkidle2' });
	await p.waitForSelector('.in-cat', { timeout: 20000 });
	const n = await p.$$eval('.in-cat', (x) => x.length);
	ok(n >= 45, size + ': ' + n + ' categories');
	// Start fresh: unpick everything, then pick comedy, rap and football.
	await p.$$eval('.in-cat[aria-pressed="true"]', (x) => x.forEach((b) => b.click()));
	ok(await p.$eval('#in-next', (b) => b.disabled), size + ': Next is off with nothing picked');
	for (const c of ['comedy', 'rap', 'football']) await click(`.in-cat[data-c="${c}"]`);
	ok(await noScroll(), size + ': no sideways scroll (categories)');
	await shot('1-categories');
	await click('#in-next');
	await p.waitForSelector('.in-crb');
	const crs = await p.$$eval('.in-crb', (x) => x.map((b) => b.dataset.a));
	ok(crs.includes('abyusif') && crs.includes('eyad-el-mogy') && crs.includes('mohamed-salah'), size + ': deeper step shows creators of the 3 categories (' + crs.length + ')');
	await p.$$eval('.in-crb[aria-pressed="true"]', (x) => x.forEach((b) => b.click()));
	for (const a of ['abyusif', 'marwan-pablo', 'eyad-el-mogy']) await click(`.in-crb[data-a="${a}"]`);
	ok(await noScroll(), size + ': no sideways scroll (creators)');
	const tap = await p.$eval('.in-crb', (b) => b.getBoundingClientRect().height);
	ok(tap >= 44, size + ': creator buttons are big enough to tap (' + Math.round(tap) + 'px)');
	await shot('2-creators');
	await click('#in-next');
	await p.waitForSelector('.in-rec.big', { timeout: 15000 });
	const start = await p.$$eval('.in-rec.big b', (x) => x.map((b) => b.textContent));
	console.log('     Start here:', start.join(' | '));
	ok(start.some((s) => /J\. Cole|XXXTentacion|Travis Scott|Kendrick/.test(s)), size + ': rap picks match Abyusif / Pablo');
	ok(start.some((s) => /Danny Gonzalez/.test(s)), size + ': comedy pick matches Mogzz');
	ok(await p.$$eval('.in-rec', (x) => x.every((r) => r.querySelector('.pill.lv') && r.querySelector('a[target=_blank]'))), size + ': every pick has a level and a link');
	ok(await noScroll(), size + ': no sideways scroll (picks)');
	await shot('3-picks');
	// More on request
	const hidden = await p.$$eval('.in-more[hidden]', (x) => x.length);
	if (hidden) { await click('[data-more]'); ok((await p.$$eval('.in-more[hidden]', (x) => x.length)) < hidden, size + ': "Show more" opens more picks'); }
	// Star one and check it is saved to the account
	const sid = await p.$eval('.in-rec.big .in-star', (b) => b.dataset.s);
	await click('.in-rec.big .in-star');
	await sleep(800);
	await p.goto('about:blank'); await p.goto(B + '/app/?as=1#interests', { waitUntil: 'networkidle2' });
	await p.waitForSelector('.in-rec', { timeout: 15000 });
	ok(await p.$eval(`.in-star[data-s="${sid}"]`, (b) => b.getAttribute('aria-pressed') === 'true'), size + ': starred pick is still saved after reload (' + sid + ')');
	await click(`.in-star[data-s="${sid}"]`); // unstar again
	// Home card
	await p.goto('about:blank'); await p.goto(B + '/app/?as=1#today', { waitUntil: 'networkidle2' });
	await p.waitForSelector('#ilove:not([hidden])', { timeout: 15000 });
	const home = await p.$eval('#ilove', (b) => b.innerText);
	ok(/Watch 10 minutes of/.test(home), size + ': Home shows today\'s pick: ' + home.split('\n').find((l) => /Watch 10/.test(l)));
	await p.$eval('#ilove', (b) => b.scrollIntoView());
	await p.screenshot({ path: `${OUT}/${size}-4-home.png` });
	// Learn tab
	await p.goto('about:blank'); await p.goto(B + '/app/?as=1#learn', { waitUntil: 'networkidle2' });
	await p.waitForSelector('[data-t="interests"]');
	ok(true, size + ': Learn has "Find content you\'ll love"');
	await click('[data-t="interests"]');
	await p.waitForSelector('.in-rec');
	ok(true, size + ': Learn tile opens the picks');
	ok(!errs.length, size + ': no page errors ' + (errs.length ? errs.join(' / ') : ''));
	await p.close();
}

(async () => {
	const br = await puppeteer.launch({ headless: true, args: ['--no-sandbox', '--mute-audio', '--autoplay-policy=user-gesture-required'] });
	try { await run(br, 'phone'); await run(br, 'computer'); } finally { await br.close(); }
	console.log(bad ? bad + ' failed' : 'all passed');
	process.exit(bad ? 1 : 0);
})();
