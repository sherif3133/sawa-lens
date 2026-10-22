/* Silent end-to-end test of "Stories for you" (beta) on the local WordPress Playground, at phone and computer
   sizes (muted, speech stubbed, the AI mocked by a test mu-plugin that answers "You write original graded readers").
   Needs ?readers_setup=1 from that mu-plugin. Run from language-lens/tools (for puppeteer):
   node ../../sawa-lens/tools/readers-test.js <outdir> */
const puppeteer = require(require.resolve('puppeteer', { paths: [process.cwd()] }));
const B = 'http://127.0.0.1:9410';
const OUT = process.argv[2] || '.';
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
let bad = 0;
const ok = (c, m) => { console.log((c ? 'ok   ' : 'FAIL ') + m); if (!c) bad++; };
const text = (u) => fetch(B + u).then((r) => r.text());
const calls = async () => +((await text('/?readers_last=1')).match(/calls: (\d+)/) || [])[1];

async function page(br, size, as) {
	const p = await br.newPage();
	await p.setViewport(size === 'phone' ? { width: 390, height: 844, isMobile: true, hasTouch: true } : { width: 1366, height: 900 });
	await p.evaluateOnNewDocument(() => { window.speechSynthesis = { speak() {}, cancel() {}, getVoices: () => [], onvoiceschanged: null }; HTMLMediaElement.prototype.play = () => Promise.resolve(); });
	p.errs = [];
	p.on('pageerror', (e) => p.errs.push(e.message));
	p.on('dialog', (d) => d.accept());
	p.shot = (n) => p.screenshot({ path: `${OUT}/${size}-${n}.png`, fullPage: true });
	p.noScroll = () => p.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1);
	p.tap = async (sel) => { await p.waitForSelector(sel, { timeout: 20000 }); await p.click(sel); await sleep(400); };
	p.api = (path, body) => p.evaluate((path, body) => fetch(SAWA_LENS.root + path, { method: body ? 'POST' : 'GET', credentials: 'same-origin', headers: { 'X-WP-Nonce': SAWA_LENS.nonce, 'Content-Type': 'application/json' }, body: body ? JSON.stringify(body) : undefined }).then((r) => r.json()), path, body);
	await p.goto(B + '/app/?as=' + as + '#learn', { waitUntil: 'networkidle2' });
	return p;
}

async function phone(br) {
	const p = await page(br, 'phone', 1);
	for (const w of ['brave', 'coach', 'nervous']) await p.api('library/word', { lemma: w, status: 'learning', sentence: 'Be brave.', title: 'test' });
	await p.goto(B + '/app/?as=1#learn', { waitUntil: 'networkidle2' });
	await p.waitForSelector('[data-t="stories"]', { timeout: 15000 });
	ok(true, 'phone: Learn shows the 📖 Stories for you tile');
	await p.tap('[data-t="stories"]');
	await p.waitForSelector('#st [data-cat]', { timeout: 15000 });
	const st = await p.$eval('#st', (b) => b.innerText);
	ok(/Today's pick/.test(st) && /Football|Business/.test(st), 'phone: today\'s pick is one of the quiz topics');
	ok(/A story made just for you/.test(st) && !!(await p.$('[data-mine]')), 'phone: personal story card with a button (3 Learning words)');
	ok(await p.noScroll(), 'phone: no sideways scroll (Stories)');
	const tapH = await p.$eval('.st-chips .btn', (b) => b.getBoundingClientRect().height);
	ok(tapH >= 44, 'phone: topic buttons big enough to tap (' + Math.round(tapH) + 'px)');
	await p.shot('1-stories');

	// Read the football story.
	const n0 = await calls();
	await p.tap('.st-chips [data-cat="football"]');
	await p.waitForSelector('.scan-text p', { timeout: 30000 });
	ok((await calls()) === n0 + 1, 'phone: a new shared story is written once (AI called 1 time)');
	ok(/#story-\d+/.test(await p.evaluate(() => location.hash)), 'phone: address is #story-<id>, so it can be reopened');
	const pills = await p.$$eval('.in-tags .pill', (x) => x.map((e) => e.textContent).join(' | '));
	ok(/Football/.test(pills) && /A2/.test(pills) && /Fiction|Explainer/.test(pills), 'phone: topic, level and Fiction labels (' + pills + ')');
	ok((await p.$$('.scan-text .sw.tg')).length >= 2, 'phone: new words are underlined in the text');
	ok(!/&quot;|&amp;/.test(await p.$eval('.scan-text', (b) => b.innerText)), 'phone: quotes show as quotes (no &quot;)');
	ok(await p.noScroll(), 'phone: no sideways scroll (story)');
	await p.tap('#readall'); // listen along (speech stubbed)
	ok(/Stop/.test(await p.$eval('#readall', (b) => b.textContent)), 'phone: listen-along starts');
	await p.tap('.scan-text .sw');
	ok(!!(await p.$('#wcard *')), 'phone: tapping a word opens the word card');
	await p.tap('[data-stw="0"]');
	await p.waitForFunction(() => document.querySelector('[data-stw="0"]').textContent === '✓', { timeout: 10000 });
	ok(true, 'phone: ＋ adds a new word to review cards');
	await p.shot('2-story');
	await p.tap('#stdone');
	await sleep(900); // the quick check scrolls into view
	ok((await p.$$('.st-q')).length === 4, 'phone: 4 quick-check questions');
	for (const [i, k] of [[0, 1], [1, 0], [2, 1], [3, 0]]) await p.$eval(`.st-q[data-q="${i}"] .opt[data-k="${k}"]`, (b) => b.click());
	await p.waitForSelector('.st-end', { timeout: 10000 });
	const end = await p.$eval('.st-end', (b) => b.textContent);
	ok(/4 of 4/.test(end) && /\+15 XP/.test(end) && /1-day reading streak/.test(end), 'phone: result shows 4/4, +15 XP and the streak (' + end.trim() + ')');
	await p.shot('3-check');
	const again = await p.evaluate(() => fetch(SAWA_LENS.root + 'readers/done', { method: 'POST', credentials: 'same-origin', headers: { 'X-WP-Nonce': SAWA_LENS.nonce, 'Content-Type': 'application/json' }, body: JSON.stringify({ id: +location.hash.split('-')[1], right: 4, total: 4 }) }).then((r) => r.json()));
	ok(again.xp === 0, 'phone: XP is given only once per story');

	// Personal story around the Learning words.
	await p.$eval('#stmore', (b) => b.click());
	await p.waitForSelector('#st [data-cat]', { timeout: 15000 });
	await p.shot('3b-back');
	await p.tap('[data-mine]');
	await p.waitForSelector('.scan-text p', { timeout: 30000 });
	ok(!!(await p.$('.pill.open')), 'phone: personal story is marked "Made for you"');
	const prompt = await text('/?readers_last=1');
	ok(/brave|coach|nervous/.test(prompt) && /never as a list/.test(prompt), 'phone: personal prompt weaves in the Learning words, not as a list');
	ok(/Never use real people/.test(prompt) && /Never copy or quote/.test(prompt), 'phone: prompt forbids real people and copied text');
	ok(/Your words in this story/.test(await p.$eval('main', (m) => m.innerText)), 'phone: shows "Your words in this story"');
	const twice = await p.api('readers/next', { personal: 1 });
	ok(twice.code === 'today', 'phone: one personal story a day');

	// A series: chapter 1, then (admin can skip the wait) chapter 2 continues the story.
	const c1 = await p.api('readers/next', { cat: 'football', format: 'series' });
	ok(c1.chapter === 1 && / – Chapter 1$/.test(c1.title), 'phone: a series starts at chapter 1 (' + c1.title + ')');
	await p.api('readers/done', { id: c1.id, right: 3, total: 4 });
	const home = await p.api('readers');
	ok(home.series && home.series.next === 2 && home.series.tomorrow, 'phone: chapter 2 is waiting for tomorrow');
	const c2 = await p.api('readers/next', { series: c1.series });
	ok(c2.chapter === 2 && c2.series === c1.id, 'phone: chapter 2 belongs to the same series');
	ok(/The story so far: Chapter 1/.test(await text('/?readers_last=1')), 'phone: chapter 2 is written from chapter 1\'s recap');

	// Library and Home.
	await p.goto(B + '/app/?as=1&r=2#stories', { waitUntil: 'networkidle2' });
	await p.waitForSelector('.sg-item', { timeout: 15000 });
	ok((await p.$$('.sg-item')).length === 4, 'phone: My stories lists the 4 opened stories');
	ok(/🔥 1-day reading streak/.test(await p.$eval('#st', (b) => b.innerText)), 'phone: reading streak on Stories');
	await p.shot('4-library');
	await p.goto(B + '/app/?as=1&r=3#today', { waitUntil: 'networkidle2' });
	await p.waitForSelector('#story:not([hidden])', { timeout: 15000 });
	ok(/📖/.test(await p.$eval('#story', (b) => b.innerText)), 'phone: Home shows the story card');
	ok(await p.noScroll(), 'phone: no sideways scroll (Home)');
	await p.shot('5-home');
	ok(!p.errs.length, 'phone: no page errors ' + p.errs.join(' / '));
	await p.close();
}

async function computer(br) {
	// Another A2 football fan gets the shared story without a new AI call.
	const p = await page(br, 'computer', 8);
	const n0 = await calls();
	const r = await p.api('readers/next', { cat: 'football', format: 'story' });
	const r2 = await p.api('readers/next', { cat: 'football', format: 'explainer' });
	const reused = [r, r2].filter((x) => x.mine === false && x.chapter === 0).length;
	console.log('     user 8 got:', r.title, '|', r2.title, '· AI calls', (await calls()) - n0);
	ok(reused === 2 && (await calls()) - n0 <= 1, 'computer: shared stories are reused (at most 1 new one written for 2 reads)');
	await p.goto(B + '/app/?as=8&r=4#story-' + r.id, { waitUntil: 'networkidle2' });
	await p.waitForSelector('.scan-text p', { timeout: 15000 });
	ok(!!(await p.$('#stdone')), 'computer: story opens from its address with the "I finished reading" button');
	await p.shot('1-story');
	const skip = await p.api('readers/skip', { cat: 'football' });
	const h = await p.api('readers');
	ok(skip.ok && !h.cats.some((c) => c.id === 'football'), 'computer: 👎 Not this topic hides it from the topics');
	const hid = await p.api('readers/hide', { id: r.id });
	ok(hid.code === 'admin', 'computer: students can\'t hide stories');
	ok(!p.errs.length, 'computer: no page errors ' + p.errs.join(' / '));
	await p.close();
}

(async () => {
	console.log(await text('/?readers_setup=1'));
	const br = await puppeteer.launch({ headless: 'new', args: ['--mute-audio', '--autoplay-policy=user-gesture-required'] });
	try { await phone(br); await computer(br); } catch (e) { ok(false, 'crashed: ' + e.message); }
	await br.close();
	console.log(bad ? bad + ' FAILED' : 'ALL OK');
	process.exit(bad ? 1 : 0);
})();
