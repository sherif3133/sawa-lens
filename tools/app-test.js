/* End-to-end test of the Language Lens web app against the local WordPress Playground (mocked AI).
   Run from language-lens/tools (for puppeteer): node ../../sawa-lens/tools/app-test.js <outdir> */
const puppeteer = require(require.resolve('puppeteer', { paths: [process.cwd()] }));
const B = 'http://127.0.0.1:9410';
const OUT = process.argv[2] || '.';
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

(async () => {
	const br = await puppeteer.launch({ headless: true, args: ['--no-sandbox'] });
	const p = await br.newPage();
	await p.setViewport({ width: 390, height: 844, deviceScaleFactor: 1, isMobile: true, hasTouch: true });
	const errs = [];
	p.on('pageerror', (e) => errs.push(e.message));
	p.on('console', (m) => m.type() === 'error' && errs.push(m.text()));
	p.on('dialog', (d) => d.accept());
	await p.setCookie({ name: 'as_user', value: '2', url: B });
	const shot = (n) => p.screenshot({ path: `${OUT}/${n}.png`, fullPage: true });
	const text = () => p.evaluate(() => document.querySelector('main').innerText);
	const click = async (sel) => { await p.waitForSelector(sel, { timeout: 15000 }); await p.click(sel); await sleep(400); };

	await p.goto(B + '/lens/', { waitUntil: 'networkidle0' });
	await p.waitForSelector('.tree');
	await shot('1-tree');
	console.log('tree nodes:', await p.$$eval('.node', (n) => n.length), '| locked:', await p.$$eval('.node.locked', (n) => n.length));

	// Skill detail + practice of "be"
	await click('.node[data-skill="be"]');
	await shot('2-skill');
	await click('[data-toggle-ar]');
	console.log('arabic shown:', await p.$eval('[data-ar]', (e) => !e.hidden));
	await click('#go');
	await p.waitForSelector('.dots', { timeout: 20000 });
	for (let k = 0; k < 20; k++) {
		const kind = await p.evaluate(() => (document.querySelector('.spot') ? 'spot' : document.querySelector('#ans') ? 'fix' : document.querySelector('.opt') ? 'opt' : 'done'));
		if (kind === 'done') break;
		if (kind === 'spot') {
			await p.$$eval('.spot .w[data-t]', (ws) => ws.forEach((w) => w.click()));
			if (k === 0) await shot('3-spot');
			await click('#chk');
		} else if (kind === 'fix') {
			await p.evaluate(() => {
				const q = document.querySelector('.q').textContent;
				const fixes = { 'My father very busy.': 'My father is very busy.', 'They at the mall now.': 'They are at the mall now.' };
				document.querySelector('#ans').value = fixes[q] || q;
			});
			await click('#chk');
		} else {
			// pick the right option by trying the first, fine for the test: mostly answer correctly via known answers
			await p.evaluate(() => {
				const map = { 'Ahmed': 'is', 'The shops': 'are', 'I ': 'am' };
				const q = document.querySelector('.q') ? document.querySelector('.q').textContent : '';
				const want = Object.keys(map).find((k) => q.indexOf(k) === 0);
				const btns = [...document.querySelectorAll('.opt')];
				(btns.find((b) => want && b.textContent === map[want]) || btns[0]).click();
			});
			await sleep(300);
		}
		if (k === 1) await shot('4-feedback');
		await click('#nx');
	}
	await p.waitForFunction(() => /\d+ \/ \d+/.test(document.querySelector('main').innerText), { timeout: 15000 });
	await shot('5-result');
	console.log('result:', (await text()).split('\n').slice(0, 4).join(' | '));

	// Writing check
	await click('[data-nav="write"]');
	await p.type('#txt', "yesterday i go to the market. my brother very tall. Give me the book. I dont know.");
	await click('#chk');
	await p.waitForSelector('.errs', { timeout: 20000 });
	await shot('6-write');
	console.log('errors:', await p.$$eval('.errs li', (l) => l.length), '| locked explanations:', await p.$$eval('.lock', (l) => l.length), '| banners:', await p.$$eval('#res .banner', (b) => b.map((x) => x.textContent).join(' / ')));
	await click('[data-peek]');
	console.log('peek shows why:', await p.$$eval('[data-why]:not([hidden])', (l) => l.length));

	// Same mistakes twice more → errors open locked skills early
	for (let r = 0; r < 2; r++) {
		await click('[data-nav="write"]');
		await p.evaluate(() => { document.querySelector('#txt').value = 'yesterday i go to the market. Give me the book. I dont know.'; });
		await click('#chk');
		await p.waitForSelector('.errs', { timeout: 20000 });
	}
	console.log('after 3 checks:', await p.$$eval('#res .banner', (b) => b.map((x) => x.textContent).join(' / ')), '| locked now:', await p.$$eval('.lock', (l) => l.length));

	await click('[data-nav="progress"]');
	await p.waitForSelector('.spark', { timeout: 15000 });
	await shot('7-progress');
	console.log('trend rows:', await p.$$eval('.trend', (l) => l.length));

	await click('[data-nav="skills"]');
	await p.waitForSelector('.tree');
	await shot('8-tree-after');
	console.log('next up:', await p.$$eval('.next .card b', (l) => l.map((x) => x.textContent).join(', ')));

	// Pronunciation practice
	await p.evaluate(() => [...document.querySelectorAll('[data-track]')].find((b) => b.dataset.track === 'pron').click());
	await click('.node[data-skill="p"]');
	await click('#go');
	await p.waitForSelector('.say', { timeout: 20000 });
	await shot('9-hear');
	console.log('JS errors:', errs.length ? errs : 'none');
	await br.close();
})().catch((e) => { console.error('FAILED', e.message); process.exit(1); });
