/*
 * Standings (r/season/standings.php) and My Season (r/season/index.php).
 *
 * standings: sortable tables, the analysis panels (a tablist; panels are
 * rendered by the server and toggled here, the choice kept in localStorage),
 * charts built lazily the first time their panel shows, tap tooltips on the
 * week strips. season: the two points doughnuts. Both: the season select,
 * charts repainted from the CSS tokens on p55:theme.
 */
(function () {
	'use strict';

	var PANEL_KEY = 'p55-standings-panel';
	var chartLoading = null;

	/** Resolves with window.Chart, loading the vendored file once if a partial navigation skipped it. */
	function needChart(src) {
		if (window.Chart) {
			return Promise.resolve(window.Chart);
		}
		if (!chartLoading) {
			chartLoading = new Promise(function (resolve, reject) {
				var existing = document.querySelector('script[src*="static/js/chart.js"]');
				var s = existing || document.createElement('script');
				var done = function () {
					if (window.Chart) {
						resolve(window.Chart);
					}
					else {
						chartLoading = null;
						reject(new Error('Chart.js failed to load'));
					}
				};
				s.addEventListener('load', done);
				s.addEventListener('error', done);
				if (!existing) {
					s.src = src;
					document.body.appendChild(s);
				}
				else {
					// Already in the page: it may have loaded between the check and now.
					setTimeout(function () {
						if (window.Chart) {
							resolve(window.Chart);
						}
					}, 0);
				}
			});
		}
		return chartLoading;
	}

	/** The design tokens charts use, read from the current theme. */
	function tokens() {
		var cs = getComputedStyle(document.documentElement);
		var get = function (name, fallback) {
			var v = cs.getPropertyValue(name).trim();
			return v || fallback;
		};
		return {
			fg: get('--fg', '#111'),
			muted: get('--fg-muted', '#555'),
			faint: get('--fg-faint', '#888'),
			line: get('--line', 'rgba(0,0,0,.1)'),
			bg: get('--bg-elev', '#fff'),
			brand: get('--brand', '#0e9f6e'),
			accent: get('--accent', '#2f5bea'),
			nfl: get('--nfl', '#1f4fd1'),
			ncaa: get('--ncaa', '#b4233a'),
			ou: get('--ou', '#7c3aed'),
			spread: get('--spread', '#e0600f'),
			font: getComputedStyle(document.body).fontFamily
		};
	}

	/** '#rrggbb' + alpha -> 'rgba()'; other colour strings pass through. */
	function alpha(color, a) {
		var m = /^#([0-9a-f]{6})$/i.exec(color);
		if (!m) {
			return color;
		}
		var n = parseInt(m[1], 16);
		return 'rgba(' + (n >> 16 & 255) + ',' + (n >> 8 & 255) + ',' + (n & 255) + ',' + a + ')';
	}

	function baseOptions(t) {
		return {
			responsive: true,
			maintainAspectRatio: false,
			animation: P55.motion.reduced ? false : { duration: 500 },
			plugins: {
				legend: { display: false },
				tooltip: {
					backgroundColor: t.fg,
					titleColor: t.bg,
					bodyColor: t.bg,
					cornerRadius: 8,
					padding: 10,
					titleFont: { family: t.font, weight: '700' },
					bodyFont: { family: t.font }
				}
			}
		};
	}

	function axis(t, extra) {
		var a = {
			grid: { color: t.line, drawBorder: false },
			ticks: { color: t.faint, font: { family: t.font, size: 11 } },
			title: { color: t.muted, font: { family: t.font, size: 11, weight: '600' } }
		};
		Object.keys(extra || {}).forEach(function (k) {
			if (typeof extra[k] === 'object' && a[k]) {
				Object.keys(extra[k]).forEach(function (k2) {
					a[k][k2] = extra[k][k2];
				});
			}
			else {
				a[k] = extra[k];
			}
		});
		return a;
	}

	/**
	 * Charts registry for a page: build(name) creates a chart once; rebuild()
	 * repaints the built ones (theme change); destroy() on teardown.
	 */
	function charts(root, src, builders) {
		var built = {};
		var dead = false;
		return {
			build: function (name) {
				if (built[name] || !builders[name]) {
					return;
				}
				var canvas = root.querySelector('canvas[data-chart="' + name + '"]');
				if (!canvas) {
					return;
				}
				built[name] = 'pending';
				needChart(src).then(function (Chart) {
					if (dead || built[name] !== 'pending') {
						return;
					}
					var t = tokens();
					Chart.defaults.font.family = t.font;
					Chart.defaults.color = t.muted;
					built[name] = new Chart(canvas, builders[name](t));
				}).catch(function () {
					built[name] = null;
				});
			},
			rebuild: function () {
				Object.keys(built).forEach(function (name) {
					var c = built[name];
					if (c && c !== 'pending') {
						c.destroy();
						built[name] = null;
						this.build(name);
					}
				}, this);
			},
			destroy: function () {
				dead = true;
				Object.keys(built).forEach(function (name) {
					var c = built[name];
					if (c && c !== 'pending') {
						c.destroy();
					}
				});
				built = {};
			}
		};
	}

	// ------------------------------------------------------------------
	// Shared behaviour

	/** Sortable tables: th[data-sort="num|str"], values from td[data-v] or text. */
	function sortables(root) {
		function cellValue(row, i, type) {
			var cell = row.cells[i];
			if (!cell) {
				return type === 'num' ? -Infinity : '';
			}
			var v = cell.hasAttribute('data-v') ? cell.getAttribute('data-v') : cell.textContent.trim();
			if (type === 'num') {
				var n = parseFloat(v);
				return isNaN(n) ? -Infinity : n;
			}
			return v.toLowerCase();
		}
		function sortBy(th) {
			var table = th.closest('table');
			var body = table.tBodies[0];
			if (!body) {
				return;
			}
			var type = th.getAttribute('data-sort');
			var current = th.getAttribute('aria-sort');
			var dir;
			if (current === 'ascending') {
				dir = 'descending';
			}
			else if (current === 'descending') {
				dir = 'ascending';
			}
			else {
				dir = th.hasAttribute('data-desc') ? 'descending' : 'ascending';
			}
			P55.$$('th[aria-sort]', table).forEach(function (h) {
				h.removeAttribute('aria-sort');
			});
			th.setAttribute('aria-sort', dir);
			var i = th.cellIndex;
			var rows = Array.prototype.slice.call(body.rows).map(function (row, pos) {
				return { row: row, v: cellValue(row, i, type), pos: pos };
			});
			// Ties keep their current order.
			rows.sort(function (a, b) {
				var c = a.v < b.v ? -1 : (a.v > b.v ? 1 : 0);
				if (dir === 'descending') {
					c = -c;
				}
				return c || (a.pos - b.pos);
			});
			rows.forEach(function (r) {
				body.appendChild(r.row);
			});
		}
		P55.$$('table[data-sortable] th[data-sort]', root).forEach(function (th) {
			th.setAttribute('tabindex', '0');
		});
		function onClick(e) {
			var th = e.target.closest ? e.target.closest('th[data-sort]') : null;
			if (th && root.contains(th)) {
				sortBy(th);
			}
		}
		function onKey(e) {
			if (e.key !== 'Enter' && e.key !== ' ') {
				return;
			}
			var th = e.target.closest ? e.target.closest('th[data-sort]') : null;
			if (th && root.contains(th)) {
				e.preventDefault();
				sortBy(th);
			}
		}
		root.addEventListener('click', onClick);
		root.addEventListener('keydown', onKey);
		return function () {
			root.removeEventListener('click', onClick);
			root.removeEventListener('keydown', onKey);
		};
	}

	/** The season select: navigate on change (the form is the no-JS path). */
	function seasonSelect(root) {
		function onChange(e) {
			var sel = e.target;
			var form = sel && sel.closest ? sel.closest('form[data-season-pick]') : null;
			if (!form) {
				return;
			}
			P55.navigate(form.getAttribute('action') + '?id=' + encodeURIComponent(sel.value));
		}
		root.addEventListener('change', onChange);
		return function () {
			root.removeEventListener('change', onChange);
		};
	}

	/** A small tooltip for [data-tip] on hover and tap (titles do not show on touch). */
	function tips(root) {
		var tip = document.createElement('div');
		tip.className = 'wk-tip';
		tip.setAttribute('role', 'tooltip');
		tip.hidden = true;
		document.body.appendChild(tip);
		var hideTimer = null;
		function show(el) {
			clearTimeout(hideTimer);
			tip.textContent = el.getAttribute('data-tip');
			tip.hidden = false;
			var r = el.getBoundingClientRect();
			var w = tip.offsetWidth;
			var left = Math.max(8, Math.min(window.innerWidth - w - 8, r.left + r.width / 2 - w / 2));
			tip.style.left = left + 'px';
			tip.style.top = (r.top - tip.offsetHeight - 6) + 'px';
		}
		function hide() {
			tip.hidden = true;
		}
		function target(e) {
			return e.target.closest ? e.target.closest('[data-tip]') : null;
		}
		function onOver(e) {
			var el = target(e);
			if (el && root.contains(el)) {
				show(el);
			}
		}
		function onOut(e) {
			if (target(e)) {
				hide();
			}
		}
		function onDown(e) {
			var el = target(e);
			if (el && root.contains(el) && e.pointerType !== 'mouse') {
				show(el);
				hideTimer = setTimeout(hide, 1800);
			}
		}
		root.addEventListener('mouseover', onOver);
		root.addEventListener('mouseout', onOut);
		root.addEventListener('pointerdown', onDown);
		window.addEventListener('scroll', hide, true);
		return function () {
			clearTimeout(hideTimer);
			root.removeEventListener('mouseover', onOver);
			root.removeEventListener('mouseout', onOut);
			root.removeEventListener('pointerdown', onDown);
			window.removeEventListener('scroll', hide, true);
			if (tip.parentNode) {
				tip.parentNode.removeChild(tip);
			}
		};
	}

	// ------------------------------------------------------------------
	// Standings

	P55.page('standings', function (root, props) {
		var weeks = props.weeks || [];
		var labels = weeks.map(function (w) {
			return 'W' + w.num + (w.complete ? '' : '*');
		});

		var registry = charts(root, props.chart_src, {
			trajectory: function (t) {
				var sets = (props.trajectory || []).map(function (p) {
					var color = p.me ? t.brand : (p.friend ? t.accent : alpha(t.faint, 0.75));
					return {
						label: p.name,
						data: p.ranks,
						borderColor: color,
						backgroundColor: color,
						borderWidth: p.me ? 3.5 : (p.friend ? 2.5 : 1.5),
						pointRadius: p.me ? 3.5 : 2,
						pointHoverRadius: 6,
						pointBorderColor: t.bg,
						pointBorderWidth: 1,
						tension: 0.25,
						spanGaps: true,
						order: p.me ? 0 : (p.friend ? 1 : 2)
					};
				});
				var o = baseOptions(t);
				o.interaction = { mode: 'nearest', intersect: false, axis: 'xy' };
				o.plugins.tooltip.callbacks = {
					title: function (items) {
						var w = weeks[items[0].dataIndex];
						return w ? 'After week ' + w.num + (w.complete ? '' : ' (in progress)') : '';
					},
					label: function (item) {
						return ' ' + item.dataset.label + ': ' + P55.fmt.ordinal(item.parsed.y);
					}
				};
				o.scales = {
					x: axis(t, { grid: { display: false } }),
					y: axis(t, {
						reverse: true,
						min: 1,
						max: Math.max(2, props.players || 1),
						ticks: {
							precision: 0,
							callback: function (v) {
								return P55.fmt.ordinal(v);
							}
						},
						title: { display: true, text: 'Season rank' }
					})
				};
				return { type: 'line', data: { labels: labels, datasets: sets }, options: o };
			},
			weekly: function (t) {
				var d = props.distribution || { labels: [], counts: [], mine: [] };
				var mine = {};
				(d.mine || []).forEach(function (v) {
					mine[v] = true;
				});
				var colors = d.labels.map(function (score) {
					return mine[score] ? t.brand : alpha(t.faint, 0.6);
				});
				var o = baseOptions(t);
				o.plugins.tooltip.callbacks = {
					title: function (items) {
						return items[0].label + ' points';
					},
					label: function (item) {
						var n = item.parsed.y;
						return ' ' + n + (n === 1 ? ' player-week' : ' player-weeks') + (mine[item.label] ? ' (you scored this)' : '');
					}
				};
				o.scales = {
					x: axis(t, {
						grid: { display: false },
						ticks: { autoSkip: true, maxRotation: 0 },
						title: { display: true, text: 'Weekly score' }
					}),
					y: axis(t, {
						min: 0,
						ticks: { precision: 0 },
						title: { display: true, text: 'Occurrences' }
					})
				};
				return {
					type: 'bar',
					data: {
						labels: d.labels,
						datasets: [{
							label: 'Occurrences',
							data: d.counts,
							backgroundColor: colors,
							borderRadius: 3,
							borderSkipped: 'bottom',
							categoryPercentage: 0.9,
							barPercentage: 0.9
						}]
					},
					options: o
				};
			}
		});

		// Panels (a tablist).
		var tabs = P55.$$('[data-panel]', root);
		function select(name, focus) {
			var found = false;
			tabs.forEach(function (tab) {
				var on = tab.getAttribute('data-panel') === name;
				found = found || on;
				tab.setAttribute('aria-selected', on ? 'true' : 'false');
				tab.setAttribute('tabindex', on ? '0' : '-1');
				if (on && focus) {
					tab.focus();
				}
			});
			if (!found) {
				return false;
			}
			P55.$$('[data-panel-body]', root).forEach(function (panel) {
				panel.hidden = panel.getAttribute('data-panel-body') !== name;
			});
			registry.build(name);
			return true;
		}
		function onTabClick(e) {
			var tab = e.target.closest ? e.target.closest('[data-panel]') : null;
			if (!tab || !root.contains(tab)) {
				return;
			}
			var name = tab.getAttribute('data-panel');
			select(name, false);
			try {
				localStorage.setItem(PANEL_KEY, name);
			}
			catch (err) {
				// storage blocked: the choice lasts for this view
			}
		}
		function onTabKey(e) {
			var tab = e.target.closest ? e.target.closest('[data-panel]') : null;
			if (!tab || (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft' && e.key !== 'Home' && e.key !== 'End')) {
				return;
			}
			e.preventDefault();
			var i = tabs.indexOf(tab);
			if (e.key === 'ArrowRight') {
				i = (i + 1) % tabs.length;
			}
			else if (e.key === 'ArrowLeft') {
				i = (i - 1 + tabs.length) % tabs.length;
			}
			else if (e.key === 'Home') {
				i = 0;
			}
			else {
				i = tabs.length - 1;
			}
			tabs[i].click();
			tabs[i].focus();
		}
		root.addEventListener('click', onTabClick);
		root.addEventListener('keydown', onTabKey);

		var stored = null;
		try {
			stored = localStorage.getItem(PANEL_KEY);
		}
		catch (err) {
			stored = null;
		}
		if (!stored || !select(stored, false)) {
			select(tabs.length ? tabs[0].getAttribute('data-panel') : '', false);
		}

		function onTheme() {
			registry.rebuild();
		}
		document.addEventListener('p55:theme', onTheme);

		var offSort = sortables(root);
		var offSelect = seasonSelect(root);
		var offTips = tips(root);

		return function () {
			root.removeEventListener('click', onTabClick);
			root.removeEventListener('keydown', onTabKey);
			document.removeEventListener('p55:theme', onTheme);
			offSort();
			offSelect();
			offTips();
			registry.destroy();
		};
	});

	// ------------------------------------------------------------------
	// My Season

	P55.page('season', function (root, props) {
		var b = props.breakdown || {};
		function donut(keys, names) {
			return function (t) {
				var o = baseOptions(t);
				o.cutout = '70%';
				o.plugins.tooltip.callbacks = {
					label: function (item) {
						return ' ' + item.label + ': ' + item.parsed + ' pts';
					}
				};
				return {
					type: 'doughnut',
					data: {
						labels: names,
						datasets: [{
							data: keys.map(function (k) {
								return b[k] || 0;
							}),
							backgroundColor: keys.map(function (k) {
								return t[k];
							}),
							borderColor: t.bg,
							borderWidth: 2,
							hoverOffset: 4
						}]
					},
					options: o
				};
			};
		}
		var registry = charts(root, props.chart_src, {
			league: donut(['nfl', 'ncaa'], ['NFL', 'NCAA']),
			type: donut(['ou', 'spread'], ['Over/Under', 'Spread'])
		});
		registry.build('league');
		registry.build('type');

		function onTheme() {
			registry.rebuild();
		}
		document.addEventListener('p55:theme', onTheme);
		var offSelect = seasonSelect(root);

		return function () {
			document.removeEventListener('p55:theme', onTheme);
			offSelect();
			registry.destroy();
		};
	});
})();
