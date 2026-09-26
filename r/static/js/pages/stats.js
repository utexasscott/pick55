/*
 * All-time stats (r/stats/*.php): ranked tables (sort by any column, the
 * rank column renumbered over the visible rows with medals for the top
 * three, a row limit with "Show all"), the best/worst toggle, the
 * minimum-weeks filter, and the Record Book's score histogram (Chart.js,
 * coloured from the theme tokens and redrawn when the theme changes).
 */
(function () {
	'use strict';

	var chartPromise = null;

	/** Chart.js, loaded once (it may be missing after a partial navigation). */
	function loadChart(src) {
		if (window.Chart) {
			return Promise.resolve(window.Chart);
		}
		if (!chartPromise) {
			chartPromise = new Promise(function (resolve, reject) {
				var s = document.createElement('script');
				s.src = src;
				s.async = true;
				s.onload = function () {
					if (window.Chart) {
						resolve(window.Chart);
					}
					else {
						reject(new Error('Chart.js did not load'));
					}
				};
				s.onerror = function () {
					chartPromise = null;
					reject(new Error('Chart.js did not load'));
				};
				document.head.appendChild(s);
			});
		}
		return chartPromise;
	}

	/** A token's colour with an alpha, from '#rrggbb', '#rgb' or 'rgb(a)(...)'. */
	function tokenColor(name, alpha) {
		var v = getComputedStyle(document.documentElement).getPropertyValue(name).trim();
		var r;
		var g;
		var b;
		var m;
		if (/^#([0-9a-f]{3})$/i.test(v)) {
			r = parseInt(v[1] + v[1], 16);
			g = parseInt(v[2] + v[2], 16);
			b = parseInt(v[3] + v[3], 16);
		}
		else if (/^#([0-9a-f]{6})/i.test(v)) {
			r = parseInt(v.slice(1, 3), 16);
			g = parseInt(v.slice(3, 5), 16);
			b = parseInt(v.slice(5, 7), 16);
		}
		else if ((m = v.match(/rgba?\(([^)]+)\)/i))) {
			var parts = m[1].split(/[\s,/]+/).filter(Boolean);
			r = parseFloat(parts[0]);
			g = parseFloat(parts[1]);
			b = parseFloat(parts[2]);
			if (alpha == null && parts[3] != null) {
				alpha = parseFloat(parts[3]);
			}
		}
		else {
			return v || '#888';
		}
		return 'rgba(' + r + ',' + g + ',' + b + ',' + (alpha == null ? 1 : alpha) + ')';
	}

	// ------------------------------------------------------------------
	// Ranked tables

	function RankedTable(el, root) {
		this.el = el;
		this.root = root;
		this.tbody = el.tBodies[0];
		this.limit = parseInt(el.getAttribute('data-limit'), 10) || 0;
		this.min = 0;
		this.more = root.querySelector('[data-show-all="' + el.id + '"]');
		this.ths = Array.prototype.slice.call(el.querySelectorAll('th[data-sort]'));
		this.primary = el.querySelector('th[data-sort-primary]');
		this.current = el.querySelector('th[aria-sort]') || null;
		this.dir = this.current ? (this.current.getAttribute('aria-sort') === 'ascending' ? 'asc' : 'desc') : null;
		var self = this;
		this.ths.forEach(function (th) {
			th.setAttribute('tabindex', '0');
			th.setAttribute('role', 'columnheader');
			if (!th.hasAttribute('aria-sort')) {
				th.setAttribute('aria-sort', 'none');
			}
		});
		this.onClick = function (e) {
			var th = e.target.closest ? e.target.closest('th[data-sort]') : null;
			if (th && self.el.contains(th)) {
				self.toggle(th);
			}
		};
		this.onKey = function (e) {
			if (e.key !== 'Enter' && e.key !== ' ') {
				return;
			}
			var th = e.target.closest ? e.target.closest('th[data-sort]') : null;
			if (th && self.el.contains(th)) {
				e.preventDefault();
				self.toggle(th);
			}
		};
		el.addEventListener('click', this.onClick);
		el.addEventListener('keydown', this.onKey);
		this.renumber();
	}

	RankedTable.prototype.col = function (th) {
		var c = th.getAttribute('data-col');
		return c !== null ? parseInt(c, 10) : th.cellIndex;
	};

	RankedTable.prototype.value = function (tr, col, type) {
		var td = tr.cells[col];
		if (!td) {
			return type === 'string' ? '' : 0;
		}
		var raw = td.getAttribute('data-sort-value');
		if (raw === null) {
			raw = td.textContent.trim();
		}
		if (type === 'string') {
			return raw.toLowerCase();
		}
		var n = parseFloat(String(raw).replace(/−/g, '-').replace(/[^0-9.\-]/g, ''));
		return isNaN(n) ? 0 : n;
	};

	RankedTable.prototype.toggle = function (th) {
		var dir;
		if (th === this.current) {
			dir = this.dir === 'asc' ? 'desc' : 'asc';
		}
		else {
			dir = th.getAttribute('data-sort-default') || 'asc';
		}
		this.sort(th, dir);
	};

	RankedTable.prototype.sort = function (th, dir) {
		var self = this;
		var type = th.getAttribute('data-sort');
		var col = this.col(th);
		var tb = th.getAttribute('data-sort-tiebreak');
		var tbCol = tb !== null ? parseInt(tb, 10) : null;
		var tbTh = tbCol !== null ? this.ths.filter(function (h) { return self.col(h) === tbCol; })[0] : null;
		var tbType = tbTh ? tbTh.getAttribute('data-sort') : 'int';
		var sign = dir === 'desc' ? -1 : 1;
		var rows = Array.prototype.slice.call(this.tbody.rows).map(function (tr, i) {
			return {
				tr: tr,
				i: i,
				v: self.value(tr, col, type),
				t: tbCol !== null ? self.value(tr, tbCol, tbType) : 0
			};
		});
		function cmp(a, b) {
			if (typeof a === 'string') {
				return a.localeCompare(b);
			}
			return a < b ? -1 : (a > b ? 1 : 0);
		}
		rows.sort(function (a, b) {
			return cmp(a.v, b.v) * sign || (tbCol !== null ? cmp(a.t, b.t) * sign : 0) || a.i - b.i;
		});
		var frag = document.createDocumentFragment();
		rows.forEach(function (r) {
			frag.appendChild(r.tr);
		});
		this.tbody.appendChild(frag);
		this.ths.forEach(function (h) {
			h.setAttribute('aria-sort', h === th ? (dir === 'asc' ? 'ascending' : 'descending') : 'none');
		});
		this.current = th;
		this.dir = dir;
		if (!P55.motion.reduced) {
			this.tbody.classList.remove('is-resorted');
			void this.tbody.offsetWidth;
			this.tbody.classList.add('is-resorted');
		}
		this.renumber();
	};

	/** Best or worst first: the sorted column, or the primary one for a name sort. */
	RankedTable.prototype.bestWorst = function (best) {
		var th = this.current;
		if (!th || th.getAttribute('data-sort') === 'string') {
			th = this.primary;
		}
		if (!th) {
			return;
		}
		var lower = th.hasAttribute('data-lower-is-better');
		this.sort(th, (best !== lower) ? 'desc' : 'asc');
	};

	RankedTable.prototype.filter = function (min) {
		this.min = min;
		Array.prototype.forEach.call(this.tbody.rows, function (tr) {
			var w = tr.getAttribute('data-weeks');
			tr.hidden = w !== null && (parseInt(w, 10) || 0) < min;
		});
		this.renumber();
	};

	RankedTable.prototype.showAll = function () {
		this.limit = 0;
		this.renumber();
	};

	RankedTable.prototype.renumber = function () {
		var n = 0;
		var limit = this.limit;
		Array.prototype.forEach.call(this.tbody.rows, function (tr) {
			if (tr.hidden) {
				tr.classList.remove('is-over');
				return;
			}
			n++;
			tr.classList.toggle('is-over', limit > 0 && n > limit);
			var badge = tr.querySelector('[data-rank]');
			if (badge) {
				badge.textContent = n;
				badge.className = 'rank' + (n <= 3 ? ' rank-' + n : '');
			}
		});
		this.visible = n;
		if (this.more) {
			var box = this.more.closest('.table-more') || this.more;
			box.hidden = !(limit > 0 && n > limit);
			var count = this.more.querySelector('[data-show-count]');
			if (count) {
				count.textContent = n;
			}
		}
		var empty = this.el.parentNode.parentNode.querySelector('[data-filter-empty]');
		if (empty) {
			empty.hidden = n > 0;
		}
	};

	RankedTable.prototype.destroy = function () {
		this.el.removeEventListener('click', this.onClick);
		this.el.removeEventListener('keydown', this.onKey);
	};

	// ------------------------------------------------------------------
	// The module

	P55.page('stats', function (root, props) {
		var tables = Array.prototype.slice.call(root.querySelectorAll('table[data-ranked]')).map(function (el) {
			return new RankedTable(el, root);
		});
		var chart = null;
		var alive = true;
		var themeTimer = null;

		function each(sel, fn) {
			Array.prototype.forEach.call(root.querySelectorAll(sel), fn);
		}

		function applyMin(btn) {
			var min = parseInt(btn.getAttribute('data-min'), 10) || 0;
			each('[data-min-filter]', function (b) {
				b.setAttribute('aria-pressed', b === btn ? 'true' : 'false');
			});
			tables.forEach(function (t) {
				t.filter(min);
			});
		}

		function onClick(e) {
			var t = e.target;
			if (!t.closest) {
				return;
			}
			var toggle = t.closest('[data-sort-toggle]');
			if (toggle && root.contains(toggle)) {
				var best = toggle.getAttribute('data-sort-toggle') === 'best';
				each('[data-sort-toggle]', function (b) {
					b.setAttribute('aria-pressed', b === toggle ? 'true' : 'false');
				});
				each('[data-tiles]', function (el) {
					el.hidden = el.getAttribute('data-tiles') !== (best ? 'best' : 'worst');
				});
				tables.forEach(function (tb) {
					tb.bestWorst(best);
				});
				return;
			}
			var minBtn = t.closest('[data-min-filter]');
			if (minBtn && root.contains(minBtn)) {
				applyMin(minBtn);
				return;
			}
			var more = t.closest('[data-show-all]');
			if (more && root.contains(more)) {
				var id = more.getAttribute('data-show-all');
				tables.forEach(function (tb) {
					if (tb.el.id === id) {
						tb.showAll();
					}
				});
			}
		}
		root.addEventListener('click', onClick);

		// The filter's starting value (the pressed button).
		var pressed = root.querySelector('[data-min-filter][aria-pressed="true"]');
		if (pressed) {
			applyMin(pressed);
		}

		// ---- Record Book histogram
		function drawHistogram() {
			var box = root.querySelector('[data-chart="histogram"]');
			var h = props.histogram;
			if (!box || !h || !alive) {
				return;
			}
			loadChart(props.chart_src).then(function (Chart) {
				if (!alive) {
					return;
				}
				var canvas = box.querySelector('canvas');
				if (chart) {
					chart.destroy();
					chart = null;
				}
				var faint = tokenColor('--fg-faint');
				var line = tokenColor('--line');
				var font = getComputedStyle(document.body).fontFamily;
				var colors = {
					perfect: tokenColor('--gold'),
					honor: tokenColor('--gold', 0.45),
					zero: tokenColor('--bad'),
					dishonor: tokenColor('--bad', 0.42),
					base: tokenColor('--fg-faint', 0.45)
				};
				var hover = {
					perfect: tokenColor('--gold'),
					honor: tokenColor('--gold', 0.7),
					zero: tokenColor('--bad'),
					dishonor: tokenColor('--bad', 0.7),
					base: tokenColor('--fg-muted', 0.8)
				};
				var every = box.clientWidth < 520 ? 10 : 5;
				chart = new Chart(canvas, {
					type: 'bar',
					data: {
						labels: h.labels,
						datasets: [{
							label: 'player-weeks',
							data: h.counts,
							backgroundColor: h.tones.map(function (t) { return colors[t]; }),
							hoverBackgroundColor: h.tones.map(function (t) { return hover[t]; }),
							borderRadius: 2,
							barPercentage: 0.86,
							categoryPercentage: 1
						}]
					},
					options: {
						responsive: true,
						maintainAspectRatio: false,
						animation: P55.motion.reduced ? false : { duration: 500 },
						scales: {
							x: {
								grid: { display: false, drawBorder: false },
								ticks: {
									color: faint,
									font: { family: font, size: 11 },
									autoSkip: false,
									maxRotation: 0,
									callback: function (value, index) {
										return index % every === 0 ? this.getLabelForValue(value) : '';
									}
								},
								title: { display: true, text: 'Weekly score', color: faint, font: { family: font, size: 11, weight: '600' } }
							},
							y: {
								beginAtZero: true,
								grid: { color: line, drawBorder: false },
								ticks: { color: faint, precision: 0, font: { family: font, size: 11 } },
								title: { display: true, text: 'Player-weeks', color: faint, font: { family: font, size: 11, weight: '600' } }
							}
						},
						plugins: {
							legend: { display: false },
							tooltip: {
								displayColors: false,
								callbacks: {
									title: function (items) {
										return items[0].label + ' points';
									},
									label: function (item) {
										return P55.fmt.num(item.raw) + ' player-week' + (item.raw == 1 ? '' : 's');
									}
								}
							}
						}
					}
				});
				box.classList.add('is-ready');
			}).catch(function () {
				box.classList.add('is-failed');
			});
		}

		function onTheme() {
			// The runtime may apply the theme inside a view transition: wait a beat.
			clearTimeout(themeTimer);
			themeTimer = setTimeout(drawHistogram, 120);
		}

		if (props.histogram) {
			drawHistogram();
			document.addEventListener('p55:theme', onTheme);
		}

		return function () {
			alive = false;
			clearTimeout(themeTimer);
			root.removeEventListener('click', onClick);
			document.removeEventListener('p55:theme', onTheme);
			tables.forEach(function (t) {
				t.destroy();
			});
			if (chart) {
				chart.destroy();
				chart = null;
			}
		};
	});
})();
