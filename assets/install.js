/* "Install the app" button for /app (Sawa Lens). Its own file so it never clashes with app.js.
 * - Chrome / Edge / Samsung (Android and computers): uses the browser's install prompt (beforeinstallprompt), then says
 *   "Installed ✓ — find Sawa on your home screen" (Chrome installs quietly, so students thought nothing happened).
 * - iPhone / iPad: shows the steps Share → Add to Home Screen.
 * - Other phone browsers with no prompt (e.g. Firefox): shows the menu steps.
 * - Hidden when the app is already open from the home screen (standalone). English or Arabic, from <html data-lang>.
 */
(function () {
	'use strict';
	var AR = document.documentElement.getAttribute('data-lang') === 'ar';
	var T = AR ? {
		btn: 'ثبّت التطبيق',
		installing: 'بيتثبّت… هيظهر على الشاشة الرئيسية بعد ثواني.',
		done: 'اتثبّت ✓ هتلاقي Sawa على الشاشة الرئيسية لموبايلك.',
		iosTitle: 'ثبّت Sawa على الآيفون',
		ios: ['دوس على زرار المشاركة <b>⬆︎</b> تحت (أو فوق في الآيباد).', 'انزل واختار <b>Add to Home Screen</b> (إضافة إلى الشاشة الرئيسية).', 'دوس <b>Add</b>. هتلاقي Sawa على الشاشة الرئيسية.'],
		otherTitle: 'ثبّت Sawa على موبايلك',
		other: ['افتح قائمة المتصفح <b>⋮</b>.', 'اختار <b>Install</b> أو <b>Add to Home screen</b>.', 'هتلاقي Sawa على الشاشة الرئيسية.'],
		close: 'تمام'
	} : {
		btn: 'Install the app',
		installing: 'Installing… it will appear on your home screen in a few seconds.',
		done: 'Installed ✓ — find Sawa on your home screen.',
		iosTitle: 'Install Sawa on your iPhone',
		ios: ['Tap the Share button <b>⬆︎</b> (at the bottom, or the top on iPad).', 'Scroll down and tap <b>Add to Home Screen</b>.', 'Tap <b>Add</b>. Sawa is now on your home screen.'],
		otherTitle: 'Install Sawa on your phone',
		other: ['Open the browser menu <b>⋮</b>.', 'Tap <b>Install</b> or <b>Add to Home screen</b>.', 'Sawa is now on your home screen.'],
		close: 'OK'
	};
	var KEY = 'sawa-app-installed';
	function store(v) { try { if (v) localStorage.setItem(KEY, '1'); else localStorage.removeItem(KEY); } catch (e) {} }
	function stored() { try { return localStorage.getItem(KEY) === '1'; } catch (e) { return false; } }

	var standalone = (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) || window.navigator.standalone === true;
	if (standalone) { store(true); return; }

	var ua = navigator.userAgent || '';
	var ios = /iPhone|iPad|iPod/.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
	var phone = ios || /Android|Mobile/i.test(ua);

	var css = document.createElement('style');
	css.textContent =
		'.sawa-inst{position:fixed;z-index:30;inset-inline-end:12px;bottom:calc(76px + env(safe-area-inset-bottom));display:flex;align-items:center;gap:8px;' +
		'min-height:44px;padding:0 18px;border:0;border-radius:999px;background:var(--red,#E3262B);color:#fff;font:600 15px/1 inherit;font-family:inherit;box-shadow:0 4px 14px rgba(0,0,0,.18);cursor:pointer}' +
		'.sawa-inst svg{width:18px;height:18px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}' +
		'.sawa-inst-msg{position:fixed;z-index:31;inset-inline:12px;bottom:calc(76px + env(safe-area-inset-bottom));max-width:420px;margin-inline-start:auto;padding:14px 16px;border-radius:14px;' +
		'background:var(--panel,#fff);color:var(--fg,#0F0F10);border:1px solid var(--line,#E6E3DF);box-shadow:0 8px 24px rgba(0,0,0,.18);font-size:15px;line-height:1.5}' +
		'.sawa-inst-msg h3{margin:0 0 8px;font-size:16px}.sawa-inst-msg ol{margin:0 0 10px;padding-inline-start:20px}.sawa-inst-msg li{margin:4px 0}' +
		'.sawa-inst-msg .row{display:flex;justify-content:flex-end}.sawa-inst-msg button{min-height:40px;padding:0 16px;border-radius:10px;border:1px solid var(--line,#E6E3DF);background:var(--soft,#EFEDEA);color:inherit;font:inherit;font-weight:600;cursor:pointer}' +
		'@media (min-width:760px){.sawa-inst,.sawa-inst-msg{bottom:16px}}';
	document.head.appendChild(css);

	var btn = null, msg = null, deferred = null;
	function hideBtn() { if (btn) { btn.remove(); btn = null; } }
	function showBtn(onClick) {
		if (btn) return;
		btn = document.createElement('button');
		btn.type = 'button'; btn.className = 'sawa-inst';
		btn.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4v11M7 10l5 5 5-5M5 20h14"/></svg>';
		btn.appendChild(document.createTextNode(T.btn));
		btn.addEventListener('click', onClick);
		document.body.appendChild(btn);
	}
	function say(html, steps, title) {
		if (msg) msg.remove();
		msg = document.createElement('div');
		msg.className = 'sawa-inst-msg'; msg.setAttribute('role', 'status');
		var h = title ? '<h3>' + title + '</h3>' : '';
		h += steps ? '<ol>' + steps.map(function (s) { return '<li>' + s + '</li>'; }).join('') + '</ol>' : '<p style="margin:0 0 10px">' + html + '</p>';
		msg.innerHTML = h + '<div class="row"><button type="button">' + T.close + '</button></div>';
		msg.querySelector('button').addEventListener('click', function () { msg.remove(); msg = null; });
		document.body.appendChild(msg);
	}

	// Chrome / Edge / Samsung: the browser offers its own install prompt
	window.addEventListener('beforeinstallprompt', function (e) {
		e.preventDefault(); deferred = e; store(false); // the prompt only comes when the app isn't installed
		showBtn(function () {
			if (!deferred) return;
			deferred.prompt();
			deferred.userChoice.then(function (c) {
				if (c && c.outcome === 'accepted') { hideBtn(); say(T.installing); }
				deferred = null;
			});
		});
	});
	window.addEventListener('appinstalled', function () { store(true); hideBtn(); say(T.done); });

	if (ios) {
		if (!stored()) showBtn(function () { say(null, T.ios, T.iosTitle); });
	} else if (phone) {
		// No install prompt after a moment (e.g. Firefox): show the menu steps instead
		setTimeout(function () { if (!deferred && !btn && !stored()) showBtn(function () { say(null, T.other, T.otherTitle); }); }, 2500);
	}
})();
