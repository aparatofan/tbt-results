<?php
/**
 * Standalone tests for TBTR_Format: tool labels, percent, period bounds and
 * the "When" text.
 *
 * WordPress is not loaded, so apply_filters() does not exist and the
 * `tbtr_tool_labels` filter is never applied: labels() must still work.
 *
 * Run: php tests/test-format.php
 */
error_reporting( E_ALL );
define( 'ABSPATH', __DIR__ . '/' );

require __DIR__ . '/../includes/class-tbtr-format.php';

$pass = 0;
$fail = 0;
function ok( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) {
		$pass++;
	} else {
		$fail++;
		echo "FAIL: {$label}\n";
	}
}
function same( $actual, $expected, $label ) {
	ok( $actual === $expected, $label . ( $actual === $expected ? '' : "\n  expected: " . var_export( $expected, true ) . "\n  actual:   " . var_export( $actual, true ) ) );
}
/** Unix timestamp of a local wall-clock time in a zone. */
function at( $local, $zone ) {
	return ( new DateTimeImmutable( $local, new DateTimeZone( $zone ) ) )->getTimestamp();
}

/* ---------- Tool labels ---------- */

same( function_exists( 'apply_filters' ), false, 'WordPress is not loaded, so no filter can apply' );
same( TBTR_Format::tool_label( 'swipe' ), 'Swipe', 'swipe' );
same( TBTR_Format::tool_label( 'matching' ), 'Matching Game', 'matching' );
same( TBTR_Format::tool_label( 'dragdrop' ), 'Drag & Drop', 'dragdrop' );
same( TBTR_Format::tool_label( 'drill' ), 'Translation Drill', 'drill' );
same( TBTR_Format::tool_label( 'test' ), 'Test', 'test' );
same( TBTR_Format::tool_label( 'fill' ), 'Fill', 'fill' );
same( TBTR_Format::tool_label( 'homework' ), 'Homework', 'homework' );
same( TBTR_Format::tool_label( 'quiz' ), 'Quiz', 'unknown slug: itself, capital first letter' );
same( TBTR_Format::tool_label( 'word-race' ), 'Word-race', 'unknown slug with a hyphen' );
same( TBTR_Format::tool_label( '' ), '', 'empty slug' );
same( TBTR_Format::labels(), TBTR_Format::default_labels(), 'labels() without WordPress is the defaults' );
same( TBTR_Format::tool_label( 'swipe', array( 'swipe' => 'Decks' ) ), 'Decks', 'explicit labels win' );
same( TBTR_Format::tool_label( 'drill', array( 'swipe' => 'Decks' ) ), 'Drill', 'explicit labels without the slug: capitalised slug' );

/* ---------- Percent ---------- */

same( TBTR_Format::percent( 7, 10 ), 70, '7 / 10' );
same( TBTR_Format::percent( 2, 3 ), 67, '2 / 3 rounds' );
same( TBTR_Format::percent( 1, 8 ), 13, '1 / 8 = 12.5 rounds half up' );
same( TBTR_Format::percent( '17', '20' ), 85, 'numeric strings from the database' );
same( TBTR_Format::percent( 0, 10 ), 0, '0 is a real score' );
same( TBTR_Format::percent( null, 10 ), null, 'null score: no percent' );
same( TBTR_Format::percent( 5, null ), null, 'null score_max: no percent' );
same( TBTR_Format::percent( null, null ), null, 'both null' );
same( TBTR_Format::percent( 0, 0 ), null, 'score_max 0: no percent' );
same( TBTR_Format::percent( 3, 0 ), null, 'score_max 0 with a score: no percent' );

same( TBTR_Format::average( '72.5000' ), 73, 'average rounds' );
same( TBTR_Format::average( null ), null, 'no scored rows: null average' );

/* ---------- Period bounds ---------- */

$utc    = new DateTimeZone( 'UTC' );
$warsaw = new DateTimeZone( 'Europe/Warsaw' );

same( TBTR_Format::period( '7' ), '7', 'period 7' );
same( TBTR_Format::period( 'all' ), 'all', 'period all' );
same( TBTR_Format::period( '' ), '30', 'empty period: default 30' );
same( TBTR_Format::period( '365' ), '30', 'unknown period: default 30' );
same( TBTR_Format::period( array() ), '30', 'array period: default 30' );

$now = at( '2026-11-13 12:00:00', 'UTC' );
same( TBTR_Format::period_start( '7', $utc, $now ), '2026-11-06 12:00:00', '7 days in UTC' );
same( TBTR_Format::period_start( '30', $utc, $now ), '2026-10-14 12:00:00', '30 days in UTC' );
same( TBTR_Format::period_start( 'all', $utc, $now ), null, 'all: no bound' );
same( TBTR_Format::period_start( 'bogus', $utc, $now ), '2026-10-14 12:00:00', 'bogus: the 30-day default' );

// Warsaw, no DST change inside the window: CET (UTC+1) at both ends.
$now = at( '2026-11-13 12:00:00', 'Europe/Warsaw' ); // 11:00 UTC
same( TBTR_Format::period_start( '7', $warsaw, $now ), '2026-11-06 11:00:00', 'Warsaw 7 days, both ends CET' );

// Warsaw across the end of summer time (25 Oct 2026, 03:00 CEST -> 02:00 CET).
// Now is 28 Oct 12:00 CET (11:00 UTC); 7 days back is 21 Oct 12:00 CEST,
// which is 10:00 UTC: seven local days, 169 hours.
$now   = at( '2026-10-28 12:00:00', 'Europe/Warsaw' );
$start = TBTR_Format::period_start( '7', $warsaw, $now );
same( $start, '2026-10-21 10:00:00', 'Warsaw 7 days across the autumn DST change' );
same( $now - ( new DateTimeImmutable( $start, $utc ) )->getTimestamp(), 169 * 3600, 'autumn: 169 hours, not 168' );

// Across the start of summer time (29 Mar 2026, 02:00 CET -> 03:00 CEST).
// Now is 2 Apr 12:00 CEST (10:00 UTC); 30 days back is 3 Mar 12:00 CET, 11:00 UTC.
$now = at( '2026-04-02 12:00:00', 'Europe/Warsaw' );
same( TBTR_Format::period_start( '30', $warsaw, $now ), '2026-03-03 11:00:00', 'Warsaw 30 days across the spring DST change' );

/* ---------- "When" text ---------- */

$now = at( '2026-11-13 23:40:00', 'Europe/Warsaw' ); // 22:40 UTC

same( TBTR_Format::when_text( '2026-11-13 17:40:00', $warsaw, $now ), 'Today 18:40', 'today, in site time' );
same( TBTR_Format::when_text( '2026-11-12 23:30:00', $warsaw, $now ), 'Today 00:30', 'UTC yesterday but site today' );
same( TBTR_Format::when_text( '2026-11-12 08:12:00', $warsaw, $now ), 'Yesterday 09:12', 'yesterday' );
same( TBTR_Format::when_text( '2026-11-11 23:30:00', $warsaw, $now ), 'Yesterday 00:30', 'UTC two days ago but site yesterday' );
same( TBTR_Format::when_text( '2026-11-11 10:00:00', $warsaw, $now ), '11 Nov', 'this year' );
same( TBTR_Format::when_text( '2026-01-01 10:00:00', $warsaw, $now ), '1 Jan', 'this year, January' );
same( TBTR_Format::when_text( '2025-11-11 10:00:00', $warsaw, $now ), '11 Nov 2025', 'last year: with the year' );
same( TBTR_Format::when_text( '2025-12-31 23:30:00', $warsaw, at( '2026-01-01 10:00:00', 'Europe/Warsaw' ) ), 'Today 00:30', 'New Year: UTC last year, site today' );
same( TBTR_Format::when_text( '2025-12-31 10:00:00', $warsaw, at( '2026-01-01 10:00:00', 'Europe/Warsaw' ) ), 'Yesterday 11:00', 'yesterday across the year boundary' );
same( TBTR_Format::when_text( '', $warsaw, $now ), '', 'no datetime' );

same( TBTR_Format::iso( '2026-11-13 17:40:00', $warsaw ), '2026-11-13T18:40:00+01:00', 'ISO 8601 in the site time zone' );
same( TBTR_Format::iso( '2026-07-01 10:00:00', $warsaw ), '2026-07-01T12:00:00+02:00', 'ISO 8601 in summer time' );
same( TBTR_Format::local_date( '2026-11-12 23:30:00', $warsaw ), '2026-11-13', 'local date crosses midnight' );

/* ---------- Request values ---------- */

same( TBTR_Format::search( '  Kat  ' ), 'Kat', 'search is trimmed' );
same( mb_strlen( TBTR_Format::search( str_repeat( 'ż', 150 ) ) ), 100, 'search is cut to 100 characters, not bytes' );
same( TBTR_Format::tool( 'DragDrop' ), 'dragdrop', 'tool slug lowercased' );
same( TBTR_Format::tool( "swipe'; DROP" ), 'swipedrop', 'tool slug stripped to the stored shape' );

echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail ? 1 : 0 );
