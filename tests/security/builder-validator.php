<?php
// D110 validator rules against a synthetic snapshot: no writes, so in-process is safe. Run by builder.sh.
use AppzaCore\Plugin\Services\ScreenLayout;

$ss = function ( $id, $slug, $props, $parent = null, $tags = null ) {
	return array( 'id' => $id, 'slug' => $slug, 'parent_superstructure_id' => $parent, 'properties_schema' => $props, 'tags' => $tags );
};
$snapshot = array(
	'template'         => array( 'appbar_placements' => array( array( 'appzet_slug' => 'bar' ) ), 'nav_placements' => array() ),
	'chrome_palette'   => array( 'appbar' => array( 'bar' ), 'nav' => array( 'nav' ), 'drawer' => array() ),
	'template_screens' => array(
		array(
			'id' => 1, 'app_map_screen_slug' => 's', 'position' => 1, 'show_in_drawer' => true, 'drawer_label' => 'S',
			'placements' => array( array( 'slot' => 'main', 'appzet_slug' => 'card' ), array( 'slot' => 'footer', 'appzet_slug' => 'foot' ) ),
			'palette'    => array( 'card', 'foot', 'banner' ),
		),
		array( 'id' => 2, 'app_map_screen_slug' => 'hidden', 'show_in_drawer' => false, 'placements' => array() ),
	),
	'superstructures'  => array(
		$ss( 1, 'card-ss', array(
			array( 'name' => 'title', 'type' => 'string' ),
			array( 'name' => 'cta_link', 'type' => 'string' ),
			array( 'name' => 'color', 'type' => 'color' ),
			array( 'name' => 'gap', 'type' => 'size' ),
			array( 'name' => 'shown', 'type' => 'boolean' ),
			array( 'name' => 'align', 'type' => 'select', 'options' => array( array( 'label' => 'L', 'value' => 'left' ) ) ),
			array( 'name' => 'tabs', 'type' => 'array_of_objects' ),
			array( 'name' => 'secret', 'type' => 'string', 'exposed_to_builder' => false ),
		), null, array( 'single' ) ),
		$ss( 2, 'card-compact', array( array( 'name' => 'title', 'type' => 'string' ) ), 1 ),
		$ss( 3, 'banner-ss', array() ),
		$ss( 4, 'foot-ss', array() ),
	),
	'appzets'          => array(
		array( 'slug' => 'card', 'superstructure_id' => 1, 'layouts' => array( array( 'slug' => 'card-ss' ), array( 'slug' => 'card-compact' ) ) ),
		array( 'slug' => 'banner', 'superstructure_id' => 3 ),
		array( 'slug' => 'foot', 'superstructure_id' => 4 ),
		array( 'slug' => 'bar', 'superstructure_id' => 3 ),
		array( 'slug' => 'nav', 'superstructure_id' => 3 ),
	),
);

$l      = new ScreenLayout( $snapshot );
$screen = $l->screen( 's' );
$fails  = 0;
$uuid   = function ( $n ) {
	return sprintf( 'site-00000000-0000-4000-8000-%012d', $n );
};
$check  = function ( $name, $ok ) use ( &$fails ) {
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . "\n";
	$fails += $ok ? 0 : 1;
};
$v = function ( $items, $part = 'body', $scr = true ) use ( $l, $screen ) {
	return $l->validate( $part, $items, $scr ? $screen : null );
};

list( $c, $e ) = $v( array( array( 'key' => 'card', 'layout' => 'card-compact' ) ) );
$check( 'a sibling layout is kept', ! $e && 'card-compact' === $c[0]['layout'] );
list( $c, $e ) = $v( array( array( 'key' => 'card', 'layout' => 'card-ss' ) ) );
$check( 'the own layout is stored as no layout', ! $e && ! isset( $c[0]['layout'] ) );
list( , $e ) = $v( array( array( 'key' => 'card', 'layout' => 'banner-ss' ) ) );
$check( 'a foreign layout is refused', isset( $e['items.0.layout'] ) );
list( , $e ) = $v( array( array( 'key' => 'card' ), array( 'key' => $uuid( 1 ), 'appzet_slug' => 'card' ) ) );
$check( 'a single AppZet twice is refused', isset( $e['items'] ) );
list( $c, $e ) = $v( array( array( 'key' => $uuid( 1 ), 'appzet_slug' => 'banner' ), array( 'key' => $uuid( 2 ), 'appzet_slug' => 'banner' ) ) );
$check( 'a non-single AppZet may repeat', ! $e && 2 === count( $c ) );
list( $c, ) = $v( array( array( 'key' => $uuid( 1 ), 'appzet_slug' => 'banner', 'slot' => 'footer' ) ) );
$check( 'a site row is always main', 'main' === $c[0]['slot'] );
list( , $e ) = $v( array( array( 'key' => 'card', 'props_override' => array( 'cta_link' => 'javascript:alert(1)' ) ) ) );
$check( 'a javascript: link is refused', isset( $e['items.0.props_override.cta_link'] ) );
list( , $e ) = $v( array( array( 'key' => 'card', 'props_override' => array( 'cta_link' => 'data:text/html,x' ) ) ) );
$check( 'a data: link is refused', isset( $e['items.0.props_override.cta_link'] ) );
list( $c, $e ) = $v( array( array( 'key' => 'card', 'props_override' => array( 'cta_link' => 'https://example.com/a?b=1' ) ) ) );
$check( 'an https link is kept', ! $e && 'https://example.com/a?b=1' === $c[0]['props_override']['cta_link'] );
list( $c, ) = $v( array( array( 'key' => 'card', 'props_override' => array( 'title' => 'Tom & Jerry' ) ) ) );
$check( 'plain text keeps its ampersand', 'Tom & Jerry' === $c[0]['props_override']['title'] );
list( $c, ) = $v( array( array( 'key' => 'card', 'props_override' => array( 'title' => 'Hi <script>x()</script><b>b</b>' ) ) ) );
$check( 'markup is filtered', false === strpos( $c[0]['props_override']['title'], '<script' ) );
list( , $e ) = $v( array( array( 'key' => 'card', 'props_override' => array( 'color' => 'red;background:url(x)' ) ) ) );
$check( 'a non-colour is refused', isset( $e['items.0.props_override.color'] ) );
list( , $e ) = $v( array( array( 'key' => 'card', 'props_override' => array( 'gap' => '10px', 'shown' => false, 'align' => 'left', 'color' => '#fff' ) ) ) );
$check( 'valid size, boolean, select, colour pass', ! $e );
list( , $e ) = $v( array( array( 'key' => 'card', 'props_override' => array( 'align' => 'middle' ) ) ) );
$check( 'a select value outside its options is refused', isset( $e['items.0.props_override.align'] ) );
list( , $e ) = $v( array( array( 'key' => 'card', 'props_override' => array( 'secret' => 'x' ) ) ) );
$check( 'a non-exposed prop is refused', isset( $e['items.0.props_override.secret'] ) );
list( , $e ) = $v( array( array( 'key' => 'card', 'props_override' => array( 'tabs' => array( array( 'label' => 'A', 'on_tap_action' => 'x' ) ) ) ) ) );
$check( 'a row naming an action is refused', isset( $e['items.0.props_override.tabs'] ) );
list( , $e ) = $v( array( array( 'key' => 'card', 'props_override' => array( 'spacing' => array( 'margin_v' => 4, 'border_color' => '#000' ) ) ) ) );
$check( 'spacing passes', ! $e );
list( $c, ) = $v( array( array( 'key' => 'card', 'visibility' => 'signed_out', 'visible_when' => array( 'binding' => 'state.x' ) ) ) );
$check( 'a preset maps to its clause; a raw clause is ignored', false === $c[0]['visible_when']['value'] );
list( $c, ) = $v( array( array( 'key' => 'card', 'visibility' => 'always' ) ) );
$check( '"always" clears the gate', array_key_exists( 'visible_when', $c[0] ) && null === $c[0]['visible_when'] );
list( , $e ) = $v( array( array( 'key' => 'bar' ) ), 'appbar', false );
$check( 'a chrome Core key is accepted', ! $e );
list( , $e ) = $v( array( array( 'key' => $uuid( 1 ), 'appzet_slug' => 'nav' ), array( 'key' => $uuid( 2 ), 'appzet_slug' => 'nav' ) ), 'nav', false );
$check( 'nav holds one entry', isset( $e['items'] ) );
list( $c, $e ) = $v( array( array( 'screen_slug' => 's', 'hidden' => true, 'label' => ' <b>Home</b> ', 'icon' => 'home' ) ), 'drawer-menu', false );
$check( 'a drawer row is cleaned', ! $e && 'Home' === $c[0]['label'] && true === $c[0]['hidden'] );
list( , $e ) = $v( array( array( 'screen_slug' => 'hidden' ) ), 'drawer-menu', false );
$check( 'a screen outside the menu is refused', isset( $e['items.0.screen_slug'] ) );
list( , $e ) = $v( array( array( 'screen_slug' => 's', 'icon' => 'x" onload="y' ) ), 'drawer-menu', false );
$check( 'a bad icon name is refused', isset( $e['items.0.icon'] ) );
$check( 'Core keys are derived like the renderer', array( 'card', 'foot' ) === array_column( $l->core_items( 'body', $screen ), 'key' ) );

echo wp_json_encode( array( 'fails' => $fails ) ) . "\n";
