<?php
/**
 * Taxa Facets table install / upgrade.
 *
 * - Defines the NEW generic schema with:
 *     size, shape_primary, shape_secondary, pattern,
 *     trait_primary, trait_secondary, diet,
 *     color_mask, call_type_mask, behavior_mask, habitat_mask,
 *     family_id, region_id.
 * - Migrates legacy columns:
 *     wing_shape      -> shape_primary
 *     tail_shape      -> shape_secondary
 *     call_pattern    -> pattern
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Get the taxa facets table name.
 *
 * @return string
 */
function taxa_facets_get_table_name() {
    global $wpdb;
    return $wpdb->prefix . 'taxa_facets';
}

/**
 * Install or upgrade the taxa_facets table to the new generic schema.
 *
 * Target schema:
 *   post_id, size, shape_primary, shape_secondary, pattern,
 *   trait_primary, trait_secondary, diet,
 *   color_mask, call_type_mask, behavior_mask, habitat_mask,
 *   family_id, region_id,
 *   taxa_rank, extinct, popularity.
 *
 * This is safe to call on every activation (or even on init with a version check).
 */
function taxa_facets_install_or_update_table() {
    global $wpdb;

    $table           = taxa_facets_get_table_name();
    $charset_collate = $wpdb->get_charset_collate();

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    /**
     * 1) Ensure table exists with the FINAL target columns using dbDelta.
     *
     * For fresh installs, this creates the full schema.
     * For existing installs, dbDelta will add any missing columns/indexes.
     */
    $sql = "CREATE TABLE $table (
        post_id         bigint(20) unsigned NOT NULL,
        size            smallint(5) unsigned DEFAULT NULL,
        shape_primary   smallint(5) unsigned DEFAULT NULL,
        shape_secondary smallint(5) unsigned DEFAULT NULL,
        pattern         smallint(5) unsigned DEFAULT NULL,
        trait_primary   smallint(5) unsigned DEFAULT NULL,
        trait_secondary smallint(5) unsigned DEFAULT NULL,
        diet            smallint(5) unsigned DEFAULT NULL,
        color_mask      bigint(20) unsigned NOT NULL DEFAULT 0,
        call_type_mask  bigint(20) unsigned NOT NULL DEFAULT 0,
        behavior_mask   bigint(20) unsigned NOT NULL DEFAULT 0,
        habitat_mask    bigint(20) unsigned NOT NULL DEFAULT 0,
        family_id       bigint(20) unsigned DEFAULT NULL,
        region_id       bigint(20) unsigned DEFAULT NULL,
        taxa_rank       varchar(32) DEFAULT NULL,
        extinct         tinyint(1) unsigned NOT NULL DEFAULT 0,
        popularity      bigint(20) unsigned NOT NULL DEFAULT 0,
        PRIMARY KEY  (post_id),
        KEY family_id (family_id),
        KEY region_id (region_id),
        KEY taxa_rank (taxa_rank),
        KEY extinct (extinct),
        KEY popularity (popularity)
    ) $charset_collate;";

    dbDelta( $sql );

    /**
     * 2) MIGRATION: if legacy columns exist, move data into the new columns.
     *
     * These sections only do work on old installs that still have the
     * previous column names. On fresh installs created with the SQL above,
     * these columns will never exist, so the blocks will no-op.
     */

    // a) wing_shape -> shape_primary
    $has_wing_shape    = taxa_facets_column_exists( 'wing_shape' );
    $has_shape_primary = taxa_facets_column_exists( 'shape_primary' );
    if ( $has_wing_shape && $has_shape_primary ) {
        // Only copy where target is NULL so we don't overwrite anything new.
        $wpdb->query(
            "UPDATE `$table`
             SET shape_primary = wing_shape
             WHERE shape_primary IS NULL"
        );

        // Drop legacy column once migrated.
        $wpdb->query( "ALTER TABLE `$table` DROP COLUMN wing_shape" );
    }

    // b) tail_shape -> shape_secondary
    $has_tail_shape      = taxa_facets_column_exists( 'tail_shape' );
    $has_shape_secondary = taxa_facets_column_exists( 'shape_secondary' );
    if ( $has_tail_shape && $has_shape_secondary ) {
        $wpdb->query(
            "UPDATE `$table`
             SET shape_secondary = tail_shape
             WHERE shape_secondary IS NULL"
        );

        $wpdb->query( "ALTER TABLE `$table` DROP COLUMN tail_shape" );
    }

    // c) call_pattern -> pattern
    $has_call_pattern = taxa_facets_column_exists( 'call_pattern' );
    $has_pattern      = taxa_facets_column_exists( 'pattern' );
    if ( $has_call_pattern && $has_pattern ) {
        $wpdb->query(
            "UPDATE `$table`
             SET pattern = call_pattern
             WHERE pattern IS NULL"
        );

        $wpdb->query( "ALTER TABLE `$table` DROP COLUMN call_pattern" );
    }

    /**
     * 3) Ensure trait_primary / trait_secondary exist
     *    (for very old tables that predate them).
     *
     * On fresh installs the CREATE TABLE already defines them, and dbDelta
     * will have added them if they were missing, but this is a cheap safety
     * net for older sites.
     */
    if ( ! taxa_facets_column_exists( 'trait_primary' ) ) {
        $wpdb->query(
            "ALTER TABLE `$table`
             ADD COLUMN trait_primary smallint(5) unsigned DEFAULT NULL
             AFTER pattern"
        );
    }

    if ( ! taxa_facets_column_exists( 'trait_secondary' ) ) {
        $wpdb->query(
            "ALTER TABLE `$table`
             ADD COLUMN trait_secondary smallint(5) unsigned DEFAULT NULL
             AFTER trait_primary"
        );
    }

    /**
     * 4) Legacy `rank` → `taxa_rank` rename (older installs only).
     *
     * New installs never get a `rank` column because the CREATE TABLE
     * above defines `taxa_rank` directly. This block only runs if there is
     * still a legacy `rank` column hanging around.
     */
    if ( taxa_facets_column_exists( 'rank' ) && ! taxa_facets_column_exists( 'taxa_rank' ) ) {
        $wpdb->query(
            "ALTER TABLE `$table`
             CHANGE COLUMN `rank` `taxa_rank` varchar(32) DEFAULT NULL"
        );
    }

    // dbDelta already defined the KEY taxa_rank/KEY extinct/KEY popularity
    // in the CREATE TABLE, so we don't need extra index cleanup here.
}


function taxa_facets_ensure_last_viewed_column() {
    global $wpdb;

    $table = $wpdb->prefix . 'taxa_facets'; // adjust if your function builds otm_4_... already
    // If you have a helper like taxa_facets_table_name(), use that instead:
    // $table = taxa_facets_table_name();

    // Quick exists check
    $col = $wpdb->get_var( $wpdb->prepare(
        "SHOW COLUMNS FROM `$table` LIKE %s",
        'last_viewed'
    ) );

    if ( $col ) {
        return;
    }

    // Add column (DATETIME is fine; NULL default is fine)
    $wpdb->query( "ALTER TABLE `$table` ADD COLUMN `last_viewed` DATETIME NULL DEFAULT NULL" );
}
add_action( 'plugins_loaded', 'taxa_facets_ensure_last_viewed_column', 25 );
