<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Make sure facet helpers are loaded (enums, masks, decode helpers, etc).
require_once __DIR__ . '/facets.php';

/**
 * Build the GPT prompt for facet generation based on the new facet structure.
 *
 * @param string $title Species/post title.
 * @param array  $lists Assoc array of allowed token lists.
 *
 * @return string
 */
function taxa_facets_build_gpt_prompt( $title, array $lists ) {
    // Admin-configurable extra instructions.
    $default_instructions = <<<TXT
You are helping categorize species for an interactive field guide.
Given ONLY the species name, choose the most typical values for each facet.
If you are unsure, you may leave a facet empty (null or []).
TXT;

    $extra_instructions = get_option( 'taxa_facets_prompt_instructions', '' );
    $extra_instructions = trim( $extra_instructions ) !== '' ? $extra_instructions : $default_instructions;

    $colors           = $lists['colors'] ?? '';
    $sizes            = $lists['sizes'] ?? '';
    $shape_primary    = $lists['shape_primary'] ?? '';
    $shape_secondary  = $lists['shape_secondary'] ?? '';
    $patterns         = $lists['patterns'] ?? '';
    $traits_primary   = $lists['traits_primary'] ?? '';
    $traits_secondary = $lists['traits_secondary'] ?? '';
    $diets            = $lists['diets'] ?? '';
    $calltypes        = $lists['calltypes'] ?? '';
    $behaviors        = $lists['behaviors'] ?? '';
    $habitats         = $lists['habitats'] ?? '';
    $families         = $lists['families'] ?? '';
    $regions          = $lists['regions'] ?? '';


    $prompt = <<<EOP
{$extra_instructions}

All facet tokens MUST be lower case and chosen ONLY from the allowed lists.

Allowed tokens:

- colors (multi): {$colors}
- size (single): {$sizes}
- shape_primary (single): {$shape_primary}
- shape_secondary (single): {$shape_secondary}
- pattern (single): {$patterns}
- trait_primary (single): {$traits_primary}
- trait_secondary (single): {$traits_secondary}
- diet (single): {$diets}
- call_type (multi): {$calltypes}
- behavior (multi): {$behaviors}
- habitat (multi): {$habitats}
- family (single): {$families}
- region (single): {$regions}

Respond in EXACTLY this JSON format and nothing else:

{
  "size": "size_token or null",
  "shape_primary": "shape_primary_token or null",
  "shape_secondary": "shape_secondary_token or null",
  "pattern": "pattern_token or null",
  "trait_primary": "trait_primary_token or null",
  "trait_secondary": "trait_secondary_token or null",
  "diet": "diet_token or null",
  "colors": ["color_token1", "color_token2"],
  "call_type": ["call_type_token1"],
  "behavior": ["behavior_token1", "behavior_token2"],
  "habitat": ["habitat_token1", "habitat_token2"],
  "family": "family_token or null",
  "region": "region_token or null"
}

Here is the taxon name:

{$title}
EOP;

    /**
     * Allow child plugins to override the full prompt if needed.
     */
    $prompt = apply_filters( 'taxa_facets_gpt_prompt', $prompt, $title, $lists );

    return $prompt;
}


/**
 * Use GPT to infer facet meta (colors, size, behavior, habitat, etc.)
 * for a taxa post, using ONLY the post name to keep token usage small.
 *
 * Expects JSON like:
 * {
 *   "size": "small",
 *   "shape_primary": "broad_rounded",
 *   "shape_secondary": "forked",
 *   "pattern": "striped",
 *   "trait_primary": "something",
 *   "trait_secondary": "something_else",
 *   "diet": "insectivorous",
 *   "colors": ["blue", "white"],
 *   "call_type": ["song", "whistle"],
 *   "behavior": ["flocking"],
 *   "habitat": ["forest_edge"],
 *   "family": "parulidae",
 *   "region": "north_america"
 * }
 *
 * @param int $post_id
 */
function taxa_generate_facets_from_gpt( $post_id ) {
    $gpt_enabled = get_option( 'gpt_enabled', '0' );
    if ( ! in_array( $gpt_enabled, array( 'true', true, '1', 1 ), true ) ) {
        return;
    }

    $post = get_post( $post_id );
    if ( ! $post instanceof WP_Post ) {
        return;
    }

    $taxa_id = get_post_meta( $post_id, 'taxa', true );
    if ( ! $taxa_id ) {
        return;
    }

    $title = get_the_title( $post_id );

    // Build allowed lists from your enum maps.
    $colors          = implode( ', ', taxa_get_allowed_colors() );
    $sizes           = implode( ', ', array_keys( taxa_facets_get_size_enum_map() ) );
    $shape_primary   = implode( ', ', array_keys( taxa_facets_get_shape_primary_enum_map() ) );
    $shape_secondary = implode( ', ', array_keys( taxa_facets_get_shape_secondary_enum_map() ) );
    $patterns        = implode( ', ', array_keys( taxa_facets_get_pattern_enum_map() ) );
    $traits_primary  = implode( ', ', array_keys( taxa_facets_get_trait_primary_enum_map() ) );
    $traits_secondary= implode( ', ', array_keys( taxa_facets_get_trait_secondary_enum_map() ) );
    $diets           = implode( ', ', array_keys( taxa_facets_get_diet_enum_map() ) );
    $calltypes       = implode( ', ', array_keys( taxa_facets_get_call_type_map() ) );
    $behaviors       = implode( ', ', array_keys( taxa_facets_get_behavior_map() ) );
    $habitats        = implode( ', ', array_keys( taxa_facets_get_habitat_map() ) );
    $families        = implode( ', ', array_keys( taxa_facets_get_family_enum_map() ) );
    $regions         = implode( ', ', array_keys( taxa_facets_get_region_enum_map() ) );

    $lists = array(
        'colors'          => $colors,
        'sizes'           => $sizes,
        'shape_primary'   => $shape_primary,
        'shape_secondary' => $shape_secondary,
        'patterns'        => $patterns,
        'traits_primary'  => $traits_primary,
        'traits_secondary'=> $traits_secondary,
        'diets'           => $diets,
        'calltypes'       => $calltypes,
        'behaviors'       => $behaviors,
        'habitats'        => $habitats,
        'families'        => $families,
        'regions'         => $regions,
    );

    $prompt   = taxa_facets_build_gpt_prompt( $title, $lists );
    $response = get_gpt_response( $prompt, 'gpt-4o-mini' );

    if ( ! $response ) {
        error_log( '[FACETS][GPT] Empty response for post ' . $post_id );
        return;
    }

    // Try to decode JSON directly first.
    $raw = trim( (string) $response );

    // Strip code fences if the helper returned ```json ... ```
    if ( strpos( $raw, '```' ) === 0 ) {
        $raw = preg_replace( '#^```(?:json)?#i', '', $raw );
        $raw = preg_replace( '#```$#', '', $raw );
        $raw = trim( $raw );
    }

    $data = json_decode( $raw, true );

    if ( ! is_array( $data ) ) {
        error_log( '[FACETS][GPT] Failed to decode JSON for post ' . $post_id . ': ' . substr( $raw, 0, 500 ) );
        return;
    }

    // Normalizers
    $norm_scalar = function( $value ) {
        $value = strtolower( trim( (string) $value ) );
        return $value === '' ? null : $value;
    };

    $norm_array = function( $value ) use ( $norm_scalar ) {
        if ( ! is_array( $value ) ) {
            return array();
        }
        $out = array();
        foreach ( $value as $v ) {
            $slug = $norm_scalar( $v );
            if ( $slug ) {
                $out[] = $slug;
            }
        }
        // Ensure unique values.
        return array_values( array_unique( $out ) );
    };

    // Scalars
    $size            = $norm_scalar( $data['size']            ?? null );
    $shape_primary   = $norm_scalar( $data['shape_primary']   ?? null );
    $shape_secondary = $norm_scalar( $data['shape_secondary'] ?? null );
    $pattern         = $norm_scalar( $data['pattern']         ?? null );
    $trait_primary   = $norm_scalar( $data['trait_primary']   ?? null );
    $trait_secondary = $norm_scalar( $data['trait_secondary'] ?? null );
    $diet            = $norm_scalar( $data['diet']            ?? null );
    $family          = $norm_scalar( $data['family']          ?? null );
    $region          = $norm_scalar( $data['region']          ?? null );

    // Arrays
    $colors_arr   = $norm_array( $data['colors']    ?? array() );
    $call_types   = $norm_array( $data['call_type'] ?? array() );
    $behaviors    = $norm_array( $data['behavior']  ?? array() );
    $habitats     = $norm_array( $data['habitat']   ?? array() );

    // Write meta for the generic keys we now use.
    // Scalars: update to slug or delete if null.
    $update_or_delete = function( $post_id, $meta_key, $value ) {
        if ( $value === null ) {
            delete_post_meta( $post_id, $meta_key );
        } else {
            update_post_meta( $post_id, $meta_key, $value );
        }
    };

    $update_or_delete( $post_id, 'facet_size',            $size );
    $update_or_delete( $post_id, 'facet_shape_primary',   $shape_primary );
    $update_or_delete( $post_id, 'facet_shape_secondary', $shape_secondary );
    $update_or_delete( $post_id, 'facet_pattern',         $pattern );
    $update_or_delete( $post_id, 'facet_trait_primary',   $trait_primary );
    $update_or_delete( $post_id, 'facet_trait_secondary', $trait_secondary );
    $update_or_delete( $post_id, 'facet_diet',            $diet );
    $update_or_delete( $post_id, 'facet_family',          $family );
    $update_or_delete( $post_id, 'facet_region',          $region );

    // Arrays: update array or delete if empty.
    if ( ! empty( $colors_arr ) ) {
        update_post_meta( $post_id, 'facet_color', $colors_arr );
    } else {
        delete_post_meta( $post_id, 'facet_color' );
    }

    if ( ! empty( $call_types ) ) {
        update_post_meta( $post_id, 'facet_call_type', $call_types );
    } else {
        delete_post_meta( $post_id, 'facet_call_type' );
    }

    if ( ! empty( $behaviors ) ) {
        update_post_meta( $post_id, 'facet_behavior', $behaviors );
    } else {
        delete_post_meta( $post_id, 'facet_behavior' );
    }

    if ( ! empty( $habitats ) ) {
        update_post_meta( $post_id, 'facet_habitat', $habitats );
    } else {
        delete_post_meta( $post_id, 'facet_habitat' );
    }

    // Optional: log what we wrote, to debug.
    error_log( '[FACETS][GPT] Updated facet_* meta for post ' . $post_id . ': ' . print_r( array(
        'size'            => $size,
        'shape_primary'   => $shape_primary,
        'shape_secondary' => $shape_secondary,
        'pattern'         => $pattern,
        'trait_primary'   => $trait_primary,
        'trait_secondary' => $trait_secondary,
        'diet'            => $diet,
        'colors'          => $colors_arr,
        'call_type'       => $call_types,
        'behavior'        => $behaviors,
        'habitat'         => $habitats,
        'family'          => $family,
        'region'          => $region,
    ), true ) );
}



/**
 * Handle "Regenerate facets (GPT) & rebuild masks" from the Taxa Facets meta box.
 */
function taxa_handle_regen_facets() {
    if ( ! isset( $_GET['post_id'], $_GET['_wpnonce'] ) ) {
        wp_die( esc_html__( 'Missing parameters.', 'taxonomy-api' ) );
    }

    $post_id = absint( $_GET['post_id'] );

    if ( ! current_user_can( 'edit_post', $post_id ) ) {
        wp_die( esc_html__( 'You do not have permission to edit this post.', 'taxonomy-api' ) );
    }

    check_admin_referer( 'taxa_regen_facets_' . $post_id );

    // 1) Re-run GPT facet generation (writes facet_* meta).
    if ( function_exists( 'taxa_generate_facets_from_gpt' ) ) {
        taxa_generate_facets_from_gpt( $post_id );
    }

    // 2) Rebuild the compact facet row (enums + bitmasks) for this post.
    $importer = taxa_api_get_importer();
    if ( $importer && method_exists( $importer, 'build_facets_for_post' ) ) {
        $importer->build_facets_for_post( $post_id );
    }

    // Redirect back to the edit screen with a flag for admin notice.
    $args = array(
        'post'                 => $post_id,
        'action'               => 'edit',
        'taxa_facets_regen'    => 1,
    );

    wp_redirect( add_query_arg( $args, admin_url( 'post.php' ) ) );
    exit;
}
add_action( 'admin_post_taxa_regen_facets', 'taxa_handle_regen_facets' );


/**
 * Admin notice for facet regeneration.
 */
function taxa_facets_admin_notices() {
    if ( ! is_admin() ) {
        return;
    }
    if ( ! current_user_can( 'edit_posts' ) ) {
        return;
    }

    if ( isset( $_GET['taxa_facets_regen'] ) && intval( $_GET['taxa_facets_regen'] ) === 1 ) {
        echo '<div class="notice notice-success is-dismissible"><p>';
        echo esc_html__( 'Taxa facets regenerated and masks rebuilt for this post.', 'taxonomy-api' );
        echo '</p></div>';
    }
}
add_action( 'admin_notices', 'taxa_facets_admin_notices' );

/**
 * Render the "Taxa Facets" meta box on taxa posts.
 *
 * Shows current facet values and a button to re-run GPT facets
 * and rebuild the facet masks row for this post.
 *
 * @param WP_Post $post
 */
/**
 * Meta box: Taxa Facets (view + manual edits).
 *
 * Shows the current compact facet row for this post and allows
 * editors to tweak the facets manually via checkboxes / radios.
 */
function taxa_facets_meta_box_render( $post ) {
    wp_nonce_field( 'taxa_facets_save', 'taxa_facets_nonce' );

    global $wpdb;

    $table = $wpdb->prefix . 'taxa_facets';

    // Single compact row for this post.
    $row = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM {$table} WHERE post_id = %d",
            $post->ID
        ),
        ARRAY_A
    );

    // --- Decode current values into slugs/labels ---

    $current_colors    = $row ? taxa_decode_color_mask_to_slugs( (int) $row['color_mask'] ) : array();
    $current_behaviors = $row ? taxa_decode_behavior_mask_to_slugs( (int) $row['behavior_mask'] ) : array();
    $current_habitats  = $row ? taxa_decode_habitat_mask_to_slugs( (int) $row['habitat_mask'] ) : array();
    $current_calltypes = $row ? taxa_decode_call_type_mask_to_slugs( (int) $row['call_type_mask'] ) : array();

    $current_size            = $row ? taxa_decode_size_enum_to_slug( (int) $row['size'] ) : '';
    $current_shape_primary   = $row ? taxa_decode_shape_primary_enum_to_slug( (int) $row['shape_primary'] ) : '';
    $current_shape_secondary = $row ? taxa_decode_shape_secondary_enum_to_slug( (int) $row['shape_secondary'] ) : '';
    $current_pattern         = $row ? taxa_decode_pattern_enum_to_slug( (int) $row['pattern'] ) : '';
    $current_diet            = $row ? taxa_decode_diet_enum_to_slug( (int) $row['diet'] ) : '';


    ?>
    <div class="taxa-facets-meta-box">
        <p><strong><?php esc_html_e( 'Current Facets', 'taxonomy-api' ); ?></strong></p>

        <p><strong><?php esc_html_e( 'Colors:', 'taxonomy-api' ); ?></strong>
            <?php echo $current_colors ? esc_html( implode( ', ', $current_colors ) ) : '&mdash;'; ?>
        </p>
        <p><strong><?php esc_html_e( 'Size:', 'taxonomy-api' ); ?></strong>
            <?php echo $current_size ? esc_html( $current_size ) : '&mdash;'; ?>
        </p>
        <p><strong><?php esc_html_e( 'Shape Primary:', 'taxonomy-api' ); ?></strong>
            <?php echo $current_shape_primary ? esc_html( $current_shape_primary ) : '&mdash;'; ?>
        </p>
        <p><strong><?php esc_html_e( 'Shape Secondary:', 'taxonomy-api' ); ?></strong>
            <?php echo $current_shape_secondary ? esc_html( $current_shape_secondary ) : '&mdash;'; ?>
        </p>
        <p><strong><?php esc_html_e( 'Pattern:', 'taxonomy-api' ); ?></strong>
            <?php echo $current_pattern ? esc_html( $current_pattern ) : '&mdash;'; ?>
        </p>
        <p><strong><?php esc_html_e( 'Diet:', 'taxonomy-api' ); ?></strong>
            <?php echo $current_diet ? esc_html( $current_diet ) : '&mdash;'; ?>
        </p>
        <p><strong><?php esc_html_e( 'Call type:', 'taxonomy-api' ); ?></strong>
            <?php echo $current_calltypes ? esc_html( implode( ', ', $current_calltypes ) ) : '&mdash;'; ?>
        </p>
        <p><strong><?php esc_html_e( 'Behavior:', 'taxonomy-api' ); ?></strong>
            <?php echo $current_behaviors ? esc_html( implode( ', ', $current_behaviors ) ) : '&mdash;'; ?>
        </p>
        <p><strong><?php esc_html_e( 'Habitat:', 'taxonomy-api' ); ?></strong>
            <?php echo $current_habitats ? esc_html( implode( ', ', $current_habitats ) ) : '&mdash;'; ?>
        </p>

        <hr />

        <p><strong><?php esc_html_e( 'Manual adjustments', 'taxonomy-api' ); ?></strong>
            <br />
            <span class="description">
                <?php esc_html_e( 'Use these controls to refine GPT-based facet assignments (e.g., add blue if the bird clearly has blue plumage).', 'taxonomy-api' ); ?>
            </span>
        </p>

        <?php
        /**
         * COLORS (bitmask, multi-select)
         */
        $color_map = taxa_facets_get_color_map(); // slug => bit
        ?>
        <p><strong><?php esc_html_e( 'Colors', 'taxonomy-api' ); ?></strong><br/>
            <?php foreach ( $color_map as $slug => $_bit ) : ?>
                <label style="display:inline-block; margin-right:8px; margin-bottom:4px;">
                    <input type="checkbox"
                           name="taxa_facets_colors[]"
                           value="<?php echo esc_attr( $slug ); ?>"
                        <?php checked( in_array( $slug, $current_colors, true ) ); ?> />
                    <?php echo esc_html( ucfirst( $slug ) ); ?>
                </label>
            <?php endforeach; ?>
        </p>

        <?php
        /**
         * SIZE (single enum)
         */
        $size_map = taxa_facets_get_size_enum_map(); // slug => int
        ?>
        <p><strong><?php esc_html_e( 'Size', 'taxonomy-api' ); ?></strong><br/>
            <label>
                <input type="radio"
                       name="taxa_facets_size"
                       value=""
                    <?php checked( $current_size === '' ); ?> />
                <?php esc_html_e( 'None', 'taxonomy-api' ); ?>
            </label>
            <?php foreach ( $size_map as $slug => $_id ) : ?>
                <label style="display:inline-block; margin-left:10px;">
                    <input type="radio"
                           name="taxa_facets_size"
                           value="<?php echo esc_attr( $slug ); ?>"
                        <?php checked( $slug === $current_size ); ?> />
                    <?php echo esc_html( ucfirst( $slug ) ); ?>
                </label>
            <?php endforeach; ?>
        </p>

        <?php
        /**
        * PRIMARY SHAPE (single enum – former “wing shape”)
        */
        $shape_primary_map = taxa_facets_get_shape_primary_enum_map();
        ?>
        <p><strong><?php esc_html_e( 'Wing shape', 'taxonomy-api' ); ?></strong><br/>
            <label>
                <input type="radio"
                    name="taxa_facets_shape_primary"
                    value=""
                    <?php checked( $current_shape_primary === '' ); ?> />
                <?php esc_html_e( 'None', 'taxonomy-api' ); ?>
            </label>
            <?php foreach ( $shape_primary_map as $slug => $_id ) : ?>
                <label style="display:inline-block; margin-left:10px;">
                    <input type="radio"
                        name="taxa_facets_shape_primary"
                        value="<?php echo esc_attr( $slug ); ?>"
                        <?php checked( $slug === $current_shape_primary ); ?> />
                    <?php echo esc_html( ucwords( str_replace( '_', ' ', $slug ) ) ); ?>
                </label>
            <?php endforeach; ?>
        </p>

        <?php
        /**
        * SECONDARY SHAPE (single enum – former “tail shape”)
        */
        $shape_secondary_map = taxa_facets_get_shape_secondary_enum_map();
        ?>
        <p><strong><?php esc_html_e( 'Tail shape', 'taxonomy-api' ); ?></strong><br/>
            <label>
                <input type="radio"
                    name="taxa_facets_shape_secondary"
                    value=""
                    <?php checked( $current_shape_secondary === '' ); ?> />
                <?php esc_html_e( 'None', 'taxonomy-api' ); ?>
            </label>
            <?php foreach ( $shape_secondary_map as $slug => $_id ) : ?>
                <label style="display:inline-block; margin-left:10px;">
                    <input type="radio"
                        name="taxa_facets_shape_secondary"
                        value="<?php echo esc_attr( $slug ); ?>"
                        <?php checked( $slug === $current_shape_secondary ); ?> />
                    <?php echo esc_html( ucwords( str_replace( '_', ' ', $slug ) ) ); ?>
                </label>
            <?php endforeach; ?>
        </p>

        <?php
        /**
        * PATTERN (single enum – former “call pattern”)
        */
        $pattern_map = taxa_facets_get_pattern_enum_map();
        ?>
        <p><strong><?php esc_html_e( 'Call pattern', 'taxonomy-api' ); ?></strong><br/>
            <label>
                <input type="radio"
                    name="taxa_facets_pattern"
                    value=""
                    <?php checked( $current_pattern === '' ); ?> />
                <?php esc_html_e( 'None', 'taxonomy-api' ); ?>
            </label>
            <?php foreach ( $pattern_map as $slug => $_id ) : ?>
                <label style="display:inline-block; margin-left:10px;">
                    <input type="radio"
                        name="taxa_facets_pattern"
                        value="<?php echo esc_attr( $slug ); ?>"
                        <?php checked( $slug === $current_pattern ); ?> />
                    <?php echo esc_html( ucwords( str_replace( '_', ' ', $slug ) ) ); ?>
                </label>
            <?php endforeach; ?>
        </p>


        <?php
        /**
         * DIET (single enum)
         */
        $diet_map = taxa_facets_get_diet_enum_map();
        ?>
        <p><strong><?php esc_html_e( 'Diet', 'taxonomy-api' ); ?></strong><br/>
            <label>
                <input type="radio"
                       name="taxa_facets_diet"
                       value=""
                    <?php checked( $current_diet === '' ); ?> />
                <?php esc_html_e( 'None', 'taxonomy-api' ); ?>
            </label>
            <?php foreach ( $diet_map as $slug => $_id ) : ?>
                <label style="display:inline-block; margin-left:10px;">
                    <input type="radio"
                           name="taxa_facets_diet"
                           value="<?php echo esc_attr( $slug ); ?>"
                        <?php checked( $slug === $current_diet ); ?> />
                    <?php echo esc_html( ucwords( str_replace( '_', ' ', $slug ) ) ); ?>
                </label>
            <?php endforeach; ?>
        </p>

        <?php
        /**
         * CALL TYPE (bitmask, multi-select)
         */
        $call_type_map = taxa_facets_get_call_type_map();
        ?>
        <p><strong><?php esc_html_e( 'Call type', 'taxonomy-api' ); ?></strong><br/>
            <?php foreach ( $call_type_map as $slug => $_bit ) : ?>
                <label style="display:inline-block; margin-right:8px; margin-bottom:4px;">
                    <input type="checkbox"
                           name="taxa_facets_call_types[]"
                           value="<?php echo esc_attr( $slug ); ?>"
                        <?php checked( in_array( $slug, $current_calltypes, true ) ); ?> />
                    <?php echo esc_html( ucwords( str_replace( '_', ' ', $slug ) ) ); ?>
                </label>
            <?php endforeach; ?>
        </p>

        <?php
        /**
         * BEHAVIOR (bitmask, multi-select)
         */
        $behavior_map = taxa_facets_get_behavior_map();
        ?>
        <p><strong><?php esc_html_e( 'Behavior', 'taxonomy-api' ); ?></strong><br/>
            <?php foreach ( $behavior_map as $slug => $_bit ) : ?>
                <label style="display:inline-block; margin-right:8px; margin-bottom:4px;">
                    <input type="checkbox"
                           name="taxa_facets_behaviors[]"
                           value="<?php echo esc_attr( $slug ); ?>"
                        <?php checked( in_array( $slug, $current_behaviors, true ) ); ?> />
                    <?php echo esc_html( ucwords( str_replace( '_', ' ', $slug ) ) ); ?>
                </label>
            <?php endforeach; ?>
        </p>

        <?php
        /**
         * HABITAT (bitmask, multi-select)
         */
        $habitat_map = taxa_facets_get_habitat_map();
        ?>
        <p><strong><?php esc_html_e( 'Habitat', 'taxonomy-api' ); ?></strong><br/>
            <?php foreach ( $habitat_map as $slug => $_bit ) : ?>
                <label style="display:inline-block; margin-right:8px; margin-bottom:4px;">
                    <input type="checkbox"
                           name="taxa_facets_habitats[]"
                           value="<?php echo esc_attr( $slug ); ?>"
                        <?php checked( in_array( $slug, $current_habitats, true ) ); ?> />
                    <?php echo esc_html( ucwords( str_replace( '_', ' ', $slug ) ) ); ?>
                </label>
            <?php endforeach; ?>
        </p>

        <hr />

        <p>
            <a href="<?php echo esc_url(
                wp_nonce_url(
                    admin_url( 'admin-post.php?action=taxa_regen_facets&post_id=' . $post->ID ),
                    'taxa_regen_facets_' . $post->ID
                )
            ); ?>"
               class="button button-secondary">
                <?php esc_html_e( 'Regenerate facets (GPT) & rebuild masks', 'taxonomy-api' ); ?>
            </a>
        </p>

        <p class="description">
            <?php esc_html_e( 'Use this if you update the species name or want to refine GPT-based facet assignments.', 'taxonomy-api' ); ?>
        </p>
    </div>
    <?php
}


/**
 * Save handler for the Taxa Facets meta box.
 */
add_action( 'save_post', 'taxa_facets_save_meta_box', 20, 2 );
function taxa_facets_save_meta_box( $post_id, $post ) {

    // Bail on autosave, revisions, etc.
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return;
    }

    if ( is_int( wp_is_post_autosave( $post_id ) ) || is_int( wp_is_post_revision( $post_id ) ) ) {
        return;
    }

    // Only for your taxa post type (adjust if needed)
    if ( $post->post_type !== 'post' ) {
        return;
    }

    // Nonce check.
    if (
        ! isset( $_POST['taxa_facets_nonce'] )
        || ! wp_verify_nonce( $_POST['taxa_facets_nonce'], 'taxa_facets_save' )
    ) {
        return;
    }

    // Permissions.
    if ( ! current_user_can( 'edit_post', $post_id ) ) {
        return;
    }

    $facets = array();

    /*
     * COLORS (bitmask; multi)
     */
    $colors = isset( $_POST['taxa_facets_colors'] )
        ? (array) $_POST['taxa_facets_colors']
        : array();

    $colors = array_map( 'sanitize_text_field', $colors );
    $facets['color_mask'] = taxa_build_color_mask( $colors );

    /*
     * SIZE (single enum)
     */
    $size_slug = isset( $_POST['taxa_facets_size'] )
        ? sanitize_text_field( $_POST['taxa_facets_size'] )
        : '';

    if ( $size_slug !== '' ) {
        $size_ids = taxa_map_size_params( array( $size_slug ) ); // slug -> int
        $facets['size'] = ! empty( $size_ids ) ? (int) reset( $size_ids ) : null;
    } else {
        $facets['size'] = null;
    }

    /*
    * PRIMARY SHAPE (single enum – stored as shape_primary)
    */
    $shape_primary_slug = isset( $_POST['taxa_facets_shape_primary'] )
        ? sanitize_text_field( $_POST['taxa_facets_shape_primary'] )
        : '';

    if ( $shape_primary_slug !== '' ) {
        $shape_primary_ids = taxa_map_shape_primary_params( array( $shape_primary_slug ) );
        $facets['shape_primary'] = ! empty( $shape_primary_ids ) ? (int) reset( $shape_primary_ids ) : null;
    } else {
        $facets['shape_primary'] = null;
    }

    /*
    * SECONDARY SHAPE (single enum – stored as shape_secondary)
    */
    $shape_secondary_slug = isset( $_POST['taxa_facets_shape_secondary'] )
        ? sanitize_text_field( $_POST['taxa_facets_shape_secondary'] )
        : '';

    if ( $shape_secondary_slug !== '' ) {
        $shape_secondary_ids = taxa_map_shape_secondary_params( array( $shape_secondary_slug ) );
        $facets['shape_secondary'] = ! empty( $shape_secondary_ids ) ? (int) reset( $shape_secondary_ids ) : null;
    } else {
        $facets['shape_secondary'] = null;
    }

    /*
    * PATTERN (single enum – stored as pattern)
    */
    $pattern_slug = isset( $_POST['taxa_facets_pattern'] )
        ? sanitize_text_field( $_POST['taxa_facets_pattern'] )
        : '';

    if ( $pattern_slug !== '' ) {
        $pattern_ids = taxa_map_pattern_params( array( $pattern_slug ) );
        $facets['pattern'] = ! empty( $pattern_ids ) ? (int) reset( $pattern_ids ) : null;
    } else {
        $facets['pattern'] = null;
    }


    /*
     * DIET (single enum)
     */
    $diet_slug = isset( $_POST['taxa_facets_diet'] )
        ? sanitize_text_field( $_POST['taxa_facets_diet'] )
        : '';

    if ( $diet_slug !== '' ) {
        $diet_ids = taxa_map_diet_params( array( $diet_slug ) );
        $facets['diet'] = ! empty( $diet_ids ) ? (int) reset( $diet_ids ) : null;
    } else {
        $facets['diet'] = null;
    }

    /*
     * CALL TYPE (bitmask; multi)
     */
    $call_types = isset( $_POST['taxa_facets_call_types'] )
        ? (array) $_POST['taxa_facets_call_types']
        : array();

    $call_types = array_map( 'sanitize_text_field', $call_types );
    $facets['call_type_mask'] = taxa_build_call_type_mask( $call_types );

    /*
     * BEHAVIOR (bitmask; multi)
     */
    $behaviors = isset( $_POST['taxa_facets_behaviors'] )
        ? (array) $_POST['taxa_facets_behaviors']
        : array();

    $behaviors = array_map( 'sanitize_text_field', $behaviors );
    $facets['behavior_mask'] = taxa_build_behavior_mask( $behaviors );

    /*
     * HABITAT (bitmask; multi)
     */
    $habitats = isset( $_POST['taxa_facets_habitats'] )
        ? (array) $_POST['taxa_facets_habitats']
        : array();

    $habitats = array_map( 'sanitize_text_field', $habitats );
    $facets['habitat_mask'] = taxa_build_habitat_mask( $habitats );

    // Finally, write everything back into the compact table row.
    taxa_facets_update_row( $post_id, $facets );
}


/**
 * Register the Taxa Facets meta box for taxa posts.
 *
 * @param WP_Post $post
 */
function taxa_add_facets_meta_box( $post ) {
    $taxa_id = get_post_meta( $post->ID, 'taxa', true );
    if ( ! $taxa_id ) {
        return;
    }

    add_meta_box(
        'taxa-facets-meta',
        __( 'Taxa Facets', 'taxonomy-api' ),
        'taxa_facets_meta_box_render',
        'post',
        'side',
        'default'
    );
}
add_action( 'add_meta_boxes_post', 'taxa_add_facets_meta_box' );