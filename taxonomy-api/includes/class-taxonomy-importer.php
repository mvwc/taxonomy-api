<?php
/**
 * Taxonomy Importer - v2.0.3
 *
 * Handles iNaturalist taxa ingestion and child processing in small batches.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Taxonomy_Importer {

    /**
     * Base URL for iNaturalist taxonomy endpoint.
     *
     * @var string
     */
    protected $api_base = 'https://api.inaturalist.org/v1/taxa/';

    /**
     * Batch size for processing child taxa.
     *
     * @var int
     */
    protected $batch_size = 10;

    /**
     * Constructor.
     */
    public function __construct() {
        $this->batch_size = absint( get_option( 'taxa_cron_batch_size', 10 ) );
        if ( $this->batch_size < 1 ) {
            $this->batch_size = 10;
        }
    }

    /**
     * Initialize root taxa by ID (e.g., 1466321).
     *
     * @param string|int $primary_taxa_id
     * @return bool True on success, false on failure.
     */
    public function initialize_root_taxa( $primary_taxa_id ) {
        $primary_taxa_id = trim( (string) $primary_taxa_id );
        if ( '' === $primary_taxa_id ) {
            error_log( 'Taxonomy_Importer::initialize_root_taxa: No primary taxa ID provided.' );
            return false;
        }

        $response = $this->get_remote_data( $this->api_base . $primary_taxa_id );

        if ( empty( $response['results'] ) || ! is_array( $response['results'] ) ) {
            error_log( 'Taxonomy_Importer::initialize_root_taxa: No results for taxa ID ' . $primary_taxa_id );
            return false;
        }

        foreach ( $response['results'] as $taxa_data ) {
            $this->ingest_taxa( $taxa_data );
        }

        return true;
    }

    /**
     * Process pending child taxa in small batches.
     *
     * @return int Number of children processed.
     */
    public function process_pending_children() {
        global $wpdb;

        $processed_count = 0;
        $remaining       = $this->batch_size;

        // Find parent posts with unprocessed children.
        $results = $wpdb->get_results(
            "SELECT post_id
             FROM {$wpdb->postmeta}
             WHERE meta_key = 'children'
             AND meta_value LIKE '%\"processed\";b:0%'
             LIMIT 50"
        );

        if ( empty( $results ) ) {
            return 0;
        }

        foreach ( $results as $row ) {
            if ( $remaining <= 0 ) {
                break;
            }

            $parent_post_id = (int) $row->post_id;
            $children_data  = get_post_meta( $parent_post_id, 'children', true );

            if ( ! is_array( $children_data ) || empty( $children_data ) ) {
                continue;
            }

            foreach ( $children_data as &$child ) {
                if ( $remaining <= 0 ) {
                    break;
                }

                if ( ! isset( $child['id'] ) || ! array_key_exists( 'processed', $child ) || true === $child['processed'] ) {
                    continue;
                }

                $child_taxa_id = $child['id'];

                // If child post doesn't exist, fetch and ingest.
                $existing_child_post = $this->get_existing_post_id_by_taxa( $child_taxa_id );

                if ( ! $existing_child_post ) {
                    $child_response = $this->get_remote_data( $this->api_base . $child_taxa_id );

                    if ( ! empty( $child_response['results'] ) && is_array( $child_response['results'] ) ) {
                        foreach ( $child_response['results'] as $child_taxa_data ) {
                            $this->ingest_taxa( $child_taxa_data, $parent_post_id );
                            $processed_count++;
                            $remaining--;

                            if ( $remaining <= 0 ) {
                                break 2; // break out of both loops.
                            }
                        }
                    } else {
                        error_log( 'Taxonomy_Importer::process_pending_children: No data for child taxa ID ' . $child_taxa_id );
                        // Mark as processed to avoid hammering the API indefinitely.
                        $this->update_child_processed_status( $parent_post_id, $child_taxa_id );
                        $processed_count++;
                        $remaining--;
                    }
                } else {
                    // Post already exists; just mark as processed.
                    $this->update_child_processed_status( $parent_post_id, $child_taxa_id );
                    $processed_count++;
                    $remaining--;
                }
            }

            // Save updated children meta (in case we modified it).
            update_post_meta( $parent_post_id, 'children', $children_data );
        }

        return $processed_count;
    }

    /**
     * Ingest a single child taxa by ID, optionally marking it as processed for a given parent.
     *
     * @param string|int $taxa_id
     * @param int|null   $parent_post_id
     * @return bool True on success, false on failure.
     */
    public function ingest_child_by_id( $taxa_id, $parent_post_id = null ) {
        $taxa_id = trim( (string) $taxa_id );
        if ( '' === $taxa_id ) {
            return false;
        }

        // Fetch fresh data for this taxa, even if a post already exists (to allow updates).
        $response = $this->get_remote_data( $this->api_base . $taxa_id );

        if ( empty( $response['results'] ) || ! is_array( $response['results'] ) ) {
            error_log( 'Taxonomy_Importer::ingest_child_by_id: No data for taxa ID ' . $taxa_id );
            // Still mark as processed so we don’t hammer the API forever.
            if ( $parent_post_id ) {
                $this->update_child_processed_status( $parent_post_id, $taxa_id );
            }
            return false;
        }

        $processed_any = false;

        foreach ( $response['results'] as $taxa_data ) {
            $post_id = $this->ingest_taxa( $taxa_data, $parent_post_id );
            if ( $post_id ) {
                $processed_any = true;
            }
        }

        if ( $processed_any && $parent_post_id ) {
            $this->update_child_processed_status( $parent_post_id, $taxa_id );
        }

        return $processed_any;
    }

    /**
     * Fetch remote data and decode JSON as an array.
     *
     * @param string $url
     * @return array|null
     */
    protected function get_remote_data( $url ) {
        $response = wp_remote_get( $url, array(
            'timeout' => 20,
            'headers' => array(
                'Accept'     => 'application/json',
                'User-Agent' => 'TaxonomyImporter/2.0 (+WordPress)',
            ),
        ) );

        if ( is_wp_error( $response ) ) {
            error_log( 'Taxonomy_Importer::get_remote_data error: ' . $response->get_error_message() );
            return null;
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        $body = (string) wp_remote_retrieve_body( $response );

        if ( $code < 200 || $code >= 300 ) {
            error_log( 'Taxonomy_Importer::get_remote_data HTTP ' . $code . ' for URL: ' . $url . ' body=' . substr( trim( $body ), 0, 200 ) );
            return null;
        }

        if ( $body === '' ) {
            error_log( 'Taxonomy_Importer::get_remote_data empty body for URL: ' . $url );
            return null;
        }

        $data = json_decode( $body, true );

        if ( null === $data && JSON_ERROR_NONE !== json_last_error() ) {
            error_log(
                'Taxonomy_Importer::get_remote_data invalid JSON for URL: ' . $url .
                ' json_error=' . json_last_error_msg() .
                ' body=' . substr( trim( $body ), 0, 200 )
            );
            return null;
        }

        return $data;
    }


    /**
     * Ingest a single taxa record (create/update post + meta + children list).
     *
     * @param array     $taxa_data
     * @param int|null  $parent_post_id
     * @return int|false Post ID on success, false on failure.
     */
	public function ingest_taxa( $taxa_data, $parent_post_id = null ) {
		if ( empty( $taxa_data ) || ! isset( $taxa_data['id'] ) ) {
			return false;
		}

		$taxa_id = $taxa_data['id'];

		// Build children array with processed = false.
		$children_data = array();
		if ( ! empty( $taxa_data['children'] ) && is_array( $taxa_data['children'] ) ) {
			foreach ( $taxa_data['children'] as $child ) {
				if ( isset( $child['id'] ) ) {
					$children_data[] = array(
						'id'        => $child['id'],
						'processed' => false,
					);
				}
			}
		}

		$existing_post_id = $this->get_existing_post_id_by_taxa( $taxa_id );
		$new_post_id      = 0;

		$rank      = isset( $taxa_data['rank'] ) ? $taxa_data['rank'] : '';
		$parent_id = isset( $taxa_data['parent_id'] ) ? $taxa_data['parent_id'] : '';
		$ancestry  = isset( $taxa_data['ancestry'] ) ? $taxa_data['ancestry'] : '';

		$image_url = isset( $taxa_data['default_photo']['medium_url'] ) ? $taxa_data['default_photo']['medium_url'] : null;
		$name      = isset( $taxa_data['name'] ) ? $taxa_data['name'] : '';
		$common    = isset( $taxa_data['preferred_common_name'] ) ? $taxa_data['preferred_common_name'] : '';

		if ( $existing_post_id ) {
			// Update existing post meta only.
			update_post_meta( $existing_post_id, 'rank', $rank );
			$this->set_post_meta( $existing_post_id, $taxa_id, $parent_id, $ancestry, $children_data );

			if ( $image_url ) {
				$this->set_featured_image( $existing_post_id, $image_url );
			}

			$post_id = $existing_post_id;
		} else {
			// Create a new post.
			if ( $common ) {
				$post_title = $common . ' (' . $name . ')';
			} else {
				$post_title = '(' . $name . ')';
			}

			// Decode and sanitize the Wikipedia summary.
			$wiki_summary_raw = isset( $taxa_data['wikipedia_summary'] ) ? $taxa_data['wikipedia_summary'] : '';
			$wiki_summary     = '';
			if ( $wiki_summary_raw ) {
				$wiki_summary = wp_kses_post( html_entity_decode( $wiki_summary_raw ) );
			}

			$wikipedia_url = isset( $taxa_data['wikipedia_url'] ) ? $taxa_data['wikipedia_url'] : '';

			$post_content  = '<h2>' . esc_html( $post_title ) . '</h2>';
			$post_content .= '<ul>';
			$post_content .= '<li>Name: ' . esc_html( $name ) . '</li>';
			$post_content .= '<li>Rank: ' . esc_html( $rank ) . '</li>';
			$post_content .= '</ul>';

			if ( $wiki_summary ) {
				$post_content .= '<p>' . $wiki_summary . '</p>';
			}

			if ( $wikipedia_url ) {
				$post_content .= '<p><a href="' . esc_url( $wikipedia_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $wikipedia_url ) . '</a></p>';
			}

			$post_content .= '<br /><br />ID: ' . esc_html( $taxa_id );

			$new_post = array(
				'post_title'   => $post_title,
				'post_content' => $post_content,
				'post_type'    => 'post',
				'post_status'  => 'publish',
			);

			$new_post_id = wp_insert_post( $new_post, true );

			if ( is_wp_error( $new_post_id ) ) {
				error_log( 'Taxonomy_Importer::ingest_taxa: Error creating post for taxa ID ' . $taxa_id . ' - ' . $new_post_id->get_error_message() );
				return false;
			}

			update_post_meta( $new_post_id, 'rank', $rank );
			$this->set_post_meta( $new_post_id, $taxa_id, $parent_id, $ancestry, $children_data );

			if ( $image_url ) {
				$this->set_featured_image( $new_post_id, $image_url );
			}

			$post_id = $new_post_id;
		}

		// Save children array on this post.
		update_post_meta( $post_id, 'children', $children_data );

		/**
		* 1) Ensure facet_* meta exists (if GPT helper is available).
		*/
		if ( function_exists( 'taxa_generate_facets_from_gpt' ) ) {
			taxa_generate_facets_from_gpt( $post_id );
		}

		/**
		* 2) Build/rebuild the compact facet row in wp_taxa_facets
		*    (enums + bitmasks) from the facet_* meta.
		*/
		if ( method_exists( $this, 'build_facets_for_post' ) ) {
			$this->build_facets_for_post( $post_id );
		}

		// If this taxa is a child of another, mark it processed in the parent.
		if ( $parent_post_id ) {
			$this->update_child_processed_status( $parent_post_id, $taxa_id );
		}

		return $post_id;
	}

    /**
    * Fetch core fields for a taxa ID from iNaturalist.
    *
    * @param string|int $taxa_id
    * @return array|null [ 'taxa_rank' => 'species', 'extinct' => 0 ] or null on failure
    */
    public function fetch_inat_core_fields_by_taxa_id( $taxa_id ) {
        $taxa_id = trim( (string) $taxa_id );
        if ( '' === $taxa_id ) {
            return null;
        }

        $response = $this->get_remote_data( $this->api_base . $taxa_id );

        if ( null === $response ) {
            // quick retry (helps with transient iNat hiccups)
            usleep( 250000 ); // 250ms
            $response = $this->get_remote_data( $this->api_base . $taxa_id );
        }
        
        if ( empty( $response['results'] ) || ! is_array( $response['results'] ) ) {
            error_log( '[FACETS][RANK] iNat fetch: no results for taxa_id=' . $taxa_id );
            return null;
        }

        $t = $response['results'][0];

        $rank    = isset( $t['rank'] ) ? trim( (string) $t['rank'] ) : '';
        $extinct = null;

        // iNat commonly provides "extinct" as boolean in taxa payload (when present).
        if ( array_key_exists( 'extinct', $t ) ) {
            $extinct = ! empty( $t['extinct'] ) ? 1 : 0;
        }

        return array(
            'taxa_rank' => $rank,
            'extinct'   => ( null === $extinct ) ? null : (int) $extinct,
        );
    }


    /**
     * Get existing post ID by taxa meta.
     *
     * @param string|int $taxa_id
     * @return int|null
     */
    public function get_existing_post_id_by_taxa( $taxa_id ) {
        global $wpdb;

        $query = $wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'taxa' AND meta_value = %s LIMIT 1",
            $taxa_id
        );

        $post_id = $wpdb->get_var( $query );

        if ( $post_id ) {
            return (int) $post_id;
        }

        return null;
    }

    /**
     * Set core taxa-related post meta fields.
     *
     * @param int   $post_id
     * @param mixed $id
     * @param mixed $parent_id
     * @param mixed $ancestry
     * @param array $children
     */
    protected function set_post_meta( $post_id, $id, $parent_id, $ancestry, $children = array() ) {
        $meta_data = array(
            'taxa'      => $id,
            'parent_id' => $parent_id,
            'ancestry'  => $ancestry,
            'children'  => $children,
        );

        foreach ( $meta_data as $key => $value ) {
            update_post_meta( $post_id, $key, $value );
        }
    }

    /**
     * Set featured image using FIFU (external image), if available.
     *
     * @param int    $post_id
     * @param string $image_url
     */
    protected function set_featured_image( $post_id, $image_url ) {
        if ( function_exists( 'fifu_dev_set_image' ) ) {
            fifu_dev_set_image( $post_id, $image_url );
        }
    }

    /**
     * Update the processed status of a child entry in a parent post.
     *
     * @param int        $parent_post_id
     * @param string|int $child_id
     */
    public function update_child_processed_status( $parent_post_id, $child_id ) {
        $children_data = get_post_meta( $parent_post_id, 'children', true );

        if ( ! is_array( $children_data ) || empty( $children_data ) ) {
            return;
        }

        foreach ( $children_data as &$child ) {
            if ( isset( $child['id'] ) && (string) $child['id'] === (string) $child_id ) {
                $child['processed'] = true;
                break;
            }
        }

        update_post_meta( $parent_post_id, 'children', $children_data );
    }

/**
 * Build and store facet values for a given taxa post.
 *
 * This reads post meta (GPT-generated facet slugs), maps them to enums or
 * bitmasks defined in facets.php, and then writes to the taxa_facets table.
 *
 * @param int $post_id
 */
public function build_facets_for_post( $post_id ) {

    // Ensure helpers exist
    if ( ! function_exists( 'taxa_facets_update_row' ) ) {
        return;
    }

    $post_id = (int) $post_id;

    //
    // --- RAW META INPUTS (NEW SCHEMA) --------------------------------
    //
    // Single-value slugs (strings or empty).
    $size_slug            = get_post_meta( $post_id, 'facet_size', true );
    $shape_primary_slug   = get_post_meta( $post_id, 'facet_shape_primary', true );
    $shape_secondary_slug = get_post_meta( $post_id, 'facet_shape_secondary', true );
    $pattern_slug         = get_post_meta( $post_id, 'facet_pattern', true );
    $trait_primary_slug   = get_post_meta( $post_id, 'facet_trait_primary', true );
    $trait_secondary_slug = get_post_meta( $post_id, 'facet_trait_secondary', true );
    $diet_meta            = get_post_meta( $post_id, 'facet_diet', true );

    // Multi-value facets (may be array or comma-separated string).
    $color_meta     = get_post_meta( $post_id, 'facet_color', true );
    $call_type_meta = get_post_meta( $post_id, 'facet_call_type', true );
    $behavior_meta  = get_post_meta( $post_id, 'facet_behavior', true );
    $habitat_meta   = get_post_meta( $post_id, 'facet_habitat', true );

    //
    // --- BACKWARD COMPATIBILITY (OLD META KEYS) ----------------------
    // If the new keys are empty but old keys exist, fall back.
    //
    if ( $shape_primary_slug === '' ) {
        $shape_primary_slug = get_post_meta( $post_id, 'facet_wing_shape', true );
    }

    if ( $shape_secondary_slug === '' ) {
        $shape_secondary_slug = get_post_meta( $post_id, 'facet_tail_shape', true );
    }

    if ( $pattern_slug === '' ) {
        $pattern_slug = get_post_meta( $post_id, 'facet_call_pattern', true );
    }

    //
    // --- Normalizer for multi-value fields ---------------------------
    //
    $normalize_multi = function( $value ) {
        if ( is_array( $value ) ) {
            return array_values( array_filter( array_map( 'trim', $value ) ) );
        }
        $value = trim( (string) $value );
        if ( $value === '' ) {
            return array();
        }
        return array_values(
            array_filter(
                array_map( 'trim', explode( ',', $value ) )
            )
        );
    };

    // Convert to arrays for the bitmask builders.
    $color_values     = $normalize_multi( $color_meta );
    $call_type_values = $normalize_multi( $call_type_meta );
    $behavior_values  = $normalize_multi( $behavior_meta );
    $habitat_values   = $normalize_multi( $habitat_meta );

    //
    // --- Diet: treat as multi but store as ONE enum -------------------
    //
    $diet_slugs  = $normalize_multi( $diet_meta );          // e.g. ['insectivore']
    $diet_values = taxa_map_diet_params( $diet_slugs );     // [3] or similar
    $diet_enum   = ! empty( $diet_values ) ? reset( $diet_values ) : null;

    //
    // --- Single-value enums -------------------------------------------
    //
    $size_values = array();
    if ( $size_slug !== '' ) {
        $size_values = taxa_map_size_params( array( $size_slug ) );
    }

    $shape_primary_values = array();
    if ( $shape_primary_slug !== '' ) {
        $shape_primary_values = taxa_map_shape_primary_params( array( $shape_primary_slug ) );
    }

    $shape_secondary_values = array();
    if ( $shape_secondary_slug !== '' ) {
        $shape_secondary_values = taxa_map_shape_secondary_params( array( $shape_secondary_slug ) );
    }

    $pattern_values = array();
    if ( $pattern_slug !== '' ) {
        $pattern_values = taxa_map_pattern_params( array( $pattern_slug ) );
    }

    $trait_primary_values = array();
    if ( $trait_primary_slug !== '' ) {
        $trait_primary_values = taxa_map_trait_primary_params( array( $trait_primary_slug ) );
    }

    $trait_secondary_values = array();
    if ( $trait_secondary_slug !== '' ) {
        $trait_secondary_values = taxa_map_trait_secondary_params( array( $trait_secondary_slug ) );
    }

    $size_enum            = ! empty( $size_values )            ? reset( $size_values )            : null;
    $shape_primary_enum   = ! empty( $shape_primary_values )   ? reset( $shape_primary_values )   : null;
    $shape_secondary_enum = ! empty( $shape_secondary_values ) ? reset( $shape_secondary_values ) : null;
    $pattern_enum         = ! empty( $pattern_values )         ? reset( $pattern_values )         : null;
    $trait_primary_enum   = ! empty( $trait_primary_values )   ? reset( $trait_primary_values )   : null;
    $trait_secondary_enum = ! empty( $trait_secondary_values ) ? reset( $trait_secondary_values ) : null;

    //
    // --- Build bitmasks -----------------------------------------------
    //
    $color_mask     = taxa_build_color_mask( $color_values );
    $call_type_mask = taxa_build_call_type_mask( $call_type_values );
    $behavior_mask  = taxa_build_behavior_mask( $behavior_values );
    $habitat_mask   = taxa_build_habitat_mask( $habitat_values );

    //
    // --- Build final facet payload ------------------------------------
    //
    $facets = array(
        'size'            => $size_enum,
        'shape_primary'   => $shape_primary_enum,
        'shape_secondary' => $shape_secondary_enum,
        'pattern'         => $pattern_enum,
        'trait_primary'   => $trait_primary_enum,
        'trait_secondary' => $trait_secondary_enum,
        'diet'            => $diet_enum,
        'color_mask'      => $color_mask,
        'call_type_mask'  => $call_type_mask,
        'behavior_mask'   => $behavior_mask,
        'habitat_mask'    => $habitat_mask,
        'family_id'       => null, // still placeholders for now
        'region_id'       => null,
    );

    // Optional detailed logging.
    error_log( '[FACETS][DEBUG] Raw facet meta for post ' . $post_id . ': ' . print_r( array(
        'size_slug'            => $size_slug,
        'shape_primary_slug'   => $shape_primary_slug,
        'shape_secondary_slug' => $shape_secondary_slug,
        'pattern_slug'         => $pattern_slug,
        'trait_primary_slug'   => $trait_primary_slug,
        'trait_secondary_slug' => $trait_secondary_slug,
        'diet_meta'            => $diet_meta,
        'color_values'         => $color_values,
        'call_type_values'     => $call_type_values,
        'behavior_values'      => $behavior_values,
        'habitat_values'       => $habitat_values,
    ), true ) );

    error_log( '[FACETS][DEBUG] Final facet payload for post ' . $post_id . ': ' . print_r( $facets, true ) );

    //
    // --- Save to DB ----------------------------------------------------
    //
    taxa_facets_update_row( $post_id, $facets );
}






}
