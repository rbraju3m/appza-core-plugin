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
		return true;
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
