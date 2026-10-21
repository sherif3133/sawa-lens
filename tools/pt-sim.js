/* Simulates placement-test takers against a local site: node tools/pt-sim.js http://127.0.0.1:9410 strong|weak */
const [base, kind] = process.argv.slice(2);
const bank = require('../assets/placement-vocab.json');
const PSEUDO = ['brastic', 'plinder', 'morvish', 'tralent', 'glimber', 'spaunt', 'crendle', 'fruskle', 'dorpish', 'quillate', 'sneavy', 'mallish', 'pronter', 'gaspen', 'flurdish', 'cobblet', 'harpel', 'vorine', 'starnish', 'plosent', 'trindle', 'bleckish', 'grommage', 'snurtle', 'pelfrey', 'contrapt', 'mundial', 'skelving', 'abrance', 'perlance'];
const SARA = 'My name is Sara and I live in Cairo. Every morning I wake up early and drink a cup of tea with my family. Then I take the bus to work because the traffic is very heavy in the city. In the evening I like to read books or watch films with my friends.';
const band = {}; const ar = {};
bank.bands.forEach((b, i) => b.items.forEach((x) => { band[x.w] = i; ar[x.w] = x.ar; }));
const strong = kind === 'strong';
const post = (p, body) => fetch(base + '/?rest_route=/sawa-lens/v1/pt/' + p, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) }).then((r) => r.json());
(async () => {
	let s = await post('start', {});
	const key = s.key;
	const path = [];
	while (!s.done) {
		let a;
		if (s.section === 'vocab') a = s.words.map((w) => PSEUDO.includes(w) ? (strong ? 0 : 1) : (strong ? 1 : (band[w] <= 1 ? 1 : 0)));
		else if (s.section === 'meaning') a = s.items.map((it) => strong ? it.options.indexOf(ar[it.w]) : 0);
		else if (s.section === 'grammar') { a = strong ? 1 : (Math.random() < 0.3 ? 1 : 0); path.push('g' + s.i); }
		else if (s.section === 'reading') {
			const words = SARA.split(/\s+/); // fill the gaps from the known text
			const gaps = s.parts_text.filter((p) => p.keep != null);
			let wi = 0; a = gaps.map((g) => { const full = SARA.match(new RegExp('\b' + g.keep + '[a-z]*', 'i')); return strong && full ? full[0].slice(g.keep.length) : 'x'; });
		}
		else if (s.section === 'listening') a = strong ? s.say : 'i the';
		else if (s.section === 'writing') a = {};
		else { console.log('unknown', s); break; }
		s = await post('answer', { key, answer: a });
		if (s.code) { console.log('ERR', s.message); break; }
	}
	console.log(kind, '→', JSON.stringify(s.result));
})();
