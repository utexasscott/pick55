/*
 * Rules (r/rules.php): the section index highlights the section being
 * read (IntersectionObserver), scrolls to a section on click (smoothly
 * unless reduced motion), and on phones keeps the active chip in view.
 */
P55.page('rules', function (root) {
	'use strict';

	var toc = root.querySelector('[data-toc]');
	if (!toc) {
		return null;
	}
	var links = Array.prototype.slice.call(toc.querySelectorAll('[data-toc-link]'));
	var sections = links.map(function (a) {
		return root.querySelector('#' + a.getAttribute('data-toc-link'));
	}).filter(Boolean);
	var visible = {};
	var current = null;
	var lockUntil = 0;
	var io = null;

	function setActive(id) {
		if (id === current) {
			return;
		}
		current = id;
		links.forEach(function (a) {
			if (a.getAttribute('data-toc-link') === id) {
				a.setAttribute('aria-current', 'location');
				// Phones: the chip row scrolls sideways; keep the chip in view without moving the page.
				if (toc.scrollWidth > toc.clientWidth) {
					var left = a.offsetLeft - (toc.clientWidth - a.offsetWidth) / 2;
					toc.scrollTo({ left: Math.max(0, left), behavior: P55.motion.reduced ? 'auto' : 'smooth' });
				}
			}
			else {
				a.removeAttribute('aria-current');
			}
		});
	}

	function pick() {
		if (Date.now() < lockUntil) {
			return;
		}
		// At the very bottom the last section is current even if it is short.
		if (window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - 4) {
			setActive(sections[sections.length - 1].id);
			return;
		}
		for (var i = 0; i < sections.length; i++) {
			if (visible[sections[i].id]) {
				setActive(sections[i].id);
				return;
			}
		}
	}

	if ('IntersectionObserver' in window && sections.length) {
		io = new IntersectionObserver(function (entries) {
			entries.forEach(function (e) {
				visible[e.target.id] = e.isIntersecting;
			});
			pick();
		}, { rootMargin: '-20% 0px -55% 0px', threshold: 0 });
		sections.forEach(function (s) {
			io.observe(s);
		});
	}

	function onScroll() {
		if (window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - 4) {
			pick();
		}
	}
	window.addEventListener('scroll', onScroll, { passive: true });

	function onClick(e) {
		var a = e.target.closest ? e.target.closest('[data-toc-link]') : null;
		if (!a || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) {
			return;
		}
		var target = root.querySelector('#' + a.getAttribute('data-toc-link'));
		if (!target) {
			return;
		}
		e.preventDefault();
		setActive(target.id);
		lockUntil = Date.now() + (P55.motion.reduced ? 100 : 900);
		target.scrollIntoView({ behavior: P55.motion.reduced ? 'auto' : 'smooth', block: 'start' });
		try {
			history.replaceState(history.state, '', '#' + target.id);
		}
		catch (err) {
			// ignore
		}
		var h = target.querySelector('h2');
		if (h) {
			h.setAttribute('tabindex', '-1');
			h.focus({ preventScroll: true });
		}
	}
	toc.addEventListener('click', onClick);

	// Arriving with a hash (a shared link): mark it.
	if (location.hash) {
		var start = root.querySelector(location.hash.replace(/[^#\w-]/g, ''));
		if (start && sections.indexOf(start) > -1) {
			setActive(start.id);
		}
	}
	if (!current && sections.length) {
		setActive(sections[0].id);
	}

	return function () {
		if (io) {
			io.disconnect();
		}
		window.removeEventListener('scroll', onScroll);
		toc.removeEventListener('click', onClick);
	};
});
