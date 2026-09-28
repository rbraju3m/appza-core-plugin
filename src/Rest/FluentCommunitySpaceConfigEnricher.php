<?php
/**
 * Attaches per-space `appza_config` (wp_appza_space_config) to fcom-mobile /spaces/by/slug.
 * Uses rest_request_after_callbacks, not rest_post_dispatch, so the simulator's internal rest_do_request is enriched too.
 */

namespace AppzaCore\Plugin\Rest;

use AppzaCore\Plugin\Schema\SpaceConfigSchema;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class FluentCommunitySpaceConfigEnricher {

	const ROUTE = '/fcom-mobile/api/v1/spaces/by/slug';

	public function register_hooks() {
		add_filter( 'rest_request_after_callbacks', [ $this, 'attach_config' ], 10, 3 );
	}

	/**
	 * @param \WP_REST_Response|\WP_HTTP_Response|\WP_Error|mixed $response
	 */
	public function attach_config( $response, $handler, $request ) {
		if ( ! $response instanceof \WP_REST_Response ) {
			return $response;
		}
		if ( (string) $request->get_route() !== self::ROUTE ) {
			return $response;
		}
		$data = $response->get_data();
		if ( ! is_array( $data ) || ! isset( $data['items'] ) ) {
			return $response;
		}
		$items = $data['items'];

		// fcom-mobile wraps the row in items.space, which the client unwraps.
		if ( is_array( $items ) && isset( $items['space'] ) && is_array( $items['space'] ) ) {
			$items['space'] = $this->enrich_row( $items['space'] );
		} elseif ( is_array( $items ) && ! empty( $items ) && is_int( array_key_first( $items ) ) ) {
			foreach ( $items as $i => $row ) {
				if ( is_array( $row ) ) {
					$items[ $i ] = $this->enrich_row( $row );
				}
			}
		} elseif ( is_array( $items ) ) {
			$items = $this->enrich_row( $items );
		}

		$data['items'] = $items;
		$response->set_data( $data );

		return $response;
	}

	protected function enrich_row( array $row ) {
		$slug = isset( $row['slug'] ) && is_string( $row['slug'] ) ? $row['slug'] : '';
		if ( $slug === '' ) {
			return $row;
		}
		$config = $this->get_config( $slug );
		$row['appza_config'] = $config ?: new \stdClass();
		$row                 = $this->add_media_audio_permission( $row, $slug );
		$row                 = $this->add_chat_permission( $row, $slug );
		return $row;
	}

	/**
	 * FluentMessaging disables (never deletes) the thread when "Group Chat?" is turned off,
	 * so an ACTIVE thread is the toggle. Fails closed: a dead CHAT tab is worse than none.
	 *
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	protected function add_chat_permission( array $row, string $slug ) {
		$permissions = isset( $row['permissions'] ) && is_array( $row['permissions'] ) ? $row['permissions'] : array();

		$permissions['can_view_chat'] = false;
		$row['chat_thread_id']        = 0;

		// FC's hasChatAccess() 403s a non-member on a community thread.
		$is_member = ! empty( $permissions['is_member'] );

		if ( $is_member
			&& class_exists( '\FluentMessaging\App\Services\ChatHelper' )
			&& class_exists( '\FluentCommunity\App\Models\Space' ) ) {
			try {
				$space = \FluentCommunity\App\Models\Space::where( 'slug', $slug )->first();
				if ( $space ) {
					$thread = \FluentMessaging\App\Services\ChatHelper::getChatThread( $space->id );
					if ( $thread && 'active' === $thread->status ) {
						$permissions['can_view_chat'] = true;
						$row['chat_thread_id']        = (int) $thread->id;
					}
				}
			} catch ( \Throwable $e ) {
			}
		}

		$row['permissions'] = $permissions;
		return $row;
	}

	/**
	 * FC Pro's `has_audio` lives on the media envelope, which the preview proxy drops, so it rides here.
	 * Left unset on failure (tab shows); skipped when the gallery is off, since the check is a LIKE scan.
	 *
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	protected function add_media_audio_permission( array $row, string $slug ) {
		$permissions = isset( $row['permissions'] ) && is_array( $row['permissions'] ) ? $row['permissions'] : null;
		if ( null === $permissions || empty( $permissions['can_view_media'] ) ) {
			return $row;
		}
		if ( ! class_exists( '\FluentCommunityPro\App\Modules\MediaGallery\Services\MediaGalleryService' ) ) {
			return $row;
		}
		if ( ! class_exists( '\FluentCommunity\App\Models\Space' ) ) {
			return $row;
		}

		try {
			$space = \FluentCommunity\App\Models\Space::where( 'slug', $slug )->first();
			if ( ! $space ) {
				return $row;
			}
			$permissions['can_view_media_audio'] = (bool) \FluentCommunityPro\App\Modules\MediaGallery\Services\MediaGalleryService::spaceHasAudio( $space );
			$row['permissions']                  = $permissions;
		} catch ( \Throwable $e ) {
		}

		return $row;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	protected function get_config( string $slug ) {
		global $wpdb;
		$table = SpaceConfigSchema::table_name();
		$json  = $wpdb->get_var(
			$wpdb->prepare( "SELECT config_json FROM {$table} WHERE space_slug = %s LIMIT 1", $slug )
		);
		if ( ! is_string( $json ) || $json === '' ) {
			return null;
		}
		$decoded = json_decode( $json, true );
		return is_array( $decoded ) ? $decoded : null;
	}
}
