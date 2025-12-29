<?php
/**
 * Front-end facets UI + AJAX search - v2.0.0 (generic facets)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Make sure facet helpers are loaded (enums, masks, etc).
require_once plugin_dir_path( __FILE__ ) . 'facets.php';

/**
 * Register REST route for faceted search.
 */
add_action( 'rest_api_init', 'taxa_facets_register_rest_routes' );

function taxa_facets_register_rest_routes() {
    register_rest_route(
        'taxa/v1',
        '/search',
        array(
            'methods'             => 'GET',
            'callback'            => 'taxa_facets_rest_search',
            'permission_callback' => '__return_true',
            'args'                => array(
                'page'           => array(
                    'default'           => 1,
                    'sanitize_callback' => 'absint',
                ),
                'per_page'       => array(
                    'default'           => 24,
                    'sanitize_callback' => 'absint',
                ),
                'sort' => array(
                    'default'           => 'popular',
                    'sanitize_callback' => function( $v ) {
                        $v = strtolower( trim( (string) $v ) );
                        $allowed = array( 'popular', 'title', 'newest' );
                        return in_array( $v, $allowed, true ) ? $v : 'popular';
                    },
                ),


                // ENUM facets (generic slots)
                'size'           => array(),
                'shape_primary'  => array(),
                'shape_secondary'=> array(),
                'pattern'        => array(),
                'trait_primary'  => array(),
                'trait_secondary'=> array(),
                'diet'           => array(),

                // Legacy alias (for older JS / URLs) – mapped to "pattern" internally.
                'call_pattern'   => array(),

                // Bitmask facets
                'colors'         => array(), // comma-separated string
                'behaviors'      => array(),
                'habitats'       => array(),
                'call_types'     => array(),

                // Other filters
                'search'         => array(), // text search

                // Rank filter is now stored on facets table (no join)
                'taxa_rank'      => array(),

                // NEW: by default we EXCLUDE extinct, unless include_extinct=1
                'include_extinct' => array(
                    'default'           => 0,
                    'sanitize_callback' => 'absint',
                ),
            ),
        )
    );
}


/**
 * Normalize a GET param that can be string|array into an array of trimmed slugs.
 *
 * @param mixed $value
 * @return string[]
 */
function taxa_facets_normalize_param( $value ) {
    if ( is_array( $value ) ) {
        $out = array();
        foreach ( $value as $v ) {
            $v = trim( (string) $v );
            if ( '' !== $v ) {
                $out[] = $v;
            }
        }
        return $out;
    }

    $value = trim( (string) $value );
    if ( '' === $value ) {
        return array();
    }

    return array_map( 'trim', explode( ',', $value ) );
}

/**
 * Turn a facet row into display strings.
 */
function taxa_facets_build_summary_from_row( array $row ) {
    $lines = array();

    if ( isset( $row['color_mask'] ) ) {
        $color_slugs = taxa_decode_color_mask_to_slugs( (int) $row['color_mask'] );
        if ( ! empty( $color_slugs ) ) {
            $lines[] = 'Colors: ' . implode( ', ', array_map( 'taxa_facets_pretty_slug', $color_slugs ) );
        }
    }

    if ( ! empty( $row['size'] ) ) {
        $size_slug = taxa_decode_size_enum_to_slug( $row['size'] );
        if ( '' !== $size_slug ) {
            $lines[] = 'Size: ' . taxa_facets_pretty_slug( $size_slug );
        }
    }

    if ( ! empty( $row['shape_primary'] ) ) {
        $shape_primary_slug = taxa_decode_shape_primary_enum_to_slug( $row['shape_primary'] );
        if ( '' !== $shape_primary_slug ) {
            $lines[] = 'Shape: ' . taxa_facets_pretty_slug( $shape_primary_slug );
        }
    }

    if ( ! empty( $row['shape_secondary'] ) ) {
        $shape_secondary_slug = taxa_decode_shape_secondary_enum_to_slug( $row['shape_secondary'] );
        if ( '' !== $shape_secondary_slug ) {
            $lines[] = 'Shape (secondary): ' . taxa_facets_pretty_slug( $shape_secondary_slug );
        }
    }

    if ( ! empty( $row['pattern'] ) ) {
        $pattern_slug = taxa_decode_pattern_enum_to_slug( $row['pattern'] );
        if ( '' !== $pattern_slug ) {
            $lines[] = 'Pattern: ' . taxa_facets_pretty_slug( $pattern_slug );
        }
    }

    if ( ! empty( $row['trait_primary'] ) ) {
        $trait_primary_slug = taxa_decode_trait_primary_enum_to_slug( $row['trait_primary'] );
        if ( '' !== $trait_primary_slug ) {
            $lines[] = 'Trait: ' . taxa_facets_pretty_slug( $trait_primary_slug );
        }
    }

    if ( ! empty( $row['trait_secondary'] ) ) {
        $trait_secondary_slug = taxa_decode_trait_secondary_enum_to_slug( $row['trait_secondary'] );
        if ( '' !== $trait_secondary_slug ) {
            $lines[] = 'Trait (secondary): ' . taxa_facets_pretty_slug( $trait_secondary_slug );
        }
    }

    if ( ! empty( $row['diet'] ) ) {
        $diet_slug = taxa_decode_diet_enum_to_slug( $row['diet'] );
        if ( '' !== $diet_slug ) {
            $lines[] = 'Diet: ' . taxa_facets_pretty_slug( $diet_slug );
        }
    }

    if ( isset( $row['behavior_mask'] ) ) {
        $behavior_slugs = taxa_decode_behavior_mask_to_slugs( (int) $row['behavior_mask'] );
        if ( ! empty( $behavior_slugs ) ) {
            $lines[] = 'Behavior: ' . implode( ', ', array_map( 'taxa_facets_pretty_slug', $behavior_slugs ) );
        }
    }

    if ( isset( $row['habitat_mask'] ) ) {
        $habitat_slugs = taxa_decode_habitat_mask_to_slugs( (int) $row['habitat_mask'] );
        if ( ! empty( $habitat_slugs ) ) {
            $lines[] = 'Habitat: ' . implode( ', ', array_map( 'taxa_facets_pretty_slug', $habitat_slugs ) );
        }
    }

    if ( isset( $row['call_type_mask'] ) ) {
        $call_type_slugs = taxa_decode_call_type_mask_to_slugs( (int) $row['call_type_mask'] );
        if ( ! empty( $call_type_slugs ) ) {
            $lines[] = 'Call type: ' . implode( ', ', array_map( 'taxa_facets_pretty_slug', $call_type_slugs ) );
        }
    }

    $lines = apply_filters( 'taxa_facets_summary_lines', $lines, $row );

    return $lines;
}

function taxa_facets_pretty_slug( $slug ) {
    $slug = (string) $slug;
    $slug = str_replace( '_', ' ', $slug );
    return mb_convert_case( $slug, MB_CASE_TITLE, 'UTF-8' );
}

/**
 * Check if a column exists on the taxa_facets table.
 *
 * @param string $column
 * @return bool
 */
if ( ! function_exists( 'taxa_facets_column_exists' ) ) {
    /**
     * Check if a column exists in the taxa facets table.
     * Cached per request to avoid repeated SHOW COLUMNS queries.
     *
     * @param string $column Column name.
     * @return bool
     */
    function taxa_facets_column_exists( $column ) {
        static $cache = array();

        $column = trim( (string) $column );
        if ( $column === '' ) {
            return false;
        }

        // Cache key is just the column because this function always checks the facets table.
        if ( array_key_exists( $column, $cache ) ) {
            return $cache[ $column ];
        }

        global $wpdb;

        // Use your helper so multisite / prefixing stays correct.
        $table = function_exists( 'taxa_facets_get_table_name' )
            ? taxa_facets_get_table_name()
            : ( $wpdb->prefix . 'taxa_facets' );

        // Note: table name cannot be parameterized; column is safe via prepare.
        $result = $wpdb->get_var(
            $wpdb->prepare(
                "SHOW COLUMNS FROM `$table` LIKE %s",
                $column
            )
        );

        $cache[ $column ] = ! empty( $result );
        return $cache[ $column ];
    }
}

/**
 * REST callback: perform faceted search against {prefix}taxa_facets.
 */
function taxa_facets_rest_search( WP_REST_Request $request ) {
    global $wpdb;

    $table_facets = $wpdb->prefix . 'taxa_facets';
    $table_posts  = $wpdb->posts;
    $table_meta   = $wpdb->postmeta;

    $page      = max( 1, (int) $request->get_param( 'page' ) );
    $per_page  = max( 1, min( 48, (int) $request->get_param( 'per_page' ) ) );
    $offset    = ( $page - 1 ) * $per_page;

    // NEW: extinct toggle flag (0 or 1)
    $include_extinct = (int) $request->get_param( 'include_extinct' );
    $include_extinct = ( $include_extinct === 1 ) ? 1 : 0;

    $sort = strtolower( trim( (string) $request->get_param( 'sort' ) ) );
    if ( '' === $sort ) {
        $sort = 'popular';
    }

    $has_popularity  = taxa_facets_column_exists( 'popularity' );
    $has_last_viewed = taxa_facets_column_exists( 'last_viewed' );

    $order_by = 'p.post_title ASC'; // fallback

    if ( 'popular' === $sort ) {
        if ( $has_popularity && $has_last_viewed ) {
            $order_by = 'COALESCE(f.popularity,0) DESC, f.last_viewed DESC, p.post_title ASC';
        } elseif ( $has_popularity ) {
            $order_by = 'COALESCE(f.popularity,0) DESC, p.post_title ASC';
        }
    } elseif ( 'newest' === $sort ) {
        $order_by = 'p.post_date DESC, p.ID DESC';
    } elseif ( 'title' === $sort ) {
        $order_by = 'p.post_title ASC';
    }

    // DEBUG — this will tell you immediately which order-by you’re using.
    error_log('[FACETS][SORT] sort=' . $sort . ' order_by=' . $order_by . ' has_popularity=' . (int)$has_popularity . ' has_last_viewed=' . (int)$has_last_viewed );


    $where  = array(
        "p.post_status = 'publish'",
        "p.post_type = 'post'",
        "m_fifu.meta_value <> ''",
    );
    $params = array();

    $fifu_meta_key = 'fifu_image_url';

    // UPDATED: Removed m_rank join. Rank/extinct come from facets table.
    $join_sql = "
        FROM {$table_facets} f
        INNER JOIN {$table_posts} p
            ON f.post_id = p.ID
        LEFT JOIN {$table_meta} m_fifu
            ON (m_fifu.post_id = p.ID AND m_fifu.meta_key = '{$fifu_meta_key}')
    ";

    // only hide extinct when toggle is OFF
    if ( ! $include_extinct ) {
        $where[] = "f.extinct = 0";
    }


    // --- SIZE (single enum via slug) ---
    $size_slug = $request->get_param( 'size' );
    if ( $size_slug ) {
        $size_values = taxa_map_size_params( array( $size_slug ) );
        if ( ! empty( $size_values ) ) {
            $where[]  = 'f.size = %d';
            $params[] = (int) reset( $size_values );
        }
    }

    // --- SHAPE PRIMARY (single enum) ---
    $shape_primary_slugs = taxa_facets_normalize_param( $request->get_param( 'shape_primary' ) );
    if ( ! empty( $shape_primary_slugs ) ) {
        $shape_primary_values = taxa_map_shape_primary_params( $shape_primary_slugs );
        if ( ! empty( $shape_primary_values ) ) {
            $placeholders = implode( ',', array_fill( 0, count( $shape_primary_values ), '%d' ) );
            $where[]      = "f.shape_primary IN ($placeholders)";
            foreach ( $shape_primary_values as $sv ) {
                $params[] = (int) $sv;
            }
        }
    }

    // --- SHAPE SECONDARY (single enum) ---
    $shape_secondary_slugs = taxa_facets_normalize_param( $request->get_param( 'shape_secondary' ) );
    if ( ! empty( $shape_secondary_slugs ) ) {
        $shape_secondary_values = taxa_map_shape_secondary_params( $shape_secondary_slugs );
        if ( ! empty( $shape_secondary_values ) ) {
            $placeholders = implode( ',', array_fill( 0, count( $shape_secondary_values ), '%d' ) );
            $where[]      = "f.shape_secondary IN ($placeholders)";
            foreach ( $shape_secondary_values as $sv ) {
                $params[] = (int) $sv;
            }
        }
    }

    // --- PATTERN (single enum) ---
    $pattern_param = $request->get_param( 'pattern' );
    if ( '' === trim( (string) $pattern_param ) ) {
        $pattern_param = $request->get_param( 'call_pattern' ); // legacy alias
    }

    $pattern_slugs = taxa_facets_normalize_param( $pattern_param );
    if ( ! empty( $pattern_slugs ) ) {
        $pattern_values = taxa_map_pattern_params( $pattern_slugs );
        if ( ! empty( $pattern_values ) ) {
            $placeholders = implode( ',', array_fill( 0, count( $pattern_values ), '%d' ) );
            $where[]      = "f.pattern IN ($placeholders)";
            foreach ( $pattern_values as $pv ) {
                $params[] = (int) $pv;
            }
        }
    }

    // --- TRAIT PRIMARY (single enum) ---
    $trait_primary_slugs = taxa_facets_normalize_param( $request->get_param( 'trait_primary' ) );
    if ( ! empty( $trait_primary_slugs ) ) {
        $trait_primary_values = taxa_map_trait_primary_params( $trait_primary_slugs );
        if ( ! empty( $trait_primary_values ) ) {
            $placeholders = implode( ',', array_fill( 0, count( $trait_primary_values ), '%d' ) );
            $where[]      = "f.trait_primary IN ($placeholders)";
            foreach ( $trait_primary_values as $tv ) {
                $params[] = (int) $tv;
            }
        }
    }

    // --- TRAIT SECONDARY (single enum) ---
    $trait_secondary_slugs = taxa_facets_normalize_param( $request->get_param( 'trait_secondary' ) );
    if ( ! empty( $trait_secondary_slugs ) ) {
        $trait_secondary_values = taxa_map_trait_secondary_params( $trait_secondary_slugs );
        if ( ! empty( $trait_secondary_values ) ) {
            $placeholders = implode( ',', array_fill( 0, count( $trait_secondary_values ), '%d' ) );
            $where[]      = "f.trait_secondary IN ($placeholders)";
            foreach ( $trait_secondary_values as $tv ) {
                $params[] = (int) $tv;
            }
        }
    }

    // --- DIET ---
    $diet_slugs = taxa_facets_normalize_param( $request->get_param( 'diet' ) );
    if ( ! empty( $diet_slugs ) ) {
        $diet_values = taxa_map_diet_params( $diet_slugs );
        if ( ! empty( $diet_values ) ) {
            $placeholders = implode( ',', array_fill( 0, count( $diet_values ), '%d' ) );
            $where[]      = "f.diet IN ($placeholders)";
            foreach ( $diet_values as $dv ) {
                $params[] = (int) $dv;
            }
        }
    }

    // --- MULTI (bitmasks) ---
    $color_slugs = taxa_facets_normalize_param( $request->get_param( 'colors' ) );
    if ( ! empty( $color_slugs ) ) {
        $color_mask = taxa_build_color_mask( $color_slugs );
        if ( $color_mask ) {
            $where[]  = '(f.color_mask & %d) = %d';
            $params[] = (int) $color_mask;
            $params[] = (int) $color_mask;
        }
    }

    $behavior_slugs = taxa_facets_normalize_param( $request->get_param( 'behaviors' ) );
    if ( ! empty( $behavior_slugs ) ) {
        $behavior_mask = taxa_build_behavior_mask( $behavior_slugs );
        if ( $behavior_mask ) {
            $where[]  = '(f.behavior_mask & %d) != 0';
            $params[] = (int) $behavior_mask;
        }
    }

    $habitat_slugs = taxa_facets_normalize_param( $request->get_param( 'habitats' ) );
    if ( ! empty( $habitat_slugs ) ) {
        $habitat_mask = taxa_build_habitat_mask( $habitat_slugs );
        if ( $habitat_mask ) {
            $where[]  = '(f.habitat_mask & %d) != 0';
            $params[] = (int) $habitat_mask;
        }
    }

    $call_type_slugs = taxa_facets_normalize_param( $request->get_param( 'call_types' ) );
    if ( ! empty( $call_type_slugs ) ) {
        $call_type_mask = taxa_build_call_type_mask( $call_type_slugs );
        if ( $call_type_mask ) {
            $where[]  = '(f.call_type_mask & %d) != 0';
            $params[] = (int) $call_type_mask;
        }
    }

    // --- TEXT SEARCH ---
    $search = trim( (string) $request->get_param( 'search' ) );
    if ( '' !== $search ) {
        $like     = '%' . $wpdb->esc_like( $search ) . '%';
        $where[]  = '(p.post_title LIKE %s OR p.post_excerpt LIKE %s OR p.post_content LIKE %s)';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    // --- TAXA RANK FILTER (NOW f.taxa_rank) ---
    $taxa_rank = trim( (string) $request->get_param( 'taxa_rank' ) );
    if ( '' !== $taxa_rank ) {
        $where[]  = 'f.taxa_rank = %s';
        $params[] = $taxa_rank;
    }

    if ( 'popular' === $sort ) {
        if ( $has_popularity && $has_last_viewed ) {
            $order_by = 'f.popularity DESC, f.last_viewed DESC, p.post_title ASC';
        } elseif ( $has_popularity ) {
            $order_by = 'f.popularity DESC, p.post_title ASC';
        } else {
            // No popularity column yet—fallback to title
            $order_by = 'p.post_title ASC';
        }
    } elseif ( 'newest' === $sort ) {
        $order_by = 'p.post_date DESC, p.ID DESC';
    } elseif ( 'title' === $sort ) {
        $order_by = 'p.post_title ASC';
    }


    $where_sql = implode( ' AND ', $where );

    $sql = "
        SELECT SQL_CALC_FOUND_ROWS
            p.ID,
            p.post_title,
            p.post_excerpt,
            p.post_name,

            f.size,
            f.shape_primary,
            f.shape_secondary,
            f.pattern,
            f.trait_primary,
            f.trait_secondary,
            f.diet,
            f.color_mask,
            f.call_type_mask,
            f.behavior_mask,
            f.habitat_mask,

            -- NEW: from facets table
            f.taxa_rank,
            f.extinct

        {$join_sql}
        WHERE {$where_sql}
        ORDER BY {$order_by}
        LIMIT %d OFFSET %d
    ";

    $params[] = $per_page;
    $params[] = $offset;

    $prepared = $wpdb->prepare( $sql, $params );

    error_log('[FACETS][SQL] ' . $prepared);
    error_log('[FACETS][SQL][last_error] ' . $wpdb->last_error);
    error_log('[FACETS][SQL][num_params] ' . count($params));

    $rows     = $wpdb->get_results( $prepared, ARRAY_A );

    error_log('[FACETS][SQL][rows_count] ' . ( is_array($rows) ? count($rows) : -1 ));
    error_log('[FACETS][SQL][last_query] ' . $wpdb->last_query);


    $total = 0;
    if ( empty( $wpdb->last_error ) ) {
        $total = (int) $wpdb->get_var( 'SELECT FOUND_ROWS()' );
    }

    $items = array();

    foreach ( $rows as $row ) {
        $post_id = (int) $row['ID'];

        // Lazy ensure (fills f.taxa_rank / f.extinct if empty)
        // This is safe and low-cost; it updates only when missing.
        taxa_facets_ensure_rank_extinct( $post_id );

        // If ensure updated, you may still be using stale $row values for this request.
        // To keep it fast, we only fall back to $row; next request will be correct.
        // If you want it immediate, we can add a tiny re-fetch of just those 2 fields.
        $taxa_rank_row = isset( $row['taxa_rank'] ) ? (string) $row['taxa_rank'] : '';
        $extinct_row   = isset( $row['extinct'] ) ? (int) $row['extinct'] : 0;

        $conservation_status = get_post_meta( $post_id, 'conservation_status', true );
        $facet_lines         = taxa_facets_build_summary_from_row( $row );

        $raw_title   = get_the_title( $post_id );
        $raw_excerpt = get_the_excerpt( $post_id );

        $title   = html_entity_decode( $raw_title,   ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $excerpt = html_entity_decode( $raw_excerpt, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

        $items[] = array(
            'id'                  => $post_id,
            'title'               => $title,
            'link'                => get_permalink( $post_id ),
            'excerpt'             => $excerpt,
            'image'               => get_the_post_thumbnail_url( $post_id, 'medium' ),
            'conservation_status' => $conservation_status,
            'facets'              => $facet_lines,

            // FIXED: return actual row values, not request param
            'taxa_rank'           => $taxa_rank_row,
            'extinct'             => $extinct_row,
        );
    }

    return new WP_REST_Response(
        array(
            'items'    => $items,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per_page,
        )
    );
}


/**
 * Front-end facets configuration (dynamic defaults).
 *
 * Child plugins should customize:
 *  - the maps (preferred): taxa_facets_color_map, taxa_facets_habitat_map, etc.
 *  - and/or the UI config: taxa_facets_frontend_options
 */
function taxa_facets_get_frontend_options() {

    // Pull maps (these are already filterable by child plugins)
    $size_map     = (array) taxa_facets_get_size_enum_map();     // slug => int
    $color_map    = (array) taxa_facets_get_color_map();         // slug => bit
    $habitat_map  = (array) taxa_facets_get_habitat_map();       // slug => bit
    $behavior_map = (array) taxa_facets_get_behavior_map();      // slug => bit
    $diet_map     = function_exists('taxa_facets_get_diet_enum_map') ? (array) taxa_facets_get_diet_enum_map() : array();

    // Build options from map keys
    $size_options = array();
    if ( ! empty( $size_map ) ) {
        // Add an "Any" option for single-select enums
        $size_options[] = array( 'slug' => '', 'label' => 'Any' );
        foreach ( array_keys( $size_map ) as $slug ) {
            $size_options[] = array(
                'slug'  => (string) $slug,
                'label' => taxa_facets_pretty_slug( $slug ),
            );
        }
    }

    $color_options = array();
    if ( ! empty( $color_map ) ) {
        foreach ( array_keys( $color_map ) as $slug ) {
            $color_options[] = array(
                'slug'  => (string) $slug,
                'label' => taxa_facets_pretty_slug( $slug ),
                // optional: let CSS class be derived from slug
                'class' => 'taxa-chip--' . sanitize_html_class( $slug ),
            );
        }
    }

    $habitat_options = array();
    if ( ! empty( $habitat_map ) ) {
        foreach ( array_keys( $habitat_map ) as $slug ) {
            $habitat_options[] = array(
                'slug'  => (string) $slug,
                'label' => taxa_facets_pretty_slug( $slug ),
            );
        }
    }

    $behavior_options = array();
    if ( ! empty( $behavior_map ) ) {
        foreach ( array_keys( $behavior_map ) as $slug ) {
            $behavior_options[] = array(
                'slug'  => (string) $slug,
                'label' => taxa_facets_pretty_slug( $slug ),
            );
        }
    }

    $diet_options = array();
    if ( ! empty( $diet_map ) ) {
        foreach ( array_keys( $diet_map ) as $slug ) {
            $diet_options[] = array(
                'slug'  => (string) $slug,
                'label' => taxa_facets_pretty_slug( $slug ),
            );
        }
    }

    // Dynamic default config — only include facets that actually have options.
    $config = array();

    if ( ! empty( $size_options ) ) {
        $config['size'] = array(
            'enabled' => true,
            'label'   => 'Size',
            'key'     => 'size',
            'type'    => 'enum',
            'multi'   => false,
            'options' => $size_options,
        );
    }

    if ( ! empty( $color_options ) ) {
        $config['colors'] = array(
            'enabled' => true,
            'label'   => 'Color',
            'key'     => 'colors',
            'type'    => 'color',
            'multi'   => true,
            'options' => $color_options,
        );
    }

    if ( ! empty( $habitat_options ) ) {
        $config['habitats'] = array(
            'enabled' => true,
            'label'   => 'Habitat',
            'key'     => 'habitats',
            'type'    => 'bitmask',
            'multi'   => true,
            'options' => $habitat_options,
        );
    }

    if ( ! empty( $behavior_options ) ) {
        $config['behaviors'] = array(
            'enabled' => true,
            'label'   => 'Behavior',
            'key'     => 'behaviors',
            'type'    => 'bitmask',
            'multi'   => true,
            'options' => $behavior_options,
        );
    }

    if ( ! empty( $diet_options ) ) {
        $config['diet'] = array(
            'enabled' => true,
            'label'   => 'Diet',
            'key'     => 'diet',
            'type'    => 'enum',
            'multi'   => true, // if you want multi-select; change if needed
            'options' => $diet_options,
        );
    }

    // Let child plugins reorder/hide/rename/add facets
    return apply_filters( 'taxa_facets_frontend_options', $config );
}



/**
 * Shortcode [taxa_explorer]
 * Renders the Merlin-style facets container + results shell.
 *
 * Now uses dynamic facet options based on the configured maps (and allows
 * child plugins like AvianDiscovery to customize via a filter).
 */
function taxa_facets_explorer_shortcode( $atts = array() ) {
    // Enqueue JS/CSS.
    taxa_facets_enqueue_assets();

    // Allow shortcode attributes if needed in future (backwards compatible).
    $atts = shortcode_atts(
        array(
            'taxa_rank' => '',   // optional override of default rank
            'scope'     => '',   // child plugins can use this (e.g. "snakes", "lizards", "trees")
        ),
        $atts,
        'taxa_explorer'
    );

    // Default taxa rank: shortcode override > filter > empty.
    $default_taxa_rank = $atts['taxa_rank'];
    if ( '' === $default_taxa_rank ) {
        $default_taxa_rank = apply_filters( 'taxa_facets_default_taxa_rank', '' );
    }

    /**
     * Let child plugins provide locked facets as a JSON string.
     *
     * Example JSON (child plugin builds this):
     *   {"group":["snakes-serpentes"]}
     *   {"growth_form":["tree"]}
     */
    $locked_facets_json = apply_filters(
        'taxa_facets_locked_facets_json',
        '',
        $atts
    );


    // 1) Pull maps from the generic helpers (these are filtered by the child plugin).
    $size_map       = taxa_facets_get_size_enum_map();      // slug => int
    $color_map      = taxa_facets_get_color_map();          // slug => bit
    $habitat_map    = taxa_facets_get_habitat_map();        // slug => bit
    $behavior_map   = taxa_facets_get_behavior_map();       // slug => bit

    // 2) Build default UI option lists from the maps.
    //    Each option: [ 'slug' => 'sparrow', 'label' => 'Sparrow' ]
    $size_options = array();
    foreach ( array_keys( (array) $size_map ) as $slug ) {
        $size_options[] = array(
            'slug'  => $slug,
            'label' => taxa_facets_pretty_slug( $slug ),
        );
    }

    $color_options = array();
    foreach ( array_keys( (array) $color_map ) as $slug ) {
        $color_options[] = array(
            'slug'  => $slug,
            'label' => taxa_facets_pretty_slug( $slug ),
        );
    }

    $habitat_options = array();
    foreach ( array_keys( (array) $habitat_map ) as $slug ) {
        $habitat_options[] = array(
            'slug'  => $slug,
            'label' => taxa_facets_pretty_slug( $slug ),
        );
    }

    $behavior_options = array();
    foreach ( array_keys( (array) $behavior_map ) as $slug ) {
        $behavior_options[] = array(
            'slug'  => $slug,
            'label' => taxa_facets_pretty_slug( $slug ),
        );
    }

    // 3) Base UI config (parent plugin default).
    //    AvianDiscovery (or any domain child plugin) can override this structure
    //    via the `taxa_facets_frontend_options` filter.
    $ui_config = array(
        'size'      => array(
            'label'   => __( 'Size similar to a', 'taxonomy-api' ),
            'facet'   => 'size',
            'multi'   => false,
            'options' => $size_options,
        ),
        'colors'    => array(
            'label'   => __( 'Color', 'taxonomy-api' ),
            'facet'   => 'colors',
            'multi'   => true,
            'options' => $color_options,
        ),
        'habitats'  => array(
            'label'   => __( 'Habitat', 'taxonomy-api' ),
            'facet'   => 'habitats',
            'multi'   => true,
            'options' => $habitat_options,
        ),
        'behaviors' => array(
            'label'   => __( 'Behavior', 'taxonomy-api' ),
            'facet'   => 'behaviors',
            'multi'   => true,
            'options' => $behavior_options,
        ),
    );

    /**
     * Allow child plugins (e.g. AvianDiscovery) to:
     *  - Reorder or remove options.
     *  - Change labels (e.g., "Forests & Woodlands" vs "forest").
     *  - Hide sections entirely.
     *
     * Expected structure:
     *
     * [
     *   'size' => [
     *      'label'   => 'Size similar to a',
     *      'facet'   => 'size',
     *      'multi'   => false,
     *      'options' => [
     *          [ 'slug' => 'sparrow', 'label' => 'Sparrow' ],
     *          ...
     *      ],
     *   ],
     *   'colors' => [ ... ],
     *   'habitats' => [ ... ],
     *   'behaviors' => [ ... ],
     * ]
     */
    $ui_config = apply_filters( 'taxa_facets_frontend_options', $ui_config );

    // Guard so notices don’t explode if a child plugin removes a block.
    $size_cfg      = isset( $ui_config['size'] )      ? $ui_config['size']      : null;
    $colors_cfg    = isset( $ui_config['colors'] )    ? $ui_config['colors']    : null;
    $habitats_cfg  = isset( $ui_config['habitats'] )  ? $ui_config['habitats']  : null;
    $behaviors_cfg = isset( $ui_config['behaviors'] ) ? $ui_config['behaviors'] : null;

    // Default taxa rank (shortcode override or filter).
    $default_taxa_rank = apply_filters( 'taxa_facets_default_taxa_rank', '' );

    // Base Taxa dropdown options.
    $taxa_options = array(
        array(
            'value' => '',
            'label' => __( 'All taxa', 'taxonomy-api' ),
        ),
        array(
            'value' => 'order',
            'label' => __( 'Order', 'taxonomy-api' ),
        ),
        array(
            'value' => 'family',
            'label' => __( 'Family', 'taxonomy-api' ),
        ),
        array(
            'value' => 'subfamily',
            'label' => __( 'Sub Family', 'taxonomy-api' ),
        ),
        array(
            'value' => 'genus',
            'label' => __( 'Genus', 'taxonomy-api' ),
        ),
        array(
            'value' => 'species',
            'label' => __( 'Species', 'taxonomy-api' ),
        ),
        array(
            'value' => 'subspecies',
            'label' => __( 'Sub Species', 'taxonomy-api' ),
        ),
    );

    /**
    * Allow child plugins to adjust the taxa dropdown options.
    *
    * @param array $taxa_options List of [ 'value' => 'family', 'label' => 'Family' ].
    * @param array $atts         Shortcode attributes (incl. 'scope' for snakes / lizards).
    */
    $taxa_options = apply_filters( 'taxa_facets_taxa_dropdown_options', $taxa_options, $atts );

    // Default taxa rank (whatever you already have here).
    $default_taxa_rank = apply_filters( 'taxa_facets_default_taxa_rank', '' );

    // 🔍 Search placeholder – child plugins can override.
    $search_placeholder = apply_filters(
        'taxa_facets_search_placeholder',
        __( 'Search...', 'taxonomy-api' ), // parent default
        $atts
    );

    ob_start();
    ?>

    <div class="taxa-explorer"
        data-taxa-facets-root
        data-default-taxa-rank="<?php echo esc_attr( $default_taxa_rank ); ?>"
        <?php if ( ! empty( $locked_facets_json ) ) : ?>
            data-locked-facets='<?php echo esc_attr( $locked_facets_json ); ?>'
        <?php endif; ?>
    >



        <!-- LEFT: results / pills / pagination -->
        <div class="taxa-explorer__results-wrap">
            <div class="taxa-explorer__toolbar" data-taxa-toolbar>
                <!-- TOP ROW: search + taxa select + filters icon -->
                <div class="taxa-toolbar__row taxa-toolbar__row--primary">
                    <div class="taxa-toolbar__left">
                        <!-- Search pill -->
                        <div class="taxa-toolbar__search-pill">
                            <span class="taxa-toolbar__search-icon" aria-hidden="true"></span>
                            <label class="screen-reader-text" for="taxa-search-input">
                                <?php echo esc_html( $search_placeholder ); ?>
                            </label>
                            <input
                                id="taxa-search-input"
                                type="search"
                                class="taxa-toolbar__search-input"
                                placeholder="<?php echo esc_attr( $search_placeholder ); ?>"
                                autocomplete="off"
                                data-facet-search
                            />
                        </div>
                        <!-- Taxa dropdown (your existing options) -->
                        <div class="taxa-toolbar__selects">
                            <div class="taxa-toolbar__select-wrap">
                                <span class="taxa-toolbar__select-label">Taxa</span>
                                <select class="taxa-toolbar__select taxa-select" data-taxa-select>
                                    <?php foreach ( $taxa_options as $opt ) : ?>
                                        <option value="<?php echo esc_attr( $opt['value'] ); ?>">
                                            <?php echo esc_html( $opt['label'] ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <span class="taxa-toolbar__select-caret" aria-hidden="true"></span>
                            </div>
                        </div>
                    </div>

                    <!-- Filters icon button (opens same modal) -->
                    <button
                        type="button"
                        class="taxa-toolbar__filters-icon"
                        data-filters-toggle
                        aria-expanded="false"
                        aria-controls="taxa-filters-modal"
                    >
                        <span class="taxa-toolbar__filters-label" data-filters-label>
                            Filters
                        </span>
                        <span class="taxa-toolbar__filters-badge" data-filters-badge></span>
                    </button>

                </div>

                <!-- BOTTOM ROW: count + layout toggle -->
                <div class="taxa-toolbar__row taxa-toolbar__row--secondary">
                    <span class="taxa-results-count" data-results-count>Loading…</span>

                    <div class="taxa-toolbar__secondary-right">
                        <div class="taxa-layout-toggle" role="radiogroup" aria-label="Card layout">
                            <button type="button"
                                    class="taxa-layout-btn is-active"
                                    data-layout="grid"
                                    aria-label="Grid layout"
                                    title="Grid layout">
                            </button>
                            <button type="button"
                                    class="taxa-layout-btn"
                                    data-layout="large"
                                    aria-label="Large card layout"
                                    title="Large card layout">
                            </button>
                            <button type="button"
                                    class="taxa-layout-btn"
                                    data-layout="compact"
                                    aria-label="Compact layout"
                                    title="Compact layout">
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Active filter pills -->
            <div class="taxa-explorer__active-filters" data-active-filters></div>

            <div class="taxa-explorer__results" data-results-list>
                <!-- Cards get injected here -->
            </div>

            <div class="taxa-explorer__pagination" data-pagination>
                <!-- Pagination controls injected here -->
            </div>
        </div>

        <!-- RIGHT: sidebar filters -->
        <div class="taxa-explorer__filters" id="taxa-filters-modal" data-filters-panel>
            <div class="taxa-filters-panel">
                <header class="taxa-filters-panel__header">
                    <span class="taxa-filters-panel__title">Filters</span>
                    <div class="taxa-filters-panel__header-actions">
                        <button type="button"
                                class="taxa-filters-panel__clear-all"
                                data-action="clear-all"
                                aria-label="Clear all filters"
                                title="Clear all filters">
                            Clear all
                        </button>
                        <button type="button"
                                class="taxa-filters-panel__close"
                                data-filters-close
                                aria-label="Close filters"
                                title="Close filters">
                            &times;
                        </button>
                    </div>
                </header>

                <div class="taxa-filters-panel__body">
                
                <header class="taxa-filters-panel__footer">
                    <button type="button"
                            class="taxa-filters-panel__apply"
                            data-filters-apply>
                        Apply filters
                    </button>
                </header>

					<!-- Include extinct toggle -->
					<section class="taxa-explorer__section taxa-explorer__section--toggle">
						<div class="taxa-section__header">
							<h3 class="taxa-explorer__label">Include extinct</h3>
						</div>

						<label class="taxa-toggle">
							<input type="checkbox" class="taxa-toggle__input" data-include-extinct />
							<span class="taxa-toggle__ui" aria-hidden="true"></span>
							<span class="taxa-toggle__text">Show extinct species in results</span>
						</label>
					</section>

                    <?php
                    // Dynamic facets from parent+child config:
                    $facets = taxa_facets_get_frontend_options();

                    foreach ( $facets as $id => $facet ) {
                        if ( empty( $facet['enabled'] ) ) {
                            continue;
                        }

                        $key     = isset( $facet['key'] ) ? $facet['key'] : $id;
                        $label   = isset( $facet['label'] ) ? $facet['label'] : taxa_facets_pretty_slug( $key );
                        $type    = isset( $facet['type'] )  ? $facet['type']  : 'enum';
                        $multi   = ! empty( $facet['multi'] );
                        $options = isset( $facet['options'] ) && is_array( $facet['options'] ) ? $facet['options'] : array();

                        ?>
                        <section class="taxa-explorer__section">
                            <div class="taxa-section__header">
                                <h3 class="taxa-explorer__label">
                                    <?php echo esc_html( $label ); ?>
                                </h3>

                                <?php
                                // Show "Clear" link for multi facets (colors, behaviors, etc.)
                                if ( $multi ) : ?>
                                    <button type="button"
                                            class="taxa-section__clear"
                                            data-action="<?php echo esc_attr( 'clear-' . $key ); ?>"
                                            aria-label="<?php echo esc_attr( 'Clear ' . strtolower( $label ) . ' filters' ); ?>"
                                            title="<?php echo esc_attr( 'Clear ' . strtolower( $label ) ); ?>">
                                        Clear
                                    </button>
                                <?php endif; ?>
                            </div>

                            <?php
                            // Determine chip container classes
                            $chips_classes = 'taxa-explorer__chips';
                            if ( 'color' === $type || 'colors' === $key ) {
                                $chips_classes .= ' taxa-explorer__chips--colors';
                            }
                            ?>
                            <div class="<?php echo esc_attr( $chips_classes ); ?>"
                                 data-facet="<?php echo esc_attr( $key ); ?>"
                                 <?php echo $multi ? ' data-multi="1"' : ''; ?>>

                                <?php foreach ( $options as $opt ) :
                                    $slug  = isset( $opt['slug'] )  ? (string) $opt['slug']  : '';
                                    $opt_label = isset( $opt['label'] ) ? (string) $opt['label'] : $slug;
                                    $extra_class = isset( $opt['class'] ) ? ' ' . $opt['class'] : '';

                                    // Base chip classes
                                    $chip_classes = 'taxa-chip';

                                    if ( 'color' === $type || 'colors' === $key ) {
                                        $chip_classes .= ' taxa-chip--color' . $extra_class;
                                    }
                                    ?>
                                    <button
                                        type="button"
                                        class="<?php echo esc_attr( $chip_classes ); ?>"
                                        data-facet-value="<?php echo esc_attr( $slug ); ?>"
                                        data-facet-label="<?php echo esc_attr( $opt_label ); ?>"
                                        aria-label="<?php echo esc_attr( $opt_label ); ?>"
                                        title="<?php echo esc_attr( $opt_label ); ?>"
                                    >
                                        <?php
                                        // Color chips are visual dots; others show text.
                                        if ( 'color' !== $type && 'colors' !== $key ) {
                                            echo esc_html( $opt_label );
                                        }
                                        ?>
                                    </button>
                                <?php endforeach; ?>

                            </div>
                        </section>
                        <?php
                    }
                    ?>

                </div><!-- /.taxa-filters-panel__body -->


            </div><!-- /.taxa-filters-panel -->
        </div>
        <!-- /.filters -->
        <div class="taxa-explorer__backdrop" data-filters-backdrop></div>

    </div>
    <?php
    return ob_get_clean();
}

add_shortcode( 'taxa_explorer', 'taxa_facets_explorer_shortcode' );


add_action( 'wp_enqueue_scripts', function () {

    $handle = 'taxa-facets-frontend';

    wp_enqueue_style(
        $handle,
        plugin_dir_url( __FILE__ ) . '../assets/css/facets.css',
        [],
        '1.0.0'
    );

    // Base assets URL (points to /assets/, NOT /assets/css/)
    $plugin_root_url = plugin_dir_url( dirname( __FILE__ ) ); // strips /includes/
    $assets_url      = $plugin_root_url . 'assets/';          // plugin-root assets


    wp_add_inline_style(
        $handle,
        ":root{
            --taxa-iridescent-gif: url('{$assets_url}iridescent.gif');
            --taxa-patterned-gif: url('{$assets_url}patterned.gif');
        }"
    );
});



/**
 * Enqueue JS + base styles for Merlin-like layout.
 */
function taxa_facets_enqueue_assets() {
    $handle = 'taxa-facets-explorer';

    // JS
    wp_enqueue_script(
        $handle,
        plugins_url( '../assets/js/taxa-facets-explorer.js', __FILE__ ),
        array(),
        '1.0.0',
        true
    );

    wp_localize_script(
        $handle,
        'TaxaFacets',
        array(
            'restUrl' => esc_url_raw( rest_url( 'taxa/v1/search' ) ),
        )
    );

    // Small inline script to handle filters toggle and layout.
    $inline_js = <<<JS
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-taxa-facets-root]').forEach(function(root){
            var layoutBtns = root.querySelectorAll('[data-layout]');

            // Layout toggle (grid / large / compact)
            if (layoutBtns.length) {
                function setLayout(layout) {
                    root.classList.remove('taxa-layout--grid', 'taxa-layout--large', 'taxa-layout--compact');
                    root.classList.add('taxa-layout--' + layout);
                }

                // Default layout
                setLayout('grid');

                layoutBtns.forEach(function(btn){
                    btn.addEventListener('click', function () {
                        var layout = btn.getAttribute('data-layout') || 'grid';
                        setLayout(layout);
                        layoutBtns.forEach(function(b){
                            b.classList.toggle('is-active', b === btn);
                        });
                    });
                });
            }
        });
    });
    JS;

    wp_add_inline_script( $handle, $inline_js );

    // CSS handle
    $css_handle = 'taxa-facets-explorer-style';

    wp_register_style( $css_handle, false, array(), '1.0.0' );
    wp_enqueue_style( $css_handle );

    // (CSS unchanged from your last version; keeping as-is)
        $css = '
    .taxa-explorer {
        display: flex !important;
        gap: 0rem;
        align-items: flex-start;
        font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    }
    .taxa-explorer__results-wrap {
        flex: 1 1 auto;
        min-width: 0;
    }
    .taxa-explorer__filters {
        width: 400px;
        max-width: 100%;
        position: sticky;
        top: 1.5rem;
        flex-shrink: 0;
    }
    .taxa-explorer--filters-collapsed .taxa-explorer__filters {
        display: none !important;
    }
    .taxa-explorer--filters-collapsed .taxa-explorer__results-wrap {
        flex: 1 1 100%;
        max-width: 100%;
    }
    @media (max-width: 960px) {
        .taxa-explorer {
            flex-direction: column !important;
        }
        .taxa-explorer__filters {
            position: static;
            width: 100%;
        }
    }

    .taxa-filters-panel {
        background: #1E1F29;
        border-radius: 16px;
        padding: 1rem 1.25rem 1.25rem;
        border: 1px solid #1f2937;
        box-shadow: 0 18px 40px rgba(0,0,0,0.55);
        color: #e5e7eb;
    }
    .taxa-filters-panel__header {
        display: flex;
        align-items: center;
        justify-content: space-between;
    }
    .taxa-filters-panel__title {
        font-size: 13px;
        letter-spacing: 0.12em;
        text-transform: uppercase;
        color: #9ca3af;
    }
    .taxa-filters-panel__clear-all {
        border: none !important;
        background: transparent !important;
        color: #9ca3af !important;
        font-size: 12px !important;
        cursor: pointer;
        padding: 0 !important;
        box-shadow: none !important;
    }

    .taxa-filters-panel__body {
    }
    .taxa-explorer__section {
        padding: 0.25rem 0;
        border-bottom: 1px solid #0b1220;
    }
    .taxa-explorer__section:last-child {
        border-bottom: none;
    }
    .taxa-section__header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 0.45rem;
    }
    .taxa-explorer__label {
        font-size: 13px;
        font-weight: 600;
        margin: 0;
        color: #e5e7eb;
    }
    .taxa-section__clear {
        border: none !important;
        background: transparent !important;
        font-size: 11px !important;
        color: #9ca3af !important;
        cursor: pointer;
        padding: 0 !important;
        box-shadow: none !important;
    }

    .taxa-explorer__chips {
        display: flex;
        flex-wrap: wrap;
        gap: 0.4rem;
    }


    /* Active filter pills */
    .taxa-explorer__active-filters {
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
        padding-bottom: 0.5rem;
        padding-left: 20px;
        padding-right: 20px;
        margin-bottom: 0.5rem;
        background: #020617;
    }
    .taxa-active-pill {
        display: inline-flex;
        align-items: center;
        gap: 0.2rem;
        padding: 0.15rem 0.45rem;
        border-radius: 999px;
        background: #e5e7eb;
        border: 1px solid #d1d5db;
        font-size: 11px;
        color: #111827;
        line-height: 1.1;
    }
    .taxa-active-pill--clear {
        background: #f97316;
        border-color: #ea580c;
        color: #fff;
    }
    .taxa-active-pill__label {
        white-space: nowrap;
    }
    .taxa-active-pill__remove, .taxa-active-pill taxa-active-pill--clear {
        border: none;
        background: transparent;
        color: inherit;
        cursor: pointer;
        font-size: 11px;
        line-height: 1;
        padding: 0px !important;
    }

    .taxa-explorer__results-meta {
        display: flex;
        justify-content: space-between;
        align-items: center;
        font-size: 13px;
        color: #4b5563;
        margin-bottom: 0.5rem;
    }
    .taxa-filters-toggle {
        border-radius: 999px !important;
        border: 1px solid #d1d5db !important;
        background: #ffffff !important;
        color: #374151 !important;
        font-size: 12px !important;
        padding: 0.25rem 0.8rem !important;
        cursor: pointer;
        box-shadow: none !important;
    }

    .taxa-explorer__results {
        display: grid;
        grid-template-columns: repeat(auto-fill,minmax(220px,1fr));
        gap: 0.5rem;
        padding: 10px;
    }
    .taxa-card {
        border-radius: 18px;
        padding: 0.8rem;
        background: #1E1F29;
        color: #f9fafb;
        text-decoration: none;
        display: block;
        border: 1px solid #2B2B33;
    }
    .taxa-card__image {
        width: 100%;
        height: 190px;
        object-fit: cover;
        border-radius: 14px;
        margin-bottom: 0.5rem;
        background:#000;
    }
    .taxa-card__title {
        font-size: 16px;
        font-weight: 500;
        margin: 0 0 0.25rem;
        color: #fff;
    }
    .taxa-card__excerpt {
        font-size: 13px;
        opacity: 0.8;
        margin: 0;
    }
    .taxa-card__excerpt:active {
        color:#eee;
        opacity: 0.8;
        margin: 0;
    }
    .taxa-card__excerpt:hover {
        color:#eee;
        opacity: 0.8;
        margin: 0;
    }
    .taxa-explorer__pagination {
        margin-top: 1rem;
        display: flex;
        align-items: center;
        gap: 0.75rem;
        flex-wrap: wrap;
        padding: 20px;
    }
    .taxa-pagination-meta {
        font-size: 12px;
        color: #6b7280;
    }
    .taxa-pagination-buttons {
        display: inline-flex;
        gap: 0.35rem;
        flex-wrap: wrap;
    }
    .taxa-page-btn {
        padding: 0.25rem 1rem;
        font-size: 12px;
        border-radius: 999px;
        border: 1px solid #1f2937;
        background:#020617;
        color:#e5e7eb;
        cursor:pointer;
    }

    .taxa-page-btn.is-active {
        background:#4f46e5;
        border-color:#6366f1;
        color:#f9fafb;
    }
        /* Results meta layout */
    .taxa-results-meta-left {
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    .taxa-results-meta-right {
        display: flex;
        align-items: center;
        gap: 0.75rem;
    }

    /* Layout toggle buttons */
    .taxa-layout-toggle {
        display: inline-flex;
        align-items: center;
        gap: 0.25rem;
        padding: 0.15rem 0.25rem;
        border-radius: 999px;
        background: #020617;
        border: 1px solid #1f2937;
    }
    .taxa-layout-btn {
        width: 26px;
        height: 18px;
        border-radius: 6px;
        border: none !important;
        background: transparent !important;
        position: relative;
        cursor: pointer;
        box-shadow: none !important;
        padding: 0 !important;
    }
    .taxa-layout-btn::before,
    .taxa-layout-btn::after {
        content: "";
        position: absolute;
        border-radius: 3px;
        background: #4b5563;
        opacity: 0.9;
    }

    /* Grid: 2x2 squares */
    .taxa-layout-btn[data-layout="grid"]::before {
        top: 3px;
        left: 4px;
        width: 7px;
        height: 5px;
        box-shadow:
            11px 0 0 0 #4b5563,
            0 7px 0 0 #4b5563,
            11px 7px 0 0 #4b5563;
    }
    .taxa-layout-btn[data-layout="grid"]::after {
        display: none;
    }

    /* Large: one big bar */
    .taxa-layout-btn[data-layout="large"]::before {
        top: 4px;
        left: 3px;
        right: 3px;
        height: 10px;
    }
    .taxa-layout-btn[data-layout="large"]::after {
        display: none;
    }

    /* Compact: 3 stacked bars */
    .taxa-layout-btn[data-layout="compact"]::before {
        top: 3px;
        left: 3px;
        right: 3px;
        height: 3px;
        box-shadow:
            0 5px 0 0 #4b5563,
            0 10px 0 0 #4b5563;
    }
    .taxa-layout-btn[data-layout="compact"]::after {
        display: none;
    }

    .taxa-layout-btn.is-active {
        background: #111827 !important;
        box-shadow: 0 0 0 1px #6366f1;
    }
    .taxa-layout-btn.is-active::before,
    .taxa-layout-btn.is-active::after {
        background: #e5e7eb;
        box-shadow:
            11px 0 0 0 #e5e7eb,
            0 7px 0 0 #e5e7eb,
            11px 7px 0 0 #e5e7eb;
    }
    /* Fix per-layout active variants so box-shadows stay correct */
    .taxa-layout-btn.is-active[data-layout="large"]::before {
        box-shadow: none;
    }
    .taxa-layout-btn.is-active[data-layout="compact"]::before {
        box-shadow:
            0 5px 0 0 #e5e7eb,
            0 10px 0 0 #e5e7eb;
    }

    .taxa-layout-btn.is-active {
        background: #111827 !important;
        border-radius: 999px;
        box-shadow: 0 0 0 1px #6366f1;
    }

    /* Layout-specific card tweaks */

    /* Default grid layout */
    .taxa-layout--grid .taxa-explorer__results {
        grid-template-columns: repeat(auto-fill,minmax(220px,1fr));
    }

    /* Large cards: big single-column rows */
    .taxa-layout--large .taxa-explorer__results {
        grid-template-columns: minmax(0,1fr);
    }
    .taxa-layout--large .taxa-card__image {
        height: 260px;
    }

    /* Compact layout: horizontal card with square image + title */
    .taxa-layout--compact .taxa-explorer__results {
        grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
    }

    .taxa-layout--compact .taxa-card__grid {
        flex-direction: row;
        align-items: center;
        gap: 0.75rem;
    }

    .taxa-layout--compact .taxa-card__media {
        flex: 0 0 auto;
    }

    /* Fixed square thumbnail */
    .taxa-layout--compact .taxa-card__image {
        width: 100px;
        height: 100px;
        max-height: 100px;
        object-fit: cover;
        border-radius: 12px;
        margin-bottom: 0; /* override default */
        background: #000;
    }

    .taxa-layout--compact .taxa-card__content {
        flex: 1 1 auto;
    }

    /* Title-only feel in compact */
    .taxa-layout--compact .taxa-card__title {
        margin: 0;
        font-size: 14px;
        line-height: 1.3;
    }

    /* Hide excerpt in compact layout */
    .taxa-layout--compact .taxa-card__excerpt {
        display: none;
    }

    /* Fixed square thumbnail: max-height 100px */
    .taxa-layout--compact .taxa-card__image {
        width: 100px;
        height: 100px;
        max-height: 100px;
        object-fit: cover;
        border-radius: 12px;
        margin-bottom: 0; /* override the default 0.5rem */
        background: #000;
    }

    .taxa-layout--compact .taxa-card__content {
        flex: 1 1 auto;
    }

    /* Title only for compact layout */
    .taxa-layout--compact .taxa-card__title {
        margin: 0;
        font-size: 14px;
        line-height: 1.3;
    }

    /* Hide excerpt in compact layout */
    .taxa-layout--compact .taxa-card__excerpt {
        display: none;
    }

    /* Base card layout skeleton */
    .taxa-card__grid {
        display: flex;
        flex-direction: column;
        gap: 0.75rem;
    }
    .taxa-card__media {
        flex: 0 0 auto;
    }
    .taxa-card__content {
        flex: 1 1 auto;
    }
    .taxa-card__meta {
        flex: 0 0 auto;
        font-size: 12px;
        color: #9ca3af;
    }
    .taxa-card__meta-section + .taxa-card__meta-section {
        margin-top: 0.75rem;
    }
    .taxa-card__meta-heading {
        text-transform: uppercase;
        letter-spacing: 0.12em;
        font-weight: 600;
        font-size: 11px;
        color: #e5e7eb;
        margin-bottom: 0.2rem;
    }
    .taxa-card__meta-value {
        font-size: 12px;
        line-height: 1.5;
        color: #e5e7eb;
    }

    /* Hide meta column in non-large layouts */
    .taxa-layout--grid .taxa-card__meta,
    .taxa-layout--compact .taxa-card__meta {
        display: none;
    }

    .taxa-card__meta-label {
        text-transform: uppercase;
        letter-spacing: 0.12em;
        font-weight: 600;
        font-size: 11px;
        opacity: 0.9;
    }

    /* Large layout: 3-column row (image | content | details) */
    .taxa-layout--large .taxa-explorer__results {
        grid-template-columns: minmax(0,1fr); /* single big card per row */
    }

    .taxa-layout--large .taxa-card__grid {
        display: grid;
        grid-template-columns: minmax(180px, 260px) minmax(0, 2fr) minmax(0, 1.5fr);
        gap: 1.25rem;
        align-items: stretch;
    }

    .taxa-layout--large .taxa-card__image {
        height: 220px;
    }

    .taxa-layout--large .taxa-card__content {
        align-self: center;
        color: #fff;
    }

    .taxa-layout--large .taxa-card__meta {
        align-self: stretch;
        border-left: 1px solid rgba(15,23,42,0.8);
        padding-left: 1.5rem;
        display: flex;
        align-items: flex-start;
        justify-content: flex-start;
    }

    /* Mobile / narrow screens: stack the large card vertically again */
    @media (max-width: 960px) {
        .taxa-layout--large .taxa-card__grid {
            display: flex;
            flex-direction: column;
        }
        .taxa-layout--large .taxa-card__meta {
            border-left: none;
            padding-left: 0;
            border-top: 1px solid rgba(15,23,42,0.8);
            padding-top: 0.75rem;
        }
    }
    .taxa-card:hover {
        color:#fff;
        background-color:#252837;
    }
    .taxa-explorer__results-meta {
        display:flex;
        justify-content:space-between;
        align-items:center;
        gap:1rem;
        margin-bottom:0.75rem;
    }
    .taxa-explorer__results-controls {
        display:flex;
        align-items:center;
        gap:0.75rem;
    }
    .taxa-search__input {
        padding:0.4rem 0.75rem;
        border-radius:999px;
        border:1px solid #1f2937;
        background:#020617;
        color:#e5e7eb;
        font-size:13px;
        min-width:220px;
    }
    .taxa-search__input::placeholder {
        color:#6b7280;
    }
    .taxa-explorer__backdrop {
        display:none;
    }
    @media (max-width: 767px) {
        .entry-content-wrap {
            padding: 0.5rem;
        }
        .taxa-card__excerpt {
            display: none;
        }
    }
    
    .taxa-card__rank {
        font-size: 12px;
        color: #9ca3af;
        margin: 0 0 0.2rem;
        text-transform: capitalize; /* just in case */
    }
    @media (max-width: 768px) {
        .taxa-explorer {
            position: relative;
            flex-direction: column;
            gap: 1rem;
        }

        /* Filters become a bottom sheet / full-screen overlay */
        .taxa-explorer__filters {
            position: fixed;
            inset: auto 0 0 0;
            max-width: none;
            background: #020617;
            padding: 1rem 1rem 1.5rem;
            z-index: 9999;
            box-shadow: 0 -10px 30px rgba(0,0,0,0.6);
            border-top-left-radius: 16px;
            border-top-right-radius: 16px;
            overflow-y: auto;
            max-height: 80vh;

            transform: translateY(100%);
            opacity: 0;
            pointer-events: none;
            transition: transform 0.25s ease, opacity 0.25s ease;
        }

        .taxa-explorer--filters-open .taxa-explorer__filters {
            transform: translateY(0);
            opacity: 1;
            pointer-events: auto;
        }

        .taxa-explorer__backdrop {
            display: block;
            position: fixed;
            inset: 0;
            background: rgba(15,23,42,0.65);
            z-index: 9998;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.25s ease;
        }

        .taxa-explorer--filters-open .taxa-explorer__backdrop {
            opacity: 1;
            pointer-events: auto;
        }

        /* Stack meta controls */
        .taxa-explorer__results-meta {
            flex-direction: column;
            align-items: flex-start;
        }

        /* Slightly tighter card padding on mobile */
        .taxa-card {
            padding: 0.0rem 0.0rem;
            border-radius: 16px;
        }

        /* --- GRID layout on mobile: 3-column photo grid (like Audubon) --- */
        .taxa-layout--grid .taxa-explorer__results {
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }

        .taxa-layout--grid .taxa-card__image {
            height: 140px;
            border-radius: 14px 14px 0px 0px;
        }

        .taxa-card__title {
            padding: 0.2rem 0.5rem;
        }

        /* --- LARGE + COMPACT layouts: single-column full-width rows --- */
        .taxa-layout--large .taxa-explorer__results,
        .taxa-layout--compact .taxa-explorer__results {
            grid-template-columns: 1fr;
        }

        /* Compact rows feel more like the Audubon list */
        .taxa-layout--compact .taxa-card__image {
            width: 50px;
            height: 50px;
            max-height: 50px;
        }

        .taxa-layout--compact .taxa-card__title {
            font-size: 15px;
        }

        .taxa-layout--compact .taxa-card {
            padding: 0.0rem 0.0rem;
        }
        
        .taxa-card__rank {
            font-size: 12px;
            color: #9ca3af;
            margin: 0 0 0.2rem;
            text-transform: capitalize; /* just in case */
            padding: 0.2rem 0.5rem;
        }
    }

    @media (min-width: 768px) {
        .taxa-explorer__backdrop {
            display: none !important;
        }
        .taxa-explorer__filters {
            position: sticky;
            top: 5rem;
            transform: none;
            opacity: 1;
            pointer-events: auto;
            max-height: calc(100vh - 2rem);
            overflow-y: auto;
            box-shadow: none;
            border-radius: 16px;
            margin: 20px;
        }
        /* Mobile toolbar adjustments */
        .taxa-explorer__toolbar {
            position: static;
            padding: 0.5rem 0 0.75rem;
            background: none;
            backdrop-filter: none;
        }

        .taxa-toolbar__primary {
            grid-template-columns: 1fr;
            border-radius: 16px;
        }

        .taxa-toolbar__field {
            border-right: none;
            border-bottom: 1px solid #1f2937;
            padding-bottom: 0.35rem;
            margin-bottom: 0.25rem;
        }
        .taxa-toolbar__field:last-child {
            border-bottom: none;
            margin-bottom: 0;
        }

        .taxa-toolbar__filters-btn {
            width: 100%;
            justify-content: center;
        }

        .taxa-toolbar__secondary {
            margin-top: 0.5rem;
            flex-direction: row;
            align-items: center;
            justify-content: space-between;
        }

    }

    html.taxa-no-scroll,
    body.taxa-no-scroll {
        overflow: hidden;
        height: 100%;
        overscroll-behavior: contain;
        /* iOS Safari is stubborn, but this helps */
    }

    /* Sticky toolbar above cards (desktop) */
    .taxa-explorer__toolbar {
        position: sticky;
        top: 0;
        z-index: 20;
        padding: 0.75rem 0 1rem;
        background: linear-gradient(#020617 0, rgba(2,6,23,0.98) 60%, rgba(2,6,23,0));
        backdrop-filter: blur(10px);
    }

    /* Main pill container like Audubon header */
    .taxa-toolbar__primary {
        display: grid;
        grid-template-columns: minmax(0, 2.2fr) minmax(0, 3fr) auto;
        gap: 0.5rem;
        align-items: stretch;
        background: #111827;
        border-radius: 999px;
        padding: 0.45rem 0.6rem;
        border: 1px solid #1f2937;
    }

    /* Fields inside pill */
    .taxa-toolbar__field {
        position: relative;
        padding: 0.1rem 0.75rem;
        display: flex;
        flex-direction: column;
        justify-content: center;
        border-right: 1px solid #1f2937;
    }
    .taxa-toolbar__field:last-child {
        border-right: none;
    }

    .taxa-toolbar__label {
        font-size: 11px;
        font-weight: 500;
        color: #9ca3af;
        margin-bottom: 0.15rem;
    }

    /* Select + search input styles inside toolbar */
    .taxa-toolbar__select,
    .taxa-toolbar__search-input {
        background: transparent;
        border: none;
        color: #f9fafb;
        font-size: 14px;
        outline: none;
        padding: 0;
        width: 100%;
    }

    .taxa-toolbar__select {
        appearance: none;
        cursor: pointer;
    }

    .taxa-toolbar__search-input::placeholder {
        color: #6b7280;
    }

    /* Filters button block on the right of the pill */
    .taxa-toolbar__filters-btn {
        border-radius: 18px;
        border: none;
        background: #1f2937;
        color: #f9fafb;
        padding: 0 0.9rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 14px;
        cursor: pointer;
        white-space: nowrap;
    }
    .taxa-toolbar__filters-btn:hover {
        background: #374151;
    }

    /* Secondary row: count + layout toggle */
    .taxa-toolbar__secondary {
        margin-top: 0.75rem;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
    }

    /* Slightly stronger styling for the count to match Audubon */
    .taxa-results-count {
        font-size: 13px;
        font-weight: 500;
        letter-spacing: 0.06em;
        text-transform: uppercase;
        color: #e5e7eb;
    }
    /* Sticky toolbar, like the Audubon header */
    .taxa-explorer__toolbar {
        position: sticky;
        top: 0;
        z-index: 40;
        background: #020617;
        padding: 0.5rem 0 0.5rem;
    }

    /* Two stacked rows */
    .taxa-toolbar__row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.75rem;
        padding: 10px;
    }
    .taxa-toolbar__row--secondary {
        margin-top: 0.1rem;
    }

    /* LEFT cluster on primary row */
    .taxa-toolbar__left {
        display: flex;
        align-items: center;
        gap: 0.9rem;
        flex: 1 1 auto;
    }

    /* Search pill */
    .taxa-toolbar__search-pill {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        background: #111827;
        border-radius: 999px;
        padding: 0.25rem 0.9rem;
        border: 1px solid #1f2937;
        flex: 1 1 auto;
        max-width: 380px;
    }
    .taxa-toolbar__search-icon {
        width: 16px;
        height: 16px;
        border-radius: 999px;
        border: 2px solid #6b7280;
        position: relative;
    }
    .taxa-toolbar__search-icon::after {
        content: "";
        position: absolute;
        width: 7px;
        height: 2px;
        border-radius: 999px;
        background: #6b7280;
        transform: rotate(45deg);
        right: -4px;
        bottom: -1px;
    }
    .taxa-toolbar__search-input {
        border: none;
        outline: none;
        background: transparent;
        color: #f9fafb;
        font-size: 14px;
        width: 100%;
    }
    .taxa-toolbar__search-input::placeholder {
        color: #6b7280;
    }

    /* “Family / Region” style dropdowns */
    .taxa-toolbar__selects {
        display: flex;
        align-items: center;
        gap: 1.4rem;
    }
    .taxa-toolbar__select-wrap {
        position: relative;
        display: inline-flex;
        flex-direction: column;
        gap: 0.05rem;
    }
    .taxa-toolbar__select-label {
        font-size: 11px;
        letter-spacing: 0.12em;
        text-transform: uppercase;
        color: #9ca3af;
    }
    .taxa-toolbar__select,
    .taxa-toolbar__ghost-button {
        background: transparent;
        border: none;
        color: #f9fafb;
        font-size: 14px;
        padding-right: 1.1rem;
        cursor: pointer;
    }
    .taxa-toolbar__select {
        appearance: none;
        -webkit-appearance: none;
    }
    .taxa-toolbar__select-caret {
        position: absolute;
        right: 5px;
        bottom: 6px;
        width: 7px;
        height: 7px;
        border-right: 1.5px solid #e5e7eb;
        border-bottom: 1.5px solid #e5e7eb;
        transform: rotate(45deg);
    }
    .taxa-toolbar__ghost-button {
        text-align: left;
        opacity: 0.7;
    }

    /* Filters icon button (square) */
    .taxa-toolbar__filters-icon {
        border-radius: 12px;
        border: none;
        background: #111827;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        position: relative;
        box-shadow: 0 0 0 1px #1f2937;
        font-size: 11px;
    }
    .taxa-toolbar__filters-icon {
        padding: 0 0.75rem;
        gap: 0.35rem;
    }

    .taxa-toolbar__filters-label {
        font-size: 12px;
        color: #e5e7eb;
    }
    .taxa-toolbar__filters-icon::before,
    .taxa-toolbar__filters-icon::after {
        position: absolute;
        width: 16px;
        height: 2px;
        border-radius: 999px;
        background: #e5e7eb;
    }
    .taxa-toolbar__filters-icon::before {
        top: 13px;
        box-shadow: 0 6px 0 0 #e5e7eb;
    }
    .taxa-toolbar__filters-icon::after {
        display: none;
    }

    /* Secondary row: count + sort + layout */
    .taxa-results-count {
        font-size: 13px;
        letter-spacing: 0.12em;
        text-transform: uppercase;
        font-weight: 600;
        color: #e5e7eb;
        padding-left: 10px;
    }
    .taxa-toolbar__secondary-right {
        display: flex;
        align-items: center;
        gap: 1.25rem;
    }

    /* “Most viewed” sort */
    .taxa-toolbar__sort {
        background: transparent;
        border: none;
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        cursor: pointer;
        color: #e5e7eb;
        font-size: 13px;
    }
    .taxa-toolbar__sort-label {
        letter-spacing: 0.04em;
    }
    .taxa-toolbar__sort-caret {
        width: 7px;
        height: 7px;
        border-right: 1.5px solid #e5e7eb;
        border-bottom: 1.5px solid #e5e7eb;
        transform: rotate(45deg);
    }
    #taxa-search-input {
        background-color: #000;
        color: #fff;
    }
    
    .taxa-filters-panel__header-actions {
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .taxa-filters-panel__close {
        border: none !important;
        background: transparent !important;
        color: #9ca3af !important;
        font-size: 24px !important;
        font-weight: 700 !important;
        line-height: 1;
        cursor: pointer;
        padding: 0px 0px 5px 25px !important;
        box-shadow: none !important;
    }

    /* Footer with Apply button */
    .taxa-filters-panel__footer {
        margin-top: 0.75rem;
        padding-top: 0.75rem;
        border-top: 1px solid #111827;
        display: flex;
        justify-content: flex-end;
    }

    /* Base Apply button */
    .taxa-filters-panel__apply {
        border-radius: 999px !important;
        border: none !important;
        background: #4f46e5 !important;
        color: #f9fafb !important;
        padding: 0.35rem 1.25rem !important;
        font-size: 13px !important;
        font-weight: 500 !important;
        cursor: pointer;
        box-shadow: 0 0 0 1px rgba(129,140,248,0.3) !important;
        transition: background 0.15s ease, box-shadow 0.15s ease, transform 0.1s ease;
    }

    /* When there are unapplied changes on mobile */
    .taxa-filters-panel__apply.is-dirty {
        background: linear-gradient(135deg, #f97316, #ef4444) !important;
        box-shadow:
            0 0 0 1px rgba(248, 171, 104, 0.6),
            0 8px 18px rgba(248, 113, 113, 0.45) !important;
        transform: translateY(-1px);
    }


    /* Desktop behavior: Apply is optional, but we can keep it visible.
    If you prefer hide on desktop, uncomment:

    @media (min-width: 769px) {
        .taxa-filters-panel__footer {
            display: none;
        }
    }
    */


    /* Visible state when there is at least one active filter */
    .taxa-toolbar__filters-badge.is-visible {
        opacity: 1;
        transform: scale(1);
    }

    /* default style (used while pending; color refined by classes below) */
    .taxa-toolbar__filters-badge {
        position: absolute;
        top: -4px;
        right: -4px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 16px;
        height: 16px;
        border-radius: 999px;
        font-size: 11px;
        line-height: 1;
        padding: 0 4px;
        color: #ffffff;
        opacity: 0;
        transform: scale(0.6);
        pointer-events: none;
        transition:
            opacity 0.15s ease,
            transform 0.15s ease,
            background-color 0.2s ease,
            color 0.2s ease;
    }

    /* BADGE when there are *no* pending changes — same background as inactive Apply button */
    .taxa-toolbar__filters-badge.is-applied {
        background: #4f46e5; /* Replace with the inactive Apply button background color */
        color: #ccc;         /* Slightly dimmer text to match inactive tone */
    }

    /* PENDING / DIRTY: orange, pulsing */
    .taxa-toolbar__filters-badge.is-dirty {
        background: #f97316; /* same as Apply button active */
        color: #ffffff;
        animation: taxa-badge-pulse 1.2s ease-out infinite;
    }

    /* APPLIED: calm slate + tiny ✓ */
    .taxa-toolbar__filters-badge.is-applied {
        background: #2a2f3a; /* match your inactive Apply background */
        color: #e5e7eb;
        animation: none;
    }

    .taxa-toolbar__filters-badge.is-applied::after {
        content: "✓";
        margin-left: 3px;
        font-size: 9px;
    }

    /* Subtle pulse animation for pending state */
    @keyframes taxa-badge-pulse {
        0% {
            transform: scale(1);
            box-shadow: 0 0 0 0 rgba(249,115,22,0.7);
        }
        70% {
            transform: scale(1.1);
            box-shadow: 0 0 0 6px rgba(249,115,22,0);
        }
        100% {
            transform: scale(1);
            box-shadow: 0 0 0 0 rgba(249,115,22,0);
        }
    }
    .taxa-page-ellipsis {
        padding: 0.25rem 0.5rem;
        font-size: 18px;
        font-weight: 500;
        color: #6b7280;
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }
	
	/* Include extinct toggle */
	.taxa-explorer__section--toggle {
		padding: 0.65rem 0;
	}
	.taxa-toggle {
		display: flex;
		align-items: center;
		gap: 0.6rem;
		cursor: pointer;
		user-select: none;
	}
	.taxa-toggle__input {
		position: absolute;
		opacity: 0;
		width: 1px;
		height: 1px;
	}
	.taxa-toggle__ui {
		width: 38px;
		height: 22px;
		border-radius: 999px;
		background: #111827;
		border: 1px solid #1f2937;
		position: relative;
		flex: 0 0 auto;
		transition: background 0.15s ease, border-color 0.15s ease;
	}
	.taxa-toggle__ui::after {
		content: "";
		position: absolute;
		top: 2px;
		left: 2px;
		width: 18px;
		height: 18px;
		border-radius: 999px;
		background: #9ca3af;
		transition: transform 0.15s ease, background 0.15s ease;
	}
	.taxa-toggle__input:checked + .taxa-toggle__ui {
		background: #4f46e5;
		border-color: #6366f1;
	}
	.taxa-toggle__input:checked + .taxa-toggle__ui::after {
		transform: translateX(16px);
		background: #ffffff;
	}
	.taxa-toggle__text {
		font-size: 12px;
		color: #e5e7eb;
		opacity: 0.9;
	}

    /* Make the image area a positioning context */
    .taxa-card__media {
        position: relative;
        overflow: hidden;
    }

    /* Make the image container a positioning context */
    .taxa-card__media {
        position: relative;
        overflow: hidden;
    }

    /* Base styling for the extinct ribbon text */
    .taxa-card__flag {
        font-size: 0.75rem;
        font-weight: 700;
        color: #fff;
        text-transform: uppercase;
    }

    /* Fancy folded ribbon in the top-right corner */
    .taxa-card__flag.ribbon {
        --f: 0.4em; /* folded part size */
        --r: 0.7em; /* angled cut-in */

        position: absolute;
        top: 0.75rem;
        right: calc(-1 * var(--f));
        padding-inline: 0.5em;
        line-height: 1.8;

        background: #FA6900;          /* ribbon color – tweak to match your theme */
        border-bottom: var(--f) solid #0005;
        border-left: var(--r) solid transparent;

        clip-path: polygon(
            var(--r) 0,
            100% 0,
            100% calc(100% - var(--f)),
            calc(100% - var(--f)) 100%,
            calc(100% - var(--f)) calc(100% - var(--f)),
            var(--r) calc(100% - var(--f)),
            0 calc(50% - var(--f) / 2)
        );

        z-index: 2;
    }

    
    @media (max-width: 768px) {
        .taxa-layout--compact .taxa-card__flag {
            font-size: 0.5rem;
            font-weight: 600;
            color: #fff;
            text-transform: uppercase;
        }

        .taxa-layout--compact .taxa-card__flag.ribbon {
            top: 0.25rem;
        }
        .taxa-toolbar__search-input {
            font-size: 16px;   /* key value */
            line-height: 1.3;
        }
    }
	';

    wp_add_inline_style( $css_handle, $css );
}
