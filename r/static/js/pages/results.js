/*
 * Week Results (r/season/week/results.php): sortable standings, the "me"
 * dock, collapsible pickers and grid rows, what-if navigation that keeps
 * scroll, the expected-winnings chart, and the live poll of api/week.php
 * while any game is undecided.
 */
(function () {
	'use strict';

	P55.page('results', function (root, props) {
		var cleanups = [];
		var stopPoll = null;
		var reloading = false;
		var chart = null;
		var destroyed = false;

		function on(target, type, fn, opts) {
			target.addEventListener(type, fn, opts);
			cleanups.push(function () {
				target.removeEventListener(type, fn, opts);
			});
		}

		function $(sel, el) {
			return (el || root).querySelector(sel);
		}

		function $$(sel, el) {
			return Array.prototype.slice.call((el || root).querySelectorAll(sel));
		}

		function reload() {
			if (reloading) {
				return;
			}
			reloading = true;
			if (stopPoll) {
				stopPoll();
				stopPoll = null;
			}
			P55.reload();
		}

		function smallMoney(v) {
			v = Number(v) || 0;
			if (v < 0.005) {
				return '';
			}
			if (v < 0.5) {
				return '<$1';
			}
			return P55.fmt.money(v);
		}

		function setText(el, text) {
			if (!el) {
				return false;
			}
			text = String(text);
			if (el.textContent !== text) {
				el.textContent = text;
				P55.flash(el);
				return true;
			}
			return false;
		}

		// --------------------------------------------------------------
		// Sorting

		var table = $('.st-table');
		var tbody = table ? table.tBodies[0] : null;

		function cellValue(row, idx, type) {
			var cell = row.cells[idx];
			var v = cell ? cell.getAttribute('data-v') : '';
			if (type === 'num') {
				var n = parseFloat(v);
				return isNaN(n) ? -Infinity : n;
			}
			return String(v || '');
		}

		function currentSort() {
			var th = table ? $('th[aria-sort]', table) : null;
			return th ? { th: th, dir: th.getAttribute('aria-sort') } : null;
		}

		function applySort(th, dir) {
			if (!tbody) {
				return;
			}
			var idx = Array.prototype.indexOf.call(th.parentNode.children, th);
			var type = th.getAttribute('data-sort');
			var rows = Array.prototype.slice.call(tbody.rows);
			var sign = dir === 'descending' ? -1 : 1;
			rows.sort(function (a, b) {
				var va = cellValue(a, idx, type);
				var vb = cellValue(b, idx, type);
				var c = 0;
				if (type === 'num') {
					c = va === vb ? 0 : (va < vb ? -1 : 1);
				}
				else {
					c = va.localeCompare(vb);
				}
				if (c === 0) {
					return Number(a.getAttribute('data-i')) - Number(b.getAttribute('data-i'));
				}
				return c * sign;
			});
			flip(rows, function () {
				rows.forEach(function (r) {
					tbody.appendChild(r);
				});
			});
			$$('th[data-sort]', table).forEach(function (h) {
				if (h === th) {
					h.setAttribute('aria-sort', dir);
				}
				else {
					h.removeAttribute('aria-sort');
				}
			});
		}

		if (table) {
			on(table, 'click', function (e) {
				var th = e.target.closest('th[data-sort]');
				if (!th) {
					return;
				}
				var cur = th.getAttribute('aria-sort');
				var first = th.getAttribute('data-first') || (th.getAttribute('data-sort') === 'num' ? 'descending' : 'ascending');
				var dir = cur ? (cur === 'ascending' ? 'descending' : 'ascending') : first;
				applySort(th, dir);
			});
		}

		/** FLIP: run `mutate`, then animate each row from where it was. */
		function flip(rows, mutate) {
			if (P55.motion.reduced || !rows.length) {
				mutate();
				return;
			}
			var before = new Map();
			rows.forEach(function (r) {
				before.set(r, r.getBoundingClientRect().top);
			});
			mutate();
			var moved = [];
			rows.forEach(function (r) {
				var dy = before.get(r) - r.getBoundingClientRect().top;
				if (Math.abs(dy) > 1) {
					r.style.transition = 'none';
					r.style.transform = 'translateY(' + dy + 'px)';
					moved.push(r);
				}
			});
			if (!moved.length) {
				return;
			}
			void tbody.offsetHeight;
			moved.forEach(function (r) {
				r.style.transition = 'transform 450ms cubic-bezier(0.2, 0.8, 0.2, 1)';
				r.style.transform = '';
				r.addEventListener('transitionend', function done() {
					r.style.transition = '';
					r.removeEventListener('transitionend', done);
				});
			});
		}

		// --------------------------------------------------------------
		// Show more: pickers per game, grid rows

		on(root, 'click', function (e) {
			var more = e.target.closest('[data-more]');
			if (more) {
				var card = more.closest('.game');
				var open = !card.classList.contains('is-open');
				card.classList.toggle('is-open', open);
				more.setAttribute('aria-expanded', open ? 'true' : 'false');
				var span = more.querySelector('span');
				if (span) {
					span.textContent = more.getAttribute(open ? 'data-less-text' : 'data-more-text');
				}
				return;
			}
			// Standings and the point-value grid: top rows plus me until expanded.
			var fold = e.target.closest('[data-fold-toggle]');
			if (fold) {
				var sec = fold.closest('[data-fold]');
				var collapsed = !sec.classList.contains('is-collapsed');
				sec.classList.toggle('is-collapsed', collapsed);
				fold.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
				var s = fold.querySelector('span');
				if (s) {
					s.textContent = fold.getAttribute(collapsed ? 'data-more-text' : 'data-less-text');
				}
				return;
			}
			// What-ifs: a partial navigation that keeps the reader where they are.
			var wi = e.target.closest('a[data-whatif]');
			if (wi && !e.defaultPrevented && e.button === 0 && !e.metaKey && !e.ctrlKey && !e.shiftKey && !e.altKey) {
				e.preventDefault();
				wi.setAttribute('aria-busy', 'true');
				P55.navigate(wi.href, { scroll: 'keep', focus: false });
			}
		});

		// --------------------------------------------------------------
		// Week menu: keep the panel on screen, show the current week

		var weekMenu = $('details.week-menu');
		if (weekMenu) {
			on(weekMenu, 'toggle', function () {
				var panel = weekMenu.querySelector('.week-menu-panel');
				if (!panel || !weekMenu.open) {
					return;
				}
				// Hang from the button's left edge when the default (right
				// edge) would push the panel off the left of the screen.
				// edge) would push the panel off the left of the screen, then
				// slide it back if that pushes it off the right (a narrow
				// phone; the panel is never wider than the screen).
				panel.classList.remove('is-left');
				panel.style.left = '';
				var r = panel.getBoundingClientRect();
				if (r.left < 8) {
					panel.classList.add('is-left');
					r = panel.getBoundingClientRect();
					var over = r.right - (document.documentElement.clientWidth - 8);
					if (over > 0) {
						panel.style.left = (-over) + 'px';
					}
				}
				// Scroll the list so the current week is in view.
				var cur = panel.querySelector('.menu-item.is-current');
				if (cur && panel.scrollHeight > panel.clientHeight) {
					panel.scrollTop = Math.max(0, cur.offsetTop - (panel.clientHeight - cur.offsetHeight) / 2);
				}
			});
		}

		// --------------------------------------------------------------
		// The "me" dock: my row, pinned when it is scrolled away

		var dock = $('[data-medock]');
		var myRow = $('tr[data-user="' + props.me + '"]');
		var standings = $('[data-standings]');
		if (dock && myRow && standings && 'IntersectionObserver' in window) {
			var rowSeen = true;
			var tableSeen = false;
			var io = new IntersectionObserver(function (entries) {
				entries.forEach(function (en) {
					if (en.target === myRow) {
						rowSeen = en.isIntersecting;
					}
					else {
						tableSeen = en.isIntersecting;
					}
				});
				var show = tableSeen && !rowSeen;
				dock.classList.toggle('is-shown', show);
				dock.setAttribute('aria-hidden', show ? 'false' : 'true');
				var btn = dock.querySelector('button');
				if (btn) {
					btn.tabIndex = show ? 0 : -1;
				}
			}, { rootMargin: '-72px 0px -84px 0px' });
			io.observe(myRow);
			io.observe(standings);
			cleanups.push(function () {
				io.disconnect();
			});
			on(dock, 'click', function () {
				myRow.scrollIntoView({ block: 'center', behavior: P55.motion.reduced ? 'auto' : 'smooth' });
				P55.flash(myRow.cells[1]);
			});
		}

		// --------------------------------------------------------------
		// Expected winnings chart

		var canvas = $('canvas[data-chart]');

		function drawChart(Chart) {
			if (destroyed || !canvas || !props.chart) {
				return;
			}
			if (chart) {
				chart.destroy();
				chart = null;
			}
			var t = P55.chartTheme();
			var palette = [t.accent, t.warn, t.ncaa, t.ou, t.muted, t.spread];
			var k = 0;
			var datasets = props.chart.series.map(function (s) {
				var color = s.me ? t.brand : palette[k++ % palette.length];
				return {
					label: s.label,
					data: s.data,
					borderColor: color,
					backgroundColor: color,
					borderWidth: s.me ? 3.5 : 1.75,
					pointRadius: s.me ? 3.5 : 2,
					pointHoverRadius: 6,
					cubicInterpolationMode: 'monotone',
					order: s.me ? 0 : 1
				};
			});
			chart = new Chart(canvas, {
				type: 'line',
				data: { labels: props.chart.labels, datasets: datasets },
				options: {
					responsive: true,
					maintainAspectRatio: false,
					animation: t.animation,
					interaction: { mode: 'index', intersect: false },
					plugins: {
						legend: {
							position: 'bottom',
							labels: { color: t.muted, usePointStyle: true, boxWidth: 8, boxHeight: 8, padding: 14 }
						},
						tooltip: {
							callbacks: {
								label: function (ctx) {
									return ' ' + ctx.dataset.label + ': ' + (smallMoney(ctx.parsed.y) || '$0');
								}
							}
						}
					},
					scales: {
						y: {
							beginAtZero: true,
							ticks: { color: t.faint, callback: function (v) { return P55.fmt.money(v); } },
							grid: { color: t.line, drawBorder: false }
						},
						x: {
							ticks: { color: t.faint, maxRotation: 0, autoSkip: true },
							grid: { display: false }
						}
					}
				}
			});
		}

		function renderChart() {
			if (!canvas || !props.chart) {
				return;
			}
			P55.chart().then(drawChart, function () {
				var box = canvas.parentNode;
				if (box && !destroyed) {
					box.innerHTML = '<p class="muted small">The chart could not load.</p>';
				}
			});
		}

		if (canvas && props.chart) {
			renderChart();
			on(document, 'p55:theme', renderChart);
		}

		// --------------------------------------------------------------
		// Live

		function paintGame(card, g) {
			var decided = card.getAttribute('data-correct') !== '0';
			var score = $('[data-f="score"]', card);
			if (score && g.away_score !== null && g.home_score !== null) {
				score.hidden = false;
				setText($('[data-f="away"]', card), g.away_score);
				setText($('[data-f="home"]', card), g.home_score);
			}
			var status = $('[data-f="status"]', card);
			if (status) {
				var html = '';
				if (g.state === 'in') {
					html = '<span class="badge-live">LIVE</span><span class="gs-label">' + P55.esc(g.label) + '</span>';
				}
				else if (g.label) {
					html = '<span class="gs-label">' + P55.esc(g.label) + '</span>';
				}
				if (status.innerHTML !== html) {
					status.innerHTML = html;
				}
			}
			if (card.getAttribute('data-state') !== g.state) {
				card.classList.remove('is-pre', 'is-in', 'is-post');
				card.classList.add('is-' + g.state);
				card.setAttribute('data-state', g.state);
			}
			if (!decided) {
				$$('.side[data-option="1"], .side[data-option="2"]', card).forEach(function (side) {
					var lead = side.getAttribute('data-option') === g.leading_option;
					if (side.classList.contains('is-leading') !== lead) {
						side.classList.toggle('is-leading', lead);
						if (lead) {
							P55.flash($('.lead-mark', side));
						}
					}
				});
			}
		}

		function paintStandings(list) {
			if (!tbody || !list) {
				return;
			}
			var rankChanged = false;
			list.forEach(function (s) {
				var row = $('tr[data-user="' + s.user_id + '"]', tbody);
				if (!row) {
					return;
				}
				var rankCell = row.cells[0];
				if (rankCell && Number(rankCell.getAttribute('data-v')) !== s.rank) {
					rankCell.setAttribute('data-v', String(s.rank));
					var r = rankCell.querySelector('.rank');
					if (r && props.ranked) {
						r.textContent = String(s.rank);
						r.className = 'rank' + (s.rank <= 3 ? ' rank-' + s.rank : '');
					}
					rankChanged = true;
				}
				var pts = $('[data-f="points"]', row);
				if (pts && setText(pts, s.points)) {
					pts.closest('td').setAttribute('data-v', String(s.points));
				}
				var right = $('[data-f="right"]', row);
				if (right && setText(right, s.right)) {
					right.closest('td').setAttribute('data-v', String(s.right));
				}
				setText($('[data-f="wrong"]', row), s.wrong);
				var exp = $('[data-f="expected"]', row);
				if (exp) {
					if (setText(exp, smallMoney(s.expected))) {
						exp.setAttribute('data-v', String(s.expected));
					}
				}
				if (s.user_id === props.me && dock) {
					setText($('[data-f="points"]', dock), s.points);
					var dr = $('[data-f="rank"] .rank', dock);
					if (dr && props.ranked) {
						dr.textContent = String(s.rank);
						dr.className = 'rank' + (s.rank <= 3 ? ' rank-' + s.rank : '');
					}
					var de = $('[data-f="expected-label"]', dock);
					if (de) {
						setText(de, 'Exp ' + (smallMoney(s.expected) || '$0'));
					}
				}
			});
			// Rows follow their new rank when the table is in rank order, and
			// the collapsed view keeps showing the top rows (plus me).
			var sort = currentSort();
			if (rankChanged && sort && sort.th.classList.contains('c-rank') && sort.dir === 'ascending') {
				applySort(sort.th, 'ascending');
				refold();
			}
		}

		/** Re-mark the standings' hidden rows from the current row order. */
		function refold() {
			var sec = table ? table.closest('[data-fold]') : null;
			if (!sec || !tbody) {
				return;
			}
			var shown = parseInt(sec.getAttribute('data-fold-rows'), 10);
			if (!(shown > 0) || !$('.is-extra', tbody)) {
				return;
			}
			Array.prototype.forEach.call(tbody.rows, function (row, i) {
				row.classList.toggle('is-extra', i >= shown && !row.classList.contains('row-me'));
			});
		}

		function paintStatus(data) {
			var line = $('[data-status]');
			if (!line) {
				return;
			}
			setText($('[data-f="in_play"]', line), data.in_play);
			setText($('[data-f="final"]', line), data.final);
			setText($('[data-f="upcoming"]', line), data.upcoming);
			$$('.sl-live, .sl-live + .sl-sep', line).forEach(function (el) {
				el.hidden = !data.in_play;
			});
		}

		function paint(data) {
			if (destroyed || !data || data.week_id !== props.week_id) {
				return;
			}
			var games = data.games || {};
			var ids = Object.keys(games);
			for (var i = 0; i < ids.length; i++) {
				var card = $('.game[data-game="' + ids[i] + '"]');
				// A result was set: the standings and probabilities recompute.
				if (card && card.getAttribute('data-correct') === '0' && games[ids[i]].correct_option !== '0') {
					reload();
					return 0;
				}
			}
			ids.forEach(function (id) {
				var card = $('.game[data-game="' + id + '"]');
				if (card) {
					paintGame(card, games[id]);
				}
			});
			paintStatus(data);
			paintStandings(data.standings);
			if (!data.num_unknowns) {
				reload();
				return 0;
			}
			return data.any_live ? 60000 : 300000;
		}

		if (props.poll && props.api) {
			stopPoll = P55.poll(function () {
				return P55.fetchJSON(props.api).then(paint);
			}, props.interval || 60000);
		}

		return function teardown() {
			destroyed = true;
			if (stopPoll) {
				stopPoll();
				stopPoll = null;
			}
			if (chart) {
				chart.destroy();
				chart = null;
			}
			cleanups.forEach(function (fn) {
				fn();
			});
			cleanups = [];
		};
	});
})();
