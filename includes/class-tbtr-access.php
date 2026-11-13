<?php
/**
 * Who may see which activity rows. One place decides; every query goes
 * through scope().
 *
 *   Administrator  every row, including class_id 0 (queried as "every class
 *                  plus class 0", so the class_created index is used)
 *   Teacher        rows whose class_id is one of their own classes
 *   Student        rows with their own user_id
 *   Logged out     nothing
 *
 * Results reads TBT Notes only through its public static helpers. When Notes
 * is missing or older than 1.22.0, notes_ready() is false and nothing here
 * touches a Notes class.
 */

defined( 'ABSPATH' ) || exit;

class TBTR_Access {

	/** The Notes release that introduced the activity log. */
	const NOTES_MIN = '1.22.0';

	/** TBT_Notes_DB methods Results relies on. */
	const NOTES_METHODS = array(
		'table_activity',
		'get_class',
		'get_all_classes',
		'get_classes_for_teacher',
		'get_student_ids_for_classes',
		'get_class_id_of_student',
	);

	/** Per-request cache of class lists, keyed by user ID. */
	private static $classes = array();

	/**
	 * Is TBT Notes 1.22.0+ active with everything Results reads?
	 *
	 * @return bool
	 */
	public static function notes_ready() {
		if ( ! class_exists( 'TBT_Notes_DB' ) || ! class_exists( 'TBT_Notes_Capabilities' ) ) {
			return false;
		}
		if ( ! defined( 'TBT_NOTES_VERSION' ) || version_compare( (string) TBT_NOTES_VERSION, self::NOTES_MIN, '<' ) ) {
			return false;
		}
		foreach ( self::NOTES_METHODS as $method ) {
			if ( ! method_exists( 'TBT_Notes_DB', $method ) ) {
				return false;
			}
		}
		return method_exists( 'TBT_Notes_Capabilities', 'user_can_manage' )
			&& method_exists( 'TBT_Notes_Capabilities', 'user_can_manage_all' );
	}

	/**
	 * Teacher or administrator.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function is_teacher( $user_id ) {
		$user_id = (int) $user_id;
		return $user_id > 0 && self::notes_ready() && TBT_Notes_Capabilities::user_can_manage( $user_id );
	}

	/**
	 * Administrator: sees every class and class 0.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function is_admin( $user_id ) {
		$user_id = (int) $user_id;
		return $user_id > 0 && self::notes_ready()
			&& TBT_Notes_Capabilities::user_can_manage( $user_id )
			&& TBT_Notes_Capabilities::user_can_manage_all( $user_id );
	}

	/**
	 * The classes this user may see as a teacher: every class for an
	 * administrator, their own for a teacher, none for anyone else.
	 *
	 * @param int $user_id User ID.
	 * @return array[] Notes class rows (id, title, teacher_id, ...).
	 */
	private static function classes( $user_id ) {
		$user_id = (int) $user_id;
		if ( isset( self::$classes[ $user_id ] ) ) {
			return self::$classes[ $user_id ];
		}
		$classes = array();
		if ( self::is_admin( $user_id ) ) {
			$classes = TBT_Notes_DB::get_all_classes();
		} elseif ( self::is_teacher( $user_id ) ) {
			$classes = TBT_Notes_DB::get_classes_for_teacher( $user_id );
		}
		self::$classes[ $user_id ] = is_array( $classes ) ? $classes : array();
		return self::$classes[ $user_id ];
	}

	/**
	 * The teacher's class IDs; null for an administrator, meaning "no class
	 * filter"; an empty array for anyone else.
	 *
	 * @param int $user_id User ID.
	 * @return int[]|null
	 */
	public static function visible_class_ids( $user_id ) {
		if ( self::is_admin( $user_id ) ) {
			return null;
		}
		return array_map(
			static function ( $c ) {
				return (int) $c['id'];
			},
			self::classes( $user_id )
		);
	}

	/**
	 * [id => title] for the class filter, A–Z. An administrator also gets
	 * 0 => "No class", last.
	 *
	 * @param int $user_id User ID.
	 * @return array<int,string>
	 */
	public static function class_titles( $user_id ) {
		$out = array();
		foreach ( self::classes( $user_id ) as $class ) {
			$out[ (int) $class['id'] ] = (string) $class['title'];
		}
		asort( $out, SORT_NATURAL | SORT_FLAG_CASE );
		if ( self::is_admin( $user_id ) ) {
			$out[0] = __( 'No class', 'tbt-results' );
		}
		return $out;
	}

	/**
	 * The class IDs a query is limited to for a teacher or administrator:
	 * the teacher's classes; for an administrator every class plus 0. The
	 * administrator's list is the visible set written out, so the query can
	 * use the class_created index rather than scan the table.
	 *
	 * @param int $user_id User ID.
	 * @return int[]
	 */
	public static function scope_class_ids( $user_id ) {
		return array_map( 'intval', array_keys( self::class_titles( $user_id ) ) );
	}

	/**
	 * The access scope for a query.
	 *
	 *   array( 'class_ids' => int[] ) for a teacher or administrator view;
	 *   array( 'user_id' => int )     for a student's own view;
	 *   null                          when the user may see nothing.
	 *
	 * @param int    $user_id User ID.
	 * @param string $as      'teacher' or 'student'.
	 * @return array|null
	 */
	public static function scope( $user_id, $as ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 || ! self::notes_ready() ) {
			return null;
		}
		if ( 'student' === $as ) {
			return array( 'user_id' => $user_id );
		}
		if ( ! self::is_teacher( $user_id ) ) {
			return null;
		}
		$ids = self::scope_class_ids( $user_id );
		return $ids ? array( 'class_ids' => $ids ) : null;
	}

	/**
	 * IDs of the students currently in some classes. Class 0 holds nobody.
	 *
	 * @param int[] $class_ids Class IDs.
	 * @return int[]
	 */
	public static function students_in( array $class_ids ) {
		$class_ids = array_values( array_filter( array_map( 'intval', $class_ids ) ) );
		if ( ! $class_ids || ! self::notes_ready() ) {
			return array();
		}
		$ids = TBT_Notes_DB::get_student_ids_for_classes( $class_ids );
		return array_values( array_unique( array_map( 'intval', is_array( $ids ) ? $ids : array() ) ) );
	}

	/**
	 * The class a student is in now, when this viewer can see it; else 0.
	 *
	 * @param int $viewer_id  Teacher user ID.
	 * @param int $student_id Student user ID.
	 * @return int
	 */
	public static function current_visible_class( $viewer_id, $student_id ) {
		if ( ! self::notes_ready() ) {
			return 0;
		}
		$class_id = (int) TBT_Notes_DB::get_class_id_of_student( (int) $student_id );
		if ( $class_id <= 0 ) {
			return 0;
		}
		return array_key_exists( $class_id, self::class_titles( $viewer_id ) ) ? $class_id : 0;
	}
}
