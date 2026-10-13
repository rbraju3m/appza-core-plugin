<?php
namespace AppzaCore\Plugin\Repository;

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * The simulator's Design tab index, one per template, as pulled from Core at sync. Kept in options that never
 * autoload: only the admin's Design tab reads it, and it is a few hundred KB.
 */
class DesignIndexRepository {

	const PREFIX = 'appza_design_index_';

	public function find( $template_slug ) {
		$index = get_option( self::PREFIX . $template_slug, null );
		return is_array( $index ) ? $index : null;
	}

	public function etag( $template_slug ) {
		return (string) get_option( self::PREFIX . 'etag_' . $template_slug, '' );
	}

	public function save( $template_slug, array $index, $etag ) {
		update_option( self::PREFIX . $template_slug, $index, false );
		update_option( self::PREFIX . 'etag_' . $template_slug, (string) $etag, false );
	}
}
