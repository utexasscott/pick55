<?php

namespace Pick55\Snippets;

class AdminWeekRow extends Snippet
{
	/**
	 * @param array $params
	 * @return string
	 */
	public static function build(array $params = [])
	{
		$params = array_merge([
			'col_season' => true,
			'week' => null,
		], $params);

		$week = $params['week'];

		ob_start();
		?>
		<tr>
			<td class="text-end"><?=$week->id?></td>
			<?php if ($params['col_season']): ?>
				<td class="text-center text-nowrap"><a class="link-muted" href="<?=config('base_url')?>admin/seasons/season/index.php?id=<?=$week->football_season_id?>"><?=$week->season->name?></a></td>
			<?php endif; ?>
			<td class="cell-link fw-bold text-nowrap"><a class="text-center" href="<?=config('base_url')?>admin/weeks/week/index.php?id=<?=$week->id?>">Week <?=$week->week_num?></a></td>
			<td class="line-height-1">
				<div><?=$week->getName()?></div>
				<?php if (strlen($week->getDescriptionLong())): ?>
					<small class="text-muted"><?=$week->getDescriptionLong()?></small>
				<?php endif; ?>
			</td>
			<td class="text-center"><?=$week->getNumPools() ? $week->getNumPools() : ''?></td>
			<td class="text-end"><?=ifempty(DateTimeDisplay::b($week->picks_due_date), 'TBD')?></td>
			<td class="text-end"><?=ifempty(DateTimeDisplay::b($week->getFirstGameAt()), 'TBD')?></td>
		</tr>
		<?php
		return ob_get_clean();
	}
}
