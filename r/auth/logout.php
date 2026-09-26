<?php

require_once __DIR__ . '/../../inc/_inc.php';

use Pick55\Alert;
use Pick55\Auth;

Auth::logout();
Alert::info("You have signed out.");
redir('r/auth/login.php');
