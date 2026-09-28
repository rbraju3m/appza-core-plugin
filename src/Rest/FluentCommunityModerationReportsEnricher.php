<?php
/**
 * Adds headline, card and menu fields to moderation report rows so the shells bind values.
 * FC's own rule: no reporter and an unlabelled reason means an auto-flag, because the auto
 * reasons are deliberately absent from Helper::getReportReasons().
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
			$row['headline'] = __( 'Flagged automatically by the site rule', 'appza-core' );
		} else {
			// "By System" is FC's wording for a labelled system report.
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
		$row['reporter_avatar'] = $reporter && isset( $reporter['avatar'] ) && is_string( $reporter['avatar'] ) ? $reporter['avatar'] : '';
		if ( ! empty( $row['created_at'] ) && is_string( $row['created_at'] ) ) {
			$row['created_at_relative'] = $this->relative_time( $row['created_at'] );
		}

		return $this->add_content_fields( $row, '' === $who && '' === $label );
	}

	/**
	 * Lifts card fields from `comment` or `post`, whichever the report is about.
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

		$plain = isset( $content['message'] ) ? wp_strip_all_tags( (string) $content['message'] ) : '';
		$plain = trim( (string) preg_replace( '/\s+/u', ' ', $plain ) );
		$row['excerpt'] = mb_strlen( $plain ) > 140 ? rtrim( mb_substr( $plain, 0, 140 ) ) . '…' : $plain;
		$row['content_body_html'] = isset( $content['message_rendered'] ) ? (string) $content['message_rendered'] : '';

		$row['content_title'] = ! $is_comment && isset( $post['title'] ) ? (string) $post['title'] : '';

		$meta = isset( $content['meta'] ) && is_array( $content['meta'] ) ? $content['meta'] : array();
		$row['has_media'] = ! empty( $meta['media_preview'] ) || ! empty( $meta['media_items'] ) || ! empty( $content['featured_image'] );
		$media_url = '';
		if ( isset( $meta['media_preview']['image'] ) && is_string( $meta['media_preview']['image'] ) ) {
			$media_url = $meta['media_preview']['image'];
		} elseif ( ! empty( $content['featured_image'] ) && is_string( $content['featured_image'] ) ) {
			$media_url = $content['featured_image'];
		} elseif ( isset( $meta['media_items'][0]['url'] ) && is_string( $meta['media_items'][0]['url'] ) ) {
			$media_url = $meta['media_items'][0]['url'];
		}
		$row['content_media_url'] = $media_url;

		$count = isset( $row['reports_count'] ) ? max( 1, (int) $row['reports_count'] ) : 1;
		/* translators: %d: number of reports on the content */
		$row['reports_count_label'] = sprintf( _n( '%d report', '%d reports', $count, 'appza-core' ), $count );

		$row['content_status']  = isset( $content['status'] ) ? (string) $content['status'] : '';
		$row['is_content_live'] = 'published' === $row['content_status'];
		$row['is_auto_flagged'] = $is_auto
			|| ( isset( $row['meta']['flagged_by'] ) && 'auto' === $row['meta']['flagged_by'] );

		// Positive booleans: visible_when is AND-only truthiness, no OR or negation.
		// Delete removes the REPORT, not the content.
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

	/** @return array<string, string> */
	protected function report_reasons() {
		$helper = '\\FluentCommunity\\App\\Services\\Helper';
		if ( ! class_exists( $helper ) || ! method_exists( $helper, 'getReportReasons' ) ) {
			return array();
		}
		$reasons = call_user_func( array( $helper, 'getReportReasons' ) );
		return is_array( $reasons ) ? $reasons : array();
	}

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
