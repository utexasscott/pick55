/*
 * Account (r/account/index.php): friends as chips with one-tap remove (undo
 * toast), search-to-add over the season's players, suggested friends, all
 * through api/friends.php with optimistic updates; the theme control's
 * state; an "unsaved changes" note on the profile form (which the runtime
 * posts with data-async).
 */
P55.page('account', function (root, props) {
	'use strict';

	var esc = P55.esc;
	var friends = (props.friends || []).slice();
	var suggested = (props.suggested || []).slice();
	var players = props.players || [];
	var MAX_RESULTS = 8;

	var list = root.querySelector('[data-friend-list]');
	var empty = root.querySelector('[data-friend-empty]');
	var count = root.querySelector('[data-friend-count]');
	var search = root.querySelector('[data-friend-search]');
	var results = root.querySelector('[data-friend-results]');
	var status = root.querySelector('[data-friend-status]');
	var sugWrap = root.querySelector('[data-suggested-wrap]');
	var sugList = root.querySelector('[data-suggested-list]');
	var form = root.querySelector('form[data-profile]');

	var chain = Promise.resolve();
	var pending = 0;

	function initials(name) {
		var parts = String(name || '').trim().split(/\s+/).slice(0, 2);
		var out = parts.map(function (p) { return p.charAt(0).toUpperCase(); }).join('');
		return out || '?';
	}

	function byName(a, b) {
		return a.name.localeCompare(b.name, undefined, { sensitivity: 'base' });
	}

	function isFriend(id) {
		return friends.some(function (f) { return f.id === id; });
	}

	function chipHtml(f) {
		return '<li class="friend-chip" data-id="' + f.id + '">'
			+ '<span class="avatar avatar-sm" aria-hidden="true">' + esc(initials(f.name)) + '</span>'
			+ '<span class="friend-name">' + esc(f.name) + '</span>'
			+ '<button type="button" class="chip-x" data-friend-remove="' + f.id + '" aria-label="Remove ' + esc(f.name) + '" title="Remove">' + P55.icon('x') + '</button>'
			+ '</li>';
	}

	function personHtml(p) {
		return '<li class="person" data-id="' + p.id + '">'
			+ '<span class="avatar avatar-sm" aria-hidden="true">' + esc(initials(p.name)) + '</span>'
			+ '<span class="person-name truncate">' + esc(p.name) + '</span>'
			+ '<button type="button" class="btn btn-ghost btn-sm" data-friend-add="' + p.id + '" data-name="' + esc(p.name) + '" aria-label="Add ' + esc(p.name) + '">'
			+ P55.icon('plus') + 'Add</button></li>';
	}

	function renderResults() {
		if (!search || !results) {
			return;
		}
		var q = search.value.trim().toLowerCase();
		if (!q) {
			results.innerHTML = '';
			if (status) {
				status.textContent = '';
			}
			return;
		}
		var starts = [];
		var contains = [];
		players.forEach(function (p) {
			if (isFriend(p.id)) {
				return;
			}
			var n = p.name.toLowerCase();
			if (n.indexOf(q) === 0 || n.indexOf(' ' + q) > -1) {
				starts.push(p);
			}
			else if (n.indexOf(q) > -1) {
				contains.push(p);
			}
		});
		var matches = starts.concat(contains);
		results.innerHTML = matches.slice(0, MAX_RESULTS).map(personHtml).join('');
		if (status) {
			status.textContent = !matches.length
				? 'No players match “' + search.value.trim() + '”.'
				: (matches.length > MAX_RESULTS ? 'Showing ' + MAX_RESULTS + ' of ' + matches.length + ' matches.' : '');
		}
	}

	function render() {
		friends.sort(byName);
		if (list) {
			list.innerHTML = friends.map(chipHtml).join('');
		}
		if (empty) {
			empty.hidden = friends.length > 0;
		}
		if (count) {
			count.textContent = friends.length;
		}
		if (sugList) {
			sugList.innerHTML = suggested.map(personHtml).join('');
		}
		if (sugWrap) {
			sugWrap.hidden = suggested.length === 0;
		}
		renderResults();
	}

	/** Requests run one at a time; the server's lists win once none is pending. */
	function send(action, id) {
		pending++;
		var p = chain.then(function () {
			return P55.fetchJSON(props.api, { method: 'POST', body: { action: action, user_id: id } });
		});
		chain = p.then(function () {}, function () {});
		return p.then(function (data) {
			pending--;
			if (pending === 0 && data && data.ok) {
				friends = data.friends || [];
				suggested = data.suggested || [];
				render();
			}
			return data;
		}, function (err) {
			pending--;
			throw err;
		});
	}

	function errorText(err) {
		return (err && err.data && err.data.error) || 'Could not reach Pick55. Try again.';
	}

	function flashChip(id) {
		var chip = list && list.querySelector('.friend-chip[data-id="' + id + '"]');
		if (chip && !P55.motion.reduced) {
			chip.classList.add('is-new');
		}
	}

	function add(id, name) {
		if (isFriend(id)) {
			return;
		}
		var wasSuggested = suggested.filter(function (s) { return s.id === id; })[0] || null;
		friends.push({ id: id, name: name });
		suggested = suggested.filter(function (s) { return s.id !== id; });
		render();
		flashChip(id);
		send('add', id).then(function () {
			P55.toast(name + ' is now a friend.', 'success', { timeout: 2500 });
		}, function (err) {
			friends = friends.filter(function (f) { return f.id !== id; });
			if (wasSuggested) {
				suggested.push(wasSuggested);
				suggested.sort(byName);
			}
			render();
			P55.toast(errorText(err), 'error');
		});
	}

	function remove(id) {
		var f = friends.filter(function (x) { return x.id === id; })[0];
		if (!f) {
			return;
		}
		var next = null;
		if (list) {
			var chip = list.querySelector('.friend-chip[data-id="' + id + '"]');
			var sib = chip && (chip.nextElementSibling || chip.previousElementSibling);
			next = sib ? sib.getAttribute('data-id') : null;
		}
		friends = friends.filter(function (x) { return x.id !== id; });
		render();
		var focusEl = (next && list.querySelector('[data-friend-remove="' + next + '"]')) || search;
		if (focusEl) {
			focusEl.focus({ preventScroll: true });
		}
		send('remove', id).then(function () {
			P55.toast('Removed ' + f.name + '.', 'info', {
				timeout: 6000,
				action: {
					label: 'Undo',
					fn: function () {
						add(f.id, f.name);
					}
				}
			});
		}, function (err) {
			if (!isFriend(f.id)) {
				friends.push(f);
			}
			render();
			P55.toast(errorText(err), 'error');
		});
	}

	function nameOf(btn, id) {
		var n = btn.getAttribute('data-name');
		if (n) {
			return n;
		}
		var row = btn.closest('[data-id]');
		var el = row && row.querySelector('.person-name, .friend-name');
		if (el) {
			return el.textContent.trim();
		}
		var p = players.filter(function (x) { return x.id === id; })[0];
		return p ? p.name : 'Player';
	}

	function onClick(e) {
		var t = e.target;
		if (!t.closest) {
			return;
		}
		var rm = t.closest('[data-friend-remove]');
		if (rm && root.contains(rm)) {
			e.preventDefault();
			remove(parseInt(rm.getAttribute('data-friend-remove'), 10));
			return;
		}
		var ad = t.closest('[data-friend-add]');
		if (ad && root.contains(ad)) {
			e.preventDefault();
			var id = parseInt(ad.getAttribute('data-friend-add'), 10);
			var inResults = results && results.contains(ad);
			add(id, nameOf(ad, id));
			if (inResults && search) {
				search.focus({ preventScroll: true });
			}
		}
	}

	function onSearchKey(e) {
		if (e.key === 'Enter') {
			e.preventDefault();
			var first = results && results.querySelector('[data-friend-add]');
			if (first) {
				first.click();
			}
		}
		else if (e.key === 'ArrowDown') {
			var btn = results && results.querySelector('[data-friend-add]');
			if (btn) {
				e.preventDefault();
				btn.focus();
			}
		}
		else if (e.key === 'Escape' && search.value) {
			search.value = '';
			renderResults();
		}
	}

	function onResultsKey(e) {
		if (e.key !== 'ArrowDown' && e.key !== 'ArrowUp') {
			return;
		}
		var btns = Array.prototype.slice.call(results.querySelectorAll('[data-friend-add]'));
		var i = btns.indexOf(document.activeElement);
		if (i < 0) {
			return;
		}
		e.preventDefault();
		if (e.key === 'ArrowDown' && btns[i + 1]) {
			btns[i + 1].focus();
		}
		else if (e.key === 'ArrowUp') {
			(btns[i - 1] || search).focus();
		}
	}

	root.addEventListener('click', onClick);
	if (search) {
		search.addEventListener('input', renderResults);
		search.addEventListener('keydown', onSearchKey);
	}
	if (results) {
		results.addEventListener('keydown', onResultsKey);
	}

	// The theme control's pressed state is kept by the runtime.

	// ---- Profile: note unsaved changes
	var initial = null;
	var note = root.querySelector('[data-dirty-note]');
	function snapshot() {
		return form ? ['email', 'first_name', 'last_name', 'venmo_phone'].map(function (n) {
			return form.elements[n] ? form.elements[n].value : '';
		}).join('\u0001') : '';
	}
	function onFormInput() {
		if (note) {
			note.hidden = snapshot() === initial;
		}
	}
	if (form) {
		initial = snapshot();
		form.addEventListener('input', onFormInput);
		var invalid = form.querySelector('[aria-invalid="true"]');
		if (invalid) {
			invalid.focus();
			if (note) {
				note.hidden = false;
			}
		}
	}

	return function () {
		root.removeEventListener('click', onClick);
		if (search) {
			search.removeEventListener('input', renderResults);
			search.removeEventListener('keydown', onSearchKey);
		}
		if (results) {
			results.removeEventListener('keydown', onResultsKey);
		}
		if (form) {
			form.removeEventListener('input', onFormInput);
		}
	};
});
