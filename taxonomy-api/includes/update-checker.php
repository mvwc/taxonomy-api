<?php
/**
 * Lightweight plugin update checker for Taxonomy API.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Taxa_Plugin_Update_Checker {
    private $plugin_file;
    private $metadata_url;
    private $plugin_slug;
    private $cache_key;
    private $cache_ttl;

    public function __construct( $plugin_file, $metadata_url ) {
        $this->plugin_file  = $plugin_file;
        $this->metadata_url = $metadata_url;
        $this->cache_key    = 'taxa_update_metadata_' . md5( $metadata_url );
        $this->cache_ttl    = 12 * HOUR_IN_SECONDS;

        $plugin_basename = plugin_basename( $plugin_file );
        $this->plugin_slug = dirname( $plugin_basename );
    }

    public function register() {
        add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'filter_update_plugins' ) );
        add_filter( 'plugins_api', array( $this, 'filter_plugins_api' ), 10, 3 );
    }

    public function filter_update_plugins( $transient ) {
        if ( empty( $transient->checked ) || ! is_array( $transient->checked ) ) {
            return $transient;
        }

        $plugin_basename = plugin_basename( $this->plugin_file );
        $current_version = $transient->checked[ $plugin_basename ] ?? TAXA_API_VERSION;

        $metadata = $this->get_metadata();
        if ( ! $metadata || empty( $metadata['version'] ) ) {
            return $transient;
        }

        if ( version_compare( $metadata['version'], $current_version, '>' ) ) {
            $transient->response[ $plugin_basename ] = (object) array(
                'slug'        => $this->plugin_slug,
                'plugin'      => $plugin_basename,
                'new_version' => $metadata['version'],
                'url'         => $metadata['homepage'],
                'package'     => $metadata['download_url'],
            );
        }

        return $transient;
    }

    public function filter_plugins_api( $result, $action, $args ) {
        if ( 'plugin_information' !== $action || empty( $args->slug ) || $args->slug !== $this->plugin_slug ) {
            return $result;
        }

        $metadata = $this->get_metadata();
        if ( ! $metadata ) {
            return $result;
        }

        return (object) array(
            'name'          => $metadata['name'],
            'slug'          => $this->plugin_slug,
            'version'       => $metadata['version'],
            'author'        => $metadata['author'],
            'homepage'      => $metadata['homepage'],
            'download_link' => $metadata['download_url'],
            'requires'      => $metadata['requires'],
            'tested'        => $metadata['tested'],
            'sections'      => $metadata['sections'],
        );
    }

    private function get_metadata() {
        $cached = get_site_transient( $this->cache_key );
        if ( $cached && is_array( $cached ) ) {
            return $cached;
        }

        $response = wp_remote_get(
            $this->metadata_url,
            array(
                'timeout' => 10,
            )
        );

        if ( is_wp_error( $response ) ) {
            return null;
        }

        $body = wp_remote_retrieve_body( $response );
        if ( ! $body ) {
            return null;
        }

        $data = json_decode( $body, true );
        if ( ! is_array( $data ) ) {
            return null;
        }

        $metadata = array(
            'name'         => $data['name'] ?? 'Taxonomy API',
            'version'      => $data['version'] ?? '',
            'author'       => $data['author'] ?? '',
            'homepage'     => $data['homepage'] ?? '',
            'download_url' => $data['download_url'] ?? '',
            'requires'     => $data['requires'] ?? '',
            'tested'       => $data['tested'] ?? '',
            'sections'     => $data['sections'] ?? array(),
        );

        $metadata = apply_filters( 'taxa_api_update_metadata', $metadata, $data );

        set_site_transient( $this->cache_key, $metadata, $this->cache_ttl );

        return $metadata;
    }
}
