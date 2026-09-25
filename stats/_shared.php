<?php

/**
 * Shared setup for the stats/ pages: auth, the cached AllTimeStats result,
 * the players' names, and small formatting helpers. Every page in this
 * directory requires this first, then sets its title and active tab.
 */

require_once __DIR__ . '/../inc/_inc.php';

use Pick55\AllTimeStats;
use Pick55\Auth;
use Pick55\Page;
use Pick55\Models\User;
use Pick55\Models\UsersSeasonsLink;
use Pick55\Snippets\Money;

Auth::guard();

// Percentage leaderboards and the record book only consider players with
// at least this many weeks (two full seasons), so a hot fortnight does not
// top the all-time list. The leaderboard page lets the viewer change it.
const STATS_MIN_WEEKS = 24;

$me = Auth::user();
$stats = AllTimeStats::get();

$users = [];
$user_ids = array_keys($stats['players']);
if (sizeof($user_ids)) {
	foreach (User::whereIn('id', $user_ids)->get() as $user) {
		$users[$user->id] = $user;
	}
}
$my_season_ids = array_map('intval', UsersSeasonsLink::where('er_user_id', '=', $me->id)
	->pluck('football_season_id')
	->all());

$page = new Page;
$page->options['stats_bar']['show'] = true;

/**
 * @param int $user_id
 * @return string HTML-escaped display name
 */
function stats_name($user_id)
{
	global $users;
	if (isset($users[$user_id])) {
		return h($users[$user_id]->getDisplayName());
	}
	return 'Player #' . (int) $user_id;
}

/**
 * @param int $season_id
 * @return string
 */
function stats_season_name($season_id)
{
	global $stats;
	return isset($stats['seasons'][$season_id]) ? h($stats['seasons'][$season_id]['name']) : '';
}

/**
 * "2018 Season" becomes "2018"; other names are kept.
 *
 * @param int $season_id
 * @return string
 */
function stats_season_short($season_id)
{
	global $stats;
	if (!isset($stats['seasons'][$season_id])) {
		return '';
	}
	$name = $stats['seasons'][$season_id]['name'];
	if (preg_match('/^(\d{4}) Season$/', $name, $m)) {
		return $m[1];
	}
	return h($name);
}

/**
 * @param int $week_id
 * @return array the week's stats row, or null
 */
function stats_week($week_id)
{
	global $stats;
	return isset($stats['weeks'][$week_id]) ? $stats['weeks'][$week_id] : null;
}

/**
 * "Week 7", linked to the results page when the viewer played that season.
 *
 * @param int $week_id
 * @param string|null $text
 * @return string
 */
function stats_week_link($week_id, $text = null)
{
	global $page, $my_season_ids;
	$week = stats_week($week_id);
	if (!$week) {
		return '';
	}
	if ($text === null) {
		$text = 'Week ' . $week['week_num'];
	}
	if (in_array($week['season_id'], $my_season_ids)) {
		return '<a href="' . $page->link('season/week/results.php?id=' . $week['id']) . '">' . $text . '</a>';
	}
	return $text;
}

/**
 * "2018 · Week 7" with the format name muted after it.
 *
 * @param int $week_id
 * @param bool $with_format
 * @return string
 */
function stats_week_label($week_id, $with_format = true)
{
	$week = stats_week($week_id);
	if (!$week) {
		return '';
	}
	$str = stats_season_short($week['season_id']) . ' &middot; ' . stats_week_link($week_id);
	if ($with_format && $week['name'] != 'Week ' . $week['week_num']) {
		$str .= ' <span class="text-muted">' . h($week['name']) . '</span>';
	}
	return $str;
}

/**
 * @param int $right
 * @param int $wrong
 * @return string "11-1"
 */
function stats_record($right, $wrong)
{
	return (int) $right . '-' . (int) $wrong;
}

/**
 * @param float $pct
 * @return string "51.9%"
 */
function stats_pct($pct)
{
	return number_format((float) $pct, 1) . '%';
}

/**
 * "1st of 31", or "Last of 31".
 *
 * @param int $rank
 * @param int $field
 * @return string
 */
function stats_place($rank, $field)
{
	if ($field > 1 && $rank == $field) {
		return 'Last of ' . $field;
	}
	return ordinal($rank) . ' of ' . $field;
}

/**
 * @param int $user_id
 * @return string row class highlighting the viewer
 */
function stats_me_class($user_id)
{
	global $me;
	return $user_id == $me->id ? 'bg-me' : '';
}

/**
 * The rows holding the highest (or lowest) value, ties included.
 *
 * @param array $rows
 * @param callable $value  row => number|null (null = not eligible)
 * @param bool $highest
 * @return array ['value' => number|null, 'rows' => list]
 */
function stats_extreme(array $rows, callable $value, $highest = true)
{
	$best = null;
	$holders = [];
	foreach ($rows as $row) {
		$v = $value($row);
		if ($v === null) {
			continue;
		}
		if ($best === null || ($highest ? $v > $best : $v < $best)) {
			$best = $v;
			$holders = [$row];
		}
		elseif ($v == $best) {
			$holders[] = $row;
		}
	}
	return ['value' => $best, 'rows' => $holders];
}

/**
 * Comma-separated names of the rows' players, "+N more" past the limit.
 *
 * @param array $rows  rows with a user_id
 * @param int $limit
 * @return string
 */
function stats_holders(array $rows, $limit = 3)
{
	$names = [];
	foreach ($rows as $row) {
		$names[] = stats_name($row['user_id']);
	}
	$names = array_unique($names);
	$extra = sizeof($names) - $limit;
	$names = array_slice($names, 0, $limit);
	$str = implode(', ', $names);
	if ($extra > 0) {
		$str .= ' <span class="text-muted">+' . $extra . ' more</span>';
	}
	return $str;
}

/**
 * One stat tile.
 *
 * @param string $label
 * @param string $value  HTML
 * @param string $sub  HTML under the value
 * @param string $class  extra classes (tile-fame, tile-shame, tile-money)
 * @return string
 */
function stats_tile($label, $value, $sub = '', $class = '')
{
	ob_start();
	?>
	<div class="stat-tile <?=$class?>">
		<div class="stat-value"><?=$value?></div>
		<div class="stat-label"><?=$label?></div>
		<?php if (strlen($sub)): ?>
			<div class="stat-sub"><?=$sub?></div>
		<?php endif; ?>
	</div>
	<?php
	return ob_get_clean();
}

/**
 * A Wall of Fame / Wall of Shame plaque for one player-week.
 *
 * @param array $row  a player-week row from AllTimeStats
 * @param string $kind  'fame' | 'shame'
 * @return string
 */
function stats_plaque(array $row, $kind)
{
	$week = stats_week($row['week_id']);
	if ($row['rank'] == 1) {
		$ribbon = $week && $week['is_playoffs'] ? 'Won the ' . h($week['name']) : 'Won the week';
	}
	else {
		$ribbon = stats_place($row['rank'], $row['field']);
	}
	ob_start();
	?>
	<div class="plaque plaque-<?=$kind?> <?=stats_me_class($row['user_id']) ? 'plaque-me' : ''?>">
		<div class="plaque-ribbon"><?=$ribbon?></div>
		<div class="plaque-score"><?=$row['points']?></div>
		<div class="plaque-points-label">points</div>
		<div class="plaque-name"><?=stats_name($row['user_id'])?></div>
		<div class="plaque-meta">
			<?=stats_season_name($row['season_id'])?> &middot; <?=stats_week_link($row['week_id'])?>
			<?php if ($week && $week['name'] != 'Week ' . $week['week_num']): ?>
				<br><span class="plaque-format"><?=h($week['name'])?></span>
			<?php endif; ?>
		</div>
		<div class="plaque-record">
			<?=stats_record($row['right'], $row['wrong'])?>
			<?php if ($row['winnings'] > 0): ?>
				&middot; <?=Money::b($row['winnings'], false, true)?>
			<?php endif; ?>
		</div>
	</div>
	<?php
	return ob_get_clean();
}
