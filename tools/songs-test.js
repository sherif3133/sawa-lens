/* Silent test of "My songs" (beta) in /app with fake Claude + Lyria answers (local mu-plugin): make a song,
   wait for it, open it, play the fill-the-gaps game. Muted, audio stubbed. Run from language-lens/tools:
   node ../../sawa-lens/tools/songs-test.js <outdir> */
const puppeteer = require(require.resolve('puppeteer', { paths: [process.cwd()] }));
const B = 'http://127.0.0.1:9410', OUT = process.argv[2] || '.';
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
let fails = 0;
const ok = (c, l, x) => { console.log((c ? 'PASS ' : 'FAIL ') + l + (x !== undefined ? ' → ' + JSON.stringify(x) : '')); if (!c) fails++; };
(async () => {
  const br = await puppeteer.launch({ headless: true, args: ['--mute-audio'] });
  const p = await br.newPage(); const errs = []; p.on('pageerror', (e) => errs.push(e.message));
  await p.evaluateOnNewDocument(() => { HTMLMediaElement.prototype.play = function () { return Promise.resolve(); }; try { window.speechSynthesis.speak = () => {}; } catch (e) {} });
  await p.setViewport({ width: 390, height: 844, isMobile: true, hasTouch: true });
  await p.goto(B + '/app/?as=1&t=' + Date.now() + '#learn', { waitUntil: 'networkidle2', timeout: 120000 }); await sleep(2500);
  ok(await p.evaluate(() => !!document.querySelector('[data-t=songs]')), 'Learn shows the My songs tile');
  await p.evaluate(() => document.querySelector('[data-t=songs]').click());
  await p.waitForSelector('#sggo', { timeout: 30000 }).catch(() => {});
  ok(await p.evaluate(() => !!document.querySelector('#sggo')), 'song is unlocked: Make my song');
  await p.evaluate(() => { document.querySelector('[name=sgs][value=acapella]').checked = true; document.querySelector('#sggo').click(); });
  await p.waitForSelector('.sg-bar', { timeout: 20000 }).catch(() => {});
  ok(await p.evaluate(() => !!document.querySelector('.sg-bar')), 'shows "being made"');
  await p.screenshot({ path: OUT + '/songs-making.png' });
  await p.waitForSelector('.sg-item:not([disabled])', { timeout: 120000 }).catch(() => {});
  const item = await p.evaluate(() => { const b = document.querySelector('.sg-item:not([disabled])'); return b ? b.innerText.replace(/\s+/g, ' ') : ''; });
  ok(!!item, 'the song is ready', item);
  await p.evaluate(() => document.querySelector('.sg-item:not([disabled])').click()); await sleep(2500);
  ok(await p.evaluate(() => !!document.querySelector('audio[src*="sawa-lens-songs"]')), 'song page has the player');
  ok(await p.evaluate(() => document.querySelectorAll('#sgl .sw.tg').length > 3), 'new words are marked', await p.evaluate(() => [...document.querySelectorAll('#sgl .sw.tg')].map((x) => x.textContent).slice(0, 6)));
  await p.screenshot({ path: OUT + '/songs-page.png', fullPage: true });
  await p.evaluate(() => document.querySelector('[data-sm=game]').click()); await sleep(500);
  const n = await p.evaluate(() => document.querySelectorAll('.sg-gap').length);
  ok(n > 3, 'fill-the-gaps has blanks', n);
  for (let i = 0; i < n; i++) { await p.evaluate(() => { const k = document.querySelector('[data-bk]:not([disabled])'); if (k) k.click(); }); await sleep(120); }
  await sleep(2000);
  ok(/of \d+ right/.test(await p.evaluate(() => document.querySelector('#sgsc').textContent)), 'score shown', await p.evaluate(() => document.querySelector('#sgsc').textContent));
  await p.screenshot({ path: OUT + '/songs-game.png', fullPage: true });
  console.log('errors:', errs.length ? JSON.stringify(errs.slice(0, 3)) : 'none');
  await br.close();
  console.log(fails ? fails + ' FAILED' : 'ALL PASS');
})().catch((e) => { console.error('FAILED', e.message); process.exit(1); });
