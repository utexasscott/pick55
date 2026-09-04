<?php

require_once __DIR__ . '/../inc/_inc.php';

use Pick55\Alert;
use Pick55\App;
use Pick55\Models\Season;

$app = App::get();

if (is_post()) {
	try {
		if (post('select_season_id')) {
			$season = Season::find(post('select_season_id'));
			if ($season) {
				$app->selectSeason($season);
			}
			redir();
		}
	}
	catch (Exception $e) {
		Alert::error($e->getMessage());
	}
}
