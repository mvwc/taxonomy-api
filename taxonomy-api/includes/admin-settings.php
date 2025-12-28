<?php
/**
 * Admin menu & settings page for Taxa Plugin
 * Version: 2.0.3
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function cnbSetupMenu() {
    add_menu_page(
        'Taxonomy Importer',
        'Taxonomy API',
        'manage_options',
        'taxa-nav',
        'taxaRender',
        'dashicons-networking',
        102
    );

    add_submenu_page(
        'taxa-nav',
        'Import Taxa API',
        'Import Taxa API',
        'manage_options',
        'taxa',
        'testInit'
    );
    add_submenu_page(
        'taxa-nav',
        'Update Children',
        'Update Children',
        'manage_options',
        'updatechildren',
        'updateChildren'
    );
    add_submenu_page(
        'taxa-nav',
        'Settings',
        'Settings',
        'manage_options',
        'taxa_settings',
        'taxaSettingsPage'
    );
}
add_action( 'admin_menu', 'cnbSetupMenu' );

function taxaRender() { ?>
    <div class="wrap">
        <h1>Taxonomy API Dashboard</h1>
        <p>Manage imports, children processing, and settings for your taxonomy ingestion.</p>

        <ul>
            <li><a href="<?php echo esc_url( admin_url( 'admin.php?page=taxa' ) ); ?>">Initialize / Import Root Taxa</a></li>
            <li><a href="<?php echo esc_url( admin_url( 'admin.php?page=updatechildren' ) ); ?>">Update Children Meta</a></li>
            <li><a href="<?php echo esc_url( admin_url( 'admin.php?page=taxa_settings' ) ); ?>">Settings</a></li>
        </ul>
    </div>
<?php
}

/**
 * Main settings page
 */
function taxaSettingsPage() {

    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    // Handle save.
    if ( isset( $_POST['save_taxa_settings'] ) ) {
        check_admin_referer( 'taxa_settings_save', 'taxa_settings_nonce' );

        // General.
        $site_focus_keyword      = isset( $_POST['site_focus_keyword'] ) ? sanitize_text_field( wp_unslash( $_POST['site_focus_keyword'] ) ) : '';
        $site_focus_keyword_slug = isset( $_POST['site_focus_keyword_slug'] ) ? sanitize_text_field( wp_unslash( $_POST['site_focus_keyword_slug'] ) ) : '';
        $primary_taxa_id         = isset( $_POST['primary_taxa_id'] ) ? sanitize_text_field( wp_unslash( $_POST['primary_taxa_id'] ) ) : '';

        update_option( 'site_focus_keyword', $site_focus_keyword );
        update_option( 'site_focus_keyword_slug', $site_focus_keyword_slug );
        update_option( 'primary_taxa_id', $primary_taxa_id );

        // Cron / import options.
        $taxa_cron_frequency = isset( $_POST['taxa_cron_frequency'] ) ? sanitize_text_field( wp_unslash( $_POST['taxa_cron_frequency'] ) ) : 'manual';
        $allowed_frequencies = array( 'manual', 'hourly', 'twicedaily', 'daily' );
        if ( ! in_array( $taxa_cron_frequency, $allowed_frequencies, true ) ) {
            $taxa_cron_frequency = 'manual';
        }

        $taxa_cron_batch_size = isset( $_POST['taxa_cron_batch_size'] ) ? absint( $_POST['taxa_cron_batch_size'] ) : 10;
        if ( $taxa_cron_batch_size < 1 ) {
            $taxa_cron_batch_size = 10;
        }

        update_option( 'taxa_cron_frequency', $taxa_cron_frequency );
        update_option( 'taxa_cron_batch_size', $taxa_cron_batch_size );

        // GPT core options.
        $gpt_enabled = isset( $_POST['gpt_enabled'] ) ? '1' : '0';
        update_option( 'gpt_enabled', $gpt_enabled );

        $gpt_api_key = isset( $_POST['gpt_api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['gpt_api_key'] ) ) : '';
        update_option( 'gpt_api_key', $gpt_api_key );

        $gpt_prompt = isset( $_POST['gpt_prompt'] )
            ? sanitize_textarea_field( wp_unslash( $_POST['gpt_prompt'] ) )
            : '';
        update_option( 'gpt_prompt', $gpt_prompt );

        // GPT sub-prompts.
        $physical_prompt         = isset( $_POST['physical_prompt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['physical_prompt'] ) ) : '';
        $behavioral_prompt       = isset( $_POST['behavioral_prompt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['behavioral_prompt'] ) ) : '';
        $regional_prompt         = isset( $_POST['regional_prompt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['regional_prompt'] ) ) : '';
        $social_prompt           = isset( $_POST['social_prompt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['social_prompt'] ) ) : '';
        $id_tips_prompt          = isset( $_POST['id_tips_prompt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['id_tips_prompt'] ) ) : '';
        $additional_notes_prompt = isset( $_POST['additional_notes_prompt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['additional_notes_prompt'] ) ) : '';

        update_option( 'physical_prompt', $physical_prompt );
        update_option( 'behavioral_prompt', $behavioral_prompt );
        update_option( 'regional_prompt', $regional_prompt );
        update_option( 'social_prompt', $social_prompt );
        update_option( 'id_tips_prompt', $id_tips_prompt );
        update_option( 'additional_notes_prompt', $additional_notes_prompt );

        // Custom key/value pairs.
        $custom_keys           = isset( $_POST['custom_keys'] ) ? (array) $_POST['custom_keys'] : array();
        $custom_friendly_names = isset( $_POST['custom_friendly_names'] ) ? (array) $_POST['custom_friendly_names'] : array();
        $custom_values         = isset( $_POST['custom_values'] ) ? (array) $_POST['custom_values'] : array();

        $custom_key_value_pairs = array();

        foreach ( $custom_keys as $index => $key ) {
            $key = sanitize_text_field( wp_unslash( $key ) );
            if ( '' === $key ) {
                continue;
            }

            $friendly = isset( $custom_friendly_names[ $index ] ) ? sanitize_text_field( wp_unslash( $custom_friendly_names[ $index ] ) ) : '';
            $value    = isset( $custom_values[ $index ] ) ? sanitize_text_field( wp_unslash( $custom_values[ $index ] ) ) : '';

            $custom_key_value_pairs[ $key ] = array(
                'friendly_name' => $friendly,
                'value'         => $value,
            );
        }

        update_option( 'custom_key_value_pairs', $custom_key_value_pairs );

        echo '<div class="notice notice-success is-dismissible"><p>Taxa settings saved.</p></div>';
    }

    // Load current values.
    $site_focus_keyword      = get_option( 'site_focus_keyword', '' );
    $site_focus_keyword_slug = get_option( 'site_focus_keyword_slug', '' );
    $primary_taxa_id         = get_option( 'primary_taxa_id', '' );

    $taxa_cron_frequency = get_option( 'taxa_cron_frequency', 'manual' );
    $taxa_cron_batch_size = absint( get_option( 'taxa_cron_batch_size', 10 ) );

    $gpt_enabled = get_option( 'gpt_enabled', '0' );
    $gpt_api_key = get_option( 'gpt_api_key', '' );

    $gpt_prompt = get_option(
        'gpt_prompt',
        'Write an engaging and informative article about the taxa ($post_title). Include insights, facts, and stories about their behavior, habitat, and ecological importance. Use a friendly tone, making it accessible and enjoyable. Include scientific terms and a legend of definitions if necessary. Title should have the common name and scientific name. Structure the article with the following sections: Overview, Etymology, Physical Characteristics, Identifiable Traits, Ecological Significance, Location/Region, Social Behavior, Nesting Practices, Natural Predators, Conservation Status, Human Impact, Interesting Facts, References. Highlight keywords in the Identifiable Traits section using <strong> tags. Use HTML formatting for WordPress. The article should be at least 800 words and include hyperlinks to scientific studies. Start with the keyword: $post_title.'
    );

    $physical_prompt         = get_option( 'physical_prompt', 'Respond with the physical characteristics of $post_title' );
    $behavioral_prompt       = get_option( 'behavioral_prompt', 'Respond with the behavioral traits of $post_title' );
    $regional_prompt         = get_option( 'regional_prompt', 'Respond with the regional habitat of $post_title' );
    $social_prompt           = get_option( 'social_prompt', 'Respond with the social attributes of $post_title' );
    $id_tips_prompt          = get_option( 'id_tips_prompt', 'Respond with the Identification Tips for $post_title' );
    $additional_notes_prompt = get_option( 'additional_notes_prompt', 'Respond with the items of interest about $post_title' );

    $custom_key_value_pairs = get_option( 'custom_key_value_pairs', array() );
    ?>
    <div class="wrap taxa-settings-wrap">
        <h1>Taxonomy API Settings</h1>
        <p>Configure root taxa, cron behavior, and optional AI-powered content enrichment.</p>

        <h2 class="nav-tab-wrapper">
            <a href="#taxa-tab-general" class="nav-tab nav-tab-active">General</a>
            <a href="#taxa-tab-import" class="nav-tab">Import &amp; Cron</a>
            <a href="#taxa-tab-ai" class="nav-tab">AI Content</a>
            <a href="#taxa-tab-custom" class="nav-tab">Custom Fields</a>
        </h2>

        <form method="post" action="">
            <?php wp_nonce_field( 'taxa_settings_save', 'taxa_settings_nonce' ); ?>

            <!-- General Tab -->
            <div id="taxa-tab-general" class="taxa-tab-panel taxa-tab-panel-active">
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row"><label for="site_focus_keyword">Site Focus Keyword</label></th>
                        <td>
                            <input type="text" id="site_focus_keyword" name="site_focus_keyword" value="<?php echo esc_attr( $site_focus_keyword ); ?>" class="regular-text" />
                            <p class="description">Main subject focus of this site (e.g. “Native Bees”, “Birds”). Used in AI prompts.</p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><label for="site_focus_keyword_slug">Site Focus Keyword Slug</label></th>
                        <td>
                            <input type="text" id="site_focus_keyword_slug" name="site_focus_keyword_slug" value="<?php echo esc_attr( $site_focus_keyword_slug ); ?>" class="regular-text" />
                            <p class="description">Slug or short handle for the focus keyword (optional).</p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><label for="primary_taxa_id">Primary Taxa ID</label></th>
                        <td>
                            <input type="text" id="primary_taxa_id" name="primary_taxa_id" value="<?php echo esc_attr( $primary_taxa_id ); ?>" class="regular-text" />
                            <p class="description">Root iNaturalist taxa ID (e.g. <code>1466321</code>). This will be the starting point for ingestion.</p>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- Import & Cron Tab -->
            <div id="taxa-tab-import" class="taxa-tab-panel">
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row"><label for="taxa_cron_frequency">Cron Frequency</label></th>
                        <td>
                            <select name="taxa_cron_frequency" id="taxa_cron_frequency">
                                <option value="manual" <?php selected( $taxa_cron_frequency, 'manual' ); ?>>Manual only (no scheduled cron)</option>
                                <option value="hourly" <?php selected( $taxa_cron_frequency, 'hourly' ); ?>>Hourly</option>
                                <option value="twicedaily" <?php selected( $taxa_cron_frequency, 'twicedaily' ); ?>>Twice Daily</option>
                                <option value="daily" <?php selected( $taxa_cron_frequency, 'daily' ); ?>>Daily</option>
                            </select>
                            <p class="description">Controls how often the plugin scans for incomplete child taxa and ingests them.</p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><label for="taxa_cron_batch_size">Batch Size per Run</label></th>
                        <td>
                            <input type="number" id="taxa_cron_batch_size" name="taxa_cron_batch_size" value="<?php echo esc_attr( $taxa_cron_batch_size ); ?>" min="1" step="1" />
                            <p class="description">Maximum number of child taxa to ingest per cron run. Smaller values keep the plugin lightweight.</p>
                        </td>
                    </tr>
                </table>
                <p class="description">
                    <strong>Note:</strong> The importer uses these settings to process only incomplete children and avoid heavy one-off recursion.
                </p>
            </div>

            <!-- AI Content Tab -->
            <div id="taxa-tab-ai" class="taxa-tab-panel">
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row"><label for="gpt_api_key">GPT API Key</label></th>
                        <td>
                            <input type="password" id="gpt_api_key" name="gpt_api_key" value="<?php echo esc_attr( $gpt_api_key ); ?>" class="regular-text" autocomplete="off" />
                            <p class="description">OpenAI API key. This is used for AI-generated content fields.</p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Enable GPT</th>
                        <td>
                            <label>
                                <input type="checkbox" name="gpt_enabled" <?php checked( '1', $gpt_enabled ); ?> />
                                Enable AI-generated content for taxa posts
                            </label>
                            <p class="description">When disabled, no API calls will be made and your site will run as a plain importer.</p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><label for="gpt_prompt">Main Article Prompt</label></th>
                        <td>
                            <textarea name="gpt_prompt" id="gpt_prompt" rows="8" cols="50" class="large-text"><?php echo esc_textarea( $gpt_prompt ); ?></textarea>
                            <p class="description">Used for the main article body. You can use <code>$post_title</code> as a placeholder.</p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Field Prompts</th>
                        <td>
                            <p class="description">These prompts power smaller meta fields, such as identification tips or physical traits.</p>

                            <label for="physical_prompt"><strong>Physical Attributes</strong></label><br />
                            <textarea name="physical_prompt" id="physical_prompt" rows="2" cols="50" class="large-text"><?php echo esc_textarea( $physical_prompt ); ?></textarea><br /><br />

                            <label for="behavioral_prompt"><strong>Behavioral Traits</strong></label><br />
                            <textarea name="behavioral_prompt" id="behavioral_prompt" rows="2" cols="50" class="large-text"><?php echo esc_textarea( $behavioral_prompt ); ?></textarea><br /><br />

                            <label for="regional_prompt"><strong>Regional Habitat</strong></label><br />
                            <textarea name="regional_prompt" id="regional_prompt" rows="2" cols="50" class="large-text"><?php echo esc_textarea( $regional_prompt ); ?></textarea><br /><br />

                            <label for="social_prompt"><strong>Social Attributes</strong></label><br />
                            <textarea name="social_prompt" id="social_prompt" rows="2" cols="50" class="large-text"><?php echo esc_textarea( $social_prompt ); ?></textarea><br /><br />

                            <label for="id_tips_prompt"><strong>Identification Tips</strong></label><br />
                            <textarea name="id_tips_prompt" id="id_tips_prompt" rows="2" cols="50" class="large-text"><?php echo esc_textarea( $id_tips_prompt ); ?></textarea><br /><br />

                            <label for="additional_notes_prompt"><strong>Additional Notes</strong></label><br />
                            <textarea name="additional_notes_prompt" id="additional_notes_prompt" rows="2" cols="50" class="large-text"><?php echo esc_textarea( $additional_notes_prompt ); ?></textarea>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- Custom Fields Tab -->
            <div id="taxa-tab-custom" class="taxa-tab-panel">
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row">Custom Key/Value Pairs</th>
                        <td>
                            <p class="description">
                                Define additional meta fields that can be populated from GPT.
                                Each key maps to a stored meta key on the post.
                            </p>

                            <div id="custom_key_value_pairs">
                                <?php
                                if ( ! empty( $custom_key_value_pairs ) ) :
                                    foreach ( $custom_key_value_pairs as $key => $pair ) :
                                        ?>
                                        <div class="custom_pair">
                                            <input type="text" name="custom_keys[]" value="<?php echo esc_attr( $key ); ?>" placeholder="Meta Key (e.g. behavior_tags)" />
                                            <input type="text" name="custom_friendly_names[]" value="<?php echo esc_attr( $pair['friendly_name'] ); ?>" placeholder="Friendly Name" />
                                            <input type="text" name="custom_values[]" value="<?php echo esc_attr( $pair['value'] ); ?>" placeholder="Prompt Template" class="large-text" />
                                            <button type="button" class="button remove_pair">Remove</button>
                                        </div>
                                        <?php
                                    endforeach;
                                endif;
                                ?>
                            </div>

                            <p>
                                <button type="button" class="button" id="add_pair">Add Pair</button>
                            </p>
                        </td>
                    </tr>
                </table>
            </div>

            <?php submit_button( 'Save Changes', 'primary', 'save_taxa_settings' ); ?>
        </form>
    </div>

    <style>
        .taxa-settings-wrap .taxa-tab-panel {
            display: none;
            margin-top: 20px;
        }
        .taxa-settings-wrap .taxa-tab-panel-active {
            display: block;
        }
        .taxa-settings-wrap .custom_pair {
            margin-bottom: 10px;
        }
        .taxa-settings-wrap .custom_pair input[type="text"] {
            margin-right: 5px;
        }
        .taxa-settings-wrap .nav-tab-wrapper {
            margin-bottom: 0;
        }
    </style>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Tab switching
            const tabs = document.querySelectorAll('.nav-tab-wrapper .nav-tab');
            const panels = document.querySelectorAll('.taxa-tab-panel');

            tabs.forEach(tab => {
                tab.addEventListener('click', function(e) {
                    e.preventDefault();
                    const target = this.getAttribute('href');

                    tabs.forEach(t => t.classList.remove('nav-tab-active'));
                    this.classList.add('nav-tab-active');

                    panels.forEach(panel => {
                        panel.classList.remove('taxa-tab-panel-active');
                    });

                    const activePanel = document.querySelector(target);
                    if (activePanel) {
                        activePanel.classList.add('taxa-tab-panel-active');
                    }
                });
            });

            // Custom key/value dynamic rows
            const container = document.getElementById('custom_key_value_pairs');
            const addButton = document.getElementById('add_pair');

            if (addButton) {
                addButton.addEventListener('click', function() {
                    const div = document.createElement('div');
                    div.classList.add('custom_pair');
                    div.innerHTML = ''
                        + '<input type="text" name="custom_keys[]" placeholder="Meta Key (e.g. behavior_tags)" /> '
                        + '<input type="text" name="custom_friendly_names[]" placeholder="Friendly Name" /> '
                        + '<input type="text" name="custom_values[]" placeholder="Prompt Template" class="large-text" /> '
                        + '<button type="button" class="button remove_pair">Remove</button>';

                    container.appendChild(div);

                    const removeBtn = div.querySelector('.remove_pair');
                    removeBtn.addEventListener('click', function() {
                        div.remove();
                    });
                });
            }

            container.querySelectorAll('.remove_pair').forEach(function(button) {
                button.addEventListener('click', function() {
                    button.parentElement.remove();
                });
            });
        });
    </script>
    <?php
}
