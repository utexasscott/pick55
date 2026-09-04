<?php

namespace Pick55\Models;

class Team extends BaseModel
{
	protected $table = 'football_teams';
	protected $primaryKey = 'id';
	public $incrementing = true;
	public $timestamps = false;
	protected $guarded = [];

	/**
	 * @return string
	 */
	public function __toString()
	{
		return $this->getName();
	}

	/**
	 * @return string
	 */
	public function getName()
	{
		return trim($this->team . ' ' . $this->nickname);
	}

	/**
	 * @return array<string>
	 */
	public static function getDefaultColors()
	{
		return [
			'eeeeee', // background
			'000000', // text
			'cccccc', // border
		];
	}

	/**
	 * @param string $colors
	 * @return string
	 */
	public static function getCssForColors($color1, $color2, $color3)
	{
		$default_colors = self::getDefaultColors();
		return "background-color: #" . ifempty($color1, $default_colors[0]) . ";"
			. "color: #" . ifempty($color2, $default_colors[1]) . ";"
			. "border: 4px solid #" . ifempty($color3, $default_colors[2]) . ";";
	}

	/**
	 * @param string $colors
	 * @return string
	 */
	public static function getInnerCssForColors($color1, $color2, $color3)
	{
		$default_colors = self::getDefaultColors();
		return "background-color: #" . ifempty($color1, $default_colors[0]) . ";"
			. "color: #" . ifempty($color2, $default_colors[1]) . ";";
	}

	/**
	 * @return string
	 */
	public function getCss()
	{
		return self::getCssForColors($this->color_1, $this->color_2, $this->color_3);
	}
}
