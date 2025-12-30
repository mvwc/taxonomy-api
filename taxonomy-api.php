<?php
/*
    Plugin Name: Taxonomy API
    Plugin URI: https://www.aviandiscovery.com
    Description: Lightweight iNaturalist taxonomy ingestor with optional AI enrichment.
    Author: Brandon Bartlett
    Version: 3.0.6
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

$taxa_update_metadata_url = is_multisite()
    ? get_site_option( 'taxa_update_metadata_url', '' )
    : get_option( 'taxa_update_metadata_url', '' );
$taxa_update_github_token = is_multisite()
    ? get_site_option( 'taxa_update_github_token', '' )
    : get_option( 'taxa_update_github_token', '' );
if ( ! $taxa_update_metadata_url ) {
    $taxa_update_metadata_url = 'https://www.aviandiscovery.com/wp-content/plugins/manifest.json';
}
$taxa_update_metadata_url = apply_filters( 'taxa_api_update_metadata_url', $taxa_update_metadata_url );
$taxa_update_github_token = apply_filters( 'taxa_api_update_github_token', $taxa_update_github_token );
if ( $taxa_update_metadata_url ) {
    $taxa_update_checker = new Taxa_Plugin_Update_Checker( __FILE__, $taxa_update_metadata_url, $taxa_update_github_token );
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
 * Tables:
 * - {$wpdb->prefix}taxa_facets_views_daily
 * - {$wpdb->prefix}taxa_facets
 */
function taxa_facets_rebuild_popularity_from_daily_views() {
    global $wpdb;

    $facets_table = $wpdb->prefix . 'taxa_facets';
    $daily_table  = $wpdb->prefix . 'taxa_facets_views_daily';

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

// In taxonomy-api.php (or a bootstrap include)

add_filter('upgrader_source_selection', 'taxa_fix_plugin_folder_name_on_update', 10, 4);

/**
 * Force stable plugin folder name during updates when ZIP unpacks to repo-tag folder (e.g. plugin-3.0.0/).
 *
 * @param string      $source        Full path to the extracted package source folder.
 * @param string      $remote_source Full path to the working directory.
 * @param \WP_Upgrader $upgrader     Upgrader instance.
 * @param array       $hook_extra    Extra args, including 'plugin' for plugin updates.
 * @return string|\WP_Error
 */
function taxa_fix_plugin_folder_name_on_update($source, $remote_source, $upgrader, $hook_extra) {

    // Only for plugin updates.
    if (empty($hook_extra['plugin'])) {
        return $source;
    }

    // Ensure this is *our* plugin being updated.
    // Example: 'taxonomy-api/taxonomy-api.php'
    $our_plugin = 'taxonomy-api/taxonomy-api.php';
    if ($hook_extra['plugin'] !== $our_plugin) {
        return $source;
    }

    $desired_folder = 'taxonomy-api';

    // $source might be ".../ai-taxonomy-api-3.0.0/"
    $source_basename = basename(trailingslashit($source));

    // If it already matches, nothing to do.
    if ($source_basename === $desired_folder) {
        return $source;
    }

    $new_source = trailingslashit($remote_source) . $desired_folder;

    // If a folder with the desired name already exists in the working dir, remove it first.
    if (is_dir($new_source)) {
        // WP_Filesystem is available during upgrades; use it if present.
        global $wp_filesystem;
        if ($wp_filesystem && is_object($wp_filesystem)) {
            $wp_filesystem->delete($new_source, true);
        } else {
            // Fallback.
            taxa_rrmdir($new_source);
        }
    }

    // Rename the extracted folder to the stable folder.
    $renamed = false;
    global $wp_filesystem;
    if ($wp_filesystem && is_object($wp_filesystem)) {
        $renamed = $wp_filesystem->move($source, $new_source, true);
    }
    if (!$renamed) {
        $renamed = @rename($source, $new_source);
    }
    if (!$renamed) {
        return new WP_Error(
            'taxa_update_rename_failed',
            'Taxonomy API update failed: could not normalize plugin folder name after extraction.'
        );
    }

    return $new_source;
}

/**
 * Fallback recursive dir delete if WP_Filesystem isn't available.
 */
function taxa_rrmdir($dir) {
    if (!is_dir($dir)) return;
    $items = scandir($dir);
    if (!$items) return;
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            taxa_rrmdir($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}
