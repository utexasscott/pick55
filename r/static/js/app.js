/*
 * Pick55 redesign runtime (docs/redesign.md section 3), exposed as window.P55.
 * Vanilla ES2018, no libraries.
 *
 * Navigation: clicks on links under r/ fetch the page's JSON fragment
 * (X-P55-Partial: 1) and swap #app-main, with a View Transition when the
 * browser has one. Anything unexpected (a network error, a reply that is
 * not the fragment contract, a redirect out of r/, a change of sign-in
 * state) falls back to a normal page load, so a hard load of any URL is
 * always the source of truth.
 */
(function (window, document) {
	'use strict';

	var root = document.documentElement;
	var BASE = root.getAttribute('data-base') || '/';
	var CLASSIC_BASE = BASE.replace(/r\/$/, '');
	var THEME_KEY = 'p55-theme';
	var P55 = window.P55 || {};
	window.P55 = P55;

	var modules = {};
	var active = { name: null, props: {}, el: null, teardown: null, started: false };
	var booted = false;
	var loaded = {};
	var navSeq = 0;
	var inflight = null;
	var histIdx = 0;
	var currentPage = '';
	var prefetched = {};
	var scrollMap = {};
	var reducedMq = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : null;
	var darkMq = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;

	// ------------------------------------------------------------------
	// Small helpers

	function $(sel, el) {
		return (el || document).querySelector(sel);
	}

	function $$(sel, el) {
		return Array.prototype.slice.call((el || document).querySelectorAll(sel));
	}

	function esc(value) {
		return String(value == null ? '' : value)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#39;');
	}

	function toURL(href) {
		return new URL(href, location.href);
	}

	function isInternal(url) {
		return url.origin === location.origin && url.pathname.indexOf(BASE) === 0;
	}

	function isPage(url) {
		var p = url.pathname;
		return p.slice(-4) === '.php' || p.slice(-1) === '/';
	}

	function noHash(href) {
		return String(href).split('#')[0];
	}

	/** 'season/standings.php?id=18' for the current (or given) URL. */
	function relPath(href) {
		var u = toURL(href || location.href);
		var p = u.pathname.indexOf(BASE) === 0 ? u.pathname.slice(BASE.length) : '';
		return p + u.search;
	}

	function dispatch(name, detail, target) {
		var ev;
		try {
			ev = new CustomEvent(name, { detail: detail, bubbles: true });
		}
		catch (e) {
			ev = document.createEvent('CustomEvent');
			ev.initCustomEvent(name, true, false, detail);
		}
		(target || document).dispatchEvent(ev);
	}

	var ICONS = {
		check: '<path d="M20 6 9 17l-5-5"/>',
		x: '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
		info: '<circle cx="12" cy="12" r="9.5"/><path d="M12 16v-4.5"/><path d="M12 8h.01"/>',
		'alert-triangle': '<path d="M10.3 3.9 1.9 18a2 2 0 0 0 1.7 3h16.8a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
		'wifi-off': '<path d="M2 2l20 20"/><path d="M8.5 16.4a5 5 0 0 1 7 0"/><path d="M5 12.9a10 10 0 0 1 5.2-2.7"/><path d="M19 12.9a10 10 0 0 0-2.3-1.6"/><path d="M2 8.8a15 15 0 0 1 4.2-2.6"/><path d="M22 8.8A15 15 0 0 0 11 5"/><path d="M12 20h.01"/>',
		undo: '<path d="M9 14 4 9l5-5"/><path d="M4 9h10.5a5.5 5.5 0 0 1 0 11H11"/>',
		lock: '<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
		plus: '<path d="M12 5v14"/><path d="M5 12h14"/>',
		minus: '<path d="M5 12h14"/>',
		'chevron-up': '<path d="m18 15-6-6-6 6"/>',
		'chevron-down': '<path d="m6 9 6 6 6-6"/>',
		'chevron-right': '<path d="m9 18 6-6-6-6"/>'
	};

	function icon(name, cls) {
		return '<svg class="icon icon-' + name + (cls ? ' ' + cls : '') + '" viewBox="0 0 24 24" width="24" height="24" fill="none"'
			+ ' stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
			+ (ICONS[name] || '') + '</svg>';
	}

	// ------------------------------------------------------------------
	// Motion

	P55.motion = {
		get reduced() {
			return !!(reducedMq && reducedMq.matches);
		}
	};

	/**
	 * Runs update() inside a View Transition when one is available and
	 * wanted, else directly. Resolves once the DOM is updated. A transition
	 * the browser skips (a hidden tab, a newer transition) is not an error.
	 */
	function transition(update, animate) {
		if (animate && document.startViewTransition && !document.hidden) {
			var t = document.startViewTransition(update);
			var quiet = function () {};
			if (t.ready) {
				t.ready.catch(quiet);
			}
			if (t.finished) {
				t.finished.catch(quiet);
			}
			return t.updateCallbackDone;
		}
		update();
		return Promise.resolve();
	}

	// ------------------------------------------------------------------
	// Progress bar

	var progress = (function () {
		var timer = null;
		var showTimer = null;
		var value = 0;
		function el() {
			return document.getElementById('p55-progress');
		}
		function set(v) {
			var bar = el();
			if (bar) {
				bar.style.width = (v * 100).toFixed(1) + '%';
			}
		}
		return {
			start: function () {
				var bar = el();
				if (!bar) {
					return;
				}
				clearInterval(timer);
				clearTimeout(showTimer);
				value = 0.1;
				bar.style.transition = 'none';
				bar.style.width = '0';
				void bar.offsetWidth;
				bar.style.transition = '';
				// Only show for fetches slower than a blink.
				showTimer = setTimeout(function () {
					bar.classList.add('is-active');
					set(value);
				}, 120);
				timer = setInterval(function () {
					value += (0.9 - value) * 0.18;
					if (bar.classList.contains('is-active')) {
						set(value);
					}
				}, 350);
			},
			done: function () {
				var bar = el();
				clearInterval(timer);
				clearTimeout(showTimer);
				if (!bar || !bar.classList.contains('is-active')) {
					return;
				}
				set(1);
				setTimeout(function () {
					bar.classList.remove('is-active');
				}, 220);
			}
		};
	})();

	// ------------------------------------------------------------------
	// Assets

	function assetKey(href) {
		return toURL(href).pathname;
	}

	function registerExistingAssets() {
		$$('link[rel="stylesheet"][href]').forEach(function (l) {
			loaded[assetKey(l.href)] = l.href;
		});
		$$('script[src]').forEach(function (s) {
			loaded[assetKey(s.src)] = s.src;
		});
	}

	function loadAssets(styles, scripts) {
		var waits = [];
		(styles || []).forEach(function (href) {
			var abs = toURL(href).href;
			var key = assetKey(abs);
			if (loaded[key] === abs) {
				return;
			}
			loaded[key] = abs;
			var link = document.createElement('link');
			link.rel = 'stylesheet';
			link.href = abs;
			link.setAttribute('data-p55-asset', '');
			waits.push(new Promise(function (resolve) {
				link.onload = resolve;
				link.onerror = resolve;
				setTimeout(resolve, 2500);
			}));
			document.head.appendChild(link);
		});
		var chain = Promise.all(waits);
		(scripts || []).forEach(function (src) {
			var abs = toURL(src).href;
			var key = assetKey(abs);
			if (loaded[key] === abs) {
				return;
			}
			loaded[key] = abs;
			chain = chain.then(function () {
				return new Promise(function (resolve) {
					var s = document.createElement('script');
					s.src = abs;
					s.async = false;
					s.setAttribute('data-p55-asset', '');
					s.onload = resolve;
					s.onerror = resolve;
					document.body.appendChild(s);
				});
			});
		});
		return chain;
	}

	// ------------------------------------------------------------------
	// Page modules

	P55.page = function (name, initFn) {
		modules[name] = initFn;
		if (booted && active.name === name && !active.started) {
			startModule();
		}
	};

	function startModule() {
		if (!active.name || active.started || !modules[active.name]) {
			return;
		}
		active.started = true;
		try {
			var teardown = modules[active.name](active.el, active.props || {});
			active.teardown = typeof teardown === 'function' ? teardown : null;
		}
		catch (err) {
			if (window.console) {
				console.error('P55 module "' + active.name + '" failed', err);
			}
		}
	}

	function teardownModule() {
		if (active.teardown) {
			try {
				active.teardown();
			}
			catch (err) {
				if (window.console) {
					console.error(err);
				}
			}
		}
		active = { name: null, props: {}, el: null, teardown: null, started: false };
	}

	function initModule(name, props, el) {
		active = { name: name || null, props: props || {}, el: el, teardown: null, started: false };
		startModule();
	}

	// ------------------------------------------------------------------
	// Shell state: nav, badges, classic links, menus

	function badgeHtml(value) {
		if (value == null || value === '') {
			return '';
		}
		if (value === 'LIVE') {
			return '<span class="tab-badge badge-live" title="Games in progress">LIVE</span>';
		}
		if (value === 'done') {
			return '<span class="tab-badge badge-done" title="Picks are in">' + icon('check') + '</span>';
		}
		return '<span class="tab-badge badge-due" title="Picks due in ' + esc(value) + '">' + esc(value) + '</span>';
	}

	function setBadges(badges) {
		badges = badges || {};
		$$('.badge-slot[data-badge]').forEach(function (slot) {
			var key = slot.getAttribute('data-badge');
			var value = badges[key] == null ? '' : String(badges[key]);
			if (slot.getAttribute('data-value') === value && slot.hasAttribute('data-value')) {
				return;
			}
			slot.setAttribute('data-value', value);
			slot.innerHTML = badgeHtml(value);
		});
	}

	function setNav(key) {
		document.body.setAttribute('data-nav', key || 'none');
		$$('.topnav [data-nav], .tabbar [data-nav]').forEach(function (a) {
			if (a.getAttribute('data-nav') === key) {
				a.setAttribute('aria-current', 'page');
			}
			else {
				a.removeAttribute('aria-current');
			}
		});
		var avatar = $('.avatar-btn');
		if (avatar) {
			avatar.classList.toggle('is-current', key === 'account');
		}
	}

	function updateClassicLinks() {
		var rel = relPath(location.href);
		$$('[data-classic]').forEach(function (a) {
			a.setAttribute('href', CLASSIC_BASE + rel);
		});
	}

	function closeMenus(except) {
		$$('details[data-menu][open]').forEach(function (d) {
			if (d !== except) {
				d.removeAttribute('open');
			}
		});
	}

	// ------------------------------------------------------------------
	// Navigation

	function saveScroll() {
		try {
			var st = Object.assign({}, history.state || {}, { p55: true, scroll: window.scrollY, idx: histIdx });
			history.replaceState(st, '');
		}
		catch (e) {
			// state too large or history unavailable: ignore
		}
	}

	/**
	 * Fetches a URL as a fragment. Resolves with the parsed fragment (plus
	 * __url, the final URL after redirects); rejects when the reply is not
	 * the contract.
	 */
	function fetchFragment(href, init) {
		init = init || {};
		var headers = { 'X-P55-Partial': '1', 'X-Requested-With': 'fetch', 'Accept': 'application/json' };
		return fetch(href, {
			method: init.method || 'GET',
			body: init.body,
			headers: headers,
			credentials: 'same-origin',
			cache: 'no-store',
			redirect: 'follow',
			signal: init.signal
		}).then(function (res) {
			var finalUrl = toURL(res.url || href);
			if (!isInternal(finalUrl)) {
				throw new Error('Left r/');
			}
			var type = res.headers.get('Content-Type') || '';
			if (type.indexOf('application/json') === -1) {
				throw new Error('Not a fragment');
			}
			return res.json().then(function (data) {
				if (!data || typeof data !== 'object') {
					throw new Error('Not a fragment');
				}
				data.__status = res.status;
				data.__url = finalUrl.href;
				return data;
			});
		});
	}

	var CARRY_KEY = 'p55-carry-alerts';

	/** Keeps a fragment's flash alerts for the next page load (sessionStorage). */
	function carryAlerts(html) {
		try {
			// A <template> parses without loading the fragment's images.
			var box = document.createElement('template');
			box.innerHTML = String(html || '');
			var list = $$('.alert', box.content || box).map(function (al) {
				var kind = (al.className.match(/alert-(success|error|warning|info)/) || [])[1] || 'info';
				var text = al.querySelector('.alert-text');
				return { kind: kind, text: (text ? text.textContent : al.textContent).trim() };
			}).filter(function (a) {
				return a.text !== '';
			});
			if (list.length) {
				sessionStorage.setItem(CARRY_KEY, JSON.stringify(list));
			}
		}
		catch (e) {
			// storage blocked: the messages are lost, the page still loads
		}
	}

	/** Shows alerts carried over from the previous page as toasts. */
	function showCarriedAlerts() {
		var list = null;
		try {
			list = JSON.parse(sessionStorage.getItem(CARRY_KEY) || 'null');
			sessionStorage.removeItem(CARRY_KEY);
		}
		catch (e) {
			list = null;
		}
		if (Array.isArray(list)) {
			list.forEach(function (a) {
				P55.toast(String(a.text), a.kind);
			});
		}
	}

	function isFragment(data) {
		return data && typeof data.html === 'string';
	}

	function takePrefetch(href) {
		var entry = prefetched[href];
		delete prefetched[href];
		if (entry && Date.now() - entry.at < 10000) {
			return entry.promise;
		}
		return null;
	}

	function prefetch(href) {
		if (prefetched[href] && Date.now() - prefetched[href].at < 10000) {
			return;
		}
		var promise = fetchFragment(href);
		promise.catch(function () {
			delete prefetched[href];
		});
		prefetched[href] = { at: Date.now(), promise: promise };
	}

	/**
	 * P55.navigate(href, opts): opts.replace (bool), opts.history (false to
	 * leave history alone), opts.scroll ('top' | 'keep' | number),
	 * opts.dir ('forward' | 'back'), opts.focus (false to leave focus),
	 * opts.transition (false for an instant swap), opts.fresh (skip prefetch).
	 */
	function navigate(href, opts) {
		opts = opts || {};
		var url = toURL(href);
		if (!isInternal(url) || !isPage(url) || !window.fetch) {
			location.assign(url.href);
			return Promise.resolve();
		}
		var seq = ++navSeq;
		if (inflight) {
			inflight.abort();
		}
		var ctrl = window.AbortController ? new AbortController() : null;
		inflight = ctrl;
		progress.start();
		var pending = (!opts.fresh && takePrefetch(url.href)) || fetchFragment(url.href, { signal: ctrl ? ctrl.signal : undefined });
		return pending.then(function (data) {
			if (seq !== navSeq) {
				return;
			}
			if (!isFragment(data)) {
				throw new Error('Not a fragment');
			}
			return apply(data, url, opts);
		}).catch(function (err) {
			if (err && err.name === 'AbortError') {
				return;
			}
			if (seq !== navSeq) {
				return;
			}
			if (window.console) {
				console.warn('P55: falling back to a full load', err);
			}
			location.assign(url.href);
		}).then(function () {
			if (seq === navSeq) {
				progress.done();
				inflight = null;
			}
		});
	}

	/**
	 * Puts a fragment on the page: assets, then the swap (with history and
	 * scroll inside the swap so the new state is what the transition shows).
	 */
	function apply(data, requested, opts) {
		opts = opts || {};
		var authed = document.body.getAttribute('data-authed') === '1';
		var target = toURL(data.url || data.__url || requested.href);
		if (!isInternal(target)) {
			location.assign(target.href);
			return new Promise(function () {});
		}
		// Signing in or out changes the shell itself: load it whole. The
		// fragment already consumed the flash messages, so carry them over.
		if (!!data.authed !== authed) {
			carryAlerts(data.html);
			location.assign(target.href);
			return new Promise(function () {});
		}
		if (requested.hash && target.pathname === requested.pathname && target.search === requested.search) {
			target.hash = requested.hash;
		}
		var finalHref = target.href;
		var samePage = noHash(finalHref) === noHash(location.href);
		var keepY = window.scrollY;
		return loadAssets(data.styles, data.scripts).then(function () {
			if (opts.history !== false) {
				saveScroll();
			}
			return swap(data, opts, function () {
				if (opts.history === false && typeof opts.idx === 'number') {
					histIdx = opts.idx;
				}
				if (opts.history !== false) {
					if (opts.replace || samePage) {
						history.replaceState({ p55: true, scroll: 0, idx: histIdx }, '', finalHref);
					}
					else {
						histIdx++;
						history.pushState({ p55: true, scroll: 0, idx: histIdx }, '', finalHref);
					}
				}
				currentPage = noHash(location.href);
				if (typeof opts.scroll === 'number') {
					window.scrollTo(0, opts.scroll);
				}
				else if (opts.scroll === 'keep') {
					window.scrollTo(0, keepY);
				}
				else if (target.hash && document.getElementById(decodeURIComponent(target.hash.slice(1)))) {
					document.getElementById(decodeURIComponent(target.hash.slice(1))).scrollIntoView();
				}
				else {
					window.scrollTo(0, 0);
				}
			});
		}).then(function () {
			if (opts.focus !== false) {
				var main = document.getElementById('app-main');
				if (main && main.focus) {
					try {
						main.focus({ preventScroll: true });
					}
					catch (e) {
						main.focus();
					}
				}
			}
			dispatch('p55:navigate', { url: location.href, nav: data.nav, mode: data.mode });
		});
	}

	function swap(data, opts, afterDom) {
		var main = document.getElementById('app-main');
		root.setAttribute('data-nav-dir', opts.dir === 'back' ? 'back' : 'forward');
		function update() {
			teardownModule();
			if (data.title) {
				document.title = data.title;
			}
			main.innerHTML = data.html;
			setNav(data.nav || 'none');
			setBadges(data.badges);
			closeMenus();
			afterDom();
			updateClassicLinks();
			syncThemeButtons();
			var page = main.firstElementChild;
			enhance(page || main);
			var mod = data.module || null;
			initModule(mod ? mod.name : null, mod ? mod.props : {}, page || main);
		}
		var animate = opts.transition !== false && !P55.motion.reduced;
		if (animate && document.startViewTransition && !document.hidden) {
			return transition(update, true);
		}
		update();
		if (animate) {
			var page = main.firstElementChild;
			if (page) {
				page.classList.add('page-enter');
				page.addEventListener('animationend', function done() {
					page.classList.remove('page-enter');
					page.removeEventListener('animationend', done);
				});
			}
		}
		return Promise.resolve();
	}

	P55.navigate = navigate;

	/** Re-fetches the current page and swaps it in place, keeping scroll. */
	P55.reload = function (opts) {
		opts = opts || {};
		return navigate(location.href, {
			replace: true,
			scroll: 'keep',
			focus: false,
			fresh: true,
			transition: opts.transition
		});
	};

	// Link clicks
	document.addEventListener('click', function (e) {
		if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) {
			return;
		}
		var a = e.target && e.target.closest ? e.target.closest('a[href]') : null;
		if (!a) {
			return;
		}
		var targetAttr = a.getAttribute('target');
		if ((targetAttr && targetAttr !== '_self') || a.hasAttribute('download') || a.hasAttribute('data-native')) {
			return;
		}
		var url = toURL(a.getAttribute('href'));
		if (!isInternal(url) || !isPage(url)) {
			return;
		}
		// A jump within this page is the browser's job.
		if (url.hash && noHash(url.href) === noHash(location.href)) {
			return;
		}
		e.preventDefault();
		navigate(url.href, { scroll: 'top' });
	});

	// Prefetch the main tabs on hover or touch.
	function prefetchFromEvent(e) {
		var a = e.target && e.target.closest ? e.target.closest('a[data-prefetch][href]') : null;
		if (!a || !window.fetch) {
			return;
		}
		var url = toURL(a.getAttribute('href'));
		if (!isInternal(url) || noHash(url.href) === noHash(location.href)) {
			return;
		}
		if (e.type === 'touchstart') {
			prefetch(url.href);
			return;
		}
		clearTimeout(a.__p55pf);
		a.__p55pf = setTimeout(function () {
			prefetch(url.href);
		}, 80);
		a.addEventListener('mouseleave', function leave() {
			clearTimeout(a.__p55pf);
			a.removeEventListener('mouseleave', leave);
		});
	}
	document.addEventListener('mouseover', prefetchFromEvent);
	document.addEventListener('touchstart', prefetchFromEvent, { passive: true });

	// Back and forward
	window.addEventListener('popstate', function (e) {
		var st = e.state;
		if (noHash(location.href) === currentPage) {
			// Only the hash changed.
			return;
		}
		if (!st || !st.p55) {
			location.reload();
			return;
		}
		var idx = typeof st.idx === 'number' ? st.idx : histIdx;
		var dir = idx < histIdx ? 'back' : 'forward';
		var y = scrollMap[idx] != null ? scrollMap[idx] : (st.scroll || 0);
		navigate(location.href, { history: false, idx: idx, scroll: y, dir: dir, fresh: true });
	});

	window.addEventListener('pagehide', saveScroll);
	window.addEventListener('scroll', function () {
		scrollMap[histIdx] = window.scrollY;
	}, { passive: true });

	// ------------------------------------------------------------------
	// Async forms and inline validation

	function fieldOf(input) {
		return input.closest ? input.closest('.field') : null;
	}

	function validateInput(input) {
		if (!input.willValidate) {
			return true;
		}
		var field = fieldOf(input);
		var ok = input.checkValidity();
		if (field) {
			field.classList.toggle('is-invalid', !ok);
			var msg = field.querySelector('.field-error');
			if (msg) {
				var custom = null;
				if (!ok) {
					var v = input.validity;
					if (v.valueMissing) {
						custom = input.getAttribute('data-msg-required');
					}
					else if (v.typeMismatch) {
						custom = input.getAttribute('data-msg-type');
					}
					else if (v.tooShort || v.patternMismatch) {
						custom = input.getAttribute('data-msg-length');
					}
				}
				msg.textContent = ok ? '' : (custom || input.validationMessage);
			}
			input.setAttribute('aria-invalid', ok ? 'false' : 'true');
		}
		return ok;
	}

	function validateForm(form) {
		var first = null;
		$$('input, select, textarea', form).forEach(function (input) {
			if (!validateInput(input) && !first) {
				first = input;
			}
		});
		if (first) {
			first.focus();
		}
		return !first;
	}

	document.addEventListener('blur', function (e) {
		var input = e.target;
		if (input && input.form && input.form.hasAttribute('data-validate') && input.value !== '') {
			validateInput(input);
		}
	}, true);

	document.addEventListener('input', function (e) {
		var input = e.target;
		var field = input && fieldOf(input);
		if (field && field.classList.contains('is-invalid')) {
			validateInput(input);
		}
	});

	function setBusy(form, submitter, busy) {
		form.setAttribute('aria-busy', busy ? 'true' : 'false');
		var btn = submitter || form.querySelector('[type="submit"]');
		if (btn) {
			if (busy) {
				btn.setAttribute('aria-busy', 'true');
			}
			else {
				btn.removeAttribute('aria-busy');
			}
		}
	}

	function submitAsync(form, submitter) {
		var method = (form.getAttribute('method') || 'GET').toUpperCase();
		var url = toURL(form.getAttribute('action') || location.href);
		var body = new FormData(form);
		if (submitter && submitter.name) {
			body.append(submitter.name, submitter.value);
		}
		if (method === 'GET') {
			url.search = new URLSearchParams(body).toString();
			navigate(url.href);
			return;
		}
		setBusy(form, submitter, true);
		progress.start();
		fetchFragment(url.href, { method: 'POST', body: body }).then(function (data) {
			if (data.__status === 401) {
				toLogin();
				return;
			}
			if (data.redirect) {
				return navigate(data.redirect);
			}
			if (isFragment(data)) {
				var same = noHash(data.url ? toURL(data.url).href : data.__url) === noHash(location.href);
				return apply(data, toURL(data.__url), { replace: same, scroll: same ? 'keep' : 'top', focus: !same });
			}
			if (data.error) {
				P55.toast(data.error, 'error');
				return;
			}
			throw new Error('Not a fragment');
		}).catch(function (err) {
			if (err && /Not a fragment|Left r\//.test(err.message)) {
				// The server answered with something else: let the browser post it.
				HTMLFormElement.prototype.submit.call(form);
				return;
			}
			P55.toast('Could not reach Pick55. Check your connection and try again.', 'error');
		}).then(function () {
			setBusy(form, submitter, false);
			progress.done();
		});
	}

	document.addEventListener('submit', function (e) {
		var form = e.target;
		if (!form || !form.matches) {
			return;
		}
		if (form.hasAttribute('data-validate') && !validateForm(form)) {
			e.preventDefault();
			return;
		}
		if (!form.matches('form[data-async]') || !window.fetch || !window.FormData) {
			return;
		}
		if (form.getAttribute('aria-busy') === 'true') {
			e.preventDefault();
			return;
		}
		e.preventDefault();
		submitAsync(form, e.submitter || null);
	});

	// ------------------------------------------------------------------
	// JSON

	function toLogin() {
		var rel = relPath(location.href);
		var q = rel && rel !== 'index.php' && rel.indexOf('auth/') !== 0 ? '?r=' + encodeURIComponent(rel) : '';
		location.assign(BASE + 'auth/login.php' + q);
	}

	/**
	 * P55.fetchJSON(url, {method, body, headers, signal}): a plain object body
	 * is sent as JSON. Resolves with the parsed reply; rejects with an Error
	 * carrying .status and .data for a 4xx/5xx; a 401 goes to sign-in.
	 */
	P55.fetchJSON = function (url, opts) {
		opts = opts || {};
		var headers = Object.assign({ 'X-Requested-With': 'fetch', 'Accept': 'application/json' }, opts.headers || {});
		var body = opts.body;
		var plain = body && typeof body === 'object'
			&& !(window.FormData && body instanceof FormData)
			&& !(window.URLSearchParams && body instanceof URLSearchParams)
			&& !(window.Blob && body instanceof Blob);
		if (plain) {
			body = JSON.stringify(body);
			headers['Content-Type'] = 'application/json';
		}
		return fetch(url, {
			method: opts.method || (body ? 'POST' : 'GET'),
			headers: headers,
			body: body,
			credentials: 'same-origin',
			cache: 'no-store',
			signal: opts.signal,
			keepalive: !!opts.keepalive
		}).then(function (res) {
			if (res.status === 401) {
				toLogin();
				var e401 = new Error('Signed out');
				e401.status = 401;
				throw e401;
			}
			var type = res.headers.get('Content-Type') || '';
			var parse = type.indexOf('json') !== -1
				? res.json()
				: res.text().then(function () {
					return { error: 'Unexpected response (' + res.status + ')' };
				});
			return parse.then(function (data) {
				if (!res.ok || (data && data.error && !res.ok)) {
					var err = new Error((data && data.error) || ('HTTP ' + res.status));
					err.status = res.status;
					err.data = data;
					throw err;
				}
				return data;
			});
		});
	};

	// ------------------------------------------------------------------
	// Polling

	/**
	 * P55.poll(fn, ms): runs fn every ms while the tab is visible (paused
	 * when hidden, fired at once when it shows again or comes back online).
	 * If fn returns (or resolves to) a number, that becomes the interval.
	 * Returns a stopper function with .now() and .every(ms).
	 */
	P55.poll = function (fn, ms) {
		var delay = ms;
		var timer = null;
		var stopped = false;
		var running = false;
		function schedule() {
			clearTimeout(timer);
			if (stopped || document.hidden) {
				return;
			}
			timer = setTimeout(run, delay);
		}
		function run() {
			if (stopped || running) {
				return;
			}
			running = true;
			clearTimeout(timer);
			Promise.resolve().then(fn).then(function (next) {
				if (typeof next === 'number' && next > 0) {
					delay = next;
				}
			}, function (err) {
				if (window.console) {
					console.warn('P55.poll', err);
				}
			}).then(function () {
				running = false;
				schedule();
			});
		}
		function onVisible() {
			if (stopped) {
				return;
			}
			if (document.hidden) {
				clearTimeout(timer);
			}
			else {
				run();
			}
		}
		document.addEventListener('visibilitychange', onVisible);
		window.addEventListener('online', onVisible);
		schedule();
		var stop = function () {
			stopped = true;
			clearTimeout(timer);
			document.removeEventListener('visibilitychange', onVisible);
			window.removeEventListener('online', onVisible);
		};
		stop.now = run;
		stop.every = function (next) {
			delay = next;
			schedule();
		};
		return stop;
	};

	// ------------------------------------------------------------------
	// Toasts and confirm

	var TOAST_KINDS = { good: 'success', ok: 'success', bad: 'error', danger: 'error', warn: 'warning' };
	var TOAST_ICONS = { success: 'check', error: 'alert-triangle', warning: 'alert-triangle', info: 'info', offline: 'wifi-off' };

	/**
	 * P55.toast(text, kind, opts): kind success | error | warning | info |
	 * offline; opts.timeout (ms, 0 = stays), opts.action {label, fn}.
	 * Returns {close, el}.
	 */
	P55.toast = function (text, kind, opts) {
		opts = opts || {};
		kind = TOAST_KINDS[kind] || kind || 'info';
		var box = document.getElementById('p55-toasts');
		if (!box) {
			return { close: function () {}, el: null };
		}
		var el = document.createElement('div');
		el.className = 'toast toast-' + kind;
		el.setAttribute('role', kind === 'error' ? 'alert' : 'status');
		el.innerHTML = icon(TOAST_ICONS[kind] || 'info') + '<div class="toast-text"></div>';
		el.querySelector('.toast-text').textContent = text;
		var timer = null;
		function close() {
			clearTimeout(timer);
			if (el.classList.contains('is-leaving')) {
				return;
			}
			el.classList.add('is-leaving');
			setTimeout(function () {
				if (el.parentNode) {
					el.parentNode.removeChild(el);
				}
			}, P55.motion.reduced ? 0 : 220);
		}
		if (opts.action && opts.action.label) {
			var btn = document.createElement('button');
			btn.type = 'button';
			btn.className = 'toast-action';
			btn.textContent = opts.action.label;
			btn.addEventListener('click', function () {
				close();
				if (typeof opts.action.fn === 'function') {
					opts.action.fn();
				}
			});
			el.appendChild(btn);
		}
		var x = document.createElement('button');
		x.type = 'button';
		x.className = 'toast-close';
		x.setAttribute('aria-label', 'Dismiss');
		x.innerHTML = icon('x');
		x.addEventListener('click', close);
		el.appendChild(x);
		box.appendChild(el);
		var all = box.querySelectorAll('.toast:not(.is-leaving)');
		if (all.length > 3) {
			all[0].classList.add('is-leaving');
			setTimeout(function () {
				if (all[0].parentNode) {
					all[0].parentNode.removeChild(all[0]);
				}
			}, 220);
		}
		var timeout = opts.timeout != null ? opts.timeout : (kind === 'error' ? 7000 : 4000);
		if (timeout > 0) {
			timer = setTimeout(close, timeout);
			el.addEventListener('mouseenter', function () {
				clearTimeout(timer);
			});
			el.addEventListener('mouseleave', function () {
				timer = setTimeout(close, 1800);
			});
		}
		return { close: close, el: el };
	};

	/**
	 * P55.confirm(text, opts): a modal dialog; resolves true on OK.
	 * opts.title, opts.ok ('OK'), opts.cancel ('Cancel'), opts.danger (bool).
	 */
	P55.confirm = function (text, opts) {
		opts = opts || {};
		if (typeof window.HTMLDialogElement !== 'function') {
			return Promise.resolve(window.confirm(text));
		}
		return new Promise(function (resolve) {
			var d = document.createElement('dialog');
			d.className = 'dialog';
			d.innerHTML = '<form method="dialog">'
				+ '<div class="dialog-body"></div>'
				+ '<div class="dialog-actions">'
				+ '<button class="btn btn-ghost" value="cancel"></button>'
				+ '<button class="btn ' + (opts.danger ? 'btn-danger' : 'btn-primary') + '" value="ok"></button>'
				+ '</div></form>';
			var body = d.querySelector('.dialog-body');
			if (opts.title) {
				var h = document.createElement('div');
				h.className = 'card-title';
				h.style.marginBottom = '6px';
				h.textContent = opts.title;
				body.appendChild(h);
			}
			var p = document.createElement('div');
			p.textContent = text;
			if (opts.title) {
				p.className = 'muted';
			}
			body.appendChild(p);
			var buttons = d.querySelectorAll('button');
			buttons[0].textContent = opts.cancel || 'Cancel';
			buttons[1].textContent = opts.ok || 'OK';
			d.addEventListener('click', function (e) {
				if (e.target === d) {
					d.close('cancel');
				}
			});
			d.addEventListener('close', function () {
				resolve(d.returnValue === 'ok');
				if (d.parentNode) {
					d.parentNode.removeChild(d);
				}
			});
			document.body.appendChild(d);
			d.showModal();
			buttons[1].focus();
		});
	};

	// ------------------------------------------------------------------
	// Theme

	// The choice for this page when storage is blocked.
	var memTheme = null;

	function storedTheme() {
		if (memTheme) {
			return memTheme;
		}
		try {
			var t = localStorage.getItem(THEME_KEY);
			return t === 'light' || t === 'dark' ? t : 'system';
		}
		catch (e) {
			return 'system';
		}
	}

	function resolvedTheme() {
		var t = storedTheme();
		if (t !== 'system') {
			return t;
		}
		return darkMq && darkMq.matches ? 'dark' : 'light';
	}

	function applyTheme() {
		var t = storedTheme();
		if (t === 'system') {
			root.removeAttribute('data-theme');
		}
		else {
			root.setAttribute('data-theme', t);
		}
		syncThemeButtons();
		var meta = $('meta[name="theme-color"]');
		if (meta) {
			meta.setAttribute('content', resolvedTheme() === 'dark' ? '#090d0c' : '#f3f5f4');
		}
	}

	/** Every [data-theme-set] control shows the stored choice as pressed. */
	function syncThemeButtons() {
		var t = storedTheme();
		$$('[data-theme-set]').forEach(function (b) {
			b.setAttribute('aria-pressed', b.getAttribute('data-theme-set') === t ? 'true' : 'false');
		});
	}

	function themeChanged() {
		dispatch('p55:theme', { theme: storedTheme(), resolved: resolvedTheme() });
	}

	P55.theme = {
		get: storedTheme,
		resolved: resolvedTheme,
		set: function (t) {
			try {
				if (t === 'light' || t === 'dark') {
					localStorage.setItem(THEME_KEY, t);
				}
				else {
					localStorage.removeItem(THEME_KEY);
				}
			}
			catch (e) {
				// storage blocked: the choice lasts for this page only
				memTheme = t === 'light' || t === 'dark' ? t : 'system';
			}
			// p55:theme fires once the new theme's tokens are in effect, so
			// listeners (charts) can read them at once.
			transition(applyTheme, !P55.motion.reduced).then(themeChanged, function () {
				applyTheme();
				themeChanged();
			});
		}
	};

	if (darkMq) {
		var onScheme = function () {
			applyTheme();
			themeChanged();
		};
		if (darkMq.addEventListener) {
			darkMq.addEventListener('change', onScheme);
		}
		else if (darkMq.addListener) {
			darkMq.addListener(onScheme);
		}
	}

	document.addEventListener('click', function (e) {
		var set = e.target.closest ? e.target.closest('[data-theme-set]') : null;
		if (set) {
			e.preventDefault();
			P55.theme.set(set.getAttribute('data-theme-set'));
			return;
		}
		var cycle = e.target.closest ? e.target.closest('[data-theme-cycle]') : null;
		if (cycle) {
			e.preventDefault();
			P55.theme.set(resolvedTheme() === 'dark' ? 'light' : 'dark');
		}
	});

	// ------------------------------------------------------------------
	// Menus

	document.addEventListener('click', function (e) {
		var inMenu = e.target.closest ? e.target.closest('details[data-menu]') : null;
		closeMenus(inMenu);
		if (inMenu && e.target.closest('a[href]')) {
			inMenu.removeAttribute('open');
		}
	});

	document.addEventListener('keydown', function (e) {
		if (e.key === 'Escape') {
			var open = $('details[data-menu][open]');
			if (open) {
				open.removeAttribute('open');
				var s = open.querySelector('summary');
				if (s) {
					s.focus();
				}
			}
		}
	});

	// ------------------------------------------------------------------
	// Numbers and time

	P55.fmt = {
		num: function (n) {
			return Math.round(Number(n) || 0).toLocaleString('en-US');
		},
		money: function (n) {
			var v = Math.round(Number(n) || 0);
			return (v < 0 ? '-$' : '$') + Math.abs(v).toLocaleString('en-US');
		},
		ordinal: function (n) {
			n = Math.round(Number(n) || 0);
			var s = ['th', 'st', 'nd', 'rd'];
			var v = n % 100;
			return n + (s[(v - 20) % 10] || s[v] || s[0]);
		},
		signed: function (n) {
			n = Math.round(Number(n) || 0);
			return n > 0 ? '+' + n : (n < 0 ? '−' + Math.abs(n) : '0');
		}
	};

	/**
	 * P55.countUp(el, to, opts): animates el's number to `to`.
	 * opts.from, opts.duration (ms), opts.format(n) -> string.
	 */
	P55.countUp = function (el, to, opts) {
		if (!el) {
			return;
		}
		opts = opts || {};
		to = Number(to) || 0;
		var format = opts.format || P55.fmt.num;
		var from = opts.from != null ? Number(opts.from) : parseFloat(String(el.textContent).replace(/[^0-9.\-]/g, ''));
		if (isNaN(from)) {
			from = 0;
		}
		if (el.__p55count) {
			cancelAnimationFrame(el.__p55count);
		}
		if (P55.motion.reduced || from === to || !window.requestAnimationFrame) {
			el.textContent = format(to);
			return;
		}
		var duration = opts.duration || 700;
		var start = null;
		function step(ts) {
			if (start === null) {
				start = ts;
			}
			var p = Math.min(1, (ts - start) / duration);
			var eased = 1 - Math.pow(1 - p, 3);
			el.textContent = format(from + (to - from) * eased);
			if (p < 1) {
				el.__p55count = requestAnimationFrame(step);
			}
			else {
				el.__p55count = null;
				el.textContent = format(to);
			}
		}
		el.__p55count = requestAnimationFrame(step);
	};

	/** P55.flash(el): a brief highlight that says "this just changed". */
	P55.flash = function (el) {
		if (!el || P55.motion.reduced) {
			return;
		}
		el.classList.remove('flash');
		void el.offsetWidth;
		el.classList.add('flash');
		el.addEventListener('animationend', function done() {
			el.classList.remove('flash');
			el.removeEventListener('animationend', done);
		});
	};

	/** P55.ring(ringEl, index, fraction): repaints one ring of a .progress-ring. */
	P55.ring = function (ringEl, index, fraction) {
		if (!ringEl) {
			return;
		}
		var bar = ringEl.querySelector('.ring-bar[data-ring="' + (index || 0) + '"]');
		if (!bar) {
			return;
		}
		var p = Math.max(0, Math.min(100, Math.round((Number(fraction) || 0) * 1000) / 10));
		bar.style.setProperty('--p', String(p));
		bar.classList.toggle('is-zero', p <= 0);
	};

	function toDate(value) {
		if (value instanceof Date) {
			return value;
		}
		if (value == null || value === '') {
			return null;
		}
		var d = new Date(typeof value === 'number' ? value : String(value));
		return isNaN(d.getTime()) ? null : d;
	}

	/** P55.relTime(date): "just now", "12m ago", "in 3h", "2d ago", "Oct 4". */
	P55.relTime = function (date, now) {
		var d = toDate(date);
		if (!d) {
			return '';
		}
		var diff = Math.round((d.getTime() - (now || Date.now())) / 1000);
		var abs = Math.abs(diff);
		var s;
		if (abs < 45) {
			return diff <= 0 ? 'just now' : 'in a moment';
		}
		if (abs < 3600) {
			s = Math.max(1, Math.round(abs / 60)) + 'm';
		}
		else if (abs < 86400) {
			s = Math.round(abs / 3600) + 'h';
		}
		else if (abs < 7 * 86400) {
			s = Math.round(abs / 86400) + 'd';
		}
		else {
			return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
		}
		return diff < 0 ? s + ' ago' : 'in ' + s;
	};

	/** P55.countdown(date): "2d 4h", "4h 12m", "12m", "0m" once past (as Fmt::countdown). */
	P55.countdown = function (date, now) {
		var d = toDate(date);
		if (!d) {
			return '';
		}
		var left = Math.floor((d.getTime() - (now || Date.now())) / 1000);
		if (left <= 0) {
			return '0m';
		}
		var days = Math.floor(left / 86400);
		var hours = Math.floor((left % 86400) / 3600);
		var minutes = Math.floor((left % 3600) / 60);
		if (days > 0) {
			return days + 'd ' + hours + 'h';
		}
		if (hours > 0) {
			return hours + 'h ' + minutes + 'm';
		}
		return Math.max(1, minutes) + 'm';
	};

	/**
	 * Keeps [data-countdown="<iso>"] and [data-reltime="<iso>"] text current.
	 * A countdown that reaches zero gets .is-past and fires p55:deadline.
	 */
	function tick() {
		var now = Date.now();
		$$('[data-countdown]').forEach(function (el) {
			var d = toDate(el.getAttribute('data-countdown'));
			if (!d) {
				return;
			}
			var text = P55.countdown(d, now);
			if (el.textContent !== text) {
				el.textContent = text;
			}
			if (d.getTime() <= now && !el.classList.contains('is-past')) {
				el.classList.add('is-past');
				dispatch('p55:deadline', { at: d }, el);
			}
		});
		$$('[data-reltime]').forEach(function (el) {
			var text = P55.relTime(el.getAttribute('data-reltime'), now);
			if (el.textContent !== text) {
				el.textContent = text;
			}
		});
	}

	// ------------------------------------------------------------------
	// Enhancement of freshly rendered content

	function enhance(scope) {
		scope = scope || document;
		$$('.alert[data-toast]', scope).forEach(function (al) {
			var text = al.querySelector('.alert-text');
			P55.toast(text ? text.textContent : al.textContent, al.getAttribute('data-toast'));
			if (al.parentNode) {
				al.parentNode.removeChild(al);
			}
		});
		$$('.alerts', scope).forEach(function (box) {
			if (!box.children.length && box.parentNode) {
				box.parentNode.removeChild(box);
			}
		});
		$$('form[data-validate]', scope).forEach(function (form) {
			form.setAttribute('novalidate', '');
		});
		tick();
	}

	P55.enhance = enhance;
	P55.setBadges = setBadges;
	P55.esc = esc;
	P55.icon = icon;
	P55.$ = $;
	P55.$$ = $$;
	P55.base = BASE;
	P55.link = function (rel) {
		return BASE + String(rel || '').replace(/^\//, '');
	};

	// ------------------------------------------------------------------
	// Charts: the classic site's vendored Chart.js 3.5.1, loaded once on
	// demand, and the design tokens charts are painted with.

	/** 'rgba()' of a colour ('#rgb', '#rrggbb', 'rgb()', 'rgba()') with alpha a. */
	function withAlpha(color, a) {
		var v = String(color || '').trim();
		var r;
		var g;
		var bl;
		var m;
		if (/^#[0-9a-f]{3}$/i.test(v)) {
			r = parseInt(v[1] + v[1], 16);
			g = parseInt(v[2] + v[2], 16);
			bl = parseInt(v[3] + v[3], 16);
		}
		else if (/^#[0-9a-f]{6}/i.test(v)) {
			r = parseInt(v.slice(1, 3), 16);
			g = parseInt(v.slice(3, 5), 16);
			bl = parseInt(v.slice(5, 7), 16);
		}
		else if ((m = v.match(/rgba?\(([^)]+)\)/i))) {
			var parts = m[1].split(/[\s,/]+/).filter(Boolean);
			r = parseFloat(parts[0]);
			g = parseFloat(parts[1]);
			bl = parseFloat(parts[2]);
			if (a == null && parts[3] != null) {
				a = parseFloat(parts[3]);
			}
		}
		else {
			return v || '#888';
		}
		return 'rgba(' + r + ',' + g + ',' + bl + ',' + (a == null ? 1 : a) + ')';
	}

	var CHART_TOKENS = {
		fg: '--fg', muted: '--fg-muted', faint: '--fg-faint', line: '--line', bg: '--bg-elev',
		brand: '--brand', accent: '--accent', good: '--good', bad: '--bad', warn: '--warn', live: '--live',
		gold: '--gold', silver: '--silver', bronze: '--bronze',
		nfl: '--nfl', ncaa: '--ncaa', ou: '--ou', spread: '--spread'
	};

	/**
	 * P55.chartTheme(): the current theme's colours for charts, read from the
	 * CSS tokens ({fg, muted, faint, line, bg, brand, accent, good, bad, warn,
	 * live, gold, silver, bronze, nfl, ncaa, ou, spread}), plus font (the UI
	 * family), animation (false under reduced motion) and alpha(color, a).
	 * Read it at draw time and again on p55:theme.
	 */
	P55.chartTheme = function () {
		var cs = getComputedStyle(root);
		var t = {};
		Object.keys(CHART_TOKENS).forEach(function (k) {
			t[k] = cs.getPropertyValue(CHART_TOKENS[k]).trim() || '#888';
		});
		t.font = getComputedStyle(document.body).fontFamily;
		t.animation = P55.motion.reduced ? false : { duration: 500 };
		t.alpha = withAlpha;
		return t;
	};

	/** The shared look of every chart: font, text, grid and tooltip from the tokens. */
	function chartDefaults(Chart) {
		var t = P55.chartTheme();
		var d = Chart.defaults;
		d.font.family = t.font;
		d.color = t.muted;
		d.borderColor = t.line;
		var tip = d.plugins && d.plugins.tooltip;
		if (tip) {
			tip.backgroundColor = t.fg;
			tip.titleColor = t.bg;
			tip.bodyColor = t.bg;
			tip.footerColor = t.bg;
			tip.cornerRadius = 8;
			tip.padding = 10;
		}
		return Chart;
	}

	var chartLoad = null;

	/**
	 * P55.chart(): resolves with window.Chart, loading the vendored
	 * static/js/chart.js once, its defaults set from the current theme.
	 * Call it for every (re)draw.
	 */
	P55.chart = function () {
		if (window.Chart) {
			return Promise.resolve(chartDefaults(window.Chart));
		}
		if (!chartLoad) {
			chartLoad = new Promise(function (resolve, reject) {
				var s = document.createElement('script');
				function fail() {
					chartLoad = null;
					if (s.parentNode) {
						s.parentNode.removeChild(s);
					}
					reject(new Error('Chart.js did not load'));
				}
				s.src = CLASSIC_BASE + 'static/js/chart.js';
				s.async = true;
				s.onload = function () {
					if (window.Chart) {
						resolve(window.Chart);
					}
					else {
						fail();
					}
				};
				s.onerror = fail;
				document.head.appendChild(s);
			});
		}
		return chartLoad.then(chartDefaults);
	};

	// ------------------------------------------------------------------
	// Boot

	function boot() {
		if (booted) {
			return;
		}
		booted = true;
		registerExistingAssets();
		applyTheme();
		if ('scrollRestoration' in history) {
			history.scrollRestoration = 'manual';
		}
		var st = history.state;
		if (st && st.p55 && typeof st.idx === 'number') {
			histIdx = st.idx;
		}
		try {
			history.replaceState(Object.assign({}, st || {}, { p55: true, idx: histIdx, scroll: st && st.scroll ? st.scroll : 0 }), '');
		}
		catch (e) {
			// ignore
		}
		currentPage = noHash(location.href);
		if (st && st.p55 && st.scroll) {
			window.scrollTo(0, st.scroll);
		}
		var badgeSlots = $$('.badge-slot[data-badge]');
		badgeSlots.forEach(function (slot) {
			var b = slot.querySelector('.tab-badge');
			var value = '';
			if (b) {
				value = b.classList.contains('badge-live') ? 'LIVE' : (b.classList.contains('badge-done') ? 'done' : b.textContent);
			}
			slot.setAttribute('data-value', value);
		});
		var main = document.getElementById('app-main');
		var page = main ? main.firstElementChild : null;
		enhance(page || document);
		showCarriedAlerts();
		if (page && page.hasAttribute('data-module')) {
			var props = {};
			try {
				props = JSON.parse(page.getAttribute('data-props') || '{}');
			}
			catch (e) {
				props = {};
			}
			initModule(page.getAttribute('data-module'), props, page);
		}
		setInterval(tick, 30000);
		window.addEventListener('offline', function () {
			P55.toast('You are offline. Changes will wait until you reconnect.', 'offline', { timeout: 6000 });
		});
		document.addEventListener('visibilitychange', function () {
			if (!document.hidden) {
				tick();
			}
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	}
	else {
		boot();
	}
})(window, document);
