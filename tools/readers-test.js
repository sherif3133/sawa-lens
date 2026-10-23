/* Silent end-to-end test of "Stories for you" on the local WordPress Playground, at phone and computer sizes
   (muted, speech and audio stubbed). The written library (data/stories) is imported by the test mu-plugin's
   ?readers_setup=1; the AI and the OpenAI voice are mocked there too. Run from language-lens/tools (for puppeteer):
   node ../../sawa-lens/tools/readers-test.js <outdir> */
const puppeteer = require(require.resolve('puppeteer', { paths: [process.cwd()] }));
const B = 'http://127.0.0.1:9410';
const OUT = process.argv[2] || '.';
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
let bad = 0, nav = 0;
const ok = (c, m) => { console.log((c ? 'ok   ' : 'FAIL ') + m); if (!c) bad++; };
const text = (u) => fetch(B + u).then((r) => r.text());
const calls = async () => +((await text('/?readers_last=1')).match(/calls: (\d+)/) || [])[1];

async function page(br, size, as) {
	const p = await br.newPage();
	await p.setViewport(size === 'phone' ? { width: 390, height: 844, isMobile: true, hasTouch: true } : { width: 1366, height: 900 });
	await p.evaluateOnNewDocument(() => { window.speechSynthesis = { speak() {}, cancel() {}, getVoices: () => [], onvoiceschanged: null }; HTMLMediaElement.prototype.play = function () { window.__played = (window.__played || 0) + 1; return Promise.resolve(); }; });
	p.errs = [];
	p.on('pageerror', (e) => p.errs.push(e.message));
	p.on('dialog', (d) => d.accept());
	p.shot = (n) => p.screenshot({ path: `${OUT}/${size}-${n}.png`, fullPage: true });
	p.noScroll = () => p.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1);
	p.tap = async (sel) => { await p.waitForSelector(sel, { timeout: 20000 }); await p.$eval(sel, (b) => b.click()); await sleep(400); };
	p.api = (path, body) => p.evaluate((path, body) => fetch(SAWA_LENS.root + path, { method: body ? 'POST' : 'GET', credentials: 'same-origin', headers: { 'X-WP-Nonce': SAWA_LENS.nonce, 'Content-Type': 'application/json' }, body: body ? JSON.stringify(body) : undefined }).then((r) => r.json()), path, body);
	p.open = (hash) => p.goto(B + '/app/?as=' + as + '&n=' + (++nav) + '#' + hash, { waitUntil: 'networkidle2' }); // a new query each time, so the page really reloads
	await p.open('learn');
	return p;
}

async function phone(br) {
	const p = await page(br, 'phone', 1);
	for (const w of ['brave', 'coach', 'nervous']) await p.api('library/word', { lemma: w, status: 'learning', sentence: 'Be brave.', title: 'test' });
	await p.open('learn');
	await p.waitForSelector('[data-t="stories"]', { timeout: 15000 });
	ok(true, 'phone: Learn shows the 📖 Stories for you tile');
	await p.tap('[data-t="stories"]');
	await p.waitForSelector('#st [data-open]', { timeout: 15000 });
	const st = await p.$eval('#st', (b) => b.innerText);
	ok(/Today's pick/.test(st) && /A2|B1/.test(st) && /min/.test(st), 'phone: today\'s pick is a real story with level and minutes');
	ok(await p.noScroll(), 'phone: no sideways scroll (Stories)');
	const tapH = await p.$eval('.st-chips .btn', (b) => b.getBoundingClientRect().height);
	ok(tapH >= 44, 'phone: topic buttons big enough to tap (' + Math.round(tapH) + 'px)');
	await p.shot('1-stories');

	// Read a football story from the library: no AI call.
	const n0 = await calls();
	await p.tap('.st-chips [data-cat="football"]');
	await p.waitForSelector('.scan-text p', { timeout: 20000 });
	ok((await calls()) === n0, 'phone: the story comes from the written library (no AI call)');
	const id = +(await p.evaluate(() => location.hash.split('-')[1]));
	const r = await p.api('readers/' + id);
	ok(r.cat.id === 'football' && r.level === 'A2', 'phone: an A2 football story for an A2 football fan (' + r.title + ')');
	const pills = await p.$$eval('.in-tags .pill', (x) => x.map((e) => e.textContent).join(' | '));
	ok(/Football/.test(pills) && /A2/.test(pills), 'phone: topic and level labels (' + pills + ')');
	ok((await p.$$('.scan-text .sw.tg')).length >= 3, 'phone: new words are underlined in the text');
	ok(!/&quot;|&amp;|&#39;/.test(await p.$eval('.scan-text', (b) => b.innerText)), 'phone: quotes and apostrophes show correctly');
	ok(await p.noScroll(), 'phone: no sideways scroll (story)');
	// Listen along with the natural voice (mocked OpenAI TTS, playback stubbed).
	await p.tap('#readall');
	await p.waitForFunction(() => window.__played >= 1, { timeout: 10000 }).catch(() => {});
	ok(/Stop/.test(await p.$eval('#readall', (b) => b.textContent)) && (await p.evaluate(() => window.__played)) >= 1, 'phone: listen-along plays the recorded natural voice');
	const again = await p.api('readers/audio', { id, p: 0 });
	ok(/sawa-lens-stories\/.+\.mp3$/.test(again.url || ''), 'phone: the recording is saved and reused (' + (again.url || again.message) + ')');
	await p.tap('#readall'); // stop
	await p.tap('.scan-text .sw');
	ok(!!(await p.$('#wcard *')), 'phone: tapping a word opens the word card');
	await p.tap('[data-stw="0"]');
	await p.waitForFunction(() => document.querySelector('[data-stw="0"]').textContent === '✓', { timeout: 10000 });
	ok(true, 'phone: ＋ adds a new word to review cards');
	await p.shot('2-story');
	await p.tap('#stdone');
	ok((await p.$$('.st-q')).length === r.questions.length, 'phone: ' + r.questions.length + ' quick-check questions');
	for (let i = 0; i < r.questions.length; i++) await p.$eval(`.st-q[data-q="${i}"] .opt[data-k="${r.questions[i].answer}"]`, (b) => b.click());
	await p.waitForSelector('.st-end', { timeout: 10000 });
	const end = await p.$eval('.st-end', (b) => b.textContent);
	ok(new RegExp(r.questions.length + ' of ' + r.questions.length).test(end) && (/\+15 XP/.test(end) || !/XP/.test(end)) && /1-day reading streak/.test(end), 'phone: result shows all right, +15 XP (unless the daily XP cap is reached) and the streak (' + end.trim() + ')');
	await p.shot('3-check');
	const twice = await p.api('readers/done', { id, right: 4, total: 4 });
	ok(twice.xp === 0, 'phone: XP is given only once per story');

	// Personal story (AI, mocked) for an admin tester.
	await p.tap('#stmore');
	await p.waitForSelector('#st [data-mine]', { timeout: 15000 });
	await p.tap('[data-mine]');
	await p.waitForSelector('.scan-text p', { timeout: 30000 });
	ok(!!(await p.$('.pill.open')), 'phone: personal story is marked "Made for you"');
	const prompt = await text('/?readers_last=1');
	ok(/brave|coach|nervous/.test(prompt) && /never as a list/.test(prompt) && /Never use real people/.test(prompt), 'phone: personal prompt weaves in Learning words; no real people');

	// The written series: chapter 1, then chapter 2 waits for tomorrow (admins may skip ahead).
	const c1 = await p.api('readers/next', { cat: 'football', format: 'series' });
	ok(c1.chapter === 1 && /Bulaq Boys – Chapter 1/.test(c1.title), 'phone: the Bulaq Boys series starts at chapter 1 (' + c1.title + ')');
	await p.api('readers/done', { id: c1.id, right: 4, total: 4 });
	const home = await p.api('readers');
	const weekend = [5, 6].includes(new Date(new Date().toLocaleString('en-US', { timeZone: 'Africa/Cairo' })).getDay()); // 3 episodes a day on Friday and Saturday
	ok(home.series && home.series.next === 2 && home.series.tomorrow === !weekend, 'phone: chapter 2 ' + (weekend ? 'is open today (weekend)' : 'is waiting for tomorrow'));
	const n1 = await calls();
	const c2 = await p.api('readers/next', { series: c1.series });
	ok(c2.chapter === 2 && c2.series === c1.id && (await calls()) === n1, 'phone: chapter 2 comes from the library, same series, no AI');

	// Library and Home.
	await p.open('stories');
	await p.waitForSelector('.sg-item', { timeout: 15000 });
	ok((await p.$$('.sg-item')).length === 4, 'phone: My stories lists the 4 opened stories');
	ok(/🔥 1-day reading streak/.test(await p.$eval('#st', (b) => b.innerText)), 'phone: reading streak on Stories');
	await p.shot('4-library');
	await p.open('today');
	await p.waitForSelector('#story:not([hidden])', { timeout: 15000 });
	ok(/📖/.test(await p.$eval('#story', (b) => b.innerText)), 'phone: Home shows the story card');
	ok(await p.noScroll(), 'phone: no sideways scroll (Home)');
	await p.shot('5-home');
	ok(!p.errs.length, 'phone: no page errors ' + p.errs.join(' / '));
	await p.close();
}

async function computer(br) {
	// A student (not admin, not a personal-story tester) at B2: everyone has stories; no personal card; B1 is the closest level, then A2.
	await text('/?readers_level=8&lv=4');
	const p = await page(br, 'computer', 8);
	const h = await p.api('readers');
	ok(h.today && h.personal.why === 'off', 'computer: a regular student has stories but no personal (AI) stories');
	const n0 = await calls();
	const seen = [];
	for (let i = 0; i < 4; i++) { // football: B1 story, A2 story, A2 series chapter 1, then another topic
		const r = await p.api('readers/next', { cat: 'football' });
		seen.push(r.cat.id + ' ' + r.level);
		await p.api('readers/done', { id: r.id, right: 1, total: 4 });
	}
	console.log('     user 8 (B2) got:', seen.join(' | '));
	ok(seen.slice(0, 3).every((s) => /^football /.test(s)) && /B1$/.test(seen[0]) && seen.every((s) => / (B1|A2)$/.test(s)), 'computer: football stories first, nearest level (B1, then A2)');
	ok(!/^football /.test(seen[3]) && (await calls()) === n0, 'computer: when football runs out, another topic (no AI call)');
	await p.open('story-' + h.today.id);
	await p.waitForSelector('.scan-text p', { timeout: 15000 });
	ok(!!(await p.$('#stq b, #stdone')), 'computer: a story opens from its address');
	await p.shot('1-story');
	const skip = await p.api('readers/skip', { cat: 'business' });
	ok(skip.ok && !(await p.api('readers')).cats.some((c) => c.id === 'business'), 'computer: 👎 Not this topic hides it from the topics');
	ok((await p.api('readers/hide', { id: h.today.id })).code === 'admin', 'computer: students can\'t hide stories');
	ok(!p.errs.length, 'computer: no page errors ' + p.errs.join(' / '));
	await p.close();
	// Admin page: Video quizzes → Stories.
	const a = await br.newPage();
	await a.goto(B + '/?readers_admin=1', { waitUntil: 'networkidle2' });
	const rows = await a.$$eval('table.widefat tbody tr', (x) => x.length).catch(() => 0);
	ok(rows >= 104, 'admin: the Stories page lists the library (' + rows + ' rows)');
	await a.screenshot({ path: `${OUT}/admin-stories.png` });
	await a.close();
}

(async () => {
	console.log(await text('/?readers_setup=1'));
	const br = await puppeteer.launch({ headless: 'new', args: ['--mute-audio', '--autoplay-policy=user-gesture-required'] });
	try { await phone(br); await computer(br); } catch (e) { ok(false, 'crashed: ' + e.message); }
	await br.close();
	console.log(bad ? bad + ' FAILED' : 'ALL OK');
	process.exit(bad ? 1 : 0);
})();
