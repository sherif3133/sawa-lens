/* Review-card fit check for the harness: await checkReview() in the console (or the browser tool's JS).
   Goes through every card (front, then answer, then Good) and reports any that run under the bottom bar
   or make the page scroll. Rule: one review card = one screen (marketing/decisions.md). */
window.checkReview = async function () {
	var sl = function (ms) { return new Promise(function (r) { setTimeout(r, ms); }); };
	var out = [], bad = [];
	function m(tag) {
		var c = document.querySelector('.rv-card'), a = document.querySelector('#acts'), se = document.scrollingElement;
		var r = { tag: tag, under: c && a ? Math.round(c.getBoundingClientRect().bottom - a.getBoundingClientRect().top) : null, scroll: se.scrollHeight - innerHeight, hscroll: se.scrollWidth - innerWidth };
		out.push(r);
		if (r.under > 0 || r.scroll > 16 || r.hscroll > 0) bad.push(r); // up to 16 px is the room placeActs() keeps under the bar
	}
	for (var i = 0; i < 8 && document.querySelector('#flip'); i++) {
		var k = (document.querySelector('.rv-kind') || {}).textContent || '';
		await sl(300); m('front ' + k);
		document.querySelector('#flip').click();
		await sl(400); m('back ' + k);
		document.querySelector('.gb.g3').click();
	}
	return { size: innerWidth + '×' + innerHeight, cards: out.length / 2, ok: !bad.length, bad: bad };
};
