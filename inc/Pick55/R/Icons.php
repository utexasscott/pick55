<?php

namespace Pick55\R;

/**
 * The redesign's icon set: 24x24 stroke icons in the Lucide style (2px
 * stroke, round caps and joins, currentColor), drawn for Pick55 so no icon
 * font or library is loaded. Size them with CSS (`.icon` is 1.25em square).
 *
 *   Icons::svg('trophy')             <svg class="icon" ...>
 *   Icons::svg('check', 'icon-sm')   extra classes
 *
 * app.js carries a copy of the few icons it draws itself (toasts, badges).
 */
class Icons
{
	/**
	 * name => inner SVG markup
	 *
	 * @var array
	 */
	private static $paths = [
		'home' => '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V20a1 1 0 0 0 1 1h4v-6h4v6h4a1 1 0 0 0 1-1V9.5"/>',
		'list-checks' => '<path d="m3 6 2 2 3.5-3.5"/><path d="m3 13 2 2 3.5-3.5"/><path d="M12.5 6.5H21"/><path d="M12.5 13.5H21"/><path d="M12.5 20H21"/><path d="M4 20h4"/>',
		'trophy' => '<path d="M8 21h8"/><path d="M12 17v4"/><path d="M7 4h10v5a5 5 0 0 1-10 0V4Z"/><path d="M17 6h2.5a.5.5 0 0 1 .5.5V7a3 3 0 0 1-3 3"/><path d="M7 6H4.5a.5.5 0 0 0-.5.5V7a3 3 0 0 0 3 3"/>',
		'bar-chart' => '<path d="M4 20h16"/><path d="M7 16v-5"/><path d="M12 16V6"/><path d="M17 16v-8"/>',
		'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
		'settings' => '<path d="M4 6h9"/><path d="M17 6h3"/><path d="M4 12h3"/><path d="M11 12h9"/><path d="M4 18h11"/><path d="M19 18h1"/><circle cx="15" cy="6" r="2"/><circle cx="9" cy="12" r="2"/><circle cx="17" cy="18" r="2"/>',
		'log-out' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/>',
		'log-in' => '<path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><path d="m10 17 5-5-5-5"/><path d="M15 12H3"/>',
		'chevron-left' => '<path d="m15 18-6-6 6-6"/>',
		'chevron-right' => '<path d="m9 18 6-6-6-6"/>',
		'chevron-up' => '<path d="m18 15-6-6-6 6"/>',
		'chevron-down' => '<path d="m6 9 6 6 6-6"/>',
		'check' => '<path d="M20 6 9 17l-5-5"/>',
		'x' => '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
		'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
		'flame' => '<path d="M12 22c3.9 0 7-2.9 7-6.8 0-3.4-2.1-5.6-3.9-7.7-.4 1.8-1.4 3-2.8 3.7.4-3.3-.9-6.4-3.1-8.2-.9 3.4-4.2 6-4.2 11.1C5 19 8.1 22 12 22Z"/><path d="M12 22c-1.7 0-3-1.3-3-3 0-1.9 1.6-3 2.4-4.6.9 1.3 3.6 2.4 3.6 4.6 0 1.7-1.3 3-3 3Z"/>',
		'grip-vertical' => '<circle cx="9" cy="5" r="1"/><circle cx="9" cy="12" r="1"/><circle cx="9" cy="19" r="1"/><circle cx="15" cy="5" r="1"/><circle cx="15" cy="12" r="1"/><circle cx="15" cy="19" r="1"/>',
		'arrow-up' => '<path d="M12 19V5"/><path d="m5 12 7-7 7 7"/>',
		'arrow-down' => '<path d="M12 5v14"/><path d="m19 12-7 7-7-7"/>',
		'sun' => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2"/><path d="M12 20v2"/><path d="m4.9 4.9 1.4 1.4"/><path d="m17.7 17.7 1.4 1.4"/><path d="M2 12h2"/><path d="M20 12h2"/><path d="m6.3 17.7-1.4 1.4"/><path d="m19.1 4.9-1.4 1.4"/>',
		'moon' => '<path d="M20.5 13.2A8.5 8.5 0 1 1 10.8 3.5a6.6 6.6 0 0 0 9.7 9.7Z"/>',
		'monitor' => '<rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8"/><path d="M12 17v4"/>',
		'info' => '<circle cx="12" cy="12" r="9.5"/><path d="M12 16v-4.5"/><path d="M12 8h.01"/>',
		'alert-triangle' => '<path d="M10.3 3.9 1.9 18a2 2 0 0 0 1.7 3h16.8a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
		'dollar-sign' => '<path d="M12 2v20"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>',
		'users' => '<circle cx="9" cy="8" r="4"/><path d="M2 21a7 7 0 0 1 14 0"/><path d="M16 3.3a4 4 0 0 1 0 7.4"/><path d="M22 21a7 7 0 0 0-4.5-6.5"/>',
		'star' => '<path d="m12 2.5 2.9 6 6.6.9-4.8 4.6 1.2 6.5L12 17.4l-5.9 3.1 1.2-6.5-4.8-4.6 6.6-.9L12 2.5Z"/>',
		'medal' => '<circle cx="12" cy="15" r="6"/><path d="M8.6 10 5.5 3h4l2.5 5.5"/><path d="M15.4 10 18.5 3h-4L12 8.5"/><path d="m12 12.3.8 1.7 1.8.2-1.3 1.2.4 1.8-1.7-.9-1.7.9.4-1.8-1.3-1.2 1.8-.2.8-1.7Z"/>',
		'calendar' => '<rect x="3" y="4.5" width="18" height="17" rx="2"/><path d="M16 2.5v4"/><path d="M8 2.5v4"/><path d="M3 10h18"/>',
		'external-link' => '<path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>',
		'refresh' => '<path d="M21 12a9 9 0 0 1-15.4 6.4L3 16"/><path d="M3 12a9 9 0 0 1 15.4-6.4L21 8"/><path d="M21 3v5h-5"/><path d="M3 21v-5h5"/>',
		'search' => '<circle cx="11" cy="11" r="7"/><path d="m20.5 20.5-4.3-4.3"/>',
		'plus' => '<path d="M12 5v14"/><path d="M5 12h14"/>',
		'minus' => '<path d="M5 12h14"/>',
		'football' => '<path d="M4.6 19.4C1.8 16.6 2.6 10 6.3 6.3S16.6 1.8 19.4 4.6s1.7 9.4-2 13.1-10 4.5-12.8 1.7Z"/><path d="m9.5 14.5 5-5"/><path d="m10.2 12.4 1.4 1.4"/><path d="m11.3 11.3 1.4 1.4"/><path d="m12.4 10.2 1.4 1.4"/><path d="M14.6 3.4l6 6"/><path d="M3.4 14.6l6 6"/>',
		'menu' => '<path d="M4 6h16"/><path d="M4 12h16"/><path d="M4 18h16"/>',
		'book-open' => '<path d="M2 4.5h6a4 4 0 0 1 4 4V21a3 3 0 0 0-3-3H2Z"/><path d="M22 4.5h-6a4 4 0 0 0-4 4V21a3 3 0 0 1 3-3h7Z"/>',
		'egg' => '<path d="M12 22c-4 0-7-2.9-7-7.2C5 9.5 8.3 2 12 2s7 7.5 7 12.8c0 4.3-3 7.2-7 7.2Z"/>',
		'list-ordered' => '<path d="M10 6h11"/><path d="M10 12h11"/><path d="M10 18h11"/><path d="M4 6h1v4"/><path d="M4 10h2"/><path d="M6 18H4c0-1 2-2 2-3s-1-1.5-2-1"/>',
		'undo' => '<path d="M9 14 4 9l5-5"/><path d="M4 9h10.5a5.5 5.5 0 0 1 0 11H11"/>',
		'lock' => '<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
		'mail' => '<rect x="2.5" y="4.5" width="19" height="15" rx="2"/><path d="m3 6 9 7 9-7"/>',
		'key' => '<circle cx="7.5" cy="15.5" r="4.5"/><path d="m10.7 12.3 9.8-9.8"/><path d="m17 6 3 3"/><path d="m14.5 8.5 2 2"/>',
		'wifi-off' => '<path d="M2 2l20 20"/><path d="M8.5 16.4a5 5 0 0 1 7 0"/><path d="M5 12.9a10 10 0 0 1 5.2-2.7"/><path d="M19 12.9a10 10 0 0 0-2.3-1.6"/><path d="M2 8.8a15 15 0 0 1 4.2-2.6"/><path d="M22 8.8A15 15 0 0 0 11 5"/><path d="M12 20h.01"/>',
		'trending-up' => '<path d="m22 7-8.5 8.5-5-5L2 17"/><path d="M16 7h6v6"/>',
		'trending-down' => '<path d="m22 17-8.5-8.5-5 5L2 7"/><path d="M16 17h6v-6"/>',
		'target' => '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1"/>',
		'zap' => '<path d="M13 2 4 14h7l-1 8 9-12h-7l1-8Z"/>',
		'eye' => '<path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/>',
		'circle-dot' => '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="1.5"/>',
		'shield' => '<path d="M12 22s8-3.5 8-10V5l-8-3-8 3v7c0 6.5 8 10 8 10Z"/>',
	];

	private function __construct() {}

	/**
	 * @param string $name
	 * @param string $class  extra classes after "icon"
	 * @return string  the inline SVG, aria-hidden; '' for an unknown name
	 */
	public static function svg($name, $class = '')
	{
		if (!isset(self::$paths[$name])) {
			return '';
		}
		$classes = trim('icon icon-' . $name . ' ' . $class);
		return '<svg class="' . h($classes) . '" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor"'
			. ' stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
			. self::$paths[$name] . '</svg>';
	}

	/**
	 * @param string $name
	 * @return bool
	 */
	public static function has($name)
	{
		return isset(self::$paths[$name]);
	}

	/**
	 * @return array  every icon name
	 */
	public static function names()
	{
		return array_keys(self::$paths);
	}
}
