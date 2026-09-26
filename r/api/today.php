<?php

require_once __DIR__ . '/../../inc/_inc.php';

use Pick55\R\Api;
use Pick55\R\Context;
use Pick55\R\Fmt;
use Pick55\R\Today;

// The Today hero's live refresh (docs/redesign.md section 6): the moment
// (Context::toArray()) plus the live week's games with the viewer's picks.
Api::guard('GET');

$ctx = Context::get();
$data = $ctx->toArray();
$data['fetched_at'] = Fmt::iso(time());
$data['games'] = [];
if ($ctx->live_week && $ctx->is_player) {
	$data['games'] = Today::strip($ctx, $ctx->live_week);
}
if ($ctx->my_live) {
	$data['my_live']['expected_label'] = Fmt::money($ctx->my_live['expected']);
}
Api::json($data);
