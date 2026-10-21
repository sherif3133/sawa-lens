/* Sawa English placement test: an adaptive English level test (A1–C2). Vanilla JS, phone first. */
(function () {
	'use strict';
	var B = window.SAWA_PT;
	var $pt = document.getElementById('pt');
	var KEYS = 'sawaPT';
	var S = { key: '', step: null, busy: false };
	var PARTS = ['Vocabulary', 'Grammar', 'Reading', 'Listening', 'Writing'];
	function parts() { return S.step && S.step.quick ? ['Vocabulary', 'Grammar'] : PARTS; }

	function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
	function store(k) { try { if (k === undefined) return localStorage.getItem(KEYS); if (k === null) localStorage.removeItem(KEYS); else localStorage.setItem(KEYS, k); } catch (e) { return null; } return null; }
	function api(path, body) {
		var h = { 'Content-Type': 'application/json' };
		if (B.nonce) h['X-WP-Nonce'] = B.nonce;
		return fetch(B.root + path, { method: 'POST', credentials: 'same-origin', headers: h, body: JSON.stringify(body || {}) }).then(function (r) {
			return r.json().catch(function () { return {}; }).then(function (j) { if (!r.ok) throw new Error(j.message || 'Something went wrong. Try again.'); return j; });
		});
	}
	function page(inner, part) {
		var bar = part ? '<div class="pt-parts">' + parts().map(function (p, i) { return '<span class="' + (i + 1 < part ? 'done' : i + 1 === part ? 'cur' : '') + '">' + p + '</span>'; }).join('') + '</div>' : '';
		$pt.innerHTML = '<header class="top"><div class="brand">Sawa English <span>· English level test</span></div>' + (B.member ? '<a class="small muted" href="' + esc(B.app) + '">App</a>' : '') + '</header>' + bar + '<main class="stack">' + inner + '</main>';
		window.scrollTo(0, 0);
	}
	function send(answer) {
		if (S.busy) return;
		S.busy = true;
		var btns = $pt.querySelectorAll('button'); btns.forEach(function (b) { b.disabled = true; });
		api('answer', { key: S.key, answer: answer }).then(function (d) { S.busy = false; show(d); }, function (e) {
			S.busy = false; btns.forEach(function (b) { b.disabled = false; });
			var er = $pt.querySelector('.err'); if (er) er.textContent = e.message; else alert(e.message);
		});
	}

	/* ── Intro ─────────────────────────────────────────── */
	function intro() {
		var prev = B.previous;
		page('<section class="stack pt-hero"><h1>What\'s your English level?</h1><p class="lead">A free test that adapts to you, like EF SET or the Duolingo English Test. You get your CEFR level (A1–C2), a score out of 100 and your vocabulary size.</p>'
			+ '<p class="ar muted">اختبار مجاني بيتأقلم مع مستواك. هتعرف مستواك من A1 لـ C2، ودرجتك من ١٠٠، وعدد الكلمات اللي تعرفها.</p></section>'
			+ '<ol class="pt-list"><li><b>Vocabulary</b> · do you know these words? <span>3 min</span></li><li><b>Grammar</b> · choose the right answer <span>6 min</span></li><li><b>Reading</b> · complete the missing letters <span>6 min</span></li><li><b>Listening</b> · listen and type <span>4 min</span></li><li><b>Writing</b> · a short text' + (B.member ? '' : ' (members)') + ' <span>5 min</span></li></ol>'
			+ '<p class="small muted">🎧 You need sound for the listening part. Don\'t use a dictionary — the result is only useful if it\'s really you.</p>'
			+ (prev ? '<div class="card small">Your last result: <b>' + esc(prev.level) + '</b> · ' + prev.score + '/100 · ' + esc(prev.date) + '</div>' : '')
			+ (B.ready ? (store() ? '<button class="btn block" id="go">Continue my test</button>' : '<button class="btn block" id="go">Full test · 25 min</button><button class="btn ghost block" id="quick">Quick check · 8 min</button><p class="small muted center-text">The quick check is vocabulary + grammar: a good first estimate. Take the full test for your exact level.</p>') + (store() ? '<button class="link small center-text" id="fresh">Start again from the beginning</button>' : '') : '<p class="card">The test is being prepared. Please come back soon.</p>')
			+ '<p class="err"></p>');
		var go = $pt.querySelector('#go');
		if (go) go.onclick = function () {
			var k = store();
			if (k) { S.key = k; go.disabled = true; api('answer', { key: k, answer: null }).then(show, function () { store(null); begin(); }); return; }
			begin();
		};
		var fr = $pt.querySelector('#fresh'); if (fr) fr.onclick = function () { store(null); begin(); };
		var qk = $pt.querySelector('#quick'); if (qk) qk.onclick = function () { qk.disabled = true; begin('quick'); };
	}
	function begin(mode) {
		api('start', { mode: mode || 'full' }).then(function (d) { S.key = d.key; store(d.key); show(d); }, function (e) { $pt.querySelector('.err').textContent = e.message; });
	}

	/* ── Steps ─────────────────────────────────────────── */
	function show(d) {
		if (d.done) { store(null); return result(d.result); }
		S.step = d;
		if (d.section === 'vocab') return vocab(d);
		if (d.section === 'meaning') return meaning(d);
		if (d.section === 'grammar') return grammar(d);
		if (d.section === 'reading') return reading(d);
		if (d.section === 'listening') return listening(d);
		if (d.section === 'writing') return writing(d);
	}

	function vocab(d) {
		var i = 0, ans = [];
		function one() {
			page('<p class="pt-kicker">Part 1 · Vocabulary · ' + (i + 1) + ' / ' + d.words.length + '</p><p class="muted">Do you know this word? Some words are made up — say <b>No</b> to those.</p>'
				+ '<p class="ar small muted">هل تعرف معنى الكلمة دي؟ فيه كلمات مش حقيقية، قول No ليها.</p>'
				+ '<div class="pt-word">' + esc(d.words[i]) + '</div><div class="pt-yn"><button class="btn ghost" data-a="0">✕ No</button><button class="btn" data-a="1">✓ Yes, I know it</button></div>'
				+ '<div class="bar"><i style="width:' + Math.round(i / d.words.length * 100) + '%"></i></div><p class="err"></p>', 1);
			$pt.querySelectorAll('[data-a]').forEach(function (b) { b.onclick = function () { pick(+b.dataset.a); }; });
		}
		function pick(v) { ans.push(v); i++; if (i < d.words.length) one(); else send(ans); }
		document.onkeydown = function (e) { if (S.step !== d) return; if (e.key === 'ArrowRight' || e.key === 'y') pick(1); if (e.key === 'ArrowLeft' || e.key === 'n') pick(0); };
		one();
	}

	function meaning(d) {
		var i = 0, ans = [];
		function one() {
			var it = d.items[i];
			page('<p class="pt-kicker">Part 1 · Vocabulary · check ' + (i + 1) + ' / ' + d.items.length + '</p><p class="muted">What does it mean?</p><div class="pt-word">' + esc(it.w) + '</div>'
				+ '<div class="opts">' + it.options.map(function (o, k) { return '<button class="opt ar" data-k="' + k + '">' + esc(o) + '</button>'; }).join('') + '<button class="opt" data-k="-1">I don\'t know</button></div><p class="err"></p>', 1);
			$pt.querySelectorAll('[data-k]').forEach(function (b) { b.onclick = function () { ans.push(+b.dataset.k); i++; if (i < d.items.length) one(); else send(ans); }; });
		}
		one();
	}

	function grammar(d) {
		page('<p class="pt-kicker">Part 2 · Grammar · ' + d.i + ' / ' + d.of + '</p><p class="muted">Choose the best answer.</p><p class="q">' + esc(d.q).replace(/_{2,}/g, '<u>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</u>') + '</p>'
			+ '<div class="opts">' + d.options.map(function (o, k) { return '<button class="opt" data-k="' + k + '">' + esc(o) + '</button>'; }).join('') + '</div><p class="err"></p>', 2);
		$pt.querySelectorAll('[data-k]').forEach(function (b) { b.onclick = function () { send(+b.dataset.k); }; });
	}

	function reading(d) {
		var g = 0;
		var html = d.parts_text.map(function (p) {
			if (p.t != null) return esc(p.t);
			var id = g++;
			return '<span class="ct">' + esc(p.keep) + '<input data-g="' + id + '" maxlength="' + (p.n + 1) + '" style="width:' + (Math.max(2, p.n) + 1.2) + 'ch" autocomplete="off" autocapitalize="off" spellcheck="false" aria-label="missing letters"></span>';
		}).join('');
		page('<p class="pt-kicker">Part 3 · Reading · text ' + d.i + ' / ' + d.of + '</p><p class="muted">Complete the words. Each word is missing its second half.</p><p class="ar small muted">كمّل الكلمات: كل كلمة ناقصة نصها التاني.</p>'
			+ (d.title ? '<h3>' + esc(d.title) + '</h3>' : '') + '<div class="card ctest" dir="ltr">' + html + '</div><button class="btn block" id="next">Next</button><p class="err"></p>', 3);
		var ins = $pt.querySelectorAll('.ct input');
		ins.forEach(function (inp, k) { inp.onkeydown = function (e) { if (e.key === 'Enter') { e.preventDefault(); if (ins[k + 1]) ins[k + 1].focus(); } }; });
		if (ins[0]) ins[0].focus();
		$pt.querySelector('#next').onclick = function () { send([].map.call(ins, function (x) { return x.value; })); };
	}

	var voice = null;
	function speak(text, rate) {
		if (!('speechSynthesis' in window)) return false;
		var vs = speechSynthesis.getVoices();
		voice = voice || vs.filter(function (v) { return /en[-_]US/i.test(v.lang) && /Google|Samantha|Aria|Jenny|Natural/i.test(v.name); })[0] || vs.filter(function (v) { return /en[-_](US|GB)/i.test(v.lang); })[0] || null;
		speechSynthesis.cancel();
		var u = new SpeechSynthesisUtterance(text); u.lang = 'en-US'; u.rate = rate || 0.9; if (voice) u.voice = voice;
		speechSynthesis.speak(u);
		return true;
	}
	function listening(d) {
		var plays = 0;
		page('<p class="pt-kicker">Part 4 · Listening · ' + d.i + ' / ' + d.of + '</p><p class="muted">Listen and type exactly what you hear. You can play it 3 times.</p><p class="ar small muted">اسمع واكتب الجملة زي ما سمعتها بالظبط.</p>'
			+ '<button class="say" id="play" aria-label="Play">▶</button><p class="small muted center-text" id="pl">3 plays left</p>'
			+ '<textarea id="txt" rows="3" autocomplete="off" autocapitalize="sentences" spellcheck="false" placeholder="Type what you hear…"></textarea><button class="btn block" id="next">Next</button><p class="err"></p>', 4);
		var pb = $pt.querySelector('#play');
		pb.onclick = function () {
			if (plays >= 3) return;
			plays++;
			if (!speak(d.say, plays === 3 ? 0.75 : 0.9)) { $pt.querySelector('.err').textContent = 'Your browser can\'t play audio. Try Chrome.'; return; }
			$pt.querySelector('#pl').textContent = plays >= 3 ? 'No plays left' : (3 - plays) + ' play' + (3 - plays > 1 ? 's' : '') + ' left';
			if (plays >= 3) pb.disabled = true;
			$pt.querySelector('#txt').focus();
		};
		$pt.querySelector('#next').onclick = function () { var v = $pt.querySelector('#txt').value.trim(); if (!plays && !v) return; send(v); };
	}
	if ('speechSynthesis' in window) speechSynthesis.onvoiceschanged = function () { voice = null; };

	function writing(d) {
		page('<p class="pt-kicker">Part 5 · Writing</p><p class="q">' + esc(d.prompt) + '</p><p class="small muted">Write on your own, without translation tools.</p>'
			+ '<textarea id="txt" rows="8" autocapitalize="sentences" placeholder="Write here…"></textarea><div class="count"><span id="wc">0 words</span></div>'
			+ '<button class="btn block" id="next">Finish the test</button><button class="link small center-text" id="skip">Skip writing and see my result</button><p class="err"></p>', 5);
		var ta = $pt.querySelector('#txt');
		ta.oninput = function () { $pt.querySelector('#wc').textContent = (ta.value.trim().match(/\S+/g) || []).length + ' words'; };
		$pt.querySelector('#next').onclick = function () { if ((ta.value.trim().match(/\S+/g) || []).length < 15) { $pt.querySelector('.err').textContent = 'Write at least a few sentences, or skip.'; return; } this.textContent = 'Checking…'; send({ text: ta.value }); };
		$pt.querySelector('#skip').onclick = function () { send({}); };
	}

	/* ── Result ────────────────────────────────────────── */
	var DESC = {
		A1: ['Beginner', 'You can understand and use very basic everyday phrases.'],
		A2: ['Elementary', 'You can handle simple, routine conversations about familiar things.'],
		B1: ['Intermediate', 'You can deal with most everyday situations and talk about your experiences and plans.'],
		B2: ['Upper intermediate', 'You can talk fluently with native speakers and understand the main ideas of complex texts.'],
		C1: ['Advanced', 'You can use English flexibly for social, academic and work life.'],
		C2: ['Proficient', 'You can understand almost everything and express yourself precisely.']
	};
	function result(r) {
		var L = ['A1', 'A2', 'B1', 'B2', 'C1', 'C2'], pos = Math.min(100, Math.max(0, r.score));
		var sk = r.skills || {}, names = { vocabulary: 'Vocabulary', grammar: 'Grammar', reading: 'Reading', listening: 'Listening', writing: 'Writing' };
		page('<section class="stack center-text pt-res"><p class="pt-kicker">Your English level</p><div class="pt-level">' + esc(r.level) + '</div><h2>' + esc(DESC[r.level][0]) + '</h2><p class="muted">' + esc(DESC[r.level][1]) + '</p>'
			+ '<p class="pt-score"><b>' + r.score + '</b>/100</p></section>'
			+ '<div class="pt-scale"><div class="pt-track">' + L.map(function (l) { return '<span class="' + (l === r.level ? 'on' : '') + '">' + l + '</span>'; }).join('') + '</div><i style="left:' + pos + '%"></i></div>'
			+ '<section class="card stack"><h3>Your skills</h3>' + Object.keys(names).filter(function (k) { return sk[k]; }).map(function (k) {
				var s = sk[k];
				return '<div class="pt-skill"><div class="row-between"><b>' + names[k] + '</b><span class="pill">' + esc(s.level) + '</span></div>'
					+ (s.score ? '<div class="bar"><i style="width:' + s.score + '%"></i></div>' : '')
					+ (k === 'vocabulary' ? '<p class="small muted">You know about <b>' + (s.size || 0).toLocaleString('en-US') + '</b> of the 7,000 most common English words.</p>' : '')
					+ (k === 'writing' && s.good ? '<p class="small">👍 ' + esc(s.good) + '</p><p class="small">➜ ' + esc(s.next) + '</p>' : '') + '</div>';
			}).join('') + '</section>'
			+ (r.quick ? '<div class="card stack"><b>This was the quick check.</b><p class="small muted">It\'s an estimate from vocabulary and grammar. The full test adds reading, listening and writing for your exact level.</p><button class="btn ghost" id="full">Take the full test</button></div>' : '')
			+ (r.beta ? '<p class="small muted">Beta: this test is still being calibrated with real answers, so your result may move a little (±' + Math.max(3, r.margin) + ' points). It gets more accurate as more students take it.</p>' : '')
			+ (B.member ? '<p class="card">Your level was saved to your account: Language Lens now colours words for ' + esc(r.level) + ', and your plan uses it.</p><a class="btn block" href="' + esc(B.app) + '">Back to the app</a>'
				: '<section class="card stack center-text"><h3>Ready to improve your level?</h3><p class="muted">Sawa English: a daily plan, review cards, videos with questions, writing and speaking feedback, and a community that studies together.</p><a class="btn block" href="' + esc(B.join) + '">Join Sawa English</a></section>')
			+ '<button class="link small center-text" id="again">Take the test again</button>');
		$pt.querySelector('#again').onclick = function () { store(null); B.previous = r; intro(); };
		var fl = $pt.querySelector('#full'); if (fl) fl.onclick = function () { store(null); begin('full'); };
	}

	intro();
})();
