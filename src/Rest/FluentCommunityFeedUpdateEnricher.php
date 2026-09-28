<?php
/**
 * FC matches submitted media URLs against the DB byte-for-byte, so host-rewritten URLs
 * deactivate every image on edit. FC reads php://input, so the request can't be fixed
 * pre-dispatch; we restore the submitted rows after FC's controller returns.
 */

namespace AppzaCore\Plugin\Rest;

use FluentCommunity\App\Models\Media;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class FluentCommunityFeedUpdateEnricher {

	const ROUTE = '/fcom-mobile/api/v1/feeds/update';

	/** @var array<int, array<int, string>> feed id => submitted URL paths */
	protected $pendingByFeedId = [];

	public function register_hooks() {
		add_filter( 'rest_pre_dispatch', [ $this, 'capture_submitted_paths' ], 10, 3 );
		add_filter( 'rest_request_after_callbacks', [ $this, 'restore_media' ], 10, 3 );
	}

	public function capture_submitted_paths( $result, $server, $request ) {
		if ( ! $request instanceof \WP_REST_Request ) {
			return $result;
		}
		if ( (string) $request->get_route() !== self::ROUTE || ! is_user_logged_in() ) {
			return $result;
		}
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			return $result;
		}
		$feedId = isset( $params['id'] ) ? (int) $params['id'] : 0;
		if ( $feedId <= 0 ) {
			return $result;
		}
		$mediaImages = isset( $params['media_images'] ) ? $params['media_images'] : null;
		if ( ! is_array( $mediaImages ) || empty( $mediaImages ) ) {
			return $result;
		}
		$paths = [];
		foreach ( $mediaImages as $img ) {
			if ( ! is_array( $img ) ) {
				continue;
			}
			$u = isset( $img['url'] ) ? (string) $img['url'] : '';
			if ( $u === '' ) {
				continue;
			}
			$p = $this->urlToPath( $u );
			if ( $p !== '' ) {
				$paths[] = $p;
			}
		}
		if ( ! empty( $paths ) ) {
			$this->pendingByFeedId[ $feedId ] = array_values( array_unique( $paths ) );
		}
		return $result;
	}

	public function restore_media( $response, $handler, $request ) {
		if ( ! $request instanceof \WP_REST_Request ) {
			return $response;
		}
		if ( (string) $request->get_route() !== self::ROUTE ) {
			return $response;
		}
		if ( ! class_exists( Media::class ) ) {
			return $response;
		}
		$params = $request->get_json_params();
		$feedId = ( is_array( $params ) && isset( $params['id'] ) ) ? (int) $params['id'] : 0;
		if ( $feedId <= 0 ) {
			return $response;
		}
		$paths = isset( $this->pendingByFeedId[ $feedId ] ) ? $this->pendingByFeedId[ $feedId ] : [];
		unset( $this->pendingByFeedId[ $feedId ] );
		if ( empty( $paths ) || ! $this->fc_accepted_edit( $response, $feedId ) ) {
			return $response;
		}

		// FC creates duplicate rows when its URL match fails; keep the oldest per path, unlink the rest.
		$rows = Media::where( 'object_source', 'feed' )
			->where( 'feed_id', $feedId )
			->orderBy( 'id' )
			->get();

		$pathSet = array_flip( $paths );
		/** @var array<string, Media> $keepByPath */
		$keepByPath = [];
		$demoteIds  = [];
		foreach ( $rows as $row ) {
			$p = $this->urlToPath( (string) $row->media_url );
			if ( $p === '' || ! isset( $pathSet[ $p ] ) ) {
				continue;
			}
			if ( ! isset( $keepByPath[ $p ] ) ) {
				$keepByPath[ $p ] = $row;
			} else {
				$demoteIds[] = (int) $row->id;
			}
		}

		$reactivateIds = [];
		foreach ( $keepByPath as $row ) {
			if ( (int) $row->is_active !== 1 ) {
				$reactivateIds[] = (int) $row->id;
			}
		}
		if ( ! empty( $reactivateIds ) ) {
			Media::whereIn( 'id', $reactivateIds )->update( [ 'is_active' => 1 ] );
		}
		if ( ! empty( $demoteIds ) ) {
			Media::whereIn( 'id', $demoteIds )->update( [ 'is_active' => 0, 'feed_id' => null ] );
		}

		$keepIds = array_map(
			function ( $row ) {
				return (int) $row->id;
			},
			array_values( $keepByPath )
		);
		$this->sync_feed_media_meta( $feedId, $keepIds );

		if ( $response instanceof \WP_REST_Response ) {
			$data = $response->get_data();
			if ( is_array( $data ) && isset( $data['items'] ) && is_array( $data['items'] ) ) {
				$data['items']['meta']         = $this->build_meta_from_ids( $feedId, $keepIds, $data['items']['meta'] ?? [] );
				$data['items']['content_type'] = $data['items']['content_type'] ?? 'image';
				$response->set_data( $data );
			}
		}

		return $response;
	}

	/** WordPress runs this hook even for refused requests, so write only when FC accepted the edit. */
	protected function fc_accepted_edit( $response, $feedId ) {
		if ( ! $response instanceof \WP_REST_Response || $response->get_status() < 200 || $response->get_status() >= 300 ) {
			return false;
		}
		if ( ! is_user_logged_in() || ! class_exists( '\\FluentCommunity\\App\\Services\\Helper' ) ) {
			return false;
		}
		$feed = \FluentCommunity\App\Models\Feed::find( $feedId );
		$user = \FluentCommunity\App\Services\Helper::getCurrentUser();
		return $feed && $user && $user->canEditFeed( $feed );
	}

	protected function sync_feed_media_meta( $feedId, array $keepIds ) {
		if ( empty( $keepIds ) ) {
			return;
		}
		$feed = \FluentCommunity\App\Models\Feed::find( $feedId );
		if ( ! $feed ) {
			return;
		}
		$meta = is_array( $feed->meta ) ? $feed->meta : [];
		$meta = $this->build_meta_from_ids( $feedId, $keepIds, $meta );
		$feed->meta = $meta;
		$feed->save();
	}

	/** One image uses FC's media_preview shape; several use media_items. */
	protected function build_meta_from_ids( $feedId, array $keepIds, $existingMeta ) {
		if ( ! is_array( $existingMeta ) ) {
			$existingMeta = [];
		}
		if ( empty( $keepIds ) ) {
			return $existingMeta;
		}
		$rows = Media::whereIn( 'id', $keepIds )->orderByRaw( 'FIELD(id, ' . implode( ',', $keepIds ) . ')' )->get();
		if ( count( $rows ) === 1 ) {
			$row = $rows->first();
			$existingMeta['media_preview'] = [
				'is_uploaded' => true,
				'image'       => $row->public_url,
				'type'        => 'meta_data',
				'provider'    => 'uploader',
				'width'       => isset( $row->settings['width'] ) ? $row->settings['width'] : null,
				'height'      => isset( $row->settings['height'] ) ? $row->settings['height'] : null,
				'media_id'    => (int) $row->id,
			];
			$existingMeta['media_preview'] = array_filter( $existingMeta['media_preview'], function ( $v ) {
				return $v !== null;
			} );
			unset( $existingMeta['media_items'] );
		} else {
			$items = [];
			foreach ( $rows as $row ) {
				$items[] = array_filter( [
					'media_id' => (int) $row->id,
					'url'      => (string) $row->public_url,
					'type'     => 'image',
					'width'    => isset( $row->settings['width'] ) ? $row->settings['width'] : null,
					'height'   => isset( $row->settings['height'] ) ? $row->settings['height'] : null,
					'provider' => 'uploader',
				], function ( $v ) {
					return $v !== null;
				} );
			}
			$existingMeta['media_items'] = $items;
			unset( $existingMeta['media_preview'] );
		}
		return $existingMeta;
	}

	/** Path only, so the same file matches whichever host served it. */
	protected function urlToPath( $url ) {
		if ( $url === '' ) {
			return '';
		}
		$path = wp_parse_url( $url, PHP_URL_PATH );
		if ( ! is_string( $path ) || $path === '' ) {
			return '';
		}
		return $path;
	}
}
