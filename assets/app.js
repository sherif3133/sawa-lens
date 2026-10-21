/* Language Lens web app: skill tree, practice and the writing checker. Vanilla JS, no build step. */
(function () {
	'use strict';
	var B = window.SAWA_LENS;
	var $app = document.getElementById('app');
	var TRACKS = [['grammar', 'Grammar'], ['tone', 'Tone & politeness'], ['pron', 'Pronunciation']];
	var STATUS = { open: 'Open', mastered: 'Mastered', review: 'Needs work', locked: 'Locked' };
	var S = { view: 'skills', track: 'grammar', tree: null, next: [], byId: {}, ar: pref('lensAr', '0') === '1' };

	function pref(k, d) { try { var v = localStorage.getItem(k); return v === null ? d : v; } catch (e) { return d; } }
	function setPref(k, v) { try { localStorage.setItem(k, v); } catch (e) { /* private mode */ } }
	function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }

	function api(path, opts) {
		opts = opts || {};
		var init = { method: opts.body ? 'POST' : 'GET', credentials: 'same-origin', headers: { 'X-WP-Nonce': B.nonce } };
		if (opts.body) {
			init.headers['Content-Type'] = 'application/json';
			init.body = JSON.stringify(opts.body);
		}
		return fetch(B.root + path, init).then(function (r) {
			return r.json().catch(function () { return {}; }).then(function (j) {
				if (!r.ok) throw new Error(j && j.message ? j.message : 'Something went wrong. Try again.');
				return j;
			});
		});
	}

	function setTree(d) {
		S.tree = d.tree;
		S.next = d.next || [];
		S.byId = {};
		d.tree.forEach(function (n) { S.byId[n.id] = n; });
	}
	function lesson(id) { return B.lessons[id] || { name: id }; }
	function nameOf(id) { return id === 'other' ? 'Other' : (lesson(id).name || id); }

	function toast(msg) {
		var t = document.createElement('div');
		t.className = 'toast';
		t.textContent = msg;
		document.body.appendChild(t);
		setTimeout(function () { t.remove(); }, 2600);
	}

	var ICONS = {
		skills: '<svg viewBox="0 0 24 24"><circle cx="6" cy="6" r="2.5"/><circle cx="18" cy="6" r="2.5"/><circle cx="12" cy="18" r="2.5"/><path d="M7.5 8l3.5 7.5M16.5 8L13 15.5"/></svg>',
		write: '<svg viewBox="0 0 24 24"><path d="M4 20h4L19 9l-4-4L4 16z"/><path d="M13.5 6.5l4 4"/></svg>',
		progress: '<svg viewBox="0 0 24 24"><path d="M4 19V5M4 19h16"/><path d="M8 15l3-4 3 2 5-6"/></svg>'
	};

	function shell(inner) {
		$app.innerHTML = '<header class="top"><div class="brand">Language Lens <span>· Sawa English</span></div><a class="small muted" href="' + esc(B.tracker) + '">Tracker</a></header>' +
			'<main class="stack">' + inner + '</main>' +
			'<nav class="nav">' + [['skills', 'Skills'], ['write', 'Write'], ['progress', 'Progress']].map(function (v) {
				return '<button data-nav="' + v[0] + '"' + (S.view === v[0] ? ' aria-current="page"' : '') + '>' + ICONS[v[0]] + v[1] + '</button>';
			}).join('') + '</nav>';
		$app.querySelectorAll('[data-nav]').forEach(function (b) { b.onclick = function () { go(b.dataset.nav); }; });
		window.scrollTo(0, 0);
	}

	function go(view, arg) {
		S.view = view;
		if (view === 'skills') return skills();
		if (view === 'skill') return skill(arg);
		if (view === 'practice') return practice(arg);
		if (view === 'write') return write(arg);
		if (view === 'progress') return progress();
	}

	function loadTree() {
		return api('tree').then(setTree);
	}

	/* ── Skills ───────────────────────────────────────── */
	function nodeHtml(n, i) {
		var meta = [];
		if (n.status === 'locked') meta.push('Opens after: ' + n.missing.map(nameOf).join(', '));
		else {
			meta.push(n.score + '% mastery');
			if (n.errors30) meta.push('<span class="e">' + n.errors30 + ' mistake' + (n.errors30 > 1 ? 's' : '') + ' in your writing this month</span>');
			if (n.byErrors) meta.push('opened early: your writing needs it');
		}
		var dot = n.status === 'mastered' ? '✓' : n.status === 'review' ? '!' : n.status === 'locked' ? '🔒' : String(i + 1);
		return '<li><button class="node ' + n.status + '" data-skill="' + n.id + '"><span class="dot">' + dot + '</span><span class="body">' +
			'<span class="row"><span class="name">' + esc(n.name) + '</span><span class="pill ' + n.status + '">' + STATUS[n.status] + '</span></span>' +
			'<span class="meta" style="display:block">' + meta.join(' · ') + '</span>' +
			(n.status !== 'locked' ? '<span class="bar' + (n.status === 'mastered' ? ' green' : '') + '" style="display:block"><i style="width:' + n.score + '%"></i></span>' : '') +
			'</span></button></li>';
	}

	function skills() {
		if (!S.tree) {
			shell('<p class="loading">Loading your skill tree…</p>');
			return loadTree().then(skills, fail);
		}
		var list = S.tree.filter(function (n) { return n.track === S.track; });
		var done = list.filter(function (n) { return n.status === 'mastered'; }).length;
		var nextHtml = S.next.length ? '<section class="stack"><h2>Work on next</h2><div class="next">' + S.next.map(function (id) {
			var n = S.byId[id];
			var why = n.status === 'review' ? 'Mistakes came back in your writing' : n.errors30 ? n.errors30 + ' mistakes in your writing' : 'Your next step';
			return '<button class="card" data-skill="' + id + '"><span class="pill ' + n.status + '">' + STATUS[n.status] + '</span><span><b>' + esc(n.name) + '</b><span class="small muted">' + why + '</span></span></button>';
		}).join('') + '</div></section>' : '';
		shell('<section class="stack"><h1>Hi ' + esc(B.name) + ' 👋</h1><p class="muted">Each point opens when you pass the ones before it. Practise, notice it, then use it in your own writing to master it.</p></section>' +
			nextHtml +
			'<section class="stack"><div class="seg" role="group">' + TRACKS.map(function (t) {
				return '<button data-track="' + t[0] + '" aria-pressed="' + (S.track === t[0]) + '">' + t[1] + '</button>';
			}).join('') + '</div>' +
			'<p class="small muted">' + done + ' of ' + list.length + ' mastered</p>' +
			'<ul class="tree">' + list.map(nodeHtml).join('') + '</ul></section>');
		$app.querySelectorAll('[data-track]').forEach(function (b) { b.onclick = function () { S.track = b.dataset.track; skills(); }; });
		$app.querySelectorAll('[data-skill]').forEach(function (b) { b.onclick = function () { go('skill', b.dataset.skill); }; });
	}

	function ruleHtml(l) {
		return '<p class="rule">' + esc(l.rule) + '</p>' +
			'<p class="ar"' + (S.ar ? '' : ' hidden') + ' data-ar>' + esc(l.rule_ar) + '</p>' +
			'<button class="link small" data-toggle-ar>' + (S.ar ? 'Hide Arabic' : 'عربي – show in Arabic') + '</button>';
	}
	function bindAr(root) {
		root.querySelectorAll('[data-toggle-ar]').forEach(function (b) {
			b.onclick = function () {
				S.ar = !S.ar;
				setPref('lensAr', S.ar ? '1' : '0');
				root.querySelectorAll('[data-ar]').forEach(function (e) { e.hidden = !S.ar; });
				root.querySelectorAll('[data-toggle-ar]').forEach(function (x) { x.textContent = S.ar ? 'Hide Arabic' : 'عربي – show in Arabic'; });
			};
		});
	}

	function skill(id) {
		var n = S.byId[id], l = lesson(id);
		if (!n) return go('skills');
		S.track = n.track;
		var parts = '<div class="parts">' +
			part('Practice', n.parts.practice, n.items ? n.accuracy + '% right in your last ' + n.items + ' answers' : 'Not started') +
			(n.parts.writing !== null ? part('Your own writing', n.parts.writing, n.uses + ' correct use' + (n.uses === 1 ? '' : 's') + (n.errors30 ? ' · ' + n.errors30 + ' mistakes this month' : '')) : '') +
			part('Noticing', n.parts.notice, n.noticed + ' found') + '</div>';
		var action = n.status === 'locked'
			? '<p class="muted">🔒 Opens when you pass: <b>' + n.missing.map(nameOf).join(', ') + '</b>. Or it opens early if your writing shows you need it.</p>'
			: '<button class="btn block" id="go">' + (n.sets ? 'Practise again' : 'Start practice') + '</button>' +
			(!B.ai ? '<p class="small err">Practice is not switched on yet.</p>' : '');
		shell('<button class="back" id="back">← Skill tree</button>' +
			'<section class="stack"><span class="pill ' + n.status + '">' + STATUS[n.status] + '</span><h1>' + esc(n.name) + '</h1>' +
			(n.status === 'review' ? '<p class="banner warn">You mastered this before, but mistakes came back in your writing. A quick practice fixes it.</p>' : '') +
			'</section>' +
			'<section class="card stack">' + ruleHtml(l) + '</section>' +
			'<section class="card stack"><h3>Examples</h3><ul class="ex">' + (l.ex || []).map(function (e) {
				return '<li><span class="x">' + esc(e[0]) + '</span><span class="ok">' + esc(e[1]) + '</span></li>';
			}).join('') + '</ul></section>' +
			'<section class="card stack" id="mine" hidden></section>' +
			'<section class="card stack"><h3>Mastery ' + n.score + '%</h3>' + parts +
			'<p class="small muted">' + (n.write ? 'Mastered at 80%: practise, notice it, and use it correctly in your writing on 2 different days.' : 'Mastered at 80%: practise and notice it. Speaking checks come later.') + '</p></section>' +
			'<section>' + action + '</section>');
		bindAr($app);
		$app.querySelector('#back').onclick = function () { go('skills'); };
		var g = $app.querySelector('#go');
		if (g) g.onclick = function () { go('practice', id); };
		if (n.write) {
			api('skill?id=' + encodeURIComponent(id)).then(function (d) {
				if (!d.mine || !d.mine.length) return;
				var box = $app.querySelector('#mine');
				if (!box) return;
				box.hidden = false;
				box.innerHTML = '<h3>Your mistakes</h3><ul class="ex">' + d.mine.map(function (e) {
					return '<li><span class="x">' + esc(e.sentence || e.wrong) + '</span><span class="ok">' + esc(e.fixed || e.fix) + '</span></li>';
				}).join('') + '</ul><p class="small muted">These come back as exercises, so you practise with your own sentences.</p>';
			}).catch(function () {});
		}
	}
	function part(label, pct, sub) {
		return '<div><div class="lbl"><span>' + label + '</span><span class="muted">' + pct + '%</span></div><div class="bar"><i style="width:' + pct + '%"></i></div><div class="small muted">' + esc(sub) + '</div></div>';
	}

	/* ── Practice ─────────────────────────────────────── */
	function norm(s) {
		return String(s).toLowerCase().replace(/[’‘]/g, "'").replace(/[“”]/g, '"').replace(/\s+/g, ' ').replace(/\s*([,.!?;:])\s*/g, '$1 ').trim().replace(/[.!?\s]+$/, '');
	}
	var voice = null;
	function speak(text) {
		if (!('speechSynthesis' in window)) return toast('Your browser can\'t play audio.');
		var vs = speechSynthesis.getVoices();
		voice = voice || vs.filter(function (v) { return /en[-_]US/i.test(v.lang) && /Google|Samantha|Aria|Jenny|Natural/i.test(v.name); })[0] || vs.filter(function (v) { return /en[-_]US/i.test(v.lang); })[0] || null;
		speechSynthesis.cancel();
		var u = new SpeechSynthesisUtterance(text);
		u.lang = 'en-US';
		u.rate = 0.85;
		if (voice) u.voice = voice;
		speechSynthesis.speak(u);
	}
	if ('speechSynthesis' in window) speechSynthesis.onvoiceschanged = function () { voice = null; };

	function practice(id) {
		var n = S.byId[id];
		shell('<button class="back" id="back">← ' + esc(n.name) + '</button><p class="loading">Preparing your exercises… (the first time can take up to a minute)</p>');
		$app.querySelector('#back').onclick = function () { go('skill', id); };
		api('practice?id=' + encodeURIComponent(id)).then(function (d) {
			runSet(id, d.items);
		}, function (e) {
			shell('<button class="back" id="back">← ' + esc(n.name) + '</button><p class="err">' + esc(e.message) + '</p><button class="btn ghost" id="retry">Try again</button>');
			$app.querySelector('#back').onclick = function () { go('skill', id); };
			$app.querySelector('#retry').onclick = function () { practice(id); };
		});
	}

	function runSet(id, items) {
		var n = S.byId[id], i = 0, results = [], found = 0;
		function dots() {
			return '<div class="dots">' + items.map(function (_, k) {
				return '<i class="' + (k < results.length ? (results[k] ? 'ok' : 'no') : k === i ? 'cur' : '') + '"></i>';
			}).join('') + '</div>';
		}
		function feedback(ok, it, extra) {
			return '<div class="fb ' + (ok ? 'ok' : 'no') + '"><b>' + (ok ? 'Correct ✓' : 'Not quite') + '</b>' + (extra || '') +
				(it.why ? '<p>' + esc(it.why) + '</p>' : '') + (it.why_ar ? '<p class="ar" data-ar' + (S.ar ? '' : ' hidden') + '>' + esc(it.why_ar) + '</p>' : '') +
				'<button class="link small" data-toggle-ar>' + (S.ar ? 'Hide Arabic' : 'عربي – show in Arabic') + '</button></div>' +
				'<button class="btn block" id="nx">' + (i + 1 < items.length ? 'Next' : 'See results') + '</button>';
		}
		function after(ok, it, extra) {
			results.push(ok ? 1 : 0);
			var f = $app.querySelector('#fb');
			f.innerHTML = feedback(ok, it, extra);
			$app.querySelector('.dots').outerHTML = dots();
			bindAr(f);
			var nx = $app.querySelector('#nx');
			nx.focus();
			nx.onclick = function () { i++; i < items.length ? show() : finish(); };
		}
		function show() {
			var it = items[i], body = '';
			var head = '<button class="back" id="back">← ' + esc(n.name) + '</button>' + dots() + (it.own ? '<p class="own">From your own writing</p>' : '');
			if (it.type === 'choose' || it.type === 'hear') {
				body = (it.type === 'hear' ? '<p class="muted">Listen. Which one did you hear?</p><button class="say" id="say" aria-label="Play">▶</button>' : '<p class="q">' + esc(it.q).replace(/_{2,}/g, '<u>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</u>') + '</p>') +
					'<div class="opts">' + it.options.map(function (o, k) { return '<button class="opt" data-k="' + k + '">' + esc(o) + '</button>'; }).join('') + '</div>';
			} else if (it.type === 'fix') {
				body = '<p class="muted">Correct the mistake' + (it.own ? '' : ' (there is one)') + ':</p><p class="q">' + esc(it.q) + '</p>' +
					'<textarea id="ans" rows="2" autocapitalize="sentences" spellcheck="false">' + esc(it.q) + '</textarea><button class="btn block" id="chk">Check</button>';
			} else if (it.type === 'spot') {
				body = '<p class="muted">Tap every word that shows <b>' + esc(n.name) + '</b>.</p><p class="spot">' + spotWords(it) + '</p><button class="btn block" id="chk">Done</button>';
			}
			shell(head + '<section class="card stack">' + body + '<div id="fb" class="stack"></div></section>');
			$app.querySelector('#back').onclick = function () { if (results.length < 3 || confirmLeave()) go('skill', id); };
			if (it.type === 'hear') {
				$app.querySelector('#say').onclick = function () { speak(it.say); };
				speak(it.say);
			}
			$app.querySelectorAll('.opt').forEach(function (b) {
				b.onclick = function () {
					var k = +b.dataset.k, ok = k === it.answer;
					$app.querySelectorAll('.opt').forEach(function (x) { x.disabled = true; });
					b.classList.add(ok ? 'right' : 'wrong');
					$app.querySelector('.opt[data-k="' + it.answer + '"]').classList.add('right');
					after(ok, it, it.type === 'hear' ? ' <span class="small">You heard: <b>' + esc(it.options[it.answer]) + '</b> <button class="link" id="again">play again</button></span>' : '');
					var ag = $app.querySelector('#again');
					if (ag) ag.onclick = function () { speak(it.say); };
				};
			});
			var chk = $app.querySelector('#chk');
			if (chk && it.type === 'fix') {
				var ta = $app.querySelector('#ans');
				ta.focus();
				ta.setSelectionRange(ta.value.length, ta.value.length);
				chk.onclick = function () {
					var v = norm(ta.value), ok = it.answer.some(function (a) { return norm(a) === v; });
					ta.disabled = true;
					chk.remove();
					after(ok, it, ok ? '' : '<p>Answer: <b>' + esc(it.answer[0]) + '</b></p>');
				};
			}
			if (chk && it.type === 'spot') {
				$app.querySelectorAll('.spot .w').forEach(function (w) { w.onclick = function () { w.classList.toggle('on'); }; });
				chk.onclick = function () {
					var hit = 0, bad = 0, total = 0;
					var groups = {};
					$app.querySelectorAll('.spot .w').forEach(function (w) {
						var on = w.classList.contains('on'), t = w.dataset.t;
						w.disabled = true;
						if (t) {
							groups[t] = groups[t] || on;
							w.classList.add(on ? 'hit' : 'miss');
						} else if (on) {
							bad++;
							w.classList.add('bad');
						}
						w.classList.remove('on');
					});
					Object.keys(groups).forEach(function (g) { total++; if (groups[g]) hit++; });
					found += hit;
					chk.remove();
					var ok = total > 0 && hit / total >= 0.7 && bad <= 1;
					after(ok, it, '<p>You found ' + hit + ' of ' + total + (bad ? ' · ' + bad + ' extra' : '') + '. The green words show it.</p>');
				};
			}
		}
		function finish() {
			var right = results.reduce(function (a, b) { return a + b; }, 0);
			shell('<p class="loading">Saving…</p>');
			api('practice', { body: { id: id, results: results, found: found } }).then(function (d) {
				setTree(d);
				var n2 = S.byId[id];
				var ch = (d.changed || []).map(function (c) {
					if (c.to === 'mastered') return '<p class="banner good">🏆 Mastered: ' + esc(nameOf(c.id)) + '</p>';
					if (c.from === 'locked') return '<p class="banner good">🔓 Unlocked: ' + esc(nameOf(c.id)) + '</p>';
					return '';
				}).join('');
				shell('<section class="stack"><h1>' + right + ' / ' + results.length + '</h1><p class="muted">' + esc(n2.name) + ' · mastery ' + n2.score + '%</p>' + ch +
					(n2.write && n2.status !== 'mastered' ? '<p class="card">Next: use it in your own writing. Write a few sentences in <b>Write</b> – correct uses on 2 different days count towards mastery.</p>' : '') +
					'<button class="btn block" id="again">Another set</button><button class="btn ghost block" id="write">Write something</button><button class="btn ghost block" id="tree">Skill tree</button></section>');
				$app.querySelector('#again').onclick = function () { go('practice', id); };
				$app.querySelector('#write').onclick = function () { go('write'); };
				$app.querySelector('#tree').onclick = function () { go('skills'); };
			}, fail);
		}
		show();
	}
	function confirmLeave() { return window.confirm('Leave this practice? Your answers so far won\'t be saved.'); }

	/** Words of a "spot it" text as buttons; words inside a target phrase share data-t. */
	function spotWords(it) {
		var text = it.text, marks = new Array(text.length).fill(0), from = 0;
		it.targets.forEach(function (t, k) {
			var at = text.toLowerCase().indexOf(String(t).toLowerCase(), from);
			if (at < 0) at = text.toLowerCase().indexOf(String(t).toLowerCase());
			if (at < 0) return;
			for (var c = at; c < at + t.length; c++) marks[c] = k + 1;
			from = at + t.length;
		});
		var out = '', re = /[A-Za-z0-9’']+/g, m, last = 0;
		while ((m = re.exec(text))) {
			out += esc(text.slice(last, m.index));
			var t = marks[m.index] || marks[m.index + m[0].length - 1] || 0;
			out += '<button class="w"' + (t ? ' data-t="' + t + '"' : '') + '>' + esc(m[0]) + '</button>';
			last = m.index + m[0].length;
		}
		return out + esc(text.slice(last));
	}

	/* ── Write ────────────────────────────────────────── */
	var draft = pref('lensDraft', '');
	function write(result) {
		if (!S.tree) return loadTree().then(function () { write(result); }, fail);
		var words = function (s) { return (s.trim().match(/\S+/g) || []).length; };
		shell('<section class="stack"><h1>Write</h1><p class="muted">Write anything in English – a message, your day, an email. You get every correction. Explanations open as you unlock the skill.</p></section>' +
			'<section class="stack"><textarea id="txt" rows="7" placeholder="Write here…" autocapitalize="sentences">' + esc(result ? result.text : draft) + '</textarea>' +
			'<div class="count"><span id="wc"></span><span>up to ~500 words</span></div>' +
			'<button class="btn block" id="chk"' + (B.ai ? '' : ' disabled') + '>Check my writing</button><p class="err" id="er"></p></section>' +
			'<section id="res" class="stack"></section>');
		var ta = $app.querySelector('#txt'), wc = $app.querySelector('#wc');
		var upd = function () { wc.textContent = words(ta.value) + ' words'; };
		upd();
		ta.oninput = function () { upd(); draft = ta.value; setPref('lensDraft', draft); };
		$app.querySelector('#chk').onclick = function () {
			var btn = this, er = $app.querySelector('#er');
			er.textContent = '';
			if (words(ta.value) < 3) { er.textContent = 'Write at least one full sentence.'; return; }
			btn.disabled = true;
			btn.textContent = 'Checking…';
			api('write', { body: { text: ta.value } }).then(function (d) {
				setTree(d);
				draft = '';
				setPref('lensDraft', '');
				btn.disabled = false;
				btn.textContent = 'Check again';
				showResult(d);
			}, function (e) {
				btn.disabled = false;
				btn.textContent = 'Check my writing';
				er.textContent = e.message;
			});
		};
		if (result) showResult(result);
	}

	/** Explanation only for points the learner has open; locked points show the correction alone. */
	function errorItem(e, k) {
		var n = S.byId[e.skill];
		var locked = n && n.status === 'locked';
		var why = '<p class="why">' + esc(e.why) + '</p>' + (e.why_ar ? '<p class="why ar" data-ar' + (S.ar ? '' : ' hidden') + '>' + esc(e.why_ar) + '</p>' : '');
		return '<li data-e="' + k + '"><div class="fix"><span class="n">' + (k + 1) + '</span><s>' + esc(e.wrong) + '</s> → <b>' + esc(e.right || '(remove)') + '</b></div>' +
			(locked
				? '<div class="lock">🔒 Explanation unlocks with <button class="link" data-skill="' + e.skill + '">' + esc(n.name) + '</button> · <button class="link" data-peek="' + k + '">show anyway</button></div><div data-why="' + k + '" hidden>' + why + '</div>'
				: why + (n ? '<div class="small" style="margin-top:6px"><button class="link" data-skill="' + e.skill + '">' + esc(n.name) + '</button></div>' : '')) +
			'</li>';
	}

	function marked(text, errors) {
		var out = '', pos = 0, spans = [];
		errors.forEach(function (e, k) {
			var at = text.indexOf(e.wrong, pos);
			if (at < 0) at = text.indexOf(e.wrong);
			if (at < 0) return;
			if (spans.some(function (s) { return at < s[1] && at + e.wrong.length > s[0]; })) return;
			spans.push([at, at + e.wrong.length, k]);
			pos = at + e.wrong.length;
		});
		spans.sort(function (a, b) { return a[0] - b[0]; });
		pos = 0;
		spans.forEach(function (s) {
			out += esc(text.slice(pos, s[0])) + '<mark data-m="' + s[2] + '">' + esc(text.slice(s[0], s[1])) + '<sup>' + (s[2] + 1) + '</sup></mark>';
			pos = s[1];
		});
		return out + esc(text.slice(pos));
	}

	function showResult(d) {
		var r = d.result, box = $app.querySelector('#res');
		var ch = (d.changed || []).map(function (c) {
			if (c.to === 'review') return '<p class="banner warn">Needs work again: ' + esc(nameOf(c.id)) + '</p>';
			if (c.to === 'mastered') return '<p class="banner good">🏆 Mastered: ' + esc(nameOf(c.id)) + '</p>';
			if (c.from === 'locked') return '<p class="banner good">🔓 Unlocked: ' + esc(nameOf(c.id)) + (S.byId[c.id] && S.byId[c.id].byErrors ? ' – your writing shows you need it now' : '') + '</p>';
			return '';
		}).join('');
		var good = Object.keys(r.good || {}).filter(function (id) { return S.byId[id]; });
		box.innerHTML = ch +
			(r.praise ? '<p class="banner good">' + esc(r.praise) + '</p>' : '') +
			(r.errors.length
				? '<div class="card"><p class="marked">' + marked(d.text, r.errors) + '</p></div><h2>' + r.errors.length + ' correction' + (r.errors.length > 1 ? 's' : '') + '</h2>' +
				'<button class="link small" data-toggle-ar>' + (S.ar ? 'Hide Arabic' : 'عربي – show in Arabic') + '</button>' +
				'<ul class="errs">' + r.errors.map(errorItem).join('') + '</ul>'
				: '<p class="card">No mistakes found. Well done! ✓</p>') +
			(good.length ? '<div class="card small"><b>Used correctly:</b> ' + good.map(function (id) { return esc(S.byId[id].name) + ' ×' + r.good[id]; }).join(' · ') + '</div>' : '') +
			(r.tip ? '<div class="card"><b>Next time:</b> ' + esc(r.tip) + '</div>' : '') +
			(r.errors.length && r.corrected ? '<details class="card"><summary>Corrected text</summary><p class="marked" style="margin-top:8px">' + esc(r.corrected) + '</p><button class="btn ghost" id="copy" style="margin-top:8px">Copy</button></details>' : '');
		bindAr(box);
		box.querySelectorAll('[data-skill]').forEach(function (b) { b.onclick = function () { go('skill', b.dataset.skill); }; });
		box.querySelectorAll('[data-peek]').forEach(function (b) {
			b.onclick = function () { box.querySelector('[data-why="' + b.dataset.peek + '"]').hidden = false; b.remove(); };
		});
		box.querySelectorAll('mark[data-m]').forEach(function (m) {
			m.onclick = function () {
				var li = box.querySelector('[data-e="' + m.dataset.m + '"]');
				li.scrollIntoView({ behavior: 'smooth', block: 'center' });
				li.classList.add('flash');
				setTimeout(function () { li.classList.remove('flash'); }, 1500);
			};
		});
		var cp = box.querySelector('#copy');
		if (cp) cp.onclick = function () { navigator.clipboard.writeText(r.corrected).then(function () { toast('Copied'); }); };
		box.scrollIntoView({ behavior: 'smooth', block: 'start' });
	}

	/* ── Progress ─────────────────────────────────────── */
	function progress() {
		shell('<section class="stack"><h1>Progress</h1><p class="muted">Are your mistakes going down? Mistakes per 100 words you write, week by week.</p></section><p class="loading">Loading…</p>');
		Promise.all([api('history'), S.tree ? null : loadTree()]).then(function (res) {
			var d = res[0], t = d.trends;
			var mastered = S.tree.filter(function (n) { return n.status === 'mastered'; }).length;
			var totalWords = t.words.reduce(function (a, b) { return a + b; }, 0);
			var trend = t.skills.length ? '<ul class="hist">' + t.skills.slice(0, 12).map(function (s) {
				var vals = s.rates.filter(function (x) { return x !== null; });
				var max = Math.max.apply(null, vals.concat([1]));
				var first = vals.length ? vals[0] : null, last = vals.length ? vals[vals.length - 1] : null;
				var dir = vals.length > 1 ? (last < first ? '<span style="color:var(--green);font-weight:600">↓ going down</span>' : last > first ? '<span class="err">↑ going up</span>' : '→ same') : '';
				return '<li class="card trend"><div class="lbl" style="display:flex;justify-content:space-between;gap:8px"><b>' + esc(s.name) + '</b><span class="small">' + dir + '</span></div>' +
					'<div class="spark">' + s.rates.map(function (x) { return x === null ? '<i class="none"></i>' : '<i style="height:' + Math.max(6, x / max * 100) + '%" title="' + x + ' per 100 words"></i>'; }).join('') + '</div>' +
					'<div class="small muted">8 weeks ago → this week' + (first !== null ? ' · ' + first + ' → ' + last + ' per 100 words' : '') + '</div></li>';
			}).join('') + '</ul>' : '<p class="card muted">Write something in <b>Write</b> and your mistakes will be tracked here.</p>';
			shell('<section class="stack"><h1>Progress</h1><p class="muted">Are your mistakes going down? Mistakes per 100 words you write, week by week.</p></section>' +
				'<section class="card" style="display:flex;justify-content:space-around;text-align:center"><div><h2>' + mastered + '</h2><span class="small muted">skills mastered</span></div><div><h2>' + totalWords + '</h2><span class="small muted">words checked (8 weeks)</span></div></section>' +
				'<section class="stack"><h2>Mistakes by type</h2>' + trend + '</section>' +
				(d.writings.length ? '<section class="stack"><h2>Your writing</h2><ul class="hist">' + d.writings.map(function (w) {
					return '<li><button data-w="' + w.id + '"><b>' + esc(w.date) + '</b> · ' + w.words + ' words · ' + w.errors + ' corrections<br><span class="small muted">' + esc(w.excerpt) + '…</span></button></li>';
				}).join('') + '</ul></section>' : ''));
			$app.querySelectorAll('[data-w]').forEach(function (b) {
				b.onclick = function () {
					api('writing?id=' + b.dataset.w).then(function (w) { S.view = 'write'; write({ text: w.text, result: w.result, changed: [] }); }, fail);
				};
			});
		}, fail);
	}

	function fail(e) {
		shell('<p class="err">' + esc(e && e.message ? e.message : 'Something went wrong.') + '</p><button class="btn ghost" id="re">Reload</button>');
		$app.querySelector('#re').onclick = function () { location.reload(); };
	}

	go('skills');
})();
