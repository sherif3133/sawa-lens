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
		library: '<svg viewBox="0 0 24 24"><path d="M5 4h4v16H5zM10 4h4v16h-4z"/><path d="M15.5 5l3.8-1 3 15.5-3.8 1z"/></svg>',
		watch: '<svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="3"/><path d="M10 9l5 3-5 3z"/></svg>',
		start: '<svg viewBox="0 0 24 24"><path d="M5 21V4"/><path d="M5 4h11l-2 4 2 4H5"/></svg>',
		today: '<svg viewBox="0 0 24 24"><path d="M3 11l9-7 9 7v9a1 1 0 0 1-1 1h-5v-6h-6v6H4a1 1 0 0 1-1-1z"/></svg>',
		learn: '<svg viewBox="0 0 24 24"><path d="M2 8l10-5 10 5-10 5z"/><path d="M6 10v5c3 2.5 9 2.5 12 0v-5"/></svg>',
		chat: '<svg viewBox="0 0 24 24"><path d="M4 5h16v11H9l-5 4z"/></svg>',
		community: '<svg viewBox="0 0 24 24"><circle cx="9" cy="8" r="3"/><circle cx="17" cy="9" r="2.5"/><path d="M3 20c0-3.5 3-6 6-6s6 2.5 6 6M14.5 14.5c3 0 6.5 1.5 6.5 5"/></svg>',
		me: '<svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4.5 3.5-7 8-7s8 2.5 8 7"/></svg>'
	};

	var APP_TABS = [['today', 'Today'], ['learn', 'Learn'], ['chat', 'Chat'], ['community', 'Community'], ['me', 'Me']];
	function tabOf(v) {
		if (['today', 'chat', 'community', 'me'].indexOf(v) >= 0) return v;
		return ['library', 'progress', 'start'].indexOf(v) >= 0 ? 'me' : 'learn';
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
			$app.innerHTML = '<header class="top"><div class="brand">Sawa English</div><button class="link small" data-nav-start>Guide</button></header><main class="stack">' + inner + '</main>';
			var gs = $app.querySelector('[data-nav-start]'); if (gs) gs.onclick = function () { go('start'); };
			paintNav();
			showTrk(['today', 'chat', 'community', 'me'].indexOf(S.view) >= 0 ? S.view : '');
			window.scrollTo(0, 0);
			return;
		}
		$app.innerHTML = '<header class="top"><div class="brand">Language Lens <span>· Sawa English</span></div><a class="small muted" href="' + esc(B.tracker) + '">Tracker</a></header>' +
			'<main class="stack">' + inner + '</main>' +
			'<nav class="nav">' + [['start', 'Start'], ['review', 'Review'], ['watch', 'Watch'], ['write', 'Write'], ['skills', 'Skills'], ['library', 'Library'], ['progress', 'Progress']].filter(function (v) { return unlocked(v[0]); }).map(function (v) {
				return '<button data-nav="' + v[0] + '"' + (S.view === v[0] ? ' aria-current="page"' : '') + '>' + ICONS[v[0]] + v[1] + (v[0] === 'review' && S.due ? '<i class="badge">' + S.due + '</i>' : '') + '</button>';
			}).join('') + '</nav>';
		$app.querySelectorAll('[data-nav]').forEach(function (b) { b.onclick = function () { go(b.dataset.nav); }; });
		window.scrollTo(0, 0);
	}

	function go(view, arg) {
		if (W && W.timer) { clearInterval(W.timer); W.timer = null; }
		if (B.appMode && S.gate) view = 'today';
		if (['today', 'learn', 'chat', 'community', 'me'].indexOf(view) < 0 && !unlocked(view === 'skill' || view === 'practice' ? 'skills' : view)) view = B.appMode ? 'learn' : 'start';
		S.view = view;
		try { if (B.appMode) history.replaceState(null, '', '#' + view + (arg && typeof arg !== 'object' ? '-' + arg : '')); } catch (e) { /* ignore */ }
		if (view === 'today') return today();
		if (view === 'learn') return learnHub();
		if (view === 'me') return meHub();
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
		{ n: 1, title: 'Watch & collect words', ar: 'شاهد واجمع الكلمات', tabs: ['start', 'watch'],
			intro: 'Learn from YouTube videos you like. Language Lens shows meanings and colours words for your level.',
			steps: [
				{ k: 'extension', t: 'Connect Language Lens in Chrome', ar: 'ثبّت الإضافة وسجّل الدخول', how: 'On a computer: install Language Lens, click its icon, then "Sign in with Sawa English".', done: function (j) { return j.extension; }, link: function (j) { return j.store ? ['Install Language Lens', j.store] : null; } },
				{ k: 'vocabTest', t: 'Take the English level test (about 25 minutes)', ar: 'اختبار تحديد المستوى', how: 'It finds your level (A1–C2) and vocabulary size, so the app and Language Lens fit you.', done: function (j) { return j.vocabTest; }, link: function () { return ['Take the level test', '/placement/']; } },
				{ k: 'learning', t: 'Watch a video and mark 5 words as Learning', ar: 'علّم ٥ كلمات «أتعلمها»', how: 'Open any YouTube video with English captions. Hover a coloured word → "Learning". Green = worth learning now.', done: function (j) { return j.learning + j.saved >= 5; }, count: function (j) { return Math.min(5, j.learning + j.saved) + '/5'; } }
			] },
		{ n: 2, title: 'Review a little every day', ar: 'راجع قليلًا كل يوم', tabs: ['review', 'library'],
			intro: 'Every word and sentence you save becomes a review card. Five minutes a day is enough — the app shows each card just before you forget it.',
			steps: [
				{ k: 'reviewDays', t: 'Review your cards on 2 different days', ar: 'راجع البطاقات يومين', how: 'Open Review. Read the card, think of the answer, tap "Show answer", then be honest: Again, Hard, Good or Easy.', done: function (j) { return j.reviewDays >= 2; }, count: function (j) { return Math.min(2, j.reviewDays) + '/2'; }, go: 'review' },
				{ k: 'saved', t: 'Save 3 sentences or phrases you like', ar: 'احفظ ٣ جمل أو عبارات', how: 'On YouTube: in the transcript panel, or select words → "Save sentence" / "Save expression". They appear in Library.', done: function (j) { return j.saved >= 3; }, count: function (j) { return Math.min(3, j.saved) + '/3'; } }
			] },
		{ n: 3, title: 'Write and fix your grammar', ar: 'اكتب وصحّح القواعد', tabs: ['write', 'skills'],
			intro: 'Write a few sentences about your day. You get every correction, and your mistakes decide which grammar points open in your skill tree.',
			steps: [
				{ k: 'writings', t: 'Check your writing twice', ar: 'صحّح كتابتك مرتين', how: 'Open Write, write 3–5 sentences, tap "Check my writing".', done: function (j) { return j.writings >= 2; }, count: function (j) { return Math.min(2, j.writings) + '/2'; }, go: 'write' },
				{ k: 'sets', t: 'Do one practice set in your skill tree', ar: 'تمرين واحد في شجرة المهارات', how: 'Open Skills → "Work on next" → Start practice. Explanations open as you unlock each point.', done: function (j) { return j.sets >= 1; }, go: 'skills' }
			] },
		{ n: 4, title: 'Speak', ar: 'تكلّم', tabs: ['speak'],
			intro: 'Speaking is where it all comes together. Talk for a minute and get the text of what you said with corrections.',
			steps: [
				{ k: 'speaks', t: 'Speak once (in Write → Speak, or a voice note in the tracker chat)', ar: 'سجّل كلامك مرة', how: 'Write → 🎙 Speak → talk about the idea of the day. Or send a voice note in the tracker chat and tap "رأي في كلامي".', done: function (j) { return j.speaks >= 1; }, go: 'write' }
			] },
		{ n: 5, title: 'Everything is open', ar: 'كل الأدوات مفتوحة', tabs: ['scan', 'progress'],
			intro: 'You know the routine. Now use the extras when you need them:',
			tips: [
				'📷 Scan a page (Write → Scan): photo of a book page → tap words for meanings.',
				'📈 Progress: see if each type of mistake is going down, week by week.',
				'🔎 Focus mode (Language Lens popup): highlight one grammar point or sound in every video.',
				'🌐 Write in Arabic in the tracker chat — it\'s translated to English for you to check before sending.',
				'⚡ Every English message, voice note and fixed mistake earns XP in the tracker.'
			], steps: [] }
	];
	function stageOf(j) {
		if (j.admin || pref('lensAll', '0') === '1') return 5;
		var seq = 5;
		for (var i = 0; i < 4; i++) if (!STAGES[i].steps.every(function (st) { return st.done(j); })) { seq = i + 1; break; }
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
					return '<li class="' + (ok ? 'ok' : '') + '"><span class="g-check">' + (ok ? '✓' : '') + '</span><div><b>' + esc(x.t) + '</b>' + (x.count && !ok ? ' <span class="pill">' + x.count(j) + '</span>' : '')
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
		if (all) all.onclick = function () { setPref('lensAll', '1'); S.stage = 5; toast('Everything is open'); guide(); };
	}

	/* ── The one app: Today, Learn and Me ─────────────── */
	function trkData() { return S.trk || (window.SawaTracker && window.SawaTracker.data()) || null; }
	/** Today: a short plan with the next step highlighted, then today's stats and quest from the tracker. */
	function today() {
		if (S.gate) { shell('<section class="stack"><h1>Welcome 👋</h1><p class="muted">One quick step first (below). Then everything opens.</p></section>'); return; }
		shell('<section class="stack"><h1>Today</h1></section><section class="card stack" id="plan"><p class="loading">Making your plan…</p></section>');
		var t = trkData();
		Promise.all([api('review/settings', { body: {} }).catch(function () { return null; }), api('videos').catch(function () { return { lessons: [] }; }),
			fetch(B.progress, { credentials: 'same-origin', headers: { 'X-WP-Nonce': B.nonce } }).then(function (r) { return r.ok ? r.json() : null; }).catch(function () { return null; }),
			t ? Promise.resolve(t) : (window.SawaTracker ? window.SawaTracker.refresh().catch(function () { return null; }) : Promise.resolve(null))]).then(function (res) {
			if (S.view !== 'today') return;
			var st = res[0] || { due: 0, today: 0 }, lessons = res[1].lessons || [], prog = res[2] || {}, tr = res[3];
			S.due = st.due; paintNav();
			var why = (tr && tr.xp && tr.xp.why) || {};
			var lesson = lessons.filter(function (l) { return l.answered < l.total; })[0];
			var items = [];
			if (unlocked('review')) items.push({ done: !st.due, t: st.due ? 'Review ' + st.due + ' card' + (st.due > 1 ? 's' : '') : st.today ? 'Cards reviewed ✓' : 'No cards due — nice', sub: '5 minutes', btn: st.due ? ['Start', function () { go('review'); }] : null });
			items.push({ done: (prog.todayWords || 0) + (prog.todaySentences || 0) > 0, t: lesson ? 'Watch: ' + lesson.title : 'Watch a video and mark new words', sub: lesson ? 'Sherif\'s video with questions' : 'Any YouTube video with Language Lens', btn: lesson ? ['Watch', function () { go('watch', lesson.id); }] : ['Videos', function () { go('watch'); }] });
			if (unlocked('write')) items.push({ done: !!(why.chat || why.voice || why.write || why.speak), t: 'Write or say a few sentences', sub: 'In Write, or in the chat', btn: ['Write', function () { go('write'); }] });
			else items.push({ done: !!(why.chat || why.voice), t: 'Say hello in the chat — in English', sub: 'Every English word earns XP', btn: ['Chat', function () { go('chat'); }] });
			items.push({ done: !!(tr && tr.stats && tr.stats.studiedToday), t: 'Log your study', sub: 'Keeps your streak alive', btn: ['Log', function () { if (window.SawaTracker) window.SawaTracker.act('log'); }] });
			var next = items.findIndex(function (x) { return !x.done; });
			var stage = STAGES[S.stage - 1], step = stage.steps.filter(function (x) { return !x.done(S.J); })[0];
			var box = $app.querySelector('#plan'); if (!box) return;
			box.innerHTML = '<h3>' + (next < 0 ? 'All done for today 🎉' : 'Your plan for today') + '</h3><ol class="plan">' + items.map(function (x, i) {
				return '<li class="' + (x.done ? 'ok' : i === next ? 'next' : '') + '"><span class="g-check">' + (x.done ? '✓' : i + 1) + '</span><div><b>' + esc(x.t) + '</b><span class="small muted">' + esc(x.sub) + '</span></div>' + (x.btn && !x.done ? '<button class="btn sm' + (i === next ? '' : ' ghost') + '" data-pi="' + i + '">' + x.btn[0] + '</button>' : '') + '</li>';
			}).join('') + '</ol>'
				+ (S.stage < 5 && step ? '<button class="stage-tip" data-start><span class="pill open">Stage ' + S.stage + ' of 5</span> Next: ' + esc(step.t) + ' →</button>' : '');
			box.querySelectorAll('[data-pi]').forEach(function (b) { b.onclick = items[+b.dataset.pi].btn[1]; });
			var sg = box.querySelector('[data-start]'); if (sg) sg.onclick = function () { go('start'); };
		});
	}
	/** Learn: the learning tools, opening one by one with the stages. */
	function learnHub() {
		var tools = [
			['review', 'Review', 'Your words and sentences come back before you forget them', 2],
			['watch', 'Watch', 'Videos from Sherif that stop and ask you questions', 1],
			['write', 'Write & speak', 'Write or talk; get every correction', 3],
			['skills', 'Skill tree', 'Grammar, tone and pronunciation, step by step', 3]
		];
		shell('<section class="stack"><h1>Learn</h1></section><div class="hub">' + tools.map(function (x) {
			var open = unlocked(x[0]);
			return '<button class="hub-t' + (open ? '' : ' locked') + '" data-t="' + x[0] + '"' + (open ? '' : ' disabled') + '><span class="hub-i">' + ICONS[x[0] === 'write' ? 'write' : x[0] === 'skills' ? 'skills' : x[0]] + '</span><b>' + x[1] + (x[0] === 'review' && S.due && open ? ' <i class="badge static">' + S.due + '</i>' : '') + '</b><span class="small muted">' + (open ? x[2] : '🔒 Opens at stage ' + x[3]) + '</span></button>';
		}).join('') + '</div>' + (S.stage < 5 ? '<p class="small muted center-text">New tools open as you go. <button class="link small" data-start>See your stages</button></p>' : ''));
		$app.querySelectorAll('[data-t]').forEach(function (b) { b.onclick = function () { go(b.dataset.t); }; });
		var sg = $app.querySelector('[data-start]'); if (sg) sg.onclick = function () { go('start'); };
	}
	/** Me: your stuff — library, progress, the guide, the extension — then your tracker stats. */
	function meHub() {
		var j = S.J || {};
		var tiles = [['library', 'My library', 'Words, phrases and sentences you saved', 'library'], ['progress', 'Progress', 'Are your mistakes going down?', 'progress'], ['start', 'Your stages', 'Stage ' + S.stage + ' of 5', 'start'], ['placement', 'Level test', j.placement ? 'Your level: ' + j.placement.level + ' · ' + j.placement.score + '/100' : 'Find your level (A1–C2)', 'skills']].filter(function (x) { return x[0] === 'start' || unlocked(x[0]); });
		shell('<section class="stack"><h1>Me</h1></section><div class="hub">' + tiles.map(function (x) {
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
			+ '<p class="small muted" dir="ltr">“' + esc(sentence) + '”</p><div class="pics" data-pics="' + esc(lemma) + '" data-sent="' + esc(sentence) + '"></div>'
			+ '<div class="ra-btns"><button class="btn sm" id="learn">+ Learning</button><button class="btn ghost sm" id="savesent">Save sentence</button><button class="btn ghost sm" data-say="' + esc(sentence) + '">🔊 Sentence</button></div></section>';
		bindSay();
		bindPics(el);
		var aw = el.querySelector('[data-ai-word]');
		aw.querySelector('button').onclick = function () {
			this.textContent = 'Explaining…';
			api('library/explain', { body: { word: word, sentence: sentence } }).then(function (x) { aw.innerHTML = '<p class="ar rv-ar">' + esc(x.meaning) + '</p>' + (x.note ? '<p class="small ar muted">' + esc(x.note) + '</p>' : '') + (x.sentence ? '<p class="small ar">' + esc(x.sentence) + '</p>' : ''); }, function (e) { aw.textContent = e.message; });
		};
		el.querySelector('#learn').onclick = function () { var b = this; api('library/word', { body: { lemma: lemma, status: 'learning' } }).then(function () { b.textContent = '✓ Learning'; b.disabled = true; toast('Added to your review cards'); }, function (e) { toast(e.message); }); };
		el.querySelector('#savesent').onclick = function () { var b = this; api('library/save', { body: { text: sentence, focus: word, kind: 'sentence', title: SCAN.title || 'Scanned page' } }).then(function () { b.textContent = '✓ Saved'; b.disabled = true; }, function (e) { toast(e.message); }); };
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
				}).join('') + '</ul>' : '<p class="card muted">No video lessons yet. They\'ll appear here when Sherif publishes one.</p>')
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
					+ (r.why ? '<p>' + esc(r.why) + '</p>' : '') + (r.why_ar ? '<p class="ar">' + esc(r.why_ar) + '</p>' : '') + (!r.ok && q.word ? '<p class="small muted">“' + esc(q.word) + '” was added to your review cards.</p>' : '') + '</div>'
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
		return '<section class="rv-top"><div><b>' + R.queue.length + '</b><span>left</span></div><div><b>' + st.today + '</b><span>reviewed today</span></div><div><b>' + st.streak + '🔥</b><span>day streak</span></div><div><b>' + st.mature + '</b><span>long-term</span></div></section>';
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
				+ '<div class="pics" data-pics="' + esc(c.lemma) + '" data-sent="' + esc(c.text || '') + '"></div>' + momentHtml(c)
				+ '<p class="small"><a href="https://youglish.com/pronounce/' + encodeURIComponent(c.lemma) + '/english" target="_blank" rel="noopener">Hear “' + esc(c.lemma) + '” in other videos ↗</a></p>';
		}
		if (c.type === 'mistake') {
			var open = unlockedSkill(c.skill);
			return '<p class="rv-sent ok" dir="ltr">✓ ' + esc(c.fixed) + '</p>'
				+ (open ? (c.why ? '<p>' + esc(c.why) + '</p>' : '') + (c.why_ar ? '<p class="ar muted">' + esc(c.why_ar) + '</p>' : '') : '<p class="small muted">🔒 The explanation unlocks with <b>' + esc(c.skillName) + '</b>.</p>')
				+ (c.skillName ? '<p class="small muted">' + esc(c.skillName) + '</p>' : '');
		}
		var link = srcLink(c);
		return (c.ar ? '<p class="ar rv-ar">' + esc(c.ar) + '</p>' : '<button class="link" data-tr="' + c.uuid + '">Show the Arabic translation</button><p class="ar rv-ar" data-ar-for="' + c.uuid + '"></p>')
			+ (c.videoId ? momentHtml(c) : link ? '<p class="small"><a href="' + esc(link) + '" target="_blank" rel="noopener">' + esc(c.title || 'Source') + '</a></p>' : '');
	}
	/** "Play the moment": the video clip where the learner met it (like YouGlish, from their own videos). */
	function momentHtml(c) {
		if (!c.videoId) return c.url ? '<p class="small muted">From: <a href="' + esc(c.url) + '" target="_blank" rel="noopener">' + esc(c.title || 'source') + '</a></p>' : '';
		var st = Math.max(0, Math.floor((c.startMs || 0) / 1000) - 1);
		return '<div class="moment" data-vid="' + esc(c.videoId) + '" data-st="' + st + '"><button class="btn ghost sm">▶ Play the moment' + (c.title ? ' · ' + esc(c.title.slice(0, 40)) : '') + '</button></div>';
	}
	function bindMoments(root) {
		root.querySelectorAll('.moment').forEach(function (m) {
			var b = m.querySelector('button'); if (!b) return;
			b.onclick = function () {
				var st = +m.dataset.st;
				m.innerHTML = '<div class="player"><iframe src="https://www.youtube-nocookie.com/embed/' + encodeURIComponent(m.dataset.vid) + '?start=' + st + '&end=' + (st + 12) + '&autoplay=1&cc_load_policy=1&rel=0&playsinline=1" allow="autoplay; encrypted-media" allowfullscreen title="Video"></iframe></div>';
			};
		});
	}
	/** Pictures for a word: photos, or an AI picture for abstract words. */
	function bindPics(root) {
		root.querySelectorAll('[data-pics]').forEach(function (box) {
			var w = box.dataset.pics;
			box.innerHTML = '<button class="link small">🖼 Show pictures</button>';
			box.querySelector('button').onclick = function () {
				box.innerHTML = '<span class="small muted">Loading pictures…</span>';
				api('image?word=' + encodeURIComponent(w)).then(function (r) {
					var paint = function () {
						box.innerHTML = (r.ai ? '<img class="ai" src="' + esc(r.ai) + '" alt="">' : '') + r.images.slice(0, 3).map(function (p) { return '<a href="' + esc(p.link) + '" target="_blank" rel="noopener" title="' + esc(p.credit) + '"><img src="' + esc(p.thumb) + '" alt="" loading="lazy"></a>'; }).join('')
							+ (!r.ai && r.canAi ? '<button class="btn ghost sm" data-aip>✨ AI picture</button>' : '') + (!r.images.length && !r.ai ? '<span class="small muted">No photos for this word.</span>' : '');
						box.querySelectorAll('img').forEach(function (im) { im.onerror = function () { (im.parentNode.tagName === 'A' ? im.parentNode : im).remove(); }; });
						var b = box.querySelector('[data-aip]');
						if (b) b.onclick = function () { b.textContent = 'Drawing…'; b.disabled = true; api('image/ai', { body: { word: w, sentence: box.dataset.sent || '' } }).then(function (x) { r.ai = x.url; paint(); }, function (e) { b.textContent = e.message; }); };
					};
					paint();
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

	function fail(e) {
		shell('<p class="err">' + esc(e && e.message ? e.message : 'Something went wrong.') + '</p><button class="btn ghost" id="re">Reload</button>');
		$app.querySelector('#re').onclick = function () { location.reload(); };
	}

	api('review/settings', { body: {} }).then(function (st) { S.due = st.due; var n = document.querySelector('[data-nav="review"]'); if (n && st.due) n.insertAdjacentHTML('beforeend', '<i class="badge">' + st.due + '</i>'); }).catch(function () {});
	var h0 = location.hash.slice(1);
	if (B.appMode) {
		document.addEventListener('sawa-tracker', function (e) {
			var d = e.detail || {};
			if (d.unread != null) { S.unread = d.unread; paintNav(); }
			if (d.gate) { S.gate = d.gate; if (S.view !== 'today') go('today'); else showTrk('today'); }
			if (d.ready) { var wasGate = S.gate; S.gate = null; S.trk = d.data; if (wasGate || S.view === 'today') go(S.view === 'today' ? 'today' : S.view); }
			// The tracker switched itself (e.g. a reply notification opened a chat): follow it.
			if (d.view && d.view !== S.view && ['today', 'chat', 'community', 'me'].indexOf(d.view) >= 0 && ['today', 'chat', 'community', 'me'].indexOf(S.view) >= 0) { S.view = d.view; paintNav(); if (d.view === 'chat' || d.view === 'community') $app.querySelector('main').innerHTML = ''; }
		});
		var hv = h0.split('-')[0], ha = h0.split('-')[1];
		loadJourney().then(function () { go(['today', 'learn', 'chat', 'community', 'me', 'review', 'library', 'watch', 'write', 'skills', 'progress', 'start'].indexOf(hv) >= 0 ? hv : 'today', ha ? +ha || ha : undefined); }, function () { S.stage = 5; go('today'); });
		return;
	}
	loadJourney().then(function () { go(['review', 'library', 'watch', 'write', 'skills', 'progress', 'start'].indexOf(h0) >= 0 ? h0 : S.stage < 5 ? 'start' : 'skills'); }, function () { S.stage = 5; go('skills'); });
})();
