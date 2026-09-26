<?php

namespace Pick55;

use \Exception;
use Pick55\Emailer;
use Pick55\Models\RememberToken;
use Pick55\Models\Signup;
use Pick55\Models\User;
use Pick55\Snippets\Emails\VerifyEmail as VerifyEmailSnippet;
use Pick55\Snippets\Emails\ResetPassword as ResetPasswordSnippet;

class Auth
{
	private function __construct() {}

	/**
	 */
	public static function guard()
	{
		if (!self::authed()) {
			redir('auth/login.php');
		}
	}

	/**
	 */
	public static function guardAdmin()
	{
		if (!self::authed()) {
			redir('auth/login.php');
		}
		if (!self::isAdmin()) {
			redir('index.php');
		}
	}

	/**
	 */
	public static function guardGuest()
	{
		if (self::authed()) {
			redir('index.php');
		}
	}

	/**
	 * @param Signup $signup
	 * @return User
	 */
	public static function convertSignup(Signup $signup)
	{
		$user = User::where('email', 'LIKE', $signup->email)
			->first();
		if ($user) {
			return $user;
		}
		$user = User::create([
			'email' => $signup->email,
			'first_name' => $signup->first_name,
			'last_name' => $signup->last_name,
			'password' => $signup->password,
			'salt' => $signup->salt,
		]);
		return $user;
	}

	/**
	 * Verifies the given password. If invalid, throws an exception.
	 *
	 * @param string $password
	 * @throws Exception
	 */
	public static function verifyPassword($password)
	{
		if (strlen($password) < 8) {
			throw new Exception("Passwords must be at least 8 characters in length.");
		}
	}

	/**
	 * Given an email address and a password, attempts to start the signup process.
	 *
	 * @param string $email
	 * @param string $password
	 * @return bool
	 */
	public static function attemptSignup($email, $password, $first_name, $last_name, $passcode)
	{
		if (strtoupper(trim($passcode)) !== 'FOOTBALL') {
			throw new Exception("Invalid passcode.");
		}
		self::verifyPassword($password);
		$salt = self::token();
		$encrypted_password = self::encryptPassword($password, $salt);
		$email = strtolower(trim(post('email')));
		$first_name = trim(post('first_name'));
		$last_name = trim(post('last_name'));
		$user = User::where('email', 'LIKE', $email)
			->first();
		if ($user) return false;
		$signup = Signup::where('email', 'LIKE', $email)
			->first();
		if (!$signup) {
			$verify_token = md5(self::token());
			$signup = Signup::create([
				'email' => $email,
				'password' => $encrypted_password,
				'first_name' => $first_name,
				'last_name' => $last_name,
				'salt' => $salt,
				'verify_token' => $verify_token,
			]);
			$signup->save();
		}
		else {
			$verify_token = $signup->verify_token;
		}

		// Generate and send an email with the verify token in a link
		$link = 'http:' . config('base_url') . 'auth/verify.php?token=' . $verify_token;
		$html = VerifyEmailSnippet::b($link);
		if (!Emailer::send($email, $email, "Verify Your Email", $html)) {
			return false;
		}
		return true;
	}

	/**
	 * Given an email address, attempts to start the process of resetting the password.
	 *
	 * @param string $email
	 * @throws Exception
	 */
	public static function attemptReset($email)
	{
		$email = trim($email);
		if (!strlen($email)) return;
		$user = User::where('email', 'LIKE', $email)
			->first();
		if (!$user) return;
		if (!self::sendResetPasswordEmail($user)) {
			throw new Exception(Page::STR_UNEXPECTED_ERROR);
		}
	}

	/**
	 * Sends an email to the user with a link to reset their password. Returns true
	 * if the email sent successfully, false otherwise.
	 *
	 * @param User $user
	 * @return bool
	 */
	public static function sendResetPasswordEmail(User $user)
	{
		// Check if we should just refresh the token instead of regenerating it
		if (strtotime($user->reset_token_at) > time() - 60 * 15) {
			// Use the existing token
			$reset_token = $user->reset_token;
		}
		else {
			// Create and save the reset token
			$reset_token = md5(self::token());
			$user->reset_token = $reset_token;
		}

		$user->reset_token_at = now();
		$user->save();

		// Generate and send an email with the reset token in a link
		$link = 'http:' . config('base_url') . 'auth/reset.php?token=' . $reset_token;
		$html = ResetPasswordSnippet::b($link);
		if (!Emailer::send($user->email, $user->getName(), "Reset Your Password", $html)) {
			return false;
		}
		return true;
	}

	/**
	 * @param User $user
	 * @param string $password
	 */
	public static function setNewPassword(User $user, $password)
	{
		self::verifyPassword($password);
		$salt = self::token();
		$user->password = self::encryptPassword($password, $salt);
		$user->salt = $salt;
		$user->reset_token = null;
		$user->reset_token_at = null;
		$user->save();
	}

	/**
	 * Checks if the given email and password are correct for a user.
	 * If they are correct, returns the user, otherwise returns null.
	 *
	 * @param string $email
	 * @param string $password
	 * @return User or null
	 */
	public static function checkCredentials($email, $password)
	{
		$email = trim($email);
		if (!strlen($email)) return null;
		if (!strlen($password)) return null;
		$user = User::where('email', 'LIKE', $email)
			->first();
		if (!$user) return null;
		if (!$user->password || !strlen($user->password)) return null;
		$encrypted_password = self::encryptPassword($password, $user->salt);
		if ($encrypted_password != $user->password) return null;
		return $user;
	}

	/**
	 * When not logged in, see if we can auto login via a cookie. When logged in,
	 * extends the cookie so the login lasts LOGIN_LIFETIME past the latest visit.
	 * The token is this device's own row (RememberToken) and is never rotated,
	 * so signing in elsewhere does not sign this device out.
	 */
	public static function attemptCookieLogin()
	{
		$token = isset($_COOKIE['remember_token']) ? (string) $_COOKIE['remember_token'] : '';
		if ($token === '') {
			return;
		}
		if (self::authed()) {
			self::sendRememberCookie($token);
			return;
		}
		$row = RememberToken::findValid($token);
		if (!$row) {
			$row = RememberToken::adoptLegacy($token);
		}
		if (!$row) {
			return;
		}
		$row->markUsed();
		self::sendRememberCookie($row->token);
		$_SESSION[SKEY]['user_id'] = (int) $row->er_user_id;
	}

	/**
	 * Returns the currently logged in user ID, null otherwise.
	 *
	 * @return int or null
	 */
	public static function getAuthedUserId()
	{
		if (isset($_SESSION[SKEY]['user_id'])) {
			return $_SESSION[SKEY]['user_id'];
		}
		return null;
	}

	/**
	 * Sets the currently logged in user by ID (a password login) and issues
	 * this device its own remember-me token.
	 *
	 * @param int or null $user_id
	 */
	public static function setAuthedUserId($user_id = null)
	{
		if (!$user_id) {
			return self::setNoAuth();
		}
		$user = User::find($user_id);
		if (!$user) {
			return self::setNoAuth();
		}

		$row = RememberToken::issue($user->id);
		self::sendRememberCookie($row->token);
		$_SESSION[SKEY]['user_id'] = (int) $user->id;
	}

	/**
	 * Sends the remember_token cookie, good for LOGIN_LIFETIME from now.
	 *
	 * @param string $token
	 */
	private static function sendRememberCookie($token)
	{
		setcookie('remember_token', $token, [
			'expires' => time() + LOGIN_LIFETIME,
			'path' => '/',
			'httponly' => true,
		]);
	}

	/**
	 * Signs this device out: its remember-me token row is deleted and the
	 * cookie expired. Other devices keep their own tokens.
	 */
	public static function setNoAuth()
	{
		if (!empty($_COOKIE['remember_token'])) {
			RememberToken::where('token', '=', (string) $_COOKIE['remember_token'])->delete();
		}
		setcookie('remember_token', '', [
			'expires' => time() - 3600,
			'path' => '/',
		]);
		$_SESSION[SKEY]['user_id'] = null;
	}

	/**
	 */
	public static function logout()
	{
		return self::setNoAuth();
	}

	/**
	 * Returns true if the user is currently logged in, false otherwise.
	 *
	 * @return bool
	 */
	public static function authed()
	{
		if (self::getAuthedUserId()) {
			return true;
		}
		return false;
	}

	/**
	 * Returns true if the user is currently logged in and is an admin, false otherwise.
	 *
	 * @return bool
	 */
	public static function isAdmin()
	{
		$user = self::user();
		if (!$user) {
			return false;
		}
		if ($user->is_admin) {
			return true;
		}
		return false;
	}

	/**
	 * Returns the currently logged in user, null otherwise.
	 *
	 * @return User or null
	 */
	public static function user()
	{
		static $cached_id = null;
		static $cached_user = null;
		$user_id = self::getAuthedUserId();
		if (!$user_id) {
			return null;
		}
		if ($cached_user === null || $cached_id !== $user_id) {
			$cached_id = $user_id;
			$cached_user = User::find($user_id);
		}
		return $cached_user;
	}

	/**
	 * Encrypts the given password with the given salt and returns it.
	 *
	 * @param string $password
	 * @param string $salt
	 * @return string
	 */
	public static function encryptPassword($password, $salt)
	{
		return sha1(md5($salt . $password));
	}

	/**
	 * Returns a generated token.
	 *
	 * @return string
	 */
	public static function token()
	{
		return sha1(uniqid(mt_rand(), true));
	}
}
