<?php

require_once __DIR__ . '/../../inc/_inc.php';

use Pick55\Auth;
use Pick55\DB;
use Pick55\Page;
use Pick55\Paging;
use Pick55\SiteVersion;
use Pick55\Traffic;
use Pick55\Models\SiteHit;
use Pick55\Models\User;

// Site traffic (docs/site-traffic.md): what Pick55\Traffic recorded in
// er_site_hits. A "view" is a GET answered with a 2xx: redirects, form
// posts and errors are in the log but not in the counts.

Auth::guardAdmin();

$page = new Page;
$page->setTitle('Traffic - Admin');
$page->options['admin_bar']['show'] = true;
$page->options['admin_bar']['title'] = 'Traffic';

const TRAFFIC_RANGES = [
	'1' => 'Today',
	'7' => 'Last 7 days',
	'30' => 'Last 30 days',
	'90' => 'Last 90 days',
	'all' => 'All time',
];
const TRAFFIC_VERSIONS = [
	SiteVersion::CLASSIC => 'Classic',
	SiteVersion::REDESIGN => 'Version 2.0',
];

/**
 * The page's URL with some filters changed (null removes one); paging resets.
 *
 * @param array $changes
 * @return string
 */
function traffic_link(array $changes)
{
	$qs = $_GET;
	unset($qs['p']);
	foreach ($changes as $key => $val) {
		if ($val === null) {
			unset($qs[$key]);
		}
		else {
			$qs[$key] = $val;
		}
	}
	return h('?' . http_build_query($qs));
}

/**
 * @param string|null $version
 * @return string  a badge
 */
function traffic_version_badge($version)
{
	if (!isset(TRAFFIC_VERSIONS[$version])) {
		return '<span class="text-muted">&mdash;</span>';
	}
	return '<span class="traffic-key traffic-key-' . h($version) . '"></span>' . h(TRAFFIC_VERSIONS[$version]);
}

// -------
// Filters
// -------

$range = (string) get('days', '7');
if (!isset(TRAFFIC_RANGES[$range])) {
	$range = '7';
}
$since = null;
if ($range !== 'all') {
	$since = date('Y-m-d 00:00:00', strtotime('-' . ((int) $range - 1) . ' days'));
}
$version = isset(TRAFFIC_VERSIONS[get('version')]) ? get('version') : null;
$user_id = ctype_digit((string) get('user_id')) ? (int) get('user_id') : null;
$path = trim((string) get('path', ''));
$show_all = get('show') === 'all';

$filtered = function () use ($since, $version, $user_id, $path) {
	$q = DB::table('er_site_hits');
	if ($since) {
		$q->where('created_at', '>=', $since);
	}
	if ($version) {
		$q->where('version', '=', $version);
	}
	if ($user_id !== null) {
		if ($user_id) {
			$q->where('er_user_id', '=', $user_id);
		}
		else {
			$q->whereNull('er_user_id');
		}
	}
	if ($path !== '') {
		$q->where('path', 'LIKE', '%' . addcslashes($path, '%_\\') . '%');
	}
	return $q;
};
$views = function () use ($filtered) {
	return $filtered()
		->where('method', '=', 'GET')
		->whereBetween('status', [200, 299]);
};

// ----
// Data
// ----

$error = null;
$totals = ['views' => 0, 'players' => 0, 'guest' => 0, SiteVersion::CLASSIC => 0, SiteVersion::REDESIGN => 0];
$buckets = [];
$by_hour = $range === '1';
$top_pages = [];
$players = [];
$choices = [];
$choice_counts = [SiteVersion::REDESIGN => 0, SiteVersion::CLASSIC => 0, 'none' => 0];
$users = [];
$hits = [];
$paging = new Paging(['per' => 50]);

try {
	// Totals
	$row = $views()
		->selectRaw('COUNT(*) AS views, COUNT(DISTINCT er_user_id) AS players, SUM(er_user_id IS NULL) AS guest, SUM(version = ?) AS classic, SUM(version = ?) AS redesign', [SiteVersion::CLASSIC, SiteVersion::REDESIGN])
		->first();
	$totals['views'] = (int) $row->views;
	$totals['players'] = (int) $row->players;
	$totals['guest'] = (int) $row->guest;
	$totals[SiteVersion::CLASSIC] = (int) $row->classic;
	$totals[SiteVersion::REDESIGN] = (int) $row->redesign;

	// Views over time: by hour for a single day, else by day
	$bucket_sql = $by_hour ? "DATE_FORMAT(created_at, '%Y-%m-%d %H')" : 'DATE(created_at)';
	$rows = $views()
		->selectRaw($bucket_sql . ' AS bucket, version, COUNT(*) AS views')
		->groupBy('bucket', 'version')
		->orderBy('bucket', 'ASC')
		->get();
	$first = $since ? strtotime($since) : null;
	foreach ($rows as $row) {
		$at = strtotime($by_hour ? $row->bucket . ':00:00' : $row->bucket);
		if ($first === null || $at < $first) {
			$first = $at;
		}
	}
	if ($first !== null) {
		$step = $by_hour ? '+1 hour' : '+1 day';
		$last = $by_hour ? strtotime(date('Y-m-d H:00:00')) : strtotime(date('Y-m-d'));
		for ($at = $first; $at <= $last; $at = strtotime($step, $at)) {
			$buckets[date($by_hour ? 'Y-m-d H' : 'Y-m-d', $at)] = [
				'label' => date($by_hour ? 'g A' : 'D, M j', $at),
				SiteVersion::CLASSIC => 0,
				SiteVersion::REDESIGN => 0,
			];
		}
		foreach ($rows as $row) {
			if (isset($buckets[$row->bucket][$row->version])) {
				$buckets[$row->bucket][$row->version] = (int) $row->views;
			}
		}
	}

	// Top pages
	$top_pages = $views()
		->selectRaw('path, version, COUNT(*) AS views, COUNT(DISTINCT er_user_id) AS players, ROUND(AVG(duration_ms)) AS avg_ms')
		->groupBy('path', 'version')
		->orderBy('views', 'DESC')
		->orderBy('path', 'ASC')
		->limit(25)
		->get();

	// Everyone's choice, all time: the site_version cookie as their latest
	// request carried it. Without a choice, the site their latest page view
	// was on (admin pages are classic only, so they do not count).
	$latest_ids = DB::table('er_site_hits')
		->whereNotNull('er_user_id')
		->groupBy('er_user_id')
		->selectRaw('MAX(id) AS id')
		->pluck('id')
		->all();
	foreach (array_chunk($latest_ids, 500) as $ids) {
		foreach (DB::table('er_site_hits')->whereIn('id', $ids)->get(['er_user_id', 'preference', 'created_at']) as $row) {
			$choices[(int) $row->er_user_id] = [
				'preference' => $row->preference,
				'uses' => null,
				'last_seen' => $row->created_at,
			];
		}
	}
	$latest_view_ids = DB::table('er_site_hits')
		->whereNotNull('er_user_id')
		->where('method', '=', 'GET')
		->whereBetween('status', [200, 299])
		->where('path', 'NOT LIKE', 'admin/%')
		->groupBy('er_user_id')
		->selectRaw('MAX(id) AS id')
		->pluck('id')
		->all();
	foreach (array_chunk($latest_view_ids, 500) as $ids) {
		foreach (DB::table('er_site_hits')->whereIn('id', $ids)->get(['er_user_id', 'version']) as $row) {
			if (isset($choices[(int) $row->er_user_id])) {
				$choices[(int) $row->er_user_id]['uses'] = $row->version;
			}
		}
	}
	foreach ($choices as $choice) {
		$choice_counts[$choice['preference'] ?: 'none']++;
	}

	// Players seen in the range
	$rows = $views()
		->whereNotNull('er_user_id')
		->selectRaw('er_user_id, COUNT(*) AS views, SUM(version = ?) AS classic, SUM(version = ?) AS redesign, MAX(created_at) AS last_seen', [SiteVersion::CLASSIC, SiteVersion::REDESIGN])
		->groupBy('er_user_id')
		->orderBy('last_seen', 'DESC')
		->get();
	foreach ($rows as $row) {
		$players[(int) $row->er_user_id] = $row;
	}

	// The log
	$log = $show_all ? $filtered() : $views();
	$paging->setTotal($log->count());
	$hits = $log
		->orderBy('id', 'DESC')
		->offset($paging->getOffset())
		->limit($paging->getLimit())
		->get();

	// Names
	$user_ids = array_keys($players + $choices);
	foreach ($hits as $hit) {
		if ($hit->er_user_id) {
			$user_ids[] = (int) $hit->er_user_id;
		}
	}
	if ($user_id) {
		$user_ids[] = $user_id;
	}
	foreach (array_chunk(array_unique($user_ids), 500) as $ids) {
		foreach (User::whereIn('id', $ids)->get() as $user) {
			$users[(int) $user->id] = $user;
		}
	}
}
catch (\Exception $e) {
	$error = $e->getMessage();
}

$user_name = function ($id) use ($users) {
	if (!$id) {
		return 'Guest';
	}
	return isset($users[$id]) && strlen($users[$id]->getName()) ? $users[$id]->getName() : 'User #' . $id;
};

$choice_label = function ($id) use ($choices) {
	if (!isset($choices[$id])) {
		return '<span class="text-muted">&mdash;</span>';
	}
	$choice = $choices[$id];
	if ($choice['preference']) {
		return traffic_version_badge($choice['preference']);
	}
	$uses = isset(TRAFFIC_VERSIONS[$choice['uses']]) ? ' (on ' . h(TRAFFIC_VERSIONS[$choice['uses']]) . ')' : '';
	return '<span class="text-muted">No choice' . $uses . '</span>';
};

$chosen = $choice_counts[SiteVersion::REDESIGN] + $choice_counts[SiteVersion::CLASSIC];
$chart = [
	'labels' => array_column($buckets, 'label'),
	'classic' => array_column($buckets, SiteVersion::CLASSIC),
	'redesign' => array_column($buckets, SiteVersion::REDESIGN),
];

ob_start();
?>
<style>
	/* Series colours follow the site, whatever the filter: classic blue, 2.0 orange */
	.traffic { --series-classic: #2a78d6; --series-r: #eb6834; }
	.traffic-key { display: inline-block; width: 10px; height: 10px; border-radius: 2px; margin-right: 6px; }
	.traffic-key-classic { background: var(--series-classic); }
	.traffic-key-r { background: var(--series-r); }
	.traffic-tile .value { font-size: 1.75rem; font-weight: 600; line-height: 1.1; }
	.traffic-tile .label { color: #52514e; font-size: .875rem; }
	.traffic-split { display: flex; height: 12px; gap: 2px; }
	.traffic-split span { display: block; min-width: 2px; border-radius: 2px; }
	.traffic-chart { position: relative; height: 280px; }
	.traffic table .num { text-align: right; font-variant-numeric: tabular-nums; }
	.traffic-url { word-break: break-all; }
</style>
<?php
$page->setStyles(ob_get_clean());

ob_start();
?>
<div class="container py-4 traffic">
	<?php if ($error): ?>
		<div class="alert alert-danger">
			The traffic table could not be read. Has <code>db/changes/2026-09-28-site-hits.sql</code> been applied?
			<div class="small text-muted mt-1"><?=h($error)?></div>
		</div>
	<?php endif; ?>

	<div class="card mb-3">
		<h4 class="card-header">Site Traffic</h4>
		<div class="card-body py-2">
			<form action="" method="get" class="d-flex flex-wrap align-items-end gap-2">
				<div>
					<label for="days" class="form-label small mb-0">Dates</label>
					<select id="days" name="days" class="form-select form-select-sm" onchange="this.form.submit()">
						<?php foreach (TRAFFIC_RANGES as $key => $label): ?>
							<option <?=sel((string) $key, $range)?> value="<?=$key?>"><?=$label?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div>
					<label for="version" class="form-label small mb-0">Site</label>
					<select id="version" name="version" class="form-select form-select-sm" onchange="this.form.submit()">
						<option value="">Both</option>
						<?php foreach (TRAFFIC_VERSIONS as $key => $label): ?>
							<option <?=sel($key, $version)?> value="<?=$key?>"><?=$label?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div>
					<label for="path" class="form-label small mb-0">Page contains</label>
					<input id="path" name="path" type="text" class="form-control form-control-sm" value="<?=h($path)?>" placeholder="season/week">
				</div>
				<div>
					<label for="show" class="form-label small mb-0">Log shows</label>
					<select id="show" name="show" class="form-select form-select-sm" onchange="this.form.submit()">
						<option value="">Page views</option>
						<option <?=selb($show_all)?> value="all">Every request</option>
					</select>
				</div>
				<?php if ($user_id !== null): ?>
					<input type="hidden" name="user_id" value="<?=$user_id?>">
				<?php endif; ?>
				<button type="submit" class="btn btn-sm btn-primary">Apply</button>
				<?php if ($user_id !== null || $version || $path !== '' || $show_all): ?>
					<a class="btn btn-sm btn-outline-secondary" href="?days=<?=h($range)?>">Clear filters</a>
				<?php endif; ?>
			</form>
			<?php if ($user_id !== null): ?>
				<div class="mt-2">
					<span class="badge bg-secondary">Player: <?=h($user_name($user_id))?></span>
					<a class="small" href="<?=traffic_link(['user_id' => null])?>">show everyone</a>
				</div>
			<?php endif; ?>
		</div>
	</div>

	<div class="row g-3 mb-3">
		<?php foreach ([
			['Page views', $totals['views']],
			['Players seen', $totals['players']],
			['Classic views', $totals[SiteVersion::CLASSIC]],
			['Version 2.0 views', $totals[SiteVersion::REDESIGN]],
			['Signed-out views', $totals['guest']],
		] as $tile): ?>
			<div class="col-6 col-md">
				<div class="card h-100 traffic-tile">
					<div class="card-body py-3">
						<div class="label"><?=$tile[0]?></div>
						<div class="value"><?=number_format($tile[1])?></div>
					</div>
				</div>
			</div>
		<?php endforeach; ?>
	</div>

	<div class="card mb-3">
		<h5 class="card-header">Which site players have chosen</h5>
		<div class="card-body">
			<?php if (!sizeof($choices)): ?>
				<div class="text-muted">No signed-in player has been seen yet.</div>
			<?php else: ?>
				<div class="row g-3 align-items-center">
					<?php foreach ([
						[SiteVersion::REDESIGN, 'Chose Version 2.0'],
						[SiteVersion::CLASSIC, 'Chose Classic'],
						['none', 'No choice yet'],
					] as $item): ?>
						<div class="col-4 col-md-2 traffic-tile">
							<div class="label"><?=isset(TRAFFIC_VERSIONS[$item[0]]) ? '<span class="traffic-key traffic-key-' . $item[0] . '"></span>' : ''?><?=$item[1]?></div>
							<div class="value"><?=number_format($choice_counts[$item[0]])?></div>
						</div>
					<?php endforeach; ?>
					<div class="col-12 col-md-6">
						<?php if ($chosen): ?>
							<div class="traffic-split" role="img" aria-label="<?=$choice_counts[SiteVersion::REDESIGN]?> chose Version 2.0, <?=$choice_counts[SiteVersion::CLASSIC]?> chose Classic">
								<?php foreach ([SiteVersion::REDESIGN, SiteVersion::CLASSIC] as $key): ?>
									<?php if ($choice_counts[$key]): ?>
										<span class="traffic-key-<?=$key?>" style="width: <?=round(100 * $choice_counts[$key] / $chosen, 1)?>%" title="<?=h(TRAFFIC_VERSIONS[$key])?>: <?=$choice_counts[$key]?>"></span>
									<?php endif; ?>
								<?php endforeach; ?>
							</div>
							<div class="small text-muted mt-1">
								<?=round(100 * $choice_counts[SiteVersion::REDESIGN] / $chosen)?>% of the <?=number_format($chosen)?> who chose picked Version 2.0.
							</div>
						<?php endif; ?>
						<div class="small text-muted">
							All time, <?=number_format(sizeof($choices))?> players. A choice is made by following a "Version 2.0" or "Classic site" link, and is kept per browser; a player's latest request decides.
						</div>
					</div>
				</div>
			<?php endif; ?>
		</div>
	</div>

	<div class="card mb-3">
		<h5 class="card-header">Page views by <?=$by_hour ? 'hour' : 'day'?></h5>
		<div class="card-body">
			<?php if (!$totals['views']): ?>
				<div class="text-muted">No page views in this range.</div>
			<?php else: ?>
				<div class="traffic-chart"><canvas id="traffic-chart" aria-label="Page views by <?=$by_hour ? 'hour' : 'day'?>, classic and Version 2.0 stacked"></canvas></div>
				<details class="mt-2">
					<summary class="small text-muted">Show as a table</summary>
					<div class="table-responsive mt-2">
						<table class="table table-sm mb-0">
							<thead>
								<tr>
									<th><?=$by_hour ? 'Hour' : 'Day'?></th>
									<th class="num">Classic</th>
									<th class="num">Version 2.0</th>
									<th class="num">Total</th>
								</tr>
							</thead>
							<tbody>
								<?php foreach (array_reverse($buckets) as $bucket): ?>
									<tr>
										<td><?=h($bucket['label'])?></td>
										<td class="num"><?=number_format($bucket[SiteVersion::CLASSIC])?></td>
										<td class="num"><?=number_format($bucket[SiteVersion::REDESIGN])?></td>
										<td class="num"><?=number_format($bucket[SiteVersion::CLASSIC] + $bucket[SiteVersion::REDESIGN])?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				</details>
			<?php endif; ?>
		</div>
	</div>

	<div class="row g-3 mb-3">
		<div class="col-lg-6">
			<div class="card h-100">
				<h5 class="card-header">Players <span class="text-muted small">(<?=sizeof($players)?>)</span></h5>
				<div class="table-responsive" style="max-height: 480px">
					<table class="table table-sm table-striped mb-0">
						<thead>
							<tr>
								<th>Player</th>
								<th>Chosen site</th>
								<th class="num">Classic</th>
								<th class="num">2.0</th>
								<th>Last seen</th>
							</tr>
						</thead>
						<tbody>
							<?php if (!sizeof($players)): ?>
								<tr><td colspan="5" class="text-center text-muted py-3">Nobody in this range.</td></tr>
							<?php endif; ?>
							<?php foreach ($players as $id => $row): ?>
								<tr>
									<td><a href="<?=traffic_link(['user_id' => $id])?>"><?=h($user_name($id))?></a></td>
									<td class="text-nowrap"><?=$choice_label($id)?></td>
									<td class="num"><?=number_format($row->classic)?></td>
									<td class="num"><?=number_format($row->redesign)?></td>
									<td class="text-nowrap small" title="<?=h($row->last_seen)?>"><?=h(ago($row->last_seen, 1))?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</div>
		</div>
		<div class="col-lg-6">
			<div class="card h-100">
				<h5 class="card-header">Top pages</h5>
				<div class="table-responsive" style="max-height: 480px">
					<table class="table table-sm table-striped mb-0">
						<thead>
							<tr>
								<th>Page</th>
								<th>Site</th>
								<th class="num">Views</th>
								<th class="num">Players</th>
								<th class="num" title="Average time to build the page">Avg ms</th>
							</tr>
						</thead>
						<tbody>
							<?php if (!sizeof($top_pages)): ?>
								<tr><td colspan="5" class="text-center text-muted py-3">No page views in this range.</td></tr>
							<?php endif; ?>
							<?php foreach ($top_pages as $row): ?>
								<tr>
									<td class="traffic-url"><a href="<?=traffic_link(['path' => $row->path, 'version' => $row->version])?>"><?=h($row->path)?></a></td>
									<td class="text-nowrap"><?=traffic_version_badge($row->version)?></td>
									<td class="num"><?=number_format($row->views)?></td>
									<td class="num"><?=number_format($row->players)?></td>
									<td class="num"><?=$row->avg_ms === null ? '' : number_format($row->avg_ms)?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</div>
		</div>
	</div>

	<div class="card">
		<h5 class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
			<span><?=$show_all ? 'Every request' : 'Page views'?> <span class="text-muted small">(<?=number_format((int) $paging->total)?>)</span></span>
			<?=$paging->total > $paging->per ? $paging->render(['edge_type' => 'icon']) : ''?>
		</h5>
		<div class="table-responsive">
			<table class="table table-sm table-striped mb-0 small">
				<thead>
					<tr>
						<th>When</th>
						<th>Player</th>
						<th>Site</th>
						<th>Chosen</th>
						<th>Page</th>
						<th class="num">Status</th>
						<th class="num">ms</th>
						<th>Device</th>
						<th>IP</th>
					</tr>
				</thead>
				<tbody>
					<?php if (!sizeof($hits)): ?>
						<tr><td colspan="9" class="text-center text-muted py-3">Nothing recorded for these filters.</td></tr>
					<?php endif; ?>
					<?php foreach ($hits as $hit): ?>
						<tr>
							<td class="text-nowrap" title="<?=h(ago($hit->created_at, 1))?>"><?=h(date('D M j, g:i:s A', strtotime($hit->created_at)))?></td>
							<td class="text-nowrap">
								<?php if ($hit->er_user_id): ?>
									<a href="<?=traffic_link(['user_id' => (int) $hit->er_user_id])?>"><?=h($user_name((int) $hit->er_user_id))?></a>
								<?php else: ?>
									<a class="text-muted" href="<?=traffic_link(['user_id' => 0])?>">Guest</a>
								<?php endif; ?>
							</td>
							<td class="text-nowrap"><?=traffic_version_badge($hit->version)?></td>
							<td class="text-nowrap"><?=traffic_version_badge($hit->preference)?></td>
							<td class="traffic-url" title="<?=h($hit->referrer ? 'From ' . $hit->referrer : '')?>">
								<?php if ($hit->method !== 'GET'): ?>
									<span class="badge bg-secondary"><?=h($hit->method)?></span>
								<?php endif; ?>
								<?=h($hit->path)?><?=strlen($hit->query) ? '<span class="text-muted">?' . h($hit->query) . '</span>' : ''?>
								<?php if ($hit->is_partial): ?>
									<span class="text-muted" title="Loaded in place by the Version 2.0 navigation">&#8635;</span>
								<?php endif; ?>
							</td>
							<td class="num"><?=(int) $hit->status?></td>
							<td class="num"><?=$hit->duration_ms === null ? '' : number_format($hit->duration_ms)?></td>
							<td title="<?=h($hit->user_agent)?>"><?=h(Traffic::device($hit->user_agent))?></td>
							<td><?=h($hit->ip)?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php if ($paging->total > $paging->per): ?>
			<div class="card-footer d-flex justify-content-end"><?=$paging->render(['edge_type' => 'icon'])?></div>
		<?php endif; ?>
	</div>
</div>
<?php
$page->setContent(ob_get_clean());

ob_start();
?>
<script>
(function () {
	var canvas = document.getElementById('traffic-chart');
	if (!canvas || !window.Chart) {
		return;
	}
	var data = <?=json_encode($chart, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)?>;
	var surface = '#ffffff';
	var series = function (label, values, color, top) {
		return {
			label: label,
			data: values,
			backgroundColor: color,
			hoverBackgroundColor: color,
			// A 2px gap in the surface colour separates the stacked segments
			borderColor: surface,
			borderWidth: top ? { top: 0, right: 0, bottom: 2, left: 0 } : 0,
			borderRadius: top ? { topLeft: 4, topRight: 4 } : 0,
			borderSkipped: false,
			maxBarThickness: 24
		};
	};
	new Chart(canvas, {
		type: 'bar',
		data: {
			labels: data.labels,
			datasets: [
				series('Classic', data.classic, '#2a78d6', false),
				series('Version 2.0', data.redesign, '#eb6834', true)
			]
		},
		options: {
			maintainAspectRatio: false,
			interaction: { mode: 'index', intersect: false },
			plugins: {
				legend: {
					position: 'top',
					align: 'start',
					labels: { color: '#52514e', boxWidth: 10, boxHeight: 10 }
				},
				tooltip: {
					callbacks: {
						footer: function (items) {
							var total = items.reduce(function (sum, item) {
								return sum + item.parsed.y;
							}, 0);
							return 'Total: ' + total.toLocaleString();
						}
					}
				}
			},
			scales: {
				x: {
					stacked: true,
					grid: { display: false, borderColor: '#c3c2b7' },
					ticks: { color: '#898781', maxRotation: 0, autoSkip: true, autoSkipPadding: 12 }
				},
				y: {
					stacked: true,
					beginAtZero: true,
					grid: { color: '#e1e0d9', drawBorder: false },
					ticks: { color: '#898781', precision: 0 }
				}
			}
		}
	});
})();
</script>
<?php
$page->setScripts(ob_get_clean());
print $page->render();
