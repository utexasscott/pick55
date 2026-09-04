<?php

namespace Pick55;

class ColorFormatter
{
	/**
	 * HSV to RGB color conversion
	 *
	 * @param int $h Hue color component, between 0 and 360*
	 * @param int $s Saturation color component, between 0 and 100*
	 * @param int $v Value (brightness) color component, between 0 and 100*
	 * @param bool $alt_scape If true, $h, $s and $v are scaled between 0 and 255 instead of their defaults
	 * @return assoc array with keys r, g, and b and values between 0 and 255
	 */
	public static function HSV_to_RGB($h, $s, $v, $alt_scale = false)
	{
		if ($alt_scale) {
			$h = $h / 255;
			$s = $s / 255;
			$v = $v / 255;
		}
		else {
			$h = $h / 360;
			$s = $s / 100;
			$v = $v / 100;
		}

		$r = 0;
		$g = 0;
		$b = 0;

		$i = floor($h * 6);
		$f = $h * 6 - $i;
		$p = $v * (1 - $s);
		$q = $v * (1 - $f * $s);
		$t = $v * (1 - (1 - $f) * $s);

		switch ($i % 6) {
			case 0: $r = $v; $g = $t; $b = $p; break;
			case 1: $r = $q; $g = $v; $b = $p; break;
			case 2: $r = $p; $g = $v; $b = $t; break;
			case 3: $r = $p; $g = $q; $b = $v; break;
			case 4: $r = $t; $g = $p; $b = $v; break;
			case 5: $r = $v; $g = $p; $b = $q; break;
		}

		return array('r' => round($r * 255), 'g' => round($g * 255), 'b' => round($b * 255));
	}

	/**
	 * RBG to HSV color conversion
	 *
	 * @param int $r Red color component, between 0 and 255
	 * @param int $g Green color component, between 0 and 255
	 * @param int $b Blue color component, between 0 and 255
	 * @return assoc array with keys h, s, and v and values between 0 and 1
	 */
	public static function RGB_to_HSV($r, $g, $b)
	{
		$r = ($r / 255);
		$g = ($g / 255);
		$b = ($b / 255);

		$min = min($r, $g, $b);
		$max = max($r, $g, $b);
		$delta = $max - $min;

		$v = $max;

		if ($delta == 0) {
			$h = 0;
			$s = 0;
		}
		else {
			$s = $delta / $max;

			$dr = ((($max - $r) / 6) + ($delta / 2)) / $delta;
			$dg = ((($max - $g) / 6) + ($delta / 2)) / $delta;
			$db = ((($max - $b) / 6) + ($delta / 2)) / $delta;

			if ($r == $max) {
				$h = $db - $dg;
			}
			else if ($g == $max) {
				$h = (1 / 3) + $dr - $db;
			}
			else if ($b == $max) {
				$h = (2 / 3) + $dg - $dr;
			}

			if ($h < 0) {
				$h++;
			}
			if ($h > 1) {
				$h--;
			}
		}

		return array('h' => $h, 's' => $s, 'v' => $v);
	}
}
