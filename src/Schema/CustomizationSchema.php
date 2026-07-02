<?php
/**
 * DDL for wp_appza_customizations per DC#13 Q1.
 *
 * Single flat overrides table — one row per (scope, target, column) override.
 * 8-value scope enum (appzet / template / template_screen /
 * template_screen_placement / data_source / action / appzet_primitive /
 * global). Composite UNIQUE per scope. Single table chosen over
 * per-catalog-table or per-scope tables for forward-compat: new catalog
 * tables at v2+ just extend the scope ENUM; no migrations.
 *
 * `appzet_primitive` (added DC#20 provisional — per-primitive layout axis):
 *   target_slug           = AppZet slug (human-readable filter key)
 *   target_slug_composite = "<appzet.slug>#children[<i>]" (unique tree key)
 *   target_column         = "layout" (leaf blob: LayoutStyle JSON)
 * Composite carries the slug prefix so the fold's target_key is self-
 * contained — parallels how template_screen_placement composite already
 * encodes `<ts.slug>#<placement_key>`.
 *
 * Overridable column inventory v1 (DC#13 Q1):
 *   appza_appzets         default_props_override (leaf), actions (structural), field_mappings (leaf)
 *   appza_templates       tokens (leaf)
 *   appza_template_screens screen_tokens (leaf), placements[].tokens_override / props_override (composite-scope)
 *   appza_data_sources    query_params (leaf), cache_ttl (leaf)
 *   appza_actions         param_schema (leaf)
 *
 * Migration flag columns added per DC#15 Q7 (col count 10 → 12):
 *   migration_flagged_at  — set by re-validation when row fails new contracts
 *   migration_error       — Zod validation error for admin UI display
 *
 * Audit cols (created_by / updated_by) FK semantics: ON DELETE SET NULL
 * per DC#13 Q3 clarification — audit FKs are historical metadata, not
 * referential integrity (catalog FKs are RESTRICT).
 */

namespace AppzaCore\Plugin\Schema;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class CustomizationSchema {

	const TABLE = 'appza_customizations';

	/**
	 * 8 scope values (7 from DC#13 Q1 + `appzet_primitive` from DC#20
	 * provisional). ENUM at DB level for declarative constraint; the
	 * PHP layer doesn't need a parallel enum class because producers
	 * (override admin UI + plug-in-admin per-primitive Layout editor)
	 * whitelist the same values at form-validation time.
	 */
	const SCOPES = array(
		'appzet',
		'template',
		'template_screen',
		'template_screen_placement',
		'data_source',
		'action',
		'appzet_primitive',
		'global',
	);

	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	public static function install() {
		global $wpdb;
		$table     = self::table_name();
		$charset   = $wpdb->get_charset_collate();
		$scope_sql = "'" . implode( "','", array_map( 'esc_sql', self::SCOPES ) ) . "'";

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			scope ENUM({$scope_sql}) NOT NULL,
			target_slug VARCHAR(190) NULL DEFAULT NULL,
			target_slug_composite VARCHAR(380) NULL DEFAULT NULL,
			target_column VARCHAR(190) NOT NULL,
			override_value LONGTEXT NOT NULL,
			version BIGINT UNSIGNED NOT NULL DEFAULT 1,
			migration_flagged_at DATETIME NULL DEFAULT NULL,
			migration_error TEXT NULL DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			created_by BIGINT UNSIGNED NULL DEFAULT NULL,
			updated_by BIGINT UNSIGNED NULL DEFAULT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY uniq_scope_target (scope, target_slug, target_slug_composite, target_column),
			KEY idx_scope (scope),
			KEY idx_migration_flagged (migration_flagged_at)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		// dbDelta is unreliable at ALTER-ing ENUM columns on tables that
		// already exist — it often leaves the column def stale after new
		// values are added. Issue an explicit MODIFY as belt-and-suspenders.
		// Idempotent: MySQL accepts a MODIFY with the same target definition.
		$wpdb->query( "ALTER TABLE {$table} MODIFY COLUMN scope ENUM({$scope_sql}) NOT NULL" );
	}

	public static function drop() {
		global $wpdb;
		$table = self::table_name();
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
	}
}
