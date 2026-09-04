<?php

namespace Pick55\Snippets;

use Pick55\Models\Game;
use Pick55\Alert;

class AdminGameRow extends Snippet
{
	/**
	 * @param array $params
	 * @return string
	 */
	public static function build(array $params = [])
	{
		$params = array_merge([
			'col_season' => true,
			'col_week' => true,
			'game' => null,
		], $params);

		$game = $params['game'];

		ob_start();
		?>
		<tr>
			<?php if ($params['col_season']): ?>
				<td>
					<a class="text-end" href="<?=config('base_url')?>admin/games/game/index.php?id=<?=$game->id?>"><?=$game->id?></a>
					<?php if ($game->week): ?>
						<br><a class="link-muted" href="<?=config('base_url')?>admin/seasons/season/index.php?id=<?=$game->week->football_season_id?>"><?=$game->week->season->name?></a>
					<?php endif; ?>
					<?php if ($game->week): ?>
						<br><a class="link-muted" href="<?=config('base_url')?>admin/weeks/week/index.php?id=<?=$game->football_week_id?>">Week <?=$game->week->week_num?></a>
					<?php endif; ?>
				</td>
			<?php endif; ?>
			<td><?=$game->awayTeam?><br><?=$game->homeTeam?></td>
			<td><?=DateDisplay::b($game->date)?><br><?=TimeDisplay::b($game->time)?></td>
			<td>
					<div class="me-1 <?=GameOptionClass::build(['game' => $game, 'option' => '1'])?>">
						<?=$game->option_1?>
					</div>
					<?php if ($game->correct_option == '1'): ?>
						<form action="" method="post">
							<input type="hidden" name="action" value="set-game-result">
							<input type="hidden" name="game_id" value="<?=$game->id?>">
							<input type="hidden" name="correct_option" value="0">
							<button type="submit" class="btn btn-xs btn-outline-secondary">Unset</button>
						</form>
					<?php else: ?>
						<form action="" method="post">
							<input type="hidden" name="action" value="set-game-result">
							<input type="hidden" name="game_id" value="<?=$game->id?>">
							<input type="hidden" name="correct_option" value="1">
							<button type="submit" class="btn btn-xs btn-outline-primary">Set</button>
						</form>
					<?php endif; ?>
					<br>
					<div class="ms-1 <?=GameOptionClass::build(['game' => $game, 'option' => '2'])?>">
						<?=$game->option_2?>
					</div>
					<?php if ($game->correct_option == '2'): ?>
						<form action="" method="post">
							<input type="hidden" name="action" value="set-game-result">
							<input type="hidden" name="game_id" value="<?=$game->id?>">
							<input type="hidden" name="correct_option" value="0">
							<button type="submit" class="btn btn-xs btn-outline-secondary">Unset</button>
						</form>
					<?php else: ?>
						<form action="" method="post">
							<input type="hidden" name="action" value="set-game-result">
							<input type="hidden" name="game_id" value="<?=$game->id?>">
							<input type="hidden" name="correct_option" value="2">
							<button type="submit" class="btn btn-xs btn-outline-primary">Set</button>
						</form>
					<?php endif; ?>
			</td>
		</tr>
		<?php
		return ob_get_clean();
	}

	/**
	 */
	public static function handle()
	{
		if (is_post()) {
			try {
				if (post('action') == 'set-game-result') {
					$game = Game::findOrFail(post('game_id'));
					$game->correct_option = post('correct_option');
					$game->save();
					Alert::success("Set game #" . $game->id . " to option '" . $game->correct_option . "'.");
				}
			}
			catch (Exception $e) {
				Alert::error($e->getMessage());
			}
			redir();
		}
	}
}
