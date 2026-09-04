<?php

require_once __DIR__ . '/../inc/_inc.php';

use Pick55\Alert;
use Pick55\Auth;
use Pick55\Page;
use Pick55\Models\User;
use Pick55\Models\Season;
use Pick55\Models\UserFriendLink;

Auth::guard();

$page = new Page;
$page->setTitle('My Account');

$me = Auth::user();
$active_season = Season::getActive();
$players = $active_season->getPlayers();
$friends = $me->getFriends();
$friend_ids = [];
foreach ($friends as $friend) {
	$friend_ids[$friend->id] = $friend->id;
}

$friended_users = $me->getFriendedUsers();
foreach ($friended_users as $k => $friended_user) {
	if (in_array($friended_user->id, $friend_ids)) {
		unset($friended_users[$k]);
	}
}

if (is_post()) {
	try {
		switch (post('action')) {
			case 'save':
				$me->first_name = trim(post('first_name'));
				$me->last_name = trim(post('last_name'));
				$me->venmo_phone = ifempty(trim(post('venmo_phone')), null);
				$me->paypal_email = ifempty(trim(post('paypal_email')), null);
				$me->save();
				if (strlen(trim(post('email'))) < 5) {
					throw new Exception('Email "' . trim(post('email')) . '" invalid.');
				}
				if (User::where('email','=',trim(post('email')))
					->where('id','!=',$me->id)
					->first()) {
					throw new Exception('User exists with email ' . trim(post('email')));
				}
				$me->email = trim(post('email'));
				$me->save();
				Alert::success("Saved changes.");
				break;
			case 'add-friend':
				$player_id = post('player_id');
				$player = User::find($player_id);
				if (!$player) {
					throw new Exception("Invalid player.");
				}
				UserFriendLink::create([
					'er_user_id' => $me->id,
					'friend_er_user_id' => $player_id,
				]);
				break;
			case 'remove-friend':
				$player_id = post('player_id');
				$player = User::find($player_id);
				if (!$player) {
					throw new Exception("Invalid player.");
				}
				$link = UserFriendLink::where('er_user_id', '=', $me->id)
					->where('friend_er_user_id', '=', $player_id)
					->first();
				if (!$link) {
					throw new Exception("Invalid friend.");
				}
				$link->delete();
				break;
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
	<h3 class="mb-3">My Account</h3>
	<div class="row">
		<div class="col-md-6 mb-3">
			<form action="" method="post">
				<input type="hidden" name="action" value="save">
				<div class="card">
					<div class="card-header">
						<div class="row">
							<div class="col">
								<h4>Account Details</h4>
							</div>
							<div class="col-auto">
								<button type="submit" class="btn btn-outline-primary btn-sm">Save Changes</button>
							</div>
						</div>
					</div>
					<div class="card-body">
						<div class="mb-0">Email</div>
						<div>
							<input type="text" class="form-control" id="email" name="email" value="<?=$me->email?>">
						</div>
						<div class="row">
							<div class="col-6 mt-2 mb-0">First Name</div>
							<div class="col-6 mt-2 mb-0">Last Name</div>
						</div>
						<div class="row">
							<div class="col-6">
								<input type="text" class="form-control" id="first_name" name="first_name" value="<?=$me->first_name?>">
							</div>
							<div class="col-6">
								<input type="text" class="form-control" id="last_name" name="last_name" value="<?=$me->last_name?>">
							</div>
						</div>
						<div class="mt-2 mb-0">Venmo Phone / @</div>
						<div>
							<input type="text" class="form-control" id="venmo_phone" name="venmo_phone" value="<?=$me->venmo_phone?>">
						</div>
					</div>
				</div>
			</form>
		</div>
		<div class="col-md-6">
			<div class="card mb-3">
				<div class="card-header">
					<h4>Friends</h4>
				</div>
				<div class="card-body">
					<form action="" method="post">
						<div class="input-group">
							<select class="form-select" name="player_id">
								<option value="0" disabled selected>-- Add Friend --</option>
								<?php foreach ($players as $player): ?>
									<?php if (in_array($player->id, $friend_ids)) continue; ?>
									<?php if ($player->id == $me->id) continue; ?>
									<option value="<?=$player->id?>"><?=$player->getDisplayName()?></option>
								<?php endforeach; ?>
							</select>
							<button type="submit" name="action" value="add-friend" class="btn btn-outline-primary btn-sm">Add Friend</button>
						</div>
					</form>
					<form action="" method="post">
						<input type="hidden" name="action" value="remove-friend">
						<table class="table striped">
							<thead>
								<tr>
									<th>My Friends</th>
									<th>Remove</th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ($friends as $friend): ?>
									<tr>
										<td><?=$friend->getDisplayName()?></td>
										<td><button type="submit" class="btn btn-sm btn-outline-danger" name="player_id" value="<?=$friend->id?>">&times;</button></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</form>
					<?php if (sizeof($friended_users)): ?>
						<form action="" method="post">
							<input type="hidden" name="action" value="add-friend">
							<table class="table striped">
								<thead>
									<tr>
										<th>Suggested Friends</th>
										<th>Add</th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ($friended_users as $friended_user): ?>
										<tr>
											<td><?=$friended_user->getDisplayName()?></td>
											<td><button type="submit" class="btn btn-sm btn-outline-primary" name="player_id" value="<?=$friended_user->id?>">+</button></td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</form>
					<?php endif; ?>
				</div>
			</div>
		</div>
	</div>
</div>
<?php
$page->setContent(ob_get_clean());
print $page->render();
