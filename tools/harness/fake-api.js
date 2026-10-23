/* Fake /app API for the phone test harness (tools/harness/index.html). No WordPress, no sign-in.
   Answers app.js's api() calls from fixtures below; anything unknown gets {} so screens still draw.
   Pick a set of cards with ?s=mixed|video|long|empty|catchup (default mixed). */
(function () {
	'use strict';
	var q = new URLSearchParams(location.search);
	var S = q.get('s') || 'mixed';
	var ROOT = '/fake-api/';

	var level = { level: 2, levels: 6, newPerDay: 7, levelMax: 7, nextPerDay: 10, catchup: S === 'catchup', dueReviews: S === 'catchup' ? 64 : 12, waiting: 9, change: S === 'mixed' ? 'up' : '', checkIn: 3, weekDays: 3 };
	var stats = { due: 12, total: 140, mature: 31, today: 4, streak: 6, newPerDay: 7, level: level, retention: 86, retention30: 82 };
	var next = ['10m', '1d', '3d', '8d'];
	var video = { videoId: 'dQw4w9WgXcQ', startMs: 43000, endMs: 47000, title: 'Rick Astley - Never Gonna Give You Up (Official Music Video)' };
	function card(k, o) { return Object.assign({ key: k, uuid: 'u-' + k, next: next, new: false }, o); }

	var CARDS = {
		mixed: [
			card('w1', { type: 'word', lemma: 'take', text: 'It will take a few minutes to get there.', new: true }),
			card('p1', { type: 'phrase', focus: 'give up', tag: 'phrasal', text: 'Never give up on your dreams, even when it is hard.' }),
			card('m1', { type: 'mistake', sentence: 'She don\'t like coffee in the morning.', wrong: 'don\'t', fixed: 'She doesn\'t like coffee in the morning.', why: 'With he, she and it, use doesn\'t.', why_ar: 'مع he و she و it بنستخدم doesn\'t.', skill: 'be', skillName: 'Present simple' }),
			card('s1', { type: 'sentence', text: 'I have been learning English for two years.', ar: 'بقالي سنتين بتعلم إنجليزي.', url: 'https://example.com/a', title: 'An article' })
		],
		video: [
			card('w2', Object.assign({ type: 'word', lemma: 'run', text: 'Never gonna run around and desert you', new: true }, video)),
			card('s2', Object.assign({ type: 'sentence', text: 'Never gonna give you up, never gonna let you down' }, video))
		],
		long: [
			card('w3', Object.assign({ type: 'word', lemma: 'set', text: 'They set the table, set the alarm and set off before the sun came up over the hills behind the old town.' }, video)),
			card('s3', { type: 'sentence', text: 'Although the meeting had been scheduled weeks in advance, nobody seemed to remember why it was important, and by the time the manager finally arrived, half of the team had already gone home for the weekend.' }),
			card('m3', { type: 'mistake', sentence: 'If I would have known about the problem earlier, I would have told you immediately so that we could fix it together.', wrong: 'would have known', fixed: 'If I had known about the problem earlier, I would have told you immediately so that we could fix it together.', why: 'After "if" in the past, use had + past participle, not would have. This is the third conditional: if + had done, would have done.', skill: 'cond3', skillName: 'Third conditional' })
		],
		empty: [],
		catchup: [card('w4', { type: 'word', lemma: 'take', text: 'Take your time.' })]
	};
	if (S === 'empty') { stats.due = 0; stats.today = 18; }

	var R = {
		'review': function (b) { return b ? { stats: stats, known: false } : { cards: (CARDS[S] || CARDS.mixed).slice(), stats: stats, waiting: 9 }; },
		'review/settings': function () { return stats; },
		'review/history': function () { return { days: [] }; },
		'tree': function () { return { tree: [{ id: 'be', status: 'open' }, { id: 'cond3', status: 'locked' }], next: [] }; },
		'library/translate': function () { return { ar: 'ترجمة تجريبية للجملة.' }; },
		'image': function () { return { images: [], canAi: false }; },
		'journey': function () { return { admin: true }; }, // stage 5: every screen open
		// Same shape as Sawa_Lens_App::today_plan(): the first step is Review when the learner has cards.
		'today': function () {
			var rv = stats.total ? { k: 'review', done: !stats.due && stats.today > 0, t: stats.due ? 'Review ' + Math.min(40, stats.due) + ' cards' : 'Cards reviewed', sub: '5 minutes', go: 'review' }
				: { k: 'collect', done: false, t: 'Save 3 new words', sub: 'From a video, a page you scan, or a quiz · they become review cards', go: 'scan' };
			return { items: [rv,
				{ k: 'watch', done: false, t: 'Read something in English and save new words', sub: 'Learn → Read a text', go: 'read' },
				{ k: 'use', done: false, t: 'Write or say something in English', sub: 'A message in the chat, or a few sentences in Write', go: 'write' },
				{ k: 'log', done: false, t: 'Count today as a study day', sub: 'Anything above counts automatically · or log other study', go: 'log' }
			], perfect: false, gained: 0, due: stats.due, minutes: 6 };
		}
	};

	var realFetch = window.fetch.bind(window);
	window.fetch = function (url, init) {
		var u = String(url);
		if (u.indexOf(ROOT) !== 0) return realFetch(url, init);
		var path = u.slice(ROOT.length).split('?')[0];
		var body = init && init.body ? JSON.parse(init.body) : null;
		var fn = R[path];
		var out = fn ? fn(body) : {};
		console.log('[fake-api]', init && init.method || 'GET', path, body || '');
		return new Promise(function (ok) { setTimeout(function () { ok(new Response(JSON.stringify(out), { status: 200, headers: { 'Content-Type': 'application/json' } })); }, 120); });
	};

	/* Tests are always silent: a fake YouTube player (grey box, clock runs, no sound), silent speech and muted audio. */
	window.YT = { Player: function (el, o) {
		var t = (o.playerVars && o.playerVars.start) || 0, st = 2, me = this, tm = setInterval(function () { if (st === 1) t += 0.1; }, 100);
		el.style.cssText = 'width:100%;aspect-ratio:16/9;background:#333;color:#aaa;display:grid;place-items:center;font:13px sans-serif';
		el.textContent = '▶ fake video ' + (o.videoId || '');
		this.seekTo = function (s) { t = s; }; this.playVideo = function () { st = 1; }; this.pauseVideo = function () { st = 2; };
		this.getCurrentTime = function () { return t; }; this.getPlayerState = function () { return st; };
		this.setPlaybackRate = function () {}; this.destroy = function () { clearInterval(tm); };
		setTimeout(function () { o.events && o.events.onReady && o.events.onReady({ target: me }); }, 50);
	} };
	if ('speechSynthesis' in window) { speechSynthesis.speak = function (u) { setTimeout(function () { u.onend && u.onend(); }, 300); }; }
	var realPlay = HTMLMediaElement.prototype.play;
	HTMLMediaElement.prototype.play = function () { this.muted = true; return realPlay.call(this); };

	var ar = q.get('lang') === 'ar';
	document.documentElement.lang = ar ? 'ar' : 'en';
	document.documentElement.dir = ar ? 'rtl' : 'ltr';
	window.SAWA_LENS = {
		root: ROOT, nonce: 'harness', name: 'Test', ai: true, stt: false, tracker: '#', lessons: {},
		lexicon: '../../assets/lexicon.json', songs: false, readers: false, appMode: true,
		progress: ROOT + 'progress', explainLang: ar ? 'ar' : 'en',
		plan: q.get('plan') === 'free' ? { features: ['review', 'lens_meanings'], join: '#' } : null
	};
	window.HARNESS_AR = ar;
})();
