<?php
/**
 * Pure helpers: tool labels, percent, period bounds, dates.
 *
 * No WordPress here beyond an optional apply_filters() for the tool labels
 * (skipped when WordPress is not loaded), so tests/test-format.php can run
 * every method with plain PHP. Callers pass the site time zone and "now" in;
 * nothing in this class reads a clock or an option of its own.
 */

defined( 'ABSPATH' ) || exit;

class TBTR_Format {

	/** Periods the filter accepts, in days; 'all' has no lower bound. */
	const PERIODS = array( '7', '30', 'all' );

	/** The default period. */
	const DEFAULT_PERIOD = '30';

	/**
	 * The built-in tool labels, keyed by slug.
	 *
	 * @return array<string,string>
	 */
	public static function default_labels() {
		return array(
			'swipe'    => 'Swipe',
			'matching' => 'Matching Game',
			'dragdrop' => 'Drag & Drop',
			'drill'    => 'Translation Drill',
			'test'     => 'Test',
			'fill'     => 'Fill',
			'homework' => 'Homework',
		);
	}

	/**
	 * Tool labels, filterable through `tbtr_tool_labels` when WordPress is
	 * loaded.
	 *
	 * @return array<string,string>
	 */
	public static function labels() {
		$labels = self::default_labels();
		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * Filter the tool labels Results shows, keyed by tool slug.
			 *
			 * @param array<string,string> $labels Slug => label.
			 */
			$filtered = apply_filters( 'tbtr_tool_labels', $labels );
			if ( is_array( $filtered ) ) {
				$labels = $filtered;
			}
		}
		return $labels;
	}

	/**
	 * The label for a tool slug. An unknown slug shows as itself with a
	 * capital first letter.
	 *
	 * @param string                    $slug   Tool slug.
	 * @param array<string,string>|null $labels Labels to use; null for labels().
	 * @return string
	 */
	public static function tool_label( $slug, $labels = null ) {
		$slug   = (string) $slug;
		$labels = is_array( $labels ) ? $labels : self::labels();
		if ( isset( $labels[ $slug ] ) && '' !== (string) $labels[ $slug ] ) {
			return (string) $labels[ $slug ];
		}
		return ucfirst( $slug );
	}

	/**
	 * round( score / score_max * 100 ) when both are set and score_max > 0,
	 * else null. A score of 0 is a real result: 0%.
	 *
	 * @param int|string|null $score     Score.
	 * @param int|string|null $score_max Maximum.
	 * @return int|null
	 */
	public static function percent( $score, $score_max ) {
		if ( null === $score || null === $score_max || '' === $score || '' === $score_max ) {
			return null;
		}
		$score     = (int) $score;
		$score_max = (int) $score_max;
		if ( $score_max <= 0 ) {
			return null;
		}
		return (int) round( $score / $score_max * 100 );
	}

	/**
	 * A period value from a request: '7', '30' or 'all'; anything else is
	 * the default.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function period( $value ) {
		$value = is_scalar( $value ) ? strtolower( trim( (string) $value ) ) : '';
		return in_array( $value, self::PERIODS, true ) ? $value : self::DEFAULT_PERIOD;
	}

	/**
	 * The UTC lower bound of a period, as a MySQL datetime, or null for 'all'.
	 *
	 * "N days back from now" is counted on the site's wall clock, so across a
	 * daylight-saving change the bound is still the same local time N days
	 * ago, not N × 24 hours ago.
	 *
	 * @param string        $period '7', '30' or 'all' (anything else: default).
	 * @param \DateTimeZone $tz     Site time zone.
	 * @param int           $now    Unix timestamp of "now".
	 * @return string|null
	 */
	public static function period_start( $period, \DateTimeZone $tz, $now ) {
		$period = self::period( $period );
		if ( 'all' === $period ) {
			return null;
		}
		return self::days_back( (int) $period, $tz, $now );
	}

	/**
	 * The UTC datetime N days back from now on the site's wall clock.
	 *
	 * @param int           $days Days.
	 * @param \DateTimeZone $tz   Site time zone.
	 * @param int           $now  Unix timestamp.
	 * @return string MySQL datetime, UTC.
	 */
	public static function days_back( $days, \DateTimeZone $tz, $now ) {
		$local = ( new \DateTimeImmutable( '@' . (int) $now ) )->setTimezone( $tz );
		$start = $local->modify( '-' . max( 0, (int) $days ) . ' days' );
		return $start->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * A stored UTC datetime as a DateTimeImmutable in the site time zone.
	 *
	 * @param string        $utc MySQL datetime, UTC.
	 * @param \DateTimeZone $tz  Site time zone.
	 * @return \DateTimeImmutable|null
	 */
	public static function local( $utc, \DateTimeZone $tz ) {
		$utc = (string) $utc;
		if ( '' === $utc ) {
			return null;
		}
		$dt = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $utc, new \DateTimeZone( 'UTC' ) );
		return $dt ? $dt->setTimezone( $tz ) : null;
	}

	/**
	 * ISO 8601 in the site time zone, or '' when the input is not a datetime.
	 *
	 * @param string        $utc MySQL datetime, UTC.
	 * @param \DateTimeZone $tz  Site time zone.
	 * @return string
	 */
	public static function iso( $utc, \DateTimeZone $tz ) {
		$dt = self::local( $utc, $tz );
		return $dt ? $dt->format( 'c' ) : '';
	}

	/**
	 * The site-time-zone calendar date of a stored UTC datetime (Y-m-d).
	 *
	 * @param string        $utc MySQL datetime, UTC.
	 * @param \DateTimeZone $tz  Site time zone.
	 * @return string
	 */
	public static function local_date( $utc, \DateTimeZone $tz ) {
		$dt = self::local( $utc, $tz );
		return $dt ? $dt->format( 'Y-m-d' ) : '';
	}

	/**
	 * "Today 18:40", "Yesterday 09:12", otherwise "11 Nov", with the year
	 * added when it is not this year: "11 Nov 2025". Site time zone.
	 *
	 * @param string        $utc MySQL datetime, UTC.
	 * @param \DateTimeZone $tz  Site time zone.
	 * @param int           $now Unix timestamp of "now".
	 * @return string
	 */
	public static function when_text( $utc, \DateTimeZone $tz, $now ) {
		$dt = self::local( $utc, $tz );
		if ( ! $dt ) {
			return '';
		}
		$today     = ( new \DateTimeImmutable( '@' . (int) $now ) )->setTimezone( $tz );
		$yesterday = $today->modify( '-1 day' );
		$day       = $dt->format( 'Y-m-d' );

		if ( $day === $today->format( 'Y-m-d' ) ) {
			return 'Today ' . $dt->format( 'H:i' );
		}
		if ( $day === $yesterday->format( 'Y-m-d' ) ) {
			return 'Yesterday ' . $dt->format( 'H:i' );
		}
		if ( $dt->format( 'Y' ) === $today->format( 'Y' ) ) {
			return $dt->format( 'j M' );
		}
		return $dt->format( 'j M Y' );
	}

	/**
	 * A search string from a request: trimmed, at most 100 characters.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function search( $value ) {
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';
		if ( function_exists( 'mb_substr' ) ) {
			return trim( mb_substr( $value, 0, 100 ) );
		}
		return trim( substr( $value, 0, 100 ) );
	}

	/**
	 * A tool slug from a request: the same shape Notes stores (a-z, 0-9, _
	 * and -, at most 20 characters), or ''.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function tool( $value ) {
		$value = is_scalar( $value ) ? strtolower( (string) $value ) : '';
		return substr( (string) preg_replace( '/[^a-z0-9_-]/', '', $value ), 0, 20 );
	}

	/**
	 * A mean of percents, rounded, or null when there are none.
	 *
	 * @param mixed $value Raw AVG() result.
	 * @return int|null
	 */
	public static function average( $value ) {
		if ( null === $value || '' === $value || ! is_numeric( $value ) ) {
			return null;
		}
		return (int) round( (float) $value );
	}
}
