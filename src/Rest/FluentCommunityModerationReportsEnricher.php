<?php
/**
 * Hooks `rest_request_after_callbacks` to give each moderation report row
 * the three strings the review modal draws (Noor's design 30), so the
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
		if ( ! empty( $row['created_at'] ) && is_string( $row['created_at'] ) ) {
			$row['created_at_relative'] = $this->relative_time( $row['created_at'] );
		}

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
