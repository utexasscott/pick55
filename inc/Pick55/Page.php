<?php

namespace Pick55;

use Pick55\App;
use Pick55\Auth;
use Pick55\PageManager;
use Pick55\Models\Game;
use Pick55\Models\Season;
use Pick55\Snippets\Rank;
use Pick55\Snippets\GameOptionClass;

class Page
{
	const ASSET_VERSION = '2026-09-25b';
	const STR_UNEXPECTED_ERROR = "Sorry, we have encountered an unexpected error.";

	public $app;
	public $base_url;
	public $title;
	public $content;
	public $scripts;
	public $styles;
	public $options;

	/**
	 */
	public function __construct()
	{
		$this->app = App::get();
		$this->base_url = config('base_url');
		$this->title = null;
		$this->content = null;
		$this->scripts = null;
		$this->styles = null;
		$this->options = [
			'header' => [
				'show_links' => true,
			],
			'season_bar' => [
				'show' => false,
				'title' => '',
				'show_select' => false,
				'rel_path' => '',
			],
			'admin_bar' => [
				'show' => false,
				'title' => '',
				'sub_bar' => [
					'type' => null,
					'obj' => null,
				],
			],
			'stats_bar' => [
				'show' => false,
				'active' => '',
			],
		];
	}

	/**
	 * @param string $title
	 */
	public function setTitle($title = '')
	{
		$this->title = $title;
	}

	/**
	 * @param string $content
	 */
	public function setContent($content = '')
	{
		$this->content = $content;
	}

	/**
	 * @param string $scripts
	 */
	public function setScripts($scripts = '')
	{
		$this->scripts = $scripts;
	}

	/**
	 * @param string $styles
	 */
	public function setStyles($styles = '')
	{
		$this->styles = $styles;
	}

	/**
	 * @param string $rel_path
	 * @return string
	 */
	public function link($rel_path = '')
	{
		return rtrim($this->base_url, '/') . '/' . ltrim($rel_path, '/');
	}

	/**
	 * The redesigned site's (r/, "Version 2.0") counterpart of the current
	 * page, query string included, when r/ has a page at the same relative
	 * path; otherwise r/ itself.
	 *
	 * @return string
	 */
	public function redesignLink()
	{
		$base = trim((string) parse_url((string) config('base_url'), PHP_URL_PATH), '/');
		$base = $base === '' ? '/' : '/' . $base . '/';
		$uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
		$path = (string) parse_url($uri, PHP_URL_PATH);
		$query = (string) parse_url($uri, PHP_URL_QUERY);
		$rel = '';
		if (strpos($path, $base) === 0) {
			$rel = (string) substr($path, strlen($base));
		}
		if ($rel !== ''
			&& preg_match('~^[A-Za-z0-9_-][A-Za-z0-9_/-]*\.php$~', $rel)
			&& strpos($rel, 'admin/') !== 0
			&& is_file(__DIR__ . '/../../r/' . $rel)) {
			return $this->link('r/' . $rel . ($query !== '' ? '?' . $query : ''));
		}
		return $this->link('r/');
	}

	/**
	 * @return string
	 */
	public function getTitle()
	{
		if ($this->title) {
			return $this->title . ' - Pick55';
		}
		return 'Pick55';
	}

	/**
	 * @return string
	 */
	public function render()
	{
		ob_start();
		?><!doctype html>
<html lang="en">
	<head>
		<!-- Required meta tags -->
		<meta charset="utf-8">
		<meta name="viewport" content="width=device-width, initial-scale=1">

		<!-- Roboto -->
		<link rel="preconnect" href="https://fonts.googleapis.com">
		<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
		<link href="https://fonts.googleapis.com/css2?family=Roboto:ital,wght@0,100;0,400;0,700;1,100;1,400;1,700&display=swap" rel="stylesheet">
		<!-- Bootstrap -->
		<link href="<?=$this->link('static/css/bootstrap.min.css')?>" rel="stylesheet">
		<!-- Font Awesome -->
		<link href="<?=$this->link('static/css/font-awesome-all.min.css')?>" rel="stylesheet">
		<!-- App -->
		<link href="<?=$this->link('static/css/global.css?v=' . self::ASSET_VERSION)?>" rel="stylesheet">

		<?=$this->styles?>

		<title><?=$this->getTitle()?></title>
	</head>
	<body class="bg-light3">
		<?=$this->renderHeader()?>
		<?=$this->renderAdminBar()?>
		<?=$this->renderSeasonBar()?>
		<?=$this->renderStatsBar()?>
		<?=$this->renderAlerts(true)?>

		<div><?=$this->content?></div>

		<?=$this->renderFooter()?>

		<!-- Bootstrap -->
		<script src="<?=$this->link('static/js/bootstrap.bundle.min.js')?>"></script>
		<!-- JQuery -->
		<script src="<?=$this->link('static/js/jquery-3.6.0.min.js')?>"></script>
		<!-- ChartJS -->
		<script src="<?=$this->link('static/js/chart.js')?>"></script>
		<!-- Font Awesome -->
		<script src="<?=$this->link('static/js/font-awesome-all.min.js')?>"></script>
		<!-- Stupidtable -->
		<script src="<?=$this->link('static/js/stupidtable.min.js')?>"></script>
		<!-- App -->
		<script src="<?=$this->link('static/js/global.js?v=' . self::ASSET_VERSION)?>"></script>

		<?=$this->scripts?>
	</body>
</html>
		<?php
		return ob_get_clean();
	}

	/**
	 * @return string
	 */
	public function renderHeader()
	{
		ob_start();
		?>
		<header class="p-3 bg-dark text-white">
			<div class="container">
				<div class="d-flex flex-wrap align-items-center justify-content-center justify-content-lg-start">
					<a href="<?=$this->link()?>" class="d-flex align-items-center text-white text-decoration-none me-lg-4">
						<img src="<?=$this->link('static/img/helmet-seafoam.png')?>">
						<div class="brand">PICK<span class="brand-sub">55</span></div>
					</a>
					<?php if ($this->options['header']['show_links']): ?>
						<ul class="nav col-12 col-lg-auto me-lg-auto justify-content-center align-items-center">
							<li><a class="nav-link px-2 text-light" href="<?=$this->link()?>">Home</a></li>
							<li><a class="nav-link px-2 text-light" href="<?=$this->link('rules.php')?>">Rules</a></li>
							<li><a class="nav-link px-2 text-info" href="<?=$this->redesignLink()?>">Version 2.0</a></li>
						</ul>
						<div class="d-flex flex-wrap justify-content-center gap-1">
							<?php if (!Auth::authed()): ?>
								<a class="btn btn-outline-light" href="<?=$this->link('auth/login.php')?>">Sign In</a>
								<a class="btn btn-primary" href="<?=$this->link('auth/signup.php')?>">Sign Up</a>
							<?php else: ?>
								<?php if ($this->app->getPickableWeek()): ?>
									<a class="btn btn-sm btn-outline-info" href="<?=$this->link('season/week/pick.php')?>">Make Picks</a>
								<?php elseif ($this->app->getViewableWeek()): ?>
									<a class="btn btn-sm btn-outline-info" href="<?=$this->link('season/week/results.php')?>">Scores</a>
								<?php endif; ?>
								<a class="btn btn-sm btn-outline-light" href="<?=$this->link('season/index.php')?>">My Season</a>
								<a class="btn btn-sm btn-outline-light" href="<?=$this->link('season/standings.php')?>">Standings</a>
								<a class="btn btn-sm btn-outline-light" href="<?=$this->link('stats/index.php')?>">Stats</a>
								<?php if (Auth::isAdmin()): ?>
									<a class="btn btn-sm btn-secondary" href="<?=$this->link('admin/index.php')?>">Admin</a>
								<?php endif; ?>
							<?php endif; ?>
						</div>
					<?php endif; ?>
				</div>
			</div>
		</header>
		<?php
		return ob_get_clean();
	}

	/**
	 * @return string
	 */
	public function renderAdminBar()
	{
		if (!Auth::isAdmin()) {
			return '';
		}
		if (!$this->options['admin_bar']['show']) {
			return '';
		}
		$title = 'Admin';
		if ($this->options['admin_bar']['title']) {
			$title = $this->options['admin_bar']['title'];
		}
		$sub_links = [];
		if ($this->options['admin_bar']['sub_bar']['type']) {
			$obj_id = null;
			if ($this->options['admin_bar']['sub_bar']['obj']) {
				$obj_id = $this->options['admin_bar']['sub_bar']['obj']->id;
			}
			switch ($this->options['admin_bar']['sub_bar']['type']) {
				case 'seasons':
					$sub_links = [
						['admin/seasons/index.php', 'List'],
						['admin/seasons/create.php', 'Create'],
						['admin/seasons/activate.php', 'Activate'],
					];
					break;
				case 'season':
					$sub_links = [
						['admin/seasons/season/index.php?id=' . $obj_id, 'Settings'],
						['admin/seasons/season/update-players.php?id=' . $obj_id, 'Modify Players'],
						['admin/seasons/season/players.php?id=' . $obj_id, 'Players'],
					];
					break;
				case 'week':
					$sub_links = [
						['admin/weeks/week/index.php?id=' . $obj_id, 'Settings'],
						['admin/weeks/week/pools.php?id=' . $obj_id, 'Pools'],
						['admin/weeks/week/picks.php?id=' . $obj_id, 'Picks'],
					];
					break;
				case 'teams':
					$sub_links = [
						['admin/teams/index.php', 'List'],
						['admin/teams/create.php', 'Create'],
					];
					break;
				case 'games':
					$sub_links = [
						['admin/games/index.php', 'Week - Unset'],
						['admin/games/index.php?show=week', 'Week - All'],
						['admin/games/index.php?show=all', 'All'],
					];
					break;
				case 'formats':
					$sub_links = [
						['admin/formats/index.php', 'List'],
						['admin/formats/format/index.php', 'Create'],
					];
					break;
			}
		}
		ob_start();
		?>
		<nav class="p-2 bg-secondary">
			<div class="container">
				<div class="d-flex flex-wrap align-items-start justify-content-between flex-column gap-1">
					<div class="d-flex justify-content-center align-items-center flex-wrap gap-1">
						<?php foreach ([
							['admin/seasons/season/index.php', 'Season'],
							['admin/weeks/week/index.php?active=1', 'Past Week'],
							['admin/weeks/week/index.php?next=1', 'Next Week'],
							['admin/games/index.php', 'Games'],
							['admin/teams/index.php', 'Teams'],
							['admin/formats/index.php', 'Formats'],
						] as $link): ?>
							<a class="btn btn-sm btn-outline-light" href="<?=$this->link($link[0])?>"><?=$link[1]?></a>
						<?php endforeach; ?>
					</div>

					<div class="d-flex justify-content-center align-items-center gap-1 flex-wrap">
						<?php if (sizeof($sub_links)): ?>
							<div class="btn btn-sm btn-placeholder text-white"><?=$title?>:</div>
							<?php foreach ($sub_links as $link): ?>
								<a class="btn btn-sm btn-darken text-white" href="<?=$this->link($link[0])?>"><?=$link[1]?></a>
							<?php endforeach; ?>
						<?php endif; ?>
					</div>
				</div>
			</div>
		</nav>
		<?php
		return ob_get_clean();
	}

	/**
	 * @return string
	 */
	public function renderSeasonBar()
	{
		if (!$this->options['season_bar']['show']) {
			return '';
		}
		$app = App::get();
		$me = Auth::user();
		$season = $app->getSeason();
		if (!$season) {
			return '';
		}
		$season_options = Season::getListForUser($me ? $me->id : null);
		$title = $season->name;
		if ($this->options['season_bar']['title']) {
			$title = $this->options['season_bar']['title'];
		}
		ob_start();
		?>
		<nav class="navbar navbar-dark bg-dark2">
			<div class="container">
				<div class="d-flex w-100 justify-content-sm-between align-items-center flex-wrap justify-content-center">
					<a class="navbar-brand"><?=$title?></a>
					<?php if (sizeof($season_options) && $this->options['season_bar']['show_select']): ?>
						<div class="btn-group">
							<a
								href="#"
								class="btn btn-outline-light dropdown-toggle btn-sm"
								data-bs-toggle="dropdown"
								aria-expanded="false"
								><?=$season->name?></a>
							<ul class="dropdown-menu dropdown-menu-end">
								<?php foreach ($season_options as $season_option): ?>
									<li>
										<a
											href="<?=$this->link($this->options['season_bar']['rel_path'] . '?id=' . $season_option->id)?>"
											class="dropdown-item <?=$season_option->id == $season->id ? 'fw-bold' : ''?>"
											href="#"
											><?=$season_option->name?></a>
									</li>
								<?php endforeach; ?>
							</ul>
						</div>
					<?php endif; ?>
				</div>
			</div>
		</nav>
		<?php
		return ob_get_clean();
	}

	/**
	 * The stats/ pages' tab bar. options['stats_bar']['active'] names the
	 * current page by its key below.
	 *
	 * @return string
	 */
	public function renderStatsBar()
	{
		if (!$this->options['stats_bar']['show']) {
			return '';
		}
		$tabs = [
			'overview' => ['stats/index.php', 'fa-book-open', 'Record Book'],
			'fame' => ['stats/fame.php', 'fa-trophy', 'Wall of Fame'],
			'shame' => ['stats/shame.php', 'fa-egg', 'Wall of Shame'],
			'leaderboard' => ['stats/leaderboard.php', 'fa-list-ol', 'Leaderboards'],
			'seasons' => ['stats/seasons.php', 'fa-calendar-alt', 'Best Seasons'],
		];
		$active = $this->options['stats_bar']['active'];
		ob_start();
		?>
		<nav class="navbar navbar-dark bg-dark2 stats-bar">
			<div class="container">
				<div class="d-flex w-100 justify-content-sm-between align-items-center flex-wrap justify-content-center gap-1">
					<a class="navbar-brand" href="<?=$this->link('stats/index.php')?>">All-Time Stats</a>
					<div class="d-flex flex-wrap justify-content-center gap-1">
						<?php foreach ($tabs as $key => $tab): ?>
							<a
								class="btn btn-sm <?=$key == $active ? 'btn-light' : 'btn-outline-light'?>"
								href="<?=$this->link($tab[0])?>"
								><i class="fas <?=$tab[1]?> me-1"></i><?=$tab[2]?></a>
						<?php endforeach; ?>
					</div>
				</div>
			</div>
		</nav>
		<?php
		return ob_get_clean();
	}

	/**
	 * @param bool $default
	 * @return string
	 */
	public function renderAlerts($default = false)
	{
		$alerts = Alert::get();
		if (!sizeof($alerts)) {
			return '';
		}
		ob_start();
		?>
		<?php if ($default): ?>
			<div class="container">
		<?php endif; ?>

		<div class="alerts <?=$default ? 'pt-2' : 'mb-3'?>">
			<?php
			foreach ($alerts as $type => $msgs):
				$subclass = 'secondary';
				switch ($type) {
					case 'success': $subclass = 'success'; break;
					case 'error': $subclass = 'danger'; break;
					case 'warning': $subclass = 'warning'; break;
					case 'info': $subclass = 'info'; break;
				}
				foreach ($msgs as $msg): ?>
					<div class="alert alert-<?=$subclass?>" role="alert">
						<?=$msg?>
					</div>
				<?php endforeach; ?>
			<?php endforeach; ?>
		</div>

		<?php if ($default): ?>
			</div>
		<?php endif; ?>
		<?php
		Alert::clear();
		return ob_get_clean();
	}

	/**
	 * @return string
	 */
	public function renderFooter()
	{
		ob_start();
		?>
		<div class="container">
			<footer class="d-flex flex-wrap justify-content-between align-items-center py-3 my-4">
				<p class="col mb-0 text-muted">&copy; <?=date("Y")?> Pick55</p>
				<ul class="nav col col-auto justify-content-end flex-column flex-sm-row text-end text-sm-start gap-1">
					<li class="nav-item"><a class="nav-link px-2 text-muted" href="<?=$this->link()?>">Home</a></li>
					<li class="nav-item"><a class="nav-link px-2 text-muted" href="<?=$this->redesignLink()?>">Version 2.0</a></li>
					<?php if (!Auth::authed()): ?>
						<li class="nav-item"><a class="nav-link px-2 text-muted" href="<?=$this->link('auth/login.php')?>">Sign In</a></li>
						<li class="nav-item"><a class="nav-link px-2 text-muted" href="<?=$this->link('auth/signup.php')?>">Sign Up</a></li>
					<?php else: ?>
						<li class="nav-item"><a class="nav-link px-2 text-muted" href="<?=$this->link('account/index.php')?>">My Account</a></li>
						<li class="nav-item"><a class="nav-link px-2 text-muted" href="<?=$this->link('auth/logout.php')?>">Sign Out</a></li>
					<?php endif; ?>
				</ul>
			</footer>
		</div>
		<?php
		return ob_get_clean();
	}
}
