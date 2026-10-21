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
		progress: '<svg viewBox="0 0 24 24"><path d="M4 19V5M4 19h16"/><path d="M8 15l3-4 3 2 5-6"/></svg>',
		review: '<svg viewBox="0 0 24 24"><rect x="4" y="6" width="14" height="14" rx="2"/><path d="M8 3h11a2 2 0 0 1 2 2v11"/></svg>',
		library: '<svg viewBox="0 0 24 24"><path d="M5 4h4v16H5zM10 4h4v16h-4z"/><path d="M15.5 5l3.8-1 3 15.5-3.8 1z"/></svg>'
	};

	function shell(inner) {
		$app.innerHTML = '<header class="top"><div class="brand">Language Lens <span>· Sawa English</span></div><a class="small muted" href="' + esc(B.tracker) + '">Tracker</a></header>' +
			'<main class="stack">' + inner + '</main>' +
			'<nav class="nav">' + [['skills', 'Skills'], ['review', 'Review'], ['write', 'Write'], ['library', 'Library'], ['progress', 'Progress']].map(function (v) {
				return '<button data-nav="' + v[0] + '"' + (S.view === v[0] ? ' aria-current="page"' : '') + '>' + ICONS[v[0]] + v[1] + (v[0] === 'review' && S.due ? '<i class="badge">' + S.due + '</i>' : '') + '</button>';
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
		if (view === 'review') return review();
		if (view === 'library') return library();
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
			(n.track === 'pron' ? '<button class="btn ghost block" id="ra" style="margin-top:8px">🎙 Read aloud' + (B.stt ? '' : ' (soon)') + '</button>' : '') +
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
		var ra = $app.querySelector('#ra');
		if (ra) ra.onclick = function () { if (!B.stt) return toast('Speaking checks switch on soon.'); readAloud(id); };
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
		shell(modeSeg('write') + '<section class="stack"><h1>Write</h1><p class="muted">Write anything in English – a message, your day, an email. You get every correction. Explanations open as you unlock the skill.</p></section>' +
			'<section class="stack"><textarea id="txt" rows="7" placeholder="Write here…" autocapitalize="sentences">' + esc(result ? result.text : draft) + '</textarea>' +
			'<div class="count"><span id="wc"></span><span>up to ~500 words</span></div>' +
			'<button class="btn block" id="chk"' + (B.ai ? '' : ' disabled') + '>Check my writing</button><p class="err" id="er"></p></section>' +
			'<section id="res" class="stack"></section>');
		bindModes();
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
				if (d.xp > 0) toast('+' + d.xp + ' XP in the tracker');
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

	/* ── Write · Speak · Scan ─────────────────────────── */
	function modeSeg(cur) {
		return '<div class="seg modes" role="group">' + [['write', '✍️ Write'], ['speak', '🎙 Speak'], ['scan', '📷 Scan a page']].map(function (m) {
			return '<button data-mode="' + m[0] + '" aria-pressed="' + (cur === m[0]) + '">' + m[1] + '</button>';
		}).join('') + '</div>';
	}
	function bindModes() {
		$app.querySelectorAll('[data-mode]').forEach(function (b) {
			b.onclick = function () { stopRec(); if (b.dataset.mode === 'write') write(); else if (b.dataset.mode === 'speak') speakView(); else scanView(); };
		});
	}

	/* Recorder: one at a time, max seconds, returns a Blob. */
	var REC = null;
	function startRec(max, onTick, onDone) {
		if (!(navigator.mediaDevices && window.MediaRecorder)) { toast('Your browser can\'t record audio.'); return; }
		navigator.mediaDevices.getUserMedia({ audio: true }).then(function (stream) {
			var types = ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4', 'audio/ogg;codecs=opus'], type = '';
			for (var i = 0; i < types.length; i++) if (MediaRecorder.isTypeSupported && MediaRecorder.isTypeSupported(types[i])) { type = types[i]; break; }
			var rec = type ? new MediaRecorder(stream, { mimeType: type }) : new MediaRecorder(stream), chunks = [], t0 = Date.now();
			REC = { rec: rec, cancel: false };
			rec.ondataavailable = function (e) { if (e.data.size) chunks.push(e.data); };
			rec.onstop = function () {
				stream.getTracks().forEach(function (t) { t.stop(); });
				clearInterval(REC && REC.tick);
				var r = REC; REC = null;
				if (r && r.cancel) return;
				onDone(new Blob(chunks, { type: (rec.mimeType || type || 'audio/webm').split(';')[0] }), Math.round((Date.now() - t0) / 1000));
			};
			rec.start();
			REC.tick = setInterval(function () { var sec = Math.floor((Date.now() - t0) / 1000); onTick(sec); if (sec >= max) rec.stop(); }, 250);
		}, function () { toast('Allow the microphone to record.'); });
	}
	function stopRec(cancel) { if (REC) { REC.cancel = !!cancel; REC.rec.stop(); } }
	function upload(path, blob, fields) {
		var fd = new FormData();
		fd.append('audio', blob, 'voice.' + (/mp4/.test(blob.type) ? 'm4a' : /ogg/.test(blob.type) ? 'ogg' : 'webm'));
		Object.keys(fields || {}).forEach(function (k) { [].concat(fields[k]).forEach(function (v) { fd.append(Array.isArray(fields[k]) ? k + '[]' : k, v); }); });
		return fetch(B.root + path, { method: 'POST', credentials: 'same-origin', headers: { 'X-WP-Nonce': B.nonce }, body: fd }).then(function (r) {
			return r.json().catch(function () { return {}; }).then(function (j) { if (!r.ok) throw new Error(j.message || 'Something went wrong.'); return j; });
		});
	}
	function mmss(sec) { return Math.floor(sec / 60) + ':' + ('0' + sec % 60).slice(-2); }

	/** Free speaking: record up to 2 minutes, get the transcript checked like writing. */
	function speakView() {
		if (!S.tree) return loadTree().then(speakView, fail);
		var prompts = ['Talk about your day.', 'Describe your home or your street.', 'What did you do last weekend?', 'What are you going to do tomorrow?', 'Talk about a film or video you liked.', 'Why are you learning English?', 'Describe a person you admire.'];
		var p = prompts[new Date().getDate() % prompts.length];
		shell(modeSeg('speak') + '<section class="stack"><h1>Speak</h1><p class="muted">Speak in English for up to 2 minutes. You get the text of what you said and corrections for grammar and word choice, like in Write.</p></section>'
			+ '<section class="card stack center-text"><p class="small muted">Idea for today</p><p class="q">' + esc(p) + '</p>'
			+ '<button class="say rec" id="rec" aria-label="Record">🎙</button><p id="rt" class="small muted">' + (B.stt ? 'Tap to start' : 'Speaking checks switch on soon.') + '</p></section>'
			+ '<section id="res" class="stack"></section>');
		bindModes();
		var btn = $app.querySelector('#rec'), rt = $app.querySelector('#rt');
		if (!B.stt) { btn.disabled = true; return; }
		btn.onclick = function () {
			if (REC) { stopRec(); return; }
			btn.classList.add('on'); btn.textContent = '■';
			startRec(120, function (sec) { rt.textContent = mmss(sec) + ' / 2:00 · tap to stop'; }, function (blob, secs) {
				btn.classList.remove('on'); btn.textContent = '🎙'; btn.disabled = true;
				rt.textContent = 'Listening to your recording…';
				upload('speak', blob, { seconds: secs }).then(function (d) {
					btn.disabled = false; rt.textContent = 'Tap to record again';
					if (d.short) { $app.querySelector('#res').innerHTML = '<p class="card">I heard: “' + esc(d.transcript) + '”. Try speaking a bit longer.</p>'; return; }
					setTree(d);
					showResult(d);
					var box = $app.querySelector('#res');
					box.insertAdjacentHTML('afterbegin', '<p class="small muted">What I heard (grammar and words are checked, not your accent):</p>');
					if (d.xp > 0) toast('+' + d.xp + ' XP in the tracker');
				}, function (e) { btn.disabled = false; rt.textContent = e.message; });
			});
		};
	}

	/** Read aloud: sentences full of one sound; each target word is marked heard or not. */
	function readAloud(id) {
		var n = S.byId[id];
		shell('<button class="back" id="back">← ' + esc(n.name) + '</button><p class="loading">Preparing sentences…</p>');
		$app.querySelector('#back').onclick = function () { stopRec(true); go('skill', id); };
		api('readaloud?id=' + encodeURIComponent(id) + '&v=' + (n.sets || 0)).then(function (d) {
			var i = 0, scores = [];
			function show(result) {
				var it = d.items[i], tg = it.targets.map(function (t) { return t.toLowerCase(); });
				var words = result ? result.words.map(function (w) { return '<span class="ra-w ' + (w.ok ? 'ok' : 'no') + (tg.indexOf(w.w) >= 0 ? ' tg' : '') + '">' + esc(w.w) + '</span>'; }).join(' ')
					: esc(it.text).replace(new RegExp('\\b(' + it.targets.map(function (t) { return t.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }).join('|') + ')\\b', 'gi'), '<mark class="fx">$1</mark>');
				shell('<button class="back" id="back">← ' + esc(n.name) + '</button>'
					+ '<div class="dots">' + d.items.map(function (_, k) { return '<i class="' + (k < scores.length ? (scores[k] >= 80 ? 'ok' : 'no') : k === i ? 'cur' : '') + '"></i>'; }).join('') + '</div>'
					+ '<section class="card stack center-text"><p class="small muted">Read it aloud, clearly and not too fast.</p><p class="q ra" dir="ltr">' + words + '</p>'
					+ '<div class="ra-btns"><button class="say sm" id="listen" aria-label="Listen">🔊</button><button class="say rec" id="rec" aria-label="Record">🎙</button></div>'
					+ '<p id="rt" class="small muted">' + (result ? 'Score ' + result.score + '% · I heard: “' + esc(result.transcript) + '”' : 'Listen first, then tap the mic') + '</p>'
					+ (result ? '<p class="small muted">Red words weren\'t heard clearly — listen and try them again.</p><div class="ra-btns"><button class="btn ghost" id="again">Try again</button><button class="btn" id="next">' + (i + 1 < d.items.length ? 'Next' : 'Finish') + '</button></div>' : '')
					+ '</section>');
				$app.querySelector('#back').onclick = function () { stopRec(true); go('skill', id); };
				$app.querySelector('#listen').onclick = function () { speak(it.text); };
				var btn = $app.querySelector('#rec'), rt = $app.querySelector('#rt');
				btn.onclick = function () {
					if (REC) { stopRec(); return; }
					btn.classList.add('on'); btn.textContent = '■';
					startRec(20, function (sec) { rt.textContent = mmss(sec) + ' · tap to stop'; }, function (blob, secs) {
						btn.disabled = true; rt.textContent = 'Checking…';
						upload('speak', blob, { target: it.text, targets: it.targets, skill: id, seconds: secs }).then(function (res) {
							if (result === undefined || !result) scores[i] = res.score; else scores[i] = Math.max(scores[i] || 0, res.score);
							show(res);
						}, function (e) { btn.disabled = false; rt.textContent = e.message; });
					});
				};
				var ag = $app.querySelector('#again'), nx = $app.querySelector('#next');
				if (ag) ag.onclick = function () { show(null); };
				if (nx) nx.onclick = function () {
					i++;
					if (i < d.items.length) return show(null);
					var avg = Math.round(scores.reduce(function (a, b) { return a + b; }, 0) / Math.max(1, scores.length));
					loadTree().then(function () {
						shell('<section class="stack center-text"><h1>' + avg + '%</h1><p class="muted">' + esc(n.name) + ' · read aloud</p><button class="btn block" id="more">More sentences</button><button class="btn ghost block" id="tree">Back to the skill</button></section>');
						$app.querySelector('#more').onclick = function () { readAloud(id); };
						$app.querySelector('#tree').onclick = function () { go('skill', id); };
					});
				};
			}
			show(null);
		}, fail);
	}

	/** Scan: photo of a book page → text you can tap for meanings, save and learn from. */
	var SCAN = null;
	function scanView() {
		try { SCAN = SCAN || JSON.parse(localStorage.getItem('lensScan') || 'null'); } catch (e) { /* storage blocked */ }
		shell(modeSeg('scan') + '<section class="stack"><h1>Scan a page</h1><p class="muted">Take a photo of a book page, worksheet or screenshot. Tap any word for its meaning, mark it as Learning, or save the sentence.</p></section>'
			+ '<label class="btn block" for="cam">📷 Take or choose a photo</label><input id="cam" type="file" accept="image/*" capture="environment" hidden>'
			+ '<p class="err" id="er"></p><section id="scan" class="stack"></section>');
		bindModes();
		$app.querySelector('#cam').onchange = function () { if (this.files[0]) doScan(this.files[0]); };
		if (SCAN) paintScan();
	}
	function doScan(file) {
		var box = $app.querySelector('#scan'), er = $app.querySelector('#er');
		er.textContent = '';
		box.innerHTML = '<p class="loading">Reading the page…</p>';
		var img = new Image(), u = URL.createObjectURL(file);
		img.onload = function () {
			var k = Math.min(1, 1600 / Math.max(img.width, img.height)), c = document.createElement('canvas');
			c.width = Math.round(img.width * k); c.height = Math.round(img.height * k);
			c.getContext('2d').drawImage(img, 0, 0, c.width, c.height); URL.revokeObjectURL(u);
			var data = c.toDataURL('image/jpeg', 0.85).split(',')[1];
			Promise.all([api('scan', { body: { image: data, mime: 'image/jpeg' } }), lex()]).then(function (res) {
				var d = res[0];
				if (!d.paragraphs.length) { box.innerHTML = '<p class="card">I couldn\'t find English text in this photo. Try a sharper, closer photo.</p>'; return; }
				SCAN = d;
				try { localStorage.setItem('lensScan', JSON.stringify(d)); } catch (e) { /* storage blocked */ }
				paintScan();
			}, function (e) { box.innerHTML = ''; er.textContent = e.message; });
		};
		img.onerror = function () { box.innerHTML = ''; er.textContent = 'This photo didn\'t open. Try a JPG or PNG.'; };
		img.src = u;
	}
	function lemmaOf(w) {
		var x = w.toLowerCase().replace(/[’']s$/, '');
		if (!LEX) return x;
		if (LEX.lemmas[x]) return x;
		var f = LEX.forms[x];
		return f && f.length ? f[0] : x;
	}
	function paintScan() {
		var box = $app.querySelector('#scan'); if (!box) return;
		var my = (paintScan.n = (paintScan.n || 0) + 1);
		lex().then(function () {
			if (my !== paintScan.n || !box.isConnected) return;
			var pi = 0;
			box.innerHTML = (SCAN.title ? '<h2>' + esc(SCAN.title) + '</h2>' : '') + '<div class="card scan-text" dir="ltr">' + SCAN.paragraphs.map(function (p) {
				var idx = pi++;
				return '<p data-p="' + idx + '">' + esc(p).replace(/[A-Za-z]+(?:[’'][A-Za-z]+)?/g, function (w) { return '<span class="sw">' + w + '</span>'; }) + '</p>';
			}).join('') + '</div><div id="wcard"></div>';
			box.querySelectorAll('.sw').forEach(function (sp) {
				sp.onclick = function () {
					box.querySelectorAll('.sw.on').forEach(function (x) { x.classList.remove('on'); });
					sp.classList.add('on');
					var para = SCAN.paragraphs[+sp.parentNode.dataset.p], word = sp.textContent;
					var sents = para.match(/[^.!?]+[.!?]*/g) || [para], sentence = sents.filter(function (x) { return x.indexOf(word) >= 0; })[0] || para;
					wordCard(word, sentence.trim());
				};
			});
		});
	}
	function wordCard(word, sentence) {
		var lemma = lemmaOf(word), ms = meanings(lemma), el = $app.querySelector('#wcard');
		el.innerHTML = '<section class="card stack wcard"><div class="row-between"><b class="rv-word" dir="ltr" style="font-size:26px">' + esc(lemma) + '</b><button class="say sm" data-say="' + esc(lemma) + '">🔊</button></div>'
			+ (ms.length ? ms.slice(0, 2).map(function (m) { return '<div><span class="pill">' + esc(m.pos) + (m.level ? ' · ' + m.level : '') + '</span><p class="ar">' + esc(m.ar) + '</p>' + (m.en ? '<p class="small">' + esc(m.en) + '</p>' : '') + '</div>'; }).join('') : '')
			+ '<div data-ai-word="' + esc(word) + '" data-ai-sent="' + esc(sentence) + '"><button class="link small">' + (ms.length ? 'Meaning in this sentence (AI)' : 'Explain with AI') + '</button></div>'
			+ '<p class="small muted" dir="ltr">“' + esc(sentence) + '”</p>'
			+ '<div class="ra-btns"><button class="btn sm" id="learn">+ Learning</button><button class="btn ghost sm" id="savesent">Save sentence</button><button class="btn ghost sm" data-say="' + esc(sentence) + '">🔊 Sentence</button></div></section>';
		bindSay();
		var aw = el.querySelector('[data-ai-word]');
		aw.querySelector('button').onclick = function () {
			this.textContent = 'Explaining…';
			api('library/explain', { body: { word: word, sentence: sentence } }).then(function (x) { aw.innerHTML = '<p class="ar rv-ar">' + esc(x.meaning) + '</p>' + (x.note ? '<p class="small ar muted">' + esc(x.note) + '</p>' : '') + (x.sentence ? '<p class="small ar">' + esc(x.sentence) + '</p>' : ''); }, function (e) { aw.textContent = e.message; });
		};
		el.querySelector('#learn').onclick = function () { var b = this; api('library/word', { body: { lemma: lemma, status: 'learning' } }).then(function () { b.textContent = '✓ Learning'; b.disabled = true; toast('Added to your review cards'); }, function (e) { toast(e.message); }); };
		el.querySelector('#savesent').onclick = function () { var b = this; api('library/save', { body: { text: sentence, focus: word, kind: 'sentence', title: SCAN.title || 'Scanned page' } }).then(function () { b.textContent = '✓ Saved'; b.disabled = true; }, function (e) { toast(e.message); }); };
		el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
	}

	/* ── Dictionary (shared with the extension) ───────── */
	var LEX = null, lexP = null;
	function lex() {
		if (LEX) return Promise.resolve(LEX);
		if (!lexP) lexP = fetch(B.lexicon).then(function (r) { return r.json(); }).then(function (j) { LEX = j; return j; }, function () { lexP = null; return null; });
		return lexP;
	}
	/** [{pos, level, ar, en, ex}] for a lemma. */
	function meanings(lemma) {
		if (!LEX) return [];
		return (LEX.lemmas[lemma] || []).map(function (i) { var e = LEX.entries[i]; return { head: e[0], pos: e[1], level: LEX.levels[e[2] - 1] || '', ar: e[3], en: String(e[4] || '').split(' · ')[0], ex: String(e[5] || '').split(' · ')[0] }; });
	}
	function firstAr(lemma) { var m = meanings(lemma); return m.length ? m[0].ar : ''; }
	var GTAG = { conditional: 'Conditional (if)', passive: 'Passive', 'present-perfect': 'Present perfect', 'past-perfect': 'Past perfect', continuous: 'Continuous', future: 'Future', modal: 'Modal verb', relative: 'Relative clause', reported: 'Reported speech', comparison: 'Comparison', question: 'Question form', phrasal: 'Phrasal verb', idiom: 'Idiom', collocation: 'Collocation', phrase: 'Phrase', other: 'Other' };
	function srcLink(c) {
		if (c.videoId) return 'https://www.youtube.com/watch?v=' + c.videoId + (c.startMs ? '&t=' + Math.floor(c.startMs / 1000) + 's' : '');
		return c.url || '';
	}
	function highlight(text, focus) {
		var t = esc(text);
		if (!focus) return t;
		var i = text.toLowerCase().indexOf(String(focus).toLowerCase());
		return i < 0 ? t : esc(text.slice(0, i)) + '<mark class="fx">' + esc(text.slice(i, i + focus.length)) + '</mark>' + esc(text.slice(i + focus.length));
	}
	function unlockedSkill(id) { var n = S.byId && S.byId[id]; return !n || n.status !== 'locked'; }

	/* ── Review cards ─────────────────────────────────── */
	var R = null;
	function review() {
		shell('<section class="stack"><h1>Review</h1><p class="muted">Your words, phrases, sentences and mistakes come back just before you forget them.</p></section><p class="loading">Loading your cards…</p>');
		Promise.all([api('review'), lex(), S.tree ? null : loadTree().catch(function () {})]).then(function (res) {
			var d = res[0];
			R = { queue: d.cards, done: 0, again: 0, stats: d.stats, waiting: d.waiting, flipped: false };
			S.due = d.stats.due;
			showCard();
		}, fail);
	}
	function reviewHead() {
		var st = R.stats;
		return '<section class="rv-top"><div><b>' + R.queue.length + '</b><span>left</span></div><div><b>' + st.today + '</b><span>reviewed today</span></div><div><b>' + st.streak + '🔥</b><span>day streak</span></div><div><b>' + st.mature + '</b><span>long-term</span></div></section>';
	}
	function cardFront(c) {
		if (c.type === 'word') return '<p class="rv-kind">Word · what does it mean?</p><p class="rv-word" dir="ltr">' + esc(c.lemma) + '</p><button class="say sm" data-say="' + esc(c.lemma) + '" aria-label="Listen">🔊</button>';
		if (c.type === 'mistake') return '<p class="rv-kind">Your mistake · fix it in your head</p><p class="rv-sent" dir="ltr">' + highlight(c.sentence, c.wrong) + '</p>';
		var kind = c.type === 'phrase' ? 'Phrase' + (GTAG[c.tag] ? ' · ' + GTAG[c.tag] : '') : c.type === 'grammar' ? 'Grammar · ' + (GTAG[c.tag] || 'example') : 'Sentence';
		return '<p class="rv-kind">' + esc(kind) + ' · do you understand it?</p>' + (c.type === 'phrase' && c.focus ? '<p class="rv-word" dir="ltr">' + esc(c.focus) + '</p>' : '')
			+ '<p class="rv-sent" dir="ltr">' + highlight(c.text, c.focus) + '</p><button class="say sm" data-say="' + esc(c.text) + '" aria-label="Listen">🔊</button>';
	}
	function cardBack(c) {
		if (c.type === 'word') {
			var ms = meanings(c.lemma);
			return ms.length ? '<ul class="rv-m">' + ms.slice(0, 4).map(function (m) {
				return '<li><span class="pill">' + esc(m.pos) + (m.level ? ' · ' + m.level : '') + '</span><p class="ar">' + esc(m.ar) + '</p>' + (m.en ? '<p class="small">' + esc(m.en) + '</p>' : '') + (m.ex ? '<p class="small muted" dir="ltr">“' + esc(m.ex) + '”</p>' : '') + '</li>';
			}).join('') + '</ul>' : '<div data-ai-word="' + esc(c.lemma) + '"><button class="link">Explain with AI</button></div>';
		}
		if (c.type === 'mistake') {
			var open = unlockedSkill(c.skill);
			return '<p class="rv-sent ok" dir="ltr">✓ ' + esc(c.fixed) + '</p>'
				+ (open ? (c.why ? '<p>' + esc(c.why) + '</p>' : '') + (c.why_ar ? '<p class="ar muted">' + esc(c.why_ar) + '</p>' : '') : '<p class="small muted">🔒 The explanation unlocks with <b>' + esc(c.skillName) + '</b>.</p>')
				+ (c.skillName ? '<p class="small muted">' + esc(c.skillName) + '</p>' : '');
		}
		var link = srcLink(c);
		return (c.ar ? '<p class="ar rv-ar">' + esc(c.ar) + '</p>' : '<button class="link" data-tr="' + c.uuid + '">Show the Arabic translation</button><p class="ar rv-ar" data-ar-for="' + c.uuid + '"></p>')
			+ (link ? '<p class="small"><a href="' + esc(link) + '" target="_blank" rel="noopener">' + (c.videoId ? '▶ Watch it in the video' : esc(c.title || 'Source')) + '</a></p>' : '');
	}
	function showCard() {
		if (!R.queue.length) {
			var st = R.stats;
			shell(reviewHead() + '<section class="card stack center-text"><h2>All done for today 🎉</h2><p class="muted">' + (R.done ? 'You reviewed ' + R.done + ' cards.' : 'No cards are due.') + (R.waiting ? ' ' + R.waiting + ' new cards are waiting for the next days.' : '') + '</p>'
				+ (st.total ? '' : '<p>Cards come from words you mark as <b>Learning</b>, phrases and sentences you save in Language Lens, and your own mistakes in Write.</p>')
				+ '<label class="small">New cards per day <select id="npd">' + [5, 10, 15, 20, 30, 50].map(function (n) { return '<option' + (n === st.newPerDay ? ' selected' : '') + '>' + n + '</option>'; }).join('') + '</select></label>'
				+ '<button class="btn ghost" id="lib">Open my library</button></section>');
			$app.querySelector('#lib').onclick = function () { go('library'); };
			$app.querySelector('#npd').onchange = function () { api('review/settings', { body: { newPerDay: +this.value } }).then(function (x) { R.stats = x; toast('Saved'); review(); }); };
			return;
		}
		var c = R.queue[0];
		R.flipped = false;
		shell(reviewHead() + '<section class="card rv-card stack">' + (c.new ? '<span class="pill open">New</span>' : '') + cardFront(c) + '<div id="back" hidden class="stack rv-back"></div></section>'
			+ '<section id="acts"><button class="btn block" id="flip">Show answer</button></section>');
		bindSay();
		$app.querySelector('#flip').onclick = flip;
	}
	function flip() {
		var c = R.queue[0], back = $app.querySelector('#back');
		R.flipped = true;
		back.innerHTML = cardBack(c);
		back.hidden = false;
		var labels = ['Again', 'Hard', 'Good', 'Easy'];
		$app.querySelector('#acts').innerHTML = '<div class="grades">' + labels.map(function (l, i) {
			return '<button class="gb g' + (i + 1) + '" data-g="' + (i + 1) + '"><b>' + l + '</b><span>' + (c.next && c.next[i] ? (c.next[i] === 'again' ? 'now' : c.next[i]) : '') + '</span></button>';
		}).join('') + '</div>';
		$app.querySelectorAll('.gb').forEach(function (b) { b.onclick = function () { grade(+b.dataset.g); }; });
		bindSay();
		bindExplain(back);
		var tr = back.querySelector('[data-tr]');
		if (tr) tr.onclick = function () {
			tr.textContent = 'Translating…';
			api('library/translate', { body: { uuid: tr.dataset.tr } }).then(function (x) { c.ar = x.ar; tr.remove(); back.querySelector('[data-ar-for]').textContent = x.ar; }, function (e) { tr.textContent = e.message; });
		};
	}
	function grade(g) {
		var c = R.queue.shift();
		R.done++;
		if (g === 1) { R.queue.push(c); c.new = false; c.next = ['again', '1d', '1d', '4d']; }
		api('review', { body: { key: c.key, grade: g } }).then(function (x) {
			R.stats = x.stats; S.due = x.stats.due;
			if (x.known) toast('🎉 "' + c.lemma + '" moved to your known words');
		}, function (e) { toast(e.message); });
		showCard();
	}
	/** Words and phrases without a dictionary entry get an AI meaning on request. */
	function bindExplain(root) {
		root.querySelectorAll('[data-ai-word]').forEach(function (box) {
			var b = box.querySelector('button');
			if (!b) return;
			b.onclick = function () {
				b.textContent = 'Explaining…';
				api('library/explain', { body: { word: box.dataset.aiWord } }).then(function (x) {
					box.innerHTML = '<p class="ar rv-ar">' + esc(x.meaning) + '</p>' + (x.note ? '<p class="small ar muted">' + esc(x.note) + '</p>' : '') + (x.example ? '<p class="small muted" dir="ltr">“' + esc(x.example) + '”</p>' : '');
				}, function (e) { b.textContent = e.message; });
			};
		});
	}
	function bindSay() { $app.querySelectorAll('[data-say]').forEach(function (b) { b.onclick = function () { speak(b.dataset.say); }; }); }
	document.addEventListener('keydown', function (e) {
		if (S.view !== 'review' || !R || !R.queue.length || /input|textarea|select/i.test(e.target.tagName)) return;
		if (!R.flipped && (e.key === ' ' || e.key === 'Enter')) { e.preventDefault(); flip(); }
		else if (R.flipped && /^[1-4]$/.test(e.key)) grade(+e.key);
	});

	/* ── Library ──────────────────────────────────────── */
	var L = { tab: 'words', q: '', data: null };
	function library() {
		shell('<section class="stack"><h1>My library</h1><p class="muted">Everything you are learning and have saved, in one place.</p></section><p class="loading">Loading…</p>');
		Promise.all([api('library'), lex()]).then(function (res) { L.data = res[0]; paintLibrary(); }, fail);
	}
	function libCounts() {
		var d = L.data, by = function (k) { return d.sentences.filter(function (x) { return x.kind === k; }).length; };
		return { words: d.learning.length, phrase: by('phrase'), sentence: by('sentence'), grammar: by('grammar'), mistakes: d.mistakes.length };
	}
	function paintLibrary() {
		var d = L.data, n = libCounts(), q = L.q.toLowerCase();
		var tabs = [['words', 'Learning words', n.words], ['phrase', 'Phrases', n.phrase], ['sentence', 'Sentences', n.sentence], ['grammar', 'Grammar', n.grammar], ['mistakes', 'My mistakes', n.mistakes]];
		var match = function (t) { return !q || String(t).toLowerCase().indexOf(q) >= 0; };
		var rows = '';
		if (L.tab === 'words') {
			rows = d.learning.filter(function (w) { return match(w.lemma) || match(firstAr(w.lemma)); }).map(function (w) {
				var m = meanings(w.lemma)[0];
				return '<li class="lib-row"><div class="lib-main"><b dir="ltr">' + esc(w.lemma) + '</b>' + (m && m.level ? ' <span class="pill">' + m.level + '</span>' : '') + (m ? '<p class="ar">' + esc(m.ar) + '</p>' : '<div data-ai-word="' + esc(w.lemma) + '"><button class="link small">Explain with AI</button></div>')
					+ '<p class="small muted">' + (w.due ? 'Next review: ' + whenDue(w.due) : 'Card starts soon') + '</p></div>'
					+ '<div class="lib-acts"><button class="icon" data-say="' + esc(w.lemma) + '" title="Listen">🔊</button><button class="btn ghost sm" data-known="' + esc(w.lemma) + '">✓ Known</button><button class="icon" data-rmw="' + esc(w.lemma) + '" title="Remove">✕</button></div></li>';
			}).join('');
			rows = '<p class="small muted">' + d.known + ' known words in total' + (d.recentKnown.length ? ' · recently learned: ' + d.recentKnown.slice(0, 12).map(esc).join(', ') : '') + '</p><ul class="lib">' + (rows || '<li class="muted">No learning words' + (q ? ' match your search.' : ' yet. Mark words as Learning in Language Lens.') + '</li>') + '</ul>';
		} else if (L.tab === 'mistakes') {
			var seenM = {};
			rows = '<ul class="lib">' + (d.mistakes.filter(function (e) { var k = (e.wrong + '|' + e.fix + '|' + e.sentence).toLowerCase(); if (seenM[k]) return false; seenM[k] = 1; return match(e.sentence) || match(e.skillName); }).map(function (e) {
				return '<li class="lib-row"><div class="lib-main"><p class="fix"><s>' + esc(e.wrong) + '</s> → <b>' + esc(e.fix) + '</b></p><p class="small" dir="ltr">' + esc(e.fixed || e.sentence) + '</p>'
					+ (unlockedSkill(e.skill) && e.why ? '<p class="small muted">' + esc(e.why) + '</p>' : '') + '<p class="small muted">' + esc(e.skillName) + ' · ' + esc(e.source) + ' · ' + esc(e.date) + '</p></div></li>';
			}).join('') || '<li class="muted">No mistakes logged yet. Check your writing in Write.</li>') + '</ul>';
		} else {
			rows = '<ul class="lib">' + (d.sentences.filter(function (x) { return x.kind === L.tab && (match(x.text) || match(x.focus) || match(x.ar)); }).map(function (x) {
				var link = srcLink(x);
				return '<li class="lib-row"><div class="lib-main">' + (x.kind === 'phrase' && x.focus ? '<b dir="ltr">' + esc(x.focus) + '</b>' + (GTAG[x.tag] ? ' <span class="pill">' + esc(GTAG[x.tag]) + '</span>' : '') : '') + (x.kind === 'grammar' && GTAG[x.tag] ? '<span class="pill">' + esc(GTAG[x.tag]) + '</span>' : '')
					+ '<p dir="ltr">' + highlight(x.text, x.focus) + '</p>' + (x.ar ? '<p class="ar small">' + esc(x.ar) + '</p>' : '<button class="link small" data-tr="' + x.uuid + '">Arabic translation</button>')
					+ (link ? '<p class="small"><a href="' + esc(link) + '" target="_blank" rel="noopener">' + (x.videoId ? '▶ ' : '') + esc(x.title || 'Source') + '</a></p>' : '') + '</div>'
					+ '<div class="lib-acts"><button class="icon" data-say="' + esc(x.focus && x.kind === 'phrase' ? x.focus : x.text) + '" title="Listen">🔊</button><button class="icon" data-rms="' + x.uuid + '" title="Delete">✕</button></div></li>';
			}).join('') || '<li class="muted">Nothing here yet. Save ' + (L.tab === 'phrase' ? 'phrases' : L.tab === 'grammar' ? 'grammar examples' : 'sentences') + ' with Language Lens on YouTube or any website.</li>') + '</ul>';
		}
		shell('<section class="stack"><h1>My library</h1></section>'
			+ '<section class="lib-tabs" role="tablist">' + tabs.map(function (t) { return '<button data-lt="' + t[0] + '" aria-pressed="' + (L.tab === t[0]) + '">' + t[1] + ' <span>' + t[2] + '</span></button>'; }).join('') + '</section>'
			+ '<input class="inp" id="lq" type="search" placeholder="Search…" value="' + esc(L.q) + '">' + rows);
		$app.querySelectorAll('[data-lt]').forEach(function (b) { b.onclick = function () { L.tab = b.dataset.lt; paintLibrary(); }; });
		var lq = $app.querySelector('#lq');
		lq.oninput = function () { L.q = lq.value; clearTimeout(L.t); L.t = setTimeout(function () { paintLibrary(); var x = $app.querySelector('#lq'); x.focus(); x.setSelectionRange(x.value.length, x.value.length); }, 250); };
		bindSay();
		bindExplain($app);
		$app.querySelectorAll('[data-known]').forEach(function (b) { b.onclick = function () { setWord(b.dataset.known, 'known'); }; });
		$app.querySelectorAll('[data-rmw]').forEach(function (b) { b.onclick = function () { setWord(b.dataset.rmw, ''); }; });
		$app.querySelectorAll('[data-rms]').forEach(function (b) {
			b.onclick = function () {
				if (!window.confirm('Delete this from your library? Its review card goes too.')) return;
				api('library/sentence', { body: { uuid: b.dataset.rms } }).then(function () { L.data.sentences = L.data.sentences.filter(function (x) { return x.uuid !== b.dataset.rms; }); paintLibrary(); toast('Deleted'); }, function (e) { toast(e.message); });
			};
		});
		$app.querySelectorAll('[data-tr]').forEach(function (b) {
			b.onclick = function () {
				b.textContent = 'Translating…';
				api('library/translate', { body: { uuid: b.dataset.tr } }).then(function (x) { L.data.sentences.forEach(function (y) { if (y.uuid === b.dataset.tr) y.ar = x.ar; }); paintLibrary(); }, function (e) { b.textContent = e.message; });
			};
		});
	}
	function whenDue(d) {
		var days = Math.round((new Date(d + 'T12:00:00') - new Date(new Date().toDateString() + ' 12:00:00')) / 864e5);
		return days <= 0 ? 'today' : days === 1 ? 'tomorrow' : 'in ' + days + ' days';
	}
	function setWord(lemma, status) {
		api('library/word', { body: { lemma: lemma, status: status } }).then(function () {
			L.data.learning = L.data.learning.filter(function (w) { return w.lemma !== lemma; });
			if (status === 'known') { L.data.known++; L.data.recentKnown.unshift(lemma); }
			paintLibrary();
			toast(status === 'known' ? '✓ "' + lemma + '" is now a known word' : 'Removed');
		}, function (e) { toast(e.message); });
	}

	function fail(e) {
		shell('<p class="err">' + esc(e && e.message ? e.message : 'Something went wrong.') + '</p><button class="btn ghost" id="re">Reload</button>');
		$app.querySelector('#re').onclick = function () { location.reload(); };
	}

	api('review/settings', { body: {} }).then(function (st) { S.due = st.due; var n = document.querySelector('[data-nav="review"]'); if (n && st.due) n.insertAdjacentHTML('beforeend', '<i class="badge">' + st.due + '</i>'); }).catch(function () {});
	go(location.hash === '#review' ? 'review' : location.hash === '#library' ? 'library' : 'skills');
})();
