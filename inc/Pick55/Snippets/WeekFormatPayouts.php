<?php

namespace Pick55\Snippets;

use Pick55\Models\WeekFormat;
use Pick55\Models\WeekFormatPayout;

/**
 * A format's payout table, as a horizontal HTML table of three rows
 * (group, place, amount; one column per payout row, total last) or one
 * line of text ("Overall: 1st $150, 2nd $100. Each pool: 1st $65, 2nd $18.").
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

		$show_total = $params['show_total'] && $format->total_payout > 0;
		ob_start();
		?>
		<div class="table-responsive">
			<table class="table table-sm table-bordered text-center mb-0 w-auto payouts-table">
				<thead>
					<tr>
						<?php foreach ($groups as $label => $rows): ?>
							<th colspan="<?=sizeof($rows)?>"><?=$label?></th>
						<?php endforeach; ?>
						<?php if ($show_total): ?>
							<th rowspan="2" class="align-middle">Total</th>
						<?php endif; ?>
					</tr>
					<tr class="small fw-normal text-muted">
						<?php foreach ($groups as $rows): ?>
							<?php foreach ($rows as $row): ?>
								<th class="fw-normal text-nowrap"><?=$row->getPlaceLabel()?></th>
							<?php endforeach; ?>
						<?php endforeach; ?>
					</tr>
				</thead>
				<tbody>
					<tr>
						<?php foreach ($groups as $rows): ?>
							<?php foreach ($rows as $row): ?>
								<td class="text-nowrap"><?=$row->getAmountLabel()?></td>
							<?php endforeach; ?>
						<?php endforeach; ?>
						<?php if ($show_total): ?>
							<td class="text-nowrap fw-bold">$<?=WeekFormatPayout::money($format->total_payout)?></td>
						<?php endif; ?>
					</tr>
				</tbody>
			</table>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Payout rows grouped under a heading: "Overall", "Each pool" (or
	 * "Each team member"), "Pool 1" ...
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
