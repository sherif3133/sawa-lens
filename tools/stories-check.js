/* Checks the written story library (data/stories/*.json): valid JSON, known topics, unique slugs,
   new words that really appear in the text, valid quick-check answers, and lengths per level.
   node tools/stories-check.js   (from sawa-lens) */
const fs = require('fs');
const path = require('path');
const dir = path.join(__dirname, '..', 'data', 'stories');
const cats = new Set(require('../data/interests.json').categories.map((c) => c.id));
const seen = new Set();
const per = {};
let bad = 0, n = 0;
const warn = (m) => { console.log('FAIL ' + m); bad++; };
for (const f of fs.readdirSync(dir).filter((x) => x.endsWith('.json'))) {
	let list;
	try { list = JSON.parse(fs.readFileSync(path.join(dir, f), 'utf8')); } catch (e) { warn(f + ': ' + e.message); continue; }
	for (const s of list) {
		n++;
		const id = f + ' ' + s.slug;
		if (seen.has(s.slug)) warn('duplicate slug ' + id);
		seen.add(s.slug);
		if (!cats.has(s.cat)) warn('unknown topic ' + id + ' ' + s.cat);
		if (![1, 2, 3, 4, 5].includes(s.level)) warn('bad level ' + id);
		if (!['story', 'explainer', 'series'].includes(s.format)) warn('bad format ' + id);
		if (s.format === 'series' && !(s.series && s.chapter)) warn('series needs series + chapter ' + id);
		if (s.choice && !((s.choice.options || []).length >= 2 && s.choice.options.every((o) => o.key && o.label))) warn('choice needs 2+ options with key and label ' + id);
		if (s.openings && s.chapter > 1) { const prev = list.find((x) => x.series === s.series && x.chapter === s.chapter - 1); const keys = prev && prev.choice ? prev.choice.options.map((o) => o.key) : []; if (!keys.length || keys.some((k) => !s.openings[k])) warn('openings must match the previous episode choice keys ' + id); }
		const text = (s.paragraphs || []).concat(Object.values(s.openings || {})).join(' '); // the choice-dependent opening counts too
		const words = text.split(/\s+/).filter(Boolean).length;
		if (words < 140) warn('too short (' + words + ' words) ' + id);
		for (const t of s.targets || []) {
			const w = t.word.toLowerCase().split(' ')[0].replace(/(e|y)$/, '');
			if (!new RegExp('\\b' + w, 'i').test(text)) warn('new word not in the text: ' + t.word + ' · ' + id);
			if (!t.en || !t.ar) warn('new word without meanings: ' + t.word + ' · ' + id);
		}
		if ((s.questions || []).length < 3) warn('fewer than 3 questions ' + id);
		for (const q of s.questions || []) if (!(q.answer >= 0 && q.answer < q.options.length)) warn('bad answer ' + id + ' · ' + q.q);
		const k = s.cat + ' L' + s.level;
		per[k] = (per[k] || 0) + 1;
	}
}
const missing = [...cats].filter((c) => !Object.keys(per).some((k) => k.startsWith(c + ' ')));
console.log(n + ' stories · ' + Object.keys(per).length + ' topic×level pairs · topics without stories: ' + (missing.join(', ') || 'none'));
console.log(bad ? bad + ' FAILED' : 'ALL OK');
process.exit(bad ? 1 : 0);
