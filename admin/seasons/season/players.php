<?php

require_once __DIR__ . '/../../../inc/_inc.php';

use Pick55\DB;
use Pick55\Alert;
use Pick55\Auth;
use Pick55\Page;
use Pick55\Models\Season;
use Pick55\Models\User;
use Pick55\Models\UsersSeasonsLink;
use Pick55\Models\UserFriendLink;

Auth::guardAdmin();

$season = Season::find(get('id'));
if (!$season) {
	redir('admin/seasons/index.php');
}

$page = new Page;
$page->setTitle('Players - ' . $season->name . ' - Seasons - Admin');
$page->options['admin_bar']['show'] = true;
$page->options['admin_bar']['title'] = $season->name;
$page->options['admin_bar']['sub_bar']['type'] = 'season';
$page->options['admin_bar']['sub_bar']['obj'] = $season;

$links = UsersSeasonsLink::where('football_season_id', '=', $season->id)
	->with('user')
	->orderBy('paid_at', 'DESC')
	->get();

if (is_post()) {
	try {
		if (post('action') == 'unpaid') {
			$link = UsersSeasonsLink::where('er_user_id', '=', post('user_id'))
				->where('football_season_id', '=', $season->id)
				->first();
			if (!$link) {
				throw new Exception("Invalid user.");
			}
			$link->paid_at = null;
			$link->payment_method = null;
			$link->save();
			Alert::success("Set user as unpaid.");
		}
		if (post('action') == 'paid') {
			$link = UsersSeasonsLink::where('er_user_id', '=', post('user_id'))
				->where('football_season_id', '=', $season->id)
				->first();
			if (!$link) {
				throw new Exception("Invalid user.");
			}
			$link->paid_at = now();
			$link->payment_method = post('payment_method');
			$link->save();
			Alert::success("Set user as paid.");
		}
		if (post('action') == 'remove') {
			$link = UsersSeasonsLink::where('er_user_id', '=', post('user_id'))
				->where('football_season_id', '=', $season->id)
				->first();
			if (!$link) {
				throw new Exception("Invalid user.");
			}
			$link->delete();
			Alert::success("Removed user.");
		}
		if (post('action') == 'add-friend') {
			$player_id = post('player_id');
			$player = User::find($player_id);
			if (!$player) {
				throw new Exception("Invalid player.");
			}
			$friend_player_id = post('friend_player_id');
			$friend_player = User::find($friend_player_id);
			if (!$friend_player) {
				throw new Exception("Invalid friend.");
			}
			$link = UserFriendLink::where('er_user_id', '=', $player_id)
				->where('friend_er_user_id', '=', $friend_player_id)
				->first();
			if ($link) {
				throw new Exception("Friend already exists.");
			}
			UserFriendLink::create([
				'er_user_id' => $player_id,
				'friend_er_user_id' => $friend_player_id,
			]);
			Alert::success("Added friend.");
		}
		if (post('action') == 'remove-friend') {
			$player_id = post('player_id');
			$player = User::find($player_id);
			if (!$player) {
				throw new Exception("Invalid player.");
			}
			$friend_player_id = post('friend_player_id');
			$friend_player = User::find($friend_player_id);
			if (!$friend_player) {
				throw new Exception("Invalid friend.");
			}
			$link = UserFriendLink::where('er_user_id', '=', $player_id)
				->where('friend_er_user_id', '=', $friend_player_id)
				->first();
			if (!$link) {
				throw new Exception("Invalid friend.");
			}
			$link->delete();
			Alert::success("Removed friend.");
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
	<div class="card">
		<h4 class="card-header">Players</h4>
		<div class="table-responsive">
			<table class="table table-sm table-striped">
				<thead>
					<tr>
						<th class="text-end">ID</th>
						<th>Name</th>
						<th>Payment</th>
						<th>Paid At</th>
						<th>Remove</th>
						<th>Friends</th>
					</tr>
				</thead>
				<tbody>
					<?php
					foreach ($links as $link):
						$player = $link->user;
						?>
						<tr>
							<td class="text-end"><?=$player->id?></td>
							<td class="line-height-1">
								<div class="fw-bold"><?=$player->getName()?></div>
								<small class="text-muted"><?=$player->email?></small>
							</td>
							<td>
								<div class="td-btns justify-content-start fs-7">
									<?php if ($link->paid_at): ?>
										<?=$link->payment_method?>
										<form action="" method="post">
											<input type="hidden" name="action" value="unpaid">
											<input type="hidden" name="user_id" value="<?=$player->id?>">
											<button type="submit" class="btn btn-xs btn-outline-secondary">Unpaid</button>
										</form>
									<?php else: ?>
										<form action="" method="post">
											<input type="hidden" name="action" value="paid">
											<input type="hidden" name="user_id" value="<?=$player->id?>">
											<div class="input-group">
												<select name="payment_method" class="form-select form-select-sm py-0">
													<option value=""></option>
													<option value="venmo">Venmo</option>
													<option value="paypal">PayPal</option>
													<option value="cash">Cash</option>
													<option value="credit">Credit</option>
													<option value="other">Other</option>
												</select>
												<button type="submit" class="btn btn-xs btn-outline-success">Set Paid</button>
											</div>
										</form>
									<?php endif; ?>
								</div>
							</td>
							<td>
								<div class="td-btns justify-content-start fs-7">
									<?php if ($link->paid_at): ?>
										<div class="me-1">
											<div><?=date('m/d g:i', strtotime($link->paid_at)) . ' --- ' . ago($link->paid_at, 1)?></div>
										</div>
									<?php endif; ?>
								</div>
							</td>
							<td>
								<div class="td-btns justify-content-center">
									<form action="" method="post">
										<input type="hidden" name="action" value="remove">
										<input type="hidden" name="user_id" value="<?=$player->id?>">
										<button type="submit" class="btn btn-xs btn-outline-secondary" data-confirm>Remove</button>
									</form>
								</div>
							</td>
							<td>
								<?php foreach ($player->getFriends() as $friend): ?>
									<div class="td-btns justify-content-start fs-7">
										<div class="me-1">
											<div><?=$friend->getDisplayName()?></div>
										</div>
									</div>
								<?php endforeach; ?>
								<div class="btn btn-xs btn-outline-secondary edit-friends" data-toggle="#edit-friends-<?=$player->id?>" data-player_id="<?=$player->id?>">Edit Friends</div>
								<div class="hidden" id="edit-friends-<?=$player->id?>">
									<form action="" method="post">
										<input type="hidden" name="action" value="add-friend">
										<input type="hidden" name="player_id" value="<?=$player->id?>">
										<div class="input-group">
											<select class="form-select form-select-sm py-0" name="friend_player_id">
												<option value="0" disabled selected>-- Add Friend --</option>
												<?php foreach ($links as $link): ?>
													<?php if ($link->user->id == $player->id) continue; ?>
													<option value="<?=$link->user->id?>"><?=$link->user->getDisplayName()?></option>
												<?php endforeach; ?>
											</select>
											<button type="submit" class="btn btn-xs btn-outline-primary">Add Friend</button>
										</div>
									</form>
									<form action="" method="post">
										<input type="hidden" name="action" value="remove-friend">
										<input type="hidden" name="player_id" value="<?=$player->id?>">
										<div class="input-group">
											<select class="form-select form-select-sm py-0" name="friend_player_id">
												<option value="0" disabled selected>-- Remove Friend --</option>
												<?php foreach ($player->getFriends() as $friend): ?>
													<option value="<?=$friend->id?>"><?=$friend->getDisplayName()?></option>
												<?php endforeach; ?>
											</select>
											<button type="submit" class="btn btn-xs btn-outline-danger">Remove Friend</button>
										</div>
									</form>
								</div>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>
</div>
<?php
ob_start();
?>
<script>
	$(document).ready(function() {
		$('.edit-friends').click(function() {
			var toggle = $(this).attr('data-toggle');
			$(toggle).toggleClass('hidden');
		});
	});
</script>
<?php
$page->setScripts(ob_get_clean());
$page->setContent(ob_get_clean());
print $page->render();
