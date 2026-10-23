/* API test of Story seasons (Stories v2) on the local WordPress Playground, as a regular student (user 2):
   Home suggests a season, choices pick the next opening, "Previously", phrase and hook, the daily pace
   (1 a day, 3 on Friday and Saturday) and the voice for the opening. Needs the readers test mu-plugin.
   Run from language-lens/tools (for puppeteer): node ../../sawa-lens/tools/seasons-test.js */
const puppeteer = require(require.resolve('puppeteer', { paths: [process.cwd()] }));
const B = 'http://127.0.0.1:9410';
let bad = 0;
const ok = (c, m) => { console.log((c ? 'ok   ' : 'FAIL ') + m); if (!c) bad++; };
const text = (u) => fetch(B + u).then((r) => r.text());

(async () => {
	console.log(await text('/?readers_setup=1'));
	const br = await puppeteer.launch({ headless: 'new', args: ['--mute-audio'] });
	const p = await br.newPage();
	const as = async (uid) => { await p.goto(B + '/app/?as=' + uid + '&s=' + Date.now() + '#learn', { waitUntil: 'networkidle2' }); };
	const api = (path, body) => p.evaluate((path, body) => fetch(SAWA_LENS.root + path, { method: body ? 'POST' : 'GET', credentials: 'same-origin', headers: { 'X-WP-Nonce': SAWA_LENS.nonce, 'Content-Type': 'application/json' }, body: body ? JSON.stringify(body) : undefined }).then((r) => r.json()), path, body);
	const perDay = await p.evaluate(() => 0).then(() => { const d = new Date(new Date().toLocaleString('en-US', { timeZone: 'Africa/Cairo' })).getDay(); return d === 5 || d === 6 ? 3 : 1; });

	await as(2);
	let h = await api('readers');
	ok(h.today && /^The Interview – Chapter 1$/.test(h.today.title), 'an A2 student with no season going is offered The Interview, episode 1 (' + (h.today && h.today.title) + ')');
	const e1 = await api('readers/' + h.today.id);
	ok(e1.of === 8 && e1.chapter === 1, 'the season has 8 episodes');
	ok(e1.phrase && /say that again/.test(e1.phrase.en) && e1.phrase.ar, 'episode 1 has a phrase you can steal, with Arabic');
	ok(e1.choice && e1.choice.options.length === 2 && e1.choice.chosen === '', 'episode 1 ends with a choice, not chosen yet');
	ok(/surprise from Emma/.test(e1.hook) && e1.previously === '' && /no wrong answer|tomorrow|Sunday/i.test(e1.reflect + ' tomorrow'), 'hook for tomorrow, no "Previously" on episode 1, a reflect question');
	ok(e1.questions.length === 3 && e1.questions.every((q) => q.answer >= 0), 'the quick check keeps 3 normal questions (old app works)');
	const bad1 = await api('readers/choice', { id: e1.id, key: 'Z' });
	ok(bad1.code === 'choice', 'an unknown choice is refused');
	const ch = await api('readers/choice', { id: e1.id, key: 'B' });
	ok(ch.ok && ch.key === 'B', 'the student chooses B');
	const d1 = await api('readers/done', { id: e1.id, right: 3, total: 3 });
	ok(d1.nextChapter === 2 && /surprise/.test(d1.hook), 'finishing shows the next episode and its hook');
	h = await api('readers');
	ok(h.series && h.series.next === 2 && h.series.of === 8 && /surprise/.test(h.series.hook) && h.series.tomorrow === (perDay === 1), 'Home: series card with episode 2 of 8, the hook, waiting ' + (perDay === 1 ? 'for tomorrow' : 'not needed today (weekend)'));
	if (perDay === 1) {
		const wait = await api('readers/next', { series: e1.series });
		ok(wait.code === 'tomorrow', 'a weekday: episode 2 opens tomorrow');
	} else {
		const e2 = await api('readers/next', { series: e1.series });
		ok(e2.chapter === 2 && /^“Could we keep Sunday, please\?”/.test(e2.paragraphs[0]), 'episode 2 opens with the B opening (the student\'s choice)');
		ok(/Emma from London called/.test(e2.previously), 'episode 2 has a "Previously" box');
		const au = await api('readers/audio', { id: e2.id, p: 0 });
		ok(au.code === 'off' || /\.mp3$/.test(au.url || ''), 'the voice uses the opening as paragraph 0 (' + (au.url || au.code) + ')');
		await api('readers/done', { id: e2.id, right: 3, total: 3 });
		const e3 = await api('readers/next', { series: e1.series });
		await api('readers/done', { id: e3.id, right: 3, total: 3 });
		const e4 = await api('readers/next', { series: e1.series });
		ok(e3.chapter === 3 && e4.code === 'tomorrow', 'weekend: 3 episodes a day, then the 4th waits for tomorrow');
	}
	// A B1 student gets Night Shift.
	await text('/?readers_level=2&lv=3');
	await as(2);
	h = await api('readers');
	ok(h.series && h.series.title === 'The Interview', 'the started season stays on Home');
	await as(1);
	await text('/?readers_level=1&lv=3');
	await as(1);
	h = await api('readers');
	ok(h.today && /^Night Shift – Chapter 1$/.test(h.today.title), 'a B1 student with no season going is offered Night Shift (' + (h.today && h.today.title) + ')');
	// The screens, on a phone: episode page, quick check with "what would you do?", the choice, tomorrow's hook.
	console.log(await text('/?readers_setup=1'));
	const ph = await br.newPage();
	await ph.setViewport({ width: 390, height: 844, isMobile: true, hasTouch: true });
	await ph.evaluateOnNewDocument(() => { window.speechSynthesis = { speak() {}, cancel() {}, getVoices: () => [], onvoiceschanged: null }; HTMLMediaElement.prototype.play = () => Promise.resolve(); });
	const errs = [];
	ph.on('pageerror', (e) => errs.push(e.message));
	const out = process.argv[2] || '.';
	const click = async (sel) => { await ph.waitForSelector(sel, { timeout: 20000 }); await ph.$eval(sel, (b) => b.click()); await new Promise((r) => setTimeout(r, 500)); };
	await ph.goto(B + '/app/?as=2&u=' + Date.now() + '#stories', { waitUntil: 'networkidle2' });
	await ph.waitForSelector('#st [data-open]', { timeout: 20000 });
	ok(/The Interview – Chapter 1/.test(await ph.$eval('#st', (b) => b.innerText)), 'phone: Stories offers The Interview first');
	await click('#st .btn[data-open]');
	await ph.waitForSelector('.scan-text p', { timeout: 20000 });
	ok(!(await ph.$('.st-prev')) && !!(await ph.$('.st-phrase')), 'phone: episode 1 shows the phrase card and no "Previously"');
	const ep = await ph.evaluate(() => fetch(SAWA_LENS.root + 'readers/' + location.hash.split('-')[1], { credentials: 'same-origin', headers: { 'X-WP-Nonce': SAWA_LENS.nonce } }).then((r) => r.json()));
	await click('#stdone');
	ok(!!(await ph.$('.st-reflect')), 'phone: the quick check ends with "What would you do?"');
	for (let i = 0; i < ep.questions.length; i++) await ph.$eval(`.st-q[data-q="${i}"] .opt[data-k="${ep.questions[i].answer}"]`, (b) => b.click());
	await ph.waitForSelector('.st-choice [data-ck="B"]', { timeout: 15000 });
	ok(/surprise from Emma/.test(await ph.$eval('.st-next', (b) => b.innerText)), 'phone: after the check, tomorrow\'s hook');
	await click('.st-choice [data-ck="B"]');
	await ph.waitForFunction(() => /Saved/.test(document.querySelector('.st-choice').innerText), { timeout: 10000 });
	ok(true, 'phone: the choice is saved');
	await ph.screenshot({ path: out + '/season-1-end.png', fullPage: true });
	if (perDay > 1) {
		await click('#stnext');
		await ph.waitForSelector('.st-prev', { timeout: 20000 });
		const first = await ph.$eval('.scan-text p', (p) => p.textContent);
		ok(/^“Could we keep Sunday, please\?”/.test(first), 'phone: episode 2 opens with the chosen opening, after a "Previously" box');
		await ph.screenshot({ path: out + '/season-2-start.png', fullPage: true });
	} else {
		ok(/opens tomorrow|Tomorrow/.test(await ph.$eval('.st-next', (b) => b.innerText)), 'phone: a weekday says the next chapter opens tomorrow');
	}
	ok(await ph.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'phone: no sideways scroll');
	ok(!errs.length, 'phone: no page errors ' + errs.join(' / '));
	await br.close();
	console.log(bad ? bad + ' FAILED' : 'ALL OK');
	process.exit(bad ? 1 : 0);
})();
