<?php

/**
 * Plugin Name:       Example Plugin
 * Description:       A test plugin demonstrating UUPD_Updater integration.
 * Tested up to:      6.8.2
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Version:           1.0.7
 * Author:            Nathan Foley
 * Author URI:        https://reallyusefulplugins.com
 * License:           GPL2
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       example-plugin
 * Website:           https://reallyusefulplugins.com
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


// ===========================================================================
// Constants
// ===========================================================================
define( 'RUP_EXAMPLE_VERSION', '1.0' );
define( 'RUP_EXAMPLE_MAIN_FILE', __FILE__ );
define( 'RUP_EXAMPLE_ITEM_NUMBER', 7 );
define( 'RUP_EXAMPLE_FRIENDLY_NAME', 'Example Plugin');
define( 'RUP_EXAMPLE_SLUG', 'example-plugin');

// Base server for your UUPD + license manager:
define( 'RUP_EXAMPLE_SERVER_BASE', 'https://hoster.wptv.uk' );

// ===========================================================================
// Core includes
// ===========================================================================
add_action( 'plugins_loaded', function() {

    require_once __DIR__ . '/inc/updater.php';
    require_once __DIR__ . '/inc/class-hoster-uupd-license-ui.php';

    $slug      = RUP_EXAMPLE_SLUG;
    $item_id   = RUP_EXAMPLE_ITEM_NUMBER;
    $server    = RUP_EXAMPLE_SERVER_BASE;
    $friendly  = RUP_EXAMPLE_FRIENDLY_NAME;
    
    // Build common strings
    $menu_slug = $slug . '-license';
    $page_title = $friendly . ' License';
    $menu_title = $friendly . ' License';

   // ===================================================================
    // 1) Register License UI first
    // ===================================================================
    \UUPD\V1\UUPD_License_UI::register( [
        'slug'           => $slug,
        'item_id'        => $item_id,
        'license_server' => $server,
        'metadata_base'  => $server . '/wp-content/plugins/hoster/inc/secure-download.php',    
        'cache_prefix'   => 'upd_',
        

        'plugin_name'    => $friendly,
        'menu_parent'    => 'options-general.php', // Set to False // or '' if you prefer
        'menu_slug'      => $menu_slug, 
        'page_title'     => $page_title,
        'menu_title'     => $menu_title,
        'capability'     => 'manage_options',
        'pass_token'     => true,
        'token_param'    => 'token',
    ] );

    // ===================================================================
    // 2) Register the updater AFTER license UI exists
    // ===================================================================
    $updater_config = [
        'plugin_file' => plugin_basename( RUP_EXAMPLE_MAIN_FILE ),
        'slug'        => $slug,
        'name'        => $friendly,
        'version'     => RUP_EXAMPLE_VERSION,
        'server'      => '', // built by filter from License UI
    ];

    \UUPD\V1\UUPD_Updater_V1::register( $updater_config );
}, 1 );

// 1. Add the settings page to the admin menu
add_action('admin_menu', 'esp_add_settings_page');
function esp_add_settings_page() {
    add_options_page(
        'Example Setting Page',     // Page title
        'Example Setting Page',     // Menu title
        'manage_options',           // Capability
        'esp-example-setting-page', // Menu slug
        'esp_render_settings_page'  // Callback
    );
}

// 2. Register the setting
add_action('admin_init', 'esp_register_settings');
function esp_register_settings() {
    register_setting(
        'esp_settings_group', // Option group
        'esp_text_option'     // Option name (stored in wp_options)
    );
}

// 3. Render the settings page
function esp_render_settings_page() {
    // Check capability
    if (!current_user_can('manage_options')) {
        return;
    }
    ?>

    <div class="wrap">
        <h1>Example Setting Page</h1>

        <?php \UUPD\V1\UUPD_License_UI::render_box_for( RUP_EXAMPLE_SLUG ); ?>


        <form method="post" action="options.php">
            <?php
            // Output security fields for the registered setting
            settings_fields('esp_settings_group');

            // If you had sections, you'd call do_settings_sections() here
            // do_settings_sections('esp-example-setting-page');

            // Get existing value
            $text_value = get_option('esp_text_option', '');
            ?>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">
                        <label for="esp_text_option">Example Text Field</label>
                    </th>
                    <td>
                        <input
                            type="text"
                            id="esp_text_option"
                            name="esp_text_option"
                            value="<?php echo esc_attr($text_value); ?>"
                            class="regular-text"
                        />
                        <p class="description">Enter any text you like.</p>
                    </td>
                </tr>
            </table>

            <?php submit_button(); ?>
        </form>
    </div>

    <?php
}
