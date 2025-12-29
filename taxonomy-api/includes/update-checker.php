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
    private $github_token;

    public function __construct( $plugin_file, $metadata_url, $github_token = '' ) {
        $this->plugin_file  = $plugin_file;
        $this->metadata_url = $metadata_url;
        $this->cache_key    = 'taxa_update_metadata_' . md5( $metadata_url );
        $this->cache_ttl    = 12 * HOUR_IN_SECONDS;
        $this->github_token = $github_token;

        $plugin_basename = plugin_basename( $plugin_file );
        $this->plugin_slug = dirname( $plugin_basename );
    }

    public function register() {
        add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'filter_update_plugins' ) );
        add_filter( 'plugins_api', array( $this, 'filter_plugins_api' ), 10, 3 );
        add_filter( 'http_request_args', array( $this, 'filter_http_request_args' ), 10, 2 );
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

    public function filter_http_request_args( $args, $url ) {
        if ( ! $this->github_token ) {
            return $args;
        }

        $host = wp_parse_url( $url, PHP_URL_HOST );
        if ( ! $host ) {
            return $args;
        }

        $is_github = false !== stripos( $host, 'github.com' ) || false !== stripos( $host, 'api.github.com' );
        if ( ! $is_github ) {
            return $args;
        }

        if ( empty( $args['headers'] ) || ! is_array( $args['headers'] ) ) {
            $args['headers'] = array();
        }

        if ( empty( $args['headers']['Authorization'] ) ) {
            $args['headers']['Authorization'] = 'token ' . $this->github_token;
        }

        if ( empty( $args['headers']['Accept'] ) ) {
            $args['headers']['Accept'] = 'application/vnd.github+json';
        }

        return $args;
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
