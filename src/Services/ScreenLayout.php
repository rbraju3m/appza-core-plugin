<?php
/**
 * The builder's site layouts (D110): what Core placed, what the site may place, and the
 * validation of a saved list. Everything is checked against the synced snapshot.
 */

namespace AppzaCore\Plugin\Services;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class ScreenLayout {

	const MAX_ITEMS     = 50;
	const MAX_STRING    = 5000;
	const SITE_KEY      = '/^site-[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';
	const SCREEN_PARTS  = array( 'body' => 'placements', 'appbar' => 'appbar_placements' );
	const CHROME_PARTS  = array(
		'appbar'      => 'appbar_placements',
		'nav'         => 'nav_placements',
		'drawer'      => 'drawer_placements',
		'drawer-menu' => 'drawer_menu',
	);
	const LAYOUT_COLUMNS = array( 'placements', 'appbar_placements', 'nav_placements', 'drawer_placements', 'drawer_menu' );
	const ONE_ONLY_PARTS = array( 'appbar', 'nav' );

	// The only gates a site may set; the client sends the id, never a binding.
	const VISIBILITY = array(
		'always'     => null,
		'signed_in'  => array( 'binding' => 'state.viewer.is_logged_in', 'op' => '==', 'value' => true ),
		'signed_out' => array( 'binding' => 'state.viewer.is_logged_in', 'op' => '==', 'value' => false ),
	);

	protected $snapshot;
	protected $appzets    = array();
	protected $ss_by_id   = array();
	protected $ss_by_slug = array();

	public function __construct( array $snapshot ) {
		$this->snapshot = $snapshot;
		foreach ( (array) ( $snapshot['appzets'] ?? array() ) as $a ) {
			if ( is_array( $a ) && isset( $a['slug'] ) ) {
				$this->appzets[ $a['slug'] ] = $a;
			}
		}
		foreach ( (array) ( $snapshot['superstructures'] ?? array() ) as $ss ) {
			if ( is_array( $ss ) && isset( $ss['id'], $ss['slug'] ) ) {
				$this->ss_by_id[ (int) $ss['id'] ] = $ss;
				$this->ss_by_slug[ $ss['slug'] ]   = $ss;
			}
		}
	}

	public function screen( $screen_slug ) {
		foreach ( (array) ( $this->snapshot['template_screens'] ?? array() ) as $s ) {
			if ( is_array( $s ) && ( $s['app_map_screen_slug'] ?? null ) === $screen_slug ) {
				return $s;
			}
		}
		return null;
	}

	/** Core's list for a part, with the keys the renderer derives (`slug`, `slug#2`, …). */
	public function core_items( $part, $screen = null ) {
		if ( 'drawer-menu' === $part ) {
			return $this->core_drawer_menu();
		}
		$column = null !== $screen ? self::SCREEN_PARTS[ $part ] : self::CHROME_PARTS[ $part ];
		$raw    = null !== $screen ? ( $screen[ $column ] ?? array() ) : ( $this->snapshot['template'][ $column ] ?? array() );
		$seen   = array();
		$out    = array();
		foreach ( (array) $raw as $p ) {
			if ( ! is_array( $p ) || empty( $p['appzet_slug'] ) || ! is_string( $p['appzet_slug'] ) ) {
				continue;
			}
			$slug          = $p['appzet_slug'];
			$seen[ $slug ] = ( $seen[ $slug ] ?? 0 ) + 1;
			$out[]         = array_merge( $p, array( 'key' => 1 === $seen[ $slug ] ? $slug : $slug . '#' . $seen[ $slug ] ) );
		}
		return $out;
	}

	protected function core_drawer_menu() {
		$screens = array_filter(
			(array) ( $this->snapshot['template_screens'] ?? array() ),
			function ( $s ) {
				return is_array( $s ) && false !== ( $s['show_in_drawer'] ?? true ) && 'none' !== ( $s['surface'] ?? 'both' );
			}
		);
		usort(
			$screens,
			function ( $a, $b ) {
				$ao = $a['drawer_position'] ?? ( $a['position'] ?? 0 );
				$bo = $b['drawer_position'] ?? ( $b['position'] ?? 0 );
				return $ao === $bo ? ( (int) $a['id'] - (int) $b['id'] ) : ( $ao - $bo );
			}
		);
		return array_values(
			array_map(
				function ( $s ) {
					return array(
						'screen_slug' => $s['app_map_screen_slug'],
						'label'       => $s['drawer_label'] ?? null,
						'icon'        => $s['drawer_icon'] ?? null,
					);
				},
				$screens
			)
		);
	}

	public function core_hash( array $core_items ) {
		return sha1( wp_json_encode( $core_items ) );
	}

	public function palette( $part, $screen = null ) {
		if ( null !== $screen ) {
			return array_values( (array) ( 'body' === $part ? ( $screen['palette'] ?? array() ) : ( $screen['appbar_palette'] ?? array() ) ) );
		}
		return array_values( (array) ( $this->snapshot['chrome_palette'][ $part ] ?? array() ) );
	}

	/**
	 * @return array{0: array, 1: array} [clean items, errors as path => message]
	 */
	public function validate( $part, $items, $screen = null ) {
		$errors = array();
		if ( ! is_array( $items ) || ( $items && array_keys( $items ) !== range( 0, count( $items ) - 1 ) ) ) {
			return array( array(), array( 'items' => 'must be a list' ) );
		}
		if ( count( $items ) > self::MAX_ITEMS ) {
			return array( array(), array( 'items' => sprintf( 'at most %d entries', self::MAX_ITEMS ) ) );
		}
		if ( 'drawer-menu' === $part ) {
			return $this->validate_drawer_menu( $items );
		}
		if ( in_array( $part, self::ONE_ONLY_PARTS, true ) && count( $items ) > 1 ) {
			return array( array(), array( 'items' => 'at most one entry' ) );
		}

		$core = array();
		foreach ( $this->core_items( $part, $screen ) as $c ) {
			$core[ $c['key'] ] = $c;
		}
		$palette = $this->palette( $part, $screen );
		$clean   = array();
		$keys    = array();
		$counts  = array();

		foreach ( $items as $i => $item ) {
			$path = "items.$i";
			if ( ! is_array( $item ) || ! isset( $item['key'] ) || ! is_string( $item['key'] ) || '' === $item['key'] ) {
				$errors[ "$path.key" ] = 'required';
				continue;
			}
			$key = $item['key'];
			if ( isset( $keys[ $key ] ) ) {
				$errors[ "$path.key" ] = 'duplicate';
				continue;
			}
			$keys[ $key ] = true;

			if ( isset( $core[ $key ] ) ) {
				$slug = $core[ $key ]['appzet_slug'];
				$out  = array( 'key' => $key );
			} elseif ( preg_match( self::SITE_KEY, $key ) ) {
				$slug = $item['appzet_slug'] ?? null;
				if ( ! is_string( $slug ) || ! in_array( $slug, $palette, true ) || ! isset( $this->appzets[ $slug ] ) ) {
					$errors[ "$path.appzet_slug" ] = 'not placeable here';
					continue;
				}
				$out = array( 'key' => $key, 'appzet_slug' => $slug );
				if ( null !== $screen && 'body' === $part ) {
					$out['slot'] = 'main';
				}
			} else {
				$errors[ "$path.key" ] = 'unknown key';
				continue;
			}
			$counts[ $slug ] = ( $counts[ $slug ] ?? 0 ) + 1;

			if ( isset( $item['layout'] ) && null !== $item['layout'] ) {
				if ( ! in_array( $item['layout'], $this->layouts_of( $slug ), true ) ) {
					$errors[ "$path.layout" ] = 'not a layout of ' . $slug;
				} elseif ( $item['layout'] !== $this->own_layout( $slug ) ) {
					$out['layout'] = $item['layout'];
				}
			}

			if ( isset( $item['visibility'] ) ) {
				if ( ! is_string( $item['visibility'] ) || ! array_key_exists( $item['visibility'], self::VISIBILITY ) ) {
					$errors[ "$path.visibility" ] = 'unknown preset';
				} else {
					$out['visible_when'] = self::VISIBILITY[ $item['visibility'] ];
				}
			}

			if ( isset( $item['props_override'] ) ) {
				list( $props, $prop_errors ) = $this->validate_props( $slug, $out['layout'] ?? null, $item['props_override'], "$path.props_override" );
				$errors = array_merge( $errors, $prop_errors );
				if ( $props ) {
					$out['props_override'] = $props;
				}
			}
			$clean[] = $out;
		}

		foreach ( $counts as $slug => $n ) {
			if ( $n > 1 && in_array( 'single', $this->tags_of( $slug ), true ) ) {
				$errors['items'] = "$slug may appear once per screen";
			}
		}
		return array( $clean, $errors );
	}

	protected function validate_drawer_menu( array $items ) {
		$allowed = array();
		foreach ( $this->core_drawer_menu() as $row ) {
			$allowed[ $row['screen_slug'] ] = true;
		}
		$errors = array();
		$clean  = array();
		$seen   = array();
		foreach ( $items as $i => $item ) {
			$path = "items.$i";
			$slug = is_array( $item ) ? ( $item['screen_slug'] ?? null ) : null;
			if ( ! is_string( $slug ) || ! isset( $allowed[ $slug ] ) ) {
				$errors[ "$path.screen_slug" ] = 'not a menu screen';
				continue;
			}
			if ( isset( $seen[ $slug ] ) ) {
				$errors[ "$path.screen_slug" ] = 'duplicate';
				continue;
			}
			$seen[ $slug ] = true;
			$out           = array( 'screen_slug' => $slug );
			if ( ! empty( $item['hidden'] ) ) {
				$out['hidden'] = true;
			}
			if ( isset( $item['label'] ) && '' !== $item['label'] ) {
				if ( ! is_string( $item['label'] ) || mb_strlen( $item['label'] ) > 60 ) {
					$errors[ "$path.label" ] = 'at most 60 characters';
				} else {
					$out['label'] = sanitize_text_field( $item['label'] );
				}
			}
			if ( isset( $item['icon'] ) && '' !== $item['icon'] ) {
				if ( ! is_string( $item['icon'] ) || ! preg_match( '/^[a-z0-9-]{1,60}$/', $item['icon'] ) ) {
					$errors[ "$path.icon" ] = 'not an icon name';
				} else {
					$out['icon'] = $item['icon'];
				}
			}
			$clean[] = $out;
		}
		return array( $clean, $errors );
	}

	protected function validate_props( $slug, $layout, $props, $path ) {
		if ( ! is_array( $props ) || ( $props && array_keys( $props ) === range( 0, count( $props ) - 1 ) ) ) {
			return array( array(), array( $path => 'must be an object' ) );
		}
		$schema = array();
		$ss     = null !== $layout ? ( $this->ss_by_slug[ $layout ] ?? null ) : $this->own_ss( $slug );
		foreach ( (array) ( $ss['properties_schema'] ?? array() ) as $p ) {
			if ( is_array( $p ) && isset( $p['name'] ) ) {
				$schema[ $p['name'] ] = $p;
			}
		}
		$errors = array();
		$clean  = array();
		foreach ( $props as $name => $value ) {
			$at = "$path.$name";
			if ( 'spacing' === $name ) {
				$v = $this->clean_spacing( $value );
			} elseif ( ! isset( $schema[ $name ] ) || false === ( $schema[ $name ]['exposed_to_builder'] ?? true ) ) {
				$errors[ $at ] = 'not an editable prop';
				continue;
			} elseif ( preg_match( '/(action_slug|_action|data_source_slug)$/', $name ) ) {
				$errors[ $at ] = 'not editable here';
				continue;
			} else {
				$v = $this->clean_value( $schema[ $name ], $name, $value );
			}
			if ( $v instanceof \WP_Error ) {
				$errors[ $at ] = $v->get_error_message();
				continue;
			}
			$clean[ $name ] = $v;
		}
		return array( $clean, $errors );
	}

	protected function clean_value( array $prop, $name, $value, $depth = 0 ) {
		$type = $prop['type'] ?? 'string';
		switch ( $type ) {
			case 'boolean':
				return is_bool( $value ) ? $value : new \WP_Error( 'type', 'must be true or false' );
			case 'number':
				return is_int( $value ) || is_float( $value ) ? $value : new \WP_Error( 'type', 'must be a number' );
			case 'color':
				return $this->is_color( $value ) ? $value : new \WP_Error( 'type', 'must be a colour' );
			case 'size':
				if ( is_int( $value ) || is_float( $value ) ) {
					return $value;
				}
				return is_string( $value ) && preg_match( '/^(auto|-?\d+(\.\d+)?(px|rem|em|%|vh|vw)?)$/', $value ) ? $value : new \WP_Error( 'type', 'must be a size' );
			case 'select':
			case 'enum':
				return in_array( $value, $this->option_values( $prop ), true ) ? $value : new \WP_Error( 'type', 'not one of the options' );
			case 'array_of_objects':
				return $this->clean_rows( $value, $depth );
			case 'string':
				return $this->clean_string( $name, $value );
			default:
				return new \WP_Error( 'type', 'not editable here' );
		}
	}

	protected function clean_rows( $value, $depth ) {
		if ( ! is_array( $value ) || count( $value ) > self::MAX_ITEMS || $depth > 0 ) {
			return new \WP_Error( 'type', 'must be a short list' );
		}
		$out = array();
		foreach ( array_values( $value ) as $row ) {
			if ( ! is_array( $row ) ) {
				return new \WP_Error( 'type', 'each row must be an object' );
			}
			$clean = array();
			foreach ( $row as $k => $v ) {
				if ( ! is_string( $k ) || preg_match( '/(action_slug|_action)$/', $k ) ) {
					return new \WP_Error( 'type', 'rows cannot name actions' );
				}
				if ( is_string( $v ) ) {
					$v = $this->clean_string( $k, $v );
				} elseif ( ! is_bool( $v ) && ! is_int( $v ) && ! is_float( $v ) && null !== $v ) {
					return new \WP_Error( 'type', 'row values must be plain' );
				}
				if ( $v instanceof \WP_Error ) {
					return $v;
				}
				$clean[ $k ] = $v;
			}
			$out[] = $clean;
		}
		return $out;
	}

	protected function clean_string( $name, $value ) {
		if ( ! is_string( $value ) || strlen( $value ) > self::MAX_STRING ) {
			return new \WP_Error( 'type', 'must be text' );
		}
		if ( preg_match( '/(url|link|href|src)$/', $name ) && '' !== $value ) {
			$scheme = wp_parse_url( $value, PHP_URL_SCHEME );
			if ( null !== $scheme && false !== $scheme && ! in_array( strtolower( $scheme ), array( 'http', 'https' ), true ) ) {
				return new \WP_Error( 'type', 'only http(s) links' );
			}
			return esc_url_raw( $value, array( 'http', 'https' ) ) ?: ( 0 === strpos( $value, '/' ) ? $value : new \WP_Error( 'type', 'not a link' ) );
		}
		// React prints text as text; only markup needs filtering, and kses would turn a bare & into &amp;.
		return false === strpos( $value, '<' ) ? $value : wp_kses_post( $value );
	}

	protected function clean_spacing( $value ) {
		if ( ! is_array( $value ) ) {
			return new \WP_Error( 'type', 'must be an object' );
		}
		$out = array();
		foreach ( $value as $k => $v ) {
			if ( 'border_color' === $k ) {
				if ( ! $this->is_color( $v ) ) {
					return new \WP_Error( 'type', 'border_color must be a colour' );
				}
				$out[ $k ] = $v;
			} elseif ( in_array( $k, array( 'margin_h', 'margin_v', 'padding_h', 'padding_v', 'border_radius', 'border_width' ), true ) ) {
				if ( ! is_int( $v ) && ! is_float( $v ) ) {
					return new \WP_Error( 'type', "$k must be a number" );
				}
				$out[ $k ] = $v;
			} else {
				return new \WP_Error( 'type', "unknown spacing field $k" );
			}
		}
		return $out;
	}

	protected function is_color( $value ) {
		return is_string( $value ) && preg_match( '/^(#[0-9a-fA-F]{3,8}|transparent|rgba?\(\s*[\d.\s,%]+\))$/', $value );
	}

	protected function option_values( array $prop ) {
		$values = array();
		foreach ( (array) ( $prop['options'] ?? ( $prop['enum_values'] ?? array() ) ) as $o ) {
			$values[] = is_array( $o ) ? ( $o['value'] ?? null ) : $o;
		}
		return $values;
	}

	protected function own_ss( $slug ) {
		$id = (int) ( $this->appzets[ $slug ]['superstructure_id'] ?? 0 );
		return $this->ss_by_id[ $id ] ?? null;
	}

	protected function own_layout( $slug ) {
		$ss = $this->own_ss( $slug );
		return $ss['slug'] ?? null;
	}

	public function layouts_of( $slug ) {
		$layouts = array();
		foreach ( (array) ( $this->appzets[ $slug ]['layouts'] ?? array() ) as $l ) {
			if ( is_array( $l ) && isset( $l['slug'] ) ) {
				$layouts[] = $l['slug'];
			}
		}
		return $layouts;
	}

	protected function tags_of( $slug ) {
		$tags = $this->appzets[ $slug ]['tags'] ?? null;
		if ( ! is_array( $tags ) ) {
			$tags = $this->own_ss( $slug )['tags'] ?? array();
		}
		return is_array( $tags ) ? $tags : array();
	}
}
