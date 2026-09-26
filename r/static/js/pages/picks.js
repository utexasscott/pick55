/*
 * Make Picks (r/season/week/pick.php, docs/redesign.md section 5).
 *
 * State is the DOM: the order of .pk-card elements in the list and each
 * card's chosen side (options, by game id). Position gives the point value:
 * 10..1 for the first ten cards, 0 after. Every change saves the whole week
 * through api/save-picks.php after a 400 ms debounce, one request at a time,
 * retrying with backoff while offline.
 *
 * Gestures: tap a side; drag a card by its grip (Pointer Events, captured on
 * the grip, which alone has touch-action: none); or focus the grip and use
 * the arrow keys, or the up/down buttons. Undo reverts the last change.
 */
P55.page('picks', function (root, props) {
	'use strict';

	var pk = root.querySelector('[data-pk]');
	var list = root.querySelector('[data-pk-list]');
	if (!pk || !list) {
		return;
	}

	var slots = P55.$$('.pk-slot', root);
	var liveEl = root.querySelector('[data-pk-live]');
	var statusBox = root.querySelector('[data-pk-status]');
	var ringEl = root.querySelector('[data-pk-ring] .progress-ring');
	var lockedBox = root.querySelector('[data-pk-locked]');
	var lockTpl = root.querySelector('template[data-pk-icon="lock"]');
	var phoneMq = window.matchMedia ? window.matchMedia('(max-width: 899.98px)') : null;

	var weekId = props.week_id;
	var lt = parseInt(props.guarantee_lt, 10) || 0;
	var saved = props.saved || {};
	var STORE = 'p55-picks-order-' + weekId;
	var HISTORY_MAX = 50;

	var options = {};
	var names = {};
	var byId = {};
	var history = [];
	var drag = null;
	var locked = false;
	var torn = false;
	var signedOut = false;

	var saveTimer = null;
	var retryTimer = null;
	var retryDelay = 0;
	var deadlineTimer = null;
	var announceTimer = null;
	var timers = [];
	var inflight = false;
	var queued = false;
	var changeSeq = 0;
	var savedSeq = 0;
	var moveToast = null;

	// ------------------------------------------------------------------
	// Helpers

	function cards() {
		return Array.prototype.slice.call(list.children).filter(function (el) {
			return el.classList.contains('pk-card');
		});
	}

	function idOf(card) {
		return parseInt(card.getAttribute('data-game'), 10);
	}

	function multAt(i) {
		return i < 10 ? 10 - i : 0;
	}

	function guaranteedAt(i) {
		return lt > 0 && multAt(i) < lt;
	}

	function isPicked(opt) {
		return opt === 1 || opt === 2 || opt === 3;
	}

	function reduced() {
		return !!(P55.motion && P55.motion.reduced);
	}

	function later(fn, ms) {
		var t = setTimeout(function () {
			var k = timers.indexOf(t);
			if (k !== -1) {
				timers.splice(k, 1);
			}
			fn();
		}, ms);
		timers.push(t);
		return t;
	}

	function pts(v) {
		return v + (v === 1 ? ' point' : ' points');
	}

	function announce(text) {
		if (!liveEl) {
			return;
		}
		clearTimeout(announceTimer);
		liveEl.textContent = '';
		announceTimer = setTimeout(function () {
			liveEl.textContent = text;
		}, 60);
	}

	function focusNoScroll(el) {
		if (!el) {
			return;
		}
		try {
			el.focus({ preventScroll: true });
		}
		catch (e) {
			el.focus();
		}
	}

	/** Top and bottom of the viewport not covered by the sticky bars. */
	function insets() {
		var top = 0;
		var bottom = 0;
		var bar = document.querySelector('.topbar');
		if (bar) {
			top = Math.max(0, bar.getBoundingClientRect().bottom);
		}
		if (phoneMq && phoneMq.matches) {
			var sum = root.querySelector('.pk-summary');
			if (sum) {
				var r = sum.getBoundingClientRect();
				if (r.bottom > 0 && r.top < window.innerHeight / 2) {
					top = Math.max(top, r.bottom);
				}
			}
		}
		var tabs = document.querySelector('.tabbar');
		if (tabs && window.getComputedStyle(tabs).display !== 'none') {
			bottom = tabs.getBoundingClientRect().height;
		}
		return { top: top, bottom: bottom };
	}

	/** Scrolls a card into the uncovered part of the viewport (ignores transforms). */
	function ensureVisible(card) {
		var ins = insets();
		var top = list.getBoundingClientRect().top + card.offsetTop;
		var bottom = top + card.offsetHeight;
		var dy = 0;
		if (top < ins.top + 8) {
			dy = top - ins.top - 8;
		}
		else if (bottom > window.innerHeight - ins.bottom - 8) {
			dy = bottom - (window.innerHeight - ins.bottom - 8);
		}
		if (dy) {
			try {
				window.scrollBy({ top: dy, behavior: reduced() ? 'auto' : 'smooth' });
			}
			catch (e) {
				window.scrollBy(0, dy);
			}
		}
	}

	// ------------------------------------------------------------------
	// State

	cards().forEach(function (card) {
		var id = idOf(card);
		byId[id] = card;
		options[id] = parseInt(card.getAttribute('data-option'), 10) || 0;
		var title = card.querySelector('.pk-title');
		names[id] = title && title.firstChild ? String(title.firstChild.textContent).trim() : 'Game';
	});

	function order() {
		return cards().map(idOf);
	}

	function snapshot() {
		return { order: order(), options: Object.assign({}, options) };
	}

	function pushHistory(snap) {
		history.push(snap);
		if (history.length > HISTORY_MAX) {
			history.shift();
		}
	}

	function placeAt(card, index) {
		var rest = cards().filter(function (c) {
			return c !== card;
		});
		list.insertBefore(card, rest[index] || null);
	}

	/** Repaints every card, the rail and the summary from the state. */
	function paint() {
		var cs = cards();
		var n = cs.length;
		var sides = 0;
		var values = 0;
		cs.forEach(function (card, i) {
			var id = idOf(card);
			var v = multAt(i);
			var g = guaranteedAt(i);
			var opt = options[id] || 0;
			var picked = isPicked(opt);
			if (picked || g) {
				sides++;
				if (v > 0) {
					values++;
				}
			}
			card.classList.toggle('is-zero', v === 0);
			card.classList.toggle('is-guaranteed', g);
			card.classList.toggle('is-picked', picked);
			card.setAttribute('data-option', String(opt));
			P55.$$('[data-pick]', card).forEach(function (b) {
				b.setAttribute('aria-pressed', parseInt(b.getAttribute('data-pick'), 10) === opt ? 'true' : 'false');
			});
			var sr = card.querySelector('[data-pk-sr]');
			var label = pts(v) + (g ? ', guaranteed' : '');
			if (sr && sr.textContent !== label) {
				sr.textContent = label;
			}
			var grip = card.querySelector('[data-grip]');
			if (grip) {
				grip.setAttribute('aria-label', 'Reorder ' + names[id] + ', ' + label + ', position ' + (i + 1) + ' of ' + n);
				grip.disabled = locked;
			}
			P55.$$('[data-move]', card).forEach(function (b) {
				var dir = parseInt(b.getAttribute('data-move'), 10);
				b.disabled = locked || (dir < 0 ? i === 0 : i === n - 1);
			});
			P55.$$('[data-pick]', card).forEach(function (b) {
				b.disabled = locked;
			});
		});
		var needed = parseInt(props.values_needed, 10) || Math.min(10, n);
		var sidesEl = root.querySelector('[data-pk="sides"]');
		if (sidesEl && sidesEl.textContent !== String(sides)) {
			sidesEl.textContent = String(sides);
		}
		var sidesText = root.querySelector('[data-pk="sides-text"]');
		if (sidesText) {
			sidesText.textContent = sides + '/' + n;
		}
		var valuesText = root.querySelector('[data-pk="values-text"]');
		if (valuesText) {
			valuesText.textContent = values + '/' + needed;
		}
		if (ringEl) {
			P55.ring(ringEl, 0, n ? sides / n : 0);
			P55.ring(ringEl, 1, needed ? values / needed : 0);
			ringEl.setAttribute('aria-label', sides + ' of ' + n + ' sides chosen, ' + values + ' of ' + needed + ' point values placed');
		}
		P55.$$('[data-pk-undo]', root).forEach(function (b) {
			b.disabled = locked || !history.length;
		});
		P55.$$('[data-pk-next]', root).forEach(function (b) {
			b.disabled = locked;
		});
	}

	function persistOrder() {
		try {
			localStorage.setItem(STORE, JSON.stringify(order()));
		}
		catch (e) {
			// storage blocked: the zero-point order falls back to kickoff order
		}
	}

	/**
	 * The server keeps point values, not the order of the 0-point games; the
	 * browser remembers that order so it survives a reload.
	 */
	function restoreTail() {
		var stored = null;
		try {
			stored = JSON.parse(localStorage.getItem(STORE) || 'null');
		}
		catch (e) {
			stored = null;
		}
		if (!Array.isArray(stored)) {
			return;
		}
		var cs = cards();
		if (cs.length <= 10) {
			return;
		}
		for (var i = 0; i < 10; i++) {
			var s = saved[idOf(cs[i])];
			if (!s || s[1] !== multAt(i)) {
				return;
			}
		}
		var rank = {};
		stored.forEach(function (id, k) {
			rank[id] = k;
		});
		var tail = cs.slice(10);
		var sorted = tail.slice().sort(function (a, b) {
			var ra = rank[idOf(a)];
			var rb = rank[idOf(b)];
			ra = ra === undefined ? 1e6 + tail.indexOf(a) : ra;
			rb = rb === undefined ? 1e6 + tail.indexOf(b) : rb;
			return ra - rb;
		});
		sorted.forEach(function (card) {
			list.appendChild(card);
		});
	}

	/** Does the page show something other than what is stored? */
	function differsFromSaved() {
		var cs = cards();
		for (var i = 0; i < cs.length; i++) {
			var id = idOf(cs[i]);
			var s = saved[id];
			if (!s || s[0] !== (options[id] || 0) || s[1] !== multAt(i)) {
				return true;
			}
		}
		return false;
	}

	// ------------------------------------------------------------------
	// Status pill

	function clock(iso) {
		var d = iso ? new Date(iso) : new Date();
		if (isNaN(d.getTime())) {
			d = new Date();
		}
		return d.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
	}

	function setStatus(kind, extra) {
		if (!statusBox) {
			return;
		}
		var tone = '';
		var iconHtml = '';
		var text = '';
		switch (kind) {
			case 'saving':
				iconHtml = '<span class="pk-spinner" aria-hidden="true"></span>';
				text = P55.esc('Saving…');
				break;
			case 'saved':
				tone = 'pill-good';
				iconHtml = P55.icon('check');
				text = P55.esc('Saved ' + clock(extra));
				break;
			case 'synced':
				tone = 'pill-good';
				iconHtml = P55.icon('check');
				text = 'All saved';
				break;
			case 'offline':
				tone = 'pill-warn';
				iconHtml = P55.icon('wifi-off');
				text = 'Offline, will retry';
				break;
			case 'signedout':
				tone = 'pill-bad';
				iconHtml = P55.icon('alert-triangle');
				text = 'Signed out &middot; <a href="' + P55.esc(props.login_url || P55.link('auth/login.php')) + '" data-native>Sign in</a>';
				break;
			case 'error':
				tone = 'pill-bad';
				iconHtml = P55.icon('alert-triangle');
				text = 'Not saved';
				break;
			case 'locked':
				tone = 'pill-outline';
				iconHtml = lockTpl ? lockTpl.innerHTML : '';
				text = 'Locked';
				break;
		}
		var html = '<span class="pill ' + tone + '" data-state="' + kind + '"' + (extra && kind === 'error' ? ' title="' + P55.esc(extra) + '"' : '') + '>'
			+ iconHtml + '<span data-pk-status-text>' + text + '</span></span>';
		if (statusBox.innerHTML !== html) {
			statusBox.innerHTML = html;
		}
	}

	// ------------------------------------------------------------------
	// Saving

	function payload() {
		return {
			week_id: weekId,
			picks: cards().map(function (card, i) {
				var id = idOf(card);
				return { game_id: id, option: options[id] || 0, mult: multAt(i) };
			})
		};
	}

	function changed() {
		changeSeq++;
		persistOrder();
		if (locked || signedOut) {
			return;
		}
		clearTimeout(saveTimer);
		clearTimeout(retryTimer);
		retryTimer = null;
		setStatus('saving');
		saveTimer = setTimeout(function () {
			saveTimer = null;
			send(false);
		}, 400);
	}

	function send(keepalive) {
		if (locked || signedOut || (torn && !keepalive)) {
			return;
		}
		if (inflight) {
			queued = true;
			return;
		}
		if (savedSeq === changeSeq) {
			return;
		}
		var seq = changeSeq;
		inflight = true;
		if (!torn) {
			setStatus('saving');
		}
		P55.fetchJSON(props.api, { body: payload(), keepalive: !!keepalive }).then(function (res) {
			inflight = false;
			retryDelay = 0;
			savedSeq = Math.max(savedSeq, seq);
			if (res && res.badges) {
				P55.setBadges(res.badges);
			}
			if (torn) {
				return;
			}
			if (savedSeq === changeSeq) {
				queued = false;
				setStatus('saved', res && res.saved_at);
			}
			else if (!saveTimer) {
				// Changed while saving: send the newer state now.
				queued = false;
				send(false);
			}
		}, function (err) {
			inflight = false;
			if (torn) {
				return;
			}
			var status = err && err.status;
			var data = (err && err.data) || {};
			if (status === 401) {
				signedOut = true;
				setStatus('signedout');
				return;
			}
			if (status === 409 || data.locked) {
				lock();
				return;
			}
			if (status && status >= 400 && status < 500) {
				setStatus('error', data.error || err.message);
				P55.toast('Your picks were not saved: ' + (data.error || err.message), 'error');
				return;
			}
			// Network trouble or a server error: keep the change, retry with backoff.
			retryDelay = retryDelay ? Math.min(retryDelay * 2, 30000) : 1500;
			setStatus('offline');
			clearTimeout(retryTimer);
			retryTimer = setTimeout(function () {
				retryTimer = null;
				send(false);
			}, retryDelay);
		});
	}

	/** Sends a pending change right away (leaving the page, hiding the tab). */
	function flush() {
		if (locked || signedOut || savedSeq === changeSeq || inflight) {
			return;
		}
		clearTimeout(saveTimer);
		saveTimer = null;
		clearTimeout(retryTimer);
		retryTimer = null;
		send(true);
	}

	function onOnline() {
		if (savedSeq !== changeSeq && !inflight && !saveTimer) {
			clearTimeout(retryTimer);
			retryTimer = null;
			retryDelay = 0;
			send(false);
		}
	}

	function onHidden() {
		if (document.hidden) {
			flush();
		}
	}

	// ------------------------------------------------------------------
	// Motion

	/** FLIP: animates cards from where they were to where mutate() put them. */
	function flip(mutate) {
		var cs = cards();
		if (reduced()) {
			mutate();
			return;
		}
		var first = cs.map(function (c) {
			return c.getBoundingClientRect().top;
		});
		mutate();
		var moved = [];
		cs.forEach(function (c, i) {
			var dy = first[i] - c.getBoundingClientRect().top;
			if (Math.abs(dy) < 1) {
				return;
			}
			c.style.transition = 'none';
			c.style.transform = 'translate3d(0,' + dy + 'px,0)';
			moved.push(c);
		});
		if (!moved.length) {
			return;
		}
		void list.offsetHeight;
		moved.forEach(function (c) {
			c.style.transition = 'transform 280ms var(--ease)';
			c.style.transform = '';
		});
		later(function () {
			moved.forEach(function (c) {
				if (!c.style.transform) {
					c.style.transition = '';
				}
			});
		}, 320);
	}

	function highlight(card) {
		if (reduced()) {
			return;
		}
		card.classList.remove('is-moved');
		void card.offsetWidth;
		card.classList.add('is-moved');
		later(function () {
			card.classList.remove('is-moved');
		}, 950);
	}

	function haptic() {
		if (navigator.vibrate) {
			try {
				navigator.vibrate(6);
			}
			catch (e) {
				// not allowed: fine
			}
		}
	}

	// ------------------------------------------------------------------
	// Changes

	function pick(card, opt) {
		var id = idOf(card);
		if (locked || options[id] === opt) {
			return;
		}
		pushHistory(snapshot());
		options[id] = opt;
		paint();
		var chip = card.querySelector('[data-pick="' + opt + '"]');
		if (chip && !reduced()) {
			chip.classList.remove('is-pop');
			void chip.offsetWidth;
			chip.classList.add('is-pop');
			later(function () {
				chip.classList.remove('is-pop');
			}, 320);
		}
		var nameEl = chip ? chip.querySelector('.pk-side-name') : null;
		announce((nameEl ? nameEl.textContent : 'Side') + ' picked for ' + names[id] + '.');
		changed();
	}

	/** Tells screen readers (and, with toast, everyone) where a card landed. */
	function announceMove(card, to, toast) {
		var v = multAt(to);
		var g = guaranteedAt(to);
		var name = names[idOf(card)];
		announce(name + (g ? ' is guaranteed' : ' is worth ' + pts(v)) + ', position ' + (to + 1) + ' of ' + cards().length + '.');
		if (!toast) {
			return;
		}
		highlight(card);
		if (moveToast) {
			moveToast.close();
		}
		moveToast = P55.toast(name + ' → ' + (g ? 'guaranteed' : pts(v)), 'info', {
			timeout: 5000,
			action: { label: 'Undo', fn: undo }
		});
	}

	/**
	 * Moves a card to a new index with a FLIP animation. how: 'key' (focus
	 * stays on the grip, no toast) or 'button' (a toast with Undo).
	 */
	function moveTo(card, to, how) {
		var cs = cards();
		var from = cs.indexOf(card);
		to = Math.max(0, Math.min(cs.length - 1, to));
		if (locked || from === -1 || from === to) {
			return;
		}
		var active = document.activeElement;
		var hadFocus = active && card.contains(active) ? active : null;
		pushHistory(snapshot());
		flip(function () {
			placeAt(card, to);
		});
		paint();
		if (hadFocus) {
			if (hadFocus.disabled) {
				// The up button at the top (or down at the bottom) is now disabled.
				hadFocus = card.querySelector('[data-grip]');
			}
			focusNoScroll(hadFocus);
		}
		ensureVisible(card);
		announceMove(card, to, how !== 'key');
		changed();
	}

	function undo() {
		if (locked || !history.length) {
			return;
		}
		var snap = history.pop();
		var active = document.activeElement;
		options = Object.assign({}, snap.options);
		flip(function () {
			snap.order.forEach(function (id) {
				if (byId[id]) {
					list.appendChild(byId[id]);
				}
			});
		});
		paint();
		if (active && root.contains(active) && document.activeElement !== active && !active.disabled) {
			focusNoScroll(active);
		}
		if (moveToast) {
			moveToast.close();
			moveToast = null;
		}
		announce('Undone.');
		changed();
	}

	function nextOpen() {
		var cs = cards();
		for (var i = 0; i < cs.length; i++) {
			var card = cs[i];
			if (!isPicked(options[idOf(card)] || 0) && !guaranteedAt(i)) {
				ensureVisible(card);
				highlight(card);
				focusNoScroll(card.querySelector('[data-pick]'));
				return;
			}
		}
		P55.toast('Every game has a side. You are all set.', 'success');
	}

	// ------------------------------------------------------------------
	// Lock (the first kickoff passed)

	function lock() {
		if (locked) {
			return;
		}
		if (drag) {
			endDrag(false, true);
		}
		locked = true;
		clearTimeout(saveTimer);
		saveTimer = null;
		clearTimeout(retryTimer);
		retryTimer = null;
		pk.classList.add('is-locked');
		if (lockedBox) {
			lockedBox.hidden = false;
		}
		paint();
		setStatus('locked');
		if (moveToast) {
			moveToast.close();
			moveToast = null;
		}
		announce('Picks are locked. The first game has kicked off.');
		if (lockedBox && lockedBox.getBoundingClientRect().bottom < 0) {
			P55.toast('Picks are locked. See the results.', 'warning');
		}
	}

	function onDeadline(e) {
		if (e.target && e.target.hasAttribute && e.target.hasAttribute('data-pk-countdown')) {
			lock();
		}
	}

	// ------------------------------------------------------------------
	// Dragging (Pointer Events)

	function onPointerDown(e) {
		var grip = e.target.closest ? e.target.closest('[data-grip]') : null;
		if (!grip || !list.contains(grip) || locked || drag || !e.isPrimary) {
			return;
		}
		if (e.pointerType === 'mouse' && e.button !== 0) {
			return;
		}
		if (e.pointerType === 'mouse') {
			// No text selection or native image drag; focus stays ours.
			e.preventDefault();
		}
		focusNoScroll(grip);
		var card = grip.closest('.pk-card');
		drag = {
			card: card,
			grip: grip,
			pointerId: e.pointerId,
			startX: e.clientX,
			startY: e.clientY,
			y: e.clientY,
			active: false,
			raf: 0
		};
		try {
			grip.setPointerCapture(e.pointerId);
		}
		catch (err) {
			// capture unavailable: moves outside the grip are lost, up still ends the drag
		}
		grip.addEventListener('pointermove', onPointerMove);
		grip.addEventListener('pointerup', onPointerUp);
		grip.addEventListener('pointercancel', onPointerCancel);
		grip.addEventListener('lostpointercapture', onLostCapture);
	}

	function beginDrag() {
		var d = drag;
		var cs = cards();
		// Clear any leftover FLIP transforms so the geometry is exact.
		cs.forEach(function (c) {
			c.style.transition = '';
			c.style.transform = '';
		});
		d.cards = cs;
		d.from = cs.indexOf(d.card);
		d.index = d.from;
		d.scroll0 = window.scrollY;
		d.tops = cs.map(function (c) {
			return c.offsetTop;
		});
		d.step = cs.length > 1 ? d.tops[1] - d.tops[0] : d.card.offsetHeight;
		d.dy = 0;
		d.insets = insets();
		d.before = snapshot();
		d.cursor = document.body.style.cursor;
		d.active = true;
		document.body.style.cursor = 'grabbing';
		pk.classList.add('is-dragging');
		list.classList.add('is-sorting');
		d.card.classList.add('is-lifted');
		markSlot(d.index);
		haptic();
		window.addEventListener('scroll', onDragScroll, { passive: true });
		document.addEventListener('keydown', onDragKey, true);
		d.raf = requestAnimationFrame(autoScroll);
	}

	function markSlot(index) {
		slots.forEach(function (s, i) {
			s.classList.toggle('is-target', i === index);
		});
	}

	/** Positions the lifted card and shifts the others around its target. */
	function updateDrag() {
		var d = drag;
		if (!d || !d.active) {
			return;
		}
		var n = d.cards.length;
		var dy = (d.y + window.scrollY) - (d.startY + d.scroll0);
		var min = d.tops[0] - d.tops[d.from] - d.step * 0.35;
		var max = d.tops[n - 1] - d.tops[d.from] + d.step * 0.35;
		dy = Math.max(min, Math.min(max, dy));
		d.dy = dy;
		d.card.style.transform = 'translate3d(0,' + dy + 'px,0) scale(1.02)';
		var index = Math.round((d.tops[d.from] + dy - d.tops[0]) / d.step);
		index = Math.max(0, Math.min(n - 1, index));
		if (index === d.index) {
			return;
		}
		d.index = index;
		d.cards.forEach(function (c, i) {
			if (c === d.card) {
				return;
			}
			var shift = 0;
			if (d.from < index && i > d.from && i <= index) {
				shift = -d.step;
			}
			else if (d.from > index && i >= index && i < d.from) {
				shift = d.step;
			}
			c.style.transform = shift ? 'translate3d(0,' + shift + 'px,0)' : '';
		});
		// The lifted card previews the value it would take.
		d.card.classList.toggle('is-zero', multAt(index) === 0);
		d.card.classList.toggle('is-guaranteed', guaranteedAt(index));
		markSlot(index);
		haptic();
	}

	/** Scrolls the page while the pointer is near the top or bottom edge. */
	function autoScroll() {
		var d = drag;
		if (!d || !d.active) {
			return;
		}
		var zone = 72;
		var top = d.insets.top;
		var bottom = window.innerHeight - d.insets.bottom;
		var speed = 0;
		if (d.y < top + zone) {
			speed = -Math.ceil(Math.min(1, (top + zone - d.y) / zone) * 20);
		}
		else if (d.y > bottom - zone) {
			speed = Math.ceil(Math.min(1, (d.y - (bottom - zone)) / zone) * 20);
		}
		if (speed) {
			var was = window.scrollY;
			window.scrollBy(0, speed);
			if (window.scrollY !== was) {
				updateDrag();
			}
		}
		d.raf = requestAnimationFrame(autoScroll);
	}

	function onPointerMove(e) {
		var d = drag;
		if (!d || e.pointerId !== d.pointerId) {
			return;
		}
		d.y = e.clientY;
		if (!d.active) {
			if (Math.abs(e.clientY - d.startY) < 4 && Math.abs(e.clientX - d.startX) < 4) {
				return;
			}
			beginDrag();
		}
		if (e.cancelable) {
			e.preventDefault();
		}
		updateDrag();
	}

	function onPointerUp(e) {
		if (drag && e.pointerId === drag.pointerId) {
			endDrag(true, false);
		}
	}

	function onPointerCancel(e) {
		if (drag && e.pointerId === drag.pointerId) {
			endDrag(false, false);
		}
	}

	function onLostCapture(e) {
		if (drag && e.pointerId === drag.pointerId) {
			endDrag(false, false);
		}
	}

	function onDragScroll() {
		updateDrag();
	}

	function onDragKey(e) {
		if (e.key === 'Escape' && drag) {
			e.preventDefault();
			e.stopPropagation();
			endDrag(false, false);
		}
	}

	/**
	 * Ends a drag. commit: keep the new place (else the card goes back).
	 * instant: no settle animation (teardown, lock).
	 */
	function endDrag(commit, instant) {
		var d = drag;
		if (!d) {
			return;
		}
		drag = null;
		cancelAnimationFrame(d.raf);
		d.grip.removeEventListener('pointermove', onPointerMove);
		d.grip.removeEventListener('pointerup', onPointerUp);
		d.grip.removeEventListener('pointercancel', onPointerCancel);
		d.grip.removeEventListener('lostpointercapture', onLostCapture);
		try {
			if (d.grip.hasPointerCapture && d.grip.hasPointerCapture(d.pointerId)) {
				d.grip.releasePointerCapture(d.pointerId);
			}
		}
		catch (err) {
			// already released
		}
		if (!d.active) {
			return;
		}
		window.removeEventListener('scroll', onDragScroll);
		document.removeEventListener('keydown', onDragKey, true);
		document.body.style.cursor = d.cursor || '';
		pk.classList.remove('is-dragging');
		markSlot(-1);

		var card = d.card;
		var to = commit ? d.index : d.from;
		var moved = to !== d.from;
		var animate = !instant && !reduced();

		// The others: after the DOM move they already sit where they were
		// shifted to, so drop their transforms without a transition. On a
		// cancel they glide back.
		d.cards.forEach(function (c) {
			if (c === card) {
				return;
			}
			if (moved || !animate) {
				c.style.transition = 'none';
			}
			c.style.transform = '';
		});
		if (moved) {
			placeAt(card, to);
		}
		// The lifted card: from where it is drawn to its slot.
		var offset = d.tops[d.from] + d.dy - d.tops[to];
		card.classList.remove('is-lifted');
		if (animate && Math.abs(offset) > 0.5) {
			card.classList.add('is-settling');
			card.style.transition = 'none';
			card.style.transform = 'translate3d(0,' + offset + 'px,0) scale(1.02)';
			void list.offsetHeight;
			card.style.transition = 'transform 240ms var(--ease)';
			card.style.transform = '';
		}
		else {
			card.style.transition = '';
			card.style.transform = '';
		}
		void list.offsetHeight;
		d.cards.forEach(function (c) {
			if (c !== card) {
				c.style.transition = '';
			}
		});
		var settle = function () {
			list.classList.remove('is-sorting');
			card.classList.remove('is-settling');
			card.style.transition = '';
		};
		if (animate) {
			later(settle, 280);
		}
		else {
			settle();
		}
		if (!moved) {
			paint();
			return;
		}
		// Undo goes back to the order from before the drag.
		pushHistory(d.before);
		paint();
		if (!instant) {
			focusNoScroll(d.grip);
			announceMove(card, to, true);
			haptic();
		}
		changed();
	}

	function onContextMenu(e) {
		if (e.target.closest && e.target.closest('[data-grip]')) {
			e.preventDefault();
		}
	}

	// ------------------------------------------------------------------
	// Clicks and keys

	function onClick(e) {
		var t = e.target;
		if (!t.closest) {
			return;
		}
		var chip = t.closest('[data-pick]');
		if (chip && list.contains(chip)) {
			pick(chip.closest('.pk-card'), parseInt(chip.getAttribute('data-pick'), 10));
			return;
		}
		var mv = t.closest('[data-move]');
		if (mv && list.contains(mv)) {
			var card = mv.closest('.pk-card');
			moveTo(card, cards().indexOf(card) + parseInt(mv.getAttribute('data-move'), 10), 'button');
			return;
		}
		if (t.closest('[data-pk-undo]')) {
			undo();
			return;
		}
		if (t.closest('[data-pk-next]')) {
			nextOpen();
		}
	}

	function onKeyDown(e) {
		var grip = e.target.closest ? e.target.closest('[data-grip]') : null;
		if (!grip || !list.contains(grip) || drag || locked || e.altKey || e.ctrlKey || e.metaKey) {
			return;
		}
		var card = grip.closest('.pk-card');
		var cs = cards();
		var i = cs.indexOf(card);
		var to = null;
		switch (e.key) {
			case 'ArrowUp':
				to = i - 1;
				break;
			case 'ArrowDown':
				to = i + 1;
				break;
			case 'Home':
				to = 0;
				break;
			case 'End':
				to = cs.length - 1;
				break;
			case 'PageUp':
				to = i - 5;
				break;
			case 'PageDown':
				to = i + 5;
				break;
		}
		if (to === null) {
			return;
		}
		e.preventDefault();
		to = Math.max(0, Math.min(cs.length - 1, to));
		if (to !== i) {
			moveTo(card, to, 'key');
		}
	}

	// ------------------------------------------------------------------
	// Start

	restoreTail();
	paint();
	persistOrder();

	root.addEventListener('click', onClick);
	list.addEventListener('keydown', onKeyDown);
	list.addEventListener('pointerdown', onPointerDown);
	list.addEventListener('contextmenu', onContextMenu);
	root.addEventListener('p55:deadline', onDeadline);
	window.addEventListener('online', onOnline);
	window.addEventListener('pagehide', flush);
	document.addEventListener('visibilitychange', onHidden);

	var deadline = props.deadline ? Date.parse(props.deadline) : NaN;
	if (!isNaN(deadline)) {
		var left = deadline - Date.now();
		if (left <= 0) {
			lock();
		}
		else if (left < 2147483000) {
			deadlineTimer = setTimeout(lock, left + 250);
		}
	}

	if (!locked) {
		if (differsFromSaved()) {
			// First visit or new games: store what the page shows, as the classic page did.
			changeSeq = 1;
			savedSeq = 0;
			send(false);
		}
		else {
			setStatus('synced');
		}
	}

	return function () {
		if (drag) {
			endDrag(false, true);
		}
		flush();
		torn = true;
		clearTimeout(saveTimer);
		clearTimeout(retryTimer);
		clearTimeout(deadlineTimer);
		clearTimeout(announceTimer);
		timers.forEach(clearTimeout);
		timers = [];
		if (moveToast) {
			moveToast.close();
			moveToast = null;
		}
		root.removeEventListener('click', onClick);
		list.removeEventListener('keydown', onKeyDown);
		list.removeEventListener('pointerdown', onPointerDown);
		list.removeEventListener('contextmenu', onContextMenu);
		root.removeEventListener('p55:deadline', onDeadline);
		window.removeEventListener('online', onOnline);
		window.removeEventListener('pagehide', flush);
		document.removeEventListener('visibilitychange', onHidden);
	};
});
