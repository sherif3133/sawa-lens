/* Read aloud like a player (Read a text, Scan, Stories), on a 375px phone, silent: a fake speech engine records
   what would be spoken and can report "now at this word". Checks: play, pause and resume from the last word, tap a
   word to read from it with a small meaning pop-up next to it, a word's 🔊 pauses the reading, stop, and words with
   apostrophes keep the right place. Needs the local Playground. Run from language-lens/tools (for puppeteer):
   node ../../sawa-lens/tools/player-test.js <outdir> */
const puppeteer = require(require.resolve('puppeteer', { paths: [process.cwd()] }));
const B = 'http://127.0.0.1:9410';
const OUT = process.argv[2] || '.';
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
let bad = 0;
const ok = (c, m) => { console.log((c ? 'ok   ' : 'FAIL ') + m); if (!c) bad++; };
const TEXT = 'One two three. Four five six.\n\nI don\'t know why. Seven eight nine.';

(async () => {
	const br = await puppeteer.launch({ headless: 'new', args: ['--mute-audio'] });
	const p = await br.newPage();
	await p.setViewport({ width: 375, height: 812, isMobile: true, hasTouch: true });
	await p.evaluateOnNewDocument(() => {
		window.__utt = [];
		Object.defineProperty(window, 'speechSynthesis', { configurable: true, value: { speak(u) { window.__utt.push(u); }, cancel() {}, pause() {}, resume() {}, getVoices: () => [], onvoiceschanged: null } });
		Object.defineProperty(window, 'SpeechSynthesisUtterance', { configurable: true, value: function (t) { this.text = t; } });
		HTMLMediaElement.prototype.play = () => Promise.resolve();
	});
	const errs = [];
	p.on('pageerror', (e) => errs.push(e.message));
	const last = () => p.evaluate(() => (window.__utt[window.__utt.length - 1] || {}).text || '');
	const bar = () => p.evaluate(() => { const b = document.getElementById('rdbar'); return b ? b.innerText.replace(/\s+/g, ' ') : ''; });
	const tap = async (sel) => { await p.waitForSelector(sel, { timeout: 15000 }); await p.$eval(sel, (b) => b.click()); await sleep(300); };

	await p.goto(B + '/app/?as=1&p=' + Date.now() + '#read', { waitUntil: 'networkidle2' });
	await p.waitForSelector('#rtext', { timeout: 20000 });
	await p.$eval('#rtext', (t, v) => { t.value = v; t.dispatchEvent(new Event('input')); }, TEXT);
	await tap('#rgo');
	await p.waitForSelector('.scan-text .sw', { timeout: 20000 });

	// Words keep their place in the paragraph, even after "don't".
	const offs = await p.$$eval('.scan-text p[data-p="1"] .sw', (x) => x.map((s) => s.textContent + '@' + s.dataset.o));
	ok(offs.includes('don\'t@2') && offs.includes('Seven@18'), 'words know their place, also after an apostrophe (' + offs.slice(0, 6).join(' ') + ')');

	await tap('#readall');
	ok(/^One two three/.test(await last()) && /Pause/.test(await p.$eval('#readall', (b) => b.textContent)), 'Listen starts at the beginning; the button becomes Pause');
	ok(/Reading/.test(await bar()), 'a player bar shows while reading (' + await bar() + ')');
	await p.evaluate(() => { const u = window.__utt[window.__utt.length - 1]; u.onboundary({ name: 'word', charIndex: 8 }); }); // the voice reached "three"
	ok(await p.$eval('.scan-text .sw.now', (s) => s.textContent) === 'three', 'the word being read is highlighted');
	await tap('#rdbar .rd-pp');
	ok(/Paused/.test(await bar()) && /Resume/.test(await p.$eval('#readall', (b) => b.textContent)), 'pause: the bar says Paused, the button says Resume');
	await tap('#rdbar .rd-pp');
	ok(/^three\. Four/.test(await last()), 'resume continues from the last word ("' + (await last()).slice(0, 20) + '…")');
	await p.evaluate(() => { const u = window.__utt[window.__utt.length - 1]; u.onend(); }); // paragraph 1 ends
	ok(/^I don't know/.test(await last()), 'it goes on to the next paragraph by itself');

	// Tap a word: the reading jumps there, and its meaning shows right next to it.
	await tap('.scan-text p[data-p="1"] .sw[data-o="18"]');
	ok(/^Seven eight nine/.test(await last()), 'tapping "Seven" reads from that word');
	const pop = await p.evaluate(() => { const w = document.querySelector('.wpop'), s = document.querySelector('.scan-text .sw.on'); if (!w) return null; const a = w.getBoundingClientRect(), b = s.getBoundingClientRect(); return { word: w.querySelector('b').textContent, near: Math.min(Math.abs(a.top - b.bottom), Math.abs(b.top - a.bottom)) < 20, inside: a.left >= 15 && a.right <= innerWidth - 15 }; });
	ok(pop && pop.word === 'seven' && pop.near && pop.inside, 'a small pop-up shows "seven" right next to the word, inside the screen');
	await p.screenshot({ path: OUT + '/player-pop.png' });
	await tap('.wpop [data-wsay]');
	ok(await last() === 'seven' && /Paused/.test(await bar()), 'the word\'s 🔊 says the word and pauses the reading');
	await tap('.wpop [data-wx]');
	ok(!(await p.$('.wpop')), '✕ closes the pop-up');
	await tap('#rdbar .rd-x');
	ok(!(await bar()) && /Listen/.test(await p.$eval('#readall', (b) => b.textContent)), 'stop: the bar goes away, the button says Listen again');
	ok(await p.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'no sideways scroll');
	ok(!errs.length, 'no page errors ' + errs.join(' / '));
	await br.close();
	console.log(bad ? bad + ' FAILED' : 'ALL OK');
	process.exit(bad ? 1 : 0);
})();
