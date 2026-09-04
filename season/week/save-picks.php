<?php

require_once __DIR__ . '/../../inc/_inc.php';

use Illuminate\Database\Capsule\Manager as DB;
use Pick55\Alert;
use Pick55\App;
use Pick55\Auth;
use Pick55\Page;
use Pick55\Models\Bet;
use Pick55\Models\Game;
use Pick55\Models\UsersSeasonsLink;
use Pick55\Models\Week;
use Pick55\Snippets\DateTimeDisplay;


$me = Auth::user();

if (!$me) {
	print json_encode(['error' => 'Logged out due to inactivity.']);
}
else {
	try {
		header('Content-Type: application/json; charset=utf-8');
		$results = [];
		$week = Week::find(input('week_id'));
		if (!$week) {
			throw new Exception("Invalid week.");
		}
		if (!$week->season) {
			throw new Exception("Invalid week.");
		}
		if (!$week->season->hasPlayer($me->id)) {
			throw new Exception("Not authorized.");
		}
		if (!$week->canUserPick($me->id)) {
			throw new Exception("Cannot pick.");
		}
		$set = [];
		foreach ($_POST as $k => $v) {
			if (preg_match('/^game-(\d+)-(.+?)$/', $k, $m)) {
				$game_id = intval($m[1]);
				$input = $m[2];

				// See if the game ID exists
				$game = Game::find($game_id);
				if (!$game) {
					continue;
				}

				// Initialize the set array for this pick and game id
				if (!isset($set[$game_id])) {
					$set[$game_id] = [
						'option' => 0,
						'mult' => 0,
					];
				}

				// Parse the input for this pick
				switch ($input) {
					case 'option':
						$option = intval($v);
						if (Bet::isValidOption($option)) {
							$set[$game_id]['option'] = $option;
						}
						break;
					case 'mult':
						$mult = intval($v);
						if (Bet::isValidMultiplier($mult)) {
							$set[$game_id]['mult'] = $mult;
						}
						break;
				}
			}
		}

		// Make sure the picks exist
		foreach ($set as $game_id => $params) {
			$pick = Bet::firstOrCreate([
				'user_id' => $me->id,
				'football_game_id' => $game_id,
			]);
		}

		// Enforce the multipliers: 0, 0, and 1-10
		$mult_struct = [
			'game_id' => null,
			'option' => null,
		];
		$by_mult = array_fill_keys(range(1, 10), $mult_struct);
		$zero_mults = [];
		foreach ($set as $game_id => $params) {
			if (!$params['mult']) {
				$zero_mults[] = [
					'game_id' => $game_id,
					'option' => $params['option'],
				];
				continue;
			}
			if (!array_key_exists($params['mult'], $by_mult)) {
				continue;
			}
			if ($by_mult[$params['mult']]['game_id']) {
				// this game mult already set
				continue;
			}
			$by_mult[$params['mult']]['game_id'] = $game_id;
			$by_mult[$params['mult']]['option'] = $params['option'];
		}

		$week_id = $week->id;
		$my_id = $me->id;
		DB::transaction(function () use ($week_id, $my_id, $by_mult, $zero_mults) {
			// Reset all my picks this week
			$sql = "
				UPDATE `football_bets` pick
					LEFT JOIN `football_games` game ON pick.`football_game_id` = game.`id`
				SET
					pick.`option` = '0',
					pick.`multiplier` = 0
				WHERE
					pick.`user_id` = " . $my_id . "
					AND game.`football_week_id` = " . $week_id;
			DB::update($sql);

			// Loop through 0 picks
			foreach ($zero_mults as $params) {
				$sql = "
					UPDATE `football_bets`
					SET
						`option` = '" . $params['option'] . "',
						`multiplier` = 0
					WHERE
						`user_id` = " . $my_id . "
						AND `football_game_id` = " . $params['game_id'];
				DB::update($sql);
			}

			// Loop through the picks we are setting now
			foreach ($by_mult as $mult => $params) {
				$sql = "
					UPDATE `football_bets`
					SET
						`option` = '" . $params['option'] . "',
						`multiplier` = " . $mult . "
					WHERE
						`user_id` = " . $my_id . "
						AND `football_game_id` = " . $params['game_id'];
				DB::update($sql);
			}
		});

		print 1;
	}
	catch (Exception $e) {
		print json_encode(['error' => $e->getMessage()]);
	}
}

