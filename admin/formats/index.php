<?php

require_once __DIR__ . '/../../inc/_inc.php';

use Pick55\Auth;
use Pick55\Page;
use Pick55\Models\Season;
use Pick55\Models\WeekFormat;
use Pick55\Models\WeekFormatPayout;
use Pick55\Snippets\WeekFormatPayouts;

Auth::guardAdmin();

$page = new Page;
$page->setTitle('Formats - Admin');
$page->options['admin_bar']['show'] = true;
$page->options['admin_bar']['title'] = 'Formats';
$page->options['admin_bar']['sub_bar']['type'] = 'formats';

// The league size to filter on: ?num_players=N, ?num_players=all, or
// (default) the active season's size.
$active_season = Season::getActive();
$active_size = $active_season ? (int) $active_season->getNumPlayers() : 0;
$filter = get('num_players');
if ($filter === null) {
	$num_players = $active_size ?: null;
}
elseif ($filter === 'all' || !intval($filter)) {
	$num_players = null;
}
else {
	$num_players = intval($filter);
}

$sizes = WeekFormat::select('num_players')
	->distinct()
	->orderBy('num_players', 'DESC')
	->pluck('num_players')
	->all();

$q = WeekFormat::with(['payouts', 'weeks.season'])
	->orderBy('is_playoffs', 'ASC')
	->orderBy('num_players', 'DESC')
	->orderBy('name', 'ASC');
if ($num_players) {
	$q->where('num_players', '=', $num_players);
}
$formats = $q->get();

ob_start();
?>
<div class="container py-4">
	<div class="card">
		<h4 class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
			<span>Week Formats<?=$num_players ? ' for ' . $num_players . ' players' : ''?></span>
			<a class="btn btn-sm btn-success" href="format/index.php?num_players=<?=$num_players ?: $active_size?>">New format</a>
		</h4>
		<div class="card-body py-2">
			<form action="" method="get" class="d-flex flex-wrap align-items-center gap-2">
				<label for="num_players" class="form-label mb-0">League size</label>
				<select id="num_players" name="num_players" class="form-select form-select-sm w-auto" onchange="this.form.submit()">
					<option value="all" <?=$num_players ? '' : 'selected'?>>All sizes</option>
					<?php foreach ($sizes as $size): ?>
						<option <?=sel($size, $num_players)?> value="<?=$size?>"><?=$size?> players<?=$size == $active_size ? ' (current)' : ''?></option>
					<?php endforeach; ?>
				</select>
				<?php if ($num_players): ?>
					<a class="btn btn-sm btn-outline-secondary" href="?num_players=all">All sizes</a>
				<?php endif; ?>
				<?php if ($active_size && $num_players != $active_size): ?>
					<a class="btn btn-sm btn-outline-secondary" href="?num_players=<?=$active_size?>">Current size (<?=$active_size?>)</a>
				<?php endif; ?>
			</form>
		</div>
		<div class="table-responsive">
			<table class="table table-sm table-striped mb-0">
				<thead>
					<tr>
						<th class="text-end">ID</th>
						<th>Name</th>
						<th class="text-end">Players</th>
						<th class="text-center">Pools</th>
						<th class="text-center">Playoffs</th>
						<th>Payouts</th>
						<th class="text-end">Total</th>
						<th>Weeks using it</th>
					</tr>
				</thead>
				<tbody>
					<?php if (!$formats->count()): ?>
						<tr>
							<td colspan="8" class="text-center text-muted py-3">No formats<?=$num_players ? ' for ' . $num_players . ' players' : ''?>.</td>
						</tr>
					<?php endif; ?>
					<?php foreach ($formats as $format): ?>
						<?php
						$weeks_by_season = [];
						foreach ($format->weeks as $week) {
							$season_name = $week->season ? $week->season->name : 'Season #' . $week->football_season_id;
							$weeks_by_season[$season_name][] = $week;
						}
						?>
						<tr>
							<td class="text-end"><?=$format->id?></td>
							<td>
								<a class="fw-bold" href="format/index.php?id=<?=$format->id?>"><?=h($format->name)?></a>
								<?php if (strlen($format->description_long)): ?>
									<div class="small text-muted"><?=h($format->description_long)?></div>
								<?php endif; ?>
							</td>
							<td class="text-end"><?=$format->num_players?></td>
							<td class="text-center text-nowrap">
								<?php if ($format->hasPools()): ?>
									<?=$format->is_teams ? 'Teams: ' : ''?><?=$format->num_pools?>
									<span class="text-muted small">(~<?=$format->getPlayersPerPool()?> each)</span>
								<?php else: ?>
									<span class="text-muted">&mdash;</span>
								<?php endif; ?>
							</td>
							<td class="text-center text-nowrap">
								<?php if ($format->is_playoffs): ?>
									<span class="badge bg-warning text-dark">Playoffs</span>
									<?php if ($format->advance): ?>
										<div class="small text-muted">top <?=$format->advance?> advance</div>
									<?php endif; ?>
								<?php endif; ?>
							</td>
							<td class="small"><?=WeekFormatPayouts::b($format, true)?></td>
							<td class="text-end text-nowrap">$<?=WeekFormatPayout::money($format->total_payout)?></td>
							<td class="small">
								<?php if (!sizeof($weeks_by_season)): ?>
									<span class="text-muted">0</span>
								<?php else: ?>
									<span class="fw-bold"><?=$format->weeks->count()?></span>
									<?php foreach ($weeks_by_season as $season_name => $weeks): ?>
										<div class="text-nowrap">
											<?=h($season_name)?>:
											<?php foreach ($weeks as $i => $week): ?><?=$i ? ', ' : ''?><a href="../weeks/week/index.php?id=<?=$week->id?>">W<?=$week->week_num?></a><?php endforeach; ?>
										</div>
									<?php endforeach; ?>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>
</div>
<?php
$page->setContent(ob_get_clean());
print $page->render();
