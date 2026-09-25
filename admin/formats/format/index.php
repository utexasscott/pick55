<?php

require_once __DIR__ . '/../../../inc/_inc.php';

use Pick55\DB;
use Pick55\Alert;
use Pick55\Auth;
use Pick55\Page;
use Pick55\Models\Season;
use Pick55\Models\WeekFormat;
use Pick55\Models\WeekFormatPayout;

Auth::guardAdmin();

// ---------------------------------------------------------------------------
// Form helpers
//
// The form is carried around as a plain array ($form) in one shape whether it
// comes from a stored format, from a POST, or from the session stash that a
// failed POST leaves behind (the page redirects after every POST, so the
// submitted values are stashed in $_SESSION[SKEY]['format_form'] and restored
// once on the next GET, the way Alert carries flash messages).
// ---------------------------------------------------------------------------

/**
 * @param int $num_players
 * @return array
 */
function format_form_defaults($num_players)
{
	return [
		'num_players' => $num_players ?: '',
		'name' => '',
		'description_long' => '',
		'is_playoffs' => '0',
		'advance' => '',
		'grouping' => 'one',
		'num_pools' => '',
		'total_payout' => '',
		'overall' => [
			format_form_row(['min_place' => 1, 'max_place' => 1]),
		],
		'pool' => [],
	];
}

/**
 * One payout row of the form, normalized.
 *
 * @param array $r
 * @return array
 */
function format_form_row(array $r)
{
	$row = [
		'pool_num' => '',
		'min_place' => '',
		'max_place' => '',
		'open' => 0,
		'min_points' => '',
		'mode' => 'per',
		'payout' => '',
		'total_payout' => '',
	];
	foreach ($row as $key => $default) {
		if (isset($r[$key]) && is_scalar($r[$key])) {
			$row[$key] = trim((string) $r[$key]);
		}
	}
	$row['open'] = $row['open'] ? 1 : 0;
	if ($row['mode'] != 'split') {
		$row['mode'] = 'per';
	}
	return $row;
}

/**
 * @param array $post
 * @return array
 */
function format_form_from_post(array $post)
{
	$form = format_form_defaults(0);
	foreach (['num_players', 'name', 'description_long', 'is_playoffs', 'advance', 'grouping', 'num_pools', 'total_payout'] as $key) {
		if (isset($post[$key]) && is_scalar($post[$key])) {
			$form[$key] = trim((string) $post[$key]);
		}
	}
	foreach (['overall', 'pool'] as $section) {
		$form[$section] = [];
		if (!empty($post[$section]) && is_array($post[$section])) {
			foreach ($post[$section] as $r) {
				if (is_array($r)) {
					$form[$section][] = format_form_row($r);
				}
			}
		}
	}
	return $form;
}

/**
 * @param WeekFormat $format
 * @return array
 */
function format_form_from_format(WeekFormat $format)
{
	$form = format_form_defaults($format->num_players);
	$form['name'] = (string) $format->name;
	$form['description_long'] = (string) $format->description_long;
	$form['is_playoffs'] = $format->is_playoffs ? '1' : '0';
	$form['advance'] = $format->advance === null ? '' : (int) $format->advance;
	$form['grouping'] = $format->hasPools() ? ($format->is_teams ? 'teams' : 'pools') : 'one';
	$form['num_pools'] = $format->hasPools() ? (int) $format->num_pools : '';
	$form['total_payout'] = (float) $format->total_payout ? (float) $format->total_payout : '';
	$form['overall'] = [];
	$form['pool'] = [];
	foreach ($format->payouts as $p) {
		$split = $p->isSplitPot();
		$row = format_form_row([
			'pool_num' => $p->pool_num === null ? '' : (int) $p->pool_num,
			'min_place' => (int) $p->min_place,
			'max_place' => $p->max_place === null ? '' : (int) $p->max_place,
			'open' => $p->max_place === null ? 1 : 0,
			'min_points' => $p->min_points === null ? '' : (int) $p->min_points,
			'mode' => $split ? 'split' : 'per',
			'payout' => $split ? '' : (float) $p->payout,
			'total_payout' => $split && $p->total_payout !== null ? (int) $p->total_payout : '',
		]);
		$form[$p->place_type == WeekFormatPayout::PLACE_OVERALL ? 'overall' : 'pool'][] = $row;
	}
	return $form;
}

/**
 * Validates the form and turns it into model attributes plus payout rows.
 *
 * @param array $form
 * @return array ['attrs' => array, 'rows' => array]
 * @throws Exception
 */
function format_form_validate(array $form)
{
	$name = trim($form['name']);
	if (!strlen($name)) {
		throw new Exception("Please enter a name.");
	}
	if (strlen($name) > 255 || strlen($form['description_long']) > 255) {
		throw new Exception("The name and description are limited to 255 characters.");
	}
	if (!preg_match('/^\d+$/', $form['num_players']) || (int) $form['num_players'] < 2) {
		throw new Exception("League size must be a whole number of at least 2 players.");
	}
	$num_players = (int) $form['num_players'];

	$grouping = in_array($form['grouping'], ['one', 'pools', 'teams']) ? $form['grouping'] : 'one';
	$num_pools = 0;
	if ($grouping != 'one') {
		if (!preg_match('/^\d+$/', $form['num_pools']) || (int) $form['num_pools'] < 2 || (int) $form['num_pools'] > $num_players) {
			throw new Exception("The number of " . ($grouping == 'teams' ? 'teams' : 'pools') . " must be between 2 and " . $num_players . " (the league size).");
		}
		$num_pools = (int) $form['num_pools'];
	}
	$is_teams = $grouping == 'teams';

	$is_playoffs = $form['is_playoffs'] == '1';
	$advance = null;
	if ($is_playoffs && strlen($form['advance'])) {
		if (!preg_match('/^\d+$/', $form['advance']) || (int) $form['advance'] < 1 || (int) $form['advance'] >= $num_players) {
			throw new Exception("Players advancing must be a whole number between 1 and " . ($num_players - 1) . ", or blank for the money round.");
		}
		$advance = (int) $form['advance'];
	}

	$rows = [];
	foreach ($form['overall'] as $i => $r) {
		$rows[] = format_form_validate_row($r, WeekFormatPayout::PLACE_OVERALL, null, 'Overall row ' . ($i + 1));
	}
	format_form_check_overlap(array_filter($rows, function ($row) {
		return $row['place_type'] == WeekFormatPayout::PLACE_OVERALL;
	}), 'Overall payouts');

	if (sizeof($form['pool']) && $num_pools < 2) {
		throw new Exception("Pool payouts can only be entered when the format has at least 2 pools.");
	}
	$pool_type = $is_teams ? WeekFormatPayout::PLACE_TEAM : WeekFormatPayout::PLACE_POOL;
	$pool_label = $is_teams ? 'Team' : 'Pool';
	$pool_rows_by_num = [];
	foreach ($form['pool'] as $i => $r) {
		$label = $pool_label . ' row ' . ($i + 1);
		$pool_num = null;
		if (strlen($r['pool_num'])) {
			if (!preg_match('/^\d+$/', $r['pool_num']) || (int) $r['pool_num'] < 1 || (int) $r['pool_num'] > $num_pools) {
				throw new Exception($label . ": '" . $r['pool_num'] . "' is not one of the " . $num_pools . " " . strtolower($pool_label) . "s.");
			}
			$pool_num = (int) $r['pool_num'];
		}
		$row = format_form_validate_row($r, $pool_type, $pool_num, $label);
		$rows[] = $row;
		$pool_rows_by_num[$pool_num === null ? 'every' : $pool_num][] = $row;
	}
	foreach ($pool_rows_by_num as $key => $group) {
		format_form_check_overlap($group, $key === 'every' ? 'Every-' . strtolower($pool_label) . ' payouts' : $pool_label . ' ' . $key . ' payouts');
	}

	$total_payout = trim($form['total_payout']);
	if (!strlen($total_payout)) {
		$total_payout = WeekFormat::computeTotalPayout($rows, $num_pools, $is_teams, $num_players);
	}
	elseif (!is_numeric($total_payout) || (float) $total_payout < 0) {
		throw new Exception("Total payout must be a dollar amount of $0 or more, or blank to compute it.");
	}
	$total_payout = round((float) $total_payout, 2);

	return [
		'attrs' => [
			'num_players' => $num_players,
			'total_payout' => $total_payout,
			'name' => $name,
			'description_long' => trim($form['description_long']),
			'is_teams' => $is_teams ? 1 : 0,
			'num_pools' => $num_pools,
			'is_playoffs' => $is_playoffs ? 1 : 0,
			'advance' => $advance,
		],
		'rows' => $rows,
	];
}

/**
 * @param array $r  a normalized form row
 * @param string $place_type
 * @param int|null $pool_num
 * @param string $label  for error messages
 * @return array  attributes of a WeekFormatPayout
 * @throws Exception
 */
function format_form_validate_row(array $r, $place_type, $pool_num, $label)
{
	if (!preg_match('/^\d+$/', $r['min_place']) || (int) $r['min_place'] < 1) {
		throw new Exception($label . ": the first place must be 1 or later.");
	}
	$min_place = (int) $r['min_place'];
	$max_place = null;
	if (!$r['open'] && strlen($r['max_place'])) {
		if (!preg_match('/^\d+$/', $r['max_place']) || (int) $r['max_place'] < $min_place) {
			throw new Exception($label . ": the last place must be at or after the first place (" . ordinal($min_place) . "), or blank for open-ended.");
		}
		$max_place = (int) $r['max_place'];
	}
	$min_points = null;
	if (strlen($r['min_points'])) {
		if (!preg_match('/^\d+$/', $r['min_points']) || (int) $r['min_points'] < 1) {
			throw new Exception($label . ": the points threshold must be a whole number of at least 1, or blank.");
		}
		$min_points = (int) $r['min_points'];
	}
	$payout = null;
	$total_payout = null;
	if ($r['mode'] == 'split') {
		if (!preg_match('/^\d+(\.0+)?$/', $r['total_payout']) || (int) $r['total_payout'] < 1) {
			throw new Exception($label . ": a split pot must be a whole-dollar amount greater than $0.");
		}
		$total_payout = (int) $r['total_payout'];
	}
	else {
		if (!is_numeric($r['payout']) || (float) $r['payout'] < 0) {
			throw new Exception($label . ": enter a per-player payout of $0 or more (or switch the row to a split pot).");
		}
		$payout = round((float) $r['payout'], 2);
	}
	return [
		'place_type' => $place_type,
		'pool_num' => $pool_num,
		'min_place' => $min_place,
		'max_place' => $max_place,
		'min_points' => $min_points,
		'payout' => $payout,
		'total_payout' => $total_payout,
	];
}

/**
 * Rows in one group (overall, every pool, pool N) may not claim the same
 * place twice, and only one of them may be open-ended.
 *
 * @param array $rows
 * @param string $label
 * @throws Exception
 */
function format_form_check_overlap(array $rows, $label)
{
	$rows = array_values($rows);
	usort($rows, function ($a, $b) {
		return $a['min_place'] - $b['min_place'];
	});
	$prev = null;
	$open = 0;
	foreach ($rows as $row) {
		if ($row['max_place'] === null) {
			$open++;
			if ($open > 1) {
				throw new Exception($label . ": only one row can be open-ended (\"and below\").");
			}
		}
		if ($prev && ($prev['max_place'] === null || $prev['max_place'] >= $row['min_place'])) {
			throw new Exception($label . ": the place ranges " . format_row_label($prev) . " and " . format_row_label($row) . " overlap.");
		}
		$prev = $row;
	}
}

/**
 * @param array $row
 * @return string
 */
function format_row_label(array $row)
{
	$p = new WeekFormatPayout($row);
	return $p->getPlaceLabel();
}

/**
 * Replaces the format's payout rows: the new rows go in first, then the old
 * ones are removed by id, so a failure part way through cannot leave the
 * format without rows (the tables are MyISAM, so the transaction around
 * this is a formality).
 *
 * @param WeekFormat $format
 * @param array $rows
 */
function format_save_rows(WeekFormat $format, array $rows)
{
	$old_ids = WeekFormatPayout::where('football_week_format_id', '=', $format->id)
		->pluck('id')
		->all();
	foreach ($rows as $row) {
		$row['football_week_format_id'] = $format->id;
		WeekFormatPayout::create($row);
	}
	if (sizeof($old_ids)) {
		WeekFormatPayout::whereIn('id', $old_ids)->delete();
	}
}

// ---------------------------------------------------------------------------
// Page
// ---------------------------------------------------------------------------

$format = null;
if (get('id')) {
	$format = WeekFormat::with(['payouts', 'weeks.season'])->find(get('id'));
	if (!$format) {
		Alert::error("Format #" . intval(get('id')) . " was not found.");
		redir('admin/formats/index.php');
	}
}

$active_season = Season::getActive();
$active_size = $active_season ? (int) $active_season->getNumPlayers() : 0;
$default_size = intval(get('num_players')) ?: $active_size;

$page = new Page;
$page->setTitle(($format ? 'Format #' . $format->id : 'Create Format') . ' - Formats - Admin');
$page->options['admin_bar']['show'] = true;
$page->options['admin_bar']['title'] = 'Formats';
$page->options['admin_bar']['sub_bar']['type'] = 'formats';

if (is_post()) {
	try {
		$action = post('action');
		if ($action == 'delete') {
			if (!$format) {
				throw new Exception("There is no format to delete.");
			}
			$num_weeks = $format->weeks()->count();
			if ($num_weeks) {
				throw new Exception("This format is used by " . $num_weeks . " week" . ($num_weeks == 1 ? '' : 's') . " and cannot be deleted.");
			}
			$num_players = $format->num_players;
			DB::transaction(function () use ($format) {
				WeekFormatPayout::where('football_week_format_id', '=', $format->id)->delete();
				$format->delete();
			});
			Alert::success("Deleted format \"" . h($format->name) . "\".");
			redir('admin/formats/index.php?num_players=' . $num_players);
		}
		elseif ($action == 'save' || $action == 'save-as-new') {
			$form = format_form_from_post($_POST);
			try {
				$parsed = format_form_validate($form);
			}
			catch (Exception $e) {
				// Keep what was typed so the next GET can show it again
				$_SESSION[SKEY]['format_form'] = [
					'id' => $format ? (int) $format->id : 0,
					'post' => $_POST,
				];
				throw $e;
			}
			$target = $format;
			$is_new = $action == 'save-as-new' || !$format;
			DB::transaction(function () use (&$target, $is_new, $parsed) {
				if ($is_new) {
					$target = WeekFormat::create($parsed['attrs']);
				}
				else {
					$target->fill($parsed['attrs']);
					$target->save();
				}
				format_save_rows($target, $parsed['rows']);
			});
			if ($is_new) {
				Alert::success(($action == 'save-as-new' ? "Saved as new format #" : "Created format #") . $target->id . ".");
				redir('admin/formats/format/index.php?id=' . $target->id);
			}
			Alert::success("Saved changes.");
		}
	}
	catch (Exception $e) {
		Alert::error($e->getMessage());
	}
	redir();
}

// Restore a failed POST (once), else load the format, else start blank
$form = null;
if (isset($_SESSION[SKEY]['format_form'])) {
	$stash = $_SESSION[SKEY]['format_form'];
	unset($_SESSION[SKEY]['format_form']);
	if (is_array($stash) && isset($stash['post']) && (int) $stash['id'] == ($format ? (int) $format->id : 0)) {
		$form = format_form_from_post($stash['post']);
	}
}
if ($form === null) {
	$form = $format ? format_form_from_format($format) : format_form_defaults($default_size);
}

// Weeks using this format, and those whose results are already visible
$weeks_using = [];
$weeks_visible = [];
if ($format) {
	foreach ($format->weeks as $week) {
		$weeks_using[] = $week;
		if ($week->canSeeResults()) {
			$weeks_visible[] = $week;
		}
	}
}
$week_label = function ($week) {
	return 'Week ' . $week->week_num . ' &ndash; ' . h($week->season ? $week->season->name : 'Season #' . $week->football_season_id);
};

$num_pools_options = [];
if ((int) $form['num_players'] >= 2) {
	$num_pools_options = range(2, (int) $form['num_players']);
}

ob_start();
?>
<div class="container py-4">
	<?php if (sizeof($weeks_visible)): ?>
		<div class="alert alert-warning">
			<strong>Changing payouts rewrites history</strong> for:
			<?php foreach ($weeks_visible as $i => $week): ?><?=$i ? ', ' : ''?><a class="alert-link" href="../../weeks/week/index.php?id=<?=$week->id?>"><?=$week_label($week)?></a><?php endforeach; ?>.
			Their results pages are calculated from this format. To use different rules for an upcoming week, use <em>Save as new format</em> and assign the new format to that week instead.
		</div>
	<?php endif; ?>

	<form action="" method="post" id="format-form" autocomplete="off">
		<div class="card">
			<h4 class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
				<span><?=$format ? 'Format #' . $format->id : 'Create Format'?></span>
				<a class="btn btn-sm btn-outline-secondary" href="../index.php?num_players=<?=(int) $form['num_players']?>">Back to list</a>
			</h4>
			<div class="card-body">
				<div class="row g-3 mb-3">
					<div class="col-sm-4 col-lg-2">
						<label for="num_players" class="form-label">League size</label>
						<input type="number" min="2" step="1" class="form-control" name="num_players" id="num_players" value="<?=h($form['num_players'])?>" required>
						<div class="form-text">players the format is designed for<?=$active_size ? ' (current league: ' . $active_size . ')' : ''?></div>
					</div>
					<div class="col-sm-8 col-lg-4">
						<label for="name" class="form-label">Name</label>
						<input type="text" class="form-control" name="name" id="name" maxlength="255" value="<?=h($form['name'])?>" required>
						<div class="form-text">shown as the week's name, e.g. "4 Pools of 8"</div>
					</div>
					<div class="col-lg-6">
						<label for="description_long" class="form-label">Description</label>
						<input type="text" class="form-control" name="description_long" id="description_long" maxlength="255" value="<?=h($form['description_long'])?>">
						<div class="form-text">one line for the players, e.g. "Overall winner wins $130. Other pool winners win $63."</div>
					</div>
				</div>

				<div class="row g-3 mb-3">
					<div class="col-md-5">
						<div class="form-label">Week type</div>
						<div class="form-check">
							<input class="form-check-input" type="radio" name="is_playoffs" id="is_playoffs_0" value="0" <?=$form['is_playoffs'] == '1' ? '' : 'checked'?>>
							<label class="form-check-label" for="is_playoffs_0">Regular week</label>
						</div>
						<div class="form-check">
							<input class="form-check-input" type="radio" name="is_playoffs" id="is_playoffs_1" value="1" <?=$form['is_playoffs'] == '1' ? 'checked' : ''?>>
							<label class="form-check-label" for="is_playoffs_1">Playoff week <span class="text-muted small">(excluded from season standings)</span></label>
						</div>
						<div id="advance-wrap" class="mt-2 ps-4 d-none">
							<label for="advance" class="form-label mb-1">Players advancing</label>
							<input type="number" min="1" step="1" class="form-control form-control-sm w-auto" name="advance" id="advance" value="<?=h($form['advance'])?>">
							<div class="form-text">players advancing to the next round (knock-out round only; leave blank for the money round)</div>
						</div>
					</div>
					<div class="col-md-7">
						<div class="form-label">Grouping</div>
						<div class="form-check">
							<input class="form-check-input" type="radio" name="grouping" id="grouping_one" value="one" <?=$form['grouping'] == 'one' ? 'checked' : ''?>>
							<label class="form-check-label" for="grouping_one">Everyone in one group</label>
						</div>
						<div class="form-check">
							<input class="form-check-input" type="radio" name="grouping" id="grouping_pools" value="pools" <?=$form['grouping'] == 'pools' ? 'checked' : ''?>>
							<label class="form-check-label" for="grouping_pools">Pools</label>
						</div>
						<div class="form-check">
							<input class="form-check-input" type="radio" name="grouping" id="grouping_teams" value="teams" <?=$form['grouping'] == 'teams' ? 'checked' : ''?>>
							<label class="form-check-label" for="grouping_teams">Teams <span class="text-muted small">(legacy: the pools play as teams and team payouts go to every member)</span></label>
						</div>
						<div id="pools-wrap" class="mt-2 ps-4 d-none">
							<label for="num_pools" class="form-label mb-1">Number of <span class="pool-word">pools</span></label>
							<select class="form-select form-select-sm w-auto" name="num_pools" id="num_pools">
								<?php foreach ($num_pools_options as $n): ?>
									<option <?=sel($n, $form['num_pools'])?> value="<?=$n?>"><?=$n?></option>
								<?php endforeach; ?>
							</select>
							<div class="form-text" id="pools-hint"></div>
						</div>
					</div>
				</div>

				<hr>

				<div class="small text-muted mb-3">
					<p class="mb-1"><strong>How payouts work.</strong> A player receives the single largest payout they qualify for. Overall payouts outrank pool payouts, so the overall winner takes the overall amount and their pool's 1st-place amount goes unpaid (the total below allows for that).</p>
					<p class="mb-1">A row pays places <em>from</em>&ndash;<em>to</em> (tick <em>and below</em> for "5th and below"), and/or anyone with at least the <em>points</em> given (e.g. 2nd&ndash;4th or 41+ points). It pays either a <em>per-player</em> amount to each qualifier, or a <em>split pot</em> shared equally by everyone who qualifies.</p>
					<p class="mb-0">Pool rows marked <em>Every pool</em> apply to each pool; a row for one pool number applies to that pool only (the Finals week pays pool 1, the finalists, and pool 2, the consolation bracket, differently).</p>
				</div>

				<h5>Overall payouts</h5>
				<div class="table-responsive">
					<table class="table table-sm align-middle payout-rows mb-2" id="overall-rows">
						<thead>
							<tr class="small">
								<th style="width: 7em;">Places from</th>
								<th style="width: 7em;">to</th>
								<th class="text-center" style="width: 6em;">and below</th>
								<th style="width: 8em;">or &ge; points</th>
								<th style="width: 12em;">Per player</th>
								<th style="width: 12em;">Split pot</th>
								<th></th>
							</tr>
						</thead>
						<tbody></tbody>
					</table>
				</div>
				<button type="button" class="btn btn-sm btn-outline-primary mb-4" data-add="overall">Add row</button>

				<div id="pool-section" class="d-none">
					<h5 id="pool-heading">Pool payouts</h5>
					<div class="table-responsive">
						<table class="table table-sm align-middle payout-rows mb-2" id="pool-rows">
							<thead>
								<tr class="small">
									<th style="width: 9em;">Applies to</th>
									<th style="width: 7em;">Places from</th>
									<th style="width: 7em;">to</th>
									<th class="text-center" style="width: 6em;">and below</th>
									<th style="width: 8em;">or &ge; points</th>
									<th style="width: 12em;">Per player</th>
									<th style="width: 12em;">Split pot</th>
									<th></th>
								</tr>
							</thead>
							<tbody></tbody>
						</table>
					</div>
					<button type="button" class="btn btn-sm btn-outline-primary mb-4" data-add="pool">Add row</button>
				</div>

				<hr>

				<div class="row g-3 align-items-start">
					<div class="col-sm-5 col-lg-3">
						<label for="total_payout" class="form-label">Total payout</label>
						<div class="input-group">
							<span class="input-group-text">$</span>
							<input type="number" min="0" step="0.01" class="form-control" name="total_payout" id="total_payout" value="<?=h($form['total_payout'])?>">
						</div>
						<div class="form-text">
							<span id="total-hint">computed; edit to override</span>
							&middot; <a href="#" id="total-recalc">recalculate</a>
						</div>
					</div>
					<div class="col-sm-7 col-lg-9 pt-sm-4">
						<div class="mt-sm-2" id="per-player"></div>
						<div class="fst-italic text-muted small mt-1" id="preview"></div>
					</div>
				</div>
			</div>
			<div class="card-footer d-flex flex-wrap gap-2">
				<button type="submit" class="btn btn-primary" name="action" value="save"><?=$format ? 'Save Changes' : 'Create Format'?></button>
				<?php if ($format): ?>
					<button type="submit" class="btn btn-outline-primary" name="action" value="save-as-new" title="Insert a copy with these values and leave this format as it is">Save as new format</button>
					<?php if (!sizeof($weeks_using)): ?>
						<button type="submit" class="btn btn-outline-danger ms-auto" name="action" value="delete" data-confirm>Delete</button>
					<?php endif; ?>
				<?php endif; ?>
			</div>
		</div>
	</form>

	<?php if ($format): ?>
		<div class="card mt-4">
			<h5 class="card-header">Weeks using this format</h5>
			<div class="card-body">
				<?php if (!sizeof($weeks_using)): ?>
					<span class="text-muted">None. This format can be deleted.</span>
				<?php else: ?>
					<ul class="mb-0">
						<?php foreach ($weeks_using as $week): ?>
							<li>
								<a href="../../weeks/week/index.php?id=<?=$week->id?>"><?=$week_label($week)?></a>
								<?php if ($week->canSeeResults()): ?>
									<span class="badge bg-warning text-dark">results visible</span>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>
		</div>
	<?php endif; ?>
</div>
<?php
$page->setContent(ob_get_clean());

ob_start();
?>
<script>
var FORMAT_FORM = <?=json_encode($form, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)?>;

(function ($) {
	var $form = $('#format-form');
	var nextIdx = 0;
	var totalManual = false;

	// ---- helpers ----

	function esc(v) {
		if (v === null || v === undefined) {
			return '';
		}
		return String(v)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#39;');
	}

	function num(v) {
		if (v === null || v === undefined || String(v).trim() === '') {
			return null;
		}
		var f = parseFloat(v);
		return isNaN(f) ? null : f;
	}

	function int(v) {
		var f = num(v);
		return f === null ? null : Math.floor(f);
	}

	function ordinal(n) {
		var m = n % 100;
		var suffix = 'th';
		if (m < 11 || m > 13) {
			if (n % 10 == 1) suffix = 'st';
			else if (n % 10 == 2) suffix = 'nd';
			else if (n % 10 == 3) suffix = 'rd';
		}
		return n + suffix;
	}

	function money(x) {
		x = parseFloat(x) || 0;
		if (Math.abs(x - Math.round(x)) < 0.005) {
			return Math.round(x).toLocaleString('en-US');
		}
		return x.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
	}

	function round2(x) {
		return Math.round(x * 100) / 100;
	}

	// ---- form state ----

	function numPlayers() { return int($('#num_players').val()) || 0; }
	function grouping() { return $form.find('input[name=grouping]:checked').val() || 'one'; }
	function poolsOn() { return grouping() != 'one'; }
	function isTeams() { return grouping() == 'teams'; }
	function isPlayoffs() { return $form.find('input[name=is_playoffs]:checked').val() == '1'; }
	function numPools() { return poolsOn() ? (int($('#num_pools').val()) || 0) : 0; }
	function poolWord() { return isTeams() ? 'team' : 'pool'; }

	// ---- payout rows ----

	function rowHtml(section, r) {
		var idx = nextIdx++;
		var n = section + '[' + idx + ']';
		var open = r.open == 1;
		var split = r.mode == 'split';
		var h = '<tr data-section="' + section + '">';
		if (section == 'pool') {
			h += '<td><select class="form-select form-select-sm row-pool-num" name="' + n + '[pool_num]" data-value="' + esc(r.pool_num) + '"></select></td>';
		}
		h += '<td><input type="number" min="1" step="1" class="form-control form-control-sm row-min-place" name="' + n + '[min_place]" value="' + esc(r.min_place) + '"></td>';
		h += '<td><input type="number" min="1" step="1" class="form-control form-control-sm row-max-place" name="' + n + '[max_place]" value="' + esc(open ? '' : r.max_place) + '"></td>';
		h += '<td class="text-center"><input type="checkbox" class="form-check-input row-open" name="' + n + '[open]" value="1"' + (open ? ' checked' : '') + ' title="This row pays the from-place and everyone below it"></td>';
		h += '<td><input type="number" min="1" step="1" class="form-control form-control-sm row-min-points" name="' + n + '[min_points]" value="' + esc(r.min_points) + '" placeholder="optional"></td>';
		h += '<td><div class="input-group input-group-sm">'
			+ '<div class="input-group-text"><input type="radio" class="form-check-input mt-0 row-mode" name="' + n + '[mode]" value="per"' + (split ? '' : ' checked') + ' title="Each qualifying player receives this amount"></div>'
			+ '<span class="input-group-text">$</span>'
			+ '<input type="number" min="0" step="0.01" class="form-control row-payout" name="' + n + '[payout]" value="' + esc(r.payout) + '" title="Amount each qualifying player receives">'
			+ '</div></td>';
		h += '<td><div class="input-group input-group-sm">'
			+ '<div class="input-group-text"><input type="radio" class="form-check-input mt-0 row-mode" name="' + n + '[mode]" value="split"' + (split ? ' checked' : '') + ' title="Everyone who qualifies shares this pot equally"></div>'
			+ '<span class="input-group-text">$</span>'
			+ '<input type="number" min="1" step="1" class="form-control row-total" name="' + n + '[total_payout]" value="' + esc(r.total_payout) + '" title="Pot shared equally by everyone who qualifies">'
			+ '</div></td>';
		h += '<td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger row-remove" title="Remove row">&times;</button></td>';
		h += '</tr>';
		return h;
	}

	function newRowDefaults(section) {
		var r = {pool_num: '', min_place: 1, max_place: 1, open: 0, min_points: '', mode: 'per', payout: '', total_payout: ''};
		var $last = $('#' + section + '-rows tbody tr').last();
		if ($last.length) {
			var open = $last.find('.row-open').prop('checked');
			var max = int($last.find('.row-max-place').val());
			if (!open && max) {
				r.min_place = max + 1;
				r.max_place = max + 1;
			}
			else {
				r.min_place = '';
				r.max_place = '';
			}
			r.pool_num = $last.find('.row-pool-num').val() || '';
		}
		return r;
	}

	function addRow(section, r) {
		$('#' + section + '-rows tbody').append(rowHtml(section, r || newRowDefaults(section)));
	}

	function syncRow($tr) {
		var open = $tr.find('.row-open').prop('checked');
		var $max = $tr.find('.row-max-place');
		$max.prop('disabled', open);
		if (open) {
			$max.val('');
		}
		var mode = $tr.find('.row-mode:checked').val() || 'per';
		$tr.find('.row-payout').prop('disabled', mode != 'per');
		$tr.find('.row-total').prop('disabled', mode != 'split');
	}

	function rebuildNumPools() {
		var $sel = $('#num_pools');
		var cur = int($sel.val());
		var n = numPlayers();
		var html = '';
		for (var i = 2; i <= n; i++) {
			html += '<option value="' + i + '">' + i + '</option>';
		}
		$sel.html(html);
		if (cur && cur <= n) {
			$sel.val(String(cur));
		}
		else if (n >= 2) {
			$sel.val(String(Math.min(Math.max(cur || 2, 2), n)));
		}
	}

	function rebuildPoolSelects() {
		var n = numPools();
		var word = poolWord();
		$('.row-pool-num').each(function () {
			var $s = $(this);
			var cur = $s.find('option').length ? $s.val() : String($s.data('value') === undefined ? '' : $s.data('value'));
			var html = '<option value="">Every ' + word + '</option>';
			for (var i = 1; i <= n; i++) {
				html += '<option value="' + i + '">' + word.charAt(0).toUpperCase() + word.slice(1) + ' ' + i + '</option>';
			}
			$s.html(html);
			if (cur && int(cur) <= n) {
				$s.val(String(int(cur)));
			}
			else {
				$s.val('');
			}
		});
	}

	// ---- the total, ported from WeekFormat::computeTotalPayout ----

	function numPlaces(r) {
		var min = r.min_place || 1;
		if (r.max_place === null) {
			return 1;
		}
		return Math.max(1, r.max_place - min + 1);
	}

	function computeTotal(rows, numPools, isTeams, numPlayers) {
		numPools = parseInt(numPools, 10) || 0;
		var playersPerPool = numPools >= 2 ? Math.ceil(numPlayers / numPools) : 0;
		var total = 0;
		var overallPlaces = 0;
		var poolFirst = 0;
		rows.forEach(function (r) {
			var places = numPlaces(r);
			var amount;
			if (r.payout !== null) {
				amount = r.payout * places;
			}
			else {
				amount = r.total_payout || 0;
			}
			if (r.place_type == 'overall') {
				total += amount;
				if (r.payout !== null) {
					overallPlaces += places;
				}
				return;
			}
			var multiplier;
			if (r.place_type == 'team') {
				// Teams are ranked against each other; the team in that place pays each member
				multiplier = Math.max(1, playersPerPool);
			}
			else if (r.pool_num === null) {
				multiplier = Math.max(1, numPools);
			}
			else {
				multiplier = 1;
			}
			total += amount * multiplier;
			if (r.min_place == 1 && r.pool_num === null && r.payout !== null) {
				poolFirst = r.payout;
			}
		});
		if (overallPlaces && poolFirst) {
			total -= Math.min(overallPlaces, Math.max(1, numPools)) * poolFirst;
		}
		return round2(total);
	}

	function collectRows() {
		var rows = [];
		var pools = poolsOn();
		var teams = isTeams();
		$('#overall-rows tbody tr, #pool-rows tbody tr').each(function () {
			var $tr = $(this);
			var section = $tr.data('section');
			if (section == 'pool' && !pools) {
				return;
			}
			var open = $tr.find('.row-open').prop('checked');
			var mode = $tr.find('.row-mode:checked').val() || 'per';
			var poolNum = section == 'pool' ? int($tr.find('.row-pool-num').val()) : null;
			rows.push({
				place_type: section == 'overall' ? 'overall' : (teams ? 'team' : 'pool'),
				pool_num: poolNum || null,
				min_place: int($tr.find('.row-min-place').val()) || 0,
				max_place: open ? null : int($tr.find('.row-max-place').val()),
				min_points: int($tr.find('.row-min-points').val()),
				payout: mode == 'per' ? num($tr.find('.row-payout').val()) : null,
				total_payout: mode == 'split' ? num($tr.find('.row-total').val()) : null
			});
		});
		return rows;
	}

	// ---- preview line, like Snippets\WeekFormatPayouts inline ----

	function placeLabel(r) {
		var parts = [];
		if (r.min_place) {
			if (r.max_place === null) parts.push(ordinal(r.min_place) + '+');
			else if (r.max_place == r.min_place) parts.push(ordinal(r.min_place));
			else parts.push(ordinal(r.min_place) + '-' + ordinal(r.max_place));
		}
		if (r.min_points !== null) {
			parts.push(r.min_points + '+ pts');
		}
		return parts.join(' or ');
	}

	function amountLabel(r) {
		if (r.payout !== null) return '$' + money(r.payout);
		if (r.total_payout) return 'split $' + money(r.total_payout);
		return 'split';
	}

	function preview(rows) {
		var typeOrder = {overall: 0, pool: 1, team: 2};
		rows = rows.slice().sort(function (a, b) {
			return (typeOrder[a.place_type] - typeOrder[b.place_type])
				|| ((a.pool_num || 0) - (b.pool_num || 0))
				|| (a.min_place - b.min_place);
		});
		var groups = {};
		var order = [];
		rows.forEach(function (r) {
			var label;
			if (r.place_type == 'overall') label = 'Overall';
			else if (r.pool_num !== null) label = (r.place_type == 'team' ? 'Team ' : 'Pool ') + r.pool_num;
			else label = r.place_type == 'team' ? 'Each team member' : 'Each pool';
			if (!groups[label]) {
				groups[label] = [];
				order.push(label);
			}
			groups[label].push(placeLabel(r) + ' ' + amountLabel(r));
		});
		if (!order.length) {
			return 'No payouts.';
		}
		return order.map(function (label) {
			return label + ': ' + groups[label].join(', ');
		}).join('. ') + '.';
	}

	// ---- refresh everything derived from the inputs ----

	function refresh() {
		var playoffs = isPlayoffs();
		$('#advance-wrap').toggleClass('d-none', !playoffs);
		$('#advance').prop('disabled', !playoffs);

		var pools = poolsOn();
		var teams = isTeams();
		$('#pools-wrap').toggleClass('d-none', !pools);
		$('#num_pools').prop('disabled', !pools);
		$('#pool-section').toggleClass('d-none', !pools);
		$('#pool-section').find('input, select, button').prop('disabled', !pools);
		$('#pool-heading').text(teams ? 'Team payouts' : 'Pool payouts');
		$('.pool-word').text(teams ? 'teams' : 'pools');

		var n = numPlayers();
		var p = numPools();
		var hint = '';
		if (pools && p >= 2 && n >= 2) {
			var per = Math.ceil(n / p);
			hint = p + ' ' + poolWord() + 's of ' + (n % p == 0 ? per : (per + ' or ' + (per - 1))) + ' players';
		}
		$('#pools-hint').text(hint);

		rebuildPoolSelects();
		$('#overall-rows tbody tr').each(function () { syncRow($(this)); });
		if (pools) {
			$('#pool-rows tbody tr').each(function () { syncRow($(this)); });
		}

		var rows = collectRows();
		var computed = computeTotal(rows, p, teams, n);
		var $total = $('#total_payout');
		if (!totalManual) {
			$total.val(String(computed));
		}
		var entered = num($total.val());
		if (totalManual && entered !== null && Math.abs(entered - computed) >= 0.005) {
			$('#total-hint').text('overridden; computed total is $' + money(computed));
		}
		else {
			$('#total-hint').text('computed; edit to override');
		}
		var perPlayer = '';
		if (entered !== null && n) {
			perPlayer = '≈ $' + money(entered / n) + ' per player';
		}
		$('#per-player').text(perPlayer);
		$('#preview').text(preview(rows));
	}

	// ---- wiring ----

	$form.on('change input', 'input, select', function () {
		if (this.id == 'total_payout') {
			totalManual = true;
		}
		if (this.id == 'num_players') {
			rebuildNumPools();
		}
		refresh();
	});

	$form.on('click', '[data-add]', function () {
		addRow($(this).data('add'));
		refresh();
	});

	$form.on('click', '.row-remove', function () {
		$(this).closest('tr').remove();
		refresh();
	});

	$('#total-recalc').on('click', function (e) {
		e.preventDefault();
		totalManual = false;
		refresh();
	});

	// ---- initial rows ----

	(FORMAT_FORM.overall || []).forEach(function (r) { addRow('overall', r); });
	(FORMAT_FORM.pool || []).forEach(function (r) { addRow('pool', r); });

	// A stored total that differs from the formula is an override; keep it
	var storedTotal = num($('#total_payout').val());
	if (storedTotal !== null) {
		totalManual = true;
		refresh();
		var computed = computeTotal(collectRows(), numPools(), isTeams(), numPlayers());
		if (Math.abs(storedTotal - computed) < 0.005) {
			totalManual = false;
		}
	}
	refresh();
})(jQuery);
</script>
<?php
$page->setScripts(ob_get_clean());
print $page->render();
