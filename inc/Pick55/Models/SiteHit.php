<?php

namespace Pick55\Models;

/**
 * One tracked page request (er_site_hits), written by Pick55\Traffic.
 * See docs/site-traffic.md.
 */
class SiteHit extends BaseModel
{
	protected $table = 'er_site_hits';
	protected $primaryKey = 'id';
	public $incrementing = true;
	public $timestamps = false;
	protected $guarded = [];

	public function user()
	{
		return $this->belongsTo(User::class, 'er_user_id');
	}
}
