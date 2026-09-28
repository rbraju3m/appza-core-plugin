<?php
/**
 * Course routes in the fcom-mobile namespace (`/catalog` because fcom-mobile already owns `/courses`).
 * The catalog query mirrors FC's CourseController::getCourses plus one clause: a course admin also sees their secret courses.
 */

namespace AppzaCore\Plugin\Rest;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class FluentCommunityCoursesController {

	private const SORTS = array( 'alphabetical', 'latest', 'oldest' );

	private const DEFAULT_PER_PAGE = 20;

	/** FC substitutes this for a missing cover; the design draws its own. */
	private const FC_PLACEHOLDER = 'assets/images/course-placeholder.jpg';

	/** Legacy appza-builder's callback on this filter fatals on a refused course read, turning FC's 422 into a 500. */
	private const LEGACY_FILTER = 'fluent_community/course_api_response';
	private const LEGACY_METHOD = 'appza_fcom_mobile_course_api_response';

	/** Public read: FC lists public and private courses to a guest too. */
	public function permission_check() {
		return PortalGuard::reader();
	}

	/** @var array<int, mixed> Stack: a course read can nest another. */
	private $parked = array();

	public function register_hooks() {
		$this->unhook_legacy_course_filter();
		add_filter( self::LEGACY_FILTER, array( $this, 'shield_refusal' ), 9, 1 );
		add_filter( self::LEGACY_FILTER, array( $this, 'unshield_refusal' ), 11, 1 );
	}

	/**
	 * fcom-mobile's copy of the legacy callback (priority 10) fatals when FC refuses, as `$data` is then a WP_REST_Response.
	 * Swap the refusal for an empty array at 9 and restore it at 11 so FC's 422 reaches the app.
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

	/** By method name: appza-builder registered it on an instance we have no handle on. */
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

		// FC's /courses omits secret courses the viewer administers. Not on My Courses: that tab is enrolments.
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
		// As FC's Course::isCourseAdmin.
		if ( empty( $permissions['course_admin'] ) ) {
			$query->where( 'created_by', $user->ID );
		}
		return array_map( 'intval', $query->pluck( 'id' )->toArray() );
	}

	/**
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
			'web_url'         => \FluentCommunity\App\Services\Helper::baseUrl( 'course/' . $course->slug ),
			'paywalls'        => $paywalls,
		);
	}

	/**
	 * FC's PaywallController::getPaywalls rule, minus the admin_url a member must never be handed.
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

	/** Access is FC's by-slug verdict; a refusal answers 200 with no rows and FC's sentence as the message. */
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

		$learner  = $enrolled || $is_admin;
		$paywalls = $learner ? array() : $this->paywall_products( (object) $course, $settings );
		$product  = $paywalls ? $paywalls[0] : null;
		$variant  = $product && ! empty( $product['variants'] ) ? $product['variants'][0] : null;
		$public   = 'public' === ( $course['privacy'] ?? '' );

		$creator = isset( $course['creator'] ) && is_array( $course['creator'] ) ? $course['creator'] : null;
		$hidden  = isset( $settings['hide_instructor_view'] ) && 'yes' === $settings['hide_instructor_view'];

		$row = array(
			'id'                       => $id,
			'slug'                     => (string) $course['slug'],
			'title'                    => (string) $course['title'],
			'privacy'                  => (string) ( $course['privacy'] ?? '' ),
			'status'                   => (string) ( $course['status'] ?? '' ),
			// Only lessons this viewer is shown (a student never counts a draft).
			'counts_label'             => $this->plural( count( $sections ), $words['section_one'], $words['section_many'] )
				. $words['counts_separator']
				. $this->plural( $lessons, $words['lesson_one'], $words['lesson_many'] ),
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
			// A guest too: FC answers them with its own login prompt.
			'cta_enroll'               => ! $learner && null === $product && $public,
			// Secret courses never get this far; FC refuses them.
			'cta_invite'               => ! $learner && null === $product && ! $public,
			'paywall_title'            => $product ? (string) $product['title'] : '',
			'paywall_price'            => $variant ? (string) $variant['total_price'] : '',
			'paywall_url'              => $variant ? (string) $variant['checkout_url'] : '',
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

	/** FC's admin students route is the gate: a non-admin gets its refusal and no rows. */
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
			// The enrolment date, not the account's: FC's pivot row for this course.
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

	/**
	 * No `lesson_slug` opens the viewer's next lesson. Always one row: a refusal is a row with `is_refused`
	 * and FC's sentence as the lock title, since the sentence differs per refusal.
	 */
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
			// An unknown slug is FC's firstOrFail model exception, not a sentence.
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

		$index = array_search( (string) $lesson['slug'], $order, true );
		$index = false === $index ? -1 : (int) $index;
		$total = count( $order );
		$prev  = $index > 0 ? $order[ $index - 1 ] : '';
		$next  = $index >= 0 && $index < $total - 1 ? $order[ $index + 1 ] : '';

		$video_url = '';
		if ( $can_view && 'yes' === ( $meta['enable_media'] ?? '' ) ) {
			// An oembed's iframe src is embeddable; its `url` is the watch page, which refuses framing.
			if ( ! empty( $media['video_url'] ) ) {
				$video_url = (string) $media['video_url'];
			} elseif ( preg_match( '#<iframe[^>]+src="([^"]+)"#', (string) ( $media['html'] ?? '' ), $m ) ) {
				$video_url = html_entity_decode( $m[1], ENT_QUOTES );
			} elseif ( 'video' === ( $media['content_type'] ?? '' ) && ! empty( $media['url'] ) ) {
				$video_url = (string) $media['url'];
			}
		}

		$poster = '' !== $video_url ? (string) ( $media['image'] ?? '' ) : '';
		if ( '' !== $video_url && '' === $poster && preg_match( '#youtu(?:\.be/|be\.com/(?:embed/|watch\?v=))([A-Za-z0-9_-]{6,})#', $video_url . ' ' . (string) ( $media['url'] ?? '' ), $m ) ) {
			$poster = 'https://img.youtube.com/vi/' . $m[1] . '/hqdefault.jpg';
		}

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
				// Course admin, or a public self-paced course with public lesson view, may watch without enrolling.
				'cta_start'       => $can_view && ! $enrolled,
				'cta_complete'    => $can_view && $enrolled && ! $done,
				'cta_completed'   => $can_view && $enrolled && $done,
				// FC's comments_count includes replies.
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
				// null = hidden (Hide result ON).
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
	 * FC's content_type stays `text` for a lesson with an embedded video, so the media is checked too.
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
			// Stamped as the row's data-value for per-status styling.
			'value'     => $status,
			'type'      => $is_quiz ? 'quiz' : ( $is_video ? 'video' : 'text' ),
			'is_locked' => ! empty( $lesson['is_locked'] ),
			'lock_type' => (string) ( $lesson['lock_type'] ?? '' ),
		);
	}

	/**
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

	/** @return array<string, mixed>|string FC's payload, or FC's refusal sentence. */
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
	 * Fallbacks for words the catalog normally sends. `%d` is replaced, not sprintf'd, so request text can only make text.
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
		// Padded here: surrounding spaces don't survive the catalog -> preview proxy trip.
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
