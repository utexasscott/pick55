<?php

require_once __DIR__ . '/../../../inc/_inc.php';

use Pick55\DB;
use Pick55\Alert;
use Pick55\Auth;
use Pick55\Page;
use Pick55\Models\PoolsUsersLink;
use Pick55\Models\UserFriendLink;
use Pick55\Models\User;
use Pick55\Models\Week;

Auth::guardAdmin();

$week = Week::find(get('id'));
if (!$week) {
	redir('admin/weeks/index.php');
}

$page = new Page;
$page->setTitle('Pools - Week #' . $week->id . ' - Weeks - Admin');
$page->options['admin_bar']['show'] = true;
$page->options['admin_bar']['title'] = 'Week #' . $week->id;
$page->options['admin_bar']['sub_bar']['type'] = 'week';
$page->options['admin_bar']['sub_bar']['obj'] = $week;

$pools = $week->pools()
	->orderBy('pool_num', 'ASC')
	->get();

$players = $week->season->getPlayers();
$any_players_unpooled = false;

$player_id_to_pool_id = [];
$pool_players = [
	'none' => [
		'players' => [],
		'pool' => null,
	]
];
foreach ($pools as $pool) {
	$pool_players[$pool->id]['players'] = [];
	$pool_players[$pool->id]['pool'] = $pool;
}
$q = PoolsUsersLink::where('week_id', '=', $week->id);
foreach ($q->cursor() as $link) {
	if (!$link->pool_id) continue;
	$player_id_to_pool_id[$link->er_user_id] = $link->pool_id;
	$pool_players[$link->pool_id]['players'][] = $link->user;
}
foreach ($players as $player) {
	if (!isset($player_id_to_pool_id[$player->id])) {
		$pool_players['none']['players'][] = $player;
		$any_players_unpooled = true;
	}
}
if (!$any_players_unpooled) {
	unset($pool_players['none']);
}

if (is_post()) {
	try {
		if (post('action') == 'auto-distribute') {
			PoolsUsersLink::where('week_id', '=', $week->id)->delete();

			$players_per_pool = round(sizeof($players) / sizeof($pools));
			$pool_ids = [];
			foreach ($pools as $pool) {
				$pool_ids[] = $pool->id;
			}
			$pool_index = 0;

			/*
			 * 1. Determine friendship strength for each friendship
			 * 2. Sort all friendships by strength descending
			 * 3. Starting with the strongest friendship:
			 * 		if one of the players is already in a pool, add the other player to that pool if number of players in pool is less than $players_per_pool
			 * 		if neither are in a pool, add both to the emptiest pool. if both are in a pool, ignore.
			 */

			$friendship_strength = [];
			$friendships_by_player = [];
			foreach ($players as $player) {
				$friends = $player->getFriends();
				if (!sizeof($friends)) continue;
				$bond_strength = 1/(sizeof($friends) + rand(3, 4));
				foreach ($friends as $friend) {
					if (!isset($friendship_strength[$player->id . '-' . $friend->id])) {
						$friendship_strength[$player->id . '-' . $friend->id] = 0;
					}
					if (!isset($friendship_strength[$friend->id . '-' . $player->id])) {
						$friendship_strength[$friend->id . '-' . $player->id] = 0;
					}
					$friendship_strength[$friend->id . '-' . $player->id] += $bond_strength;
					$friendship_strength[$player->id . '-' . $friend->id] += $bond_strength;
				}
			}
			arsort($friendship_strength);
			
			// var_dump($friendship_strength);
			// exit;

			$pool_players = [];
			foreach ($pools as $pool) {
				$pool_players[$pool->id] = [];
			}

			$player_id_to_pool_id = [];
			foreach ($friendship_strength as $friendship => $strength) {
				list($player_id, $friend_id) = explode('-', $friendship);
				$player = User::find($player_id);
				$friend = User::find($friend_id);
				if (!$player || !$friend) continue;
				if (!$player->isActiveForSeason($week->football_season_id) || !$friend->isActiveForSeason($week->football_season_id)) continue;
				// var_dump($player->getName() . ' => ' . $friend->getName());
				if (isset($player_id_to_pool_id[$player->id]) && isset($player_id_to_pool_id[$friend->id])) {
					continue;
				}
				if (isset($player_id_to_pool_id[$player->id])) {
					$pool_id = $player_id_to_pool_id[$player->id];
					if (sizeof($pool_players[$pool_id]) >= $players_per_pool) continue;
					$pool_players[$pool_id][] = $friend;
					$player_id_to_pool_id[$friend->id] = $pool_id;
				}
				elseif (isset($player_id_to_pool_id[$friend->id])) {
					$pool_id = $player_id_to_pool_id[$friend->id];
					if (sizeof($pool_players[$pool_id]) >= $players_per_pool) continue;
					$pool_players[$pool_id][] = $player;
					$player_id_to_pool_id[$player->id] = $pool_id;
				}
				else {
					$emptiest_pool_id = null;
					$emptiest_pool_size = 999999;
					foreach ($pools as $pool) {
						if (sizeof($pool_players[$pool->id]) < $emptiest_pool_size) {
							$emptiest_pool_id = $pool->id;
							$emptiest_pool_size = sizeof($pool_players[$pool->id]);
						}
					}
					$pool_players[$emptiest_pool_id][] = $player;
					$pool_players[$emptiest_pool_id][] = $friend;
					$player_id_to_pool_id[$player->id] = $emptiest_pool_id;
					$player_id_to_pool_id[$friend->id] = $emptiest_pool_id;
				}
			}
			
			// print_r($pool_players);
			// exit;

			// Assign remaining players to emptiest pools
			foreach ($players as $player) {
				if (isset($player_id_to_pool_id[$player->id])) continue;
				$emptiest_pool_id = null;
				$emptiest_pool_size = 999999;
				foreach ($pools as $pool) {
					if (sizeof($pool_players[$pool->id]) < $emptiest_pool_size) {
						$emptiest_pool_id = $pool->id;
						$emptiest_pool_size = sizeof($pool_players[$pool->id]);
					}
				}
				$pool_players[$emptiest_pool_id][] = $player;
				$player_id_to_pool_id[$player->id] = $emptiest_pool_id;
			}
			// print_r($pool_players);
			// exit;
			foreach ($pool_players as $pool_id => $players) {
				foreach ($players as $player) {
					$link = PoolsUsersLink::firstOrCreate([
						'er_user_id' => $player->id,
						'week_id' => $week->id,
						'pool_id' => $pool_id,
					]);
				}
			}

			Alert::success("Auto distributed players.");
		}
		if (post('action') == 'set-pool-size') {
			$week->setNumPools(post('num_pools'));
			Alert::success("Saved number of pools.");
		}
		if (post('action') == 'save-pools') {
			foreach ($pools as $pool) {
				$pool->name = post('pool_name_' . $pool->id);
				$pool->save();
			}
			Alert::success("Saved changes.");
		}
		if (post('action') == 'save-players') {
			foreach ($players as $player) {
				$link = PoolsUsersLink::firstOrCreate([
					'er_user_id' => $player->id,
					'week_id' => $week->id,
				]);
				$link->pool_id = post('player_pool_' . $player->id, null);
				$link->save();
			}
			Alert::success("Saved player pools.");
		}
	}
	catch (Exception $e) {
		Alert::error($e->getMessage());
	}
	redir();
}

ob_start();
?>
<div class="container py-4">
	<div class="row">
		<div class="col-md-6">
			<form action="" method="post">
				<input type="hidden" name="action" value="set-pool-size">
				<div class="card mb-3">
					<h4 class="card-header">Number of Pools</h4>
					<div class="card-body">
						<div class="row">
							<div class="col-auto align-items-center">
								<label for="num_pools" class="col-form-label">Number of Pools</label>
							</div>
							<div class="col-auto">
								<select id="num_pools" name="num_pools" class="form-select">
									<option value="0">No Pools</option>
									<?php foreach (range(1, 12) as $num): ?>
										<option <?=sel($num, $week->num_pools)?> value="<?=$num?>"><?=$num?></option>
									<?php endforeach; ?>
								</select>
							</div>
							<div class="col-auto">
								<button type="submit" class="btn btn-primary btn-block">Save Changes</button>
							</div>
						</div>
					</div>
				</div>
			</form>
		</div>
		<div class="col-md-6">
			<form action="" method="post">
				<input type="hidden" name="action" value="save-pools">
				<div class="card mb-3">
					<h4 class="card-header">Pool Names</h4>
					<div class="card-body">
						<div class="row">
							<?php foreach ($pools as $pool): ?>
								<div class="col-md-4 mb-3">
									<input type="text" class="form-control" name="pool_name_<?=$pool->id?>" value="<?=$pool->name?>">
								</div>
							<?php endforeach; ?>
						</div>
					</div>
					<div class="card-footer text-center">
						<button type="submit" class="btn btn-primary btn-block">Save Changes</button>
					</div>
				</div>
			</form>
		</div>
	</div>

	<?php if (sizeof($pools)): ?>
		<div class="card mb-3">
			<form action="" method="post">
				<div class="card-header">
					<div class="row">
						<h4 class="col">Player Pools</h4>
						<div class="col-auto">
							<button type="submit" name="action" value="auto-distribute" class="btn btn-outline-primary btn-sm">Auto Distribute Players</button>
						</div>
						<div class="col-auto">
							<button type="submit" name="action" value="save-players" class="btn btn-primary btn-sm">Save Changes</button>
						</div>
					</div>
				</div>
				<div class="row mx-1">
					<?php foreach ($pool_players as $pool_id => $arr): ?>
						<div class="col-6 col-md-4 col-lg-3 col-xl-2">
							<div class="h4"><?=$arr['pool'] ? $arr['pool']->name : 'Unpooled'?> (<?=sizeof($arr['players'])?>)</div>
							<?php foreach ($arr['players'] as $player): ?>
								<?php $num_friends_per_pool = $player->getNumFriendsPerPool($week->id); ?>
								<div>
									<div class="row">
										<div class="col">
											<select class="form-select form-select-sm" name="player_pool_<?=$player->id?>">
												<option value="<?=$pool_id?>"><?=$player->getName()?></option>
												<?php foreach ($pools as $pool): ?>
													<?php if ($pool->id == $pool_id) continue; ?>
													<option value="<?=$pool->id?>"><?=$player->getName()?> => <?=$pool->name?> <?=$num_friends_per_pool[$pool->id]?></option>
												<?php endforeach; ?>
											</select>
										</div>
										<div class="col col-auto"><?=$num_friends_per_pool[$pool_id]?> / <?=$player->getNumFriends()?></div>
									</div>
								</div>
							<?php endforeach; ?>
						</div>
					<?php endforeach; ?>
				</div>
			</form>
		</div>
	<?php endif; ?>
</div>
<?php
$page->setContent(ob_get_clean());
print $page->render();
