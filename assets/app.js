/* Language Lens web app: skill tree, practice and the writing checker. Vanilla JS, no build step. */
(function () {
	'use strict';
	var B = window.SAWA_LENS;
	var $app = document.getElementById('app');
	var TRACKS = [['grammar', 'Grammar'], ['tone', 'Tone & politeness'], ['pron', 'Pronunciation']];
	var STATUS = { open: 'Open', mastered: 'Mastered', review: 'Needs work', locked: 'Locked' };
	var S = { view: 'skills', track: 'grammar', tree: null, next: [], byId: {}, ar: B.explainLang ? B.explainLang === 'ar' : pref('lensAr', '0') === '1' }; // "Explanations: English / العربية", saved with the account

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
		library: '<svg viewBox="0 0 24 24"><path d="M5 4h4v16H5zM10 4h4v16h-4z"/><path d="M15.5 5l3.8-1 3 15.5-3.8 1z"/></svg>',
		watch: '<svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="3"/><path d="M10 9l5 3-5 3z"/></svg>',
		start: '<svg viewBox="0 0 24 24"><path d="M5 21V4"/><path d="M5 4h11l-2 4 2 4H5"/></svg>',
		today: '<svg viewBox="0 0 24 24"><path d="M3 11l9-7 9 7v9a1 1 0 0 1-1 1h-5v-6h-6v6H4a1 1 0 0 1-1-1z"/></svg>',
		learn: '<svg viewBox="0 0 24 24"><path d="M2 8l10-5 10 5-10 5z"/><path d="M6 10v5c3 2.5 9 2.5 12 0v-5"/></svg>',
		chat: '<svg viewBox="0 0 24 24"><path d="M4 5h16v11H9l-5 4z"/></svg>',
		community: '<svg viewBox="0 0 24 24"><path d="M7 8h10a4 4 0 0 1 4 4v3a3 3 0 0 1-5.4 1.8L14.5 15h-5l-1.1 1.8A3 3 0 0 1 3 15v-3a4 4 0 0 1 4-4z"/><path d="M8 11v3M6.5 12.5h3"/><circle cx="15.5" cy="11.5" r=".6"/><circle cx="17.5" cy="13.5" r=".6"/></svg>', // Play: a game controller
		me: '<svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4.5 3.5-7 8-7s8 2.5 8 7"/></svg>'
	};

	var APP_TABS = [['today', 'Home'], ['learn', 'Learn'], ['chat', 'Chat'], ['community', 'Play']]; // Play: the Silk Road game and your clan
	function tabOf(v) {
		if (['today', 'chat', 'community'].indexOf(v) >= 0) return v;
		return ['me', 'library', 'progress', 'start'].indexOf(v) >= 0 ? 'today' : 'learn';
	}
	function paintNav() {
		var nav = document.getElementById('nav'); if (!nav) return;
		var cur = tabOf(S.view);
		nav.innerHTML = APP_TABS.map(function (t) {
			var badge = t[0] === 'learn' && S.due ? S.due : t[0] === 'chat' && S.unread ? S.unread : 0;
			return '<button data-nav="' + t[0] + '"' + (cur === t[0] ? ' aria-current="page"' : '') + '>' + ICONS[t[0]] + t[1] + (badge ? '<i class="badge">' + badge + '</i>' : '') + '</button>';
		}).join('');
		nav.querySelectorAll('[data-nav]').forEach(function (b) { b.onclick = function () { go(b.dataset.nav); }; });
	}
	/** Tracker parts (Today's stats, Chat, Community, Me) live in #trk under the page. */
	function showTrk(v) {
		var t = document.getElementById('trk'); if (!t) return;
		t.hidden = !v;
		if (!v) return;
		(function call(n) { if (window.SawaTracker) window.SawaTracker.show(v); else if (n < 50) setTimeout(function () { call(n + 1); }, 200); })(0);
	}
	function shell(inner) {
		if (B.appMode) {
			// Home shows the tracker (your stats, streak, quests) at the top: move it out before redrawing, back in after.
			var trk = document.getElementById('trk'), nav = document.getElementById('nav');
			if (trk && $app.contains(trk)) document.body.insertBefore(trk, nav);
			$app.innerHTML = '<header class="top"><div class="brand">Sawa English</div><button class="link small" data-nav-start>Guide</button></header><main class="stack">' + inner + '</main>';
			var gs = $app.querySelector('[data-nav-start]'); if (gs) gs.onclick = function () { go('start'); };
			paintNav();
			showTrk(['today', 'chat', 'me'].indexOf(S.view) >= 0 || (S.view === 'community' && unlocked('community')) ? S.view : '');
			if (trk) trk.classList.toggle('at-top', S.view === 'today');
			if (trk && S.view === 'today') { var mn = $app.querySelector('main'); mn.insertBefore(trk, mn.firstChild); }
			window.scrollTo(0, 0);
			return;
		}
		$app.innerHTML = '<header class="top"><div class="brand">Language Lens <span>· Sawa English</span></div><a class="small muted" href="' + esc(B.tracker) + '">Tracker</a></header>' +
			'<main class="stack">' + inner + '</main>' +
			'<nav class="nav">' + [['start', 'Start'], ['review', 'Review'], ['read', 'Read'], ['write', 'Write'], ['skills', 'Skills'], ['library', 'Library'], ['progress', 'Progress']].filter(function (v) { return unlocked(v[0]); }).map(function (v) {
				return '<button data-nav="' + v[0] + '"' + (S.view === v[0] ? ' aria-current="page"' : '') + '>' + ICONS[v[0]] + v[1] + (v[0] === 'review' && S.due ? '<i class="badge">' + S.due + '</i>' : '') + '</button>';
			}).join('') + '</nav>';
		$app.querySelectorAll('[data-nav]').forEach(function (b) { b.onclick = function () { go(b.dataset.nav); }; });
		window.scrollTo(0, 0);
	}

	function go(view, arg) {
		if (W && W.timer) { clearInterval(W.timer); W.timer = null; }
		stopClip(); stopReading();
		if (B.appMode && S.gate) view = 'today';
		if (['today', 'learn', 'chat', 'community', 'me'].indexOf(view) < 0 && !unlocked(view === 'skill' || view === 'practice' ? 'skills' : view)) view = B.appMode ? 'learn' : 'start';
		S.view = view;
		try { if (B.appMode) history.replaceState(null, '', '#' + view + (arg && typeof arg !== 'object' ? '-' + arg : '')); } catch (e) { /* ignore */ }
		if (view === 'today') return today();
		if (view === 'learn') return learnHub();
		if (view === 'me') { view = 'today'; S.view = 'today'; } // Me is part of Home now
		if (view === 'scan') return scanView();
		if (view === 'read') return readView();
		if (view === 'community' && !unlocked('community')) return shell('<section class="stack"><h1>Play</h1></section><div class="card stack"><p><b>🐫 The Silk Road opens at stage 3</b> — a world you walk through with the other students, where monsters attack with the words you save. Clans, the weekly boss and the caravan board open with it.</p><p class="muted">To get there, finish your stages — or check a few sentences you wrote in Learn → Write. The chat is already open — say hello in English!</p><div class="ra-btns"><button class="btn" data-go="chat">Open the chat</button><button class="btn ghost" data-go="start">See your stages</button></div></div>'), $app.querySelectorAll('[data-go]').forEach(function (b) { b.onclick = function () { go(b.dataset.go); }; });
		if (view === 'chat' || view === 'community') return shell('');
		if (view === 'start') return guide();
		if (view === 'skills') return skills();
		if (view === 'skill') return skill(arg);
		if (view === 'practice') return practice(arg);
		if (view === 'write') return write(arg);
		if (view === 'progress') return progress();
		if (view === 'review') return review();
		if (view === 'library') return library();
		if (view === 'watch') return watch(arg);
		if (view === 'interests') return interests(arg);
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
				api('prefs', { body: { explainLang: S.ar ? 'ar' : 'en' } }).catch(function () {}); // Language Lens follows it too
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
			(n.track === 'pron' && unlocked('speak') ? '<button class="btn ghost block" id="ra" style="margin-top:8px">🎙 Read aloud' + (B.stt ? '' : ' (soon)') + '</button>' : '') +
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

	/* ── Start here: a guided path that unlocks features step by step ── */
	var STAGES = [
		{ n: 1, title: 'Find your level & collect words', ar: 'اعرف مستواك واجمع كلمات', tabs: ['start', 'read', 'scan', 'interests'],
			intro: 'Two small steps. Everything works on your phone.',
			steps: [
				{ k: 'vocabTest', t: 'Take the quick level check (about 8 minutes)', ar: 'اختبار سريع لتحديد المستوى', how: 'It finds your level and vocabulary size, so everything fits you. You can take the full test later.', done: function (j) { return j.vocabTest; }, link: function () { return ['Start the level check', '/placement/']; } },
				{ k: 'learning', t: 'Save 5 words you want to learn', ar: 'احفظ ٥ كلمات عايز تتعلمها', how: 'On your phone: Learn → Scan a page, tap a word → "+ Learning". On a computer: Language Lens on YouTube.', done: function (j) { return j.learning + j.saved >= 5; }, count: function (j) { return Math.min(5, j.learning + j.saved) + '/5'; }, go: 'scan' },
				{ k: 'interests', optional: true, t: 'Find English content you\'ll love', ar: 'اكتشف محتوى إنجليزي هيعجبك', how: 'Tell us what you watch in Arabic (comedy, rap, football…) and get English creators, music and books to match. 2 minutes.', done: function (j) { return j.interests; }, go: 'interests' },
				{ k: 'extension', optional: true, t: 'On a computer: add Language Lens to Chrome', ar: 'على الكمبيوتر: ثبّت إضافة Language Lens', how: 'Meanings on YouTube and any website. Click its icon → "Sign in with Sawa English".', done: function (j) { return j.extension; }, link: function (j) { return j.store ? ['Install Language Lens', j.store] : null; } }
			] },
		{ n: 2, title: 'Review a little every day', ar: 'راجع قليلًا كل يوم', tabs: ['review', 'library'],
			intro: 'Every word and sentence you save becomes a review card. Five minutes a day is enough — the app shows each card just before you forget it.',
			steps: [
				{ k: 'reviewDays', t: 'Review your cards on 2 different days', ar: 'راجع البطاقات يومين', how: 'Open Review. Read the card, think of the answer, tap "Show answer", then be honest: Again, Hard, Good or Easy.', done: function (j) { return j.reviewDays >= 2; }, count: function (j) { return Math.min(2, j.reviewDays) + '/2'; }, go: 'review' },
				{ k: 'saved', t: 'Save 3 sentences or phrases you like', ar: 'احفظ ٣ جمل أو عبارات', how: 'On YouTube: in the transcript panel, or select words → "Save sentence" / "Save expression". They appear in Library.', done: function (j) { return j.saved >= 3; }, count: function (j) { return Math.min(3, j.saved) + '/3'; } }
			] },
		{ n: 3, title: 'Write and fix your grammar', ar: 'اكتب وصحّح القواعد', tabs: ['write', 'skills', 'community'],
			intro: 'Write a few sentences about your day. You get every correction, and your mistakes decide which grammar points open in your skill tree. Community opens too, with the Silk Road game: its monsters attack with your own words, saved sentences and mistakes, so every fight is a review.',
			steps: [
				{ k: 'writings', t: 'Check your writing twice', ar: 'صحّح كتابتك مرتين', how: 'Open Write, write 3–5 sentences, tap "Check my writing".', done: function (j) { return j.writings >= 2; }, count: function (j) { return Math.min(2, j.writings) + '/2'; }, go: 'write' },
				{ k: 'sets', t: 'Do one practice set in your skill tree', ar: 'تمرين واحد في شجرة المهارات', how: 'Open Skills → "Work on next" → Start practice. Explanations open as you unlock each point.', done: function (j) { return j.sets >= 1; }, go: 'skills' }
			] },
		{ n: 4, title: 'Speak', ar: 'تكلّم', tabs: ['speak'],
			intro: 'Speaking is where it all comes together. Talk for a minute and get the text of what you said with corrections. In the Silk Road you can now pick a job (Thief, Hunter or Trader), buy gear and raid — better English unlocks better gear.',
			steps: [
				{ k: 'speaks', t: 'Speak once (in Write → Speak, or a voice note in the tracker chat)', ar: 'سجّل كلامك مرة', how: 'Write → 🎙 Speak → talk about the idea of the day. Or send a voice note in the tracker chat and tap "رأي في كلامي".', done: function (j) { return j.speaks >= 1; }, go: 'write' }
			] },
		{ n: 5, title: 'Everything is open', ar: 'كل الأدوات مفتوحة', tabs: ['progress'],
			intro: 'You know the routine. Now use the extras when you need them:',
			tips: [
				'📷 Scan a page (Write → Scan): photo of a book page → tap words for meanings.',
				'📈 Progress: see if each type of mistake is going down, week by week.',
				'🔎 Focus mode (Language Lens popup): highlight one grammar point or sound in every video.',
				'🌐 Write in Arabic in the tracker chat — it\'s translated to English for you to check before sending.',
				'⚡ Every English message, voice note and fixed mistake earns XP in the tracker.',
				'⚔ Silk Road parties: play with 2–4 friends — a party chat, a 48-hour party boss and live battles on the words you are all learning.'
			], steps: [] }
	];
	function stageOf(j) {
		if (j.admin || pref('lensAll', '0') === '1') return 5;
		var seq = 5;
		for (var i = 0; i < 4; i++) if (!STAGES[i].steps.every(function (st) { return st.optional || st.done(j); })) { seq = i + 1; break; }
		// Students who already use later tools aren't held back by an earlier step they skipped.
		var seen = j.speaks ? 4 : j.writings || j.sets ? 3 : j.reviews ? 2 : 1;
		return Math.max(seq, seen);
	}
	function unlocked(tab) {
		if (!S.J) return true;
		for (var i = 0; i < S.stage; i++) if (STAGES[i].tabs.indexOf(tab) >= 0) return true;
		return ['skill', 'practice'].indexOf(tab) >= 0 ? S.stage >= 3 : false;
	}
	function loadJourney() {
		return api('journey').then(function (j) {
			var before = S.stage || +pref('lensStage', '0');
			S.J = j; S.stage = stageOf(j);
			if (before && S.stage > before) setTimeout(function () { toast('🎉 Unlocked: ' + STAGES[S.stage - 1].title); }, 400);
			setPref('lensStage', String(S.stage));
			window.SawaAppStage = S.stage; // the Silk Road opens its parts stage by stage
		});
	}
	function guide() {
		var j = S.J;
		if (!j) return loadJourney().then(guide, fail);
		var cur = S.stage;
		var card = function (st) {
			var state = st.n < cur ? 'done' : st.n === cur ? 'now' : 'locked';
			var head = '<div class="g-head"><span class="g-n">' + (state === 'done' ? '✓' : state === 'locked' ? '🔒' : st.n) + '</span><div><b>' + esc(st.title) + '</b><span class="ar small">' + esc(st.ar) + '</span></div></div>';
			if (state === 'locked') return '<li class="g-stage locked">' + head + '<p class="small muted">Opens after stage ' + (st.n - 1) + '.</p></li>';
			if (state === 'done') return '<li class="g-stage done">' + head + '</li>';
			return '<li class="g-stage now">' + head + '<p>' + esc(st.intro) + '</p>'
				+ (st.steps.length ? '<ul class="g-steps">' + st.steps.map(function (x) {
					var ok = x.done(j), lk = x.link ? x.link(j) : null;
					return '<li class="' + (ok ? 'ok' : '') + '"><span class="g-check">' + (ok ? '✓' : '') + '</span><div><b>' + esc(x.t) + '</b>' + (x.optional ? ' <span class="pill">optional</span>' : '') + (x.count && !ok ? ' <span class="pill">' + x.count(j) + '</span>' : '')
						+ '<p class="ar small muted">' + esc(x.ar) + '</p>' + (ok ? '' : '<p class="small">' + esc(x.how) + '</p>' + (x.go ? '<button class="btn sm" data-go="' + x.go + '">Open ' + x.go.charAt(0).toUpperCase() + x.go.slice(1) + '</button>' : '') + (lk ? '<a class="btn ghost sm" href="' + esc(lk[1]) + '" target="_blank" rel="noopener">' + esc(lk[0]) + '</a>' : '')) + '</div></li>';
				}).join('') + '</ul>' : '')
				+ (st.tips ? '<ul class="g-tips">' + st.tips.map(function (t) { return '<li>' + esc(t) + '</li>'; }).join('') + '</ul>' : '')
				+ '</li>';
		};
		var routine = cur >= 2 ? '<section class="card stack"><h3>Your daily 15 minutes</h3><ol class="g-routine"><li><b>Review</b> your cards (5 min)</li><li><b>Watch</b> one video with Language Lens and mark new words (5–10 min)</li>' + (cur >= 3 ? '<li><b>Write or speak</b> a few sentences — in Write, or in the tracker chat</li>' : '') + '</ol><p class="small muted">Log it in the <a href="' + esc(j.tracker) + '">tracker</a> to keep your streak.</p></section>' : '';
		shell('<section class="stack"><h1>Start here</h1><p class="muted">One step at a time. New tools appear as you finish each stage, so you never have to learn everything at once.</p>'
			+ '<div class="g-bar"><i style="width:' + Math.round((cur - 1) / 4 * 100) + '%"></i></div><p class="small muted">Stage ' + cur + ' of 5</p></section>'
			+ routine + '<ol class="g-list">' + STAGES.map(card).join('') + '</ol>'
			+ '<p class="small muted center-text">' + (cur < 5 ? 'Already know your way around? <button class="link small" id="all">Show me everything</button>' : '') + ' · <a href="' + esc(j.guide) + '" target="_blank" rel="noopener">Full guide (Arabic)</a></p>');
		$app.querySelectorAll('[data-go]').forEach(function (b) { b.onclick = function () { go(b.dataset.go); }; });
		var all = $app.querySelector('#all');
		if (all) all.onclick = function () { setPref('lensAll', '1'); S.stage = 5; window.SawaAppStage = 5; toast('Everything is open'); guide(); };
	}

	/* ── The one app: Today, Learn and Me ─────────────── */
	function trkData() { return S.trk || (window.SawaTracker && window.SawaTracker.data()) || null; }
	/** Today: a short plan with the next step highlighted, then today's stats and quest from the tracker. */
	function today() {
		if (S.gate) { shell('<section class="stack"><h1>Welcome 👋</h1><p class="muted">One quick step first (below). Then everything opens.</p></section>'); return; }
		shell('<section class="card stack" id="plan"><p class="loading">Making your plan…</p></section><section class="card stack" id="ilove" hidden></section><section class="card" id="expo" hidden></section><section class="stack" id="prog"></section>' + meTiles());
		paintExposure();
		paintInterestCard();
		bindMeTiles();
		paintProgress($app.querySelector('#prog'));
		api('review/settings', { body: {} }).then(function (x) { S.reviewStats = x; }).catch(function () {});
		api('today').then(function (p) {
			if (S.view !== 'today') return;
			S.due = p.due; paintNav();
			var next = p.items.findIndex(function (x) { return !x.done; });
			var box = $app.querySelector('#plan'); if (!box) return;
			var stage = STAGES[S.stage - 1], step = stage.steps.filter(function (x) { return !x.optional && !x.done(S.J); })[0];
			box.innerHTML = '<div class="row-between"><h3>' + (p.perfect ? 'Perfect day 🎉' : 'Your plan for today') + '</h3><span class="pill' + (p.perfect ? ' mastered' : '') + '">' + (p.perfect ? '+50 XP · +40 🪙 today' : '+5 XP & 5 🪙 each · bonus for all') + '</span></div><ol class="plan">' + p.items.map(function (x, i) {
				return '<li class="' + (x.done ? 'ok' : i === next ? 'next' : '') + '"><span class="g-check">' + (x.done ? '✓' : i + 1) + '</span><div><b>' + esc(x.t) + '</b><span class="small muted">' + esc(x.sub) + '</span></div>' + (!x.done ? '<button class="btn sm' + (i === next ? '' : ' ghost') + '" data-go="' + esc(x.go) + '">' + ({ review: 'Start', scan: 'Scan', chat: 'Chat', log: 'Log' }[x.go] || 'Go') + '</button>' : '') + '</li>';
			}).join('') + '</ol>'
				+ (p.minutes ? '<p class="small muted">⏱ ' + p.minutes + ' min counted automatically today — no need to log it.</p>' : '')
				+ (S.stage < 5 && step ? '<button class="stage-tip" data-start><span class="pill open">Stage ' + S.stage + ' of 5</span> Next: ' + esc(step.t) + ' →</button>' : '');
			if (p.gained) toast('+' + p.gained + ' XP');
			box.querySelectorAll('[data-go]').forEach(function (b) {
				b.onclick = function () { var g = b.dataset.go; if (g === 'log') { if (window.SawaTracker) window.SawaTracker.act('log'); } else if (g.indexOf('watch-') === 0) go('watch', +g.slice(6)); else go(g); };
			});
			var sg = box.querySelector('[data-start]'); if (sg) sg.onclick = function () { go('start'); };
		}, function () { var box = $app.querySelector('#plan'); if (box) box.innerHTML = '<p class="muted">Your plan couldn\'t load. Pull down to refresh.</p>'; });
	}
	/** English you met today with Language Lens: words read on websites, heard in videos, and looked up out of curiosity. */
	function paintExposure() {
		api('daily').then(function (d) {
			var box = $app.querySelector('#expo'), t = d.today, w = d.week, n = function (x) { return (+x || 0).toLocaleString('en-US'); };
			if (!box || (!w.read && !w.heard && !w.curious)) return;
			box.hidden = false;
			box.innerHTML = '<h3>English you met today</h3><div class="me-sum expo"><div><b>' + n(t.read) + '</b><span>📖 words read</span></div><div><b>' + n(t.heard) + '</b><span>🎧 words heard in videos</span></div><div><b>' + n(t.curious) + '</b><span>💡 curious about</span></div></div>'
				+ '<p class="small muted">This week: ' + n(w.read) + ' words read · ' + n(w.heard) + ' heard · curious about ' + n(w.curious) + ' words (' + n(d.curiousTotal) + ' in all). Every word you meet counts, even the ones you don\'t save.</p>';
		}, function () {});
	}
	/** Learn: the learning tools, opening one by one with the stages. */
	function learnHub() {
		var tools = [
			['review', 'Review', 'Your words and sentences come back before you forget them', 2],
			['read', 'Read a text', 'Paste any English text → tap words, listen to it', 1],
			['scan', 'Scan a page', 'Photo or screenshot of a page → tap words for meanings', 1],
			['interests', 'Find content you\'ll love', 'What you enjoy in Arabic → English creators, music and books', 1],
			['write', 'Write & speak', 'Write or talk; get every correction', 3],
			['skills', 'Skill tree', 'Grammar, tone and pronunciation, step by step', 3]
		];
		shell('<section class="stack"><h1>Learn</h1></section><div class="hub">' + tools.map(function (x) {
			var open = unlocked(x[0]);
			return '<button class="hub-t' + (open ? '' : ' locked') + '" data-t="' + x[0] + '"' + (open ? '' : ' disabled') + '><span class="hub-i">' + ICONS[x[0] === 'scan' || x[0] === 'read' ? 'library' : x[0]] + '</span><b>' + x[1] + (x[0] === 'review' && S.due && open ? ' <i class="badge static">' + S.due + '</i>' : '') + '</b><span class="small muted">' + (open ? x[2] : '🔒 Opens at stage ' + x[3]) + '</span></button>';
		}).join('') + '</div>' + (S.stage < 5 ? '<p class="small muted center-text">New tools open as you go. <button class="link small" data-start>See your stages</button></p>' : ''));
		$app.querySelectorAll('[data-t]').forEach(function (b) { b.onclick = function () { go(b.dataset.t); }; });
		var sg = $app.querySelector('[data-start]'); if (sg) sg.onclick = function () { go('start'); };
	}
	/* ── Progress charts (home page) ─────────────────────
	 * Small inline-SVG charts: thin marks, a recessive grid, one hue per job, and a hover/tap tooltip on every mark.
	 * Colours: blue = the learner's activity (sequential steps for the calendar), orange = the second series.
	 */
	var CH = { blue: '#2a78d6', orange: '#eb6834', seq: ['#EEF0F2', '#b7d3f6', '#86b6ef', '#3987e5', '#1c5cab'], grid: '#e1e0d9', axis: '#898781' };
	function fmtDay(d, long) {
		var x = new Date(d + 'T12:00:00');
		return x.toLocaleDateString('en-GB', long ? { weekday: 'short', day: 'numeric', month: 'short' } : { day: 'numeric', month: 'short' });
	}
	function nf(n) { return (+n || 0).toLocaleString('en-US'); }
	/** One tooltip for every chart: marks carry data-tip; hover or tap shows it. */
	function bindTips(root) {
		var tip = document.getElementById('ch-tip');
		if (!tip) { tip = document.createElement('div'); tip.id = 'ch-tip'; tip.className = 'ch-tip'; tip.hidden = true; document.body.appendChild(tip); }
		var show = function (e) {
			var m = e.target.closest('[data-tip]'); if (!m || !root.contains(m)) { tip.hidden = true; return; }
			tip.innerHTML = m.getAttribute('data-tip'); tip.hidden = false;
			var r = m.getBoundingClientRect(), w = tip.offsetWidth;
			tip.style.left = Math.max(8, Math.min(innerWidth - w - 8, r.left + r.width / 2 - w / 2)) + 'px';
			tip.style.top = Math.max(8, r.top - tip.offsetHeight - 8) + 'px';
			root.querySelectorAll('.hot').forEach(function (x) { x.classList.remove('hot'); }); m.classList.add('hot');
		};
		var hide = function () { tip.hidden = true; root.querySelectorAll('.hot').forEach(function (x) { x.classList.remove('hot'); }); };
		root.addEventListener('mouseover', show); root.addEventListener('click', show); root.addEventListener('mouseleave', hide);
		window.addEventListener('scroll', hide, { passive: true });
	}
	/** Bars over time. rows: [[label, value, tip]]; opts: { color, ref (a reference line value), refLabel, max, stack: [[label, v1, v2, tip]] } */
	function barChart(rows, o) {
		o = o || {};
		var W = CH.w || 600, H = 150, pl = 30, pb = 20, pt = 8, n = rows.length, bw = (W - pl) / n, gap = Math.min(6, bw * 0.25);
		var max = o.max || Math.max(1, Math.max.apply(null, rows.map(function (r) { return o.stack ? r[1] + r[2] : r[1]; })));
		var nice = max <= 5 ? 5 : max <= 10 ? 10 : Math.ceil(max / 4 / Math.pow(10, Math.floor(Math.log10(max / 4)))) * Math.pow(10, Math.floor(Math.log10(max / 4))) * 4;
		if (o.max) nice = o.max;
		var y = function (v) { return pt + (H - pt - pb) * (1 - v / nice); };
		var g = '';
		[0, 0.5, 1].forEach(function (f) { var v = Math.round(nice * f), yy = y(v); g += '<line x1="' + pl + '" x2="' + W + '" y1="' + yy + '" y2="' + yy + '" stroke="' + CH.grid + '" stroke-width="1"/><text x="' + (pl - 6) + '" y="' + (yy + 4) + '" text-anchor="end" class="ch-ax">' + nf(v) + (o.unit || '') + '</text>'; });
		var bars = rows.map(function (r, i) {
			var x = pl + i * bw + gap / 2, w = Math.max(2, bw - gap), out = '';
			if (o.stack) {
				var y1 = y(r[1]), y2 = y(r[1] + r[2]);
				if (r[1]) out += '<rect x="' + x + '" y="' + y1 + '" width="' + w + '" height="' + (y(0) - y1) + '" rx="2" fill="' + CH.blue + '"/>';
				if (r[2]) out += '<rect x="' + x + '" y="' + y2 + '" width="' + w + '" height="' + Math.max(0, y1 - y2 - (r[1] ? 2 : 0)) + '" rx="2" fill="' + CH.orange + '"/>';
			} else if (r[1] != null) {
				var yy = y(r[1]);
				out += '<rect x="' + x + '" y="' + yy + '" width="' + w + '" height="' + Math.max(r[1] ? 2 : 0, y(0) - yy) + '" rx="3" fill="' + (o.color || CH.blue) + '"/>';
			}
			// a hit area taller than the bar, so small bars are easy to hover or tap
			out += '<rect class="ch-hit" x="' + (pl + i * bw) + '" y="' + pt + '" width="' + bw + '" height="' + (H - pt - pb) + '" fill="transparent" data-tip="' + esc(r[r.length - 1]) + '"/>';
			if (o.every && (n - 1 - i) % o.every === 0) out += '<text x="' + (pl + i * bw + bw / 2) + '" y="' + (H - 4) + '" text-anchor="middle" class="ch-ax">' + esc(r[0]) + '</text>';
			return out;
		}).join('');
		var ref = o.ref != null ? '<line x1="' + pl + '" x2="' + W + '" y1="' + y(o.ref) + '" y2="' + y(o.ref) + '" stroke="' + CH.axis + '" stroke-dasharray="4 4" stroke-width="1"/><text x="' + W + '" y="' + (y(o.ref) - 4) + '" text-anchor="end" class="ch-ax">' + esc(o.refLabel || '') + '</text>' : '';
		return '<svg class="ch" viewBox="0 0 ' + W + ' ' + H + '" role="img" aria-label="' + esc(o.label || '') + '">' + g + bars + ref + '</svg>';
	}
	/** A line over time with a dot per day on hover. rows: [[date, value]] */
	function lineChart(rows, o) {
		o = o || {};
		var W = CH.w || 600, H = 150, pl = 40, pb = 20, pt = 10, n = rows.length;
		var vals = rows.map(function (r) { return r[1]; }), lo = Math.min.apply(null, vals), hi = Math.max.apply(null, vals);
		if (hi === lo) { hi = lo + 10; }
		var pad = (hi - lo) * 0.15; lo = Math.max(0, Math.floor(lo - pad)); hi = Math.ceil(hi + pad);
		var x = function (i) { return pl + (W - pl - 8) * i / Math.max(1, n - 1); }, y = function (v) { return pt + (H - pt - pb) * (1 - (v - lo) / (hi - lo)); };
		var g = '';
		[lo, Math.round((lo + hi) / 2), hi].forEach(function (v) { g += '<line x1="' + pl + '" x2="' + W + '" y1="' + y(v) + '" y2="' + y(v) + '" stroke="' + CH.grid + '"/><text x="' + (pl - 6) + '" y="' + (y(v) + 4) + '" text-anchor="end" class="ch-ax">' + nf(v) + '</text>'; });
		var d = rows.map(function (r, i) { return (i ? 'L' : 'M') + x(i).toFixed(1) + ' ' + y(r[1]).toFixed(1); }).join(' ');
		var area = d + ' L' + x(n - 1) + ' ' + y(lo) + ' L' + x(0) + ' ' + y(lo) + ' Z';
		var hits = rows.map(function (r, i) {
			return '<g class="ch-pt" data-tip="' + esc('<b>' + nf(r[1]) + '</b> ' + (o.what || '') + '<br>' + fmtDay(r[0], true)) + '"><rect x="' + (x(i) - (W - pl) / n / 2) + '" y="' + pt + '" width="' + ((W - pl) / n) + '" height="' + (H - pt - pb) + '" fill="transparent"/><line class="ch-x" x1="' + x(i) + '" x2="' + x(i) + '" y1="' + pt + '" y2="' + (H - pb) + '" stroke="' + CH.axis + '" stroke-width="1"/><circle class="ch-dot" cx="' + x(i) + '" cy="' + y(r[1]) + '" r="4.5" fill="' + CH.blue + '" stroke="#fff" stroke-width="2"/></g>';
		}).join('');
		var lab = [0, Math.floor((n - 1) / 2), n - 1].map(function (i) { return '<text x="' + x(i) + '" y="' + (H - 4) + '" text-anchor="' + (i === 0 ? 'start' : i === n - 1 ? 'end' : 'middle') + '" class="ch-ax">' + fmtDay(rows[i][0]) + '</text>'; }).join('');
		return '<svg class="ch" viewBox="0 0 ' + W + ' ' + H + '" role="img" aria-label="' + esc(o.label || '') + '">' + g + '<path d="' + area + '" fill="' + CH.blue + '" opacity=".08"/><path d="' + d + '" fill="none" stroke="' + CH.blue + '" stroke-width="2" stroke-linejoin="round"/>' + hits + lab + '</svg>';
	}
	/** 12-week study calendar: one square per day, darker = more reviews. */
	function heatmap(rows) {
		var first = new Date(rows[0][0] + 'T12:00:00').getDay(), cells = '', s = 13, gp = 3;
		var max = Math.max(1, Math.max.apply(null, rows.map(function (r) { return r[1]; })));
		var step = function (v) { return !v ? 0 : v < max * 0.25 ? 1 : v < max * 0.5 ? 2 : v < max * 0.75 ? 3 : 4; };
		var off = (first + 6) % 7; // weeks start on Monday
		rows.forEach(function (r, i) {
			var k = i + off, col = Math.floor(k / 7), row = k % 7;
			cells += '<rect x="' + (col * (s + gp)) + '" y="' + (row * (s + gp)) + '" width="' + s + '" height="' + s + '" rx="3" fill="' + CH.seq[step(r[1])] + '" data-tip="' + esc((r[1] ? '<b>' + r[1] + '</b> cards reviewed' : 'No reviews') + '<br>' + fmtDay(r[0], true)) + '"/>';
		});
		var cols = Math.ceil((rows.length + off) / 7), W = cols * (s + gp), H = 7 * (s + gp);
		return '<svg class="ch heat" viewBox="0 0 ' + W + ' ' + H + '" role="img" aria-label="Days you reviewed cards in the last 12 weeks">' + cells + '</svg>'
			+ '<div class="ch-key small muted">Less ' + CH.seq.map(function (c) { return '<i style="background:' + c + '"></i>'; }).join('') + ' More</div>';
	}
	/** Home: your progress in pictures. */
	function paintProgress(box) {
		api('review/history').then(function (h) {
			if (!box.isConnected) return;
			CH.w = Math.max(280, Math.min(640, box.clientWidth - 34)); // chart width in px = viewBox width, so text isn't shrunk
			var R = R0(), wk = h.weeks.filter(function (w) { return w[1] != null; });
			var st = h.stages, totalCards = st.fresh + st.learning + st.young + st.mature;
			var known = h.known, gained = known[known.length - 1][1] - known[0][1];
			var activeDays = h.reviews.slice(-28).filter(function (r) { return r[1]; }).length;
			var met = h.met, metTot = met.reduce(function (a, r) { return a + r[1] + r[2]; }, 0);
			var stageBar = totalCards ? [['New', st.fresh, CH.seq[1]], ['Learning', st.learning, CH.seq[2]], ['Getting there', st.young, CH.seq[3]], ['Long-term', st.mature, CH.seq[4]]].map(function (x) { return x[1] ? '<i style="flex:' + x[1] + ';background:' + x[2] + '" data-tip="' + esc('<b>' + x[1] + '</b> ' + x[0].toLowerCase() + ' cards') + '"></i>' : ''; }).join('') : '';
			box.innerHTML = '<h2>Your progress</h2>'
				+ '<div class="ch-tiles"><div><b>' + nf(known[known.length - 1][1]) + '</b><span>words known' + (gained ? ' · <em>+' + nf(gained) + '</em> in 60 days' : '') + '</span></div><div><b>' + activeDays + '<small>/28</small></b><span>study days this month</span></div><div><b>' + (R.ret != null ? R.ret + '%' : '–') + '</b><span>cards remembered this week</span></div><div><b>' + nf(st.mature) + '</b><span>cards in long-term memory</span></div></div>'
				+ '<section class="card"><h3>Study calendar</h3><p class="small muted">Every square is a day. Keep the chain going — even 5 cards count.</p>' + heatmap(h.reviews) + '</section>'
				+ '<section class="card"><h3>Words you know</h3>' + lineChart(known, { what: 'words known', label: 'Words known over the last 60 days' }) + '</section>'
				+ (wk.length ? '<section class="card"><h3>How much you remember</h3><p class="small muted">Review cards you got right each week. Around 90% is the sweet spot; the dashed line (80%) unlocks the next card level.</p>' + barChart(h.weeks.map(function (w) { return [fmtDay(w[0]), w[1], w[1] == null ? 'No reviews this week' : '<b>' + w[1] + '%</b> remembered (' + w[2] + ' reviews)<br>week of ' + fmtDay(w[0])]; }), { max: 100, unit: '%', ref: 80, every: 2, label: 'Percent of review cards remembered each week' }) + '</section>' : '')
				+ (totalCards ? '<section class="card"><h3>Your cards</h3><div class="ch-stack">' + stageBar + '</div><div class="ch-legend small">' + [['New', st.fresh, 1], ['Learning', st.learning, 2], ['Getting there', st.young, 3], ['Long-term', st.mature, 4]].map(function (x) { return '<span><i style="background:' + CH.seq[x[2]] + '"></i>' + x[0] + ' <b>' + nf(x[1]) + '</b></span>'; }).join('') + '</div>'
					+ '<h3 style="margin-top:16px">Coming up</h3><p class="small muted">Reviews waiting for you in the next 14 days.</p>' + barChart(h.forecast.map(function (f, i) { return [i ? fmtDay(f[0]).split(' ')[0] : 'Today', f[1], '<b>' + f[1] + '</b> reviews<br>' + (i ? fmtDay(f[0], true) : 'today')]; }), { every: 2, label: 'Reviews due in the next 14 days' }) + '</section>' : '')
				+ (metTot ? '<section class="card"><h3>English you met</h3><div class="ch-legend small"><span><i style="background:' + CH.blue + '"></i>📖 read</span><span><i style="background:' + CH.orange + '"></i>🎧 heard in videos</span></div>' + barChart(met.map(function (r) { return [fmtDay(r[0]).split(' ')[0], r[1], r[2], '<b>' + nf(r[1] + r[2]) + '</b> words<br>📖 ' + nf(r[1]) + ' read · 🎧 ' + nf(r[2]) + ' heard' + (r[3] ? '<br>💡 curious about ' + r[3] : '') + '<br>' + fmtDay(r[0], true)]; }), { stack: true, every: 2, label: 'Words read and heard each day, last 14 days' }) + '</section>' : '');
			bindTips(box);
		}, function () { box.innerHTML = ''; });
	}
	function R0() { return { ret: S.reviewStats ? S.reviewStats.retention : null }; }
	/** Your things (library, mistakes, stages, level test, the extension), at the bottom of Home. */
	function meTiles() {
		var j = S.J || {};
		var tiles = [['library', 'My library', 'Words, phrases and sentences you saved', 'library'], ['progress', 'My mistakes', 'Are your mistakes going down?', 'progress'], ['start', 'Your stages', 'Stage ' + S.stage + ' of 5', 'start'], ['placement', 'Level test', j.placement ? 'Your level: ' + j.placement.level + ' · ' + j.placement.score + '/100' : 'Find your level (A1–C2)', 'skills']].filter(function (x) { return x[0] === 'start' || unlocked(x[0]); });
		return '<h2>Your things</h2><div class="hub">' + tiles.map(function (x) {
			return '<button class="hub-t" data-t="' + x[0] + '"><span class="hub-i">' + ICONS[x[3]] + '</span><b>' + x[1] + '</b><span class="small muted">' + esc(x[2]) + '</span></button>';
		}).join('') + '</div>'
			+ '<section class="card row-between"><div><b>Language Lens for Chrome</b><p class="small muted">' + (j.extension ? 'Connected ✓ — meanings on YouTube and any website.' : 'Not connected yet. Use it on a computer.') + '</p></div>' + (!j.extension && j.store ? '<a class="btn sm" href="' + esc(j.store) + '" target="_blank" rel="noopener">Install</a>' : '') + '</section>';
	}
	function bindMeTiles() {
		$app.querySelectorAll('[data-t]').forEach(function (b) { b.onclick = function () { if (b.dataset.t === 'placement') location.href = '/placement/'; else go(b.dataset.t); }; });
	}
	/** Me: your stuff — library, progress, the guide, the extension — then your tracker stats. */
	function meHub() {
		var j = S.J || {};
		var tiles = [['library', 'My library', 'Words, phrases and sentences you saved', 'library'], ['progress', 'Progress', 'Are your mistakes going down?', 'progress'], ['start', 'Your stages', 'Stage ' + S.stage + ' of 5', 'start'], ['placement', 'Level test', j.placement ? 'Your level: ' + j.placement.level + ' · ' + j.placement.score + '/100' : 'Find your level (A1–C2)', 'skills']].filter(function (x) { return x[0] === 'start' || unlocked(x[0]); });
		var tr = trkData() || {}, st = tr.stats || {}, xp = tr.xp || {};
		var sum = '<section class="card me-sum"><div><b>' + (j.placement ? esc(j.placement.level) : '–') + '</b><span>' + (j.placement ? 'your level' : '<a href="/placement/">find your level</a>') + '</span></div><div><b>' + (st.streak || 0) + '🔥</b><span>day streak</span></div><div><b>' + (j.known != null ? j.known : j.learning || 0) + '</b><span>' + (j.known != null ? 'words known' : 'words saved') + '</span></div><div><b>' + (xp.total || 0) + '</b><span>XP</span></div></section>';
		shell('<section class="stack"><h1>Me</h1></section>' + sum + '<div class="hub">' + tiles.map(function (x) {
			return '<button class="hub-t" data-t="' + x[0] + '"><span class="hub-i">' + ICONS[x[3]] + '</span><b>' + x[1] + '</b><span class="small muted">' + esc(x[2]) + '</span></button>';
		}).join('') + '</div>'
			+ '<section class="card row-between"><div><b>Language Lens for Chrome</b><p class="small muted">' + (j.extension ? 'Connected ✓ — meanings on YouTube and any website.' : 'Not connected yet. Use it on a computer.') + '</p></div>' + (!j.extension && j.store ? '<a class="btn sm" href="' + esc(j.store) + '" target="_blank" rel="noopener">Install</a>' : '') + '</section>');
		$app.querySelectorAll('[data-t]').forEach(function (b) { b.onclick = function () { if (b.dataset.t === 'placement') location.href = '/placement/'; else go(b.dataset.t); }; });
	}

	/* ── Write · Speak · Scan ─────────────────────────── */
	function modeSeg(cur) {
		return '<div class="seg modes" role="group">' + [['write', '✍️ Write'], ['speak', '🎙 Speak'], ['scan', '📷 Scan a page']].filter(function (m) { return unlocked(m[0]); }).map(function (m) {
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
	/** Read a text: paste any English text and use it like a scanned page (tap words, save, listen). */
	function readView() {
		shell('<section class="stack"><h1>Read a text</h1><p class="muted">Paste any English text or upload a PDF — an article, a story, a lesson. Read it as it is, or let AI rewrite it at <b>your level</b>: almost every word one you know, plus a few new ones to learn. Tap a word for its meaning, save it, or listen to the whole text.</p></section>'
			+ '<textarea id="rtext" rows="7" dir="ltr" maxlength="40000" placeholder="Paste English text here"></textarea>'
			+ '<div class="ra-btns"><label class="btn ghost" for="rpdf">📄 Upload a PDF</label><input id="rpdf" type="file" accept="application/pdf,.pdf" hidden><span class="small muted" id="rinfo"></span></div>'
			+ '<input id="rtitle" dir="auto" maxlength="120" placeholder="Title (optional)">'
			+ '<div class="ra-btns"><button class="btn" id="radapt">✨ Adapt to my level</button><button class="btn ghost" id="rgo">Read as it is</button></div><p class="err" id="er"></p><section id="scan" class="stack"></section>');
		var ta = $app.querySelector('#rtext'), er = $app.querySelector('#er'), info = $app.querySelector('#rinfo');
		var count = function () { var n = (ta.value.match(/[A-Za-z]+/g) || []).length; info.textContent = n ? n.toLocaleString('en-US') + ' words' + (n > ADAPT_MAX ? ' · the first ' + ADAPT_MAX.toLocaleString('en-US') + ' can be adapted' : '') : ''; };
		ta.oninput = function () { er.textContent = ''; count(); };
		var text = function () {
			var t = ta.value.trim();
			if (!t) { er.textContent = 'Paste a text or upload a PDF first.'; return ''; }
			var ar = (t.match(/[؀-ۿ]/g) || []).length, all = (t.match(/[A-Za-z؀-ۿ]/g) || []).length;
			if (all && ar / all > 0.3) { er.textContent = 'This looks like Arabic. Paste an English text.'; return ''; }
			return t;
		};
		var paras = function (t) { return t.split(/\n\s*\n|\n/).map(function (p) { return p.trim(); }).filter(Boolean).slice(0, 300); };
		$app.querySelector('#rgo').onclick = function () {
			var t = text(); if (!t) return;
			SCAN = { title: $app.querySelector('#rtitle').value.trim() || 'My text', paragraphs: paras(t) };
			try { localStorage.setItem('lensScan', JSON.stringify(SCAN)); } catch (e) { /* storage blocked */ }
			paintScan();
		};
		$app.querySelector('#radapt').onclick = function () {
			var t = text(); if (!t) return;
			adaptText($app.querySelector('#rtitle').value.trim() || 'My text', paras(t), this);
		};
		$app.querySelector('#rpdf').onchange = function () {
			var f = this.files[0]; if (!f) return;
			er.textContent = ''; info.textContent = 'Reading the PDF…';
			pdfText(f).then(function (r) {
				ta.value = r.text; count();
				if (!$app.querySelector('#rtitle').value) $app.querySelector('#rtitle').value = f.name.replace(/\.pdf$/i, '').slice(0, 120);
				if (!r.text.trim()) er.textContent = 'No text found in this PDF. If it is a scanned book, take a photo of the page in Scan a page instead.';
				else if (r.more) info.textContent += ' · first ' + r.pages + ' pages';
			}, function () { info.textContent = ''; er.textContent = 'This PDF couldn\'t be opened. Try copying the text instead.'; });
		};
	}

	/* ── Graded reading: a text rewritten at your level (Krashen's i+1) ── */
	var ADAPT_MAX = 6000, ADAPT_CHUNK = 550;
	/** The PDF's text, page by page, in the browser (pdf.js); lines are joined back into paragraphs. */
	function pdfText(file) {
		var load = window.pdfjsLib ? Promise.resolve() : new Promise(function (ok, no) {
			var s = document.createElement('script');
			s.src = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js';
			s.onload = ok; s.onerror = no; document.head.appendChild(s);
		});
		return load.then(function () {
			pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
			return file.arrayBuffer();
		}).then(function (buf) { return pdfjsLib.getDocument({ data: buf }).promise; }).then(function (doc) {
			var pages = Math.min(doc.numPages, 40), out = [], chain = Promise.resolve();
			for (var i = 1; i <= pages; i++) (function (i) {
				chain = chain.then(function () { return doc.getPage(i); }).then(function (pg) { return pg.getTextContent(); }).then(function (tc) {
					var lines = [], line = '', lastY = null, gap = 0;
					tc.items.forEach(function (it) {
						var y = it.transform[5];
						if (lastY !== null && Math.abs(y - lastY) > 2) { lines.push({ t: line.trim(), gap: Math.abs(y - lastY) }); line = ''; }
						line += it.str + (it.hasEOL ? ' ' : ''); lastY = y;
					});
					if (line.trim()) lines.push({ t: line.trim(), gap: 0 });
					// a bigger gap than usual between lines = a new paragraph
					var gaps = lines.map(function (l) { return l.gap; }).filter(Boolean).sort(function (a, b) { return a - b; }), usual = gaps[Math.floor(gaps.length / 2)] || 12;
					var para = '';
					lines.forEach(function (l) {
						if (!l.t) return;
						para += (para && !/-$/.test(para) ? ' ' : '') + l.t.replace(/-$/, '');
						if (l.gap > usual * 1.4 || /[.!?:"”]$/.test(l.t) && l.t.length < 45) { out.push(para); para = ''; }
					});
					if (para) out.push(para);
				});
			})(i);
			return chain.then(function () { return { text: out.join('\n\n'), pages: pages, more: doc.numPages > pages }; });
		});
	}
	/** Share of words a learner at this level probably knows (by the dictionary's levels; names and unknown words skipped). */
	function coverage(paragraphs, level) {
		var n = 0, ok = 0;
		paragraphs.forEach(function (p) {
			(p.match(/[A-Za-z]+(?:[’'][a-z]+)?/g) || []).forEach(function (w) {
				if (/^[A-Z]/.test(w) && !/^(I|A|The|An)$/.test(w)) return; // probably a name
				var es = LEX && LEX.lemmas[lemmaOf(w)];
				if (!es) return;
				var lv = Math.min.apply(null, es.map(function (i) { return LEX.entries[i][2]; }));
				n++; if (lv <= level) ok++;
			});
		});
		return n ? Math.round(100 * ok / n) : null;
	}
	/** Rewrites the text chunk by chunk (about 550 words each), so long texts show progress and never time out. */
	function adaptText(title, paragraphs, btn) {
		var chunks = [], cur = [], words = 0, total = 0, er = $app.querySelector('#er');
		paragraphs.forEach(function (p) {
			var w = (p.match(/[A-Za-z]+/g) || []).length;
			if (total >= ADAPT_MAX) return;
			if (words && words + w > ADAPT_CHUNK) { chunks.push(cur); cur = []; words = 0; }
			cur.push(p); words += w; total += w;
		});
		if (cur.length) chunks.push(cur);
		var out = [], targets = {}, level = 0, i = 0, box = $app.querySelector('#scan');
		btn.disabled = true;
		var step = function () {
			if (i >= chunks.length) return done();
			btn.textContent = '✨ Adapting… ' + (i + 1) + ' of ' + chunks.length;
			box.innerHTML = '<p class="loading">Rewriting part ' + (i + 1) + ' of ' + chunks.length + ' at your level…</p>';
			api('adapt', { body: { text: chunks[i].join('\n\n') } }).then(function (r) {
				out = out.concat(r.paragraphs); level = r.levelNum;
				r.targets.forEach(function (t) { targets[t.word] = t; });
				i++; step();
			}, function (e) { btn.disabled = false; btn.textContent = '✨ Adapt to my level'; box.innerHTML = ''; er.textContent = e.message; if (out.length) done(); });
		};
		var done = function () {
			btn.disabled = false; btn.textContent = '✨ Adapt to my level';
			lex().then(function () {
				SCAN = { title: title, paragraphs: out, adapted: true, level: level, targets: Object.keys(targets).map(function (k) { return targets[k]; }), before: coverage(paragraphs, level), after: coverage(out, level), partial: i < chunks.length };
				try { localStorage.setItem('lensScan', JSON.stringify(SCAN)); } catch (e) { /* storage blocked */ }
				paintScan();
			});
		};
		step();
	}
	/** Above an adapted text: how much easier it got, and its new words (tap to hear, + to learn). */
	function adaptedHead() {
		if (!SCAN || !SCAN.adapted) return '';
		var lv = ['A1', 'A2', 'B1', 'B2', 'C1', 'C2'][(SCAN.level || 2) - 1];
		return '<section class="card adapted"><div class="row-between"><b>✨ Rewritten for ' + lv + '</b>' + (SCAN.before != null && SCAN.after != null ? '<span class="pill">' + SCAN.before + '% → <b>' + SCAN.after + '%</b> words at your level</span>' : '') + '</div>'
			+ (SCAN.partial ? '<p class="small muted">Only part of the text was adapted. Try again later for the rest.</p>' : '')
			+ (SCAN.targets && SCAN.targets.length ? '<p class="small muted">New words in this text (underlined) — your +1. Tap ＋ to add one to your review cards.</p><ul class="tg-list">' + SCAN.targets.map(function (t, i) {
				return '<li><button class="say sm" data-say="' + esc(t.word) + '" aria-label="Listen">🔊</button><b dir="ltr">' + esc(t.word) + '</b><span class="small muted" dir="ltr">' + esc(t.en) + '</span><span class="small" dir="rtl">' + esc(t.ar) + '</span><button class="btn ghost sm" data-tgl="' + i + '">＋</button></li>';
			}).join('') + '</ul>' : '') + '</section>';
	}
	function scanView() {
		try { SCAN = SCAN || JSON.parse(localStorage.getItem('lensScan') || 'null'); } catch (e) { /* storage blocked */ }
		shell(modeSeg('scan') + '<section class="stack"><h1>Scan a page</h1><p class="muted">Take a photo of a book page, worksheet or screenshot. Tap any word for its meaning, mark it as Learning, or save the sentence.</p></section>'
			+ '<div class="scan-in" id="drop"><div class="ra-btns"><label class="btn" for="cam">📷 Take a photo</label><label class="btn" for="pick">🖼 Upload an image</label><button class="btn ghost" id="pastebtn">📋 Paste a screenshot</button></div>'
			+ '<p class="small muted">Or press Ctrl+V (⌘V on Mac) to paste a screenshot, or drop an image here.</p></div>'
			+ '<input id="cam" type="file" accept="image/*" capture="environment" hidden><input id="pick" type="file" accept="image/*" hidden>'
			+ '<p class="err" id="er"></p><section id="scan" class="stack"></section>');
		bindModes();
		$app.querySelector('#cam').onchange = function () { if (this.files[0]) doScan(this.files[0]); };
		$app.querySelector('#pick').onchange = function () { if (this.files[0]) doScan(this.files[0]); };
		// Paste a screenshot: the Paste button (asks the browser once), or Ctrl+V anywhere on this screen.
		$app.querySelector('#pastebtn').onclick = function () {
			var er = $app.querySelector('#er');
			if (!navigator.clipboard || !navigator.clipboard.read) { er.textContent = 'Press Ctrl+V (⌘V on Mac) to paste your screenshot.'; return; }
			navigator.clipboard.read().then(function (items) {
				for (var i = 0; i < items.length; i++) { var t = items[i].types.filter(function (x) { return /^image\//.test(x); })[0]; if (t) return items[i].getType(t).then(doScan); }
				er.textContent = 'There is no picture to paste. Take a screenshot first (on Windows: Win+Shift+S), then press Paste.';
			}, function () { er.textContent = 'Press Ctrl+V (⌘V on Mac) to paste your screenshot.'; });
		};
		var drop = $app.querySelector('#drop');
		drop.ondragover = function (e) { e.preventDefault(); drop.classList.add('over'); };
		drop.ondragleave = function () { drop.classList.remove('over'); };
		drop.ondrop = function (e) { e.preventDefault(); drop.classList.remove('over'); var f = e.dataTransfer.files[0]; if (f && /^image\//.test(f.type)) doScan(f); };
		if (SCAN) paintScan();
	}
	document.addEventListener('paste', function (e) {
		if (S.view !== 'scan' || !e.clipboardData) return;
		var items = e.clipboardData.items || [];
		for (var i = 0; i < items.length; i++) if (/^image\//.test(items[i].type)) { var f = items[i].getAsFile(); if (f) { e.preventDefault(); doScan(f); return; } }
	});
	/* Listen to the whole text: paragraph by paragraph, the one being read is highlighted. */
	var READ = null;
	function stopReading() { if (READ) { READ = null; if ('speechSynthesis' in window) speechSynthesis.cancel(); $app.querySelectorAll('.scan-text p.reading').forEach(function (p) { p.classList.remove('reading'); }); var b = $app.querySelector('#readall'); if (b) b.textContent = '🔊 Listen to the whole text'; } }
	function readAll(btn) {
		if (READ) return stopReading();
		if (!('speechSynthesis' in window)) return toast('Your browser can\'t play audio.');
		var paras = SCAN.paragraphs, i = 0, run = READ = {}, vs = speechSynthesis.getVoices();
		var v = vs.filter(function (x) { return /en[-_]US/i.test(x.lang) && /Google|Samantha|Aria|Jenny|Natural/i.test(x.name); })[0] || vs.filter(function (x) { return /en[-_]US/i.test(x.lang); })[0] || null;
		btn.textContent = '■ Stop reading';
		speechSynthesis.cancel();
		(function next() {
			if (READ !== run) return;
			$app.querySelectorAll('.scan-text p.reading').forEach(function (p) { p.classList.remove('reading'); });
			if (i >= paras.length) return stopReading();
			var el = $app.querySelector('.scan-text p[data-p="' + i + '"]'); if (el) { el.classList.add('reading'); el.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); }
			var u = new SpeechSynthesisUtterance(paras[i]); u.lang = 'en-US'; u.rate = +(($app.querySelector('#readrate') || {}).value || 0.9); if (v) u.voice = v;
			u.onend = function () { i++; next(); }; u.onerror = function () { stopReading(); };
			speechSynthesis.speak(u);
		})();
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
	/** The Silk Road game (in the tracker) uses the same dictionary, word saving and review cards. */
	window.SawaLens = { lex: function () { return lex(); }, meanings: function (l) { return meanings(l); }, lemmaOf: function (w) { return lemmaOf(w); }, api: function (p, o) { return api(p, o); } };
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
			var tg = {}; (SCAN.targets || []).forEach(function (t) { tg[t.word] = 1; });
			box.innerHTML = (SCAN.title ? '<h2>' + esc(SCAN.title) + '</h2>' : '') + adaptedHead() + '<div class="ra-btns"><button class="btn" id="readall">🔊 Listen to the whole text</button><label class="small">Speed <select id="readrate"><option value="0.75">Slow</option><option value="0.9" selected>Normal</option><option value="1.05">Fast</option></select></label></div><p class="small muted">Tap a word for its meaning · select words to save a phrase or a sentence</p><div class="card scan-text" dir="ltr">' + SCAN.paragraphs.map(function (p) {
				var idx = pi++;
				return '<p data-p="' + idx + '">' + esc(p).replace(/[A-Za-z]+(?:[’'][A-Za-z]+)?/g, function (w) { return '<span class="sw' + (tg[w.toLowerCase()] || tg[lemmaOf(w)] ? ' tg' : '') + '">' + w + '</span>'; }) + '</p>';
			}).join('') + '</div><div id="wcard"></div>';
			box.querySelector('#readall').onclick = function () { readAll(this); };
			box.querySelectorAll('[data-tgl]').forEach(function (b) {
				b.onclick = function () {
					var t = SCAN.targets[+b.dataset.tgl]; b.disabled = true;
					api('library/word', { body: { lemma: lemmaOf(t.word), status: 'learning', sentence: (SCAN.paragraphs.filter(function (p) { return p.toLowerCase().indexOf(t.word) >= 0; })[0] || '').slice(0, 600), title: SCAN.title } }).then(function () { b.textContent = '✓'; toast('Added to your review cards'); }, function (e) { b.disabled = false; toast(e.message); });
				};
			});
			box.querySelectorAll('.adapted [data-say]').forEach(function (b) { b.onclick = function () { speak(b.dataset.say); }; });
			box.querySelectorAll('.sw').forEach(function (sp) {
				sp.onclick = function () {
					box.querySelectorAll('.sw.on').forEach(function (x) { x.classList.remove('on'); });
					sp.classList.add('on');
					var para = SCAN.paragraphs[+sp.parentNode.dataset.p], word = sp.textContent;
					var sents = para.match(/[^.!?]+[.!?]*/g) || [para], sentence = sents.filter(function (x) { return x.indexOf(word) >= 0; })[0] || para;
					wordCard(word, sentence.trim());
				};
			});
			// Select any part of the text (finger or mouse) to save exactly that part.
			var txt = box.querySelector('.scan-text');
			var onSel = function () {
				setTimeout(function () {
					var s = window.getSelection(), t = s ? String(s).replace(/\s+/g, ' ').trim() : '';
					if (t.length < 3 || t.length > 600 || !s.anchorNode || !txt.contains(s.anchorNode)) return;
					var pEl = (s.anchorNode.nodeType === 1 ? s.anchorNode : s.anchorNode.parentElement).closest('p[data-p]');
					saveEditor(t, sentenceAround(pEl ? SCAN.paragraphs[+pEl.dataset.p] : t, t));
				}, 30);
			};
			txt.addEventListener('mouseup', onSel);
			txt.addEventListener('touchend', onSel);
		});
	}
	/** The sentence of the paragraph that holds this piece (or the piece itself when it is longer). */
	function sentenceAround(para, piece) {
		var sents = String(para).match(/[^.!?]+[.!?]*/g) || [para];
		var hit = sents.filter(function (x) { return x.indexOf(piece) >= 0; })[0];
		return (hit || (piece.length > 60 ? piece : para)).trim();
	}
	/** Save a phrase or sentence the student chose and can edit first: only what is in the box is saved. */
	function saveEditor(text, sentence) {
		var el = $app.querySelector('#wcard'); if (!el) return;
		var words = text.split(/\s+/).length, phraseFirst = words <= 6;
		el.innerHTML = '<section class="card stack savebox"><b>Save to your review cards</b><p class="small muted">Change the words if you like. Only what is in the box is saved.</p>'
			+ '<textarea id="sv-t" rows="3" dir="ltr" maxlength="600">' + esc(text) + '</textarea>'
			+ '<div class="ra-btns"><button class="btn' + (phraseFirst ? '' : ' ghost') + '" data-k="phrase">Save as a phrase</button><button class="btn' + (phraseFirst ? ' ghost' : '') + '" data-k="sentence">Save as a sentence</button><button class="link small" id="sv-x">Cancel</button></div>'
			+ '<p class="small muted" id="sv-ctx">' + (phraseFirst && sentence !== text ? 'A phrase card shows it inside its sentence: “' + esc(sentence.slice(0, 160)) + '”' : '') + '</p><p class="err" id="sv-er"></p></section>';
		var ta = el.querySelector('#sv-t'), er = el.querySelector('#sv-er');
		ta.oninput = function () { er.textContent = ''; };
		el.querySelector('#sv-x').onclick = function () { el.innerHTML = ''; };
		el.querySelectorAll('[data-k]').forEach(function (b) {
			b.onclick = function () {
				var t = ta.value.replace(/\s+/g, ' ').trim(), kind = b.dataset.k;
				if (t.length < 2) { er.textContent = 'Write the words you want to save.'; return; }
				var body = kind === 'phrase' ? { text: sentence.indexOf(t) >= 0 ? sentence : t, focus: t, kind: 'phrase', title: SCAN.title || 'Scanned page' } : { text: t, focus: '', kind: 'sentence', title: SCAN.title || 'Scanned page' };
				el.querySelectorAll('[data-k]').forEach(function (x) { x.disabled = true; });
				api('library/save', { body: body }).then(function () {
					el.innerHTML = '<section class="card stack savebox"><b>✓ Saved as a ' + kind + '</b><p class="small" dir="ltr">“' + esc(t) + '”</p><p class="small muted">It is in your review cards and your library now.</p></section>';
					try { window.getSelection().removeAllRanges(); } catch (e) { /* ignore */ }
				}, function (e) { er.textContent = e.message; el.querySelectorAll('[data-k]').forEach(function (x) { x.disabled = false; }); });
			};
		});
		el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
	}
	function wordCard(word, sentence) {
		var lemma = lemmaOf(word), ms = meanings(lemma), el = $app.querySelector('#wcard');
		el.innerHTML = '<section class="card stack wcard"><div class="row-between"><b class="rv-word" dir="ltr" style="font-size:26px">' + esc(lemma) + '</b><button class="say sm" data-say="' + esc(lemma) + '">🔊</button></div>'
			+ (ms.length ? ms.slice(0, 2).map(function (m) { return '<div><span class="pill">' + esc(m.pos) + (m.level ? ' · ' + m.level : '') + '</span><p class="ar">' + esc(m.ar) + '</p>' + (m.en ? '<p class="small">' + esc(m.en) + '</p>' : '') + '</div>'; }).join('') : '')
			+ '<div data-ai-word="' + esc(word) + '" data-ai-sent="' + esc(sentence) + '"><button class="link small">' + (ms.length ? 'Meaning in this sentence (AI)' : 'Explain with AI') + '</button></div>'
			+ '<p class="small muted" dir="ltr">“' + esc(sentence) + '”</p><div class="pics" data-pics="' + esc(lemma) + '" data-sent="' + esc(sentence) + '"></div>'
			+ '<div class="ra-btns"><button class="btn sm" id="learn">+ Learning</button><button class="btn ghost sm" id="savesent">Save sentence</button><button class="btn ghost sm" data-say="' + esc(sentence) + '">🔊 Sentence</button></div></section>';
		bindSay();
		bindPics(el);
		var aw = el.querySelector('[data-ai-word]');
		aw.querySelector('button').onclick = function () {
			this.textContent = 'Explaining…';
			api('library/explain', { body: { word: word, sentence: sentence } }).then(function (x) { aw.innerHTML = '<p class="ar rv-ar">' + esc(x.meaning) + '</p>' + (x.note ? '<p class="small muted' + (x.noteLang === 'en' ? '' : ' ar') + '">' + esc(x.note) + '</p>' : '') + (x.sentence ? '<p class="small ar">' + esc(x.sentence) + '</p>' : ''); }, function (e) { aw.textContent = e.message; });
		};
		el.querySelector('#learn').onclick = function () { var b = this; api('library/word', { body: { lemma: lemma, status: 'learning' } }).then(function () { b.textContent = '✓ Learning'; b.disabled = true; toast('Added to your review cards'); }, function (e) { toast(e.message); }); };
		el.querySelector('#savesent').onclick = function () { saveEditor(sentence, sentence); };
		el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
	}

	/* ── Watch: video quizzes (EdPuzzle-style) ────────── */
	var W = null, ytP = null;
	function ytApi() {
		if (window.YT && window.YT.Player) return Promise.resolve(window.YT);
		if (!ytP) ytP = new Promise(function (res) {
			window.onYouTubeIframeAPIReady = function () { res(window.YT); };
			var sc = document.createElement('script'); sc.src = 'https://www.youtube.com/iframe_api'; document.head.appendChild(sc);
		});
		return ytP;
	}
	function fmtT(t) { t = Math.floor(t); return Math.floor(t / 60) + ':' + ('0' + t % 60).slice(-2); }
	function watch(id) {
		if (id) return watchLesson(id);
		shell('<section class="stack"><h1>Watch</h1><p class="muted">Videos from Sherif with questions inside. The video stops and asks you about the words you need — like a teacher next to you.</p></section><p class="loading">Loading…</p>');
		api('videos').then(function (d) {
			shell('<section class="stack"><h1>Watch</h1><p class="muted">Videos from Sherif with questions inside. The video stops and asks you about the words you need. Missed words go to your review cards.</p></section>'
				+ (d.lessons.length ? '<ul class="vids">' + d.lessons.map(function (l) {
					var done = l.answered >= l.total && l.total;
					return '<li><button class="vid" data-l="' + l.id + '"><img src="https://i.ytimg.com/vi/' + esc(l.videoId) + '/mqdefault.jpg" alt="" loading="lazy"><span class="vid-main"><b>' + esc(l.title || 'Video lesson') + '</b>'
						+ '<span class="small muted">' + l.total + ' questions' + (l.answered ? ' · ' + l.correct + '/' + l.total + ' right' : '') + '</span>'
						+ (l.words.length ? '<span class="small muted">' + esc(l.words.slice(0, 6).join(' · ')) + '</span>' : '') + '</span>' + (done ? '<span class="pill mastered">Done</span>' : l.answered ? '<span class="pill open">Continue</span>' : '') + '</button></li>';
				}).join('') + '</ul>' : '<p class="card muted">No video quizzes yet — they\'ll appear here when Sherif publishes one.</p>')
				+ (d.free && d.free.length ? '<h2>Sherif\'s free courses</h2><p class="small muted">Watch on YouTube. On a computer, Language Lens adds meanings and saves your words.</p><ul class="vids">' + d.free.map(function (v) {
					return '<li><a class="vid" href="https://www.youtube.com/watch?v=' + esc(v.videoId) + '" target="_blank" rel="noopener"><img src="https://i.ytimg.com/vi/' + esc(v.videoId) + '/mqdefault.jpg" alt="" loading="lazy"><span class="vid-main"><b dir="auto">' + esc(v.title) + '</b><span class="small muted">YouTube ↗</span></span></a></li>';
				}).join('') + '</ul>' : '')
				+ '<p class="small muted">Tip: on a computer, Language Lens asks the same questions right on YouTube.</p>');
			$app.querySelectorAll('[data-l]').forEach(function (b) { b.onclick = function () { go('watch', +b.dataset.l); }; });
		}, fail);
	}
	function watchLesson(id) {
		shell('<button class="back" id="back">← Videos</button><p class="loading">Loading the video…</p>');
		$app.querySelector('#back').onclick = function () { go('watch'); };
		Promise.all([api('video?id=' + id), ytApi()]).then(function (res) {
			var L = res[0];
			W = { L: L, idx: 0, asking: null, timer: null, player: null };
			shell('<button class="back" id="back">← Videos</button><h2>' + esc(L.title || 'Video lesson') + '</h2>'
				+ '<div class="player"><div id="yt"></div></div>'
				+ '<div class="qmarks" id="qm"></div><section id="qbox" class="stack"></section>');
			$app.querySelector('#back').onclick = function () { go('watch'); };
			paintMarks();
			W.player = new YT.Player('yt', {
				videoId: L.videoId,
				playerVars: { playsinline: 1, rel: 0, modestbranding: 1, cc_load_policy: 1, cc_lang_pref: 'en' },
				events: { onReady: function () { W.timer = setInterval(checkQ, 250); } }
			});
		}, fail);
	}
	function paintMarks() {
		var el = $app.querySelector('#qm'); if (!el) return;
		el.innerHTML = W.L.questions.map(function (q, i) {
			var a = W.L.answers[q.id];
			return '<button class="qm ' + (a ? (a.ok ? 'ok' : 'no') : '') + '" data-qi="' + i + '" title="' + fmtT(q.t) + '">' + (i + 1) + '</button>';
		}).join('') + '<span class="small muted">' + Object.keys(W.L.answers).filter(function (k) { return W.L.answers[k].ok; }).length + '/' + W.L.questions.length + ' right</span>';
		el.querySelectorAll('[data-qi]').forEach(function (b) { b.onclick = function () { var q = W.L.questions[+b.dataset.qi]; W.player.seekTo(Math.max(0, q.t - 10), true); W.player.playVideo(); }; });
	}
	/** Pause at the next unanswered question once its time has passed. */
	function checkQ() {
		if (!W || !W.player || !W.player.getCurrentTime || W.asking) return;
		var t = W.player.getCurrentTime();
		for (var i = 0; i < W.L.questions.length; i++) {
			var q = W.L.questions[i];
			if (!W.L.answers[q.id] && t >= q.t && t < q.t + 6) { W.player.pauseVideo(); ask(q); return; }
		}
		if (W.player.getPlayerState && W.player.getPlayerState() === 0) finishLesson();
	}
	function ask(q) {
		W.asking = q;
		var box = $app.querySelector('#qbox'), i = W.L.questions.indexOf(q);
		var body = q.type === 'blank'
			? '<input class="inp" id="qa" autocomplete="off" autocapitalize="off" placeholder="Type the missing word"><button class="btn block" id="qsend">Check</button>'
			: '<div class="opts">' + q.options.map(function (o, k) { return '<button class="opt" data-k="' + k + '">' + esc(o) + '</button>'; }).join('') + '</div>';
		box.innerHTML = '<div class="card stack qcard"><p class="small muted">Question ' + (i + 1) + ' of ' + W.L.questions.length + ' · ' + fmtT(q.t) + (q.word ? ' · <b>' + esc(q.word) + '</b>' : '') + '</p><p class="q">' + esc(q.q).replace(/_{3,}/g, '<u>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</u>') + '</p>' + body
			+ '<div id="qfb"></div><button class="link small" id="rewatch">↺ Watch that part again</button></div>';
		box.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
		$app.querySelector('#rewatch').onclick = function () { W.asking = null; box.innerHTML = ''; W.player.seekTo(Math.max(0, q.t - 12), true); W.player.playVideo(); };
		var send = function (a, btn) {
			api('video/answer', { body: { id: W.L.id, qid: q.id, a: a } }).then(function (r) {
				W.L.answers[q.id] = { a: a, ok: r.ok };
				if (q.type !== 'blank') {
					box.querySelectorAll('.opt').forEach(function (o) { o.disabled = true; });
					if (btn) btn.classList.add(r.ok ? 'right' : 'wrong');
					var right = box.querySelector('.opt[data-k="' + r.answer + '"]'); if (right) right.classList.add('right');
				} else { box.querySelector('#qa').disabled = true; }
				box.querySelector('#qfb').innerHTML = '<div class="fb ' + (r.ok ? 'ok' : 'no') + '"><b>' + (r.ok ? 'Correct ✓ +5 XP' : 'Not quite' + (q.type === 'blank' ? ' — it\'s “' + esc(r.answer) + '”' : '')) + '</b>'
					+ (r.why ? '<p>' + esc(r.why) + '</p>' : '') + (r.why_ar && S.ar ? '<p class="ar">' + esc(r.why_ar) + '</p>' : '') + (!r.ok && q.word ? '<p class="small muted">“' + esc(q.word) + '” was added to your review cards.</p>' : '') + '</div>'
					+ '<button class="btn block" id="qgo">Continue the video ▶</button>';
				paintMarks();
				$app.querySelector('#qgo').onclick = function () { W.asking = null; box.innerHTML = ''; W.player.playVideo(); };
			}, function (e) { toast(e.message); });
		};
		box.querySelectorAll('.opt').forEach(function (b) { b.onclick = function () { send(+b.dataset.k, b); }; });
		var qs = box.querySelector('#qsend');
		if (qs) { var inp = box.querySelector('#qa'); inp.focus(); inp.onkeydown = function (e) { if (e.key === 'Enter') qs.click(); }; qs.onclick = function () { if (inp.value.trim()) { qs.remove(); send(inp.value.trim()); } }; }
	}
	function finishLesson() {
		if (W.finished) return;
		W.finished = true;
		var ok = Object.keys(W.L.answers).filter(function (k) { return W.L.answers[k].ok; }).length;
		$app.querySelector('#qbox').innerHTML = '<div class="card stack center-text"><h2>' + ok + ' / ' + W.L.questions.length + '</h2><p class="muted">Video finished. Words you missed are now in your review cards.</p><button class="btn" id="rv">Review now</button></div>';
		$app.querySelector('#rv').onclick = function () { go('review'); };
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
		return '<section class="rv-top"><div><b>' + R.queue.length + '</b><span>left</span></div><div><b>' + st.today + '</b><span>reviewed today</span></div><div><b>' + st.streak + '🔥</b><span>day streak</span></div><div><b>' + (st.retention != null ? st.retention + '%' : st.mature) + '</b><span>' + (st.retention != null ? 'remembered this week' : 'long-term') + '</span></div></section>' + levelBox(st);
	}
	/**
	 * Card level (progressive overload): new cards a day grow with consistency. Shows the level, what the next one
	 * needs, and catch-up mode when reviews pile up.
	 */
	function levelBox(st) {
		var lv = st.level;
		if (!lv || !lv.level) return '';
		if (lv.change && !R.levelSeen) {
			R.levelSeen = true;
			toast(lv.change === 'up' ? '⬆️ Card level ' + lv.level + '! Now ' + lv.levelMax + ' new cards a day.' : 'Card level ' + lv.level + ': fewer new cards for a while, so reviews stay easy.');
		}
		var dots = '';
		for (var i = 1; i <= lv.levels; i++) dots += '<i class="' + (i <= lv.level ? 'on' : '') + '"></i>';
		var tip = lv.catchup ? '<b>Catch-up time:</b> ' + lv.dueReviews + ' reviews are waiting. Finish them and new cards come back — no new cards until then, so it never gets too much.'
			: lv.level >= lv.levels ? 'Top level: ' + lv.levelMax + ' new cards a day. Keep it up!'
			: 'Review on <b>5 of 7 days</b> this week (' + lv.weekDays + ' so far) and remember 80% to reach level ' + (lv.level + 1) + ' (' + lv.nextPerDay + ' new cards a day). Check-in in ' + lv.checkIn + ' day' + (lv.checkIn === 1 ? '' : 's') + '.';
		return '<section class="card lvbox' + (lv.catchup ? ' catchup' : '') + '"><div class="row-between"><b>Card level ' + lv.level + ' <span class="lvdots">' + dots + '</span></b><span class="pill">' + lv.newPerDay + ' new a day</span></div><p class="small muted">' + tip + (lv.waiting ? ' ' + lv.waiting + ' saved items are waiting to become cards.' : '') + '</p></section>';
	}
	function cardFront(c) {
		if (c.type === 'word') return '<p class="rv-kind">Word · what does it mean?</p><p class="rv-word" dir="ltr">' + esc(c.lemma) + '</p>'
			+ (c.text ? '<p class="rv-ctx" dir="ltr">“' + highlight(c.text, wordIn(c.text, c.lemma)) + '”</p>' : '')
			+ '<div class="ra-btns"><button class="say sm" data-say="' + esc(c.lemma) + '" aria-label="Listen">🔊</button>' + (c.text ? '<button class="say sm" data-say="' + esc(c.text) + '" aria-label="Listen to the sentence">💬</button>' : '') + '</div>';
		if (c.type === 'mistake') return '<p class="rv-kind">Your mistake · fix it in your head</p><p class="rv-sent" dir="ltr">' + highlight(c.sentence, c.wrong) + '</p>';
		var kind = c.type === 'phrase' ? 'Phrase' + (GTAG[c.tag] ? ' · ' + GTAG[c.tag] : '') : c.type === 'grammar' ? 'Grammar · ' + (GTAG[c.tag] || 'example') : 'Sentence';
		return '<p class="rv-kind">' + esc(kind) + ' · do you understand it?</p>' + (c.type === 'phrase' && c.focus ? '<p class="rv-word" dir="ltr">' + esc(c.focus) + '</p>' : '')
			+ '<p class="rv-sent" dir="ltr">' + highlight(c.text, c.focus) + '</p><button class="say sm" data-say="' + esc(c.text) + '" aria-label="Listen">🔊</button>';
	}
	function cardBack(c) {
		if (c.type === 'word') {
			var ms = meanings(c.lemma);
			return (ms.length ? '<ul class="rv-m">' + ms.slice(0, 4).map(function (m) {
				return '<li><span class="pill">' + esc(m.pos) + (m.level ? ' · ' + m.level : '') + '</span><p class="ar">' + esc(m.ar) + '</p>' + (m.en ? '<p class="small">' + esc(m.en) + '</p>' : '') + (m.ex ? '<p class="small muted" dir="ltr">“' + esc(m.ex) + '”</p>' : '') + '</li>';
			}).join('') + '</ul>' : '<div data-ai-word="' + esc(c.lemma) + '"><button class="link">Explain with AI</button></div>')
				+ '<div class="pics" data-pics="' + esc(c.lemma) + '" data-sent="' + esc(c.text || '') + '"></div>' + (c._clip ? '' : momentHtml(c))
				+ '<p class="small"><a href="https://youglish.com/pronounce/' + encodeURIComponent(c.lemma) + '/english" target="_blank" rel="noopener">Hear “' + esc(c.lemma) + '” in other videos ↗</a></p>';
		}
		if (c.type === 'mistake') {
			var open = unlockedSkill(c.skill);
			return '<p class="rv-sent ok" dir="ltr">✓ ' + esc(c.fixed) + '</p>'
				+ (open ? (c.why ? '<p>' + esc(c.why) + '</p>' : '') + (c.why_ar && S.ar ? '<p class="ar muted">' + esc(c.why_ar) + '</p>' : '') : '<p class="small muted">🔒 The explanation unlocks with <b>' + esc(c.skillName) + '</b>.</p>')
				+ (c.skillName ? '<p class="small muted">' + esc(c.skillName) + '</p>' : '');
		}
		var link = srcLink(c);
		return (c.ar ? '<p class="ar rv-ar">' + esc(c.ar) + '</p>' : '<button class="link" data-tr="' + c.uuid + '">Show the Arabic translation</button><p class="ar rv-ar" data-ar-for="' + c.uuid + '"></p>')
			+ (c.videoId ? (c._clip ? '' : momentHtml(c)) : link ? '<p class="small"><a href="' + esc(link) + '" target="_blank" rel="noopener">' + esc(c.title || 'Source') + '</a></p>' : '');
	}
	/**
	 * "Play the moment": the clip from the learner's own video where they met the word or sentence, in the
	 * speaker's real voice. It plays by itself when a card opens (students can switch that off), stops when the
	 * sentence ends, and can loop (with a pause to repeat it: shadowing) or play slower.
	 */
	function clipPref(k, d) { try { var v = localStorage.getItem(k); return v == null ? d : v === '1'; } catch (e) { return d; } }
	function setClipPref(k, v) { try { localStorage.setItem(k, v ? '1' : '0'); } catch (e) { /* storage blocked */ } }
	function clipTimes(c) {
		var st = Math.max(0, (c.startMs || 0) / 1000 - 0.3);
		var words = String(c.text || '').split(/\s+/).filter(Boolean).length || 6;
		var en = c.endMs && c.endMs > c.startMs ? c.endMs / 1000 + 0.4 : st + Math.max(2.5, Math.min(12, words * 0.42 + 1.4));
		return [Math.round(st * 10) / 10, Math.round(en * 10) / 10];
	}
	function momentHtml(c) {
		if (!c.videoId) return c.url ? '<p class="small muted">From: <a href="' + esc(c.url) + '" target="_blank" rel="noopener">' + esc(c.title || 'source') + '</a></p>' : '';
		var t = clipTimes(c);
		return '<div class="moment" data-vid="' + esc(c.videoId) + '" data-st="' + t[0] + '" data-en="' + t[1] + '"><div class="clip-box"></div>'
			+ '<div class="ra-btns clip-bar"><button class="btn ghost sm" data-clip="play">▶ Play the moment</button><button class="btn ghost sm" data-clip="loop" aria-pressed="false">🔁 Loop to shadow</button><button class="btn ghost sm" data-clip="slow" aria-pressed="false">🐢 Slower</button></div>'
			+ '<label class="small clip-auto"><input type="checkbox" data-clip="auto"' + (clipPref('lensClipAuto', true) ? ' checked' : '') + '> Play the moment by itself on every card</label>'
			+ (c.title ? '<p class="small muted">' + esc(c.title.slice(0, 60)) + '</p>' : '') + '</div>';
	}
	var CLIP = null;
	function stopClip() { if (CLIP) { clearInterval(CLIP.tm); clearTimeout(CLIP.lt); try { CLIP.p.destroy(); } catch (e) { /* gone */ } CLIP = null; } }
	function bindMoments(root, auto) {
		root.querySelectorAll('.moment').forEach(function (m) {
			var st = +m.dataset.st, en = +m.dataset.en, box = m.querySelector('.clip-box'), loop = false, slow = false;
			var pb = m.querySelector('[data-clip="play"]'), lb = m.querySelector('[data-clip="loop"]'), sb = m.querySelector('[data-clip="slow"]');
			function play() {
				pb.textContent = '↻ Replay';
				ytApi().then(function (YT) {
					if (!m.isConnected) return;
					if (CLIP && CLIP.m === m && CLIP.ready) { CLIP.p.setPlaybackRate(slow ? 0.75 : 1); CLIP.p.seekTo(st, true); CLIP.p.playVideo(); return; }
					stopClip(); box.innerHTML = '<div></div>'; box.classList.add('on');
					var c = CLIP = { m: m };
					c.p = new YT.Player(box.firstChild, {
						videoId: m.dataset.vid,
						playerVars: { start: Math.floor(st), autoplay: 1, controls: 1, rel: 0, playsinline: 1, cc_load_policy: 1, modestbranding: 1 },
						events: { onReady: function (e) { c.ready = true; e.target.setPlaybackRate(slow ? 0.75 : 1); e.target.seekTo(st, true); e.target.playVideo(); } }
					});
					c.tm = setInterval(function () {
						if (!m.isConnected) return stopClip();
						if (!c.ready || !c.p.getCurrentTime) return;
						if (c.p.getPlayerState() === 1 && c.p.getCurrentTime() >= en) {
							c.p.pauseVideo();
							// Loop: a pause as long as the sentence, so the student can say it, then again.
							if (loop) { clearTimeout(c.lt); c.lt = setTimeout(function () { if (CLIP === c && loop) { c.p.seekTo(st, true); c.p.playVideo(); } }, (en - st) * 1000 / (slow ? 0.75 : 1) + 700); }
						}
					}, 100);
				});
			}
			pb.onclick = play;
			lb.onclick = function () { loop = !loop; lb.setAttribute('aria-pressed', String(loop)); lb.classList.toggle('on', loop); if (loop) play(); else if (CLIP) clearTimeout(CLIP.lt); };
			sb.onclick = function () { slow = !slow; sb.setAttribute('aria-pressed', String(slow)); sb.classList.toggle('on', slow); if (CLIP && CLIP.m === m && CLIP.ready) CLIP.p.setPlaybackRate(slow ? 0.75 : 1); };
			m.querySelector('[data-clip="auto"]').onchange = function () { setClipPref('lensClipAuto', this.checked); };
			if (auto && clipPref('lensClipAuto', true)) play();
		});
	}
	/** Pictures for a word: photos, or an AI picture for abstract words. */
	function bindPics(root) {
		root.querySelectorAll('[data-pics]').forEach(function (box) {
			var w = box.dataset.pics;
			box.innerHTML = '<button class="link small">🖼 Show a picture</button>';
			box.querySelector('button').onclick = function () {
				box.innerHTML = '<span class="small muted">Loading pictures…</span>';
				var pos = (meanings(w)[0] || {}).pos || '';
				api('image?word=' + encodeURIComponent(w) + (pos ? '&pos=' + encodeURIComponent(pos) : '')).then(function (r) {
					var drawing = false;
					var draw = function () { drawing = true; paint(); api('image/ai', { body: { word: w, sentence: box.dataset.sent || '' } }).then(function (x) { r.ai = x.url; drawing = false; paint(); }, function (e) { drawing = false; box.innerHTML = '<span class="small err">' + esc(e.message) + '</span>'; }); };
					var paint = function () {
						// One big picture: the one made for this word, else the photo (nouns only).
						var p = r.images[0];
						box.innerHTML = r.ai ? '<img class="big" src="' + esc(r.ai) + '" alt="">'
							: p ? '<a href="' + esc(p.link) + '" target="_blank" rel="noopener" title="' + esc(p.credit) + '"><img class="big" src="' + esc(p.url || p.thumb) + '" alt=""></a>' + (r.canAi ? '<button class="link small" data-aip>Not right? Draw one</button>' : '')
							: drawing ? '<span class="small muted">Drawing a picture for this word… (about 15 seconds)</span>' : '<span class="small muted">No picture for this word yet.</span>';
						var b = box.querySelector('[data-aip]');
						if (b) b.onclick = draw;
					};
					if (!r.ai && !r.images.length && r.canAi) draw(); else paint();
				}, function (e) { box.innerHTML = '<span class="small err">' + esc(e.message) + '</span>'; });
			};
		});
	}
	/** The form of the lemma used in the sentence (ran for run), to highlight it. */
	function wordIn(text, lemma) {
		var words = String(text).match(/[A-Za-z]+(?:'[a-z]+)?/g) || [];
		for (var i = 0; i < words.length; i++) { var w = words[i].toLowerCase(); if (w === lemma || lemmaOf(w) === lemma || w.indexOf(lemma) === 0) return words[i]; }
		return lemma;
	}
	function showCard() {
		if (!R.queue.length) {
			var st = R.stats;
			var more = Math.max(0, (st.due || 0) - 0);
			shell(reviewHead() + '<section class="card stack center-text"><h2>All done for today 🎉</h2><p class="muted">' + (R.done ? 'You reviewed ' + R.done + ' cards.' : 'No cards are due.') + (more ? ' ' + more + ' more can wait for tomorrow — no pressure.' : '') + (R.waiting ? ' ' + R.waiting + ' new cards are waiting for the next days.' : '') + '</p>'
				+ (st.total ? '' : '<p>Cards come from words you mark as <b>Learning</b>, phrases and sentences you save in Language Lens, and your own mistakes in Write.</p>')
				+ (st.level && st.level.levelMax > 5 ? '<label class="small">Fewer new cards? <select id="npd">' + [5, 7, 10, 13, 16, 20, 25].filter(function (n) { return n <= st.level.levelMax; }).map(function (n) { return '<option' + (n === st.newPerDay ? ' selected' : '') + '>' + n + '</option>'; }).join('') + '</select> a day (your level allows ' + st.level.levelMax + ')</label>' : '')
				+ '<button class="btn ghost" id="lib">Open my library</button></section>');
			$app.querySelector('#lib').onclick = function () { go('library'); };
			if ($app.querySelector('#npd')) $app.querySelector('#npd').onchange = function () { api('review/settings', { body: { newPerDay: +this.value } }).then(function (x) { R.stats = x; toast('Saved'); review(); }); };
			return;
		}
		stopClip();
		var c = R.queue[0];
		R.flipped = false;
		c._clip = !!c.videoId; // the clip of the moment plays on the front, in the speaker's own voice
		var tip = '';
		try { if (!localStorage.getItem('lensRvTip')) tip = '<div class="card small rv-tip"><b>How it works:</b> look at the card, think of the answer, then tap <b>Show answer</b>. Be honest: <b>Again</b> = I forgot · <b>Hard</b> = just about · <b>Good</b> = I knew it · <b>Easy</b> = too easy. The app shows each card again just before you would forget it. <button class="link small" id="rvtip">Got it</button></div>'; } catch (e) { /* storage blocked */ }
		shell(reviewHead() + tip + '<section class="card rv-card stack">' + (c.new ? '<span class="pill open">New</span>' : '') + cardFront(c) + (c._clip ? momentHtml(c) : '') + '<div id="back" hidden class="stack rv-back"></div></section>'
			+ '<section id="acts"><button class="btn block" id="flip">Show answer</button></section>');
		bindSay();
		if (c._clip) bindMoments($app.querySelector('.rv-card'), true);
		$app.querySelector('#flip').onclick = flip;
		var tb = $app.querySelector('#rvtip'); if (tb) tb.onclick = function () { try { localStorage.setItem('lensRvTip', '1'); } catch (e) { /* ignore */ } tb.closest('.rv-tip').remove(); };
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
		bindMoments(back);
		bindPics(back);
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
					box.innerHTML = '<p class="ar rv-ar">' + esc(x.meaning) + '</p>' + (x.note ? '<p class="small muted' + (x.noteLang === 'en' ? '' : ' ar') + '">' + esc(x.note) + '</p>' : '') + (x.example ? '<p class="small muted" dir="ltr">“' + esc(x.example) + '”</p>' : '');
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
					+ (w.text ? '<p class="small" dir="ltr">“' + highlight(w.text, wordIn(w.text, w.lemma)) + '”</p>' : '') + '<div class="pics" data-pics="' + esc(w.lemma) + '" data-sent="' + esc(w.text || '') + '"></div>'
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
		bindPics($app);
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

	/* ── Find English content you'll love ────────────────
	 * An interests quiz: what you enjoy in Arabic (categories, then creators) → English creators, music,
	 * podcasts and books that match, tagged by how hard their English is. Data: data/interests.json.
	 */
	var IN = { sel: {}, liked: {}, saved: {} };
	var LV = { A1: 1, A2: 2, B1: 3, B2: 4, C1: 5, C2: 6 };
	var IN_TYPE = { youtube: ['▶', 'YouTube'], podcast: ['🎙', 'Podcast'], music: ['🎵', 'Music'], book: ['📖', 'Book'], site: ['🌐', 'Website'], series: ['📺', 'Series'] };
	var IN_CC = { yes: 'CC captions', auto: 'Auto captions', lyrics: 'Lyric videos', subs: 'Subtitles', text: 'Text', none: 'No captions' };
	ICONS.interests = '<svg viewBox="0 0 24 24"><path d="M12 20s-7-4.4-7-10a4 4 0 0 1 7-2.6A4 4 0 0 1 19 10c0 5.6-7 10-7 10z"/></svg>';
	function inLoad() {
		if (IN.data) return Promise.resolve();
		return api('interests').then(function (r) {
			var p = r.picks;
			if (p) { IN.picks = p; IN.sel = {}; IN.liked = {}; IN.saved = {}; p.cats.forEach(function (k) { IN.sel[k] = 1; }); p.liked.forEach(function (k) { IN.liked[k] = 1; }); (p.saved || []).forEach(function (k) { IN.saved[k] = 1; }); }
			return fetch(r.data).then(function (x) { if (!x.ok) throw new Error('Couldn\'t load the list. Try again.'); return x.json(); });
		}).then(function (d) { IN.data = d; IN.byCat = {}; d.categories.forEach(function (c) { IN.byCat[c.id] = c; }); });
	}
	function inSave() {
		return api('interests', { body: { cats: Object.keys(IN.sel), liked: Object.keys(IN.liked), saved: Object.keys(IN.saved) } }).then(function (r) { IN.picks = r.picks; if (S.J) S.J.interests = true; });
	}
	function inMyLevel() { var p = S.J && S.J.placement; return p && LV[p.level] ? LV[p.level] : 2.5; }
	/** How likely the student is to follow a pick: not a grade, just a rough guess from the kind of content, how fast they talk and the student's level. */
	function inEase(e) {
		var x = (LV[e.level] || 3) - inMyLevel() + (e.speech === 'slow' ? -1 : e.speech === 'fast' ? 1 : 0) + (e.captions === 'text' ? -0.5 : 0);
		return x <= 0 ? ['easy', '🟢 You\'ll follow most of it'] : x <= 1.5 ? ['mid', '🟡 You\'ll get the main idea'] : ['hard', '🟠 A stretch — keep captions on'];
	}
	/** Every English pick for the chosen categories, best first: creators you like count most, then good first picks and Language Lens; easier ones win ties. */
	function inRecs() {
		var by = {};
		Object.keys(IN.sel).forEach(function (k) {
			var c = IN.byCat[k]; if (!c) return;
			c.en.forEach(function (e) {
				var hits = e.like.filter(function (a) { return IN.liked[a]; });
				var sc = 1 + hits.length * 4 + (e.start ? 1 : 0) + ({ easy: 0.4, mid: 0.2, hard: 0 }[inEase(e)[0]]) + (e.lens ? 0.8 : 0) + (IN.saved[e.id] ? 2 : 0);
				var cur = by[e.id];
				if (!cur || sc > cur.sc) by[e.id] = { e: e, c: c, sc: sc, hits: hits, cats: (cur ? cur.cats : []).concat(k) };
				else cur.cats.push(k);
			});
		});
		return Object.keys(by).map(function (k) { return by[k]; }).sort(function (a, b) { return b.sc - a.sc; });
	}
	/** "Start here": the best few, at most 2 from one category. */
	function inStart(all, n) {
		var per = {}, out = [];
		all.forEach(function (r) { if (out.length < n && (per[r.c.id] || 0) < 2) { per[r.c.id] = (per[r.c.id] || 0) + 1; out.push(r); } });
		return out;
	}
	function inAvatar(name) {
		var w = name.replace(/[^A-Za-z0-9؀-ۿ ]/g, ' ').trim().split(/\s+/), h = 0;
		for (var i = 0; i < name.length; i++) h = (h * 31 + name.charCodeAt(i)) % 360;
		return '<span class="in-av" aria-hidden="true" style="background:hsl(' + h + ' 45% 88%);color:hsl(' + h + ' 45% 28%)">' + esc(((w[0] || '?').charAt(0) + (w[1] ? w[1].charAt(0) : '')).toUpperCase()) + '</span>';
	}
	function inBar(step, label) {
		return '<div class="in-steps" aria-label="Step ' + step + ' of 3">' + [1, 2, 3].map(function (i) { return '<i class="' + (i <= step ? 'on' : '') + '"></i>'; }).join('') + '</div><p class="small muted">Step ' + step + ' of 3 · ' + label + '</p>';
	}
	function interests(arg) {
		if (!IN.data) { shell('<p class="loading">Loading…</p>'); return inLoad().then(function () { if (S.view === 'interests') interests(arg || (IN.picks ? 'recs' : '')); }, fail); }
		if (arg === 'deeper') return inDeeper();
		if (arg === 'recs' && IN.picks) return inShow();
		inCats();
	}
	function inSticky(html) { return '<div class="in-go">' + html + '</div>'; }
	function inCats() {
		var cats = IN.data.categories;
		shell('<section class="stack"><h1>Find English content you\'ll love</h1>' + inBar(1, 'what you enjoy')
			+ '<p>What do you watch, listen to or read <b>in Arabic</b>? Pick everything you like — we\'ll find the English version.</p><p class="ar small muted">إيه اللي بتحب تتفرج عليه أو تسمعه بالعربي؟ اختار كل اللي يعجبك.</p></section>'
			+ '<div class="in-cats" role="group" aria-label="Interests">' + cats.map(function (c) {
				return '<button class="in-cat" data-c="' + esc(c.id) + '" aria-pressed="' + (IN.sel[c.id] ? 'true' : 'false') + '"><span class="in-e" aria-hidden="true">' + c.emoji + '</span><b>' + esc(c.name) + '</b><span class="ar small">' + esc(c.ar) + '</span></button>';
			}).join('') + '</div>'
			+ inSticky('<span class="small" id="in-n"></span><button class="btn" id="in-next">Next →</button>'));
		var upd = function () { var n = Object.keys(IN.sel).length; $app.querySelector('#in-n').textContent = n ? n + ' picked' : 'Pick at least one'; $app.querySelector('#in-next').disabled = !n; };
		$app.querySelectorAll('.in-cat').forEach(function (b) {
			b.onclick = function () { var k = b.dataset.c; if (IN.sel[k]) delete IN.sel[k]; else IN.sel[k] = 1; b.setAttribute('aria-pressed', IN.sel[k] ? 'true' : 'false'); upd(); };
		});
		$app.querySelector('#in-next').onclick = function () { go('interests', 'deeper'); };
		upd();
	}
	function inDeeper() {
		var cr = IN.data.creators, cats = IN.data.categories.filter(function (c) { return IN.sel[c.id]; });
		if (!cats.length) return inCats();
		var seen = {};
		shell('<section class="stack"><button class="link small" id="back">← Back</button><h1>Do you like these creators?</h1>' + inBar(2, 'go deeper')
			+ '<p>Tap the ones you watch or listen to. We\'ll match them with English creators in the same style. Not sure? Skip it.</p><p class="ar small muted">دوس على اللي بتتابعهم — هنلاقيلك زيهم بالإنجليزي.</p></section>'
			+ cats.map(function (c) {
				var list = c.arab.filter(function (a) { return cr[a]; });
				return '<section class="stack"><h2 class="in-h"><span aria-hidden="true">' + c.emoji + '</span> ' + esc(c.name) + '</h2><div class="in-creators">' + list.map(function (a) {
					var x = cr[a], dup = seen[a]; seen[a] = 1;
					return '<div class="in-cr"><button class="in-crb" data-a="' + esc(a) + '" aria-pressed="' + (IN.liked[a] ? 'true' : 'false') + '"' + (dup ? ' data-dup' : '') + '>' + inAvatar(x.name) + '<span class="in-crt"><b>' + esc(x.name) + '</b> <span class="ar">' + esc(x.ar) + '</span><span class="small muted">' + esc(x.what) + '</span></span><span class="in-tick" aria-hidden="true">✓</span></button>'
						+ '<a class="small in-yt" href="' + esc(x.url) + '" target="_blank" rel="noopener">Find on YouTube ↗</a></div>';
				}).join('') + '</div></section>';
			}).join('')
			+ inSticky('<span class="small" id="in-n"></span><button class="btn" id="in-next">Show my English picks →</button>'));
		var upd = function () { var n = Object.keys(IN.liked).length; $app.querySelector('#in-n').textContent = n ? n + ' liked' : 'Optional'; };
		$app.querySelectorAll('.in-crb').forEach(function (b) {
			b.onclick = function () {
				var a = b.dataset.a; if (IN.liked[a]) delete IN.liked[a]; else IN.liked[a] = 1;
				$app.querySelectorAll('.in-crb[data-a="' + a + '"]').forEach(function (x) { x.setAttribute('aria-pressed', IN.liked[a] ? 'true' : 'false'); });
				upd();
			};
		});
		$app.querySelector('#back').onclick = function () { go('interests', 'cats'); };
		$app.querySelector('#in-next').onclick = function () {
			var btn = this; btn.disabled = true; btn.textContent = 'Saving…';
			// Liked creators only count for the categories still picked.
			var keep = {}; cats.forEach(function (c) { c.arab.forEach(function (a) { if (IN.liked[a]) keep[a] = 1; }); }); IN.liked = keep;
			inSave().then(function () { toast('Saved ✓'); go('interests', 'recs'); }, function (e) { btn.disabled = false; btn.textContent = 'Show my English picks →'; toast(e.message); });
		};
		upd();
	}
	function inCard(r, big) {
		var e = r.e, t = IN_TYPE[e.type] || IN_TYPE.site, cr = IN.data.creators;
		var because = r.hits.length ? 'Because you like ' + r.hits.map(function (a) { return cr[a].name; }).join(' & ') : 'For ' + r.c.emoji + ' ' + r.c.name;
		return '<article class="in-rec' + (big ? ' big' : '') + '"><div class="in-rh"><span class="in-ty" aria-hidden="true">' + t[0] + '</span><div><b>' + esc(e.name) + '</b><span class="small muted">' + t[1] + ' · ' + esc(because) + '</span></div>'
			+ '<button class="in-star" data-s="' + esc(e.id) + '" aria-pressed="' + (IN.saved[e.id] ? 'true' : 'false') + '" aria-label="Save ' + esc(e.name) + '">' + (IN.saved[e.id] ? '★' : '☆') + '</button></div>'
			+ '<p class="small">' + esc(e.what) + '</p><p class="small in-why">💡 ' + esc(e.why) + '</p>'
			+ '<div class="in-tags"><span class="pill ease ' + inEase(e)[0] + '">' + inEase(e)[1] + '</span>' + (e.speech ? '<span class="pill">' + ({ slow: '🐢 slow speech', clear: '🗣 clear speech', fast: '⚡ fast speech' }[e.speech] || '') + '</span>' : '') + '<span class="pill">' + (IN_CC[e.captions] || '') + '</span>' + (e.lens ? '<span class="pill open">🔍 Works with Language Lens</span>' : '') + '</div>'
			+ '<a class="btn sm' + (big ? '' : ' ghost') + '" href="' + esc(e.url) + '" target="_blank" rel="noopener">' + (e.type === 'book' ? 'Find the book ↗' : e.type === 'music' ? 'Listen ↗' : e.type === 'site' ? 'Open ↗' : 'Watch ↗') + '</a></article>';
	}
	function inShow() {
		var all = inRecs(), start = inStart(all, 5);
		var used = {}; start.forEach(function (r) { used[r.e.id] = 1; });
		var saved = all.filter(function (r) { return IN.saved[r.e.id]; });
		var groups = IN.data.categories.filter(function (c) { return IN.sel[c.id]; }).map(function (c) {
			var rest = all.filter(function (r) { return r.cats.indexOf(c.id) >= 0 && !used[r.e.id]; });
			rest.forEach(function (r) { used[r.e.id] = 1; });
			return rest.length ? '<section class="stack in-grp"><h3 class="in-h"><span aria-hidden="true">' + c.emoji + '</span> ' + esc(c.name) + '</h3><div class="in-recs">' + rest.map(function (r, i) { return '<div' + (i >= 2 ? ' class="in-more" hidden' : '') + '>' + inCard(r) + '</div>'; }).join('') + '</div>' + (rest.length > 2 ? '<button class="link small" data-more>Show ' + (rest.length - 2) + ' more</button>' : '') + '</section>' : '';
		}).join('');
		shell('<section class="stack"><h1>Your English picks</h1>' + inBar(3, 'your picks')
			+ '<p class="muted">Matched to what you love. Watch 10 minutes a day — with captions on. You don\'t need to understand every word: getting the main idea is how your English grows.</p>'
			+ '<p class="small muted">🔍 On a computer, YouTube picks work with <b>Language Lens</b>: tap any word in the captions for its meaning.</p></section>'
			+ '<section class="stack"><h2>⭐ Start here</h2><div class="in-recs">' + start.map(function (r) { return inCard(r, true); }).join('') + '</div></section>'
			+ (saved.length ? '<details class="card"><summary><b>★ Saved (' + saved.length + ')</b></summary><div class="in-recs" style="margin-top:12px">' + saved.map(function (r) { return inCard(r); }).join('') + '</div></details>' : '')
			+ (groups ? '<section class="stack"><h2>More for you</h2>' + groups + '</section>' : '')
			+ '<section class="card stack"><b>Want different picks?</b><p class="small muted">Change what you like any time — your picks update.</p><div class="ra-btns"><button class="btn ghost sm" id="in-edit">Edit my interests</button><button class="btn ghost sm" id="in-cr">Change creators</button><button class="link small" id="in-redo">Start over</button></div></section>');
		$app.querySelectorAll('[data-more]').forEach(function (b) { b.onclick = function () { b.parentNode.querySelectorAll('.in-more').forEach(function (x) { x.hidden = false; }); b.remove(); }; });
		$app.querySelectorAll('.in-star').forEach(function (b) {
			b.onclick = function () {
				var k = b.dataset.s; if (IN.saved[k]) delete IN.saved[k]; else IN.saved[k] = 1;
				$app.querySelectorAll('.in-star[data-s="' + k + '"]').forEach(function (x) { x.setAttribute('aria-pressed', IN.saved[k] ? 'true' : 'false'); x.textContent = IN.saved[k] ? '★' : '☆'; });
				inSave().then(function () { toast(IN.saved[k] ? '★ Saved' : 'Removed'); }, function (e) { toast(e.message); });
			};
		});
		$app.querySelector('#in-edit').onclick = function () { go('interests', 'cats'); };
		$app.querySelector('#in-cr').onclick = function () { go('interests', 'deeper'); };
		$app.querySelector('#in-redo').onclick = function () { if (!window.confirm('Clear your picks and start again?')) return; IN.sel = {}; IN.liked = {}; IN.saved = {}; go('interests', 'cats'); };
	}
	/** Home: today's pick (a YouTube video that works with Language Lens), or an invitation to take the quiz. */
	function paintInterestCard() {
		var box = $app.querySelector('#ilove'); if (!box) return;
		inLoad().then(function () {
			if (S.view !== 'today' || !box.isConnected) return;
			box.hidden = false;
			if (!IN.picks) {
				box.innerHTML = '<div class="in-home"><span class="in-e" aria-hidden="true">💛</span><div><h3>Find English content you\'ll love</h3><p class="small muted">Tell us what you enjoy in Arabic — comedy, rap, football, business… — and get English creators, music and books that match your level.</p></div></div><button class="btn sm" data-in="">Take the 2-minute quiz</button>';
			} else {
				var yt = inRecs().filter(function (r) { return r.e.lens && r.e.type !== 'music'; });
				var day = Math.floor(Date.now() / 864e5), r = yt.length ? yt[day % Math.min(yt.length, 8)] : null;
				box.innerHTML = r ? '<div class="row-between"><h3>🎬 Watch today</h3><button class="link small" data-in="recs">All your picks</button></div><p><b>Watch 10 minutes of ' + esc(r.e.name) + '</b></p><p class="small muted">' + esc(r.e.why) + '</p><div class="in-tags"><span class="pill ease ' + inEase(r.e)[0] + '">' + inEase(r.e)[1] + '</span><span class="pill open">🔍 Works with Language Lens</span></div><a class="btn sm" href="' + esc(r.e.url) + '" target="_blank" rel="noopener">Open on YouTube ↗</a>'
					: '<div class="row-between"><h3>Your English picks</h3></div><button class="btn sm" data-in="recs">See your picks</button>';
			}
			box.querySelectorAll('[data-in]').forEach(function (b) { b.onclick = function () { go('interests', b.dataset.in || undefined); }; });
		}).catch(function () {});
	}

	function fail(e) {
		shell('<p class="err">' + esc(e && e.message ? e.message : 'Something went wrong.') + '</p><button class="btn ghost" id="re">Reload</button>');
		$app.querySelector('#re').onclick = function () { location.reload(); };
	}

	api('review/settings', { body: {} }).then(function (st) { S.due = st.due; var n = document.querySelector('[data-nav="review"]'); if (n && st.due) n.insertAdjacentHTML('beforeend', '<i class="badge">' + st.due + '</i>'); }).catch(function () {});
	var h0 = location.hash.slice(1);
	if (B.appMode) {
		if ('serviceWorker' in navigator) navigator.serviceWorker.register('/app/sw.js', { scope: '/app/' }).catch(function () {});
		document.addEventListener('sawa-tracker', function (e) {
			var d = e.detail || {};
			if (d.unread != null) { S.unread = d.unread; paintNav(); }
			if (d.go) { go(d.go); return; } // a Silk Road quest step: take the student to where they learn it
			if (d.gate) { S.gate = d.gate; if (S.view !== 'today') go('today'); else showTrk('today'); }
			if (d.ready) { var wasGate = S.gate; S.gate = null; S.trk = d.data; if (wasGate || S.view === 'today') go(S.view === 'today' ? 'today' : S.view); }
			// The tracker switched itself (e.g. a reply notification opened a chat): follow it.
			if (d.view && d.view !== S.view && ['today', 'chat', 'community', 'me'].indexOf(d.view) >= 0 && ['today', 'chat', 'community', 'me'].indexOf(S.view) >= 0) { S.view = d.view; paintNav(); if (d.view === 'chat' || d.view === 'community') $app.querySelector('main').innerHTML = ''; }
		});
		var hv = h0.split('-')[0], ha = h0.split('-')[1];
		loadJourney().then(function () { go(['today', 'learn', 'chat', 'community', 'me', 'review', 'library', 'read', 'scan', 'write', 'skills', 'progress', 'start', 'interests'].indexOf(hv) >= 0 ? hv : 'today', ha ? +ha || ha : undefined); }, function () { S.stage = 5; go('today'); });
		return;
	}
	loadJourney().then(function () { go(['review', 'library', 'read', 'scan', 'write', 'skills', 'progress', 'start'].indexOf(h0) >= 0 ? h0 : S.stage < 5 ? 'start' : 'skills'); }, function () { S.stage = 5; go('skills'); });
})();
