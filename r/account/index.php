<?php

require_once __DIR__ . '/../../inc/_inc.php';

use Pick55\Alert;
use Pick55\Auth;
use Pick55\Models\User;
use Pick55\Models\UserFriendLink;
use Pick55\R\Fmt;
use Pick55\R\Icons;
use Pick55\R\Shell;

Shell::guard();

$me = Auth::user();
$flash_key = 'r_account_form';

// ---------------------------------------------------------------------
// POST: the profile (the classic account page's rules), and add/remove
// friend as the no-JS fallback of api/friends.php. Always back to GET.
// ---------------------------------------------------------------------

if (is_post()) {
	try {
		switch (post('action')) {
			case 'save':
				$email = trim((string) post('email'));
				$values = [
					'email' => $email,
					'first_name' => trim((string) post('first_name')),
					'last_name' => trim((string) post('last_name')),
					'venmo_phone' => trim((string) post('venmo_phone')),
				];
				if (strlen($email) < 5) {
					$_SESSION[$flash_key] = ['values' => $values, 'field' => 'email', 'error' => 'Enter a valid email address.'];
					throw new Exception('Email "' . $email . '" invalid.');
				}
				if (User::where('email', '=', $email)->where('id', '!=', $me->id)->first()) {
					$_SESSION[$flash_key] = ['values' => $values, 'field' => 'email', 'error' => 'Another account already uses this email.'];
					throw new Exception('User exists with email ' . $email);
				}
				// paypal_email is not on the form and is left as it is.
				$me->first_name = $values['first_name'];
				$me->last_name = $values['last_name'];
				$me->venmo_phone = ifempty($values['venmo_phone'], null);
				$me->email = $email;
				$me->save();
				Alert::success('Saved your profile.');
				break;
			case 'add-friend':
			case 'remove-friend':
				$player_id = (int) post('player_id');
				if ($player_id <= 0 || $player_id === (int) $me->id || !User::find($player_id)) {
					throw new Exception('Invalid player.');
				}
				if (post('action') === 'add-friend') {
					UserFriendLink::firstOrCreate(['er_user_id' => (int) $me->id, 'friend_er_user_id' => $player_id]);
				}
				else {
					UserFriendLink::where('er_user_id', '=', $me->id)->where('friend_er_user_id', '=', $player_id)->delete();
				}
				break;
		}
	}
	catch (Exception $e) {
		if (empty($_SESSION[$flash_key])) {
			Alert::error($e->getMessage());
		}
	}
	redir('r/account/index.php');
}

$shell = new Shell;
$ctx = $shell->ctx;
$shell->setTitle('Account');
$shell->setNav('account');
$shell->addStyle('css/pages/account.css');
$shell->addScript('js/pages/account.js');

// A failed save comes back with what was typed and the field to flag.
$form = null;
if (!empty($_SESSION[$flash_key])) {
	$form = $_SESSION[$flash_key];
	unset($_SESSION[$flash_key]);
}
$val = function ($key) use ($form, $me) {
	if ($form && isset($form['values'][$key])) {
		return $form['values'][$key];
	}
	return (string) $me->{$key};
};
$bad = function ($key) use ($form) {
	return $form && isset($form['field']) && $form['field'] === $key ? (string) $form['error'] : '';
};

// ---- Friends: mine, and the players who friended me that I have not friended back
$friend_ids = array_map('intval', UserFriendLink::where('er_user_id', '=', $me->id)
	->pluck('friend_er_user_id')
	->all());
$fan_ids = array_map('intval', UserFriendLink::where('friend_er_user_id', '=', $me->id)
	->pluck('er_user_id')
	->all());
$suggested_ids = array_values(array_diff($fan_ids, $friend_ids, [(int) $me->id]));

$pack = function (array $ids) {
	$out = [];
	if (sizeof($ids)) {
		foreach (User::whereIn('id', $ids)->get() as $u) {
			$out[] = ['id' => (int) $u->id, 'name' => $u->getDisplayName()];
		}
	}
	usort($out, function ($a, $b) {
		return strcasecmp($a['name'], $b['name']);
	});
	return $out;
};
$friends = $pack($friend_ids);
$suggested = $pack($suggested_ids);

// Everyone in the active season, for search-to-add.
$players = [];
foreach ($ctx->players() as $id => $user) {
	if ((int) $id !== (int) $me->id) {
		$players[] = ['id' => (int) $id, 'name' => $user->getDisplayName()];
	}
}
usort($players, function ($a, $b) {
	return strcasecmp($a['name'], $b['name']);
});

$shell->setModule('account', [
	'api' => $shell->link('api/friends.php'),
	'friends' => $friends,
	'suggested' => $suggested,
	'players' => $players,
	'season' => $ctx->season ? $ctx->season->name : null,
]);

/** "Scott No." -> "SN" */
$initials = function ($name) {
	$parts = preg_split('/\s+/', trim((string) $name));
	$out = '';
	foreach (array_slice($parts, 0, 2) as $p) {
		if ($p !== '') {
			$out .= mb_strtoupper(mb_substr($p, 0, 1));
		}
	}
	return $out !== '' ? $out : '?';
};

$friend_chip = function (array $f) use ($initials) {
	ob_start();
	?>
	<li class="friend-chip" data-id="<?=(int) $f['id']?>">
		<span class="avatar avatar-sm" aria-hidden="true"><?=h($initials($f['name']))?></span>
		<span class="friend-name"><?=h($f['name'])?></span>
		<form method="post" class="chip-form">
			<input type="hidden" name="action" value="remove-friend">
			<button type="submit" class="chip-x" name="player_id" value="<?=(int) $f['id']?>" data-friend-remove="<?=(int) $f['id']?>" aria-label="Remove <?=h($f['name'])?>" title="Remove"><?=Icons::svg('x')?></button>
		</form>
	</li>
	<?php
	return ob_get_clean();
};

$person_row = function (array $p) use ($initials) {
	ob_start();
	?>
	<li class="person" data-id="<?=(int) $p['id']?>">
		<span class="avatar avatar-sm" aria-hidden="true"><?=h($initials($p['name']))?></span>
		<span class="person-name truncate"><?=h($p['name'])?></span>
		<form method="post" class="chip-form">
			<input type="hidden" name="action" value="add-friend">
			<button type="submit" class="btn btn-ghost btn-sm" name="player_id" value="<?=(int) $p['id']?>" data-friend-add="<?=(int) $p['id']?>" aria-label="Add <?=h($p['name'])?>"><?=Icons::svg('plus')?>Add</button>
		</form>
	</li>
	<?php
	return ob_get_clean();
};

$display = Fmt::name($me);

ob_start();
?>
<header class="account-head enter">
	<span class="avatar account-avatar" aria-hidden="true"><?=h(Fmt::initials($me))?></span>
	<div class="account-id">
		<span class="eyebrow">Account</span>
		<h1 class="page-title"><?=h($display)?></h1>
		<p class="page-sub"><?=h($ctx->season ? ($ctx->is_player ? 'Playing the ' . $ctx->season->name : 'Not in the ' . $ctx->season->name . ' yet') : 'No season running')?></p>
	</div>
</header>

<div class="account-grid">
	<div class="stack">
		<section class="card enter" style="--i: 1" aria-labelledby="profile-title">
			<div class="card-head">
				<h2 class="card-title" id="profile-title"><?=Icons::svg('user')?>Profile</h2>
			</div>
			<form method="post" action="<?=h($shell->link('account/index.php'))?>" class="profile-form" data-async data-validate novalidate data-profile>
				<input type="hidden" name="action" value="save">
				<div class="field<?=$bad('email') !== '' ? ' is-invalid' : ''?>">
					<label class="field-label" for="acct-email">Email</label>
					<input class="input" type="email" id="acct-email" name="email" value="<?=h($val('email'))?>" required minlength="5" maxlength="255" autocomplete="email" inputmode="email" spellcheck="false"
						data-msg-required="Enter your email address." data-msg-type="That does not look like an email address." data-msg-length="Enter a valid email address."
						<?=$bad('email') !== '' ? 'aria-invalid="true"' : ''?> aria-describedby="acct-email-err">
					<div class="field-error" id="acct-email-err"><?=h($bad('email'))?></div>
				</div>
				<div class="field-row">
					<div class="field">
						<label class="field-label" for="acct-first">First name</label>
						<input class="input" type="text" id="acct-first" name="first_name" value="<?=h($val('first_name'))?>" maxlength="255" autocomplete="given-name">
						<div class="field-error"></div>
					</div>
					<div class="field">
						<label class="field-label" for="acct-last">Last name</label>
						<input class="input" type="text" id="acct-last" name="last_name" value="<?=h($val('last_name'))?>" maxlength="255" autocomplete="family-name">
						<div class="field-error"></div>
					</div>
				</div>
				<div class="field">
					<label class="field-label" for="acct-venmo">Venmo <span class="faint">phone or @handle</span></label>
					<input class="input" type="text" id="acct-venmo" name="venmo_phone" value="<?=h($val('venmo_phone'))?>" maxlength="255" autocomplete="off" spellcheck="false" placeholder="@your-handle">
					<div class="field-hint">Where your winnings are sent.</div>
					<div class="field-error"></div>
				</div>
				<div class="form-foot">
					<span class="faint small" data-dirty-note hidden>Unsaved changes</span>
					<button type="submit" class="btn btn-primary" data-save>Save profile</button>
				</div>
			</form>
		</section>

		<section class="card enter" style="--i: 3" aria-labelledby="prefs-title">
			<div class="card-head">
				<h2 class="card-title" id="prefs-title"><?=Icons::svg('settings')?>Appearance</h2>
			</div>
			<div class="pref-row">
				<div>
					<div class="pref-label">Theme</div>
					<div class="faint small">Saved on this device.</div>
				</div>
				<div class="seg" role="group" aria-label="Theme">
					<button type="button" data-theme-set="system" aria-pressed="false"><?=Icons::svg('monitor')?>System</button>
					<button type="button" data-theme-set="light" aria-pressed="false"><?=Icons::svg('sun')?>Light</button>
					<button type="button" data-theme-set="dark" aria-pressed="false"><?=Icons::svg('moon')?>Dark</button>
				</div>
			</div>
		</section>

		<nav class="card card-flush enter account-links" style="--i: 4" aria-label="More">
			<a class="link-row" href="<?=h($shell->link('rules.php'))?>"><?=Icons::svg('book-open')?><span>Rules</span><?=Icons::svg('chevron-right', 'link-chev')?></a>
			<a class="link-row" href="<?=h($shell->classicLink(''))?>" data-native><?=Icons::svg('external-link')?><span>Classic site</span><?=Icons::svg('chevron-right', 'link-chev')?></a>
			<?php if (Auth::isAdmin()): ?>
				<a class="link-row" href="<?=h($shell->classicLink('admin/index.php'))?>" data-native><?=Icons::svg('shield')?><span>Admin</span><?=Icons::svg('chevron-right', 'link-chev')?></a>
			<?php endif; ?>
			<a class="link-row link-row-danger" href="<?=h($shell->link('auth/logout.php'))?>" data-native><?=Icons::svg('log-out')?><span>Sign out</span><?=Icons::svg('chevron-right', 'link-chev')?></a>
		</nav>
	</div>

	<section class="card friends-card enter" style="--i: 2" aria-labelledby="friends-title" data-friends>
		<div class="card-head">
			<div>
				<h2 class="card-title" id="friends-title"><?=Icons::svg('users')?>Friends <span class="pill friend-count" data-friend-count><?=sizeof($friends)?></span></h2>
				<p class="card-sub">Friends are marked on standings and results.</p>
			</div>
		</div>

		<ul class="friend-chips" data-friend-list aria-label="Your friends"><?php foreach ($friends as $f): ?><?=$friend_chip($f)?><?php endforeach; ?></ul>
		<p class="friends-empty faint" data-friend-empty <?=sizeof($friends) ? 'hidden' : ''?>>No friends yet. Add the people you want to keep an eye on.</p>

		<div class="friend-add">
			<?php if (sizeof($players)): ?>
				<label class="field-label" for="friend-q">Add a friend</label>
				<div class="search js-only">
					<?=Icons::svg('search', 'search-icon')?>
					<input class="input search-input" type="search" id="friend-q" placeholder="Search <?=h(sizeof($players))?> players" title="Players in the <?=h($ctx->season->name)?>" autocomplete="off" spellcheck="false" data-friend-search aria-controls="friend-results" aria-describedby="friend-q-status">
				</div>
				<ul class="list people search-results" id="friend-results" data-friend-results aria-label="Matching players"></ul>
				<p class="faint small search-status" id="friend-q-status" data-friend-status aria-live="polite"></p>
				<form method="post" class="nojs-only nojs-add">
					<select class="select" name="player_id" aria-label="Player">
						<?php foreach ($players as $p): ?>
							<?php if (in_array($p['id'], $friend_ids, true)) continue; ?>
							<option value="<?=(int) $p['id']?>"><?=h($p['name'])?></option>
						<?php endforeach; ?>
					</select>
					<button type="submit" class="btn btn-ghost" name="action" value="add-friend">Add</button>
				</form>
			<?php else: ?>
				<p class="faint small mb-0">Search opens when a season is running: you can add any of its players.</p>
			<?php endif; ?>
		</div>

		<div class="suggested" data-suggested-wrap <?=sizeof($suggested) ? '' : 'hidden'?>>
			<h3 class="suggested-title">Suggested</h3>
			<p class="faint small">They added you. Add them back?</p>
			<ul class="list people" data-suggested-list aria-label="Suggested friends"><?php foreach ($suggested as $p): ?><?=$person_row($p)?><?php endforeach; ?></ul>
		</div>
	</section>
</div>
<?php
$shell->setContent(ob_get_clean());
print $shell->render();
