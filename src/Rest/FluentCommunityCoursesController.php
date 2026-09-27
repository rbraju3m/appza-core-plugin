<?php
/**
 * Course list — the All Courses and My Courses tabs (Courses doc, states 1–4).
 *
 *   GET /wp-json/fcom-mobile/api/v1/courses/catalog
 *
 * Registered under the fcom-mobile namespace from our plugin, the same
 * call the leaderboard and members-directory controllers make (P68).
 * fcom-mobile and FluentCommunity are third-party and are never edited.
 * Named `/catalog` because fcom-mobile already owns `/courses` and every
 * path under it the app might want.
 *
 * ---------------------------------------------------------------------
 * Why a route of ours (D86)
 * ---------------------------------------------------------------------
 *
 * The card needs three things no single FluentCommunity route answers:
 *
 *   1. the filters — `/courses` has search, topic, sort and "enrolled";
 *      `/courses/all-courses` has none of them;
 *   2. the viewer's progress — `/all-courses` carries `track`, `/courses`
 *      does not;
 *   3. whether the course is PAID — neither does; FC's portal asks
 *      `/cart/spaces/{id}/paywalls` per course.
 *
 * And one thing the doc asks for that FC's `/courses` leaves out: a
 * SECRET course shows to its course admin (state 1; Noor, 2026-09-22 —
 * "doc wins"). FC shows a secret course only to its enrolled students,
 * admins included, while its `/all-courses` does include it for them.
 *
 * So the query below IS FluentCommunity's `CourseController::getCourses`
 * — the same clauses in the same order — with one extra clause for the
 * admin's secret courses. It reads FC's models in-process rather than
 * asking FC over rest_do_request: one question per request (P134), and
 * the paywall and progress are FC's own helpers either way.
 *
 * ---------------------------------------------------------------------
 * The card's button is decided here
 * ---------------------------------------------------------------------
 *
 * Five mutually exclusive states, each a plain boolean because a child's
 * `visible_when` is a truthiness test (P159):
 *
 *   is_paid       paywall on (show_paywalls + a published FluentCart
 *                 product + FluentCart active) and the viewer NOT enrolled
 *   cta_start     enrolled, no lesson completed yet
 *   cta_progress  enrolled, some lessons completed — "N% COMPLETE"
 *   cta_review    enrolled, progress 100
 *   cta_view      anyone else
 *
 * "Enrolled" is FC's own `CourseHelper::isEnrolled` — any membership row,
 * which is what the portal's card reads.
 *
 * Every word on the card comes from the request (the catalog), so a
 * rename is a catalog edit; the numbers and the plurals are ours.
 */

namespace AppzaCore\Plugin\Rest;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class FluentCommunityCoursesController {

	/** FluentCommunity's own sort keys. */
	private const SORTS = array( 'alphabetical', 'latest', 'oldest' );

	private const DEFAULT_PER_PAGE = 20;

	/**
	 * FC swaps a missing cover for this bundled image. The design draws
	 * its own grey cap instead, so the card has to be able to tell.
	 */
	private const FC_PLACEHOLDER = 'assets/images/course-placeholder.jpg';

	/**
	 * Legacy v1 `appza-builder` filters every fcom-mobile course read and
	 * fatals on a refused one, turning FC's 422 into a 500 (P179). Noor's
	 * call, 2026-09-22: switch that one filter off from here; nothing in
	 * appza-builder is edited, and v2 counts sections and lessons itself.
	 */
	private const LEGACY_FILTER = 'fluent_community/course_api_response';
	private const LEGACY_METHOD = 'appza_fcom_mobile_course_api_response';

	/** Public read: FC lists public and private courses to a guest too. */
	public function permission_check() {
		return PortalGuard::reader();
	}

	/**
	 * A refusal parked by shield_refusal() for unshield_refusal() to put
	 * back. A stack, because a course read can nest another.
	 *
	 * @var array<int, mixed>
	 */
	private $parked = array();

	public function register_hooks() {
		$this->unhook_legacy_course_filter();
		add_filter( self::LEGACY_FILTER, array( $this, 'shield_refusal' ), 9, 1 );
		add_filter( self::LEGACY_FILTER, array( $this, 'unshield_refusal' ), 11, 1 );
	}

	/**
	 * fcom-mobile hooks this filter too, at 10, with a copy of the same
	 * callback — and it fatals the same way: when FluentCommunity REFUSES
	 * (a secret course, "You must need to be invited to view this
	 * course"), `$data` is a WP_REST_Response, and `isset($data['…'])` on
	 * it throws. So removing appza-builder's copy alone still left the
	 * 500 (found 2026-09-22). fcom-mobile is third-party and stays
	 * untouched: around it, a refusal is swapped for an empty array at 9
	 * — which every copy returns unchanged — and put back at 11, so FC's
	 * own 422 and its sentence reach the app.
	 */
	public function shield_refusal( $data ) {
		if ( is_array( $data ) ) {
			$this->parked[] = null;
			return $data;
		}
		$this->parked[] = $data;
		return array();
	}

	public function unshield_refusal( $data ) {
		$parked = array_pop( $this->parked );
		return null === $parked ? $data : $parked;
	}

	/**
	 * Removes the legacy callback by METHOD name — appza-builder's loader
	 * registered it against an instance we have no handle on.
	 */
	protected function unhook_legacy_course_filter() {
		global $wp_filter;
		if ( empty( $wp_filter[ self::LEGACY_FILTER ] ) || ! ( $wp_filter[ self::LEGACY_FILTER ] instanceof \WP_Hook ) ) {
			return;
		}
		foreach ( $wp_filter[ self::LEGACY_FILTER ]->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$fn = $callback['function'];
				if ( is_array( $fn ) && isset( $fn[1] ) && self::LEGACY_METHOD === $fn[1] ) {
					remove_filter( self::LEGACY_FILTER, $fn, $priority );
				}
			}
		}
	}

	/* ---------------------------------------------------------------
	 * GET /courses/catalog
	 * ------------------------------------------------------------- */

	public function catalog( \WP_REST_Request $request ) {
		if ( ! class_exists( '\\FluentCommunity\\Modules\\Course\\Model\\Course' ) ) {
			return $this->ok( array() );
		}

		$user        = \FluentCommunity\App\Services\Helper::getCurrentUser();
		$enrolled    = \FluentCommunity\Modules\Course\Services\CourseHelper::getEnrolledCourseIds();
		$is_creator  = $user && $user->hasCourseCreatorAccess();
		$my_courses  = 'enrolled' === sanitize_key( (string) $request->get_param( 'type' ) );
		$search      = trim( sanitize_text_field( (string) $request->get_param( 'search' ) ) );
		$topic_slug  = sanitize_title( (string) $request->get_param( 'topic_slug' ) );
		$sort_by     = sanitize_key( (string) $request->get_param( 'sort_by' ) );
		if ( ! in_array( $sort_by, self::SORTS, true ) ) {
			$sort_by = 'alphabetical';
		}
		$per_page = $this->clamp( (int) $request->get_param( 'per_page' ), self::DEFAULT_PER_PAGE, 1, 100 );
		$page     = max( 1, (int) $request->get_param( 'page' ) );

		// Secret courses this viewer administers — the one clause FC's
		// /courses does not have. Not on My Courses: that tab is
		// enrolments, and the doc says so.
		$admin_secret_ids = $my_courses ? array() : $this->administered_secret_ids( $user );

		$query = \FluentCommunity\Modules\Course\Model\Course::searchBy( $search )
			->where(
				function ( $q ) use ( $is_creator, $my_courses, $user ) {
					if ( $is_creator && ! $my_courses ) {
						$q->where( 'status', 'published' )->orWhere( 'created_by', $user->ID );
					} else {
						$q->where( 'status', 'published' );
					}
				}
			)
			->byPostTopic( $topic_slug )
			->where(
				function ( $q ) use ( $enrolled, $admin_secret_ids ) {
					$q->whereIn( 'privacy', array( 'public', 'private' ) );
					$q->orWhereIn( 'id', $enrolled ?: array( 0 ) );
					if ( $admin_secret_ids ) {
						$q->orWhereIn( 'id', $admin_secret_ids );
					}
				}
			);

		if ( $my_courses ) {
			$query->whereIn( 'id', $enrolled ?: array( 0 ) );
		}

		if ( 'alphabetical' === $sort_by ) {
			$query->orderBy( 'title', 'ASC' );
		} elseif ( 'oldest' === $sort_by ) {
			$query->orderBy( 'created_at', 'ASC' );
		} else {
			$query->orderBy( 'created_at', 'DESC' );
		}

		$courses = $query->forPage( $page, $per_page )->get();

		$words = $this->words( $request );
		$items = array();
		foreach ( $courses as $course ) {
			$items[] = $this->card_row( $course, $words );
		}

		return $this->ok( $items );
	}

	/**
	 * @param \FluentCommunity\App\Models\User|null $user
	 * @return int[]
	 */
	protected function administered_secret_ids( $user ) {
		if ( ! $user ) {
			return array();
		}
		$permissions = $user->getPermissions();
		if ( empty( $permissions['course_admin'] ) && empty( $permissions['course_creator'] ) ) {
			return array();
		}
		$query = \FluentCommunity\Modules\Course\Model\Course::where( 'privacy', 'secret' );
		// Course::isCourseAdmin: a course admin administers every course,
		// a course creator only their own.
		if ( empty( $permissions['course_admin'] ) ) {
			$query->where( 'created_by', $user->ID );
		}
		return array_map( 'intval', $query->pluck( 'id' )->toArray() );
	}

	/**
	 * One card.
	 *
	 * @param \FluentCommunity\Modules\Course\Model\Course $course
	 * @param array<string, string>                        $words
	 * @return array<string, mixed>
	 */
	protected function card_row( $course, array $words ) {
		$helper   = '\\FluentCommunity\\Modules\\Course\\Services\\CourseHelper';
		$settings = is_array( $course->settings ) ? $course->settings : array();
		$id       = (int) $course->id;

		$sections = \FluentCommunity\Modules\Course\Model\CourseTopic::where( 'space_id', $id )->count();
		$lessons  = \FluentCommunity\Modules\Course\Model\CourseLesson::where( 'space_id', $id )->count();

		$hide_count = isset( $settings['hide_members_count'] ) && 'yes' === $settings['hide_members_count'];
		$students   = $hide_count ? 0 : \FluentCommunity\App\Models\SpaceUserPivot::where( 'space_id', $id )->count();

		$is_enrolled = $helper::isEnrolled( $id );
		$progress    = $is_enrolled ? (int) $helper::getCourseProgress( $id ) : 0;
		$completed   = $is_enrolled ? count( (array) $helper::getCompletedLessonIds( $id ) ) : 0;
		$paywalls    = $is_enrolled ? array() : $this->paywall_products( $course, $settings );
		$is_paid     = ! empty( $paywalls );

		$cover = (string) $course->cover_photo;
		if ( '' !== $cover && false !== strpos( $cover, self::FC_PLACEHOLDER ) ) {
			$cover = '';
		}

		return array(
			'id'              => $id,
			'slug'            => (string) $course->slug,
			'title'           => (string) $course->title,
			'description'     => (string) $course->description,
			'cover_photo'     => $cover,
			'has_cover'       => '' !== $cover,
			'privacy'         => (string) $course->privacy,
			'status'          => (string) $course->status,
			'course_type'     => isset( $settings['course_type'] ) ? (string) $settings['course_type'] : 'self_paced',
			'sections_count'  => $sections,
			'lessons_count'   => $lessons,
			'counts_label'    => $this->plural( $sections, $words['section_one'], $words['section_many'] )
				. $words['counts_separator']
				. $this->plural( $lessons, $words['lesson_one'], $words['lesson_many'] ),
			'students_count'  => $students,
			// Empty when the course hides it, so the child drops (state 2).
			'students_label'  => $hide_count ? '' : $this->plural( $students, $words['student_one'], $words['student_many'] ),
			'is_enrolled'     => $is_enrolled,
			'progress'        => $progress,
			'completed_count' => $completed,
			'is_paid'         => $is_paid,
			'cta_start'       => ! $is_paid && $is_enrolled && 0 === $completed && $progress < 100,
			'cta_progress'    => ! $is_paid && $is_enrolled && $completed > 0 && $progress < 100,
			'cta_review'      => ! $is_paid && $is_enrolled && $progress >= 100,
			'cta_view'        => ! $is_paid && ! $is_enrolled,
			'progress_label'  => str_replace( '%d', (string) $progress, $words['progress_format'] ),
			'paid_note'       => $is_paid ? $words['paid_note'] : '',
			// The course's page in the portal, where the paywall lives —
			// "Buying happens on the website, not in the app."
			'web_url'         => \FluentCommunity\App\Services\Helper::baseUrl( 'course/' . $course->slug ),
			'paywalls'        => $paywalls,
		);
	}

	/**
	 * The FluentCart products behind a course's paywall — FC's own
	 * `PaywallController::getPaywalls` rule, less the admin_url a member
	 * must never be handed.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	protected function paywall_products( $course, array $settings ) {
		if ( ! defined( 'FLUENTCART_VERSION' ) || ! class_exists( '\\FluentCart\\App\\Models\\Product' ) ) {
			return array();
		}
		if ( ! isset( $settings['show_paywalls'] ) || 'yes' !== $settings['show_paywalls'] ) {
			return array();
		}
		$product_ids = isset( $settings['cart_product_ids'] ) ? array_filter( array_map( 'intval', (array) $settings['cart_product_ids'] ) ) : array();
		if ( ! $product_ids ) {
			return array();
		}

		$products = \FluentCart\App\Models\Product::whereIn( 'ID', $product_ids )
			->where( 'post_status', 'publish' )
			->with(
				array(
					'variants' => function ( $q ) {
						$q->select( 'id', 'post_id', 'item_price', 'variation_title', 'other_info' );
					},
				)
			)
			->get();

		$base = \FluentCommunity\App\Services\Helper::baseUrl( '/' );
		$out  = array();
		foreach ( $products as $product ) {
			$variants = array();
			foreach ( $product->variants as $variant ) {
				$variants[] = array(
					'id'           => (int) $variant->id,
					'title'        => (string) $variant->variation_title,
					'total_price'  => html_entity_decode( (string) $variant->formatted_total, ENT_QUOTES ),
					'checkout_url' => add_query_arg(
						array(
							'fluent-cart' => 'instant_checkout',
							'item_id'     => $variant->id,
							'quantity'    => 1,
							'redirect_to' => $base . 'checkout?space_id=' . (int) $course->id,
						),
						site_url()
					),
				);
			}
			$out[] = array(
				'id'       => (int) $product->ID,
				'title'    => (string) $product->post_title,
				'view_url' => (string) $product->view_url,
				'variants' => $variants,
			);
		}
		return $out;
	}

	/* ---------------------------------------------------------------
	 * GET /courses/detail          — the course (one row)
	 * GET /courses/detail/sections — its sections, lessons inside
	 *
	 * Courses doc, states 5–7. Both read FluentCommunity's own
	 * `/courses/{slug}/by-slug`, ONE rest_do_request per HTTP request
	 * (P134), so every lock, every refusal and the admin's draft lessons
	 * are FC's `processCourse` — nothing about access is re-decided here.
	 * What is ours: the words, the counts, and which ONE bottom button
	 * draws.
	 *
	 * A refusal ("Course not found. maybe the course is not published
	 * yet!", "You must need to be invited to view this course") answers
	 * 200 with no rows and FC's sentence as the message: the header
	 * publishes the empty verdict and the screen's not-found card reads
	 * it (D80/D83).
	 * ------------------------------------------------------------- */

	public function detail( \WP_REST_Request $request ) {
		$read = $this->read_course( $request );
		if ( is_string( $read ) ) {
			return $this->refused( $read );
		}
		$course = $read['course'];
		$track  = isset( $read['track'] ) && is_array( $read['track'] ) ? $read['track'] : array();
		$words  = $this->words( $request );

		$id       = (int) $course['id'];
		$settings = isset( $course['settings'] ) && is_array( $course['settings'] ) ? $course['settings'] : array();
		$is_admin = ! empty( $course['is_course_admin'] );
		$enrolled = ! empty( $track['isEnrolled'] );
		$progress = isset( $track['progress'] ) ? (int) $track['progress'] : 0;
		$done_ids = isset( $track['completed_lessons'] ) ? array_map( 'intval', (array) $track['completed_lessons'] ) : array();

		$sections = isset( $read['sections'] ) && is_array( $read['sections'] ) ? $read['sections'] : array();
		$lessons  = 0;
		foreach ( $sections as $section ) {
			$lessons += count( (array) ( $section['lessons'] ?? array() ) );
		}

		// Doc state 5's bottom button. A student or the course admin
		// learns; anyone else is offered the one way in FC allows them.
		$learner  = $enrolled || $is_admin;
		$paywalls = $learner ? array() : $this->paywall_products( (object) $course, $settings );
		$product  = $paywalls ? $paywalls[0] : null;
		$variant  = $product && ! empty( $product['variants'] ) ? $product['variants'][0] : null;
		$public   = 'public' === ( $course['privacy'] ?? '' );

		// The instructor card — FC leaves `creator` off when the course
		// hides its instructor view, and so does the screen.
		$creator = isset( $course['creator'] ) && is_array( $course['creator'] ) ? $course['creator'] : null;
		$hidden  = isset( $settings['hide_instructor_view'] ) && 'yes' === $settings['hide_instructor_view'];

		$row = array(
			'id'                       => $id,
			'slug'                     => (string) $course['slug'],
			'title'                    => (string) $course['title'],
			'privacy'                  => (string) ( $course['privacy'] ?? '' ),
			'status'                   => (string) ( $course['status'] ?? '' ),
			// "X sections . Y lessons" — the lessons THIS viewer is shown
			// (a student never counts a draft), as the list below them.
			'counts_label'             => $this->plural( count( $sections ), $words['section_one'], $words['section_many'] )
				. $words['counts_separator']
				. $this->plural( $lessons, $words['lesson_one'], $words['lesson_many'] ),
			// "Course description (the long description)" — FC's course
			// details, as text; the short description when there are none.
			// The "Course content" heading draws only over sections.
			'has_sections'             => count( $sections ) > 0,
			'description'              => $this->plain_text(
				'' !== (string) ( $settings['course_details_rendered'] ?? '' )
					? (string) $settings['course_details_rendered']
					: (string) ( $course['description'] ?? '' )
			),
			'is_course_admin'          => $is_admin,
			'is_enrolled'              => $enrolled,
			'progress'                 => $progress,
			'show_instructor'          => $creator && ! $hidden,
			'instructor_name'          => $creator ? (string) ( $creator['display_name'] ?? '' ) : '',
			'instructor_avatar'        => $creator ? (string) ( $creator['avatar'] ?? '' ) : '',
			'instructor_courses_label' => $creator ? $this->plural( (int) ( $creator['total_courses'] ?? 0 ), $words['course_one'], $words['course_many'] ) : '',
			'cta_start'                => $learner && 0 === count( $done_ids ) && $progress < 100,
			'cta_continue'             => $learner && count( $done_ids ) > 0 && $progress < 100,
			'cta_review'               => $learner && $progress >= 100,
			'cta_paywall'              => ! $learner && null !== $product,
			// A guest is offered it too: FC answers them with its own
			// "You need to login to enroll in this course." (state 5).
			'cta_enroll'               => ! $learner && null === $product && $public,
			// Private (secret never gets this far — FC refuses it).
			'cta_invite'               => ! $learner && null === $product && ! $public,
			'paywall_title'            => $product ? (string) $product['title'] : '',
			'paywall_price'            => $variant ? (string) $variant['total_price'] : '',
			'paywall_url'              => $variant ? (string) $variant['checkout_url'] : '',
			// Where START / CONTINUE lands — the lesson screen (chunk 4).
			'next_lesson_slug'         => $this->next_lesson_slug( $sections, $done_ids ),
		);

		return $this->ok( array( $row ) );
	}

	public function detail_sections( \WP_REST_Request $request ) {
		$read = $this->read_course( $request );
		if ( is_string( $read ) ) {
			return $this->refused( $read );
		}
		$words    = $this->words( $request );
		$track    = isset( $read['track'] ) && is_array( $read['track'] ) ? $read['track'] : array();
		$done_ids = isset( $track['completed_lessons'] ) ? array_map( 'intval', (array) $track['completed_lessons'] ) : array();

		$items = array();
		foreach ( (array) ( $read['sections'] ?? array() ) as $section ) {
			$lessons = array();
			foreach ( (array) ( $section['lessons'] ?? array() ) as $lesson ) {
				$lessons[] = $this->lesson_row( $lesson, $done_ids );
			}
			$items[] = array(
				'id'            => (int) $section['id'],
				'slug'          => (string) ( $section['slug'] ?? '' ),
				'title'         => (string) $section['title'],
				'lessons_label' => $this->plural( count( $lessons ), $words['lesson_one'], $words['lesson_many'] ),
				'is_locked'     => ! empty( $section['is_locked'] ),
				'lessons'       => $lessons,
			);
		}
		return $this->ok( $items );
	}

	/* ---------------------------------------------------------------
	 * GET /courses/detail/students — the Students page (states 8–10)
	 *
	 * FluentCommunity's `CourseAdminController::getCourseStudents`, one
	 * in-process read: its search (name or username), its three sorts
	 * (display_name · created_at = enrolled · last_activity) and both
	 * directions, and its progress (completed ÷ published lessons). Ours:
	 * "@username", "Enrolled 2h" and "0%" — words from the catalog.
	 * FC's route is the gate: a non-admin gets its refusal and no rows.
	 * ------------------------------------------------------------- */

	public function detail_students( \WP_REST_Request $request ) {
		$course_id = (int) $request->get_param( 'course_id' );
		if ( $course_id <= 0 ) {
			return $this->refused( __( 'Course ID is required.', 'appza-core' ) );
		}
		$sort_by  = sanitize_key( (string) $request->get_param( 'sort_by' ) );
		$sort_dir = strtoupper( sanitize_key( (string) $request->get_param( 'sort_dir' ) ) );

		$fc = new \WP_REST_Request( 'GET', '/fluent-community/v2/admin/courses/' . $course_id . '/students' );
		$fc->set_query_params(
			array_filter(
				array(
					'search'   => sanitize_text_field( (string) $request->get_param( 'search' ) ),
					'sort_by'  => in_array( $sort_by, array( 'display_name', 'created_at', 'last_activity' ), true ) ? $sort_by : 'created_at',
					'sort_dir' => in_array( $sort_dir, array( 'ASC', 'DESC' ), true ) ? $sort_dir : '',
					'per_page' => 100,
				),
				'strlen'
			)
		);
		$response = rest_do_request( $fc );
		$data     = $response instanceof \WP_REST_Response ? $response->get_data() : null;
		if ( ! $response instanceof \WP_REST_Response || $response->get_status() >= 400 || ! is_array( $data ) ) {
			$message = is_array( $data ) && ! empty( $data['message'] ) ? (string) $data['message'] : __( 'Sorry, you are not allowed to do that.', 'appza-core' );
			return $this->refused( $message );
		}
		$data = json_decode( wp_json_encode( $data ), true );
		$rows = isset( $data['students']['data'] ) ? (array) $data['students']['data'] : array();

		$enrolled_format = $this->word( $request, 'enrolled_format', __( 'Enrolled %s', 'appza-core' ) );
		$items           = array();
		foreach ( $rows as $row ) {
			$username = (string) ( $row['username'] ?? '' );
			$progress = max( 0, min( 100, (int) ( $row['progress'] ?? 0 ) ) );
			// The enrolment, not the account: FC's pivot row for THIS course.
			$enrolled = (string) ( $row['space_pivot']['created_at'] ?? ( $row['created_at'] ?? '' ) );
			$items[]  = array(
				'user_id'        => (int) $row['user_id'],
				'display_name'   => (string) ( $row['display_name'] ?? '' ),
				'username_at'    => '' !== $username ? '@' . $username : '',
				'avatar'         => (string) ( $row['avatar'] ?? '' ),
				'enrolled_at'    => $enrolled,
				'enrolled_label' => '' !== $enrolled ? str_replace( '%s', $this->compact_age( $enrolled ), $enrolled_format ) : '',
				'last_activity'  => (string) ( $row['last_activity'] ?? '' ),
				'progress'       => $progress,
				'progress_label' => $progress . '%',
				'course_id'      => $course_id,
			);
		}
		return $this->ok( $items );
	}

	/* ---------------------------------------------------------------
	 * GET /courses/lesson — the lesson screen (Courses doc, states 16–17)
	 *
	 * Two FluentCommunity reads: the course's by-slug (for the viewer's
	 * lesson list — "Lesson X of Y", < and > — and the track: enrolled,
	 * completed lessons) and the lesson's by-slug (content, media, and
	 * every lock: FC's own `resolveLessonAccess` and `getAccessMessage`,
	 * whose sentences are the doc's). Nothing about access is decided here.
	 *
	 * No `lesson_slug` opens the viewer's next lesson — the first one they
	 * may open and have not completed — which is what START / CONTINUE
	 * mean on the details (state 5 / 7) and what a screen opened with no
	 * lesson falls back to.
	 *
	 * ALWAYS one row. A refusal (unpublished course, secret course, a
	 * lesson that does not exist) is a row with `is_refused` and FC's
	 * sentence as the lock title, so the lock card draws it with Back to
	 * Course — the sentence differs per refusal, which a verdict cannot
	 * carry.
	 * ------------------------------------------------------------- */

	public function lesson( \WP_REST_Request $request ) {
		$position_format   = $this->word( $request, 'position_format', __( 'Lesson %1$d of %2$d', 'appza-core' ) );
		$discussion_format = $this->word( $request, 'discussion_format', __( 'Discussion(%d)', 'appza-core' ) );
		$discussion_none   = $this->word( $request, 'discussion_none', __( 'Discussion', 'appza-core' ) );
		$not_found       = __( 'Lesson not found. maybe the lesson is not published yet!', 'fluent-community' );

		$read = $this->read_course( $request );
		if ( is_string( $read ) ) {
			return $this->ok( array( $this->refused_lesson( $read ) ) );
		}
		$course   = $read['course'];
		$track    = isset( $read['track'] ) && is_array( $read['track'] ) ? $read['track'] : array();
		$sections = isset( $read['sections'] ) && is_array( $read['sections'] ) ? $read['sections'] : array();
		$done_ids = isset( $track['completed_lessons'] ) ? array_map( 'intval', (array) $track['completed_lessons'] ) : array();
		$enrolled = ! empty( $track['isEnrolled'] );

		// The viewer's lessons in course order — FC's own list, drafts
		// only for the course admin, as the details draw it.
		$order = array();
		foreach ( $sections as $section ) {
			foreach ( (array) ( $section['lessons'] ?? array() ) as $row ) {
				$order[] = (string) ( $row['slug'] ?? '' );
			}
		}

		$slug = sanitize_title( (string) $request->get_param( 'lesson_slug' ) );
		if ( '' === $slug ) {
			$slug = $this->next_lesson_slug( $sections, $done_ids );
		}
		if ( '' === $slug ) {
			return $this->ok( array( $this->refused_lesson( $not_found ) ) );
		}

		$response = rest_do_request( new \WP_REST_Request( 'GET', '/fluent-community/v2/courses/' . sanitize_title( (string) $course['slug'] ) . '/lessons/' . $slug . '/by-slug' ) );
		$data     = $response instanceof \WP_REST_Response ? $response->get_data() : null;
		$status   = $response instanceof \WP_REST_Response ? $response->get_status() : 500;
		if ( $status >= 400 || ! is_array( $data ) || empty( $data['lesson'] ) ) {
			$message = is_array( $data ) && ! empty( $data['message'] ) ? (string) $data['message'] : '';
			// A slug that matches no lesson is FC's firstOrFail — a model
			// exception, not a sentence. FC's own wording for it instead.
			if ( '' === $message || false !== strpos( $message, 'No query results' ) ) {
				$message = $not_found;
			}
			return $this->ok( array( $this->refused_lesson( $message ) ) );
		}
		$lesson = json_decode( wp_json_encode( $data['lesson'] ), true );

		$id       = (int) $lesson['id'];
		$can_view = ! empty( $lesson['can_view'] );
		$meta     = isset( $lesson['meta'] ) && is_array( $lesson['meta'] ) ? $lesson['meta'] : array();
		$media    = isset( $meta['media'] ) && is_array( $meta['media'] ) ? $meta['media'] : array();

		// "Lesson 4 of 5", and the neighbours < > move to.
		$index = array_search( (string) $lesson['slug'], $order, true );
		$index = false === $index ? -1 : (int) $index;
		$total = count( $order );
		$prev  = $index > 0 ? $order[ $index - 1 ] : '';
		$next  = $index >= 0 && $index < $total - 1 ? $order[ $index + 1 ] : '';

		// The video: FC's media on the lesson, when the lesson shows media.
		$video_url = '';
		if ( $can_view && 'yes' === ( $meta['enable_media'] ?? '' ) ) {
			// An oembed's own iframe src is the embeddable URL; its `url`
			// is the watch page, which refuses to be framed.
			if ( ! empty( $media['video_url'] ) ) {
				$video_url = (string) $media['video_url'];
			} elseif ( preg_match( '#<iframe[^>]+src="([^"]+)"#', (string) ( $media['html'] ?? '' ), $m ) ) {
				$video_url = html_entity_decode( $m[1], ENT_QUOTES );
			} elseif ( 'video' === ( $media['content_type'] ?? '' ) && ! empty( $media['url'] ) ) {
				$video_url = (string) $media['url'];
			}
		}

		// The poster: FC's image when the media has one, else YouTube's
		// own thumbnail (fcom-mobile's rule), so the card is not blank
		// before play (design 16).
		$poster = '' !== $video_url ? (string) ( $media['image'] ?? '' ) : '';
		if ( '' !== $video_url && '' === $poster && preg_match( '#youtu(?:\.be/|be\.com/(?:embed/|watch\?v=))([A-Za-z0-9_-]{6,})#', $video_url . ' ' . (string) ( $media['url'] ?? '' ), $m ) ) {
			$poster = 'https://img.youtube.com/vi/' . $m[1] . '/hqdefault.jpg';
		}

		// FC's lock: the h1 and the p of its own locker markup.
		$lock_title   = '';
		$lock_message = '';
		if ( ! $can_view ) {
			$html = (string) ( $lesson['access_message'] ?? '' );
			if ( preg_match( '#<h1[^>]*>(.*?)</h1>#s', $html, $m ) ) {
				$lock_title = trim( html_entity_decode( wp_strip_all_tags( $m[1] ), ENT_QUOTES ) );
			}
			if ( preg_match( '#<p[^>]*>(.*?)</p>#s', $html, $m ) ) {
				$lock_message = trim( html_entity_decode( wp_strip_all_tags( $m[1] ), ENT_QUOTES ) );
			}
			if ( '' === $lock_title ) {
				$lock_title = __( 'This lesson is currently locked', 'fluent-community' );
			}
		}

		$content = $can_view ? trim( wp_kses_post( (string) ( $lesson['content'] ?? '' ) ) ) : '';

		$course_settings = isset( $course['settings'] ) && is_array( $course['settings'] ) ? $course['settings'] : array();
		$comments_on     = $can_view
			&& 'yes' !== ( $course_settings['disable_comments'] ?? '' )
			&& 'yes' === ( $meta['enable_comments'] ?? '' );
		$comments_count  = (int) ( $lesson['comments_count'] ?? 0 );
		$done    = in_array( $id, $done_ids, true );

		$row = array_merge(
			$this->refused_lesson( '' ),
			array(
				'id'              => $id,
				'slug'            => (string) $lesson['slug'],
				'title'           => (string) $lesson['title'],
				'course_id'       => (int) $course['id'],
				'course_slug'     => (string) $course['slug'],
				'type'            => 'quiz' === ( $lesson['content_type'] ?? '' ) ? 'quiz' : ( '' !== $video_url ? 'video' : 'text' ),
				'position_label'  => $index >= 0 ? str_replace( array( '%1$d', '%2$d' ), array( number_format_i18n( $index + 1 ), number_format_i18n( $total ) ), $position_format ) : '',
				// One lesson has nowhere to go (design 16 draws no arrows).
				'has_nav'         => $total > 1,
				'has_prev'        => '' !== $prev,
				'has_next'        => '' !== $next,
				'prev_slug'       => $prev,
				'next_slug'       => $next,
				'can_view'        => $can_view,
				'has_video'       => '' !== $video_url,
				'video_url'       => $video_url,
				'video_poster'    => $poster,
				'has_content'     => '' !== trim( wp_strip_all_tags( $content ) ),
				'content_html'    => $content,
				'is_locked'       => ! $can_view,
				'is_refused'      => false,
				'lock_title'      => $lock_title,
				'lock_message'    => $lock_message,
				'is_enrolled'     => $enrolled,
				'is_course_admin' => ! empty( $course['is_course_admin'] ),
				// State 16: the viewer may watch without being enrolled — the
				// course admin, or anyone on a public self-paced course with
				// Public lesson view ON — so START COURSE takes the place of
				// Complete Lesson. State 17: a student completes; a completed
				// lesson reads COMPLETED LESSON, and tapping it undoes.
				'cta_start'       => $can_view && ! $enrolled,
				'cta_complete'    => $can_view && $enrolled && ! $done,
				'cta_completed'   => $can_view && $enrolled && $done,
				// The discussion (states 22–29). Open when the portal opens
				// it: the course has not disabled comments, the viewer may
				// see the lesson, and the lesson has Enable comments ON.
				// Writing is for a student or the course admin (state 22);
				// anyone else reads. "Discussion(N)" counts FC's comments_count,
				// which includes replies (Noor).
				'comments_enabled'   => $comments_on,
				'comments_can_write' => $comments_on && ( $enrolled || ! empty( $course['is_course_admin'] ) ),
				'comments_count'     => $comments_count,
				'discussion_label'   => $comments_count > 0 ? str_replace( '%d', number_format_i18n( $comments_count ), $discussion_format ) : $discussion_none,
			),
			$this->quiz_fields( $lesson, (int) $course['id'], $can_view, $enrolled )
		);

		return $this->ok( array( $row ) );
	}

	/**
	 * The quiz under a quiz lesson (Courses doc, states 18–21). FC Pro's
	 * questions — the lesson meta, correct flags already stripped by FC —
	 * and, for an enrolled viewer, FC's saved result for them (its own
	 * `/quiz/result`, which already hides the verdicts when the quiz has
	 * Hide result ON). Grading, attempts and pass/fail stay FC's.
	 *
	 * The app draws single choice and true/false as radios and multiple
	 * choice as checkboxes (the doc's two kinds); any other FC question
	 * type is listed with `supported` false.
	 *
	 * @param array<string, mixed> $lesson
	 * @return array<string, mixed>
	 */
	protected function quiz_fields( array $lesson, $course_id, $can_view, $enrolled ) {
		$meta    = isset( $lesson['meta'] ) && is_array( $lesson['meta'] ) ? $lesson['meta'] : array();
		$is_quiz = $can_view && 'quiz' === ( $lesson['content_type'] ?? '' );
		$fields  = array(
			'is_quiz'          => $is_quiz,
			'quiz_questions'   => array(),
			'quiz_can_submit'  => false,
			'quiz_hide_result' => false,
			'quiz_has_result'  => false,
			'quiz_result'      => null,
		);
		if ( ! $is_quiz ) {
			return $fields;
		}

		$questions = array();
		foreach ( (array) ( $meta['quiz_questions'] ?? array() ) as $question ) {
			if ( empty( $question['enabled'] ) || empty( $question['slug'] ) ) {
				continue;
			}
			$type    = (string) ( $question['type'] ?? '' );
			$options = array();
			foreach ( (array) ( $question['options'] ?? array() ) as $option ) {
				if ( isset( $option['label'] ) && '' !== (string) $option['label'] ) {
					$options[] = array( 'label' => (string) $option['label'] );
				}
			}
			$questions[] = array(
				'slug'      => (string) $question['slug'],
				'type'      => $type,
				'label'     => trim( html_entity_decode( wp_strip_all_tags( (string) ( $question['label_rendered'] ?? ( $question['label'] ?? '' ) ) ), ENT_QUOTES ) ),
				'multiple'  => 'multiple_choice' === $type,
				'supported' => in_array( $type, array( 'single_choice', 'true_false', 'multiple_choice' ), true ),
				'options'   => $options,
			);
		}
		$fields['quiz_questions']   = $questions;
		$fields['quiz_can_submit']  = $enrolled;
		$fields['quiz_hide_result'] = 'yes' === ( $meta['hide_result'] ?? '' );

		if ( $enrolled ) {
			$response = rest_do_request( new \WP_REST_Request( 'GET', '/fluent-community/v2/courses/' . (int) $course_id . '/lessons/' . (int) $lesson['id'] . '/quiz/result' ) );
			$data     = $response instanceof \WP_REST_Response && $response->get_status() < 400 ? json_decode( wp_json_encode( $response->get_data() ), true ) : null;
			$result   = is_array( $data ) && ! empty( $data['result'] ) && is_array( $data['result'] ) ? $data['result'] : null;
			if ( $result ) {
				$fields['quiz_has_result'] = true;
				$fields['quiz_result']     = $this->quiz_result_row( $result );
			}
		}
		return $fields;
	}

	/**
	 * FC's quiz result, flattened for the card: status (passed | failed |
	 * published), score, "2/2", attempts, and per question the viewer's
	 * answer and — unless FC hid it — whether it was right.
	 *
	 * @param array<string, mixed> $result
	 * @return array<string, mixed>
	 */
	protected function quiz_result_row( array $result ) {
		$meta    = isset( $result['meta'] ) && is_array( $result['meta'] ) ? $result['meta'] : array();
		$answers = array();
		foreach ( (array) ( $result['message'] ?? array() ) as $slug => $answer ) {
			if ( ! is_array( $answer ) ) {
				continue;
			}
			$answers[ (string) $slug ] = array(
				'user_answer' => $answer['user_answer'] ?? null,
				// null = hidden (Hide result ON): no verdict to draw.
				'is_correct'  => array_key_exists( 'is_correct', $answer ) ? (bool) $answer['is_correct'] : null,
			);
		}
		return array(
			'status'   => (string) ( $result['status'] ?? '' ),
			'score'    => (int) ( $result['score'] ?? 0 ),
			'correct'  => (int) ( $meta['correct_answers'] ?? 0 ),
			'total'    => (int) ( $meta['total_questions'] ?? 0 ),
			'attempts' => (int) ( $meta['attempts'] ?? 0 ),
			'answers'  => (object) $answers,
		);
	}

	/** The lesson screen's row for a refusal: FC's sentence on the lock card. */
	protected function refused_lesson( $message ) {
		return array(
			'id'              => 0,
			'slug'            => '',
			'title'           => '',
			'course_id'       => 0,
			'course_slug'     => '',
			'type'            => 'text',
			'position_label'  => '',
			'has_nav'         => false,
			'has_prev'        => false,
			'has_next'        => false,
			'prev_slug'       => '',
			'next_slug'       => '',
			'can_view'        => false,
			'has_video'       => false,
			'video_url'       => '',
			'video_poster'    => '',
			'has_content'     => false,
			'content_html'    => '',
			'is_locked'       => true,
			'is_refused'      => true,
			'lock_title'      => (string) $message,
			'lock_message'    => '',
			'is_enrolled'     => false,
			'is_course_admin' => false,
			'cta_start'       => false,
			'cta_complete'    => false,
			'cta_completed'   => false,
			'comments_enabled'   => false,
			'comments_can_write' => false,
			'comments_count'     => 0,
			'discussion_label'   => '',
			'is_quiz'          => false,
			'quiz_questions'   => array(),
			'quiz_can_submit'  => false,
			'quiz_hide_result' => false,
			'quiz_has_result'  => false,
			'quiz_result'      => null,
		);
	}

	/** "2h", "3d", "2mo" — the designs' compact age (All Members' rule). */
	protected function compact_age( $datetime ) {
		try {
			$then = new \DateTime( $datetime, new \DateTimeZone( 'UTC' ) );
		} catch ( \Exception $e ) {
			return (string) $datetime;
		}
		$diff = time() - $then->getTimestamp();
		if ( $diff < 60 ) {
			return max( 0, $diff ) . 's';
		}
		foreach ( array( 31536000 => 'y', 2592000 => 'mo', 86400 => 'd', 3600 => 'h', 60 => 'm' ) as $unit => $suffix ) {
			if ( $diff >= $unit ) {
				return (int) floor( $diff / $unit ) . $suffix;
			}
		}
		return '0s';
	}

	protected function word( \WP_REST_Request $request, $key, $fallback ) {
		$value = $request->get_param( $key );
		return is_string( $value ) && '' !== trim( $value ) ? sanitize_text_field( $value ) : $fallback;
	}

	/**
	 * One lesson row. `status` picks the leading glyph and `type` the
	 * trailing tag, each through the catalog's prop_map, so both glyphs
	 * and the word "Quiz" stay right-panel props.
	 *
	 * status, first that holds: locked · done · video · open.
	 * The doc: "video lessons show a play icon and quiz lessons carry a
	 * Quiz tag". A lesson is a video when FC's media on it is one — FC's
	 * own content_type stays `text` for a lesson with an embedded video.
	 *
	 * @param array<string, mixed> $lesson
	 * @param int[]                $done_ids
	 * @return array<string, mixed>
	 */
	protected function lesson_row( array $lesson, array $done_ids ) {
		$id       = (int) $lesson['id'];
		$meta     = isset( $lesson['meta'] ) && is_array( $lesson['meta'] ) ? $lesson['meta'] : array();
		$is_video = 'video' === ( $lesson['content_type'] ?? '' )
			|| 'video' === ( $meta['media']['content_type'] ?? '' );
		$is_quiz  = 'quiz' === ( $lesson['content_type'] ?? '' );

		if ( ! empty( $lesson['is_locked'] ) ) {
			$status = 'locked';
		} elseif ( in_array( $id, $done_ids, true ) ) {
			$status = 'done';
		} elseif ( $is_video ) {
			$status = 'video';
		} else {
			$status = 'open';
		}

		return array(
			'id'        => $id,
			'slug'      => (string) ( $lesson['slug'] ?? '' ),
			'title'     => (string) $lesson['title'],
			'status'    => $status,
			// Stamped as the row's data-value, so the stylesheet can colour
			// the play glyph blue (design 6) without a prop per status.
			'value'     => $status,
			'type'      => $is_quiz ? 'quiz' : ( $is_video ? 'video' : 'text' ),
			'is_locked' => ! empty( $lesson['is_locked'] ),
			'lock_type' => (string) ( $lesson['lock_type'] ?? '' ),
		);
	}

	/**
	 * The first lesson this viewer may open that they have not completed;
	 * the first they may open at all when every one is done.
	 *
	 * @param array<int, array<string, mixed>> $sections
	 * @param int[]                            $done_ids
	 */
	protected function next_lesson_slug( array $sections, array $done_ids ) {
		$first = '';
		foreach ( $sections as $section ) {
			foreach ( (array) ( $section['lessons'] ?? array() ) as $lesson ) {
				if ( empty( $lesson['can_view'] ) ) {
					continue;
				}
				if ( '' === $first ) {
					$first = (string) $lesson['slug'];
				}
				if ( ! in_array( (int) $lesson['id'], $done_ids, true ) ) {
					return (string) $lesson['slug'];
				}
			}
		}
		return $first;
	}

	/**
	 * FluentCommunity's by-slug, in-process. Returns FC's payload, or FC's
	 * sentence when it refuses.
	 *
	 * @return array<string, mixed>|string
	 */
	protected function read_course( \WP_REST_Request $request ) {
		$slug = sanitize_title( (string) $request->get_param( 'course_slug' ) );
		if ( '' === $slug ) {
			return __( 'Course slug is required.', 'appza-core' );
		}
		$response = rest_do_request( new \WP_REST_Request( 'GET', '/fluent-community/v2/courses/' . $slug . '/by-slug' ) );
		$data     = $response instanceof \WP_REST_Response ? $response->get_data() : null;
		$status   = $response instanceof \WP_REST_Response ? $response->get_status() : 500;
		if ( $status >= 400 || ! is_array( $data ) || empty( $data['course'] ) ) {
			$message = is_array( $data ) && ! empty( $data['message'] ) ? (string) $data['message'] : '';
			return '' !== $message ? $message : __( 'Course not found. maybe the course is not published yet!', 'fluent-community' );
		}
		// FC hands back its models; the rows below read plain arrays.
		return json_decode( wp_json_encode( $data ), true );
	}

	protected function refused( $message ) {
		return rest_ensure_response(
			array(
				'response' => array( 'status' => 200, 'message' => (string) $message ),
				'items'    => array(),
			)
		);
	}

	protected function plain_text( $html ) {
		$text = wp_strip_all_tags( str_replace( array( '</p>', '<br>', '<br/>', '<br />' ), "\n", (string) $html ) );
		return trim( preg_replace( "/\n{3,}/", "\n\n", html_entity_decode( $text, ENT_QUOTES ) ) );
	}

	/**
	 * The card's words. The catalog's DataSource sends them; these are
	 * only what a request without them gets. `%d` is replaced rather than
	 * handed to sprintf, so a format from a request can only make text.
	 *
	 * @return array<string, string>
	 */
	protected function words( \WP_REST_Request $request ) {
		$defaults = array(
			'section_one'      => __( '%d section', 'appza-core' ),
			'section_many'     => __( '%d sections', 'appza-core' ),
			'lesson_one'       => __( '%d lesson', 'appza-core' ),
			'lesson_many'      => __( '%d lessons', 'appza-core' ),
			'student_one'      => __( '%d student', 'appza-core' ),
			'student_many'     => __( '%d students', 'appza-core' ),
			'course_one'       => __( '%d course', 'appza-core' ),
			'course_many'      => __( '%d courses', 'appza-core' ),
			'counts_separator' => '.',
			'progress_format'  => __( '%d% COMPLETE', 'appza-core' ),
			'paid_note'        => __( 'lifetime access. Enrollment opens on the web.', 'appza-core' ),
		);
		$words = array();
		foreach ( $defaults as $key => $default ) {
			$value         = $request->get_param( $key );
			$words[ $key ] = is_string( $value ) && '' !== $value ? sanitize_text_field( $value ) : $default;
		}
		// The separator is padded here: a leading or trailing space does
		// not survive the trip from the catalog through the preview proxy
		// ("1 section.1 lesson" on the first photograph).
		$sep = trim( wp_strip_all_tags( (string) $request->get_param( 'counts_separator' ) ) );
		$words['counts_separator'] = ' ' . ( '' !== $sep ? $sep : '.' ) . ' ';
		return $words;
	}

	protected function plural( $n, $one, $many ) {
		return str_replace( '%d', number_format_i18n( (int) $n ), 1 === (int) $n ? $one : $many );
	}

	protected function clamp( $value, $fallback, $min, $max ) {
		if ( $value <= 0 ) {
			return $fallback;
		}
		return max( $min, min( $max, $value ) );
	}

	protected function ok( array $items ) {
		return rest_ensure_response(
			array(
				'response' => array( 'status' => 200, 'message' => 'Success' ),
				'items'    => array_values( $items ),
			)
		);
	}
}
