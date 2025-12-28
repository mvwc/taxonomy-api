<?php
/**
 * Facet enums, bitmask builders, and meta mappers (generic core).
 *
 * IMPORTANT:
 *  - Existing stored values MUST NEVER be reordered within a given map.
 *  - Child / domain plugins should only APPEND new values to the end of each map.
 *
 * This file is intentionally GENERIC. It exposes:
 *  - Generic facet map getters (color, behavior, habitat, etc.) that are filterable.
 *  - Helpers to build bitmasks and map enum slugs <-> small ints.
 *
 * Domain-specific plugins (e.g. Avian, Plants, Marine, Mammals) should override or
 * extend these maps via filters such as:
 *
 *   - `taxa_facets_color_map`
 *   - `taxa_facets_call_type_map`
 *   - `taxa_facets_behavior_map`
 *   - `taxa_facets_habitat_map`
 *   - `taxa_facets_size_enum_map`
 *   - `taxa_facets_shape_primary_enum_map`
 *   - `taxa_facets_shape_secondary_enum_map`
 *   - `taxa_facets_pattern_enum_map`
 *   - `taxa_facets_trait_primary_enum_map`
 *   - `taxa_facets_trait_secondary_enum_map`
 *   - `taxa_facets_diet_enum_map`
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ---------------------------
 * COLOR (bitmask facet)
 * ---------------------------
 *
 * Color is generic enough to live in the core. Child plugins can still extend
 * or override the map via `taxa_facets_color_map`.
 */

function taxa_facets_get_color_map() {
    static $map = null;

    if ( null === $map ) {
        // DO NOT REORDER. Only append new colors at the end.
        $defaults = array(
            'red'         => 1 << 0,
            'orange'      => 1 << 1,
            'yellow'      => 1 << 2,
            'green'       => 1 << 3,
            'olive'       => 1 << 4,
            'blue'        => 1 << 5,
            'purple'      => 1 << 6,
            'white'       => 1 << 7,
            'tan'         => 1 << 8,
            'gray'        => 1 << 9,
            'pink'        => 1 << 10,
            'brown'       => 1 << 11,
            'black'       => 1 << 12,

            // Extended identification colors
            'teal'        => 1 << 13,
            'cyan'        => 1 << 14,
            'navy'        => 1 << 15,
            'gold'        => 1 << 16,
            'rust'        => 1 << 17,
            'iridescent'  => 1 << 18,
            'patterned'   => 1 << 19,
        );


        /**
         * Filter the color facet map.
         *
         * @param array $defaults slug => bit
         */
        $map = apply_filters( 'taxa_facets_color_map', $defaults );
    }

    return $map;
}

/**
 * Canonical list of allowed color slugs, used by GPT prompts.
 *
 * @return string[]
 */
function taxa_get_allowed_colors() {
    return array_keys( taxa_facets_get_color_map() );
}

/**
 * Normalize arbitrary color terms (from GPT, etc.) down to our canonical slugs.
 *
 * @param string[]|string $terms
 * @return string[]
 */
function taxa_normalize_color_terms( $terms ) {
    if ( ! is_array( $terms ) ) {
        $terms = array( $terms );
    }

    $allowed = taxa_get_allowed_colors();

    // Simple alias map for common variants (generic).
    $aliases = array(
        // Spelling / separators
        'grey'          => 'gray',
        'grayish'       => 'gray',
        'greyish'       => 'gray',
        'off-white'     => 'white',
        'off white'     => 'white',
        'ivory'         => 'white',
        'eggshell'      => 'white',
        'snow'          => 'white',
        'pearl'         => 'white',

        // Blue family
        'navy blue'     => 'navy',
        'dark blue'     => 'navy',
        'midnight blue' => 'navy',
        'deep blue'     => 'navy',
        'royal blue'    => 'blue',
        'sky blue'      => 'cyan',
        'light blue'    => 'cyan',
        'aqua'          => 'cyan',
        'aquamarine'    => 'cyan',
        'turquoise'     => 'teal',
        'teal-blue'     => 'teal',
        'teal blue'     => 'teal',
        'blue-green'    => 'teal',
        'blue green'    => 'teal',

        // Your existing "blue-grey" mappings were forcing gray-ish tones into blue.
        // Better to map them to gray now.
        'blue-grey'     => 'gray',
        'blue gray'     => 'gray',
        'bluish gray'   => 'gray',
        'grey-blue'     => 'gray',
        'grey blue'     => 'gray',

        // Purple / Violet
        'violet'        => 'purple',
        'lavender'      => 'purple',
        'mauve'         => 'purple',
        'magenta'       => 'pink',

        // Pink / Red
        'rose'          => 'pink',
        'salmon'        => 'pink',
        'coral'         => 'orange',
        'crimson'       => 'red',
        'scarlet'       => 'red',
        'maroon'        => 'red',

        // Orange / Yellow / Gold
        'amber'         => 'gold',
        'golden'        => 'gold',
        'golden yellow' => 'gold',
        'mustard'       => 'gold',
        'ochre'         => 'gold',
        'yellowish'     => 'yellow',

        // Browns / Tans / Rust
        'buff'          => 'tan',
        'cream'         => 'tan',
        'beige'         => 'tan',
        'sand'          => 'tan',
        'sandy'         => 'tan',
        'khaki'         => 'tan',
        'fawn'          => 'tan',
        'taupe'         => 'tan',
        'light brown'   => 'tan',
        'dark brown'    => 'brown',
        'chestnut'      => 'brown',
        'chocolate'     => 'brown',
        'cinnamon'      => 'rust',
        'copper'        => 'rust',
        'rusty'         => 'rust',
        'reddish brown' => 'rust',
        'rufous'        => 'rust', // better match than 'brown' now that you have rust

        // Green / Olive
        'yellow-green'  => 'olive',
        'yellow green'  => 'olive',
        'olive green'   => 'olive',
        'chartreuse'    => 'olive',
        'lime'          => 'green',
        'emerald'       => 'green',

        // Patterned / Iridescent (semantic, not literal)
        'iridescence'   => 'iridescent',
        'metallic'      => 'iridescent',
        'shimmering'    => 'iridescent',
        'shimmer'       => 'iridescent',
        'opalescent'    => 'iridescent',

        'pattern'       => 'patterned',
        'patterned'     => 'patterned',
        'mottled'       => 'patterned',
        'speckled'      => 'patterned',
        'spotted'       => 'patterned',
        'striped'       => 'patterned',
        'banded'        => 'patterned',
        'marbled'       => 'patterned',
    );


    /**
     * Allow child plugins to add/override color aliases.
     *
     * @param array $aliases
     */
    $aliases = apply_filters( 'taxa_facets_color_aliases', $aliases );

    $out = array();

    foreach ( $terms as $raw ) {
        if ( ! is_string( $raw ) ) {
            continue;
        }

        $t = strtolower( trim( $raw ) );

        // Explode multi-color strings like "blue, gray" or "blue / gray"
        $parts = preg_split( '/[\/,]+/', $t );
        foreach ( $parts as $part ) {
            $part = trim( $part );
            if ( '' === $part ) {
                continue;
            }

            if ( isset( $aliases[ $part ] ) ) {
                $part = $aliases[ $part ];
            }

            if ( in_array( $part, $allowed, true ) ) {
                $out[] = $part;
            }
        }
    }

    return array_values( array_unique( $out ) );
}

/**
 * Build color bitmask from canonical color slugs.
 *
 * @param string[] $color_slugs
 * @return int
 */
function taxa_build_color_mask( $color_slugs ) {
    $map  = taxa_facets_get_color_map();
    $mask = 0;

    foreach ( (array) $color_slugs as $slug ) {
        $slug = strtolower( trim( (string) $slug ) );
        if ( isset( $map[ $slug ] ) ) {
            $mask |= $map[ $slug ];
        }
    }

    return $mask;
}

/**
 * ---------------------------
 * GENERIC BITMASK FACETS
 * ---------------------------
 */

function taxa_facets_get_call_type_map() {
    static $map = null;

    if ( null === $map ) {
        $defaults = array(); // Core is intentionally empty / generic.
        $map      = apply_filters( 'taxa_facets_call_type_map', $defaults );
    }

    return $map;
}

function taxa_build_call_type_mask( $types ) {
    $map  = taxa_facets_get_call_type_map();
    $mask = 0;

    foreach ( (array) $types as $slug ) {
        $slug = strtolower( trim( (string) $slug ) );
        if ( isset( $map[ $slug ] ) ) {
            $mask |= $map[ $slug ];
        }
    }

    return $mask;
}

function taxa_facets_get_behavior_map() {
    static $map = null;

    if ( null === $map ) {
        $defaults = array(); // Core is intentionally empty / generic.
        $map      = apply_filters( 'taxa_facets_behavior_map', $defaults );
    }

    return $map;
}

function taxa_build_behavior_mask( $behaviors ) {
    $map  = taxa_facets_get_behavior_map();
    $mask = 0;

    foreach ( (array) $behaviors as $slug ) {
        $slug = strtolower( trim( (string) $slug ) );
        if ( isset( $map[ $slug ] ) ) {
            $mask |= $map[ $slug ];
        }
    }

    return $mask;
}

function taxa_facets_get_habitat_map() {
    static $map = null;

    if ( null === $map ) {
        $defaults = array(); // Core is intentionally empty / generic.
        $map      = apply_filters( 'taxa_facets_habitat_map', $defaults );
    }

    return $map;
}

function taxa_build_habitat_mask( $habitats ) {
    $map  = taxa_facets_get_habitat_map();
    $mask = 0;

    foreach ( (array) $habitats as $slug ) {
        $slug = strtolower( trim( (string) $slug ) );
        if ( isset( $map[ $slug ] ) ) {
            $mask |= $map[ $slug ];
        }
    }

    return $mask;
}

/**
 * ---------------------------
 * SINGLE-VALUE ENUM FACETS
 * ---------------------------
 */

function taxa_facets_get_size_enum_map() {
    static $map = null;

    if ( null === $map ) {
        $defaults = array();
        $map      = apply_filters( 'taxa_facets_size_enum_map', $defaults );
    }

    return $map;
}

function taxa_map_size_params( $tokens ) {
    $map = taxa_facets_get_size_enum_map();
    $out = array();

    foreach ( (array) $tokens as $t ) {
        $t = strtolower( trim( (string) $t ) );
        if ( isset( $map[ $t ] ) ) {
            $out[] = $map[ $t ];
        }
    }

    return array_values( array_unique( $out ) );
}

function taxa_facets_get_shape_primary_enum_map() {
    static $map = null;

    if ( null === $map ) {
        $defaults = array();
        $map      = apply_filters( 'taxa_facets_shape_primary_enum_map', $defaults );
    }

    return $map;
}

function taxa_map_shape_primary_params( $tokens ) {
    $map = taxa_facets_get_shape_primary_enum_map();
    $out = array();

    foreach ( (array) $tokens as $t ) {
        $t = strtolower( trim( (string) $t ) );
        if ( isset( $map[ $t ] ) ) {
            $out[] = $map[ $t ];
        }
    }

    return array_values( array_unique( $out ) );
}

function taxa_facets_get_shape_secondary_enum_map() {
    static $map = null;

    if ( null === $map ) {
        $defaults = array();
        $map      = apply_filters( 'taxa_facets_shape_secondary_enum_map', $defaults );
    }

    return $map;
}

function taxa_map_shape_secondary_params( $tokens ) {
    $map = taxa_facets_get_shape_secondary_enum_map();
    $out = array();

    foreach ( (array) $tokens as $t ) {
        $t = strtolower( trim( (string) $t ) );

        if ( 'wedge-shaped' === $t ) {
            $t = 'wedge';
        }

        if ( isset( $map[ $t ] ) ) {
            $out[] = $map[ $t ];
        }
    }

    return array_values( array_unique( $out ) );
}

function taxa_facets_get_pattern_enum_map() {
    static $map = null;

    if ( null === $map ) {
        $defaults = array();
        $map      = apply_filters( 'taxa_facets_pattern_enum_map', $defaults );
    }

    return $map;
}

function taxa_map_pattern_params( $tokens ) {
    $map = taxa_facets_get_pattern_enum_map();
    $out = array();

    foreach ( (array) $tokens as $t ) {
        $t = strtolower( trim( (string) $t ) );
        if ( isset( $map[ $t ] ) ) {
            $out[] = $map[ $t ];
        }
    }

    return array_values( array_unique( $out ) );
}

function taxa_facets_get_trait_primary_enum_map() {
    static $map = null;

    if ( null === $map ) {
        $defaults = array();
        $map      = apply_filters( 'taxa_facets_trait_primary_enum_map', $defaults );
    }

    return $map;
}

function taxa_map_trait_primary_params( $tokens ) {
    $map = taxa_facets_get_trait_primary_enum_map();
    $out = array();

    foreach ( (array) $tokens as $t ) {
        $t = strtolower( trim( (string) $t ) );
        if ( isset( $map[ $t ] ) ) {
            $out[] = $map[ $t ];
        }
    }

    return array_values( array_unique( $out ) );
}

function taxa_facets_get_trait_secondary_enum_map() {
    static $map = null;

    if ( null === $map ) {
        $defaults = array();
        $map      = apply_filters( 'taxa_facets_trait_secondary_enum_map', $defaults );
    }

    return $map;
}

function taxa_map_trait_secondary_params( $tokens ) {
    $map = taxa_facets_get_trait_secondary_enum_map();
    $out = array();

    foreach ( (array) $tokens as $t ) {
        $t = strtolower( trim( (string) $t ) );
        if ( isset( $map[ $t ] ) ) {
            $out[] = $map[ $t ];
        }
    }

    return array_values( array_unique( $out ) );
}

function taxa_facets_get_diet_enum_map() {
    static $map = null;

    if ( null === $map ) {
        $defaults = array();
        $map      = apply_filters( 'taxa_facets_diet_enum_map', $defaults );
    }

    return $map;
}

function taxa_map_diet_params( $tokens ) {
    $map = taxa_facets_get_diet_enum_map();
    $out = array();

    foreach ( (array) $tokens as $t ) {
        $t = strtolower( trim( (string) $t ) );
        if ( isset( $map[ $t ] ) ) {
            $out[] = $map[ $t ];
        }
    }

    return array_values( array_unique( $out ) );
}

/**
 * Placeholder enum map for family.
 */
function taxa_facets_get_family_enum_map() {
    return array();
}

/**
 * Placeholder enum map for region.
 */
function taxa_facets_get_region_enum_map() {
    return array();
}

/**
 * Ensure facets row has taxa_rank/extinct populated.
 *
 * Rules:
 * - Always ensure a row exists for this post_id.
 * - If taxa_rank is empty, populate it from post meta 'rank' (preferred).
 * - If still missing, allow child plugin to fetch from iNat via filter.
 * - If extinct is null/missing, populate from post meta 'extinct', else filter fallback.
 *
 * @param int $post_id
 * @return void
 */
function taxa_facets_ensure_rank_extinct( $post_id ) {

    error_log( '[FACETS][RANK] entered ensure_rank_extinct post_id=' . (int) $post_id );

    global $wpdb;

    $post_id = (int) $post_id;
    if ( $post_id <= 0 ) {
        return;
    }

    $table = $wpdb->prefix . 'taxa_facets';

    $row = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT post_id, taxa_rank, extinct
             FROM {$table}
             WHERE post_id = %d",
            $post_id
        ),
        ARRAY_A
    );

    // Ensure row exists.
    if ( empty( $row ) ) {
        $wpdb->query(
            $wpdb->prepare(
                "INSERT IGNORE INTO {$table} (post_id) VALUES (%d)",
                $post_id
            )
        );
        $row = array(
            'taxa_rank' => null,
            'extinct'   => null,
        );
    }

    $needs_rank    = ! isset( $row['taxa_rank'] ) || '' === trim( (string) $row['taxa_rank'] );
    $needs_extinct = ! array_key_exists( 'extinct', $row ) || null === $row['extinct'];

    if ( ! $needs_rank && ! $needs_extinct ) {
        return;
    }

    $taxa_rank = null;
    $extinct   = null;

    // 1) Prefer existing post meta.
    if ( $needs_rank ) {
        $meta_rank = get_post_meta( $post_id, 'rank', true );
        $taxa_rank = trim( (string) $meta_rank );
    }

    if ( $needs_extinct ) {
        $meta_extinct = get_post_meta( $post_id, 'extinct', true );
        if ( '' !== (string) $meta_extinct ) {
            $extinct = filter_var( $meta_extinct, FILTER_VALIDATE_BOOLEAN ) ? 1 : 0;
        }
    }

    // 2) If still missing rank/extinct, fetch from iNat using the 'taxa' meta (taxon ID).
    if (
        ( $needs_rank && '' === (string) $taxa_rank ) ||
        ( $needs_extinct && null === $extinct )
    ) {
        $taxa_id = get_post_meta( $post_id, 'taxa', true );
        $taxa_id = trim( (string) $taxa_id );

        if ( '' !== $taxa_id ) {
            $importer = null;

            // Use your singleton if available, else instantiate.
            if ( function_exists( 'taxa_api_get_importer' ) ) {
                $importer = taxa_api_get_importer();
            } elseif ( class_exists( 'Taxonomy_Importer' ) ) {
                $importer = new Taxonomy_Importer();
            }

            if ( $importer && method_exists( $importer, 'fetch_inat_core_fields_by_taxa_id' ) ) {
                $inat = $importer->fetch_inat_core_fields_by_taxa_id( $taxa_id );

                if ( is_array( $inat ) ) {
                    if ( $needs_rank && '' === (string) $taxa_rank && isset( $inat['taxa_rank'] ) ) {
                        $taxa_rank = trim( (string) $inat['taxa_rank'] );
                    }
                    if ( $needs_extinct && null === $extinct && array_key_exists( 'extinct', $inat ) ) {
                        $extinct = ( null === $inat['extinct'] ) ? null : (int) $inat['extinct'];
                    }
                }
            } else {
                error_log( '[FACETS][RANK] Importer missing or method missing; cannot fetch iNat core fields.' );
            }
        } else {
            error_log( '[FACETS][RANK] Missing post meta "taxa" for post_id=' . $post_id . ' (cannot iNat fetch)' );
        }
    }

    // Build update payload.
    $data   = array();
    $format = array();

    if ( $needs_rank && '' !== (string) $taxa_rank ) {
        $data['taxa_rank'] = $taxa_rank;
        $format[]          = '%s';
    }

    if ( $needs_extinct && null !== $extinct ) {
        $data['extinct'] = (int) $extinct;
        $format[]        = '%d';
    }

    if ( empty( $data ) ) {
        // Helpful debug: shows why nothing updated.
        error_log( '[FACETS][RANK] No update for post_id=' . $post_id . ' needs_rank=' . (int) $needs_rank . ' needs_extinct=' . (int) $needs_extinct . ' meta_rank=' . print_r( get_post_meta( $post_id, 'rank', true ), true ) . ' taxa_id=' . print_r( get_post_meta( $post_id, 'taxa', true ), true ) );
        return;
    }

    $wpdb->update(
        $table,
        $data,
        array( 'post_id' => $post_id ),
        $format,
        array( '%d' )
    );
}

/**
 * Upsert (insert or replace) a compact facet row for a post.
 *
 * Now also supports:
 *  - taxa_rank (string)
 *  - extinct (0/1)
 *
 * @param int   $post_id
 * @param array $facets
 */
function taxa_facets_update_row( $post_id, array $facets ) {
    global $wpdb;

    $table = $wpdb->prefix . 'taxa_facets';
    $post_id = (int) $post_id;

    if ( $post_id <= 0 ) {
        return;
    }

    /**
     * Ensure taxa_rank/extinct are populated on the row if missing.
     * IMPORTANT: This should NOT rely on a child plugin; your ensure function
     * should fetch iNat using the Taxonomy_Importer + meta key 'taxa' when needed.
     */
    if ( function_exists( 'taxa_facets_ensure_rank_extinct' ) ) {
        taxa_facets_ensure_rank_extinct( $post_id );
    }

    // Read current row so we don't stomp non-empty fields with blanks.
    $existing = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT taxa_rank, extinct, family_id, region_id
             FROM {$table}
             WHERE post_id = %d
             LIMIT 1",
            $post_id
        ),
        ARRAY_A
    );

    // Normalize and cast values.
    $size            = isset( $facets['size'] ) ? (int) $facets['size'] : null;
    $shape_primary   = isset( $facets['shape_primary'] ) ? (int) $facets['shape_primary'] : null;
    $shape_secondary = isset( $facets['shape_secondary'] ) ? (int) $facets['shape_secondary'] : null;
    $pattern         = isset( $facets['pattern'] ) ? (int) $facets['pattern'] : null;
    $trait_primary   = isset( $facets['trait_primary'] ) ? (int) $facets['trait_primary'] : null;
    $trait_secondary = isset( $facets['trait_secondary'] ) ? (int) $facets['trait_secondary'] : null;
    $diet            = isset( $facets['diet'] ) ? (int) $facets['diet'] : null;

    $color_mask      = isset( $facets['color_mask'] ) ? (int) $facets['color_mask'] : 0;
    $call_type_mask  = isset( $facets['call_type_mask'] ) ? (int) $facets['call_type_mask'] : 0;
    $behavior_mask   = isset( $facets['behavior_mask'] ) ? (int) $facets['behavior_mask'] : 0;
    $habitat_mask    = isset( $facets['habitat_mask'] ) ? (int) $facets['habitat_mask'] : 0;

    // Keep family/region if incoming value is empty/null.
    $family_id_in = isset( $facets['family_id'] ) ? $facets['family_id'] : null;
    $region_id_in = isset( $facets['region_id'] ) ? $facets['region_id'] : null;

    $family_id = is_numeric( $family_id_in ) && (int) $family_id_in > 0
        ? (int) $family_id_in
        : ( isset( $existing['family_id'] ) && is_numeric( $existing['family_id'] ) ? (int) $existing['family_id'] : null );

    $region_id = is_numeric( $region_id_in ) && (int) $region_id_in > 0
        ? (int) $region_id_in
        : ( isset( $existing['region_id'] ) && is_numeric( $existing['region_id'] ) ? (int) $existing['region_id'] : null );

    // taxa_rank/extinct: do NOT overwrite with empty; prefer existing.
    $taxa_rank_in = isset( $facets['taxa_rank'] ) ? trim( (string) $facets['taxa_rank'] ) : '';
    $taxa_rank_existing = isset( $existing['taxa_rank'] ) ? trim( (string) $existing['taxa_rank'] ) : '';

    $taxa_rank = ( $taxa_rank_in !== '' )
        ? $taxa_rank_in
        : ( $taxa_rank_existing !== '' ? $taxa_rank_existing : null );

    // Extinct: if facets provide it, use it; otherwise keep existing; else default 0.
    if ( array_key_exists( 'extinct', $facets ) ) {
        $extinct = (int) ( ! empty( $facets['extinct'] ) ? 1 : 0 );
    } elseif ( isset( $existing['extinct'] ) && $existing['extinct'] !== null ) {
        $extinct = (int) $existing['extinct'];
    } else {
        $extinct = 0;
    }

    $data = array(
        'post_id'         => $post_id,
        'size'            => $size,
        'shape_primary'   => $shape_primary,
        'shape_secondary' => $shape_secondary,
        'pattern'         => $pattern,
        'trait_primary'   => $trait_primary,
        'trait_secondary' => $trait_secondary,
        'diet'            => $diet,
        'color_mask'      => $color_mask,
        'call_type_mask'  => $call_type_mask,
        'behavior_mask'   => $behavior_mask,
        'habitat_mask'    => $habitat_mask,
        'family_id'       => $family_id,
        'region_id'       => $region_id,

        'taxa_rank'       => $taxa_rank,
        'extinct'         => $extinct,
    );

    $format = array(
        '%d',  // post_id
        '%d',  // size
        '%d',  // shape_primary
        '%d',  // shape_secondary
        '%d',  // pattern
        '%d',  // trait_primary
        '%d',  // trait_secondary
        '%d',  // diet
        '%d',  // color_mask
        '%d',  // call_type_mask
        '%d',  // behavior_mask
        '%d',  // habitat_mask
        '%d',  // family_id
        '%d',  // region_id
        '%s',  // taxa_rank
        '%d',  // extinct
    );

    // DEBUG (optional)
    error_log( '[FACETS][DEBUG] Update row facets: ' . print_r( $facets, true ) );
    error_log( '[FACETS][DEBUG] Existing row (partial): ' . print_r( $existing, true ) );
    error_log( '[FACETS][DEBUG] DB data payload (final): ' . print_r( $data, true ) );

    $wpdb->replace( $table, $data, $format );
}

/**
 * Generic helper: decode a bitmask back to the slugs that are set.
 */
function taxa_facets_decode_mask_to_slugs( $mask, $map ) {
    $mask = (int) $mask;
    $out  = array();

    foreach ( $map as $slug => $bit ) {
        if ( $mask & $bit ) {
            $out[] = $slug;
        }
    }

    return $out;
}

function taxa_decode_color_mask_to_slugs( $mask ) {
    $map = taxa_facets_get_color_map();
    return taxa_facets_decode_mask_to_slugs( $mask, $map );
}

function taxa_decode_behavior_mask_to_slugs( $mask ) {
    $map = taxa_facets_get_behavior_map();
    return taxa_facets_decode_mask_to_slugs( $mask, $map );
}

function taxa_decode_habitat_mask_to_slugs( $mask ) {
    $map = taxa_facets_get_habitat_map();
    return taxa_facets_decode_mask_to_slugs( $mask, $map );
}

function taxa_decode_call_type_mask_to_slugs( $mask ) {
    $map = taxa_facets_get_call_type_map();
    return taxa_facets_decode_mask_to_slugs( $mask, $map );
}

/**
 * SIZE ENUM → slug
 */
function taxa_decode_size_enum_to_slug( $enum ) {
    $map = taxa_facets_get_size_enum_map(); // slug => int
    foreach ( $map as $slug => $id ) {
        if ( (int) $enum === (int) $id ) {
            return $slug;
        }
    }
    return '';
}

/**
 * SHAPE PRIMARY ENUM → slug
 */
function taxa_decode_shape_primary_enum_to_slug( $enum ) {
    $map = taxa_facets_get_shape_primary_enum_map();
    foreach ( $map as $slug => $id ) {
        if ( (int) $enum === (int) $id ) {
            return $slug;
        }
    }
    return '';
}

/**
 * SHAPE SECONDARY ENUM → slug
 */
function taxa_decode_shape_secondary_enum_to_slug( $enum ) {
    $map = taxa_facets_get_shape_secondary_enum_map();
    foreach ( $map as $slug => $id ) {
        if ( (int) $enum === (int) $id ) {
            return $slug;
        }
    }
    return '';
}

/**
 * PATTERN ENUM → slug
 */
function taxa_decode_pattern_enum_to_slug( $enum ) {
    $map = taxa_facets_get_pattern_enum_map();
    foreach ( $map as $slug => $id ) {
        if ( (int) $enum === (int) $id ) {
            return $slug;
        }
    }
    return '';
}

/**
 * TRAIT PRIMARY ENUM → slug
 */
function taxa_decode_trait_primary_enum_to_slug( $enum ) {
    $map = taxa_facets_get_trait_primary_enum_map();
    foreach ( $map as $slug => $id ) {
        if ( (int) $enum === (int) $id ) {
            return $slug;
        }
    }
    return '';
}

/**
 * TRAIT SECONDARY ENUM → slug
 */
function taxa_decode_trait_secondary_enum_to_slug( $enum ) {
    $map = taxa_facets_get_trait_secondary_enum_map();
    foreach ( $map as $slug => $id ) {
        if ( (int) $enum === (int) $id ) {
            return $slug;
        }
    }
    return '';
}

/**
 * DIET ENUM → slug
 */
function taxa_decode_diet_enum_to_slug( $enum ) {
    $map = taxa_facets_get_diet_enum_map();
    foreach ( $map as $slug => $id ) {
        if ( (int) $enum === (int) $id ) {
            return $slug;
        }
    }
    return '';
}

/**
 * Track post views into:
 *  - {prefix}taxa_facets.popularity (lifetime)
 *  - {prefix}taxa_facets.last_viewed
 *  - {prefix}taxa_facets_views_daily.views (daily bucket)
 *
 * IMPORTANT:
 *  - Daily table MUST have UNIQUE(post_id, ymd) (or PRIMARY KEY on both)
 *    or ON DUPLICATE KEY UPDATE will never run.
 */

/**
 * Create/upgrade the daily views table.
 * Safe to call multiple times.
 */
function taxa_facets_create_daily_views_table() {
    global $wpdb;

    $table           = $wpdb->prefix . 'taxa_facets_views_daily';
    $charset_collate = $wpdb->get_charset_collate();

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    // Create table with the CORRECT unique key so "ON DUPLICATE KEY" works.
    $sql = "CREATE TABLE {$table} (
        post_id bigint(20) unsigned NOT NULL,
        ymd date NOT NULL,
        views int unsigned NOT NULL DEFAULT 0,
        PRIMARY KEY (post_id, ymd),
        KEY ymd (ymd),
        KEY post_id (post_id)
    ) {$charset_collate};";

    dbDelta( $sql );

    // Hard-ensure unique key exists even if dbDelta missed it (rare, but happens).
    $has_unique = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(1)
             FROM information_schema.statistics
             WHERE table_schema = DATABASE()
               AND table_name = %s
               AND index_name IN ('PRIMARY', 'post_day')",
            $table
        )
    );

    // If somehow no key exists, add a unique index.
    // (If PRIMARY exists, this won't run; if neither exists, we add one.)
    if ( empty( $has_unique ) ) {
        // This can fail if duplicates already exist.
        // If it fails, you'll see it in error_log and we can merge duplicates.
        $wpdb->query( "ALTER TABLE {$table} ADD UNIQUE KEY post_day (post_id, ymd)" );
    }
}

/**
 * Ensure the facets row exists so updates don't fail.
 */
function taxa_facets_ensure_facets_row_exists( $post_id ) {
    global $wpdb;

    $facets = $wpdb->prefix . 'taxa_facets';

    // If your facets table has more NOT NULL columns beyond post_id,
    // db schema should provide defaults. This assumes it does.
    $wpdb->query(
        $wpdb->prepare(
            "INSERT IGNORE INTO {$facets} (post_id) VALUES (%d)",
            $post_id
        )
    );
}

/**
 * Main view tracker.
 */
add_action( 'template_redirect', 'taxa_facets_track_post_view' );
function taxa_facets_track_post_view() {
    // Don’t track admin/AJAX/REST.
    if ( is_admin() || wp_doing_ajax() || wp_is_json_request() ) {
        return;
    }

    // Your taxa posts are regular posts.
    if ( ! is_singular( 'post' ) ) {
        return;
    }

    $post_id = (int) get_queried_object_id();
    if ( ! $post_id ) {
        return;
    }

    /**
     * Cookie throttle (6 hours).
     * NOTE:
     * - If you want each device to count separately, cookie-based throttle is fine.
     * - If you want *every* page view to count, remove this block.
     */
    $cookie = 'taxa_viewed_' . $post_id;

    if ( isset( $_COOKIE[ $cookie ] ) ) {
        return;
    }

    $expire = time() + 6 * HOUR_IN_SECONDS;

    // More reliable cookie params across WP setups.
    $path   = COOKIEPATH ? COOKIEPATH : '/';
    $domain = COOKIE_DOMAIN ? COOKIE_DOMAIN : '';
    $secure = is_ssl();
    $httponly = true;

    setcookie( $cookie, '1', $expire, $path, $domain, $secure, $httponly );
    $_COOKIE[ $cookie ] = '1';

    global $wpdb;

    $facets = $wpdb->prefix . 'taxa_facets';
    $daily  = $wpdb->prefix . 'taxa_facets_views_daily';

    // Ensure daily table exists (and has the correct key).
    taxa_facets_create_daily_views_table();

    // Ensure facets row exists.
    taxa_facets_ensure_facets_row_exists( $post_id );

    // Use WP timezone date for daily rollups (prevents DB timezone mismatch).
    $ymd = wp_date( 'Y-m-d' );

    // Lifetime popularity + last_viewed (atomic; popularity safe if NULL).
    $updated = $wpdb->query(
        $wpdb->prepare(
            "UPDATE {$facets}
             SET popularity  = COALESCE(popularity, 0) + 1,
                 last_viewed = NOW()
             WHERE post_id = %d",
            $post_id
        )
    );

    // Daily bucket: increments when (post_id, ymd) already exists.
    $daily_upsert = $wpdb->query(
        $wpdb->prepare(
            "INSERT INTO {$daily} (post_id, ymd, views)
             VALUES (%d, %s, 1)
             ON DUPLICATE KEY UPDATE views = views + 1",
            $post_id,
            $ymd
        )
    );

    // Ensure taxa_rank/extinct are filled on view as a safety net.
    if ( function_exists( 'taxa_facets_ensure_rank_extinct' ) ) {
        error_log( '[FACETS][RANK] ensure_rank_extinct called from views for post_id=' . $post_id );
        taxa_facets_ensure_rank_extinct( $post_id );
    } else {
        error_log( '[FACETS][RANK] ensure_rank_extinct missing for post_id=' . $post_id );
    }

    // Optional debug logging (leave on while testing).
    error_log( sprintf(
        '[FACETS][VIEWS] post_id=%d ymd=%s facets_update=%s daily_upsert=%s last_error=%s',
        $post_id,
        $ymd,
        var_export( $updated, true ),
        var_export( $daily_upsert, true ),
        $wpdb->last_error ? $wpdb->last_error : '(none)'
    ) );
}

/**
 * Schedule the daily rollup task (if you’re using it).
 */
add_action( 'init', function () {
    if ( ! wp_next_scheduled( 'taxa_facets_rollup_popularity_30d' ) ) {
        wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'taxa_facets_rollup_popularity_30d' );
    }
} );
