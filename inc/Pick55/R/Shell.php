<?php

namespace Pick55\R;

use Pick55\Alert;
use Pick55\Auth;

/**
 * The redesign's page shell (docs/redesign.md section 3): the document, the
 * top bar and bottom tab bar, flash alerts, per-page assets, and the partial
 * fragment app.js swaps in on navigation.
 *
 *   require_once __DIR__ . '/../inc/_inc.php';
 *   use Pick55\R\Shell;
 *   Shell::guard();
 *   $shell = new Shell;
 *   $shell->setTitle('Standings');
 *   $shell->setNav('standings');
 *   $shell->addStyle('css/pages/standings.css');
 *   $shell->addScript('js/pages/standings.js');
 *   $shell->setModule('standings', ['season_id' => 18]);
 *   ob_start(); ... $shell->setContent(ob_get_clean());
 *   print $shell->render();
 */
class Shell
{
	/**
	 * The main tabs: key => [rel path, label, icon].
	 */
	const TABS = [
		'today' => ['index.php', 'Today', 'home'],
		'picks' => ['season/week/pick.php', 'Picks', 'list-checks'],
		'results' => ['season/week/results.php', 'Results', 'trophy'],
		'standings' => ['season/standings.php', 'Standings', 'medal'],
		'stats' => ['stats/index.php', 'Stats', 'bar-chart'],
	];

	/** @var Context */
	public $ctx;

	private $title = '';
	private $nav = 'none';
	private $content = '';
	private $module = null;
	private $props = [];
	private $styles = [];
	private $scripts = [];
	private $page_class = '';

	public function __construct()
	{
		$this->ctx = Context::get();
	}

	// ------------------------------------------------------------------
	// Links and assets

	/**
	 * @param string $rel  path under r/, e.g. 'season/standings.php?id=18'
	 * @return string
	 */
	public function link($rel = '')
	{
		return config('base_url') . 'r/' . ltrim((string) $rel, '/');
	}

	/**
	 * @param string $rel  path of the classic site, e.g. 'admin/index.php'
	 * @return string
	 */
	public function classicLink($rel = '')
	{
		return config('base_url') . ltrim((string) $rel, '/');
	}

	/**
	 * A file under r/static/, versioned by its modification time.
	 *
	 * @param string $rel  e.g. 'css/pages/today.css'
	 * @return string
	 */
	public function asset($rel)
	{
		$rel = ltrim((string) $rel, '/');
		$path = dirname(__DIR__, 3) . '/r/static/' . $rel;
		$v = is_file($path) ? filemtime($path) : 0;
		return $this->link('static/' . $rel) . '?v=' . $v;
	}

	// ------------------------------------------------------------------
	// Page setup

	/**
	 * @param string $title  shown as "$title · Pick55"
	 */
	public function setTitle($title)
	{
		$this->title = (string) $title;
	}

	/**
	 * @param string $key  today | picks | results | standings | stats | account | none
	 */
	public function setNav($key)
	{
		$this->nav = (string) $key;
	}

	/**
	 * @param string $html  the page's content (inside .page in #app-main)
	 */
	public function setContent($html)
	{
		$this->content = (string) $html;
	}

	/**
	 * The JS module (registered with P55.page) to init after render.
	 *
	 * @param string $name
	 * @param array $props  JSON-encodable
	 */
	public function setModule($name, array $props = [])
	{
		$this->module = (string) $name;
		$this->props = $props;
	}

	/**
	 * Extra classes on the .page wrapper, e.g. 'page-narrow', 'page-auth'.
	 *
	 * @param string $class
	 */
	public function setPageClass($class)
	{
		$this->page_class = (string) $class;
	}

	/**
	 * @param string $rel  under r/static/, e.g. 'css/pages/today.css'
	 */
	public function addStyle($rel)
	{
		$this->styles[$rel] = $this->asset($rel);
	}

	/**
	 * @param string $rel  under r/static/, e.g. 'js/pages/today.js'
	 */
	public function addScript($rel)
	{
		$this->scripts[$rel] = $this->asset($rel);
	}

	/**
	 * @return string
	 */
	public function getTitle()
	{
		return $this->title !== '' ? $this->title . " \u{00B7} Pick55" : 'Pick55';
	}

	// ------------------------------------------------------------------
	// Guards and request helpers

	/**
	 * Signed out: to r/auth/login.php, carrying the current r/ path as ?r=.
	 */
	public static function guard()
	{
		if (Auth::authed()) {
			return;
		}
		$rel = self::currentRel();
		$query = '';
		if ($rel !== '' && $rel !== 'index.php' && self::safeReturn($rel) !== null) {
			$query = '?r=' . urlencode($rel);
		}
		redir('r/auth/login.php' . $query);
	}

	/**
	 * Signed in: to r/index.php.
	 */
	public static function guardGuest()
	{
		if (Auth::authed()) {
			redir('r/index.php');
		}
	}

	/**
	 * @return bool  the request wants the JSON fragment
	 */
	public static function isPartial()
	{
		if (isset($_SERVER['HTTP_X_P55_PARTIAL']) && $_SERVER['HTTP_X_P55_PARTIAL'] === '1') {
			return true;
		}
		return isset($_GET['_partial']) && $_GET['_partial'] === '1';
	}

	/**
	 * @return string  the site's base path, e.g. '/pick55/'
	 */
	public static function basePath()
	{
		$path = parse_url((string) config('base_url'), PHP_URL_PATH);
		return '/' . trim((string) $path, '/') . '/';
	}

	/**
	 * The request URI without the _partial marker: '/pick55/r/season/standings.php?id=18'.
	 *
	 * @return string
	 */
	public static function currentUrl()
	{
		$uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';
		$path = (string) parse_url($uri, PHP_URL_PATH);
		$query = (string) parse_url($uri, PHP_URL_QUERY);
		if ($query !== '') {
			$parts = array_filter(explode('&', $query), function ($pair) {
				return $pair !== '' && strpos($pair, '_partial=') !== 0 && $pair !== '_partial';
			});
			$query = implode('&', $parts);
		}
		return $path . ($query !== '' ? '?' . $query : '');
	}

	/**
	 * The current URL relative to r/: 'season/standings.php?id=18', '' for r/ itself.
	 *
	 * @return string
	 */
	public static function currentRel()
	{
		$url = self::currentUrl();
		$prefix = self::basePath() . 'r/';
		if (strpos($url, $prefix) === 0) {
			return (string) substr($url, strlen($prefix));
		}
		return '';
	}

	/**
	 * Validates a ?r= return path: a relative .php path under r/ with an
	 * optional query, never an auth or api path, never leaving r/.
	 *
	 * @param string $r
	 * @return string|null
	 */
	public static function safeReturn($r)
	{
		$r = trim((string) $r);
		if ($r === '') {
			return null;
		}
		if (!preg_match('~^[A-Za-z0-9_-][A-Za-z0-9_/-]*\.php(\?[A-Za-z0-9_=&%.+,:\[\]-]*)?$~', $r)) {
			return null;
		}
		if (strpos($r, '..') !== false || strpos($r, '//') !== false) {
			return null;
		}
		if (strpos($r, 'auth/') === 0 || strpos($r, 'api/') === 0) {
			return null;
		}
		return $r;
	}

	// ------------------------------------------------------------------
	// Components shared by every page

	/**
	 * The standard empty state (no season, not a player, nothing yet).
	 *
	 * @param string $title
	 * @param string $text  plain text
	 * @param array $actions  list of ['label' => ..., 'href' => full URL, 'kind' => 'primary'|'ghost', 'native' => bool]
	 * @param string $icon
	 * @return string
	 */
	public static function empty($title, $text = '', array $actions = [], $icon = 'info')
	{
		ob_start();
		?>
		<div class="empty enter">
			<div class="empty-icon"><?=Icons::svg($icon)?></div>
			<h2 class="empty-title"><?=h($title)?></h2>
			<?php if ((string) $text !== ''): ?>
				<p class="empty-text"><?=h($text)?></p>
			<?php endif; ?>
			<?php if (sizeof($actions)): ?>
				<div class="empty-actions">
					<?php foreach ($actions as $action): ?>
						<a
							class="btn <?=(isset($action['kind']) && $action['kind'] === 'primary') ? 'btn-primary' : 'btn-ghost'?>"
							href="<?=h($action['href'])?>"
							<?=!empty($action['native']) ? 'data-native' : ''?>
							><?=h($action['label'])?></a>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Concentric progress rings (outer first). app.js paints a ring by
	 * setting its --p custom property (0-100).
	 *
	 * @param array $rings  list of [fraction 0..1, tone: brand|accent|good|warn|bad|live]
	 * @param string $center_html  HTML inside the ring (escape it yourself)
	 * @param int $size  px
	 * @param string $label  accessible label (plain text)
	 * @return string
	 */
	public static function ring(array $rings, $center_html = '', $size = 96, $label = '')
	{
		$html = '<div class="progress-ring" style="--size:' . (int) $size . 'px" role="img" aria-label="' . h($label) . '">';
		$html .= '<svg viewBox="0 0 100 100" aria-hidden="true" focusable="false">';
		$stroke = sizeof($rings) > 1 ? 8 : 9;
		foreach (array_values($rings) as $i => $ring) {
			$r = 45 - $i * ($stroke + 3);
			$p = max(0, min(100, round((float) $ring[0] * 100, 1)));
			$tone = isset($ring[1]) ? preg_replace('/[^a-z]/', '', $ring[1]) : 'brand';
			$html .= '<circle class="ring-track" cx="50" cy="50" r="' . $r . '" stroke-width="' . $stroke . '" pathLength="100"/>';
			$html .= '<circle class="ring-bar ring-' . $tone . ($p <= 0 ? ' is-zero' : '') . '" data-ring="' . $i . '" cx="50" cy="50" r="' . $r . '" stroke-width="' . $stroke . '" pathLength="100" style="--p:' . $p . '"/>';
		}
		$html .= '</svg>';
		$html .= '<div class="ring-center">' . $center_html . '</div>';
		$html .= '</div>';
		return $html;
	}

	/**
	 * A tab's badge markup ('' for none). app.js renders the same markup.
	 *
	 * @param string|null $value  'LIVE', 'done', or short text such as '2d'
	 * @return string
	 */
	public static function badge($value)
	{
		if ($value === null || $value === '') {
			return '';
		}
		if ($value === 'LIVE') {
			return '<span class="tab-badge badge-live" title="Games in progress">LIVE</span>';
		}
		if ($value === 'done') {
			return '<span class="tab-badge badge-done" title="Picks are in">' . Icons::svg('check') . '</span>';
		}
		return '<span class="tab-badge badge-due" title="Picks due in ' . h($value) . '">' . h($value) . '</span>';
	}

	// ------------------------------------------------------------------
	// Rendering

	/**
	 * @return string  the flash alerts, then cleared
	 */
	private function renderAlerts()
	{
		$alerts = Alert::get();
		Alert::clear();
		$html = '';
		$icons = ['success' => 'check', 'error' => 'alert-triangle', 'warning' => 'alert-triangle', 'info' => 'info'];
		foreach ($alerts as $type => $msgs) {
			foreach ($msgs as $msg) {
				$type = isset($icons[$type]) ? $type : 'info';
				// Good news becomes a toast when JS runs; problems stay on the page.
				$toast = in_array($type, ['success', 'info'], true) ? ' data-toast="' . h($type) . '"' : '';
				$role = $type === 'error' ? 'alert' : 'status';
				$html .= '<div class="alert alert-' . h($type) . '" role="' . $role . '"' . $toast . '>'
					. Icons::svg($icons[$type]) . '<div class="alert-text">' . h($msg) . '</div></div>';
			}
		}
		return $html !== '' ? '<div class="alerts">' . $html . '</div>' : '';
	}

	/**
	 * @return string  the .page wrapper with alerts and content
	 */
	private function renderPage()
	{
		$attrs = '';
		if ($this->module) {
			$attrs .= ' data-module="' . h($this->module) . '"';
			$attrs .= " data-props='" . json_encode((object) $this->props, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE) . "'";
		}
		$class = trim('page ' . ($this->nav !== 'none' ? 'page-' . preg_replace('/[^a-z]/', '', $this->nav) . ' ' : '') . $this->page_class);
		return '<div class="' . h($class) . '"' . $attrs . '>' . $this->renderAlerts() . $this->content . '</div>';
	}

	/**
	 * The full document, or the JSON fragment for a partial request.
	 *
	 * @return string
	 */
	public function render()
	{
		if (!headers_sent()) {
			header('Vary: X-P55-Partial');
		}
		if (self::isPartial()) {
			return $this->renderPartial();
		}
		return $this->renderDocument();
	}

	/**
	 * @return string
	 */
	private function renderPartial()
	{
		if (!headers_sent()) {
			header('Content-Type: application/json; charset=utf-8');
			header('Cache-Control: no-store');
		}
		$data = [
			'title' => $this->getTitle(),
			'url' => self::currentUrl(),
			'nav' => $this->nav,
			'badges' => (object) $this->ctx->badges(),
			'authed' => Auth::authed(),
			'mode' => $this->ctx->mode,
			'html' => $this->renderPage(),
			'module' => $this->module ? ['name' => $this->module, 'props' => (object) $this->props] : null,
			'styles' => array_values($this->styles),
			'scripts' => array_values($this->scripts),
		];
		return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
	}

	/**
	 * @return string
	 */
	private function renderDocument()
	{
		if (!headers_sent()) {
			header('Content-Type: text/html; charset=utf-8');
		}
		$authed = Auth::authed();
		$me = $authed ? Auth::user() : null;
		$badges = $this->ctx->badges();
		$page_html = $this->renderPage();
		$classic_rel = self::currentRel();
		ob_start();
		?><!doctype html>
<html lang="en" data-base="<?=h(self::basePath() . 'r/')?>">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
	<meta name="color-scheme" content="light dark">
	<meta name="theme-color" content="#f3f5f4">
	<title><?=h($this->getTitle())?></title>
	<script>(function(d){d.documentElement.classList.add('js');try{var t=localStorage.getItem('p55-theme');if(t==='light'||t==='dark'){d.documentElement.setAttribute('data-theme',t);}}catch(e){}})(document);</script>
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Archivo:wdth,wght@62..125,100..900&amp;display=swap">
	<link rel="stylesheet" href="<?=h($this->asset('css/app.css'))?>">
	<?php foreach ($this->styles as $url): ?>
		<link rel="stylesheet" href="<?=h($url)?>" data-p55-asset>
	<?php endforeach; ?>
	<link rel="icon" type="image/png" href="<?=h($this->classicLink('static/img/helmet-seafoam.png'))?>">
</head>
<body data-nav="<?=h($this->nav)?>" data-authed="<?=$authed ? '1' : '0'?>">
	<a class="skip-link" href="#app-main">Skip to content</a>
	<div class="nav-progress" id="p55-progress" aria-hidden="true"></div>

	<header class="topbar">
		<div class="topbar-inner">
			<a class="brand" href="<?=h($this->link('index.php'))?>" aria-label="Pick55 home">
				<img class="brand-mark" src="<?=h($this->classicLink('static/img/helmet-seafoam.png'))?>" alt="" width="30" height="30">
				<span class="brand-word">PICK<span>55</span></span>
			</a>
			<?php if ($authed): ?>
				<nav class="topnav" aria-label="Main">
					<?php foreach (self::TABS as $key => $tab): ?>
						<a
							class="topnav-link"
							href="<?=h($this->link($tab[0]))?>"
							data-nav="<?=h($key)?>"
							data-prefetch
							<?=$this->nav === $key ? 'aria-current="page"' : ''?>
							><?=Icons::svg($tab[2])?><span><?=h($tab[1])?></span><span class="badge-slot" data-badge="<?=h($key)?>"><?=self::badge(isset($badges[$key]) ? $badges[$key] : null)?></span></a>
					<?php endforeach; ?>
				</nav>
			<?php endif; ?>
			<div class="topbar-end">
				<?php if ($authed): ?>
					<?=$this->renderAccountMenu($me)?>
				<?php else: ?>
					<button type="button" class="btn btn-icon btn-quiet" data-theme-cycle aria-label="Change theme"><?=Icons::svg('sun', 'theme-icon-light')?><?=Icons::svg('moon', 'theme-icon-dark')?></button>
					<a class="btn btn-quiet btn-sm" href="<?=h($this->link('auth/login.php'))?>">Sign in</a>
					<a class="btn btn-primary btn-sm" href="<?=h($this->link('auth/signup.php'))?>">Sign up</a>
				<?php endif; ?>
			</div>
		</div>
	</header>

	<main id="app-main" tabindex="-1"><?=$page_html?></main>

	<footer class="site-footer">
		<div class="site-footer-inner">
			<span>&copy; <?=date('Y')?> Pick55</span>
			<nav class="site-footer-links" aria-label="Footer">
				<a href="<?=h($this->link('rules.php'))?>">Rules</a>
				<a href="<?=h($this->classicLink($classic_rel))?>" data-native data-classic>Classic site</a>
				<?php if ($authed && Auth::isAdmin()): ?>
					<a href="<?=h($this->classicLink('admin/index.php'))?>" data-native>Admin</a>
				<?php endif; ?>
			</nav>
		</div>
	</footer>

	<?php if ($authed): ?>
		<nav class="tabbar" aria-label="Main">
			<?php foreach (self::TABS as $key => $tab): ?>
				<a
					class="tabbar-link"
					href="<?=h($this->link($tab[0]))?>"
					data-nav="<?=h($key)?>"
					data-prefetch
					<?=$this->nav === $key ? 'aria-current="page"' : ''?>
					><span class="tabbar-icon"><?=Icons::svg($tab[2])?><span class="badge-slot" data-badge="<?=h($key)?>"><?=self::badge(isset($badges[$key]) ? $badges[$key] : null)?></span></span><span class="tabbar-label"><?=h($tab[1])?></span></a>
			<?php endforeach; ?>
		</nav>
	<?php endif; ?>

	<div class="toasts" id="p55-toasts" role="status" aria-live="polite"></div>

	<script src="<?=h($this->asset('js/app.js'))?>" defer></script>
	<?php foreach ($this->scripts as $url): ?>
		<script src="<?=h($url)?>" defer data-p55-asset></script>
	<?php endforeach; ?>
</body>
</html>
<?php
		return ob_get_clean();
	}

	/**
	 * @param \Pick55\Models\User $me
	 * @return string
	 */
	private function renderAccountMenu($me)
	{
		ob_start();
		?>
		<details class="menu" data-menu>
			<summary class="avatar-btn<?=$this->nav === 'account' ? ' is-current' : ''?>" aria-label="Account menu">
				<span class="avatar" aria-hidden="true"><?=h(Fmt::initials($me))?></span>
			</summary>
			<div class="menu-panel">
				<div class="menu-head">
					<span class="avatar avatar-lg" aria-hidden="true"><?=h(Fmt::initials($me))?></span>
					<div>
						<div class="menu-name"><?=h(Fmt::name($me))?></div>
						<div class="menu-sub"><?=h($this->ctx->season ? $this->ctx->season->name : 'Pick55')?></div>
					</div>
				</div>
				<a class="menu-item" href="<?=h($this->link('season/index.php'))?>"><?=Icons::svg('calendar')?>My season</a>
				<a class="menu-item" href="<?=h($this->link('account/index.php'))?>"><?=Icons::svg('user')?>Account &amp; friends</a>
				<a class="menu-item" href="<?=h($this->link('rules.php'))?>"><?=Icons::svg('book-open')?>Rules</a>
				<div class="menu-sep" role="separator"></div>
				<div class="menu-theme">
					<span>Theme</span>
					<div class="seg seg-sm" role="group" aria-label="Theme">
						<button type="button" data-theme-set="system" aria-pressed="false" title="Match system"><?=Icons::svg('monitor')?><span class="sr-only">System</span></button>
						<button type="button" data-theme-set="light" aria-pressed="false" title="Light"><?=Icons::svg('sun')?><span class="sr-only">Light</span></button>
						<button type="button" data-theme-set="dark" aria-pressed="false" title="Dark"><?=Icons::svg('moon')?><span class="sr-only">Dark</span></button>
					</div>
				</div>
				<div class="menu-sep" role="separator"></div>
				<a class="menu-item" href="<?=h($this->classicLink(self::currentRel()))?>" data-native data-classic><?=Icons::svg('external-link')?>Classic site</a>
				<?php if (Auth::isAdmin()): ?>
					<a class="menu-item" href="<?=h($this->classicLink('admin/index.php'))?>" data-native><?=Icons::svg('shield')?>Admin</a>
				<?php endif; ?>
				<a class="menu-item" href="<?=h($this->link('auth/logout.php'))?>" data-native><?=Icons::svg('log-out')?>Sign out</a>
			</div>
		</details>
		<?php
		return ob_get_clean();
	}
}
