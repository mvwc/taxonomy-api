<?php
/*
    Plugin Name: Taxonomy API
    Plugin URI: https://www.aviandiscovery.com
    Description: Lightweight iNaturalist taxonomy ingestor with optional AI enrichment.
    Author: Brandon Bartlett
    Version: 3.0.0
    Author URI: https://www.aviandiscovery.com
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Plugin constants.
define( 'TAXA_API_VERSION', '3.0.0' );
define( 'TAXA_API_PLUGIN_FILE', __FILE__ );
define( 'TAXA_API_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'TAXA_API_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Includes.
require_once TAXA_API_PLUGIN_DIR . 'includes/functions.php';
require_once TAXA_API_PLUGIN_DIR . 'includes/class-taxonomy-importer.php';
require_once TAXA_API_PLUGIN_DIR . 'includes/admin-settings.php';
// Facet engine
require_once TAXA_API_PLUGIN_DIR . 'includes/facets.php';
require_once TAXA_API_PLUGIN_DIR . 'includes/facets-frontend.php';
require_once TAXA_API_PLUGIN_DIR . 'includes/facets-admin.php';


require_once TAXA_API_PLUGIN_DIR . 'includes/install.php';
require_once TAXA_API_PLUGIN_DIR . 'includes/update-checker.php';

$taxa_update_metadata_url = get_option( 'taxa_update_metadata_url', '' );
if ( ! $taxa_update_metadata_url ) {
    $taxa_update_metadata_url = 'https://www.aviandiscovery.com/wp-content/plugins/taxonomy-api/manifest.json';
}
$taxa_update_metadata_url = apply_filters( 'taxa_api_update_metadata_url', $taxa_update_metadata_url );
if ( $taxa_update_metadata_url ) {
    $taxa_update_checker = new Taxa_Plugin_Update_Checker( __FILE__, $taxa_update_metadata_url );
    $taxa_update_checker->register();
}

register_activation_hook( __FILE__, 'taxonomy_api_activate' );

function taxonomy_api_activate() {
    // Install or upgrade the facets table to the latest generic schema.
    taxa_facets_install_or_update_table();

    // You can also bump / store a DB version option here if you want.
    update_option( 'taxonomy_api_facets_db_version', '2.1.0' );
}


/**
 * Plugin activation callback.
 */
function taxa_api_activate() {
    // Schedule cron based on current settings.
    taxa_api_schedule_cron();
}
register_activation_hook( __FILE__, 'taxa_api_activate' );

/**
 * Plugin deactivation callback.
 */
function taxa_api_deactivate() {
    taxa_api_clear_cron();
}
register_deactivation_hook( __FILE__, 'taxa_api_deactivate' );

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'TAXA_FACETS_POPULARITY_CRON_HOOK', 'taxa_facets_rebuild_popularity' );

/**
 * Schedule on activation (daily).
 */
function taxa_facets_schedule_popularity_cron() {
    if ( ! wp_next_scheduled( TAXA_FACETS_POPULARITY_CRON_HOOK ) ) {
        // Run once shortly after activation, then daily.
        wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'daily', TAXA_FACETS_POPULARITY_CRON_HOOK );
    }
}

/**
 * Clear on deactivation.
 */
function taxa_facets_clear_popularity_cron() {
    $ts = wp_next_scheduled( TAXA_FACETS_POPULARITY_CRON_HOOK );
    if ( $ts ) {
        wp_unschedule_event( $ts, TAXA_FACETS_POPULARITY_CRON_HOOK );
    }
}

// If this is a plugin file with activation hooks:
register_activation_hook( __FILE__, 'taxa_facets_schedule_popularity_cron' );
register_deactivation_hook( __FILE__, 'taxa_facets_clear_popularity_cron' );

// Or if you can't use activation hooks in this file, do:
// add_action('init', 'taxa_facets_schedule_popularity_cron');

add_action( TAXA_FACETS_POPULARITY_CRON_HOOK, 'taxa_facets_rebuild_popularity_from_daily_views' );

/**
 * Rebuild f.popularity from views_daily (rolling 30 days).
 *
 * Tables you referenced:
 * - otm_2_taxa_facets_views_daily
 * - otm_2_taxa_facets
 */
function taxa_facets_rebuild_popularity_from_daily_views() {
    global $wpdb;

    // Your tables are explicitly named with otm_2_ prefix in your message.
    // If you want this to be dynamic per blog prefix, replace with $wpdb->prefix.
    $facets_table = 'otm_2_taxa_facets';
    $daily_table  = 'otm_2_taxa_facets_views_daily';

    // Safety: only run if popularity exists.
    if ( function_exists( 'taxa_facets_column_exists' ) ) {
        if ( ! taxa_facets_column_exists( 'popularity' ) ) {
            error_log('[FACETS][POPULARITY] popularity column missing; skipping rebuild.');
            return;
        }
    }

    // Rolling window start (30 days).
    $start_ymd = gmdate( 'Y-m-d', time() - 30 * DAY_IN_SECONDS );

    // 1) Zero out popularity first (so posts with no recent views become 0).
    $wpdb->query( "UPDATE {$facets_table} SET popularity = 0" );

    // 2) Update popularity based on last 30 days.
    // Assumes views_daily has columns: post_id, ymd, views (or view_count).
    // Adjust `views` column name if yours is different.
    $sql = $wpdb->prepare(
        "
        UPDATE {$facets_table} f
        INNER JOIN (
            SELECT post_id, SUM(views) AS pop
            FROM {$daily_table}
            WHERE ymd >= %s
            GROUP BY post_id
        ) d ON d.post_id = f.post_id
        SET f.popularity = d.pop
        ",
        $start_ymd
    );

    $result = $wpdb->query( $sql );

    error_log(
        '[FACETS][POPULARITY] Rebuilt popularity from daily views. start=' . $start_ymd .
        ' rows_updated=' . ( is_numeric($result) ? $result : 'n/a' ) .
        ' last_error=' . ( $wpdb->last_error ? $wpdb->last_error : '(none)' )
    );
}
