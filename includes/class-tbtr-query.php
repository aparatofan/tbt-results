<?php
/**
 * Every read of the TBT Notes activity table.
 *
 * Results never writes there. Each query takes an access scope from
 * TBTR_Access::scope() and is built by where(), so no list, count or stat
 * can reach rows outside it. Values always go through $wpdb->prepare();
 * search terms through $wpdb->esc_like().
 *
 * Index use: a teacher or administrator scope is class_id IN (...), which
 * the class_created (class_id, created_at) index serves, including the
 * period bound; a student scope is user_id = %d, served by user_created.
 */

defined( 'ABSPATH' ) || exit;

class TBTR_Query {

	/** Rows per page. */
	const PER_PAGE = 100;

	/** The columns a list reads. meta is never read. */
	const COLUMNS = 'id, user_id, class_id, student_name, tool, object_ref, object_title, score, score_max, created_at';

	/** SQL for one row's percent, null when it has none. Mirrors TBTR_Format::percent(). */
	const PERCENT_SQL = 'CASE WHEN score IS NOT NULL AND score_max IS NOT NULL AND score_max > 0 THEN ROUND( score * 100 / score_max ) END';

	/**
	 * The activity table name.
	 *
	 * @return string
	 */
	public static function table() {
		return TBT_Notes_DB::table_activity();
	}

	/**
	 * Build the WHERE clause for a scope and filters.
	 *
	 * Filters (all optional):
	 *   search        string  '' for none
	 *   search_names  bool    also match student_name (teacher views)
	 *   tool          string  exact slug
	 *   class         int     a class ID already checked against the visible set
	 *   since         string  UTC lower bound for created_at
	 *   user_id       int     one student (teacher's student view)
	 *   ref           string  object_ref (with tool: one activity)
	 *
	 * @param array $scope   From TBTR_Access::scope().
	 * @param array $filters Filters.
	 * @return array{0:string,1:array} SQL fragment and its arguments.
	 */
	public static function where( array $scope, array $filters = array() ) {
		global $wpdb;

		$sql  = array();
		$args = array();

		if ( isset( $scope['user_id'] ) ) {
			$sql[]  = 'user_id = %d';
			$args[] = (int) $scope['user_id'];
		} else {
			$ids = array_values( array_unique( array_map( 'intval', (array) ( $scope['class_ids'] ?? array() ) ) ) );
			if ( ! $ids ) {
				// No scope, no rows. Never fall through to an unscoped query.
				return array( '1 = 0', array() );
			}
			$sql[] = 'class_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')';
			$args  = array_merge( $args, $ids );
		}

		if ( isset( $filters['class'] ) && null !== $filters['class'] ) {
			$sql[]  = 'class_id = %d';
			$args[] = (int) $filters['class'];
		}
		if ( ! empty( $filters['user_id'] ) ) {
			$sql[]  = 'user_id = %d';
			$args[] = (int) $filters['user_id'];
		}
		if ( isset( $filters['tool'] ) && '' !== (string) $filters['tool'] ) {
			$sql[]  = 'tool = %s';
			$args[] = (string) $filters['tool'];
		}
		if ( isset( $filters['ref'] ) && null !== $filters['ref'] ) {
			$sql[]  = 'object_ref = %s';
			$args[] = (string) $filters['ref'];
		}
		if ( ! empty( $filters['since'] ) ) {
			$sql[]  = 'created_at >= %s';
			$args[] = (string) $filters['since'];
		}
		if ( isset( $filters['search'] ) && '' !== (string) $filters['search'] ) {
			$like = '%' . $wpdb->esc_like( (string) $filters['search'] ) . '%';
			if ( ! empty( $filters['search_names'] ) ) {
				$sql[]  = '( student_name LIKE %s OR object_title LIKE %s )';
				$args[] = $like;
				$args[] = $like;
			} else {
				$sql[]  = 'object_title LIKE %s';
				$args[] = $like;
			}
		}

		return array( implode( ' AND ', $sql ), $args );
	}

	/**
	 * Run a prepared query built from a WHERE clause.
	 *
	 * @param string $select  Everything before WHERE, with {t} for the table.
	 * @param array  $where   From where().
	 * @param string $tail    GROUP BY / ORDER BY / LIMIT, with %d placeholders.
	 * @param array  $tail_args Arguments for $tail.
	 * @return string Prepared SQL.
	 */
	private static function sql( $select, array $where, $tail = '', array $tail_args = array() ) {
		global $wpdb;
		$sql  = str_replace( '{t}', self::table(), $select ) . ' WHERE ' . $where[0] . ( '' !== $tail ? ' ' . $tail : '' );
		$args = array_merge( $where[1], $tail_args );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders are generated; every value is in $args.
		return $args ? $wpdb->prepare( $sql, $args ) : $sql;
	}

	/**
	 * One page of rows, newest first, with the total for the same filters.
	 *
	 * @param array $scope   Access scope.
	 * @param array $filters Filters.
	 * @param int   $page    Page, from 1.
	 * @return array{rows:array[],has_more:bool,total:int}
	 */
	public static function page( array $scope, array $filters, $page ) {
		global $wpdb;
		$page  = max( 1, (int) $page );
		$where = self::where( $scope, $filters );

		// One extra row says whether there is a next page.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared in sql().
		$rows = $wpdb->get_results(
			self::sql( 'SELECT ' . self::COLUMNS . ' FROM {t}', $where, 'ORDER BY id DESC LIMIT %d OFFSET %d', array( self::PER_PAGE + 1, ( $page - 1 ) * self::PER_PAGE ) ),
			ARRAY_A
		);
		$rows     = is_array( $rows ) ? $rows : array();
		$has_more = count( $rows ) > self::PER_PAGE;
		if ( $has_more ) {
			$rows = array_slice( $rows, 0, self::PER_PAGE );
		}

		if ( 1 === $page && ! $has_more ) {
			$total = count( $rows );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared in sql().
			$total = (int) $wpdb->get_var( self::sql( 'SELECT COUNT(*) FROM {t}', $where ) );
		}

		return array(
			'rows'     => $rows,
			'has_more' => $has_more,
			'total'    => $total,
		);
	}

	/**
	 * Count, distinct students, average percent and best percent.
	 *
	 * @param array $scope   Access scope.
	 * @param array $filters Filters.
	 * @return array{finished:int,students:int,average:int|null,best:int|null}
	 */
	public static function summary( array $scope, array $filters ) {
		global $wpdb;
		$where = self::where( $scope, $filters );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared in sql().
		$row = $wpdb->get_row(
			self::sql( 'SELECT COUNT(*) AS n, COUNT(DISTINCT user_id) AS u, AVG(' . self::PERCENT_SQL . ') AS a, MAX(' . self::PERCENT_SQL . ') AS b FROM {t}', $where ),
			ARRAY_A
		);
		return array(
			'finished' => (int) ( $row['n'] ?? 0 ),
			'students' => (int) ( $row['u'] ?? 0 ),
			'average'  => TBTR_Format::average( $row['a'] ?? null ),
			'best'     => isset( $row['b'] ) && null !== $row['b'] ? (int) $row['b'] : null,
		);
	}

	/**
	 * Per tool: count and average (null for a tool with no scored rows).
	 * Most-used first, then by label.
	 *
	 * @param array $scope   Access scope.
	 * @param array $filters Filters.
	 * @return array[] Each: tool, tool_label, count, average.
	 */
	public static function by_tool( array $scope, array $filters ) {
		global $wpdb;
		$where = self::where( $scope, $filters );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared in sql().
		$rows   = $wpdb->get_results(
			self::sql( 'SELECT tool, COUNT(*) AS n, AVG(' . self::PERCENT_SQL . ') AS a FROM {t}', $where, 'GROUP BY tool' ),
			ARRAY_A
		);
		$labels = TBTR_Format::labels();
		$out    = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[] = array(
				'tool'       => (string) $row['tool'],
				'tool_label' => TBTR_Format::tool_label( $row['tool'], $labels ),
				'count'      => (int) $row['n'],
				'average'    => TBTR_Format::average( $row['a'] ),
			);
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return $b['count'] <=> $a['count'] ?: strnatcasecmp( $a['tool_label'], $b['tool_label'] );
			}
		);
		return $out;
	}

	/**
	 * Tool slugs present in the scope, with labels, A–Z by label.
	 *
	 * @param array $scope   Access scope.
	 * @param array $filters Filters.
	 * @return array[] Each: slug, label.
	 */
	public static function tools( array $scope, array $filters = array() ) {
		global $wpdb;
		$where = self::where( $scope, $filters );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared in sql().
		$slugs  = $wpdb->get_col( self::sql( 'SELECT DISTINCT tool FROM {t}', $where ) );
		$labels = TBTR_Format::labels();
		$out    = array();
		foreach ( is_array( $slugs ) ? $slugs : array() as $slug ) {
			$out[] = array(
				'slug'  => (string) $slug,
				'label' => TBTR_Format::tool_label( $slug, $labels ),
			);
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return strnatcasecmp( $a['label'], $b['label'] );
			}
		);
		return $out;
	}

	/**
	 * Distinct site-time-zone dates with at least one row.
	 *
	 * Reads created_at only, for one student over a bounded period.
	 *
	 * @param array         $scope   Access scope.
	 * @param array         $filters Filters (normally user_id/since).
	 * @param \DateTimeZone $tz      Site time zone.
	 * @return int
	 */
	public static function active_days( array $scope, array $filters, \DateTimeZone $tz ) {
		global $wpdb;
		$where = self::where( $scope, $filters );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared in sql().
		$times = $wpdb->get_col( self::sql( 'SELECT created_at FROM {t}', $where ) );
		$days  = array();
		foreach ( is_array( $times ) ? $times : array() as $utc ) {
			$day = TBTR_Format::local_date( $utc, $tz );
			if ( '' !== $day ) {
				$days[ $day ] = true;
			}
		}
		return count( $days );
	}

	/**
	 * The newest row in a scope, or null.
	 *
	 * @param array $scope   Access scope.
	 * @param array $filters Filters.
	 * @return array|null
	 */
	public static function newest( array $scope, array $filters ) {
		global $wpdb;
		$where = self::where( $scope, $filters );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared in sql().
		$row = $wpdb->get_row( self::sql( 'SELECT ' . self::COLUMNS . ' FROM {t}', $where, 'ORDER BY id DESC LIMIT 1' ), ARRAY_A );
		return $row ? $row : null;
	}

	/**
	 * The newest scored row's score_max, or null when no row has a score.
	 *
	 * @param array $scope   Access scope.
	 * @param array $filters Filters.
	 * @return int|null
	 */
	public static function newest_score_max( array $scope, array $filters ) {
		global $wpdb;
		$where     = self::where( $scope, $filters );
		$where[0] .= ' AND score IS NOT NULL AND score_max IS NOT NULL AND score_max > 0';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared in sql().
		$max = $wpdb->get_var( self::sql( 'SELECT score_max FROM {t}', $where, 'ORDER BY id DESC LIMIT 1' ) );
		return null === $max ? null : (int) $max;
	}

	/**
	 * Distinct class IDs and user IDs in a scope.
	 *
	 * @param array  $scope   Access scope.
	 * @param array  $filters Filters.
	 * @param string $column  'class_id' or 'user_id'.
	 * @return int[]
	 */
	public static function distinct( array $scope, array $filters, $column ) {
		global $wpdb;
		$column = 'class_id' === $column ? 'class_id' : 'user_id';
		$where  = self::where( $scope, $filters );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared in sql(); $column is one of two literals.
		$ids = $wpdb->get_col( self::sql( "SELECT DISTINCT {$column} FROM {t}", $where ) );
		return array_map( 'intval', is_array( $ids ) ? $ids : array() );
	}

	/**
	 * An activity's name is object_title from its newest row. For the
	 * activities on a page, the newest title within the same scope.
	 *
	 * @param array   $scope Access scope.
	 * @param array[] $rows  Raw rows.
	 * @return array<string,string> "tool\nref" => title.
	 */
	public static function newest_titles( array $scope, array $rows ) {
		global $wpdb;
		$pairs = array();
		foreach ( $rows as $row ) {
			$pairs[ $row['tool'] . "\n" . $row['object_ref'] ] = array( (string) $row['tool'], (string) $row['object_ref'] );
		}
		if ( ! $pairs ) {
			return array();
		}

		$where = self::where( $scope );
		$or    = array();
		foreach ( $pairs as $pair ) {
			$or[]        = '( tool = %s AND object_ref = %s )';
			$where[1][]  = $pair[0];
			$where[1][]  = $pair[1];
		}
		$where[0] .= ' AND ( ' . implode( ' OR ', $or ) . ' )';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared in sql().
		$ids = $wpdb->get_col( self::sql( 'SELECT MAX(id) FROM {t}', $where, 'GROUP BY tool, object_ref' ) );
		$ids = array_values( array_filter( array_map( 'intval', is_array( $ids ) ? $ids : array() ) ) );
		if ( ! $ids ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders are generated; values are prepared.
		$found = $wpdb->get_results( $wpdb->prepare( 'SELECT tool, object_ref, object_title FROM ' . self::table() . " WHERE id IN ({$placeholders})", $ids ), ARRAY_A );
		$out   = array();
		foreach ( is_array( $found ) ? $found : array() as $row ) {
			$out[ $row['tool'] . "\n" . $row['object_ref'] ] = (string) $row['object_title'];
		}
		return $out;
	}

	/**
	 * Current display names for users, in one query.
	 *
	 * @param int[] $user_ids User IDs.
	 * @return array<int,string> ID => display name (or login when empty).
	 */
	public static function user_names( array $user_ids ) {
		$user_ids = array_values( array_unique( array_filter( array_map( 'intval', $user_ids ) ) ) );
		if ( ! $user_ids ) {
			return array();
		}
		$users = get_users(
			array(
				'include' => $user_ids,
				'fields'  => array( 'ID', 'display_name', 'user_login' ),
			)
		);
		$out = array();
		foreach ( $users as $user ) {
			$name                    = trim( (string) $user->display_name );
			$out[ (int) $user->ID ] = '' !== $name ? $name : (string) $user->user_login;
		}
		return $out;
	}

	/**
	 * Shape raw rows for the browser.
	 *
	 * @param array   $scope  Access scope (for the newest activity titles).
	 * @param array[] $rows   Raw rows.
	 * @param array   $titles Class titles, id => title.
	 * @return array[]
	 */
	public static function shape( array $scope, array $rows, array $titles ) {
		if ( ! $rows ) {
			return array();
		}

		$tz     = wp_timezone();
		$now    = time();
		$labels = TBTR_Format::labels();
		$names  = self::newest_titles( $scope, $rows );

		// student_name is a snapshot; only an empty one falls back to the
		// user's current display name.
		$missing = array();
		foreach ( $rows as $row ) {
			if ( '' === trim( (string) $row['student_name'] ) ) {
				$missing[] = (int) $row['user_id'];
			}
		}
		$current = self::user_names( $missing );

		$out = array();
		foreach ( $rows as $row ) {
			$key      = $row['tool'] . "\n" . $row['object_ref'];
			$class_id = (int) $row['class_id'];
			$student  = trim( (string) $row['student_name'] );
			$score    = null === $row['score'] ? null : (int) $row['score'];
			$max      = null === $row['score_max'] ? null : (int) $row['score_max'];

			$out[] = array(
				'id'          => (int) $row['id'],
				'user_id'     => (int) $row['user_id'],
				'student'     => '' !== $student ? $student : (string) ( $current[ (int) $row['user_id'] ] ?? '' ),
				'tool'        => (string) $row['tool'],
				'tool_label'  => TBTR_Format::tool_label( $row['tool'], $labels ),
				'ref'         => (string) $row['object_ref'],
				'title'       => isset( $names[ $key ] ) && '' !== $names[ $key ] ? $names[ $key ] : (string) $row['object_title'],
				'class_id'    => $class_id,
				'class_title' => self::class_title( $class_id, $titles ),
				'when'        => TBTR_Format::iso( $row['created_at'], $tz ),
				'when_text'   => TBTR_Format::when_text( $row['created_at'], $tz, $now ),
				'score'       => $score,
				'score_max'   => $max,
				'percent'     => TBTR_Format::percent( $score, $max ),
			);
		}
		return $out;
	}

	/**
	 * A class's title for display: "No class" for 0.
	 *
	 * @param int   $class_id Class ID.
	 * @param array $titles   id => title.
	 * @return string
	 */
	public static function class_title( $class_id, array $titles ) {
		$class_id = (int) $class_id;
		if ( 0 === $class_id ) {
			return __( 'No class', 'tbt-results' );
		}
		return isset( $titles[ $class_id ] ) ? (string) $titles[ $class_id ] : '';
	}
}
