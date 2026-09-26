/*
 * Today (r/index.php): refreshes the hero from api/today.php every minute
 * while a week is live, and re-renders the page when the moment changes
 * (a deadline passes, a game kicks off or ends, the mode flips).
 */
P55.page('today', function (root, props) {
	'use strict';

	var STATUS_TEXT = {
		covering: 'Winning',
		trailing: 'Losing',
		even: 'Even',
		pending: '',
		right: 'Right',
		wrong: 'Wrong',
		auto: 'Guaranteed',
		none: 'No pick'
	};
	var stop = null;
	var reloading = false;

	function reload() {
		if (reloading) {
			return;
		}
		reloading = true;
		P55.reload();
	}

	function each(sel, fn) {
		Array.prototype.forEach.call(root.querySelectorAll(sel), fn);
	}

	function setText(sel, text) {
		text = String(text);
		each(sel, function (el) {
			if (el.textContent !== text) {
				el.textContent = text;
				P55.flash(el);
			}
		});
	}

	function setNumber(sel, value, format) {
		each(sel, function (el) {
			var shown = format ? format(value) : String(value);
			if (el.textContent !== shown) {
				P55.countUp(el, value, format ? { format: format } : {});
				P55.flash(el);
			}
		});
	}

	function paintGame(card, g) {
		['away', 'home'].forEach(function (side) {
			var el = card.querySelector('[data-f="' + side + '"]');
			var v = g[side + '_score'];
			var text = v === null || v === undefined ? '' : String(v);
			if (el && el.textContent !== text) {
				el.textContent = text;
				P55.flash(el);
			}
		});
		var label = card.querySelector('[data-f="label"]');
		if (label) {
			var html = (g.state === 'in' ? '<span class="live-dot"></span>' : '') + P55.esc(g.label || g.kickoff);
			if (label.innerHTML !== html) {
				label.innerHTML = html;
			}
		}
		var status = card.querySelector('[data-f="status"]');
		var text = STATUS_TEXT[g.status] || '';
		if (status && status.textContent !== text) {
			status.textContent = text;
		}
		card.className = card.className.replace(/\bst-[a-z]+\b/g, '').replace(/\s+/g, ' ').trim() + ' st-' + g.status;
	}

	function paint(data) {
		var liveId = data.live_week ? data.live_week.id : null;
		var pickId = data.pick_week ? data.pick_week.id : null;
		if (data.mode !== props.mode || liveId !== props.live_week_id || pickId !== props.pick_week_id) {
			reload();
			return;
		}
		P55.setBadges(data.badges);
		var l = data.my_live;
		if (l) {
			setNumber('[data-live="points"]', l.points);
			setText('[data-live="rank"]', l.rank_label);
			setText('[data-live="players"]', l.players);
			setText('[data-live="right"]', l.right);
			setText('[data-live="wrong"]', l.wrong);
			setNumber('[data-live="games_left"]', l.games_left);
			setText('[data-live="in_play"]', l.in_play);
			setNumber('[data-live="expected"]', Math.round(l.expected), P55.fmt.money);
			setText('[data-live="behind-text"]', l.behind_label);
		}
		var reorder = false;
		(data.games || []).forEach(function (g) {
			var card = root.querySelector('[data-game="' + g.id + '"]');
			if (!card) {
				reorder = true;
				return;
			}
			if (card.getAttribute('data-state') !== g.state) {
				// A game kicked off or ended: the strip's order changes.
				reorder = true;
			}
			paintGame(card, g);
		});
		each('[data-live="fetched"]', function (el) {
			el.setAttribute('data-reltime', data.fetched_at);
			el.textContent = P55.relTime(data.fetched_at);
		});
		if (reorder) {
			reload();
		}
	}

	// A deadline on the page passed (picks lock, picks open): the moment moved.
	function onDeadline() {
		reload();
	}
	root.addEventListener('p55:deadline', onDeadline);

	if (props.poll) {
		stop = P55.poll(function () {
			return P55.fetchJSON(props.api).then(paint);
		}, props.poll);
	}

	return function () {
		if (stop) {
			stop();
		}
		root.removeEventListener('p55:deadline', onDeadline);
	};
});
