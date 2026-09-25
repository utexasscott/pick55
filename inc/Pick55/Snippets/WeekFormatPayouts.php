<?php

namespace Pick55\Snippets;

use Pick55\Models\WeekFormat;
use Pick55\Models\WeekFormatPayout;

/**
 * A format's payout table, as a small HTML table (default) or one line of
 * text ("Overall: 1st $150, 2nd $100. Each pool: 1st $65, 2nd $18.").
 */
class WeekFormatPayouts extends Snippet
{
	/**
	 * @param WeekFormat $format
	 * @param bool $inline
	 * @return string
	 */
	public static function b(WeekFormat $format = null, $inline = false)
	{
		return self::build(['format' => $format, 'inline' => $inline]);
	}

	/**
	 * @param array $params
	 * @return string
	 */
	public static function build(array $params = [])
	{
		$params = array_merge([
			'format' => null,
			'inline' => false,
			'show_total' => true,
		], $params);

		$format = $params['format'];
		if (!$format) {
			return '';
		}
		$groups = self::groups($format);
		if (!sizeof($groups)) {
			return $params['inline'] ? 'No payouts' : '';
		}

		if ($params['inline']) {
			$parts = [];
			foreach ($groups as $label => $rows) {
				$items = [];
				foreach ($rows as $row) {
					$items[] = $row->getPlaceLabel() . ' ' . $row->getAmountLabel();
				}
				$parts[] = $label . ': ' . implode(', ', $items);
			}
			return implode('. ', $parts) . '.';
		}

		ob_start();
		?>
		<table class="table table-sm table-borderless mb-0 w-auto">
			<?php foreach ($groups as $label => $rows): ?>
				<tr>
					<th colspan="2" class="pt-2"><?=$label?></th>
				</tr>
				<?php foreach ($rows as $row): ?>
					<tr>
						<td class="text-nowrap ps-3"><?=$row->getPlaceLabel()?></td>
						<td class="text-nowrap text-end"><?=$row->getAmountLabel()?></td>
					</tr>
				<?php endforeach; ?>
			<?php endforeach; ?>
			<?php if ($params['show_total'] && $format->total_payout > 0): ?>
				<tr class="border-top">
					<th>Total</th>
					<th class="text-end">$<?=WeekFormatPayout::money($format->total_payout)?></th>
				</tr>
			<?php endif; ?>
		</table>
		<?php
		return ob_get_clean();
	}

	/**
	 * Payout rows grouped under a heading: "Overall", "Each pool" (or
	 * "Each team"), "Pool 1" ...
	 *
	 * @param WeekFormat $format
	 * @return array label => list of WeekFormatPayout
	 */
	public static function groups(WeekFormat $format)
	{
		$groups = [];
		foreach ($format->payouts as $row) {
			if ($row->place_type == WeekFormatPayout::PLACE_OVERALL) {
				$label = 'Overall';
			}
			elseif ($row->pool_num !== null) {
				$label = ($row->place_type == WeekFormatPayout::PLACE_TEAM ? 'Team ' : 'Pool ') . (int) $row->pool_num;
			}
			else {
				$label = $row->place_type == WeekFormatPayout::PLACE_TEAM ? 'Each team member' : 'Each pool';
			}
			$groups[$label][] = $row;
		}
		return $groups;
	}
}
