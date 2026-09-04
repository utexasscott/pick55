<?php

require_once __DIR__ . '/../inc/_inc.php';

use Pick55\Auth;

Auth::logout();
redir('auth/login.php');
