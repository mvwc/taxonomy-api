<?php
/**
 * Helper functions, cron wiring, AI utilities, and editor UX - v2.0.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Get a singleton instance of the Taxonomy_Importer.
 *
 * @return Taxonomy_Importer
 */
function taxa_api_get_importer() {
    static $importer = null;

    if ( null === $importer ) {
        $importer = new Taxonomy_Importer();
    }

    return $importer;
}

/**
 * Schedule cron event for processing incomplete children.
 */
function taxa_api_schedule_cron() {
    taxa_api_clear_cron();

    $frequency = get_option( 'taxa_cron_frequency', 'manual' );

    if ( 'manual' === $frequency ) {
        return;
    }

    if ( ! in_array( $frequency, array( 'hourly', 'twicedaily', 'daily' ), true ) ) {
        $frequency = 'daily';
    }

    if ( ! wp_next_scheduled( 'taxa_process_incomplete_children_event' ) ) {
        wp_schedule_event( time() + 60, $frequency, 'taxa_process_incomplete_children_event' );
    }
}

/**
 * Clear scheduled cron event.
 */
function taxa_api_clear_cron() {
    $timestamp = wp_next_scheduled( 'taxa_process_incomplete_children_event' );
    if ( $timestamp ) {
        wp_unschedule_event( $timestamp, 'taxa_process_incomplete_children_event' );
    }
}

/**
 * Reschedule cron when frequency option changes.
 */
function taxa_api_handle_cron_option_change( $old_value, $value, $option ) { // phpcs:ignore
    taxa_api_schedule_cron();
}
add_action( 'update_option_taxa_cron_frequency', 'taxa_api_handle_cron_option_change', 10, 3 );

/**
 * Cron callback to process incomplete children.
 */
function taxa_api_cron_process_incomplete_children() {
    $importer = taxa_api_get_importer();
    $count    = $importer->process_pending_children();

    if ( $count > 0 ) {
        error_log( 'Taxonomy_Importer cron processed ' . $count . ' child taxa.' );
    }
}
add_action( 'taxa_process_incomplete_children_event', 'taxa_api_cron_process_incomplete_children' );



/**
 * ADMIN PAGES (wired by admin-settings.php menu)
 */

/**
 * Initialize root taxa via admin page.
 * Callback for ?page=taxa
 */
function testInit() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $taxa_id = get_option( 'primary_taxa_id', '' );

    echo '<div class="wrap"><h1>Initialize Root Taxa</h1>';

    if ( ! $taxa_id ) {
        echo '<p>No <strong>Primary Taxa ID</strong> set. Please configure it under <em>Settings &gt; Taxonomy API Settings</em> first.</p>';
        echo '</div>';
        return;
    }

    $importer = taxa_api_get_importer();
    $result   = $importer->initialize_root_taxa( $taxa_id );

    if ( $result ) {
        echo '<p>Root taxa initialization attempted for ID <code>' . esc_html( $taxa_id ) . '</code>. Check your posts list for new/updated taxa posts.</p>';
    } else {
        echo '<p>There was a problem initializing root taxa for ID <code>' . esc_html( $taxa_id ) . '</code>. See debug log for details.</p>';
    }

    echo '</div>';
}

/**
 * Manual children update page.
 * Callback for ?page=updatechildren
 */
function updateChildren() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    echo '<div class="wrap"><h1>Process Pending Child Taxa</h1>';

    $importer = taxa_api_get_importer();
    $count    = $importer->process_pending_children();

    echo '<p>Processed <strong>' . intval( $count ) . '</strong> pending child taxa this run.</p>';
    echo '<p>You can adjust batch size and cron frequency under the <em>Import &amp; Cron</em> tab in Settings.</p>';
    echo '</div>';
}

/**
 * --- GPT / AI UTILITIES ---
 */


/**
 * Call OpenAI Chat Completions API and return the response text.
 *
 * @param string $prompt
 * @param string $model
 * @return string|null
 */
function get_gpt_response( $prompt, $model ) {

    $gpt_enabled = get_option( 'gpt_enabled', '0' );

    if ( ! in_array( $gpt_enabled, array( 'true', true, '1', 1 ), true ) ) {
        error_log( 'Taxa Plugin: GPT disabled in settings.' );
        return null;
    }

    $api_key = get_option( 'gpt_api_key', '' );
    $url     = 'https://api.openai.com/v1/chat/completions';

    if ( empty( $api_key ) ) {
        error_log( 'Taxa Plugin: OpenAI API key is missing or not set.' );
        return null;
    }

    $site_focus_keyword = get_option( 'site_focus_keyword', '' );

    $data = array(
        'model'    => $model,
        'messages' => array(
            array(
                'role'    => 'system',
                'content' => 'You are an expert on the ' . $site_focus_keyword . ' taxa.',
            ),
            array(
                'role'    => 'user',
                'content' => $prompt,
            ),
        ),
        'temperature' => 0.1,
        'max_tokens'  => 5000,
    );

    $headers = array(
        'Content-Type: application/json',
        'Authorization: Bearer ' . $api_key,
    );

    $ch = curl_init( $url );
    curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
    curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers );
    curl_setopt( $ch, CURLOPT_POST, true );
    curl_setopt( $ch, CURLOPT_POSTFIELDS, wp_json_encode( $data ) );

    $response = curl_exec( $ch );

    if ( curl_errno( $ch ) ) {
        error_log( 'Taxa Plugin: cURL error: ' . curl_error( $ch ) );
        curl_close( $ch );
        return null;
    }

    curl_close( $ch );

    $response_data = json_decode( $response, true );

    if ( isset( $response_data['choices'][0]['message']['content'] ) ) {
        return $response_data['choices'][0]['message']['content'];
    }

    error_log( 'Taxa Plugin: Unexpected OpenAI response: ' . json_encode( $response_data ) );
    return null;
}

/**
 * Update a core meta field using a GPT prompt template if empty.
 *
 * @param int    $post_id
 * @param string $field_name
 */
function updateCoreMetaField( $post_id, $field_name ) {
    $gpt_enabled = get_option( 'gpt_enabled', '0' );
    if ( ! in_array( $gpt_enabled, array( 'true', true, '1', 1 ), true ) ) {
        return;
    }

    $post_title = get_the_title( $post_id );
    $existing   = get_post_meta( $post_id, $field_name, true );

    if ( ! empty( $existing ) ) {
        return;
    }

    $field_prompt_template = get_option( $field_name . '_prompt', 'Respond with the Identification Tips for $post_title' );
    $field_prompt          = str_replace( '$post_title', $post_title, $field_prompt_template );

    $response = get_gpt_response( $field_prompt, 'gpt-4o-mini' );
    if ( ! $response ) {
        return;
    }

    update_post_meta( $post_id, $field_name, wp_kses_post( $response ) );
}

/**
 * Update an arbitrary meta field using custom key/value prompt definitions.
 *
 * @param int    $post_id
 * @param string $field_name
 */
function updateMetaField( $post_id, $field_name ) {
    $gpt_enabled = get_option( 'gpt_enabled', '0' );
    if ( ! in_array( $gpt_enabled, array( 'true', true, '1', 1 ), true ) ) {
        return;
    }

    $post_title = get_the_title( $post_id );
    $option_val = get_option( 'custom_key_value_pairs' );
    $prompts    = maybe_unserialize( $option_val );

    if ( ! isset( $prompts[ $field_name ]['value'] ) ) {
        error_log( 'Taxa Plugin: Field name not found in custom key-value pairs: ' . $field_name );
        return;
    }

    $prompt_template = $prompts[ $field_name ]['value'];
    $prompt          = str_replace( '$post_title', $post_title, $prompt_template );

    $response = get_gpt_response( $prompt, 'gpt-4o-mini' );
    if ( ! $response ) {
        return;
    }

    $response           = rtrim( $response, '.' );
    $processed_response = strtolower( trim( $response ) );

    $response_array = array_map( 'trim', explode( ',', $processed_response ) );

    update_post_meta( $post_id, $field_name, $response_array );
}

/**
 * Generate GPT-based blog content on new post creation.
 */
function generate_taxa_blog_post( $post_ID, $post, $update ) {
    $gpt_enabled = get_option( 'gpt_enabled', '0' );

    if ( ! in_array( $gpt_enabled, array( 'true', true, '1', 1 ), true ) ) {
        return;
    }

    // Avoid infinite loop and autosaves.
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return;
    }

    if ( wp_is_post_revision( $post_ID ) ) {
        return;
    }

    // Only for brand new posts.
    if ( $update ) {
        return;
    }

    if ( 'post' !== $post->post_type ) {
        return;
    }

    $prompt_template = get_option( 'gpt_prompt' );
    if ( false === $prompt_template ) {
        return;
    }

    $post_title = $post->post_title;
    $prompt     = str_replace( '$post_title', $post_title, $prompt_template );

    $generated_content = get_gpt_response( $prompt, 'gpt-4o-mini' );
    if ( empty( $generated_content ) ) {
        return;
    }

    $existing_content = $post->post_content;
    $new_content      = $generated_content . "\n\n" . $existing_content;

    $result = wp_update_post(
        array(
            'ID'           => $post_ID,
            'post_content' => $new_content,
        ),
        true
    );

    if ( is_wp_error( $result ) ) {
        error_log( 'Taxa Plugin: Failed to update post content. Error: ' . $result->get_error_message() );
    }
}
add_action( 'save_post', 'generate_taxa_blog_post', 10, 3 );

/**
 * Add a custom "Generate GPT Content" link to post rows.
 */
function add_gpt_action_link( $actions, $post ) {
    if ( $post->post_type === 'post' ) {
        $url                                = admin_url( 'admin-post.php?action=generate_gpt_content&post_id=' . $post->ID );
        $actions['generate_gpt_content'] = '<a href="' . esc_url( $url ) . '">Generate GPT Content</a>';
    }
    return $actions;
}
add_filter( 'post_row_actions', 'add_gpt_action_link', 10, 2 );

/**
 * Handle the "Generate GPT Content" admin action.
 */
function handle_gpt_content_generation() {
    if ( ! current_user_can( 'edit_posts' ) ) {
        return;
    }

    $post_id = isset( $_GET['post_id'] ) ? intval( $_GET['post_id'] ) : 0;
    if ( $post_id ) {
        $post = get_post( $post_id );
        generate_taxa_blog_post( $post_id, $post, false );
        wp_redirect( admin_url( 'edit.php?post_type=post' ) );
        exit;
    }
}
add_action( 'admin_post_generate_gpt_content', 'handle_gpt_content_generation' );



/**
 * --- RANK MATH / TAXONOMY META HELPERS ---
 */

/**
 * Set Rank Math focus keyword to the post title.
 *
 * @param int $post_id
 */
function set_rank_math_focus_keyword( $post_id ) {
    $post_title = get_the_title( $post_id );
    if ( ! $post_title ) {
        return;
    }
    update_post_meta( $post_id, 'rank_math_focus_keyword', $post_title );
}

/**
 * --- META / TAXONOMY HELPERS ---
 */

/**
 * Get a post ID by meta key and value.
 *
 * @param string $meta_key
 * @param string $meta_value
 * @return int|null
 */
function get_post_id_by_meta_value( $meta_key, $meta_value ) {
    global $wpdb;
    $query = $wpdb->prepare(
        "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = %s AND meta_value = %s LIMIT 1",
        $meta_key,
        $meta_value
    );

    $post_id = $wpdb->get_var( $query );
    return $post_id ? (int) $post_id : null;
}

/**
 * Append an external image to the gallery_photo + gallery_photo_dates meta.
 *
 * @param int    $post_id
 * @param string $image_url
 * @param string $caption
 */
function add_external_image_url( $post_id, $image_url, $caption ) {
    $gallery_array = get_post_meta( $post_id, 'gallery_photo', true );
    $gallery_dates = get_post_meta( $post_id, 'gallery_photo_dates', true );

    if ( ! is_array( $gallery_array ) ) {
        $gallery_array = array();
    }

    if ( ! is_array( $gallery_dates ) ) {
        $gallery_dates = array();
    }

    $current_date = current_time( 'mysql' );

    $gallery_array[] = array(
        'url'     => esc_url_raw( $image_url ),
        'caption' => sanitize_text_field( $caption ),
    );

    $gallery_dates[] = $current_date;

    update_post_meta( $post_id, 'gallery_photo', $gallery_array );
    update_post_meta( $post_id, 'gallery_photo_dates', $gallery_dates );
}

/**
 * Fetch and update gallery images from the iNaturalist API for a given taxa post.
 *
 * @param WP_Post $post
 */
function update_gallery_images( $post ) {
    if ( ! $post instanceof WP_Post ) {
        return;
    }

    // Get taxa ID from post meta.
    $taxa_id = get_post_meta( $post->ID, 'taxa', true );
    if ( ! $taxa_id ) {
        return;
    }

    $url      = 'https://api.inaturalist.org/v1/taxa/' . $taxa_id;
    $response = wp_remote_get( $url, array( 'timeout' => 15 ) );

    if ( is_wp_error( $response ) ) {
        return;
    }

    $body = wp_remote_retrieve_body( $response );
    if ( is_wp_error( $body ) || empty( $body ) ) {
        return;
    }

    $data = json_decode( $body, true );
    if ( ! $data || empty( $data['results'] ) || ! is_array( $data['results'] ) ) {
        return;
    }

    foreach ( $data['results'] as $taxa_self_data ) {

        // Taxon photos → gallery.
        if ( ! empty( $taxa_self_data['taxon_photos'] ) && is_array( $taxa_self_data['taxon_photos'] ) ) {
            foreach ( $taxa_self_data['taxon_photos'] as $taxa_self_photos ) {
                if ( empty( $taxa_self_photos['photo'] ) ) {
                    continue;
                }

                $photo       = $taxa_self_photos['photo'];
                $attribution = isset( $photo['attribution'] ) ? $photo['attribution'] : '';

                // Skip "all rights reserved".
                if ( $attribution && false !== strpos( strtolower( $attribution ), 'all rights reserved' ) ) {
                    continue;
                }

                if ( ! empty( $photo['large_url'] ) ) {
                    add_external_image_url( $post->ID, $photo['large_url'], $attribution );
                }
            }
        }

        // Extinct flag.
        if ( isset( $taxa_self_data['extinct'] ) ) {
            update_post_meta( $post->ID, 'extinct', (bool) $taxa_self_data['extinct'] );
        }
    }
}


/**
 * Update category based on ancestry (order/suborder ancestor).
 *
 * @param int $post_id
 */
function update_post_category_based_on_ancestry( $post_id ) {
    if ( 'post' !== get_post_type( $post_id ) ) {
        return;
    }

    $ancestry = get_post_meta( $post_id, 'ancestry', true );
    if ( ! $ancestry ) {
        return;
    }

    $ancestry_ids  = array_reverse( explode( '/', $ancestry ) );
    $category_name = '';

    foreach ( $ancestry_ids as $taxa_id ) {
        $ancestor_post_id = get_post_id_by_meta_value( 'taxa', $taxa_id );
        if ( $ancestor_post_id ) {
            $rank = get_post_meta( $ancestor_post_id, 'rank', true );
            if ( 'order' === $rank || 'suborder' === $rank ) {
                $category_name = get_the_title( $ancestor_post_id );
                break;
            }
        }
    }

    if ( ! $category_name ) {
        return;
    }

    $category_name = str_replace( ',', '&#44;', $category_name );
    $category      = get_term_by( 'name', $category_name, 'category' );

    if ( ! $category ) {
        $category_id = wp_insert_term( $category_name, 'category' );
        if ( is_wp_error( $category_id ) ) {
            return;
        }
        $category_id = $category_id['term_id'];
    } else {
        $category_id = $category->term_id;
    }

    wp_set_post_categories( $post_id, array( $category_id ) );
}

/**
 * Update tag based on ancestry (order ancestor).
 *
 * @param int $post_id
 */
function update_post_tag_based_on_ancestry( $post_id ) {
    if ( 'post' !== get_post_type( $post_id ) ) {
        return;
    }

    if ( wp_is_post_revision( $post_id ) ) {
        return;
    }

    $ancestry = get_post_meta( $post_id, 'ancestry', true );
    if ( ! $ancestry ) {
        return;
    }

    $ancestry_ids = explode( '/', $ancestry );
    $tag_name     = '';

    foreach ( $ancestry_ids as $taxa_id ) {
        $ancestor_post_id = get_post_id_by_meta_value( 'taxa', $taxa_id );
        if ( $ancestor_post_id ) {
            $rank = get_post_meta( $ancestor_post_id, 'rank', true );
            if ( 'order' === $rank ) {
                $tag_name = get_the_title( $ancestor_post_id );
                break;
            }
        }
    }

    if ( ! $tag_name ) {
        return;
    }

    $tag_name = str_replace( ',', '&#44;', $tag_name );
    wp_set_post_tags( $post_id, array( $tag_name ), true );
}

/**
 * Create category based on rank meta.
 *
 * @param int $post_id
 */
function create_category_based_on_rank( $post_id ) {
    $rank = get_post_meta( $post_id, 'rank', true );
    if ( ! $rank ) {
        return;
    }

    $rank     = str_replace( ',', '&#44;', $rank );
    $category = get_term_by( 'name', $rank, 'category' );

    if ( ! $category ) {
        $category_id = wp_insert_term( $rank, 'category' );
        if ( is_wp_error( $category_id ) ) {
            return;
        }
        $category_id = $category_id['term_id'];
    } else {
        $category_id = $category->term_id;
    }

    wp_set_post_categories( $post_id, array( $category_id ), true );
}

// Hook these light helpers into save_post for taxonomy posts.
add_action( 'save_post', 'update_post_category_based_on_ancestry' );
add_action( 'save_post', 'update_post_tag_based_on_ancestry' );
add_action( 'save_post', 'create_category_based_on_rank' );
add_action( 'save_post', 'set_rank_math_focus_keyword' );



/**
 * --- EDIT SCREEN META BOX: Taxa Children ---
 */

/**
 * Register the "Taxa Children" meta box on post edit screen for taxa posts.
 */
function taxa_add_children_meta_box( $post ) {
    // Only show for posts that have a 'taxa' meta key (i.e., taxa posts).
    $taxa_id = get_post_meta( $post->ID, 'taxa', true );
    if ( ! $taxa_id ) {
        return;
    }

    add_meta_box(
        'taxa_children_meta',
        __( 'Taxa Children', 'taxonomy-api' ),
        'taxa_children_meta_box_render',
        'post',
        'side',
        'default'
    );
}
add_action( 'add_meta_boxes_post', 'taxa_add_children_meta_box' );

/**
 * Meta box: Taxa Children + Ingestion controls (table UI).
 *
 * Columns:
 * - Taxa ID
 * - Post / Link (once ingested)
 * - Status
 * - Action (Ingest button for pending children)
 */
function taxa_children_meta_box_render( $post ) {
    // Children structure is stored as an array in 'children' meta.
    $children = get_post_meta( $post->ID, 'children', true );
    if ( ! is_array( $children ) ) {
        $children = array();
    }

    // Determine pending children.
    $pending_children = array_filter(
        $children,
        function( $child ) {
            return isset( $child['processed'] ) && ! $child['processed'];
        }
    );
    $pending_count = count( $pending_children );

    echo '<div class="taxa-meta-box">';

    // Meta box header with badge.
    echo '<p><strong>Taxa Children</strong>';

    if ( $pending_count > 0 ) {
        echo ' <span style="
            display:inline-block;
            padding:2px 6px;
            margin-left:6px;
            font-size:11px;
            border-radius:999px;
            background:#d63638;
            color:#fff;
        ">children pending</span>';
    } else {
        echo ' <span style="
            display:inline-block;
            padding:2px 6px;
            margin-left:6px;
            font-size:11px;
            border-radius:999px;
            background:#46b450;
            color:#fff;
        ">all children ingested</span>';
    }

    echo '</p>';

    // No children at all.
    if ( empty( $children ) ) {
        echo '<p><em>No children recorded for this taxa.</em></p>';
        echo '</div>';
        return;
    }

    // Table wrapper: no fixed height, let content define it.
    echo '<div style="border:1px solid #ddd;border-radius:3px;overflow-x:auto;">';
    echo '<table class="widefat striped" style="margin:0;font-size:12px;">';
    echo '<thead>';
    echo '<tr>';
    echo '<th>Taxa&nbsp;ID</th>';
    echo '<th>Post / Link</th>';
    echo '<th>Status</th>';
    echo '<th>Action</th>';
    echo '</tr>';
    echo '</thead>';
    echo '<tbody>';

    foreach ( $children as $child ) {
        $child_id        = isset( $child['id'] ) ? (int) $child['id'] : 0;
        $child_processed = isset( $child['processed'] ) ? (bool) $child['processed'] : false;

        if ( ! $child_id ) {
            continue;
        }

        // Find existing post by `taxa` meta (no GenerateTaxaProcessor dependency).
        $existing_post_id = get_post_id_by_meta_value( 'taxa', $child_id );
        $post_link_html   = '<em>not yet created</em>';

        if ( $existing_post_id ) {
            $title     = get_the_title( $existing_post_id );
            $edit_link = get_edit_post_link( $existing_post_id, '' ); // <<< NEW: links to the edit screen

            if ( $edit_link ) {
                $post_link_html  = '<a href="' . esc_url( $edit_link ) . '" target="_blank">';
                $post_link_html .= esc_html( $title );
                $post_link_html .= '</a>';
            } else {
                $post_link_html = esc_html( $title );
            }
        }

        // Status label.
        if ( $child_processed ) {
            $status_html = '<span style="color:#46b450;font-weight:600;">ingested</span>';
        } else {
            $status_html = '<span style="color:#d63638;font-weight:600;">pending</span>';
        }

        // Action column: ingest button only if still pending.
        $action_html = '<span style="color:#999;">—</span>';

        if ( ! $child_processed ) {
            $nonce = wp_create_nonce( 'taxa_ingest_child_' . $post->ID . '_' . $child_id );
            $url   = add_query_arg(
                array(
                    'action'        => 'taxa_ingest_child',
                    'post_id'       => $post->ID,
                    'taxa_child_id' => $child_id,
                    '_wpnonce'      => $nonce,
                ),
                admin_url( 'admin-post.php' )
            );

            $action_html  = '<a href="' . esc_url( $url ) . '" ';
            $action_html .= 'class="button button-small" ';
            $action_html .= 'style="font-size:11px;line-height:1.4;">Ingest</a>';
        }

        echo '<tr>';
        echo '<td>' . esc_html( $child_id ) . '</td>';
        echo '<td>' . $post_link_html . '</td>';
        echo '<td>' . $status_html . '</td>';
        echo '<td>' . $action_html . '</td>';
        echo '</tr>';
    }

    echo '</tbody>';
    echo '</table>';
    echo '</div>';

    // Bulk "Ingest Pending Children" button, if there’s anything left to do.
    if ( $pending_count > 0 ) {
        $all_nonce = wp_create_nonce( 'taxa_ingest_all_children_' . $post->ID );
        $all_url   = add_query_arg(
            array(
                'action'   => 'taxa_ingest_all_children',
                'post_id'  => $post->ID,
                '_wpnonce' => $all_nonce,
            ),
            admin_url( 'admin-post.php' )
        );

        echo '<p style="margin-top:8px;">';
        echo '<a href="' . esc_url( $all_url ) . '" class="button button-secondary button-small">';
        echo 'Ingest Pending Children';
        echo '</a>';
        echo '</p>';
    } else {
        echo '<p style="margin-top:8px;font-size:11px;color:#777;"><em>All children have been ingested.</em></p>';
    }

    echo '</div>';
}



/**
 * Handle ingesting a single child taxa from the edit screen.
 */
function taxa_handle_ingest_child() {
    if ( ! isset( $_GET['post_id'], $_GET['taxa_child_id'], $_GET['_wpnonce'] ) ) {
        wp_die( esc_html__( 'Missing parameters.', 'taxonomy-api' ) );
    }

    $post_id  = absint( $_GET['post_id'] );
    $child_id = sanitize_text_field( wp_unslash( $_GET['taxa_child_id'] ) );

    if ( ! current_user_can( 'edit_post', $post_id ) ) {
        wp_die( esc_html__( 'You do not have permission to edit this post.', 'taxonomy-api' ) );
    }

    check_admin_referer( 'taxa_ingest_child_' . $post_id . '_' . $child_id );

    $importer = taxa_api_get_importer();
    $success  = $importer->ingest_child_by_id( $child_id, $post_id );

    $args = array(
        'post'   => $post_id,
        'action' => 'edit',
    );

    if ( $success ) {
        $args['taxa_children_ingested'] = 1;
    } else {
        $args['taxa_children_error'] = 1;
    }

    wp_redirect( add_query_arg( $args, admin_url( 'post.php' ) ) );
    exit;
}
add_action( 'admin_post_taxa_ingest_child', 'taxa_handle_ingest_child' );

/**
 * Handle ingesting all pending children for a post from the edit screen.
 */
function taxa_handle_ingest_all_children() {
    if ( ! isset( $_GET['post_id'], $_GET['_wpnonce'] ) ) {
        wp_die( esc_html__( 'Missing parameters.', 'taxonomy-api' ) );
    }

    $post_id = absint( $_GET['post_id'] );

    if ( ! current_user_can( 'edit_post', $post_id ) ) {
        wp_die( esc_html__( 'You do not have permission to edit this post.', 'taxonomy-api' ) );
    }

    check_admin_referer( 'taxa_ingest_all_children_' . $post_id );

    $children = get_post_meta( $post_id, 'children', true );
    if ( ! is_array( $children ) || empty( $children ) ) {
        $args = array(
            'post'                   => $post_id,
            'action'                 => 'edit',
            'taxa_children_ingested' => 0,
        );
        wp_redirect( add_query_arg( $args, admin_url( 'post.php' ) ) );
        exit;
    }

    $importer = taxa_api_get_importer();
    $count    = 0;

    foreach ( $children as $child ) {
        if ( ! isset( $child['id'] ) ) {
            continue;
        }
        $child_id  = $child['id'];
        $processed = isset( $child['processed'] ) ? (bool) $child['processed'] : false;

        if ( $processed ) {
            continue;
        }

        $success = $importer->ingest_child_by_id( $child_id, $post_id );
        if ( $success ) {
            $count++;
        }
    }

    $args = array(
        'post'                   => $post_id,
        'action'                 => 'edit',
        'taxa_children_ingested' => $count,
    );

    wp_redirect( add_query_arg( $args, admin_url( 'post.php' ) ) );
    exit;
}
add_action( 'admin_post_taxa_ingest_all_children', 'taxa_handle_ingest_all_children' );





/**
 * Show admin notices after ingesting children from the edit screen.
 */
function taxa_children_admin_notices() {
    if ( ! is_admin() ) {
        return;
    }

    if ( ! current_user_can( 'edit_posts' ) ) {
        return;
    }

    if ( isset( $_GET['taxa_children_ingested'] ) ) {
        $count = intval( $_GET['taxa_children_ingested'] );
        $msg   = ( 1 === $count )
            ? __( '1 child taxa was ingested.', 'taxonomy-api' )
            : sprintf( __( '%d child taxa were ingested.', 'taxonomy-api' ), $count );

        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
    }

    if ( isset( $_GET['taxa_children_error'] ) ) {
        echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'There was an error ingesting the requested child taxa. Check the debug log for details.', 'taxonomy-api' ) . '</p></div>';
    }

	if ( isset( $_GET['taxa_gallery_cleared'] ) ) {
        echo '<div class="notice notice-success is-dismissible"><p>' .
            esc_html__( 'Taxon gallery cleared. Images will be refreshed on the next front-end view of this taxa.', 'taxonomy-api' ) .
            '</p></div>';
    }

    if ( isset( $_GET['taxa_observation_gallery_cleared'] ) ) {
        echo '<div class="notice notice-success is-dismissible"><p>' .
            esc_html__( 'Observation gallery cleared. Images will be refreshed on the next front-end view of this taxa.', 'taxonomy-api' ) .
            '</p></div>';
    }
    if ( isset( $_GET['taxa_fifu_refreshed'] ) ) {
        echo '<div class="notice notice-success is-dismissible"><p>' .
            esc_html__( 'Featured image (FIFU) was refreshed from iNaturalist for this taxa.', 'taxonomy-api' ) .
            '</p></div>';
    }

}
add_action( 'admin_notices', 'taxa_children_admin_notices' );

/**
 * Show a small "Children pending" badge in the Posts list
 * for taxa posts that still have unprocessed children.
 */
function taxa_children_pending_post_state( $post_states, $post ) {

    // Only apply to regular posts in the admin list table.
    if ( is_admin() && 'post' === $post->post_type ) {

        // Only care about posts that are taxa posts (i.e., have a 'taxa' meta).
        $taxa_id = get_post_meta( $post->ID, 'taxa', true );
        if ( ! $taxa_id ) {
            return $post_states;
        }

        $children = get_post_meta( $post->ID, 'children', true );
        if ( ! is_array( $children ) || empty( $children ) ) {
            return $post_states;
        }

        // Check if any child is still unprocessed.
        $has_pending = false;
        foreach ( $children as $child ) {
            if ( isset( $child['processed'] ) && $child['processed'] === false ) {
                $has_pending = true;
                break;
            }
        }

        if ( $has_pending ) {
            // This shows as a small grey badge next to the post title.
            $post_states['taxa_children_pending'] = __( 'Children pending', 'taxonomy-api' );
        }
    }

    return $post_states;
}
add_filter( 'display_post_states', 'taxa_children_pending_post_state', 10, 2 );

/**
 * On single taxa posts:
 * - Ensure gallery images are up to date (once or if old).
 * - Set FIFU alt/url from the first gallery image if missing.
 */
function taxa_maybe_update_gallery_images() {
    if ( ! is_single() || 'post' !== get_post_type() ) {
        return;
    }

    global $post;
    if ( ! $post instanceof WP_Post ) {
        return;
    }

    // 1) GALLERY UPDATE LOGIC (refresh if last update > 30 days ago)
    $gallery_photo_dates = get_post_meta( $post->ID, 'gallery_photo_dates', true );
    $last_update         = taxa_get_last_gallery_update_date( $gallery_photo_dates );

    $needs_update   = false;
    $days_interval  = 30;
    $threshold_time = current_time( 'timestamp' ) - ( $days_interval * DAY_IN_SECONDS );

    if ( ! $last_update ) {
        // No dates saved yet → definitely need an update.
        $needs_update = true;
    } else {
        $last_ts = strtotime( $last_update );
        if ( false === $last_ts || $last_ts < $threshold_time ) {
            $needs_update = true;
        }
    }

    if ( $needs_update ) {
        update_gallery_images( $post );
        // error_log( 'Gallery Update for site: ' . get_bloginfo( 'name' ) . ', Post ID: ' . $post->ID );
    }


    // 2) FIFU ALT/URL BACKFILL FROM GALLERY (non-destructive)
    taxa_backfill_fifu_from_gallery( $post->ID, false );

}
add_action( 'wp', 'taxa_maybe_update_gallery_images' );

/**
 * Safely get the most recent date from a meta field that stores an array of dates.
 *
 * @param mixed $meta_value
 * @return string|null  MySQL datetime string or null if unavailable.
 */
function taxa_get_last_gallery_update_date( $meta_value ) {
    if ( empty( $meta_value ) ) {
        return null;
    }

    // Handle legacy serialized values just in case.
    if ( is_string( $meta_value ) ) {
        $maybe = maybe_unserialize( $meta_value );
        if ( $maybe !== false || $meta_value === 'b:0;' ) {
            $meta_value = $maybe;
        }
    }

    if ( ! is_array( $meta_value ) || empty( $meta_value ) ) {
        return null;
    }

    $last = end( $meta_value );
    if ( ! $last ) {
        return null;
    }

    return $last;
}

/**
 * Backfill (or refresh) FIFU external featured image from the taxon gallery.
 *
 * @param int  $post_id
 * @param bool $force If true, overwrite existing FIFU meta. If false, only set when empty.
 */
function taxa_backfill_fifu_from_gallery( $post_id, $force = false ) {
    $post_id = (int) $post_id;
    if ( $post_id <= 0 ) {
        return;
    }

    // If not forcing, respect any existing FIFU settings.
    if ( ! $force ) {
        $existing_alt = get_post_meta( $post_id, 'fifu_image_alt', true );
        $existing_url = get_post_meta( $post_id, 'fifu_image_url', true );
        if ( $existing_alt || $existing_url ) {
            return;
        }
    }

    $gallery_photo = get_post_meta( $post_id, 'gallery_photo', true );

    // Handle legacy serialized storage if needed.
    if ( is_string( $gallery_photo ) ) {
        $maybe = maybe_unserialize( $gallery_photo );
        if ( $maybe !== false || $gallery_photo === 'b:0;' ) {
            $gallery_photo = $maybe;
        }
    }

    if ( ! is_array( $gallery_photo ) || empty( $gallery_photo ) ) {
        return;
    }

    $first = $gallery_photo[0];

    if ( empty( $first['url'] ) ) {
        return;
    }

    $alt = '';
    if ( ! empty( $first['caption'] ) ) {
        $alt = $first['caption'];
    } else {
        $alt = get_the_title( $post_id );
    }

    update_post_meta( $post_id, 'fifu_image_alt', sanitize_text_field( $alt ) );
    update_post_meta( $post_id, 'fifu_image_url', esc_url_raw( $first['url'] ) );
}


/**
 * Append an external image to the observation_gallery_photo + observation_gallery_photo_dates meta.
 *
 * Structure mirrors gallery_photo so Kadence can consume it the same way.
 *
 * @param int    $post_id
 * @param string $image_url
 * @param string $caption
 */
function add_observation_image_url( $post_id, $image_url, $caption ) {
    $gallery_array = get_post_meta( $post_id, 'observation_gallery_photo', true );
    $gallery_dates = get_post_meta( $post_id, 'observation_gallery_photo_dates', true );

    if ( ! is_array( $gallery_array ) ) {
        $gallery_array = array();
    }

    if ( ! is_array( $gallery_dates ) ) {
        $gallery_dates = array();
    }

    $current_date = current_time( 'mysql' );

    $gallery_array[] = array(
        'url'     => esc_url_raw( $image_url ),
        'caption' => sanitize_text_field( $caption ),
    );

    $gallery_dates[] = $current_date;

    update_post_meta( $post_id, 'observation_gallery_photo', $gallery_array );
    update_post_meta( $post_id, 'observation_gallery_photo_dates', $gallery_dates );
}

/**
 * Fetch and update observation-based gallery images from the iNaturalist API for a given taxa post.
 *
 * Uses /v1/observations?taxon_id={taxa_id} and pulls taxon.default_photo.medium_url.
 *
 * @param WP_Post $post
 */
function update_observation_gallery_images( $post ) {
    if ( ! $post instanceof WP_Post ) {
        return;
    }

    // Get taxa ID from post meta.
    $taxa_id = get_post_meta( $post->ID, 'taxa', true );
    if ( ! $taxa_id ) {
        return;
    }

    // Build observations endpoint query:
    $api_url = add_query_arg(
        array(
            'taxon_id' => $taxa_id,
            'per_page' => 50,
            'order'    => 'desc',
            'order_by' => 'created_at',
        ),
        'https://api.inaturalist.org/v1/observations'
    );

    $response = wp_remote_get( $api_url, array( 'timeout' => 15 ) );
    if ( is_wp_error( $response ) ) {
        return;
    }

    $body = wp_remote_retrieve_body( $response );
    if ( is_wp_error( $body ) || empty( $body ) ) {
        return;
    }

    $data = json_decode( $body, true );
    if ( ! $data || empty( $data['results'] ) || ! is_array( $data['results'] ) ) {
        return;
    }

    // Reset the observation gallery before inserting fresh items
    delete_post_meta( $post->ID, 'observation_gallery_photo' );
    delete_post_meta( $post->ID, 'observation_gallery_photo_dates' );

    $used_urls = array();

    foreach ( $data['results'] as $observation ) {
        if ( empty( $observation['taxon'] ) || empty( $observation['taxon']['default_photo'] ) ) {
            continue;
        }

        $default_photo = $observation['taxon']['default_photo'];

        // Attribution & license.
        $attribution = isset( $default_photo['attribution'] ) ? $default_photo['attribution'] : '';

        // 🔒 Skip restricted copyright: "all rights reserved".
        if ( $attribution && stripos( $attribution, 'all rights reserved' ) !== false ) {
            continue;
        }

        // Prefer medium_url, fallback to url.
        $image_url = '';
        if ( ! empty( $default_photo['medium_url'] ) ) {
            $image_url = $default_photo['medium_url'];
        } elseif ( ! empty( $default_photo['url'] ) ) {
            $image_url = $default_photo['url'];
        }

        if ( empty( $image_url ) ) {
            continue;
        }

        // Avoid duplicates if multiple observations use the same default_photo.
        if ( in_array( $image_url, $used_urls, true ) ) {
            continue;
        }

        $used_urls[] = $image_url;

        add_observation_image_url(
            $post->ID,
            $image_url,
            $attribution ?: 'Unknown attribution'
        );
    }
}

/**
 * On single taxa posts, ensure observation gallery images are up to date.
 */
function taxa_maybe_update_observation_gallery_images() {
    if ( ! is_single() || 'post' !== get_post_type() ) {
        return;
    }

    global $post;
    if ( ! $post instanceof WP_Post ) {
        return;
    }

    // 1) OBSERVATION GALLERY UPDATE LOGIC (refresh if last update > 30 days ago)
    $gallery_photo_dates = get_post_meta( $post->ID, 'observation_gallery_photo_dates', true );
    $last_update         = taxa_get_last_gallery_update_date( $gallery_photo_dates );

    $needs_update   = false;
    $days_interval  = 30;
    $threshold_time = current_time( 'timestamp' ) - ( $days_interval * DAY_IN_SECONDS );

    if ( ! $last_update ) {
        // No dates saved yet → definitely need an update.
        $needs_update = true;
    } else {
        $last_ts = strtotime( $last_update );
        if ( false === $last_ts || $last_ts < $threshold_time ) {
            $needs_update = true;
        }
    }

    if ( $needs_update ) {
        update_observation_gallery_images( $post );
        // error_log( 'Observation gallery update for Post ID: ' . $post->ID );
    }

}
add_action( 'wp', 'taxa_maybe_update_observation_gallery_images' );

/**
 * Meta box: Taxa Gallery Info.
 *
 * Shows:
 * - Taxon Gallery: image count + last updated
 * - Observation Gallery: image count + last updated
 */
function taxa_gallery_info_meta_box_render( $post ) {

    // Taxon gallery.
    $gallery_images = get_post_meta( $post->ID, 'gallery_photo', true );
    $gallery_dates  = get_post_meta( $post->ID, 'gallery_photo_dates', true );

    $gallery_count = ( is_array( $gallery_images ) ) ? count( $gallery_images ) : 0;
    $gallery_last  = taxa_get_last_gallery_update_date( $gallery_dates );

    // Observation gallery.
    $obs_gallery_images = get_post_meta( $post->ID, 'observation_gallery_photo', true );
    $obs_gallery_dates  = get_post_meta( $post->ID, 'observation_gallery_photo_dates', true );

    $obs_count = ( is_array( $obs_gallery_images ) ) ? count( $obs_gallery_images ) : 0;
    $obs_last  = taxa_get_last_gallery_update_date( $obs_gallery_dates );

    echo '<div class="taxa-meta-box">';

    echo '<p><strong>Gallery Status</strong></p>';

    // Taxon Gallery block.
    echo '<p style="margin:0 0 8px 0;"><strong>Taxon Gallery</strong><br />';
    echo 'Images: <strong>' . intval( $gallery_count ) . '</strong><br />';
    if ( $gallery_last ) {
        $formatted = date_i18n(
            get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
            strtotime( $gallery_last )
        );
        echo 'Last updated: ' . esc_html( $formatted );
    } else {
        echo 'Last updated: not yet updated';
    }
    echo '</p>';

    echo '<hr />';

    // Observation Gallery block.
    echo '<p style="margin:0 0 8px 0;"><strong>Observation Gallery</strong><br />';
    echo 'Images: <strong>' . intval( $obs_count ) . '</strong><br />';
    if ( $obs_last ) {
        $formatted = date_i18n(
            get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
            strtotime( $obs_last )
        );
        echo 'Last updated: ' . esc_html( $formatted );
    } else {
        echo 'Last updated: not yet updated';
    }
    echo '</p>';

    echo '<p style="font-size:11px;color:#555;margin-top:8px;">';
    echo 'Gallery images are auto-updated from iNaturalist when this taxa post is viewed.';
    echo '</p>';

    // Clear buttons.
    $clear_gallery_nonce = wp_create_nonce( 'taxa_clear_gallery_' . $post->ID );
    $clear_gallery_url   = add_query_arg(
        array(
            'action'   => 'taxa_clear_gallery',
            'post_id'  => $post->ID,
            '_wpnonce' => $clear_gallery_nonce,
        ),
        admin_url( 'admin-post.php' )
    );

    $clear_obs_nonce = wp_create_nonce( 'taxa_clear_observation_gallery_' . $post->ID );
    $clear_obs_url   = add_query_arg(
        array(
            'action'   => 'taxa_clear_observation_gallery',
            'post_id'  => $post->ID,
            '_wpnonce' => $clear_obs_nonce,
        ),
        admin_url( 'admin-post.php' )
    );

    echo '<p style="margin-top:8px;">';
    echo '<a href="' . esc_url( $clear_gallery_url ) . '" class="button button-secondary button-small" style="margin-right:6px;">';
    echo esc_html__( 'Clear Taxon Gallery', 'taxonomy-api' );
    echo '</a>';

    echo '<a href="' . esc_url( $clear_obs_url ) . '" class="button button-secondary button-small">';
    echo esc_html__( 'Clear Observation Gallery', 'taxonomy-api' );
    echo '</a>';
    echo '</p>';

    echo '</div>';

}

/**
 * Register the Taxa FIFU / Featured Image meta box for taxa posts.
 */
function taxa_add_fifu_meta_box( $post ) {
    $taxa_id = get_post_meta( $post->ID, 'taxa', true );
    if ( ! $taxa_id ) {
        return;
    }

    add_meta_box(
        'taxa-fifu-meta',
        __( 'Featured Image from iNaturalist', 'taxonomy-api' ),
        'taxa_fifu_meta_box_render',
        'post',
        'side',
        'low'
    );
}
add_action( 'add_meta_boxes_post', 'taxa_add_fifu_meta_box' );

/**
 * Meta box: Manually refresh FIFU image from iNaturalist.
 *
 * - Shows current FIFU URL/alt
 * - Provides a button to fetch latest iNat images and set FIFU.
 */
function taxa_fifu_meta_box_render( $post ) {
    $taxa_id = get_post_meta( $post->ID, 'taxa', true );
    if ( ! $taxa_id ) {
        echo '<p><em>' . esc_html__( 'This control is only available for taxa posts.', 'taxonomy-api' ) . '</em></p>';
        return;
    }

    $fifu_url = get_post_meta( $post->ID, 'fifu_image_url', true );
    $fifu_alt = get_post_meta( $post->ID, 'fifu_image_alt', true );

    echo '<div class="taxa-meta-box">';

    echo '<p><strong>' . esc_html__( 'Current Featured Image (FIFU)', 'taxonomy-api' ) . '</strong></p>';

    if ( $fifu_url ) {
        echo '<p style="margin-bottom:8px;">';
        echo '<img src="' . esc_url( $fifu_url ) . '" alt="" style="max-width:100%;height:auto;display:block;margin-bottom:4px;border:1px solid #ddd;" />';
        if ( $fifu_alt ) {
            echo '<span style="font-size:11px;color:#555;">' . esc_html( $fifu_alt ) . '</span>';
        }
        echo '</p>';
    } else {
        echo '<p><em>' . esc_html__( 'No FIFU image is set yet.', 'taxonomy-api' ) . '</em></p>';
    }

    // Button to refresh from iNat.
    $nonce = wp_create_nonce( 'taxa_refresh_fifu_' . $post->ID );
    $url   = add_query_arg(
        array(
            'action'   => 'taxa_refresh_fifu',
            'post_id'  => $post->ID,
            '_wpnonce' => $nonce,
        ),
        admin_url( 'admin-post.php' )
    );

    echo '<p style="margin-top:8px;">';
    echo '<a href="' . esc_url( $url ) . '" class="button button-secondary button-small">';
    esc_html_e( 'Refresh from iNaturalist', 'taxonomy-api' );
    echo '</a>';
    echo '</p>';

    echo '<p class="description" style="margin-top:8px;">';
    esc_html_e( 'Fetches photos from the iNaturalist taxon API for this taxa and sets the featured image (FIFU) from the gallery.', 'taxonomy-api' );
    echo '</p>';

    echo '</div>';
}

/**
 * Register the Taxa Gallery Info meta box for taxa posts.
 */
function taxa_add_gallery_info_meta_box( $post ) {
    $taxa_id = get_post_meta( $post->ID, 'taxa', true );
    if ( ! $taxa_id ) {
        return;
    }

    add_meta_box(
        'taxa-gallery-info',
        __( 'Taxa Gallery Info', 'taxonomy-api' ),
        'taxa_gallery_info_meta_box_render',
        'post',
        'side',
        'low'
    );
}
add_action( 'add_meta_boxes_post', 'taxa_add_gallery_info_meta_box' );

/**
 * Handle clearing the taxon gallery meta so it repopulates on next view.
 */
function taxa_handle_clear_gallery() {
    if ( ! isset( $_GET['post_id'], $_GET['_wpnonce'] ) ) {
        wp_die( esc_html__( 'Missing parameters.', 'taxonomy-api' ) );
    }

    $post_id = absint( $_GET['post_id'] );

    if ( ! current_user_can( 'edit_post', $post_id ) ) {
        wp_die( esc_html__( 'You do not have permission to edit this post.', 'taxonomy-api' ) );
    }

    check_admin_referer( 'taxa_clear_gallery_' . $post_id );

    delete_post_meta( $post_id, 'gallery_photo' );
    delete_post_meta( $post_id, 'gallery_photo_dates' );

    $args = array(
        'post'                => $post_id,
        'action'              => 'edit',
        'taxa_gallery_cleared' => 1,
    );

    wp_redirect( add_query_arg( $args, admin_url( 'post.php' ) ) );
    exit;
}
add_action( 'admin_post_taxa_clear_gallery', 'taxa_handle_clear_gallery' );

/**
 * Handle clearing the observation gallery meta so it repopulates on next view.
 */
function taxa_handle_clear_observation_gallery() {
    if ( ! isset( $_GET['post_id'], $_GET['_wpnonce'] ) ) {
        wp_die( esc_html__( 'Missing parameters.', 'taxonomy-api' ) );
    }

    $post_id = absint( $_GET['post_id'] );

    if ( ! current_user_can( 'edit_post', $post_id ) ) {
        wp_die( esc_html__( 'You do not have permission to edit this post.', 'taxonomy-api' ) );
    }

    check_admin_referer( 'taxa_clear_observation_gallery_' . $post_id );

    delete_post_meta( $post_id, 'observation_gallery_photo' );
    delete_post_meta( $post_id, 'observation_gallery_photo_dates' );

    $args = array(
        'post'                              => $post_id,
        'action'                            => 'edit',
        'taxa_observation_gallery_cleared'  => 1,
    );

    wp_redirect( add_query_arg( $args, admin_url( 'post.php' ) ) );
    exit;
}
add_action( 'admin_post_taxa_clear_observation_gallery', 'taxa_handle_clear_observation_gallery' );

/**
 * Handle manual refresh of FIFU image from iNaturalist for a single taxa post.
 */
function taxa_handle_refresh_fifu() {
    if ( ! isset( $_GET['post_id'], $_GET['_wpnonce'] ) ) {
        wp_die( esc_html__( 'Missing parameters.', 'taxonomy-api' ) );
    }

    $post_id = absint( $_GET['post_id'] );

    if ( ! current_user_can( 'edit_post', $post_id ) ) {
        wp_die( esc_html__( 'You do not have permission to edit this post.', 'taxonomy-api' ) );
    }

    check_admin_referer( 'taxa_refresh_fifu_' . $post_id );

    $post = get_post( $post_id );
    if ( ! $post instanceof WP_Post ) {
        wp_die( esc_html__( 'Invalid post.', 'taxonomy-api' ) );
    }

    // 1) Refresh gallery images from iNaturalist for this taxa.
    update_gallery_images( $post );

    // 2) Force-refresh FIFU from the updated gallery.
    taxa_backfill_fifu_from_gallery( $post_id, true );

    $args = array(
        'post'                 => $post_id,
        'action'               => 'edit',
        'taxa_fifu_refreshed'  => 1,
    );

    wp_redirect( add_query_arg( $args, admin_url( 'post.php' ) ) );
    exit;
}
add_action( 'admin_post_taxa_refresh_fifu', 'taxa_handle_refresh_fifu' );

/**
 * Lazy-build facets row when a taxa post is viewed on the front end.
 *
 * On first view of a taxa post, if no row exists in the taxa_facets table
 * for this post_id, we:
 *  - Run GPT facet generation (writes facet_* meta), if enabled.
 *  - Build the compact facets row from that meta.
 */
function taxa_facets_lazy_build_for_viewed_post() {
    // Only run on the front-end main query for single posts.
    if ( ! is_singular( 'post' ) ) {
        return;
    }

    global $post, $wpdb;

    if ( ! $post instanceof WP_Post ) {
        return;
    }

    // Only for posts that have a taxa ID – i.e. your imported taxa posts.
    $taxa_id = get_post_meta( $post->ID, 'taxa', true );
    if ( ! $taxa_id ) {
        return;
    }

    $table = $wpdb->prefix . 'taxa_facets';

    // Make sure the facets table exists (avoid errors on fresh installs).
    $table_exists = $wpdb->get_var(
        $wpdb->prepare( "SHOW TABLES LIKE %s", $table )
    );

    if ( ! $table_exists ) {
        return;
    }

    // Does this post already have a compact facets row?
    $existing = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE post_id = %d",
            $post->ID
        )
    );

    if ( $existing > 0 ) {
        // Already built – nothing to do.
        return;
    }

    // Make sure importer helper exists.
    if ( ! function_exists( 'taxa_api_get_importer' ) ) {
        return;
    }

    $importer = taxa_api_get_importer();
    if ( ! $importer || ! method_exists( $importer, 'build_facets_for_post' ) ) {
        return;
    }

    /**
     * 1) Run GPT facet generation (writes facet_* meta) if the feature
     *    is enabled and the helper exists.
     *
     *    taxa_generate_facets_from_gpt() already checks gpt_enabled and
     *    bails safely if it's off.
     */
    if ( function_exists( 'taxa_generate_facets_from_gpt' ) ) {
        taxa_generate_facets_from_gpt( $post->ID );
    }

    /**
     * 2) Build the compact facet row from the facet_* meta.
     */
    $importer->build_facets_for_post( $post->ID );

    // Optional debug log:
    // error_log( '[FACETS][LAZY] GPT+facets built for post ' . $post->ID );
}
add_action( 'wp', 'taxa_facets_lazy_build_for_viewed_post' );
