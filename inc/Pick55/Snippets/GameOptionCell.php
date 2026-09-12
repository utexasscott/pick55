<?php

namespace Pick55\Snippets;

use Pick55\DB;
use Pick55\Models\Bet;
use Pick55\Models\User;

class GameOptionCell
{
	const CLASS_CORRECT = 'td-right2';
	const CLASS_CORRECT_WHAT_IF = 'td-right-what-if';
	const CLASS_INCORRECT = 'td-wrong2';
	const CLASS_INCORRECT_WHAT_IF = 'td-wrong-what-if';
	const CLASS_AUTO = 'td-auto2';
	const CLASS_MY_PICK = 'border border-dark border-4';
	const ICON_CORRECT = ' <i class="fas fa-check text-muted"></i>';
	const ICON_INCORRECT = ' <i class="fas fa-times text-muted"></i>';

	/**
	 * @param array $params
	 * @return string
	 */
	public static function build(array $params = [])
	{
		$params = array_merge([
			'game' => null,
			'option' => null,
			'user_ids' => [],
			'my_user_id' => null,
			'show_what_if' => false,
			'what_if_option' => null,
			// Optional preloaded data (avoids per-cell queries):
			'players' => null, // user_id => User, must cover every user in user_ids
			'picks' => null, // list of bet arrays/objects for this game (any option), keys user_id, option, multiplier, id
		], $params);

		$game = $params['game'];
		$my_pick_option = null;
		$picks = [];
		$no_pick_player_ids = [];
		$players = [];
		if (is_array($params['players'])) {
			$players = $params['players'];
		}
		else {
			foreach (User::whereIn('id', $params['user_ids'])->get() as $player) {
				$players[$player->id] = $player;
			}
		}

		if ($params['option'] == '0') {
			$no_pick_players = [];
			$game_id = $game->id;
			$q = DB::table(User::getTableName() . ' AS user')
				->leftJoin(Bet::getTableName() . ' AS pick', function ($join) use ($game_id) {
					$join->on('pick.user_id', '=', 'user.id')
						->where('pick.football_game_id', '=', $game_id);
				})
				->select([
					'user.id',
				])
				->whereIn('user.id', $params['user_ids'])
				->where(function ($q) {
					$q->whereNull('pick.id')
						->orWhere('pick.option', '=', '0');
				});
			foreach ($q->cursor() as $row) {
				$no_pick_player_ids[] = $row->id;
			}
		}
		elseif (is_array($params['picks'])) {
			$user_ids = array_flip(array_map('intval', $params['user_ids']));
			foreach ($params['picks'] as $pick) {
				$pick = (object) $pick;
				if ($pick->option != $params['option'] || !isset($user_ids[(int) $pick->user_id])) {
					continue;
				}
				$picks[] = $pick;
			}
			usort($picks, function ($a, $b) {
				if ($a->multiplier != $b->multiplier) {
					return $b->multiplier <=> $a->multiplier;
				}
				return $a->id <=> $b->id;
			});
		}
		else {
			$q = $game->bets()
				->whereIn('user_id', $params['user_ids'])
				->where('option', '=', $params['option']);
			$picks = $q->orderBy('multiplier', 'DESC')
				->get();
		}

		if ($params['my_user_id'] && $params['option'] != '0') {
			foreach ($picks as $pick) {
				if ($pick->user_id == $params['my_user_id']) {
					$my_pick_option = $pick->option;
					break;
				}
			}
		}

		$td_classes = [];
		$option_name = '';
		$what_if_key = 'g' . $game->id;
		switch ($params['option']) {
			case '1':
				$option_name = $game->option_1;
				if ($game->correct_option == '1') {
					$td_classes[] = self::CLASS_CORRECT;
					$option_name .= self::ICON_CORRECT;
				}
				elseif ($game->correct_option == '2') {
					$td_classes[] = self::CLASS_INCORRECT;
					$option_name .= self::ICON_INCORRECT;
				}
				elseif ($params['show_what_if']) {
					if ($params['what_if_option'] == '1') {
						$td_classes[] = self::CLASS_CORRECT_WHAT_IF;
						$option_name .= '<a href="' . query_string_add($what_if_key, '') . '" class="btn btn-xs btn-outline-secondary">Reset</a>';
						$option_name .= self::ICON_CORRECT;
					}
					else {
						$option_name .= '<a href="' . query_string_add($what_if_key, '1') . '" class="btn btn-xs btn-outline-primary">What If</a>';
						if ($params['what_if_option'] == '2') {
							$td_classes[] = self::CLASS_INCORRECT_WHAT_IF;
							$option_name .= self::ICON_INCORRECT;
						}
					}
				}
				if ($my_pick_option == '1') {
					$td_classes[] = self::CLASS_MY_PICK;
				}
				break;
			case '2':
				$option_name = $game->option_2;
				if ($game->correct_option == '2') {
					$td_classes[] = self::CLASS_CORRECT;
					$option_name .= self::ICON_CORRECT;
				}
				elseif ($game->correct_option == '1') {
					$td_classes[] = self::CLASS_INCORRECT;
					$option_name .= self::ICON_INCORRECT;
				}
				elseif ($params['show_what_if']) {
					if ($params['what_if_option'] == '2') {
						$td_classes[] = self::CLASS_CORRECT_WHAT_IF;
						$option_name .= '<a href="' . query_string_add($what_if_key, '') . '" class="btn btn-xs btn-outline-secondary">Reset</a>';
						$option_name .= self::ICON_CORRECT;
					}
					else {
						$option_name .= '<a href="' . query_string_add($what_if_key, '2') . '" class="btn btn-xs btn-outline-primary">What If</a>';
						if ($params['what_if_option'] == '1') {
							$td_classes[] = self::CLASS_INCORRECT_WHAT_IF;
							$option_name .= self::ICON_INCORRECT;
						}
					}
				}
				if ($my_pick_option == '2') {
					$td_classes[] = self::CLASS_MY_PICK;
				}
				break;
			case '3':
				$option_name = 'AUTO';
				$td_classes[] = self::CLASS_AUTO;
				if ($my_pick_option == '3') {
					$td_classes[] = self::CLASS_MY_PICK;
				}
				break;
		}

		ob_start();
		?>
		<td class="<?=implode(' ', $td_classes)?>">
			<div class="d-flex justify-content-between fw-bold p-2 mb-1 align-items-start"><?=$option_name?></div>
			<div class="small-names">
				<?php if ($params['option'] == '0'): ?>
					<?php foreach ($no_pick_player_ids as $player_id): ?>
						<div class="text-nowrap">
							<?=$players[$player_id]->getDisplayName()?>
						</div>
					<?php endforeach; ?>
				<?php else: ?>
					<?php foreach ($picks as $pick): ?>
						<div class="text-nowrap <?=$params['my_user_id'] && $pick->user_id == $params['my_user_id'] ? 'bg-me' : ''?>">
							<span class="small-multiplier"><?=$pick->multiplier?>x</span>
							<span><?=$players[$pick->user_id]->getDisplayName()?></span>
						</div>
					<?php endforeach; ?>
				<?php endif; ?>
			</div>
		</td>
		<?php
		return ob_get_clean();
	}
}
