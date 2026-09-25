<?php

/**
 * Games from Scrape: lists the newest VegasInsider scrape per league with a checkbox per
 * spread and per total, and creates the ticked ones as football_games rows in the chosen week.
 * See docs/odds-scraper.md.
 */

require_once __DIR__ . '/../../../inc/_inc.php';

use Pick55\Alert;
use Pick55\Auth;
use Pick55\Page;
use Pick55\VegasInsider;
use Pick55\Models\Game;
use Pick55\Models\Team;
use Pick55\Models\Week;

Auth::guardAdmin();

$week = Week::find(get('week_id', get('id')));
if (!$week) {
	redir('admin/weeks/index.php');
}

$page = new Page;
$page->setTitle('Games from Scrape - Week #' . $week->id . ' - Weeks - Admin');
$page->options['admin_bar']['show'] = true;
$page->options['admin_bar']['title'] = 'Week #' . $week->id;
$page->options['admin_bar']['sub_bar']['type'] = 'week';
$page->options['admin_bar']['sub_bar']['obj'] = $week;

$self = 'admin/weeks/week/bulk-games.php?week_id=' . $week->id;

$season_weeks = Week::where('football_season_id', '=', $week->football_season_id)
	->orderBy('week_num', 'ASC')
	->get();

// Teams keyed by league then VegasInsider slug.
$teams_by_slug = [];
foreach (Team::all() as $team) {
	if ($team->vegas_insider_url) {
		$teams_by_slug[$team->type][strtolower($team->vegas_insider_url)] = $team;
	}
}

// Games already in this week, keyed by away:home:bet_type so scrape rows can be flagged.
$existing_games = $week->games()
	->orderBy('date', 'ASC')
	->orderBy('time', 'ASC')
	->get();
$existing_by_key = [];
foreach ($existing_games as $game) {
	$existing_by_key[$game->away_team_id . ':' . $game->home_team_id . ':' . $game->bet_type] = $game;
}

/**
 * The newest scrape per league, each game annotated with its matched Team rows and
 * the ids of games already created from it in this week.
 *
 * @return array league => scrape or null
 */
function load_scrapes() {
	global $teams_by_slug, $existing_by_key;
	$scrapes = [];
	foreach (array_keys(VegasInsider::getUrls()) as $league) {
		$scrape = VegasInsider::newest($league);
		if ($scrape) {
			foreach ($scrape['games'] as $i => $g) {
				$away = isset($teams_by_slug[$league][$g['away_team']]) ? $teams_by_slug[$league][$g['away_team']] : null;
				$home = isset($teams_by_slug[$league][$g['home_team']]) ? $teams_by_slug[$league][$g['home_team']] : null;
				$g['away'] = $away;
				$g['home'] = $home;
				$g['matched'] = $away && $home;
				$g['kickoff_ts'] = strtotime($g['date'] . ' ' . $g['time']);
				$g['existing'] = [];
				if ($g['matched']) {
					foreach (Game::getPickTypes() as $bet_type) {
						$key = $away->id . ':' . $home->id . ':' . $bet_type;
						if (isset($existing_by_key[$key])) {
							$g['existing'][$bet_type] = $existing_by_key[$key];
						}
					}
				}
				$scrape['games'][$i] = $g;
			}
		}
		$scrapes[$league] = $scrape;
	}
	return $scrapes;
}

$scrapes = load_scrapes();

/**
 * The short label used inside option text: NFL games use the nickname ("Bills"),
 * NCAA games the school ("Oklahoma"), matching how games have been entered by hand.
 *
 * @param Team $team
 * @return string
 */
function option_label(Team $team) {
	return $team->type == Game::LEAGUE_NFL ? $team->nickname : $team->team;
}

/**
 * @param float $value
 * @return string "+3.5" / "-3.5"
 */
function signed($value) {
	return ($value > 0 ? '+' : '') . number_format($value, 1);
}

if (is_post()) {
	try {
		if (post('action') == 'create') {
			// Refuse if a scrape changed under the admin between render and submit.
			foreach ($scrapes as $league => $scrape) {
				$posted = post('stamp_' . $league);
				if ($posted !== null && $posted !== '' && (!$scrape || (string) $scrape['ts'] !== (string) $posted)) {
					throw new Exception("The " . $league . " scrape changed since this page loaded. Nothing was created; tick the games again.");
				}
			}
			$picks = isset($_POST['pick']) && is_array($_POST['pick']) ? $_POST['pick'] : [];
			$created = 0;
			$skipped = [];
			foreach ($picks as $pick) {
				if (!preg_match('/^(NFL|NCAA):(\d+):(spread|over-under)$/', $pick, $m)) {
					continue;
				}
				$league = $m[1];
				$index = (int) $m[2];
				$bet_type = $m[3];
				if (!$scrapes[$league] || !isset($scrapes[$league]['games'][$index])) {
					$skipped[] = $pick . ' (not in the scrape)';
					continue;
				}
				$g = $scrapes[$league]['games'][$index];
				$label = $g['away_name'] . ' @ ' . $g['home_name'] . ' ' . $bet_type;
				if (!$g['matched']) {
					$skipped[] = $label . ' (team not matched)';
					continue;
				}
				if ($g[$bet_type] === null) {
					$skipped[] = $label . ' (no line)';
					continue;
				}
				if (isset($g['existing'][$bet_type])) {
					$skipped[] = $label . ' (already game #' . $g['existing'][$bet_type]->id . ')';
					continue;
				}
				$away = $g['away'];
				$home = $g['home'];
				$value = round((float) $g[$bet_type], 1);
				if ($bet_type == Game::BET_TYPE_SPREAD) {
					$option_1 = option_label($away) . ' (' . signed($value) . ')';
					$option_2 = option_label($home) . ' (' . signed(-1 * $value) . ')';
				}
				else {
					$option_1 = 'OVER (' . number_format($value, 1) . ')';
					$option_2 = 'UNDER (' . number_format($value, 1) . ')';
				}
				$game = Game::create([
					'football_week_id' => $week->id,
					'type' => $league,
					'away_team_id' => $away->id,
					'home_team_id' => $home->id,
					'title' => $away->getName() . ' @ ' . $home->getName(),
					'date' => $g['date'],
					'time' => $g['time'],
					'bet_type' => $bet_type,
					'value' => $value,
					'option_1' => $option_1,
					'option_2' => $option_2,
				]);
				$existing_by_key[$away->id . ':' . $home->id . ':' . $bet_type] = $game;
				$created++;
			}
			if ($created) {
				Alert::success("Created " . $created . " game" . ($created == 1 ? '' : 's') . " in Week " . $week->week_num . ".");
			}
			else {
				Alert::warning("No games were created. Tick at least one spread or total first.");
			}
			if (sizeof($skipped)) {
				Alert::warning("Skipped: <ul class=\"mb-0\"><li>" . implode('</li><li>', array_map('h', $skipped)) . "</li></ul>");
			}
		}
		elseif (post('action') == 'scrape') {
			foreach (array_keys(VegasInsider::getUrls()) as $league) {
				try {
					$result = VegasInsider::scrape($league);
					Alert::success("Scraped " . $league . ": " . sizeof($result['games']) . " games.");
				}
				catch (Exception $e) {
					Alert::error("Scraping " . $league . " failed: " . h($e->getMessage()));
				}
			}
		}
	}
	catch (Exception $e) {
		Alert::error($e->getMessage());
	}
	redir($self);
}

ob_start();
?>
<script>
$(document).ready(function () {
	function update_count() {
		var n = $('input[name="pick[]"]:checked').length;
		$('.pick-count').text(n);
		$('.action-create').prop('disabled', n == 0);
	}
	$('body').on('change', 'input[name="pick[]"]', update_count);
	$('body').on('change', '.ck-all', function () {
		var checked = $(this).prop('checked');
		$('input[name="pick[]"].' + $(this).data('target') + ':not(:disabled)').prop('checked', checked);
		update_count();
	});
	$('body').on('click', '.action-scrape', function () {
		$(this).prop('disabled', true).text('Scraping…');
		$(this).closest('form').submit();
	});
	update_count();
});
</script>
<?php
$page->setScripts(ob_get_clean());

/**
 * @param array $g annotated scrape game
 * @param string $side 'away' or 'home'
 * @return string
 */
function render_team_cell(array $g, $side) {
	$team = $g[$side];
	$name = $g[$side . '_name'];
	$slug = $g[$side . '_team'];
	ob_start();
	if ($team) {
		?>
		<span class="text-success" title="matched football_teams #<?=$team->id?> by slug '<?=h($slug)?>'"><i class="fas fa-check"></i></span>
		<span class="fw-bold"><?=h($team->getName())?></span>
		<?php
	}
	else {
		?>
		<span class="text-danger" title="no football_teams row has this slug"><i class="fas fa-exclamation-triangle"></i></span>
		<?=h($name)?>
		<a class="badge bg-warning text-dark text-decoration-none" href="<?=config('base_url')?>admin/teams/index.php" title="Set this slug on the team's edit page">slug: <?=h($slug)?></a>
		<?php
	}
	return ob_get_clean();
}

/**
 * @param array $g annotated scrape game
 * @param string $league
 * @param int $index
 * @param string $bet_type
 * @return string
 */
function render_pick_cell(array $g, $league, $index, $bet_type) {
	$value = $g[$bet_type];
	ob_start();
	if ($value === null) {
		?><span class="text-muted">no line</span><?php
	}
	elseif (isset($g['existing'][$bet_type])) {
		$game = $g['existing'][$bet_type];
		?>
		<span class="text-muted"><?=$bet_type == Game::BET_TYPE_SPREAD ? signed($value) : number_format($value, 1)?></span>
		<a class="badge bg-secondary text-decoration-none" href="<?=config('base_url')?>admin/games/game/index.php?id=<?=$game->id?>" title="already in this week as game #<?=$game->id?> (<?=h($game->value)?>)">in week</a>
		<?php
	}
	elseif (!$g['matched']) {
		?><span class="text-muted"><?=$bet_type == Game::BET_TYPE_SPREAD ? signed($value) : number_format($value, 1)?></span><?php
	}
	else {
		$id = 'pick-' . $league . '-' . $index . '-' . $bet_type;
		?>
		<div class="form-check">
			<input class="form-check-input ck-<?=$league?>-<?=$bet_type?>" type="checkbox" name="pick[]" id="<?=$id?>" value="<?=$league?>:<?=$index?>:<?=$bet_type?>">
			<label class="form-check-label fw-bold" for="<?=$id?>"><?=$bet_type == Game::BET_TYPE_SPREAD ? signed($value) : number_format($value, 1)?></label>
		</div>
		<?php
	}
	return ob_get_clean();
}

ob_start();
?>
<div class="container py-4">
	<div class="card mb-4">
		<h4 class="card-header">Games from Scrape - <?=h($week->season->name)?> - Week <?=$week->week_num?></h4>
		<div class="card-body">
			<div class="row g-3 align-items-end">
				<div class="col-md-6">
					<form action="" method="get">
						<label class="form-label" for="week_id">Create games in</label>
						<div class="input-group">
							<select name="week_id" id="week_id" class="form-select">
								<?php foreach ($season_weeks as $w): ?>
									<option <?=sel($w->id, $week->id)?> value="<?=$w->id?>">Week <?=$w->week_num?><?=$w->picks_due_date ? ' - picks due ' . date("D m/d g:i A", strtotime($w->picks_due_date)) : ''?> (<?=$w->games()->count()?> games)</option>
								<?php endforeach; ?>
							</select>
							<button class="btn btn-outline-primary" type="submit">Switch</button>
						</div>
					</form>
				</div>
				<div class="col-md-6 text-md-end">
					<form action="" method="post">
						<input type="hidden" name="action" value="scrape">
						<button type="button" class="action-scrape btn btn-outline-secondary" title="Fetch both VegasInsider pages now instead of waiting for the Monday cron">Scrape now</button>
					</form>
				</div>
			</div>
			<div class="fst-italic mt-3">
				Tick the spreads and totals to create. Spreads are against the away team: negative means the away team is favored.
				Every line already ends in .5. Kickoffs are Central time.
				A <span class="text-danger"><i class="fas fa-exclamation-triangle"></i></span> team has no <code>football_teams</code> row with that Vegas Insider slug; set the slug on the team's edit page and reload.
			</div>
		</div>
	</div>

	<form action="" method="post" id="create-form">
		<input type="hidden" name="action" value="create">
		<?php foreach ($scrapes as $league => $scrape): ?>
			<input type="hidden" name="stamp_<?=$league?>" value="<?=$scrape ? $scrape['ts'] : ''?>">
			<div class="card mb-4">
				<h4 class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
					<span class="text-<?=strtolower($league)?>"><?=$league?></span>
					<?php if ($scrape): ?>
						<small class="text-muted fs-6">scraped <?=date("D m/d g:i A", $scrape['ts'])?> (<?=ago($scrape['ts'], 1)?>), <?=sizeof($scrape['games'])?> games</small>
					<?php endif; ?>
				</h4>
				<?php if (!$scrape): ?>
					<div class="card-body text-muted">No <?=$league?> scrape on disk yet. Click "Scrape now" or wait for the Monday cron.</div>
				<?php elseif (!sizeof($scrape['games'])): ?>
					<div class="card-body text-muted">The newest <?=$league?> scrape parsed to zero games.</div>
				<?php else: ?>
					<div class="table-responsive">
						<table class="table table-sm table-striped align-middle mb-0">
							<thead>
								<tr>
									<th>Kickoff</th>
									<th class="text-end">Away</th>
									<th class="text-center">@</th>
									<th>Home</th>
									<th class="text-nowrap">
										<div class="form-check">
											<input class="form-check-input ck-all" type="checkbox" id="all-<?=$league?>-spread" data-target="ck-<?=$league?>-<?=Game::BET_TYPE_SPREAD?>">
											<label class="form-check-label text-spread" for="all-<?=$league?>-spread">Spread</label>
										</div>
									</th>
									<th class="text-nowrap">
										<div class="form-check">
											<input class="form-check-input ck-all" type="checkbox" id="all-<?=$league?>-ou" data-target="ck-<?=$league?>-<?=Game::BET_TYPE_OVER_UNDER?>">
											<label class="form-check-label text-ou" for="all-<?=$league?>-ou">O/U</label>
										</div>
									</th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ($scrape['games'] as $index => $g): ?>
									<tr class="<?=$g['kickoff_ts'] && $g['kickoff_ts'] < time() ? 'text-muted' : ''?>">
										<td class="text-nowrap" title="<?=h($g['kickoff_utc'])?> UTC">
											<?=$g['kickoff_ts'] ? date("D m/d g:i A", $g['kickoff_ts']) : '?'?>
											<?php if ($g['kickoff_ts'] && $g['kickoff_ts'] < time()): ?><span class="badge bg-light text-dark">started</span><?php endif; ?>
										</td>
										<td class="text-end"><?=render_team_cell($g, 'away')?></td>
										<td class="text-center">@</td>
										<td><?=render_team_cell($g, 'home')?></td>
										<td class="text-nowrap"><?=render_pick_cell($g, $league, $index, Game::BET_TYPE_SPREAD)?></td>
										<td class="text-nowrap"><?=render_pick_cell($g, $league, $index, Game::BET_TYPE_OVER_UNDER)?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php endif; ?>
			</div>
		<?php endforeach; ?>
		<div class="card mb-4">
			<div class="card-body d-flex justify-content-between align-items-center flex-wrap gap-2">
				<span>Week <?=$week->week_num?> has <?=sizeof($existing_games)?> game<?=sizeof($existing_games) == 1 ? '' : 's'?> now.</span>
				<button type="submit" class="action-create btn btn-primary" disabled>Create <span class="pick-count">0</span> ticked games in Week <?=$week->week_num?></button>
			</div>
		</div>
	</form>

	<?php if (sizeof($existing_games)): ?>
		<div class="card">
			<h4 class="card-header">Games in Week <?=$week->week_num?></h4>
			<div class="table-responsive">
				<table class="table table-sm table-striped align-middle mb-0">
					<thead>
						<tr>
							<th>ID</th>
							<th>League</th>
							<th>Kickoff</th>
							<th>Game</th>
							<th>Type</th>
							<th class="text-end">Value</th>
							<th>Options</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($existing_games as $game): ?>
							<tr>
								<td><a href="<?=config('base_url')?>admin/games/game/index.php?id=<?=$game->id?>"><?=$game->id?></a></td>
								<td class="text-<?=strtolower($game->type)?>"><?=$game->type?></td>
								<td class="text-nowrap"><?=date("D m/d g:i A", strtotime($game->date . ' ' . $game->time))?></td>
								<td><?=h($game->title)?></td>
								<td class="<?=$game->bet_type == Game::BET_TYPE_SPREAD ? 'text-spread' : 'text-ou'?>"><?=$game->bet_type?></td>
								<td class="text-end"><?=$game->value?></td>
								<td><?=h($game->option_1)?> / <?=h($game->option_2)?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
	<?php endif; ?>
</div>
<?php
$page->setContent(ob_get_clean());
print $page->render();
