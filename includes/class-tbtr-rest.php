<?php
/**
 * REST routes, namespace tbt-results/v1. All GET, all read-only.
 *
 *   /teacher                     teacher  every visible row
 *   /teacher/student/{id}        teacher  one student, within the visible rows
 *   /teacher/activity?tool&ref   teacher  one activity, within the visible rows
 *   /me                          student  their own rows
 *
 * Every permission_callback checks Notes, login and role. The data itself is
 * limited by TBTR_Access::scope() inside each query, so a route can never
 * return a row outside what the caller may see, whatever the parameters.
 */

defined( 'ABSPATH' ) || exit;

class TBTR_Rest {

	const NS = 'tbt-results/v1';

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	public function routes() {
		register_rest_route(
			self::NS,
			'/teacher',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'teacher' ),
				'permission_callback' => array( $this, 'can_teacher' ),
			)
		);
		register_rest_route(
			self::NS,
			'/teacher/student/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'student' ),
				'permission_callback' => array( $this, 'can_teacher' ),
			)
		);
		register_rest_route(
			self::NS,
			'/teacher/activity',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'activity' ),
				'permission_callback' => array( $this, 'can_teacher' ),
			)
		);
		register_rest_route(
			self::NS,
			'/me',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'me' ),
				'permission_callback' => array( $this, 'can_me' ),
			)
		);
	}

	/* ---------- Permissions ---------- */

	/**
	 * @return true|WP_Error
	 */
	public function can_teacher() {
		$base = $this->can_me();
		if ( true !== $base ) {
			return $base;
		}
		if ( ! TBTR_Access::is_teacher( get_current_user_id() ) ) {
			return new WP_Error( 'tbtr_forbidden', __( 'This page is for teachers.', 'tbt-results' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * @return true|WP_Error
	 */
	public function can_me() {
		if ( ! TBTR_Access::notes_ready() ) {
			return new WP_Error( 'tbtr_notes_missing', __( 'Results need TBT Notes 1.22.0 or later.', 'tbt-results' ), array( 'status' => 503 ) );
		}
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'tbtr_logged_out', __( 'Log in to see results.', 'tbt-results' ), array( 'status' => 401 ) );
		}
		return true;
	}

	/* ---------- Routes ---------- */

	/**
	 * All results a teacher may see.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function teacher( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$titles  = TBTR_Access::class_titles( $user_id );
		$scope   = TBTR_Access::scope( $user_id, 'teacher' );

		if ( null === $scope ) {
			return self::respond(
				array(
					'no_classes' => true,
					'stats'      => array( 'finished' => 0, 'active' => 0, 'of' => 0, 'average' => null ),
					'rows'       => array(),
					'has_more'   => false,
					'total'      => 0,
					'classes'    => array(),
					'tools'      => array(),
				)
			);
		}

		$tz  = wp_timezone();
		$now = time();

		// The tiles are always the last 7 days, whatever the filters say.
		$week = TBTR_Query::summary( $scope, array( 'since' => TBTR_Format::days_back( 7, $tz, $now ) ) );

		$class   = self::class_param( $request->get_param( 'class' ), $titles );
		$filters = array(
			'search'       => TBTR_Format::search( $request->get_param( 'search' ) ),
			'search_names' => true,
			'tool'         => TBTR_Format::tool( $request->get_param( 'tool' ) ),
			'class'        => $class,
			'since'        => TBTR_Format::period_start( $request->get_param( 'period' ), $tz, $now ),
		);
		$page = TBTR_Query::page( $scope, $filters, self::page_param( $request ) );

		$classes = array();
		foreach ( $titles as $id => $title ) {
			$classes[] = array(
				'id'    => (int) $id,
				'title' => (string) $title,
			);
		}

		return self::respond(
			array(
				'stats'    => array(
					'finished' => $week['finished'],
					'active'   => $week['students'],
					// Class 0 holds nobody, so an administrator's "of" is every
					// student in every class.
					'of'       => count( TBTR_Access::students_in( $scope['class_ids'] ) ),
					'average'  => $week['average'],
				),
				'rows'     => TBTR_Query::shape( $scope, $page['rows'], $titles ),
				'has_more' => $page['has_more'],
				'total'    => $page['total'],
				'classes'  => $classes,
				'tools'    => TBTR_Query::tools( $scope ),
			)
		);
	}

	/**
	 * One student, within the teacher's visible rows.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function student( WP_REST_Request $request ) {
		$user_id    = get_current_user_id();
		$student_id = absint( $request->get_param( 'id' ) );
		$titles     = TBTR_Access::class_titles( $user_id );
		$scope      = TBTR_Access::scope( $user_id, 'teacher' );
		$not_yours  = new WP_Error( 'tbtr_not_yours', __( 'This student is not in your classes.', 'tbt-results' ), array( 'status' => 404 ) );

		if ( null === $scope || $student_id <= 0 ) {
			return $not_yours;
		}

		$tz      = wp_timezone();
		$now     = time();
		$mine    = array( 'user_id' => $student_id );
		$all     = TBTR_Query::summary( $scope, $mine );
		$current = TBTR_Access::current_visible_class( $user_id, $student_id );

		// No visible rows and not in a visible class now: not this teacher's
		// student. A student in their class with no rows yet is an empty page.
		if ( 0 === $all['finished'] && 0 === $current ) {
			return $not_yours;
		}

		$newest = TBTR_Query::newest( $scope, $mine );
		$names  = TBTR_Query::user_names( array( $student_id ) );
		$name   = $names[ $student_id ] ?? '';
		if ( '' === $name && $newest ) {
			$name = (string) $newest['student_name'];
		}
		$class_id = $current ? $current : ( $newest ? (int) $newest['class_id'] : 0 );

		$last = array(
			'when'      => $newest ? TBTR_Format::iso( $newest['created_at'], $tz ) : '',
			'when_text' => $newest ? TBTR_Format::when_text( $newest['created_at'], $tz, $now ) : '',
		);

		$filters = $mine + array(
			'tool'  => TBTR_Format::tool( $request->get_param( 'tool' ) ),
			'since' => TBTR_Format::period_start( $request->get_param( 'period' ), $tz, $now ),
		);
		$page = TBTR_Query::page( $scope, $filters, self::page_param( $request ) );

		return self::respond(
			array(
				'student'  => array(
					'id'          => $student_id,
					'name'        => $name,
					'class_title' => ( $current || $newest ) ? TBTR_Query::class_title( $class_id, $titles ) : '',
					'last_active' => $last,
				),
				'stats'    => array(
					'finished'       => $all['finished'],
					'average'        => $all['average'],
					'active_days_30' => TBTR_Query::active_days( $scope, $mine + array( 'since' => TBTR_Format::days_back( 30, $tz, $now ) ), $tz ),
					'last_active'    => $last,
				),
				'by_tool'  => TBTR_Query::by_tool( $scope, $mine ),
				'rows'     => TBTR_Query::shape( $scope, $page['rows'], $titles ),
				'has_more' => $page['has_more'],
				'total'    => $page['total'],
				'tools'    => TBTR_Query::tools( $scope, $mine ),
			)
		);
	}

	/**
	 * One activity (tool + ref), within the teacher's visible rows.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function activity( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$tool    = TBTR_Format::tool( $request->get_param( 'tool' ) );
		$ref     = substr( sanitize_text_field( (string) $request->get_param( 'ref' ) ), 0, 64 );
		$titles  = TBTR_Access::class_titles( $user_id );
		$scope   = TBTR_Access::scope( $user_id, 'teacher' );
		$missing = new WP_Error( 'tbtr_no_activity', __( 'This activity is not in your classes.', 'tbt-results' ), array( 'status' => 404 ) );

		if ( null === $scope || '' === $tool || '' === $ref ) {
			return $missing;
		}

		$these = array(
			'tool' => $tool,
			'ref'  => $ref,
		);
		$all = TBTR_Query::summary( $scope, $these );
		if ( 0 === $all['finished'] ) {
			return $missing;
		}

		$newest    = TBTR_Query::newest( $scope, $these );
		$class_ids = TBTR_Query::distinct( $scope, $these, 'class_id' );
		$done_ids  = TBTR_Query::distinct( $scope, $these, 'user_id' );

		$class_titles = array();
		foreach ( $class_ids as $id ) {
			$class_titles[] = TBTR_Query::class_title( $id, $titles );
		}
		natcasesort( $class_titles );

		// Students in those classes now (class 0 holds nobody) who have no
		// row for this activity. The class IDs came from scoped rows, so
		// they are already within the visible set.
		$members  = TBTR_Access::students_in( $class_ids );
		$not_done = array_values( TBTR_Query::user_names( array_diff( $members, $done_ids ) ) );
		natcasesort( $not_done );
		$not_done = array_values( $not_done );

		$page = TBTR_Query::page( $scope, $these, self::page_param( $request ) );

		return self::respond(
			array(
				'activity' => array(
					'tool'         => $tool,
					'tool_label'   => TBTR_Format::tool_label( $tool ),
					'ref'          => $ref,
					'title'        => (string) $newest['object_title'],
					'class_titles' => array_values( $class_titles ),
					'score_max'    => TBTR_Query::newest_score_max( $scope, $these ),
				),
				'stats'    => array(
					'done'    => $all['students'],
					// Who did it plus who has not: a student who did it and
					// then left the class still counts once, never "5 of 4".
					'of'      => $all['students'] + count( $not_done ),
					'average' => $all['average'],
					'best'    => $all['best'],
				),
				'not_done' => $not_done,
				'rows'     => TBTR_Query::shape( $scope, $page['rows'], $titles ),
				'has_more' => $page['has_more'],
				'total'    => $page['total'],
			)
		);
	}

	/**
	 * A student's own results.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function me( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$scope   = TBTR_Access::scope( $user_id, 'student' );
		$tz      = wp_timezone();
		$now     = time();

		$month_filter = array( 'since' => TBTR_Format::days_back( 30, $tz, $now ) );
		$month        = TBTR_Query::summary( $scope, $month_filter );

		$filters = array(
			'search' => TBTR_Format::search( $request->get_param( 'search' ) ),
			'tool'   => TBTR_Format::tool( $request->get_param( 'tool' ) ),
			'since'  => TBTR_Format::period_start( $request->get_param( 'period' ), $tz, $now ),
		);
		$page = TBTR_Query::page( $scope, $filters, self::page_param( $request ) );

		// No class titles and no names: shape() fills them, so drop them here.
		$rows = array();
		foreach ( TBTR_Query::shape( $scope, $page['rows'], array() ) as $row ) {
			unset( $row['student'], $row['class_id'], $row['class_title'] );
			$rows[] = $row;
		}

		return self::respond(
			array(
				'stats'    => array(
					'finished'    => $month['finished'],
					'average'     => $month['average'],
					'active_days' => TBTR_Query::active_days( $scope, $month_filter, $tz ),
				),
				'rows'     => $rows,
				'has_more' => $page['has_more'],
				'total'    => $page['total'],
				'tools'    => TBTR_Query::tools( $scope ),
			)
		);
	}

	/* ---------- Helpers ---------- */

	/**
	 * The class filter: a class ID in the visible set, else null (ignored).
	 * 0 is accepted only when it is in the set, i.e. for an administrator.
	 *
	 * @param mixed $value  Raw value.
	 * @param array $titles Visible classes, id => title.
	 * @return int|null
	 */
	private static function class_param( $value, array $titles ) {
		if ( null === $value || '' === $value || ! is_scalar( $value ) || ! ctype_digit( (string) $value ) ) {
			return null;
		}
		$id = (int) $value;
		return array_key_exists( $id, $titles ) ? $id : null;
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return int Page, from 1.
	 */
	private static function page_param( WP_REST_Request $request ) {
		return min( 10000, max( 1, absint( $request->get_param( 'page' ) ) ) );
	}

	/**
	 * A response that no cache keeps.
	 *
	 * @param array $data Body.
	 * @return WP_REST_Response
	 */
	private static function respond( array $data ) {
		$response = new WP_REST_Response( $data, 200 );
		foreach ( wp_get_nocache_headers() as $name => $value ) {
			if ( $value ) {
				$response->header( $name, $value );
			}
		}
		$response->header( 'X-LiteSpeed-Cache-Control', 'no-cache' );
		return $response;
	}
}
