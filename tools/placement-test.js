/* Placement scoring + Lens vocabulary profile checks (education/placement-test-spec.md, Part 4 steps 1–2).
   Needs a local WordPress Playground with sawa-lens and the test helper tools/placement-test-mu.php (never on the live site).
   Start one in Git Bash from the repo root (MSYS_NO_PATHCONV=1 keeps the mount paths):
     MSYS_NO_PATHCONV=1 npx @wp-playground/cli@3.1.56 server --port=9412 --login \
       --mount-dir="$PWD/sawa-lens" /wordpress/wp-content/plugins/sawa-lens \
       --mount-dir="$PWD/sawa-lens/tools" /wordpress/wp-content/mu-plugins \
       --blueprint=sawa-lens/tools/placement-test-blueprint.json
   Then: node sawa-lens/tools/placement-test.js http://127.0.0.1:9412 */
const B = (process.argv[2] || 'http://127.0.0.1:9412').replace(/\/$/, '');
const call = (p, body) => fetch(B + '/?rest_route=/sawa-pt-test/v1' + p, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body || {}) })
	.then(async (r) => { const t = await r.text(); try { return JSON.parse(t); } catch (e) { throw new Error(p + ': ' + t.slice(0, 400)); } });
let fail = 0;
const ok = (c, m) => { console.log((c ? 'ok   ' : 'FAIL ') + m); if (!c) fail++; };
const same = (a, b) => JSON.stringify(a) === JSON.stringify(b);

(async () => {
	const U = await call('/reset');
	ok(U.pt_a && U.pt_b && U.pt_c, 'test users ready');

	// 1. Scoring: same answers → same θ; vocabulary counted once (prior mean 0, SD 1.5).
	const state = { skills: { grammar: [[-1, 1, 1], [0, 1, 1], [0.5, 0, 1], [0, 1, 1], [1, 0, 1]], reading: [[0, 0.6, 4]] }, vocabTheta: -0.5, vocabSize: 2600 };
	const s1 = await call('/score', { state }), s2 = await call('/score', { state });
	ok(same(s1, s2), 'same answers give the same θ (' + s1.theta + ')');
	const noVocab = await call('/score', { state: Object.assign({}, state, { vocabTheta: undefined }) });
	const lowVocab = await call('/score', { state: Object.assign({}, state, { vocabTheta: -3 }) });
	ok(lowVocab.theta < s1.theta && s1.theta - lowVocab.theta < 1, 'the vocabulary result moves θ once, not as the prior too (' + s1.theta + ' vs ' + lowVocab.theta + ', none ' + noVocab.theta + ')');

	// 2. Borderline: within 0.25 of a cut → the lower level.
	const p1 = await call('/place', { th: -0.9 }), p2 = await call('/place', { th: -0.5 }), p3 = await call('/place', { th: 0.2 });
	ok(p1.level === 2 && p1.borderline === 'A2-B1', 'θ −0.9 → A2, borderline A2-B1');
	ok(p2.level === 3 && !p2.borderline, 'θ −0.5 → B1, not borderline');
	ok(p3.level === 3 && p3.borderline === 'B1-B2', 'θ 0.2 → B1, borderline B1-B2');

	// 3. Levels go up, never down on one test.
	const B1 = { levelNum: 3, raw: 3 };
	ok(same(await call('/decide', { prev: B1, raw: 2, th: -1.5 }), [3, 'kept']), 'one lower test: level stays B1');
	ok(same(await call('/decide', { prev: { levelNum: 3, raw: 2 }, raw: 2, th: -1.5 }), [2, 'down']), 'two lower tests in a row: level drops');
	ok(same(await call('/decide', { prev: B1, raw: 4, th: 0.3 }), [3, 'up_next']), 'just above the B2 cut: stays B1 for now');
	ok(same(await call('/decide', { prev: B1, raw: 4, th: 0.6 }), [4, 'up']), 'clearly above the cut (> 0.4): up to B2');
	ok(same(await call('/decide', { prev: { levelNum: 3, raw: 4 }, raw: 4, th: 0.3 }), [4, 'up']), 'second test in a row above: up to B2');
	ok(same(await call('/decide', { prev: { levelNum: 3 }, raw: 2, th: -1.5 }), [3, 'kept']), 'old saved results (no raw) still work');

	// 4. Whole tests as a learner: history, kept level, profile.
	const a1 = await call('/run', { uid: U.pt_a, kind: 'strong', quick: true });
	ok(a1.result && a1.result.saved, 'strong quick check saved: ' + (a1.result && a1.result.level) + ' θ ' + (a1.result && a1.result.theta));
	const a2 = await call('/run', { uid: U.pt_a, kind: 'weak', quick: true });
	ok(a2.result.note === 'kept' && a2.result.level === a1.result.level && a2.result.tested !== a1.result.level, 'one weak test: "' + a2.result.tested + '" tested, level stays ' + a2.result.level);
	let m = await call('/meta', { uid: U.pt_a });
	ok(m.level === a1.result.levelNum, 'stored level unchanged after one lower test');
	const a3 = await call('/run', { uid: U.pt_a, kind: 'weak', quick: true });
	ok(a3.result.note === 'down', 'second weak test: level drops to ' + a3.result.level);
	m = await call('/meta', { uid: U.pt_a });
	ok(Array.isArray(m.history) && m.history.length === 3 && m.history[0].form === 'quick' && 'theta' in m.history[0] && m.history[0].vocab.bands.length === 6, 'sawa_lens_placement_history keeps every test');
	ok(m.profile.source === 'test' && m.profile.test === 'quick', 'the Lens profile comes from the test');

	// 5. 90-day exposure: a retest gets new items; after 90 days old items may come back.
	const b1 = await call('/run', { uid: U.pt_b, kind: 'strong', quick: true });
	const b2 = await call('/run', { uid: U.pt_b, kind: 'strong', quick: true });
	ok(b2.seen.length >= b1.used.length && b1.used.every((id) => b2.seen.includes(id)), 'the retest knows the ' + b1.used.length + ' items seen before');
	ok(!b2.used.some((id) => b1.used.includes(id)), 'no item repeats within 90 days');
	await call('/age', { uid: U.pt_b, days: 91 });
	const b3 = await call('/run', { uid: U.pt_b, kind: 'strong', quick: true });
	ok(!b1.used.some((id) => b3.seen.includes(id)), 'after 90 days the old items are allowed again');

	// 6. /sync: old fields keep working, vocabProfile is new.
	const C = U.pt_c;
	let r = await call('/sync', { uid: C, body: { since: 0, level: 0, words: [], sentences: [] } });
	ok('level' in r && 'vocab' in r && 'words' in r && 'cursor' in r, 'old /sync fields still there');
	ok(r.vocabProfile && r.vocabProfile.source === 'level' && same(r.vocabProfile.bands, [0.95, 0.6, 0.3, 0.12, 0.05, 0.02]), 'no test, no level: the A2 profile');
	r = await call('/sync', { uid: C, body: { since: 0, level: 3 } });
	ok(r.vocabProfile.source === 'level' && r.vocabProfile.bands[1] === 0.85, 'an old extension picking B1: the B1 profile');
	const now = Date.now();
	r = await call('/sync', { uid: C, body: { since: 0, vocab: { date: '2026-10-23', estimate: 2400, bands: [0.95, 0.75, 0.8, 0.3, 0.1], falseAlarms: 0, t: now } } });
	ok(r.vocabProfile.source === 'test' && r.vocabProfile.test === 'lens' && r.vocabProfile.bands[2] === 0.75 && r.vocabProfile.bands.length === 6, 'the extension\'s level check sets a smoothed profile ' + JSON.stringify(r.vocabProfile.bands));
	r = await call('/sync', { uid: C, body: { since: 0, level: 2, levelT: now + 5 } });
	ok(r.vocabProfile.source === 'level', 'a level picked by hand after the test wins');
	const full = await call('/run', { uid: C, kind: 'strong', quick: false });
	ok(full.result && full.result.saved && !full.result.quick, 'full test as a learner: ' + full.result.level);
	r = await call('/sync', { uid: C, body: { since: 0, vocab: { date: '2026-10-23', estimate: 500, bands: [0.4, 0.1, 0, 0, 0], falseAlarms: 0, t: Date.now() + 1000 } } });
	ok(r.vocabProfile.test === 'placement' && r.vocab.src === 'placement', 'the extension\'s check never replaces a full test');
	const q = await call('/run', { uid: C, kind: 'weak', quick: true });
	m = await call('/meta', { uid: C });
	ok(!q.result.saved && m.placement.quick === false && m.profile.test === 'placement', 'a quick check never replaces a full test');

	console.log(fail ? fail + ' FAILED' : 'all passed');
	process.exit(fail ? 1 : 0);
})().catch((e) => { console.error(e.message); process.exit(1); });
