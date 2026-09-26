<?php

namespace Pick55\Models;

use Pick55\Auth;

/**
 * One remember-me token per signed-in device (er_users_remember_tokens).
 * See docs/login-sessions.md.
 *
 * A row is issued by a password login, kept alive by every cookie login
 * (last_used_at), deleted by logout, and expires LOGIN_LIFETIME after its
 * last use. Tokens are never rotated, so devices never sign each other out.
 */
class RememberToken extends BaseModel
{
	protected $table = 'er_users_remember_tokens';
	protected $primaryKey = 'id';
	public $incrementing = true;
	public $timestamps = false;
	protected $guarded = [];

	public function user()
	{
		return $this->belongsTo(User::class, 'er_user_id');
	}

	/**
	 * The row for a token, when it exists and was used within LOGIN_LIFETIME.
	 * An expired row is deleted on the way past.
	 *
	 * @param string $token
	 * @return RememberToken|null
	 */
	public static function findValid($token)
	{
		$token = (string) $token;
		if (strlen($token) < 16) {
			return null;
		}
		$row = self::where('token', '=', $token)->first();
		if (!$row) {
			return null;
		}
		if (strtotime($row->last_used_at) < time() - LOGIN_LIFETIME) {
			$row->delete();
			return null;
		}
		return $row;
	}

	/**
	 * Issues a fresh token for a user (a new device signing in) and prunes
	 * expired rows while here.
	 *
	 * @param int $user_id
	 * @return RememberToken
	 */
	public static function issue($user_id)
	{
		self::prune();
		return self::create([
			'er_user_id' => (int) $user_id,
			'token' => Auth::token(),
			'created_at' => now(),
			'last_used_at' => now(),
		]);
	}

	/**
	 * Before 2026-09-26 the one token per user lived in er_users.remember_token.
	 * A cookie still carrying such a token is moved into this table so nobody
	 * has to sign in again over the change. Safe to remove after 2026-10-26,
	 * when every legacy cookie has expired.
	 *
	 * @param string $token
	 * @return RememberToken|null
	 */
	public static function adoptLegacy($token)
	{
		$token = (string) $token;
		if (strlen($token) < 16) {
			return null;
		}
		$user = User::where('remember_token', '=', $token)->first();
		if (!$user) {
			return null;
		}
		$user->remember_token = null;
		$user->save();
		return self::create([
			'er_user_id' => (int) $user->id,
			'token' => $token,
			'created_at' => now(),
			'last_used_at' => now(),
		]);
	}

	/**
	 * Records a use, so the token lives another LOGIN_LIFETIME.
	 */
	public function markUsed()
	{
		$this->last_used_at = now();
		$this->save();
	}

	/**
	 * Deletes every token unused for LOGIN_LIFETIME.
	 *
	 * @return int rows deleted
	 */
	public static function prune()
	{
		return self::where('last_used_at', '<', date('Y-m-d H:i:s', time() - LOGIN_LIFETIME))
			->delete();
	}
}
