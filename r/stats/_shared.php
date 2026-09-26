<?php

/**
 * Shared setup for the redesign's all-time stats pages (r/stats/*.php):
 * the guard, the shell, the cached AllTimeStats result, player names, the
 * viewer's seasons (for links) and friends (for row marks), and the small
 * renderers the five pages share. Every number means exactly what the
 * classic stats/ pages show (docs/all-time-stats.md).
 *
 * Globals after require: $shell, $me, $stats, $users, $my_season_ids, $friend_ids.
 */

require_once __DIR__ . '/../../inc/_inc.php';

use Pick55\AllTimeStats;
use Pick55\Models\User;
use Pick55\Models\UsersSeasonsLink;
use Pick55\R\Fmt;
use Pick55\R\Icons;
use Pick55\R\Shell;

Shell::guard();

// Percentage records only count players with at least this many weeks (two
// full seasons), as the classic pages do. The leaderboard lets the viewer
// change the tables' filter; the tiles and the record book stay at 24.
const RS_MIN_WEEKS = 24;

$shell = new Shell;
$shell->setNav('stats');
$shell->addStyle('css/pages/stats.css');
$shell->addScript('js/pages/stats.js');

$me = $shell->ctx->user;
$stats = AllTimeStats::get();

$users = [];
$user_ids = array_keys($stats['players']);
if (sizeof($user_ids)) {
	foreach (User::whereIn('id', $user_ids)->get() as $user) {
		$users[(int) $user->id] = $user;
	}
}
$my_season_ids = array_map('intval', UsersSeasonsLink::where('er_user_id', '=', $me->id)
	->pluck('football_season_id')
	->all());
$friend_ids = $shell->ctx->friendIds();

/**
 * The five routes: key => [rel path, label, icon].
 */
function rs_routes()
{
	return [
		'book' => ['stats/index.php', 'Record Book', 'book-open'],
		'fame' => ['stats/fame.php', 'Wall of Fame', 'star'],
		'shame' => ['stats/shame.php', 'Wall of Shame', 'egg'],
		'leaders' => ['stats/leaderboard.php', 'Leaderboards', 'list-ordered'],
		'seasons' => ['stats/seasons.php', 'Best Seasons', 'calendar'],
	];
}

/**
 * The page header with the sub-nav segmented control.
 *
 * @param string $active  a key of rs_routes()
 * @param string $sub  plain text under the title
 * @return string
 */
function rs_head($active, $sub = '')
{
	global $shell;
	$routes = rs_routes();
	ob_start();
	?>
	<header class="stats-head enter">
		<span class="eyebrow">All-time stats</span>
		<h1 class="page-title"><?=h($routes[$active][1])?></h1>
		<?php if ($sub !== ''): ?>
			<p class="page-sub"><?=h($sub)?></p>
		<?php endif; ?>
	</header>
	<nav class="stats-nav enter" style="--i: 1" aria-label="All-time stats">
		<div class="seg">
			<?php foreach ($routes as $key => $route): ?>
				<a href="<?=h($shell->link($route[0]))?>" <?=$key === $active ? 'aria-current="page"' : ''?>><?=Icons::svg($route[2])?><span><?=h($route[1])?></span></a>
			<?php endforeach; ?>
		</div>
	</nav>
	<?php
	return ob_get_clean();
}

/**
 * @param int $user_id
 * @return string plain text display name
 */
function rs_name($user_id)
{
	global $users;
	if (isset($users[$user_id])) {
		return $users[$user_id]->getDisplayName();
	}
	return 'Player #' . (int) $user_id;
}

/**
 * @param int $user_id
 * @return string 'row-me', 'row-friend' or ''
 */
function rs_row_class($user_id)
{
	global $me, $friend_ids;
	if ((int) $user_id === (int) $me->id) {
		return 'row-me';
	}
	return isset($friend_ids[(int) $user_id]) ? 'row-friend' : '';
}

/**
 * A player's name as HTML, with the friend dot and "(you)".
 *
 * @param int $user_id
 * @return string
 */
function rs_name_html($user_id)
{
	$class = rs_row_class($user_id);
	$html = '<span class="player-name">' . h(rs_name($user_id)) . '</span>';
	if ($class === 'row-me') {
		$html .= ' <span class="faint">(you)</span>';
	}
	elseif ($class === 'row-friend') {
		$html .= '<span class="friend-mark" title="Friend"></span>';
	}
	return $html;
}

/**
 * The first cell of a ranked table: the rank badge (numbered by stats.js)
 * and the player's name.
 *
 * @param int $user_id
 * @param string $extra_html  after the name
 * @return string
 */
function rs_player_cell($user_id, $extra_html = '')
{
	return '<td class="cell-player" data-sort-value="' . h(rs_name($user_id)) . '">'
		. '<span class="rank" data-rank></span>'
		. '<span class="cell-player-name">' . rs_name_html($user_id) . $extra_html . '</span></td>';
}

/**
 * @param int $season_id
 * @return string plain text
 */
function rs_season_name($season_id)
{
	global $stats;
	return isset($stats['seasons'][$season_id]) ? $stats['seasons'][$season_id]['name'] : '';
}

/**
 * "2018 Season" becomes "2018"; other names are kept. Plain text.
 *
 * @param int $season_id
 * @return string
 */
function rs_season_short($season_id)
{
	$name = rs_season_name($season_id);
	if (preg_match('/^(\d{4}) Season$/', $name, $m)) {
		return $m[1];
	}
	return $name;
}

/**
 * The season's name, linked to its standings when the viewer played it.
 *
 * @param int $season_id
 * @param bool $short
 * @return string HTML
 */
function rs_season_link($season_id, $short = false)
{
	global $shell, $my_season_ids;
	$text = h($short ? rs_season_short($season_id) : rs_season_name($season_id));
	if (in_array((int) $season_id, $my_season_ids, true)) {
		return '<a href="' . h($shell->link('season/standings.php?id=' . (int) $season_id)) . '">' . $text . '</a>';
	}
	return $text;
}

/**
 * @param int $week_id
 * @return array|null the week's stats row
 */
function rs_week($week_id)
{
	global $stats;
	return isset($stats['weeks'][$week_id]) ? $stats['weeks'][$week_id] : null;
}

/**
 * "Week 7", linked to the week's results when the viewer played that season.
 *
 * @param int $week_id
 * @param string|null $text  plain text
 * @return string HTML
 */
function rs_week_link($week_id, $text = null)
{
	global $shell, $my_season_ids;
	$week = rs_week($week_id);
	if (!$week) {
		return '';
	}
	if ($text === null) {
		$text = 'Week ' . $week['week_num'];
	}
	if (in_array((int) $week['season_id'], $my_season_ids, true)) {
		return '<a href="' . h($shell->link('season/week/results.php?id=' . (int) $week['id'])) . '">' . h($text) . '</a>';
	}
	return h($text);
}

/**
 * "2018 · Week 7" with the format name muted after it.
 *
 * @param int $week_id
 * @param bool $with_format
 * @return string HTML
 */
function rs_week_label($week_id, $with_format = true)
{
	$week = rs_week($week_id);
	if (!$week) {
		return '';
	}
	$str = h(rs_season_short($week['season_id'])) . ' &middot; ' . rs_week_link($week_id);
	if ($with_format && $week['name'] != 'Week ' . $week['week_num']) {
		$str .= ' <span class="faint">' . h($week['name']) . '</span>';
	}
	return $str;
}

/**
 * @param float $pct
 * @return string "51.9%"
 */
function rs_pct($pct)
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
function rs_place($rank, $field)
{
	if ($field > 1 && $rank == $field) {
		return 'Last of ' . $field;
	}
	return ordinal((int) $rank) . ' of ' . (int) $field;
}

/**
 * The rows holding the highest (or lowest) value, ties included.
 *
 * @param array $rows
 * @param callable $value  row => number|null (null = not eligible)
 * @param bool $highest
 * @return array ['value' => number|null, 'rows' => list]
 */
function rs_extreme(array $rows, callable $value, $highest = true)
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
 * usort that keeps the stored order of ties (PHP 7's sort is not stable),
 * so the server's order matches the classic pages' stable client sort.
 *
 * @param array $rows
 * @param callable $cmp  (a, b) => int
 * @return array list
 */
function rs_stable_sort(array $rows, callable $cmp)
{
	$keyed = [];
	$i = 0;
	foreach ($rows as $row) {
		$keyed[] = [$row, $i++];
	}
	usort($keyed, function ($a, $b) use ($cmp) {
		$c = $cmp($a[0], $b[0]);
		return $c !== 0 ? $c : $a[1] - $b[1];
	});
	return array_map(function ($x) {
		return $x[0];
	}, $keyed);
}

/**
 * Names of the rows' players, "+N more" past the limit.
 *
 * @param array $rows  rows with a user_id
 * @param int $limit
 * @return string HTML
 */
function rs_holders(array $rows, $limit = 3)
{
	$names = [];
	foreach ($rows as $row) {
		$name = rs_name($row['user_id']);
		if (!isset($names[$name])) {
			$names[$name] = $row['user_id'];
		}
	}
	$extra = sizeof($names) - $limit;
	$out = [];
	foreach (array_slice($names, 0, $limit, true) as $name => $user_id) {
		$class = rs_row_class($user_id);
		$out[] = '<span class="holder' . ($class ? ' is-' . substr($class, 4) : '') . '">' . h($name) . '</span>';
	}
	$str = implode(', ', $out);
	if ($extra > 0) {
		$str .= ' <span class="faint">+' . $extra . ' more</span>';
	}
	return $str;
}

/**
 * Player-season holders: "Name 2018; Name 2021", "+N more" past the limit.
 *
 * @param array $rows  player-season rows
 * @param int $limit
 * @param string $sep  HTML between holders
 * @return string HTML
 */
function rs_ps_holders(array $rows, $limit = 3, $sep = '; ')
{
	$out = [];
	foreach (array_slice($rows, 0, $limit) as $ps) {
		$class = rs_row_class($ps['user_id']);
		$out[] = '<span class="holder' . ($class ? ' is-' . substr($class, 4) : '') . '">' . h(rs_name($ps['user_id'])) . '</span> <span class="faint">' . h(rs_season_short($ps['season_id'])) . '</span>';
	}
	if (sizeof($rows) > $limit) {
		$out[] = '<span class="faint">+' . (sizeof($rows) - $limit) . ' more</span>';
	}
	return implode($sep, $out);
}

/**
 * One headline tile.
 *
 * @param string $label  plain text
 * @param string $value  HTML
 * @param string $sub  HTML
 * @param string $tone  '' | fame | shame | money
 * @param int $i  stagger index
 * @return string
 */
function rs_tile($label, $value, $sub = '', $tone = '', $i = 0)
{
	ob_start();
	?>
	<div class="stat tile<?=$tone !== '' ? ' tone-' . h($tone) : ''?> enter" style="--i: <?=(int) $i?>" data-tile="<?=h($label)?>">
		<span class="stat-label"><?=h($label)?></span>
		<span class="stat-value"><?=$value !== '' && $value !== null ? $value : '&ndash;'?></span>
		<?php if ((string) $sub !== ''): ?>
			<span class="stat-sub"><?=$sub?></span>
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
 * @param int $i  stagger index
 * @return string
 */
function rs_plaque(array $row, $kind, $i = 0)
{
	$week = rs_week($row['week_id']);
	if ($row['rank'] == 1) {
		$ribbon = $week && $week['is_playoffs'] ? 'Won the ' . $week['name'] : 'Won the week';
	}
	else {
		$ribbon = rs_place($row['rank'], $row['field']);
	}
	$class = rs_row_class($row['user_id']);
	ob_start();
	?>
	<article class="plaque plaque-<?=h($kind)?><?=$class === 'row-me' ? ' is-me' : ($class === 'row-friend' ? ' is-friend' : '')?> enter" style="--i: <?=(int) min($i, 12)?>" data-plaque="<?=(int) $row['user_id']?>-<?=(int) $row['week_id']?>">
		<div class="plaque-ribbon"><?=h($ribbon)?></div>
		<div class="plaque-body">
			<div class="plaque-score num"><?=(int) $row['points']?></div>
			<div class="plaque-unit">points</div>
			<div class="plaque-name"><?=rs_name_html($row['user_id'])?></div>
			<div class="plaque-meta"><?=h(rs_season_name($row['season_id']))?> &middot; <?=rs_week_link($row['week_id'])?></div>
			<?php if ($week && $week['name'] != 'Week ' . $week['week_num']): ?>
				<div class="plaque-format"><?=h($week['name'])?></div>
			<?php endif; ?>
		</div>
		<div class="plaque-foot num">
			<span title="Right–wrong"><?=h(Fmt::record($row['right'], $row['wrong']))?></span>
			<?php if ($row['winnings'] > 0): ?>
				<span class="plaque-money"><?=h(Fmt::money($row['winnings'], false, true))?></span>
			<?php endif; ?>
		</div>
	</article>
	<?php
	return ob_get_clean();
}

/**
 * The best/worst toggle and the minimum filter bar of the ranked pages.
 *
 * @param array $options  list of [min, label]
 * @param int $selected
 * @param string $filter_label  plain text before the filter
 * @return string
 */
function rs_toolbar(array $options, $selected, $filter_label)
{
	ob_start();
	?>
	<div class="stats-toolbar card enter" style="--i: 2">
		<div class="toolbar-group">
			<span class="toolbar-label">Order</span>
			<div class="seg seg-sm" role="group" aria-label="Order">
				<button type="button" data-sort-toggle="best" aria-pressed="true"><?=Icons::svg('arrow-up')?>Best first</button>
				<button type="button" data-sort-toggle="worst" aria-pressed="false"><?=Icons::svg('arrow-down')?>Worst first</button>
			</div>
		</div>
		<div class="toolbar-group">
			<span class="toolbar-label"><?=h($filter_label)?></span>
			<div class="seg seg-sm" role="group" aria-label="<?=h($filter_label)?>">
				<?php foreach ($options as $opt): ?>
					<button type="button" data-min-filter="weeks" data-min="<?=(int) $opt[0]?>" aria-pressed="<?=$opt[0] == $selected ? 'true' : 'false'?>"><?=h($opt[1])?></button>
				<?php endforeach; ?>
			</div>
		</div>
		<p class="toolbar-hint faint small mb-0">Tap a column to sort. <span class="hint-me">Your rows</span> are highlighted.</p>
	</div>
	<?php
	return ob_get_clean();
}

/**
 * "Show all N" under a limited table (stats.js wires it and hides it when
 * the visible rows fit).
 *
 * @param string $table_id
 * @param int $count
 * @return string
 */
function rs_show_all($table_id, $count)
{
	return '<button type="button" class="btn btn-ghost btn-sm" data-show-all="' . h($table_id) . '">Show all <span data-show-count>' . (int) $count . '</span></button>';
}
