/**
 * TBT Results — teacher page [tbt_results] and student page [tbt_my_results].
 *
 * The view and its filters live in the URL, so Back and a refresh keep them:
 *
 *   (none)                         all results (teacher) / my results (student)
 *   tbtr_student={id}              one student (teacher)
 *   tbtr_tool={slug}&tbtr_ref={r}  one activity (teacher)
 *
 *   tbtr_search, tbtr_filter_tool, tbtr_class, tbtr_period   list filters
 *   tbtr_s_tool, tbtr_s_period                               one student's filters
 *
 * Opening a student or an activity pushes a history entry and keeps the list
 * filters in the URL; filter and search changes replace the current entry.
 * All data comes from tbt-results/v1; every visibility decision is made on
 * the server. Plain JS, no build step, no dependencies.
 */
( function () {
	'use strict';

	var CFG = window.TBTR_RESULTS || {};
	var root = document.querySelector( '[data-tbtr-app]' );
	if ( ! root || ! CFG.rest ) {
		return;
	}

	var MODE = CFG.mode === 'teacher' ? 'teacher' : 'student';
	var PERIODS = [ [ '7', 'Last 7 days' ], [ '30', 'Last 30 days' ], [ 'all', 'All time' ] ];
	var P = {
		student: 'tbtr_student',
		tool: 'tbtr_tool',
		ref: 'tbtr_ref',
		search: 'tbtr_search',
		ftool: 'tbtr_filter_tool',
		cls: 'tbtr_class',
		period: 'tbtr_period',
		stool: 'tbtr_s_tool',
		speriod: 'tbtr_s_period',
	};

	/* ---------- Helpers ---------- */

	function esc( value ) {
		return String( value == null ? '' : value ).replace( /[&<>"']/g, function ( c ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ];
		} );
	}

	function plural( n, one, many ) {
		return n + ' ' + ( n === 1 ? one : many );
	}

	function pct( value ) {
		return value == null ? '–' : value + '%';
	}

	function period( value ) {
		return value === '7' || value === 'all' ? value : '30';
	}

	/* ---------- URL state ---------- */

	function readState() {
		var q = new URLSearchParams( window.location.search );
		var st = {
			view: 'all',
			student: 0,
			tool: '',
			ref: '',
			search: q.get( P.search ) || '',
			ftool: q.get( P.ftool ) || '',
			cls: q.get( P.cls ) || '',
			period: period( q.get( P.period ) ),
			stool: q.get( P.stool ) || '',
			speriod: period( q.get( P.speriod ) ),
		};
		if ( MODE === 'teacher' ) {
			var sid = parseInt( q.get( P.student ) || '', 10 );
			if ( sid > 0 ) {
				st.view = 'student';
				st.student = sid;
			} else if ( q.get( P.tool ) && q.get( P.ref ) ) {
				st.view = 'activity';
				st.tool = q.get( P.tool );
				st.ref = q.get( P.ref );
			}
		}
		return st;
	}

	function urlFor( st ) {
		var q = new URLSearchParams( window.location.search );
		Object.keys( P ).forEach( function ( k ) {
			q.delete( P[ k ] );
		} );
		if ( st.view === 'student' ) {
			q.set( P.student, String( st.student ) );
			if ( st.stool ) {
				q.set( P.stool, st.stool );
			}
			if ( st.speriod !== '30' ) {
				q.set( P.speriod, st.speriod );
			}
		} else if ( st.view === 'activity' ) {
			q.set( P.tool, st.tool );
			q.set( P.ref, st.ref );
		}
		// The list filters ride along in every view, so going back to the
		// list finds them where they were.
		if ( st.search ) {
			q.set( P.search, st.search );
		}
		if ( st.ftool ) {
			q.set( P.ftool, st.ftool );
		}
		if ( st.cls !== '' ) {
			q.set( P.cls, st.cls );
		}
		if ( st.period !== '30' ) {
			q.set( P.period, st.period );
		}
		var s = q.toString();
		return window.location.pathname + ( s ? '?' + s : '' ) + window.location.hash;
	}

	function go( st, push ) {
		var url = urlFor( st );
		if ( push ) {
			window.history.pushState( null, '', url );
		} else {
			window.history.replaceState( null, '', url );
		}
	}

	/* ---------- REST ---------- */

	var noncePromise = null;

	function refreshNonce() {
		if ( ! noncePromise ) {
			noncePromise = fetch( CFG.ajax + ( CFG.ajax.indexOf( '?' ) < 0 ? '?' : '&' ) + 'action=tbtr_nonce', {
				credentials: 'same-origin',
				cache: 'no-store',
			} ).then( function ( res ) {
				return res.json();
			} ).then( function ( data ) {
				if ( ! data || ! data.success ) {
					throw new Error( 'nonce' );
				}
				CFG.nonce = data.data.nonce;
			} ).finally( function () {
				noncePromise = null;
			} );
		}
		return noncePromise;
	}

	/**
	 * GET a route. Rejects with { code, message, status }. An expired nonce
	 * is refreshed and the call retried once.
	 */
	function api( path, params, retried ) {
		var q = new URLSearchParams();
		Object.keys( params || {} ).forEach( function ( k ) {
			if ( params[ k ] !== '' && params[ k ] != null ) {
				q.set( k, params[ k ] );
			}
		} );
		var s = q.toString();
		var url = CFG.rest + path + ( s ? ( CFG.rest.indexOf( '?' ) < 0 ? '?' : '&' ) + s : '' );
		return fetch( url, {
			credentials: 'same-origin',
			cache: 'no-store',
			headers: { 'X-WP-Nonce': CFG.nonce || '' },
		} ).then( function ( res ) {
			return res.json().catch( function () {
				return {};
			} ).then( function ( data ) {
				if ( res.ok ) {
					return data;
				}
				if ( data && data.code === 'rest_cookie_invalid_nonce' && ! retried ) {
					return refreshNonce().then( function () {
						return api( path, params, true );
					} );
				}
				throw { code: ( data && data.code ) || 'http_' + res.status, message: ( data && data.message ) || '', status: res.status };
			} );
		}, function () {
			throw { code: 'network', message: '', status: 0 };
		} );
	}

	/* ---------- Markup pieces ---------- */

	var SEARCH_ICON = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" focusable="false"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>';

	function adminBar( title, placeholder, value ) {
		return '<div class="tbtr-abar">' +
			'<h2 class="tbtr-abar__title">' + esc( title ) + '</h2>' +
			'<span class="tbtr-abar__line" aria-hidden="true"></span>' +
			'<div class="tbtr-search">' + SEARCH_ICON +
				'<label class="tbtr-sr" for="tbtr-q">' + esc( placeholder ) + '</label>' +
				'<input id="tbtr-q" type="search" placeholder="' + esc( placeholder ) + '" value="' + esc( value ) + '" autocomplete="off" maxlength="100">' +
				'<button type="button" class="tbtr-search__clear" data-tbtr="clear" aria-label="Clear the search"' + ( value ? '' : ' hidden' ) + '>×</button>' +
			'</div>' +
		'</div>';
	}

	function stat( value, label ) {
		return '<div class="tbtr-stat"><div class="tbtr-stat__v">' + esc( value ) + '</div><div class="tbtr-stat__k">' + esc( label ) + '</div></div>';
	}

	function select( id, label ) {
		return '<span class="tbtr-select"><label class="tbtr-sr" for="' + id + '">' + esc( label ) + '</label><select id="' + id + '"></select></span>';
	}

	/** Fill a select in place, so it keeps focus while the list reloads. */
	function setOptions( el, options, value ) {
		if ( ! el ) {
			return;
		}
		var html = options.map( function ( o ) {
			return '<option value="' + esc( o[ 0 ] ) + '">' + esc( o[ 1 ] ) + '</option>';
		} ).join( '' );
		if ( el.getAttribute( 'data-html' ) !== html ) {
			el.innerHTML = html;
			el.setAttribute( 'data-html', html );
		}
		el.value = value;
		if ( el.value !== value && options.length ) {
			el.value = options[ 0 ][ 0 ];
		}
	}

	function toolOptions( tools, current ) {
		var opts = [ [ '', 'All tools' ] ];
		var seen = false;
		( tools || [] ).forEach( function ( t ) {
			opts.push( [ t.slug, t.label ] );
			if ( t.slug === current ) {
				seen = true;
			}
		} );
		if ( current && ! seen ) {
			opts.push( [ current, current.charAt( 0 ).toUpperCase() + current.slice( 1 ) ] );
		}
		return opts;
	}

	function resultCell( r ) {
		if ( r.score == null ) {
			return '<span class="tbtr-pill tbtr-pill--done">✓ Done</span>';
		}
		if ( r.percent == null ) {
			return '<span class="tbtr-res">' + esc( r.score ) + ( r.score_max != null ? ' / ' + esc( r.score_max ) : '' ) + '</span>';
		}
		var w = Math.max( 0, Math.min( 100, r.percent ) );
		return '<span class="tbtr-res">' + esc( r.score ) + ' / ' + esc( r.score_max ) + ' · ' + esc( r.percent ) + '%' +
			'<span class="tbtr-meter" aria-hidden="true"><i style="width:' + w + '%"></i></span></span>';
	}

	function whenCell( r ) {
		return '<td class="tbtr-when"><time datetime="' + esc( r.when ) + '">' + esc( r.when_text ) + '</time></td>';
	}

	function studentButton( r ) {
		return '<button type="button" class="tbtr-lnk" data-tbtr="student" data-id="' + esc( r.user_id ) + '">' + esc( r.student || '—' ) + '</button>';
	}

	function activityButton( r ) {
		return '<button type="button" class="tbtr-lnk" data-tbtr="activity" data-tool="' + esc( r.tool ) + '" data-ref="' + esc( r.ref ) + '">' + esc( r.title || r.ref ) + '</button>';
	}

	function toolPill( r ) {
		return ' <span class="tbtr-tool">' + esc( r.tool_label ) + '</span>';
	}

	/** Table columns per view. */
	var COLUMNS = {
		all: [ 'Student', 'Activity', 'Class', 'When', 'Result' ],
		student: [ 'Activity', 'When', 'Result' ],
		activity: [ 'Student', 'When', 'Result' ],
		mine: [ 'Activity', 'When', 'Result' ],
	};

	function rowHtml( kind, r ) {
		var cells = '';
		if ( kind === 'all' ) {
			cells = '<td class="tbtr-who">' + studentButton( r ) + '</td>' +
				'<td class="tbtr-act">' + activityButton( r ) + toolPill( r ) + '</td>' +
				'<td class="tbtr-cls">' + esc( r.class_title ) + '</td>';
		} else if ( kind === 'student' ) {
			cells = '<td class="tbtr-act">' + activityButton( r ) + toolPill( r ) + '</td>';
		} else if ( kind === 'activity' ) {
			cells = '<td class="tbtr-who">' + studentButton( r ) + '</td>';
		} else {
			cells = '<td class="tbtr-act">' + esc( r.title || r.ref ) + toolPill( r ) + '</td>';
		}
		return '<tr>' + cells + whenCell( r ) + '<td>' + resultCell( r ) + '</td></tr>';
	}

	function tableHtml( kind, rows ) {
		return '<div class="tbtr-table-wrap"><table class="tbtr-table"><thead><tr>' +
			COLUMNS[ kind ].map( function ( h ) {
				return '<th scope="col">' + esc( h ) + '</th>';
			} ).join( '' ) +
			'</tr></thead><tbody>' + rows.map( function ( r ) {
				return rowHtml( kind, r );
			} ).join( '' ) + '</tbody></table></div>';
	}

	/* ---------- View state ---------- */

	var st = readState();
	var seq = 0;        // the newest request; older answers are dropped
	var data = null;    // the last good answer for this view
	var rows = [];      // rows shown, across "Show more" pages
	var pageNo = 1;
	var searchTimer = null;

	function $( sel ) {
		return root.querySelector( sel );
	}

	function setHtml( sel, html ) {
		var el = $( sel );
		if ( el ) {
			el.innerHTML = html;
		}
	}

	function kind() {
		return MODE === 'student' ? 'mine' : st.view;
	}

	/** The shell of a view: drawn once, then its slots are filled. */
	function shell() {
		var k = kind();
		var html = '';
		if ( k === 'all' ) {
			html = adminBar( 'Results', 'Search a student or an activity…', st.search ) +
				'<div class="tbtr-error" data-slot="error" hidden></div>' +
				'<div class="tbtr-stats" data-slot="stats"></div>' +
				'<section class="tbtr-stage">' +
					'<div class="tbtr-filters">' + select( 'tbtr-ftool', 'Tool' ) + select( 'tbtr-fclass', 'Class' ) + select( 'tbtr-fperiod', 'Period' ) +
						'<span class="tbtr-spacer"></span><span class="tbtr-summary" data-slot="count" role="status"></span></div>' +
					'<div data-slot="table"></div><div data-slot="more"></div>' +
				'</section>';
		} else if ( k === 'mine' ) {
			html = adminBar( 'My results', 'Search your activities…', st.search ) +
				'<div class="tbtr-error" data-slot="error" hidden></div>' +
				'<div class="tbtr-stats" data-slot="stats"></div>' +
				'<section class="tbtr-stage">' +
					'<div class="tbtr-filters">' + select( 'tbtr-ftool', 'Tool' ) + select( 'tbtr-fperiod', 'Period' ) +
						'<span class="tbtr-spacer"></span><span class="tbtr-summary" data-slot="count" role="status"></span></div>' +
					'<div data-slot="table"></div><div data-slot="more"></div>' +
				'</section>';
		} else if ( k === 'student' ) {
			html = '<button type="button" class="tbtr-back" data-tbtr="home">← All results</button>' +
				'<div class="tbtr-error" data-slot="error" hidden></div>' +
				'<div data-slot="body"><p class="tbtr-loading" role="status">Loading…</p></div>';
		} else {
			html = '<button type="button" class="tbtr-back" data-tbtr="home">← All results</button>' +
				'<div class="tbtr-error" data-slot="error" hidden></div>' +
				'<div data-slot="body"><p class="tbtr-loading" role="status">Loading…</p></div>';
		}
		root.innerHTML = html;
	}

	/** Start a view from the URL: new shell, first page. */
	function start() {
		st = readState();
		data = null;
		rows = [];
		pageNo = 1;
		shell();
		load( false );
	}

	function request() {
		var k = kind();
		if ( k === 'all' ) {
			return [ 'teacher', { search: st.search, tool: st.ftool, 'class': st.cls, period: st.period } ];
		}
		if ( k === 'mine' ) {
			return [ 'me', { search: st.search, tool: st.ftool, period: st.period } ];
		}
		if ( k === 'student' ) {
			return [ 'teacher/student/' + st.student, { tool: st.stool, period: st.speriod } ];
		}
		return [ 'teacher/activity', { tool: st.tool, ref: st.ref } ];
	}

	function load( append ) {
		var my = ++seq;
		var req = request();
		var params = req[ 1 ];
		params.page = append ? pageNo + 1 : 1;
		root.setAttribute( 'aria-busy', 'true' );
		var moreBtn = $( '[data-tbtr="more"]' );
		if ( moreBtn ) {
			moreBtn.disabled = true;
		}

		api( req[ 0 ], params ).then( function ( res ) {
			if ( my !== seq ) {
				return;
			}
			root.removeAttribute( 'aria-busy' );
			hideError();
			pageNo = params.page;
			rows = append ? rows.concat( res.rows || [] ) : ( res.rows || [] );
			data = res;
			paint( append );
		}, function ( err ) {
			if ( my !== seq ) {
				return;
			}
			root.removeAttribute( 'aria-busy' );
			if ( err.code === 'tbtr_not_yours' || err.code === 'tbtr_no_activity' ) {
				setHtml( '[data-slot="body"]', '<section class="tbtr-stage tbtr-message"><p class="tbtr-muted">' +
					esc( err.code === 'tbtr_not_yours' ? 'This student is not in your classes.' : 'This activity is not in your classes.' ) + '</p></section>' );
				return;
			}
			showError( append );
		} );
	}

	function showError( append ) {
		var el = $( '[data-slot="error"]' );
		if ( ! el ) {
			return;
		}
		el.innerHTML = '<span>Could not load results. Try again.</span> <button type="button" class="tbtr-btn" data-tbtr="retry"' +
			( append ? ' data-append="1"' : '' ) + '>Try again</button>';
		el.hidden = false;
		var moreBtn = $( '[data-tbtr="more"]' );
		if ( moreBtn ) {
			moreBtn.disabled = false;
		}
		// The first load failed: nothing to keep, so drop the loading line.
		if ( ! data ) {
			setHtml( '[data-slot="body"]', '' );
		}
	}

	function hideError() {
		var el = $( '[data-slot="error"]' );
		if ( el ) {
			el.hidden = true;
			el.innerHTML = '';
		}
	}

	/* ---------- Painting ---------- */

	function paint( append ) {
		var k = kind();
		if ( k === 'all' ) {
			paintAll();
		} else if ( k === 'mine' ) {
			paintMine();
		} else if ( k === 'student' ) {
			paintStudent( append );
		} else {
			paintActivity( append );
		}
	}

	function paintList( k, emptyText ) {
		var count = $( '[data-slot="count"]' );
		if ( count ) {
			count.textContent = k === 'all' || k === 'mine' ? plural( data.total || 0, 'result', 'results' ) : ( data.total || 0 ) + ' shown';
		}
		setHtml( '[data-slot="table"]', rows.length ? tableHtml( k, rows ) : '<p class="tbtr-empty">' + esc( emptyText ) + '</p>' );
		setHtml( '[data-slot="more"]', data.has_more ? '<div class="tbtr-more"><button type="button" class="tbtr-btn" data-tbtr="more">Show more</button></div>' : '' );
	}

	function paintAll() {
		var s = data.stats || {};
		setHtml( '[data-slot="stats"]',
			stat( s.finished || 0, 'activities finished this week' ) +
			stat( ( s.active || 0 ) + ' of ' + ( s.of || 0 ), 'students active this week' ) +
			stat( pct( s.average ), 'average score this week' ) );

		setOptions( $( '#tbtr-ftool' ), toolOptions( data.tools, st.ftool ), st.ftool );
		var classOpts = [ [ '', 'All classes' ] ].concat( ( data.classes || [] ).map( function ( c ) {
			return [ String( c.id ), c.title ];
		} ) );
		setOptions( $( '#tbtr-fclass' ), classOpts, st.cls );
		setOptions( $( '#tbtr-fperiod' ), PERIODS, st.period );

		paintList( 'all', 'Nothing matches. Try another name, tool or period.' );
	}

	function paintMine() {
		var s = data.stats || {};
		setHtml( '[data-slot="stats"]',
			stat( s.finished || 0, 'activities finished in the last 30 days' ) +
			stat( pct( s.average ), 'your average score' ) +
			stat( s.active_days || 0, 'days you practised' ) );

		setOptions( $( '#tbtr-ftool' ), toolOptions( data.tools, st.ftool ), st.ftool );
		setOptions( $( '#tbtr-fperiod' ), PERIODS, st.period );

		var filtered = st.search || st.ftool;
		paintList( 'mine', filtered ? 'Nothing matches. Try another activity, tool or period.' : 'Nothing here yet. Finish a test, a deck or a drill and it appears here.' );
	}

	function paintStudent( append ) {
		if ( ! append && ! $( '[data-slot="table"]' ) ) {
			var stu = data.student || {};
			var s = data.stats || {};
			var last = stu.last_active && stu.last_active.when_text ? stu.last_active.when_text : 'never';
			var meta = ( stu.class_title ? stu.class_title + ' · ' : '' ) + 'last active ' + last;
			var byTool = ( data.by_tool || [] ).map( function ( t ) {
				return '<div class="tbtr-bt"><b>' + esc( t.tool_label ) + '</b><span>' + esc( t.count + ' done' +
					( t.average != null ? ' · average ' + t.average + '%' : '' ) ) + '</span></div>';
			} ).join( '' );

			setHtml( '[data-slot="body"]',
				'<div class="tbtr-rhead"><h2>' + esc( stu.name || '—' ) + '</h2><p class="tbtr-summary">' + esc( meta ) + '</p></div>' +
				'<div class="tbtr-stats">' +
					stat( s.finished || 0, 'activities finished' ) +
					stat( pct( s.average ), 'average score' ) +
					stat( s.active_days_30 || 0, 'active days in the last 30' ) +
				'</div>' +
				( byTool ? '<section class="tbtr-stage"><h2 class="tbtr-stage__title">By tool</h2><div class="tbtr-bytool">' + byTool + '</div></section>' : '' ) +
				'<section class="tbtr-stage">' +
					'<div class="tbtr-filters"><h2 class="tbtr-stage__title">Activities</h2>' + select( 'tbtr-ftool', 'Tool' ) + select( 'tbtr-fperiod', 'Period' ) +
						'<span class="tbtr-spacer"></span><span class="tbtr-summary" data-slot="count" role="status"></span></div>' +
					'<div data-slot="table"></div><div data-slot="more"></div>' +
				'</section>' );
		}
		setOptions( $( '#tbtr-ftool' ), toolOptions( data.tools, st.stool ), st.stool );
		setOptions( $( '#tbtr-fperiod' ), PERIODS, st.speriod );
		paintList( 'student', 'Nothing in this period.' );
	}

	function paintActivity( append ) {
		if ( ! append ) {
			var a = data.activity || {};
			var s = data.stats || {};
			var scored = a.score_max != null;
			var meta = [ a.tool_label ].concat( a.class_titles || [] ).join( ' · ' ) +
				( scored ? ' · out of ' + a.score_max : ' · no score, done or not done' );
			var notDone = data.not_done || [];

			setHtml( '[data-slot="body"]',
				'<div class="tbtr-rhead"><h2>' + esc( a.title || a.ref ) + '</h2><p class="tbtr-summary">' + esc( meta ) + '</p></div>' +
				'<div class="tbtr-stats">' +
					stat( ( s.done || 0 ) + ' of ' + ( s.of || 0 ), 'students in the class have done it' ) +
					( scored ? stat( pct( s.average ), 'average score' ) + stat( pct( s.best ), 'best score' ) : '' ) +
				'</div>' +
				( notDone.length ? '<section class="tbtr-stage"><h2 class="tbtr-stage__title">Not done yet</h2><ul class="tbtr-notyet">' +
					notDone.map( function ( n ) {
						return '<li>' + esc( n ) + '</li>';
					} ).join( '' ) + '</ul></section>' : '' ) +
				'<section class="tbtr-stage"><h2 class="tbtr-stage__title">Who did it</h2>' +
					'<div data-slot="table"></div><div data-slot="more"></div>' +
				'</section>' );
		}
		setHtml( '[data-slot="table"]', tableHtml( 'activity', rows ) );
		setHtml( '[data-slot="more"]', data.has_more ? '<div class="tbtr-more"><button type="button" class="tbtr-btn" data-tbtr="more">Show more</button></div>' : '' );
	}

	/* ---------- Events ---------- */

	function reload() {
		pageNo = 1;
		load( false );
	}

	root.addEventListener( 'click', function ( e ) {
		var b = e.target.closest( '[data-tbtr]' );
		if ( ! b || ! root.contains( b ) ) {
			return;
		}
		var act = b.getAttribute( 'data-tbtr' );

		if ( act === 'student' ) {
			st.view = 'student';
			st.student = parseInt( b.getAttribute( 'data-id' ), 10 ) || 0;
			st.stool = '';
			st.speriod = '30';
			go( st, true );
			start();
			window.scrollTo( 0, root.getBoundingClientRect().top + window.pageYOffset - 24 );
		} else if ( act === 'activity' ) {
			st.view = 'activity';
			st.tool = b.getAttribute( 'data-tool' ) || '';
			st.ref = b.getAttribute( 'data-ref' ) || '';
			go( st, true );
			start();
			window.scrollTo( 0, root.getBoundingClientRect().top + window.pageYOffset - 24 );
		} else if ( act === 'home' ) {
			st.view = 'all';
			go( st, true );
			start();
		} else if ( act === 'more' ) {
			load( true );
		} else if ( act === 'retry' ) {
			if ( b.getAttribute( 'data-append' ) ) {
				load( true );
			} else if ( kind() === 'student' || kind() === 'activity' ) {
				data = null;
				rows = [];
				setHtml( '[data-slot="body"]', '<p class="tbtr-loading" role="status">Loading…</p>' );
				load( false );
			} else {
				reload();
			}
		} else if ( act === 'clear' ) {
			var input = $( '#tbtr-q' );
			if ( input ) {
				input.value = '';
				input.focus();
			}
			setSearch( '' );
		}
	} );

	function setSearch( value ) {
		clearTimeout( searchTimer );
		st.search = value;
		var clear = $( '[data-tbtr="clear"]' );
		if ( clear ) {
			clear.hidden = ! value;
		}
		go( st, false );
		reload();
	}

	root.addEventListener( 'input', function ( e ) {
		if ( e.target.id !== 'tbtr-q' ) {
			return;
		}
		var value = e.target.value.trim().slice( 0, 100 );
		var clear = $( '[data-tbtr="clear"]' );
		if ( clear ) {
			clear.hidden = ! e.target.value;
		}
		clearTimeout( searchTimer );
		searchTimer = setTimeout( function () {
			if ( value !== st.search ) {
				setSearch( value );
			}
		}, 300 );
	} );

	root.addEventListener( 'keydown', function ( e ) {
		if ( e.target.id === 'tbtr-q' && e.key === 'Escape' && e.target.value ) {
			e.target.value = '';
			setSearch( '' );
		}
	} );

	root.addEventListener( 'change', function ( e ) {
		var id = e.target.id;
		var v = e.target.value;
		var student = kind() === 'student';
		if ( id === 'tbtr-ftool' ) {
			if ( student ) {
				st.stool = v;
			} else {
				st.ftool = v;
			}
		} else if ( id === 'tbtr-fclass' ) {
			st.cls = v;
		} else if ( id === 'tbtr-fperiod' ) {
			if ( student ) {
				st.speriod = period( v );
			} else {
				st.period = period( v );
			}
		} else {
			return;
		}
		go( st, false );
		reload();
	} );

	window.addEventListener( 'popstate', start );

	start();
}() );
