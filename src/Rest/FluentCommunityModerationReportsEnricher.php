<?php
/**
 * Hooks `rest_request_after_callbacks` to give each moderation report row
 * the three strings the review modal draws (Noor's design 30), and the
 * card and menu fields of the Moderation screen (doc states 7–32), so the
 * shells bind values instead of branching.
 *
 * Same hook and the same reason as FluentCommunityFeedPermissionsFilter:
 * `rest_request_after_callbacks` fires inside dispatch(), which catches
 * both the outer serve_request and the rest_do_request sub-calls the
 * preview proxy makes for the WP-admin simulator. And the same policy —
 * fcom-mobile is third-party and stays unmodified (P68); we hook its
 * response rather than editing it.
 *
 * The branch reproduced here is FluentCommunity's own, from the
 * `ReportReviewModal` row template in its portal bundle:
 *
 *   no reporter AND no label for the reason  →  "Flagged automatically
 *                                               by the site rule"
 *   otherwise                                →  "<who> reported for <label>"
 *
 * It reads that way because the AUTO reasons — flag_all_new_posts,
 * user_flagged, profanity, first_post_approval — are deliberately absent
 * from Helper::getReportReasons(), which lists only the six a HUMAN can
 * pick. An unlabelled reason with no reporter is therefore exactly "the
 * site rule did this", and FC uses the absence as the signal.
 */

namespace AppzaCore\Plugin\Rest;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class FluentCommunityModerationReportsEnricher {

	const ROUTE = '/fcom-mobile/api/v1/moderation/reports';

	public function register_hooks() {
		add_filter( 'rest_request_after_callbacks', array( $this, 'enrich' ), 10, 3 );
	}

	/**
	 * @param \WP_REST_Response|\WP_HTTP_Response|\WP_Error|mixed $response
	 * @param array                                              $handler
	 * @param \WP_REST_Request                                   $request
	 * @return mixed
	 */
	public function enrich( $response, $handler, $request ) {
		if ( ! ( $response instanceof \WP_REST_Response ) ) {
			return $response;
		}
		if ( self::ROUTE !== $request->get_route() ) {
			return $response;
		}

		$data = $response->get_data();
		if ( ! is_array( $data ) || empty( $data['items'] ) || ! is_array( $data['items'] ) ) {
			return $response;
		}

		$reasons = $this->report_reasons();
		foreach ( $data['items'] as $i => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$data['items'][ $i ] = $this->enrich_row( $row, $reasons );
		}

		$response->set_data( $data );
		return $response;
	}

	/**
	 * @param array<string, string> $reasons
	 */
	protected function enrich_row( array $row, array $reasons ) {
		$reason = isset( $row['reason'] ) ? (string) $row['reason'] : '';
		$label  = isset( $reasons[ $reason ] ) ? $reasons[ $reason ] : '';

		$reporter = isset( $row['reporter'] ) && is_array( $row['reporter'] ) ? $row['reporter'] : null;
		$who      = '';
		if ( $reporter ) {
			foreach ( array( 'display_name', 'name', 'username' ) as $key ) {
				if ( ! empty( $reporter[ $key ] ) && is_string( $reporter[ $key ] ) ) {
					$who = $reporter[ $key ];
					break;
				}
			}
		}

		if ( '' === $who && '' === $label ) {
			// The auto case — design 30's bold line.
			$row['headline'] = __( 'Flagged automatically by the site rule', 'appza-core' );
		} else {
			// A human report, or a system one whose reason IS labelled.
			// "By System" is FluentCommunity's own wording for the second.
			$row['headline'] = '' === $label
				? ( '' !== $who ? $who : __( 'By System', 'appza-core' ) )
				: sprintf(
					/* translators: 1: who reported, 2: the reason */
					__( '%1$s reported for %2$s', 'appza-core' ),
					'' !== $who ? $who : __( 'By System', 'appza-core' ),
					$label
				);
		}

		$row['reason_label'] = $label;
		$row['reporter_name'] = $who;
		// Design 12's face beside the reporter's name. An auto-flag has none.
		$row['reporter_avatar'] = $reporter && isset( $reporter['avatar'] ) && is_string( $reporter['avatar'] ) ? $reporter['avatar'] : '';
		if ( ! empty( $row['created_at'] ) && is_string( $row['created_at'] ) ) {
			$row['created_at_relative'] = $this->relative_time( $row['created_at'] );
		}

		return $this->add_content_fields( $row, '' === $who && '' === $label );
	}

	/**
	 * The Moderation screen's card and menu (doc states 7–32, 2026-09-17).
	 *
	 * A report row carries the content it is about twice over — `post`
	 * always, `comment` too when a comment was reported — and a child binds
	 * one flat path. So the fields the card draws are lifted from whichever
	 * of the two the report is about: its author, its words, whether it has
	 * media, and how many reports it carries.
	 *
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	protected function add_content_fields( array $row, $is_auto ) {
		$is_comment = isset( $row['content_type'] ) && 'comment' === $row['content_type'];
		$post       = isset( $row['post'] ) && is_array( $row['post'] ) ? $row['post'] : array();
		$comment    = isset( $row['comment'] ) && is_array( $row['comment'] ) ? $row['comment'] : array();
		$content    = $is_comment ? $comment : $post;

		$row['content_id']         = isset( $content['id'] ) ? (int) $content['id'] : 0;
		$row['content_type_label'] = $is_comment ? __( 'Comment', 'appza-core' ) : __( 'Post', 'appza-core' );

		$author = isset( $content['xprofile'] ) && is_array( $content['xprofile'] ) ? $content['xprofile'] : array();
		$row['author_name']   = isset( $author['display_name'] ) ? (string) $author['display_name'] : '';
		$row['author_avatar'] = isset( $author['avatar'] ) ? (string) $author['avatar'] : '';
		$row['content_created_at_relative'] = ! empty( $content['created_at'] ) && is_string( $content['created_at'] )
			? $this->relative_time( $content['created_at'] )
			: '';

		$space = isset( $post['space'] ) && is_array( $post['space'] ) ? $post['space'] : array();
		$row['space_title'] = isset( $space['title'] ) ? (string) $space['title'] : '';

		// Plain text from the raw message: the card draws a line of words,
		// and FluentCommunity's own `title` here is a 160-character human
		// excerpt that the review modal's Details tab already uses.
		$plain = isset( $content['message'] ) ? wp_strip_all_tags( (string) $content['message'] ) : '';
		$plain = trim( (string) preg_replace( '/\s+/u', ' ', $plain ) );
		$row['excerpt'] = mb_strlen( $plain ) > 140 ? rtrim( mb_substr( $plain, 0, 140 ) ) . '…' : $plain;
		$row['content_body_html'] = isset( $content['message_rendered'] ) ? (string) $content['message_rendered'] : '';

		// The review modal's Details box (designs 9, 26, 31): a bold title
		// over the words. Only a post has one — FluentCommunity fills it
		// with an excerpt when the author wrote none, as its portal shows.
		$row['content_title'] = ! $is_comment && isset( $post['title'] ) ? (string) $post['title'] : '';

		// Design 7's clapperboard under the words. A post's media is its
		// oEmbed preview, its featured image or its uploaded media; a comment
		// carries only the first and the last.
		$meta = isset( $content['meta'] ) && is_array( $content['meta'] ) ? $content['meta'] : array();
		$row['has_media'] = ! empty( $meta['media_preview'] ) || ! empty( $meta['media_items'] ) || ! empty( $content['featured_image'] );
		// Design 9's picture under the words: the oEmbed's thumbnail, the
		// featured image, or the first uploaded item — whichever exists.
		$media_url = '';
		if ( isset( $meta['media_preview']['image'] ) && is_string( $meta['media_preview']['image'] ) ) {
			$media_url = $meta['media_preview']['image'];
		} elseif ( ! empty( $content['featured_image'] ) && is_string( $content['featured_image'] ) ) {
			$media_url = $content['featured_image'];
		} elseif ( isset( $meta['media_items'][0]['url'] ) && is_string( $meta['media_items'][0]['url'] ) ) {
			$media_url = $meta['media_items'][0]['url'];
		}
		$row['content_media_url'] = $media_url;

		// Design 7's chip. `reports_count` on the row is FluentCommunity's
		// count for that content at the time this report was filed.
		$count = isset( $row['reports_count'] ) ? max( 1, (int) $row['reports_count'] ) : 1;
		/* translators: %d: number of reports on the content */
		$row['reports_count_label'] = sprintf( _n( '%d report', '%d reports', $count, 'appza-core' ), $count );

		$row['content_status']  = isset( $content['status'] ) ? (string) $content['status'] : '';
		$row['is_content_live'] = 'published' === $row['content_status'];
		$row['is_auto_flagged'] = $is_auto
			|| ( isset( $row['meta']['flagged_by'] ) && 'auto' === $row['meta']['flagged_by'] );

		// Which rows and buttons a report offers, per tab — Noor's designs.
		// Published as POSITIVE booleans because a menu row's visible_when
		// is a truthiness test with AND and no OR or negation (P159), and
		// the modal reads the same fields so the two can never disagree
		// with the table in .scratch/moderation/plan.md:
		//
		//   status        menu (8, 16, 25, 30)            modal (9, 17, 26, 31)
		//   pending       dismiss, unpublish              dismiss, unpublish
		//   flagged       approve, publish, delete        dismiss, unpublish
		//   ignored       unpublish, delete               unpublish, delete
		//   unpublished   approve, delete                 publish, delete
		//
		// Review is on every row, so it has no field. Delete removes the
		// REPORT and leaves the content (Noor, 2026-09-17).
		$status = isset( $row['status'] ) ? (string) $row['status'] : '';
		$row['menu_can_dismiss']     = 'pending' === $status;
		$row['menu_can_approve']     = in_array( $status, array( 'flagged', 'unpublished' ), true );
		$row['menu_can_publish']     = 'flagged' === $status;
		$row['menu_can_unpublish']   = in_array( $status, array( 'pending', 'ignored' ), true );
		$row['menu_can_delete']      = in_array( $status, array( 'flagged', 'ignored', 'unpublished' ), true );
		$row['review_can_dismiss']   = in_array( $status, array( 'pending', 'flagged' ), true );
		$row['review_can_unpublish'] = in_array( $status, array( 'pending', 'flagged', 'ignored' ), true );
		$row['review_can_publish']   = 'unpublished' === $status;
		$row['review_can_delete']    = in_array( $status, array( 'ignored', 'unpublished' ), true );

		return $row;
	}

	/**
	 * @return array<string, string>
	 */
	protected function report_reasons() {
		$helper = '\\FluentCommunity\\App\\Services\\Helper';
		if ( ! class_exists( $helper ) || ! method_exists( $helper, 'getReportReasons' ) ) {
			return array();
		}
		$reasons = call_user_func( array( $helper, 'getReportReasons' ) );
		return is_array( $reasons ) ? $reasons : array();
	}

	/**
	 * The same short form the post card uses, so "now" beside a report
	 * reads like "now" beside the post it is about.
	 */
	protected function relative_time( $datetime_str ) {
		try {
			$then = new \DateTime( $datetime_str, new \DateTimeZone( 'UTC' ) );
		} catch ( \Exception $e ) {
			return $datetime_str;
		}
		$diff = time() - $then->getTimestamp();
		if ( $diff < 0 ) {
			return $datetime_str;
		}
		if ( $diff < 60 ) {
			return __( 'now', 'appza-core' );
		}
		if ( $diff < 3600 ) {
			return (int) floor( $diff / 60 ) . 'm';
		}
		if ( $diff < 86400 ) {
			return (int) floor( $diff / 3600 ) . 'h';
		}
		if ( $diff < 2592000 ) {
			return (int) floor( $diff / 86400 ) . 'd';
		}
		if ( $diff < 31536000 ) {
			return (int) floor( $diff / 2592000 ) . 'mo';
		}
		return (int) floor( $diff / 31536000 ) . 'y';
	}
}
