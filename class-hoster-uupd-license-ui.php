<?php
/**
 * UUPD_License_UI – Reusable license activation/deactivation UI for WordPress plugins/themes,
 * integrated with the Hoster licensing system and UUPD-based plugin updates.
 *
 * This class provides:
 *
 * - An optional admin “License” page with activation/deactivation controls.
 * - A compact **inline license box** for embedding inside your own settings screens.
 * - Persistent license storage inside wp_options (`uupd_license_{slug}`).
 * - Automatic integration with the UUPD updater (server URL rewriting + cache flushing).
 * - Optional “please activate your license” admin notice.
 * - Live (on-demand) license check methods and cron-friendly scheduled checks.
 *
 * --------------------------------------------------------------------------
 *  HOSTER API INTEGRATION
 * --------------------------------------------------------------------------
 *
 * This class uses the following fixed Hoster API endpoints:
 *
 *   POST /wp-json/hoster/v1/hoster-activate-license
 *   POST /wp-json/hoster/v1/hoster-deactivate-license
 *   POST /wp-json/hoster/v1/hoster-check-license
 *
 * All requests send:
 *   {
 *     "download_id": <item_id>,
 *     "license_key": "...",
 *     "site_url":   "https://example.com"
 *   }
 *
 * Activation responses return:
 *   - success / message
 *   - used_activations / remaining_activations
 *   - token  ← used for authenticated update downloads
 *
 * Before each activation call, this class automatically performs a
 * **pre-deactivation** request to avoid Hoster’s “duplicate activation” errors.
 *
 *
 * --------------------------------------------------------------------------
 *  UPDATER INTEGRATION (UUPD)
 * --------------------------------------------------------------------------
 *
 * When the UUPD updater requests the metadata URL, this class rewrites it into:
 *
 *   {metadata_base}?file=json&download={item_id}&token={token}
 *
 * Example:
 *   https://hoster.example.com/wp-content/plugins/hoster/inc/secure-download.php
 *      ?file=json
 *      &download=7
 *      &token=32cae3efb341...
 *
 * The token is stored in the license option and automatically refreshed upon
 * activation. All updater-related transients and caches are cleared whenever
 * the license is activated, deactivated, or changed.
 *
 *
 * --------------------------------------------------------------------------
 *  USAGE A – LICENSE UI ONLY (NO UPDATER)
 * --------------------------------------------------------------------------
 *
 *   use UUPD\V1\UUPD_License_UI;
 *
 *   UUPD_License_UI::register( [
 *       'slug'           => 'example-plugin',
 *       'item_id'        => 7,
 *       'license_server' => 'https://hoster.example.com',
 *       'metadata_base'  => 'https://hoster.example.com/wp-content/plugins/hoster/inc/secure-download.php',
 *
 *       // Optional UI configuration:
 *       'plugin_name'    => 'Example Plugin',
 *       'menu_parent'    => 'options-general.php', // or false to disable the menu
 *       'menu_slug'      => 'example-plugin-license',
 *       'page_title'     => 'Example Plugin License',
 *       'menu_title'     => 'License',
 *   ] );
 *
 * You may also embed the inline license box:
 *
 *   \UUPD\V1\UUPD_License_UI::render_box_for( 'example-plugin' );
 *
 *
 * --------------------------------------------------------------------------
 *  USAGE B – LICENSE UI + UUPD UPDATER
 * --------------------------------------------------------------------------
 *
 *   use UUPD\V1\UUPD_License_UI;
 *   use UUPD\V1\UUPD_Updater_V1;
 *
 *   UUPD_License_UI::register( [
 *       'slug'           => 'example-plugin',
 *       'item_id'        => 7,
 *       'license_server' => 'https://hoster.example.com',
 *       'metadata_base'  => 'https://hoster.example.com/wp-content/plugins/hoster/inc/secure-download.php',
 *       'plugin_name'    => 'Example Plugin',
 *       'cache_prefix'   => 'upd_',
 *   ] );
 *
 *   UUPD_Updater_V1::register( [
 *       'plugin_file' => plugin_basename( __FILE__ ),
 *       'slug'        => 'example-plugin',
 *       'name'        => 'Example Plugin',
 *       'version'     => '1.0.0',
 *       'server'      => '', // rewritten dynamically using the stored license token
 *   ] );
 *
 *
 * --------------------------------------------------------------------------
 *  ONCE REGISTERED, THIS CLASS WILL:
 * --------------------------------------------------------------------------
 *
 * - Add a License admin page (unless disabled).
 * - Store license details and token in wp_options.
 * - Call Hoster APIs to activate, deactivate, and check licenses.
 * - Automatically de-activate before each activation for safety.
 * - Rewrite the UUPD update metadata URL using secure-download.php + token.
 * - Flush UUPD caches/transients when a license changes.
 * - Display an inline license box for embedding in settings pages.
 * - Support scheduled/cron license checks.
 *
 *
 * @package UUPD\V1
 */






namespace UUPD\V1;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( __NAMESPACE__ . '\\UUPD_License_UI' ) ) {

    class UUPD_License_UI {

        const VERSION = '1.0.0'; // Change as needed

        /** @var array<string,self> */
        protected static $instances = [];

        /** @var bool */
        protected static $inline_box_css_printed = false;

        /** @var bool */
        protected static $inline_box_js_printed = false;

        /** @var array */
        protected $config;

        /**
         * Register a license UI + updater integration instance for a plugin or theme,
         * using the Hoster licensing API.
         *
         * This sets up:
         *  - Admin license page (activate / deactivate / check)
         *  - Storage of license status and activation token
         *  - Automatic updater integration via secure-download.php
         *
         * -------------------------------------------------------------------------
         * REQUIRED CONFIG
         * -------------------------------------------------------------------------
         *
         * $config = [
         *   // Unique slug for your plugin/theme.
         *   // Must match the slug used by the updater.
         *   'slug'           => 'example-plugin',
         *
         *   // The Hoster product post ID (the download_id).
         *   'item_id'        => 7,
         *
         *   // Base site URL of the Hoster server.
         *   // Do NOT include endpoint paths — these are fixed internally.
         *   // Example: 'https://hoster.wptv.uk'
         *   'license_server' => 'https://example.com',
         *
         *   // Base path to secure-download.php (no query parameters).
         *   // The updater will automatically append file=json, download={id}, &token={token}
         *   // Example: 'https://example.com/wp-content/plugins/hoster/inc/secure-download.php'
         *   'metadata_base'  => 'https://example.com/wp-content/plugins/hoster/inc/secure-download.php',
         *
         *
         * -------------------------------------------------------------------------
         * OPTIONAL CONFIG
         * -------------------------------------------------------------------------
         *
         *   // Display name for UI pages. Default = slug.
         *   'plugin_name'    => 'My Plugin',
         *
         *   // Option key used to store license data. Default: "uupd_license_{slug}".
         *   'option_name'    => 'uupd_license_example-plugin',
         *
         *   // Admin menu placement:
         *   // - 'options-general.php' (default)
         *   // - 'top_level'
         *   // - or any parent menu slug
         *   'menu_parent'    => 'options-general.php',
         *
         *   // Menu slug for the license settings page.
         *   // Default: "{slug}-license"
         *   'menu_slug'      => 'example-plugin-license',
         *
         *   // Titles for the license screen.
         *   'page_title'     => 'Example Plugin License',
         *   'menu_title'     => 'License',
         *
         *   // Capability needed to manage the license. Default: 'manage_options'.
         *   'capability'     => 'manage_options',
         *
         *   // For top-level menu icons (optional)
         *   'icon_url'       => '',
         *   'position'       => null,
         *
         *   // Prefix for caching transients used by the updater.
         *   'cache_prefix'   => 'upd_',
         *
         *   // Whether to append activation token to update URLs. Default: true.
         *   // Hoster returns "token" in activation response.
         *   'pass_token'     => true,
         *
         *   // Query parameter name for the token. Default: "token".
         *   'token_param'    => 'token',
         * ];
         *
         * -------------------------------------------------------------------------
         * HOSTER API USED BY THIS CLASS
         * -------------------------------------------------------------------------
         *
         *   POST /wp-json/hoster/v1/hoster-activate-license
         *   POST /wp-json/hoster/v1/hoster-deactivate-license
         *   POST /wp-json/hoster/v1/hoster-check-license
         *
         *   All requests send:
         *     {
         *       "download_id": {item_id},
         *       "license_key": "...",
         *       "site_url": "https://example.com"
         *     }
         *
         *   Activation responses provide:
         *     - success / message
         *     - token (used for update downloads)
         *
         * -------------------------------------------------------------------------
         * UPDATER INTEGRATION
         * -------------------------------------------------------------------------
         *
         * The updater receives a rewritten metadata URL:
         *
         *   {metadata_base}?file=json&download={item_id}&token={token}
         *
         * Example:
         *   https://example.com/wp-content/plugins/hoster/inc/secure-download.php
         *      ?file=json
         *      &download=7
         *      &token=32cae3efb34...
         *
         *
         * @param array $config Configuration array.
         * @return self
         */

        public static function register( array $config ) {
            $defaults = [
                'slug'           => '',
                'item_id'        => 0,
                'license_server' => '',
                'metadata_base'  => '',
                'plugin_name'    => '',
                'option_name'    => '',
                'menu_parent'    => 'options-general.php',
                'menu_slug'      => '',
                'page_title'     => '',
                'menu_title'     => '',
                'capability'     => 'manage_options',
                'icon_url'       => '',
                'position'       => null,
                'cache_prefix'   => 'upd_',
                'pass_token'     => false,
                'token_param'    => 'token',

            ];

            $config = wp_parse_args( $config, $defaults );

            if ( empty( $config['slug'] ) ) {
                _doing_it_wrong( __METHOD__, __( 'Missing slug in UUPD_License_UI::register()', 'default' ), self::VERSION );
                return null;
            }

            if ( empty( $config['item_id'] ) ) {
                _doing_it_wrong( __METHOD__, __( 'Missing item_id in UUPD_License_UI::register()', 'default' ), self::VERSION );
                return null;
            }

            if ( empty( $config['license_server'] ) ) {
                _doing_it_wrong( __METHOD__, __( 'Missing license_server in UUPD_License_UI::register()', 'default' ), self::VERSION );
                return null;
            }

            if ( empty( $config['metadata_base'] ) ) {
                _doing_it_wrong( __METHOD__, __( 'Missing metadata_base in UUPD_License_UI::register()', 'default' ), self::VERSION );
                return null;
            }

            $slug = sanitize_key( $config['slug'] );

            if ( empty( $config['plugin_name'] ) ) {
                $config['plugin_name'] = $slug;
            }

            if ( empty( $config['option_name'] ) ) {
                $config['option_name'] = 'uupd_license_' . $slug;
            }

            if ( empty( $config['menu_slug'] ) ) {
                $config['menu_slug'] = 'uupd_license_' . $slug;
            }

            if ( empty( $config['page_title'] ) ) {
                $config['page_title'] = sprintf( __( '%s License', 'default' ), $config['plugin_name'] );
            }

            if ( empty( $config['menu_title'] ) ) {
                $config['menu_title'] = __( 'License', 'default' );
            }

            $config['slug'] = $slug;

            if ( isset( self::$instances[ $slug ] ) ) {
                // Already registered.
                return self::$instances[ $slug ];
            }

            $instance = new self( $config );
            self::$instances[ $slug ] = $instance;

            return $instance;
        }

        /**
         * Get an already-registered instance.
         *
         * @param string $slug
         *
         * @return self|null
         */
        public static function get_instance( $slug ) {
            $slug = sanitize_key( $slug );
            return isset( self::$instances[ $slug ] ) ? self::$instances[ $slug ] : null;
        }

        /**
         * Constructor.
         *
         * @param array $config
         */
        public function __construct( array $config ) {
            $this->config = $config;

            // Admin UI – only if menu_parent is not empty/false
            if ( ! empty( $this->config['menu_parent'] ) && $this->config['menu_parent'] !== false ) {
                add_action( 'admin_menu', [ $this, 'register_menu' ] );
            }


            // Handle form posts
            add_action( 'admin_post_uupd_license_action', [ __CLASS__, 'handle_form_post' ] );

            // Global admin notice (please activate license)
            add_action( 'admin_notices', [ $this, 'maybe_show_admin_notice' ] );

            // Hook into UUPD to build the *remote URL* based on stored license
            add_filter(
                'uupd/server_url/' . $this->config['slug'],
                [ __CLASS__, 'filter_server_url' ],
                10,
                2
            );

            // Backwards-compatible alias used in some updaters.
            add_filter(
                'uupd/server_url/' . $this->config['slug'],
                [ __CLASS__, 'filter_remote_url' ],
                10,
                2
            );

            // Also expose a cron hook for this slug:
            add_action(
                'uupd_license_check_' . $this->config['slug'],
                [ $this, 'cron_check_license' ]
            );
        }

        /**
         * Register the admin menu/page.
         */
        public function register_menu() {
            $c = $this->config;

            if ( $c['menu_parent'] === 'top_level' ) {
                add_menu_page(
                    $c['page_title'],
                    $c['menu_title'],
                    $c['capability'],
                    $c['menu_slug'],
                    [ $this, 'render_page' ],
                    $c['icon_url'],
                    $c['position']
                );
            } else {
                $parent = $c['menu_parent'] ? $c['menu_parent'] : 'options-general.php';

                add_submenu_page(
                    $parent,
                    $c['page_title'],
                    $c['menu_title'],
                    $c['capability'],
                    $c['menu_slug'],
                    [ $this, 'render_page' ]
                );
            }
        }

        /**
         * Render the main license management page.
         */
        public function render_page() {
            if ( ! current_user_can( $this->config['capability'] ) ) {
                wp_die( esc_html__( 'You do not have permission to access this page.', 'default' ) );
            }

            $c       = $this->config;
            $slug    = $c['slug'];
            $option  = get_option( $c['option_name'], [] );
            $status  = isset( $option['status'] ) ? $option['status'] : 'inactive';
            $license = isset( $option['license_key'] ) ? $option['license_key'] : '';
            $license_masked = $license ? $this->mask_license_key( $license ) : '';

            $is_active = ( strtolower( $status ) === 'active' );

            $status_label = $is_active ? __( 'Active', 'default' ) : __( 'Inactive', 'default' );
            $status_class = $is_active ? 'uupd-license-status--active' : 'uupd-license-status--inactive';

            $last_response = isset( $option['last_response'] ) && is_array( $option['last_response'] )
                ? $option['last_response']
                : [];

            $last_checked = ! empty( $option['last_check'] )
                ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $option['last_check'] )
                : __( 'Never', 'default' );

            $last_error = isset( $option['last_error'] ) ? $option['last_error'] : '';

            // Small details from license, if present
            $expires         = isset( $last_response['date_expires'] ) ? $last_response['date_expires'] : '';
            $activations     = isset( $last_response['activations'] ) ? (int) $last_response['activations'] : null;
            $max_activations = isset( $last_response['max_activations'] ) ? (int) $last_response['max_activations'] : null;

            $host = parse_url( home_url(), PHP_URL_HOST );

            ?>
            <div class="wrap">
                <h1><?php echo esc_html( $c['page_title'] ); ?></h1>

                <style>
                    .uupd-license-wrap {
                        max-width: 800px;
                        margin-top: 20px;
                    }
                    .uupd-license-card {
                        background: #fff;
                        border: 1px solid #ccd0d4;
                        border-radius: 4px;
                        padding: 20px;
                        box-shadow: 0 1px 1px rgba(0,0,0,.04);
                    }
                    .uupd-license-header {
                        display: flex;
                        justify-content: space-between;
                        align-items: center;
                        margin-bottom: 10px;
                    }
                    .uupd-license-title {
                        margin: 0;
                        font-size: 18px;
                    }
                    .uupd-license-status {
                        padding: 4px 10px;
                        border-radius: 999px;
                        font-size: 12px;
                        font-weight: 600;
                        text-transform: uppercase;
                        letter-spacing: 0.05em;
                    }
                    .uupd-license-status--active {
                        background: #e3f7e5;
                        color: #22863a;
                        border: 1px solid #34a853;
                    }
                    .uupd-license-status--inactive {
                        background: #fff5f5;
                        color: #d93025;
                        border: 1px solid #ea4335;
                    }
                    .uupd-license-body {
                        margin-top: 8px;
                    }
                    .uupd-license-field {
                        margin-bottom: 16px;
                    }
                    .uupd-license-field label {
                        display: block;
                        font-weight: 500;
                        margin-bottom: 4px;
                    }
                    .uupd-license-field input[type="text"] {
                        width: 100%;
                        max-width: 380px;
                    }
                    .uupd-license-meta {
                        display: flex;
                        flex-wrap: wrap;
                        justify-content: space-between;
                        align-items: center;
                        margin-top: 12px;
                        font-size: 12px;
                        color: #666;
                    }
                    .uupd-license-actions .button {
                        margin-right: 6px;
                    }
                    .uupd-license-meta small {
                        display: block;
                    }
                    .uupd-license-error {
                        margin-top: 10px;
                        padding: 8px 10px;
                        border-radius: 3px;
                        background: #fff5f5;
                        border: 1px solid #ea4335;
                        color: #d93025;
                    }
                </style>

                <div class="uupd-license-wrap">
                    <div class="uupd-license-card">
                        <div class="uupd-license-header">
                            <h2 class="uupd-license-title">
                                <?php echo esc_html( $c['plugin_name'] ); ?>
                            </h2>
                            <div class="uupd-license-status <?php echo esc_attr( $status_class ); ?>">
                                <?php echo esc_html( $status_label ); ?>
                            </div>
                        </div>

                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="uupd-license-body">
                            <?php wp_nonce_field( 'uupd_license_action_' . $slug, 'uupd_license_nonce' ); ?>
                            <input type="hidden" name="action" value="uupd_license_action" />
                            <input type="hidden" name="uupd_slug" value="<?php echo esc_attr( $slug ); ?>" />

                                                        <div class="uupd-license-field">
                                <?php if ( ! $is_active ) : ?>
                                    <label for="uupd_license_key"><?php esc_html_e( 'License Key', 'default' ); ?></label>
                                    <?php
                                    // Do not pre-fill the box with the stored license. We only show a masked
                                    // version below for privacy.
                                    $input_value = '';
                                    ?>
                                    <input
                                        type="text"
                                        id="uupd_license_key"
                                        name="uupd_license_key"
                                        class="regular-text"
                                        value="<?php echo esc_attr( $input_value ); ?>"
                                        placeholder="<?php esc_attr_e( 'Enter your license key', 'default' ); ?>"
                                    />
                                    <?php if ( $license_masked ) : ?>
                                        <p class="description">
                                            <?php
                                            printf(
                                                esc_html__( 'Current key: %s', 'default' ),
                                                esc_html( $license_masked )
                                            );
                                            ?>
                                        </p>
                                    <?php endif; ?>
                                <?php else : ?>
                                    <p><?php esc_html_e( 'Your license is active.', 'default' ); ?></p>
                                    <?php if ( $license_masked ) : ?>
                                        <p class="description">
                                            <?php
                                            printf(
                                                esc_html__( 'Current key: %s', 'default' ),
                                                esc_html( $license_masked )
                                            );
                                            ?>
                                        </p>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>

                            <div class="uupd-license-actions">
                                <?php if ( ! $is_active ) : ?>
                                    <button type="submit" name="uupd_action" value="activate" class="button button-primary">
                                        <?php esc_html_e( 'Activate License', 'default' ); ?>
                                    </button>
                                <?php endif; ?>

                                <?php if ( $is_active ) : ?>
                                    <button type="submit" name="uupd_action" value="deactivate" class="button">
                                        <?php esc_html_e( 'Deactivate License', 'default' ); ?>
                                    </button>
                                <?php endif; ?>
                            </div>


                            <?php if ( $last_error ) : ?>
                                <div class="uupd-license-error">
                                    <?php echo esc_html( $last_error ); ?>
                                </div>
                            <?php endif; ?>

                            <div class="uupd-license-meta">
                                <div>
                                    <small>
                                        <?php printf( esc_html__( 'Site: %s', 'default' ), esc_html( $host ) ); ?>
                                    </small>
                                    <small>
                                        <?php printf( esc_html__( 'Last checked: %s', 'default' ), esc_html( $last_checked ) ); ?>
                                    </small>
                                </div>

                                <div>
                                    <?php if ( $expires ) : ?>
                                        <small>
                                            <?php printf( esc_html__( 'Expires: %s', 'default' ), esc_html( $expires ) ); ?>
                                        </small>
                                    <?php endif; ?>
                                    <?php if ( $activations !== null && $max_activations !== null ) : ?>
                                        <small>
                                            <?php
                                            printf(
                                                esc_html__( 'Activations: %1$d / %2$d', 'default' ),
                                                $activations,
                                                $max_activations
                                            );
                                            ?>
                                        </small>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            <?php
        }

        /**
         * Global admin notice if license is missing/invalid/expired.
         */
        public function maybe_show_admin_notice() {
            if ( ! current_user_can( $this->config['capability'] ) ) {
                return;
            }

            $c       = $this->config;
            $option  = get_option( $c['option_name'], [] );
            $status  = isset( $option['status'] ) ? $option['status'] : 'inactive';
            $license = isset( $option['license_key'] ) ? $option['license_key'] : '';

            $status_normalized = strtolower( $status );

            $license_array = [
                'status'  => $status_normalized,
                'license' => $license,
                'option'  => $option,
            ];

            $show = true;

            /**
             * Per-slug filter to control whether to show the notice.
             *
             * @param bool  $show
             * @param array $license_array
             * @param self  $instance
             */
            $show = apply_filters( 'uupd/license_ui/show_notice/' . $c['slug'], $show, $license_array, $this );

            /**
             * Global filter to control the notice display.
             *
             * @param bool   $show
             * @param string $slug
             * @param array  $license_array
             * @param self   $instance
             */
            $show = apply_filters( 'uupd/license_ui/show_notice', $show, $c['slug'], $license_array, $this );

            if ( ! $show ) {
                return;
            }

            // If the status is active, no need to nag.
            if ( $status_normalized === 'active' ) {
                return;
            }

            // Only show in admin (not AJAX/REST/etc).
            if ( ! is_admin() ) {
                return;
            }

            $message = sprintf(
                /* translators: 1: plugin name */
                __( 'Please activate your %s license to enable updates and support.', 'default' ),
                $c['plugin_name']
            );

            $url = add_query_arg(
                [ 'page' => $this->config['menu_slug'] ],
                admin_url( $this->config['menu_parent'] === 'options-general.php' || empty( $this->config['menu_parent'] )
                    ? 'options-general.php'
                    : 'admin.php'
                )
            );

            ?>
            <div class="notice notice-warning">
                <p>
                    <?php echo esc_html( $message ); ?>
                    <a href="<?php echo esc_url( $url ); ?>" class="button button-primary" style="margin-left: 10px;">
                        <?php esc_html_e( 'Activate License', 'default' ); ?>
                    </a>
                </p>
            </div>
            <?php
        }

        /**
         * Handle form POST from the license page or inline license box.
         */
        public static function handle_form_post() {
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_die( esc_html__( 'You do not have permission to perform this action.', 'default' ) );
            }

            $slug = isset( $_POST['uupd_slug'] ) ? sanitize_key( wp_unslash( $_POST['uupd_slug'] ) ) : '';

            if ( ! $slug ) {
                wp_die( esc_html__( 'Missing slug.', 'default' ) );
            }

            if ( ! isset( $_POST['uupd_license_nonce'] )
                || ! wp_verify_nonce( $_POST['uupd_license_nonce'], 'uupd_license_action_' . $slug ) ) {
                wp_die( esc_html__( 'Invalid nonce.', 'default' ) );
            }

            $inst = self::get_instance( $slug );
            if ( ! $inst ) {
                wp_die( esc_html__( 'License handler not found.', 'default' ) );
            }

            $action = isset( $_POST['uupd_action'] ) ? sanitize_text_field( wp_unslash( $_POST['uupd_action'] ) ) : '';
            $key    = isset( $_POST['uupd_license_key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['uupd_license_key'] ) ) ) : '';

            $inst->process_action( $action, $key );

            // Figure out where to go after processing the action.
            // By default we send the user back to the dedicated license page,
            // but an inline license box can override this via the optional
            // "uupd_redirect" POST field.
            $default_redirect = add_query_arg(
                [
                    'page' => $inst->config['menu_slug'],
                ],
                admin_url( $inst->config['menu_parent'] === 'options-general.php' || empty( $inst->config['menu_parent'] )
                    ? 'options-general.php'
                    : 'admin.php'
                )
            );

            $redirect_raw = isset( $_POST['uupd_redirect'] ) ? wp_unslash( $_POST['uupd_redirect'] ) : '';
            if ( $redirect_raw ) {
                // Ensure we only redirect to a safe, allowed location.
                $redirect = wp_validate_redirect( $redirect_raw, $default_redirect );
            } else {
                $redirect = $default_redirect;
            }

            wp_safe_redirect( $redirect );
            exit;
        }

        /**
         * Process activate/deactivate actions.
         *
         * @param string $action      Action: activate|deactivate.
         * @param string $license_key License key string.
         */
        protected function process_action( $action, $license_key ) {
            $c = $this->config;

            $option_name = $c['option_name'];

            //What Version are we using?
            $this->log(
                sprintf(
                    '✓ Using UUPD_License_UI - Version: %s',
                    self::VERSION
                )
            );

            if ( $action === 'activate' ) {
                if ( empty( $license_key ) ) {
                    // Empty key: just clear stored data.
                    delete_option( $option_name );
                    return;
                }

                $this->log(
                    'Calling activate endpoint.',
                    [
                        'license_key_masked' => $this->mask_license_key( $license_key ),
                    ]
                );

                $result = $this->call_license_endpoint( $license_key, 'activate', home_url() );

                $this->log(
                    'Activate endpoint result',
                    [
                        'code' => isset( $result['code'] ) ? $result['code'] : null,
                    ]
                );

                $this->update_option_from_response( $license_key, $result );

            } elseif ( $action === 'deactivate' ) {
            // Deactivation does not require providing the key again.
            $current = get_option( $option_name, [] );
            $key     = isset( $current['license_key'] ) ? $current['license_key'] : '';

            if ( ! $key ) {
                // Nothing to deactivate.
                delete_option( $option_name );
                return;
            }

            $this->log(
                'Calling deactivate endpoint.',
                [
                    'license_key_masked' => $this->mask_license_key( $key ),
                ]
            );

            $result = $this->call_license_endpoint( $key, 'deactivate', home_url() );

            $this->log(
                'Deactivate endpoint result',
                [
                    'code' => isset( $result['code'] ) ? $result['code'] : null,
                ]
            );

            $code = isset( $result['code'] ) ? (int) $result['code'] : 0;

            if ( $code >= 200 && $code < 300 ) {
                // Successful deactivation – completely clear stored license data.
                delete_option( $option_name );
            } else {
                // Something went wrong – keep the option but store the error.
                $this->update_option_from_response( $key, $result );
            }
        }


            // Flush updater cache so that subsequent update checks use the
            // latest license data / metadata URL.
            $this->flush_updater_cache();
        }

        /**
         * Mask a license key for logging or display (first 4 and last 4 characters).
         *
         * @param string $license
         *
         * @return string
         */
        protected function mask_license_key( $license ) {
            $license = (string) $license;
            $len     = strlen( $license );

            if ( $len <= 8 ) {
                return str_repeat( '*', max( $len - 2, 0 ) ) . substr( $license, -2 );
            }

            return substr( $license, 0, 4 ) . str_repeat( '*', $len - 8 ) . substr( $license, -4 );
        }

       
        /**
         * Call the Hoster license endpoint (activate/deactivate/check).
         *
         * @param string $license_key License key entered by the user.
         * @param string $action      Action: activate|deactivate|check.
         * @param string $app         Site URL (we send as site_url).
         *
         * @return array { code:int, data:array }
         */
         protected function call_license_endpoint( $license_key, $action, $app ) {
            $c        = $this->config;
            $server   = isset( $c['license_server'] ) ? trim( $c['license_server'] ) : '';
            $item_id  = isset( $c['item_id'] ) ? (int) $c['item_id'] : 0;
            $action   = strtolower( (string) $action );

            if ( ! $server || ! $item_id ) {
                return [
                    'code' => 0,
                    'data' => [
                        'success' => false,
                        'message' => __( 'License server or item ID is not configured.', 'default' ),
                    ],
                ];
            }

            $endpoint_base = untrailingslashit( $server );

            // Common JSON payload for Hoster.
            $payload = [
                'download_id' => $item_id,
                'license_key' => $license_key,
                'site_url'    => $app,
            ];

            $args = [
                'timeout' => 20,
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'body'    => wp_json_encode( $payload ),
            ];

            $this->log(
                'Hoster license request prepared.',
                [
                    'action'             => $action,
                    'endpoint_base'      => $endpoint_base,
                    'license_key_masked' => $this->mask_license_key( $license_key ),
                    'site_url'           => $app,
                ]
            );

            // ------------------------------------------------
            // IMPORTANT: Pre-deactivate before activation
            // to avoid "duplicate activation" errors.
            // ------------------------------------------------
            if ( $action === 'activate' ) {
                $pre_url = $endpoint_base . '/wp-json/hoster/v1/hoster-deactivate-license';

                $this->log(
                    'Hoster: pre-deactivating license before activate.',
                    [
                        'url'                => $pre_url,
                        'license_key_masked' => $this->mask_license_key( $license_key ),
                    ]
                );

                $pre_response = wp_remote_post( $pre_url, $args );

                $this->log(
                    'Hoster: pre-deactivate response (ignored for main flow).',
                    [
                        'is_error' => is_wp_error( $pre_response ),
                    ]
                );
            }

            // Main endpoint based on $action
            if ( $action === 'deactivate' ) {
                $url = $endpoint_base . '/wp-json/hoster/v1/hoster-deactivate-license';
            } elseif ( $action === 'check' || $action === 'check_license' ) {
                $url = $endpoint_base . '/wp-json/hoster/v1/hoster-check-license';
            } else {
                // Default to activate
                $url = $endpoint_base . '/wp-json/hoster/v1/hoster-activate-license';
            }

            $this->log(
                'Hoster: calling license endpoint.',
                [
                    'url'                => $url,
                    'action'             => $action,
                    'license_key_masked' => $this->mask_license_key( $license_key ),
                ]
            );

            $response = wp_remote_post( $url, $args );

            if ( is_wp_error( $response ) ) {
                $this->log(
                    'Hoster: license endpoint WP_Error.',
                    [
                        'error' => $response->get_error_message(),
                    ]
                );

                return [
                    'code' => 0,
                    'data' => [
                        'success' => false,
                        'message' => $response->get_error_message(),
                    ],
                ];
            }

            $code = wp_remote_retrieve_response_code( $response );
            $body = wp_remote_retrieve_body( $response );
            $data = json_decode( $body, true );
            if ( ! is_array( $data ) ) {
                $data = [];
            }

            $this->log(
                'Hoster: license endpoint response.',
                [
                    'code' => $code,
                    'data' => $data,
                ]
            );

            return [
                'code' => $code,
                'data' => $data,
            ];
        }


        /**
         * Update stored license option based on API response.
         *
         * @param string $license_key Raw license key string.
         * @param array  $result      Result array from call_license_endpoint().
         */
        protected function update_option_from_response( $license_key, array $result ) {
            $c           = $this->config;
            $option_name = $c['option_name'];

            $code = isset( $result['code'] ) ? (int) $result['code'] : 0;
            $data = ( isset( $result['data'] ) && is_array( $result['data'] ) ) ? $result['data'] : [];

            $status     = 'unknown';
            $license_id = null;
            $error_msg  = '';

            // ------------------------------------------------------------------
            // 1) STATUS – always prefer explicit status from the API
            // ------------------------------------------------------------------
            if ( isset( $data['status'] ) ) {
                // Hoster: "active", "invalid", "expired", ...
                $status = strtolower( (string) $data['status'] );
            } elseif ( isset( $data['license_status'] ) ) {
                // Legacy/alternative name if ever used
                $status = strtolower( (string) $data['license_status'] );
            }

            // ------------------------------------------------------------------
            // 2) FALLBACK – activation success with no status field
            //
            // Hoster activation success:
            //   HTTP 200, success=true, NO "status"
            //
            // We only fall back to success when:
            //   - HTTP is 2xx, AND
            //   - status is still "unknown", AND
            //   - "success" exists.
            // ------------------------------------------------------------------
            if (
                $status === 'unknown' &&
                $code >= 200 && $code < 300 &&
                array_key_exists( 'success', $data )
            ) {
                $status = ! empty( $data['success'] ) ? 'active' : 'inactive';
            }

            // ------------------------------------------------------------------
            // 3) LICENSE ID
            // ------------------------------------------------------------------
            if ( isset( $data['id'] ) ) {
                $license_id = (int) $data['id'];
            } elseif ( isset( $data['license_id'] ) ) {
                $license_id = (int) $data['license_id'];
            }

            // ------------------------------------------------------------------
            // 4) ERROR MESSAGE – for non-2xx OR explicit success = false
            // ------------------------------------------------------------------
            if (
                $code < 200 || $code >= 300 ||
                ( isset( $data['success'] ) && empty( $data['success'] ) )
            ) {
                $error_msg = isset( $data['message'] ) ? (string) $data['message'] : '';
                if ( ! $error_msg && isset( $data['error'] ) ) {
                    $error_msg = (string) $data['error'];
                }

                if ( ! $error_msg && $code ) {
                    $error_msg = sprintf(
                        /* translators: %d: HTTP status code */
                        __( 'License request failed with status code %d.', 'default' ),
                        $code
                    );
                }
            }

            // ------------------------------------------------------------------
            // 5) Save everything to the option.
            //
            // IMPORTANT:
            //  - Only "active" is treated as active elsewhere:
            //      $is_active = ( strtolower( $status ) === 'active' );
            //  - "expired", "invalid", "inactive", "unknown" are all NOT active.
            // ------------------------------------------------------------------
            $option = [
                'license_key'   => $license_key,
                'status'        => $status,
                'license_id'    => $license_id,
                'item_id'       => $c['item_id'],
                'last_response' => $data,
                'last_check'    => time(),
                'last_error'    => $error_msg,
            ];

            $this->log(
                'Updating stored license option from response.',
                [
                    'code'        => $code,
                    'status'      => $status,
                    'license_id'  => $license_id,
                    'last_error'  => $error_msg,
                    'option_name' => $option_name,
                ]
            );

            update_option( $option_name, $option, false );
        }



        /**
         * Flush UUPD cache/transients after license changes.
         */
        protected function flush_updater_cache() {
            $c = $this->config;

            $prefix = $c['cache_prefix'];
            $slug   = $c['slug'];

            global $wpdb;

            if ( empty( $wpdb->options ) ) {
                return;
            }

            $like = $wpdb->esc_like( '_transient_' . $prefix . $slug );
            $sql  = $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                $like . '%',
                $wpdb->esc_like( '_transient_timeout_' . $prefix . $slug ) . '%'
            );

            $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

            $this->log(
                'Flushed updater cache transients.',
                [
                    'prefix' => $prefix,
                    'slug'   => $slug,
                ]
            );
        }

        /**
         * Static helper to run a live license check for a registered slug.
         *
         * @param string $slug
         * @param bool   $flush_updater_cache Whether to flush updater cache after check.
         *
         * @return array|null Result array from call_license_endpoint() or null if no license.
         */
        public static function check_license_for( $slug, $flush_updater_cache = true ) {
            $inst = self::get_instance( $slug );
            if ( ! $inst ) {
                return null;
            }

            return $inst->check_license_live( $flush_updater_cache );
        }

        /**
         * Instance method to run a live license check.
         *
         * @param bool $flush_updater_cache Whether to flush updater cache after check.
         *
         * @return array|null
         */
        public function check_license_live( $flush_updater_cache = true ) {
            $c = $this->config;

            $option_name = $c['option_name'];
            $option      = get_option( $option_name, [] );
            $license_key = isset( $option['license_key'] ) ? $option['license_key'] : '';

            if ( ! $license_key ) {
                return null;
            }

            $this->log(
                'Running live license check.',
                [
                    'license_key' => $this->mask_license_key( $license_key ),
                ]
            );

            $result = $this->call_license_endpoint( $license_key, 'check', home_url() );

            $this->update_option_from_response( $license_key, $result );

            if ( $flush_updater_cache ) {
                $this->flush_updater_cache();
            }

            return $result;
        }

        /**
         * Cron handler to run scheduled license checks.
         */
        public function cron_check_license() {
            $this->check_license_live( true );
        }

        /**
         * Rewrites the updater "server" URL to point to the Hoster secure-download script
         * and append the license token from the last activation.
         *
         * Final URL:
         *   {metadata_base}?file=json&download={item_id}&token={TOKEN}
         *
         * where metadata_base = https://hoster.wptv.uk/wp-content/plugins/hoster/inc/secure-download.php
         *
         * @param string $url  Original URL from updater.
         * @param string $slug Plugin slug.
         *
         * @return string
         */
        public static function filter_server_url( $url, $slug ) {
            $inst = self::get_instance( $slug );
            if ( ! $inst ) {
                return $url;
            }

            $c           = $inst->config;
            $option_name = $c['option_name'];
            $option      = get_option( $option_name, [] );
            $item_id     = isset( $c['item_id'] ) ? (int) $c['item_id'] : 0;

            if ( empty( $c['metadata_base'] ) || ! $item_id ) {
                return $url;
            }

            $base = $c['metadata_base']; // e.g. https://hoster.wptv.uk/wp-content/plugins/hoster/inc/secure-download.php

            // Base download URL for Hoster JSON metadata.
            $new = add_query_arg(
                [
                    'file'     => 'json',
                    'download' => $item_id,
                ],
                $base
            );

            // Append token from last_response if enabled and available.
            if ( ! empty( $c['pass_token'] ) ) {
                $token_param   = ! empty( $c['token_param'] ) ? $c['token_param'] : 'token';
                $last_response = ! empty( $option['last_response'] ) && is_array( $option['last_response'] )
                    ? $option['last_response']
                    : [];

                if ( ! empty( $last_response['token'] ) ) {
                    $new = add_query_arg(
                        $token_param,
                        rawurlencode( $last_response['token'] ),
                        $new
                    );
                }
            }

            $inst->log(
                'Hoster: rewriting updater metadata URL.',
                [
                    'original_url' => $url,
                    'new_url'      => $new,
                ]
            );

            return $new;
        }

        /**
         * Back-compat alias used by updater.
         */
        public static function filter_remote_url( $url, $slug ) {
            return self::filter_server_url( $url, $slug );
        }


        
            
        /**
         * Render a compact inline license box for a given slug.
         *
         * This lets you embed the license UI inside any existing admin page
         * (for example, your main plugin settings screen) while still using
         * the same storage, endpoints and updater integration as the full
         * UUPD_License_UI page.
         *
         * Usage:
         *   \UUPD\V1\UUPD_License_UI::render_box_for( 'your-slug' );
         *
         * @param string $slug Unique slug used when calling register().
         * @param array  $args Optional overrides:
         *                     - id               (string) HTML id for the box
         *                     - title            (string) Box heading
         *                     - description      (string) Help text under heading
         *                     - placeholder      (string) Placeholder for input
         *                     - activate_label   (string) Activate button label
         *                     - deactivate_label (string) Deactivate button label
         *                     - box_class        (string) Extra CSS class on wrapper
         */
        public static function render_box_for( $slug, array $args = [] ) {
            $inst = self::get_instance( $slug );
            if ( ! $inst ) {
                return;
            }

            $c = $inst->config;

            if ( ! current_user_can( $c['capability'] ) ) {
                return;
            }

            $option  = get_option( $c['option_name'], [] );
            $status  = isset( $option['status'] ) ? $option['status'] : 'inactive';
            $license = isset( $option['license_key'] ) ? $option['license_key'] : '';
            $license_masked = $license ? $inst->mask_license_key( $license ) : '';

            $is_active    = ( strtolower( $status ) === 'active' );
            $status_label = $is_active ? __( 'Active', 'default' ) : __( 'Inactive', 'default' );
            $status_class = $is_active ? 'uupd-license-status--active' : 'uupd-license-status--inactive';

            $last_response = isset( $option['last_response'] ) && is_array( $option['last_response'] )
                ? $option['last_response']
                : [];

            $last_checked = ! empty( $option['last_check'] )
                ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $option['last_check'] )
                : __( 'Never', 'default' );

            $last_error = isset( $option['last_error'] ) ? $option['last_error'] : '';

            $expires         = isset( $last_response['date_expires'] ) ? $last_response['date_expires'] : '';
            $activations     = isset( $last_response['activations'] ) ? (int) $last_response['activations'] : null;
            $max_activations = isset( $last_response['max_activations'] ) ? (int) $last_response['max_activations'] : null;

            $host = parse_url( home_url(), PHP_URL_HOST );

            $defaults = [
                'id'               => 'uupd-license-box-' . $slug,
                'title'            => sprintf( __( '%s Licence', 'default' ), $c['plugin_name'] ),
                'description'      => $is_active
                    ? __( 'Your license is active.', 'default' )
                    : sprintf( __( 'Enter your license key to enable updates for %s.', 'default' ), $c['plugin_name'] ),
                'placeholder'      => __( 'Enter your license key', 'default' ),
                'activate_label'   => __( 'Activate License', 'default' ),
                'deactivate_label' => __( 'Deactivate License', 'default' ),
                'box_class'        => '',
            ];

            $args = wp_parse_args( $args, $defaults );

            // Avoid duplicate "Your license is active" message – we show it in the body section.
            if ( $is_active ) {
                $args['description'] = '';
            }

            // Print compact CSS once
            if ( ! self::$inline_box_css_printed ) {
                self::$inline_box_css_printed = true;
                ?>
                <style>
                    .uupd-license-inline-box {
                        border: 1px solid #d0d7de;
                        background: #fff;
                        padding: 12px 16px;
                        margin: 16px 0;
                        border-radius: 6px;
                        max-width: 540px;
                        box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
                        font-size: 13px;
                    }
                    .uupd-license-inline-header {
                        display: flex;
                        justify-content: space-between;
                        align-items: center;
                        cursor: pointer;
                        user-select: none;
                    }
                    .uupd-license-inline-title {
                        margin: 0;
                        font-size: 14px;
                        font-weight: 600;
                    }
                    .uupd-license-inline-right {
                        display: flex;
                        align-items: center;
                        gap: 8px;
                    }
                    .uupd-license-toggle-icon {
                        display: inline-block;
                        transition: transform 0.15s ease;
                        font-size: 12px;
                    }
                    .uupd-license-inline-box[data-collapsed="1"] .uupd-license-toggle-icon {
                        transform: rotate(-90deg);
                    }
                    .uupd-license-inline-description {
                        margin: 8px 0 10px;
                        max-width: 460px;
                        color: #4b5563;
                    }
                    .uupd-license-status {
                        padding: 2px 8px;
                        border-radius: 999px;
                        font-size: 11px;
                        font-weight: 600;
                        text-transform: uppercase;
                        letter-spacing: 0.05em;
                        white-space: nowrap;
                    }
                    .uupd-license-status--active {
                        background: #e3f7e5;
                        color: #15803d;
                        border: 1px solid #22c55e;
                    }
                    .uupd-license-status--inactive {
                        background: #fef2f2;
                        color: #b91c1c;
                        border: 1px solid #f97373;
                    }
                    .uupd-license-inline-body {
                        margin-top: 10px;
                    }
                    .uupd-license-inline-body .uupd-license-field {
                        margin-bottom: 8px;
                    }
                    .uupd-license-inline-body .uupd-license-field label {
                        display: block;
                        font-weight: 500;
                        margin-bottom: 4px;
                    }
                    .uupd-license-inline-body .uupd-license-field input[type="text"],
                    .uupd-license-inline-body .uupd-license-field input[type="password"] {
                        width: 100%;
                        max-width: 320px;
                    }
                    .uupd-license-inline-body .uupd-license-actions {
                        margin-top: 6px;
                    }
                    .uupd-license-inline-body .uupd-license-actions .button {
                        margin-right: 6px;
                    }
                    .uupd-license-meta {
                        margin-top: 6px;
                        font-size: 11px;
                        color: #6b7280;
                    }
                    .uupd-license-meta small {
                        display: inline-block;
                        margin-right: 10px;
                    }
                    .uupd-license-error {
                        margin-top: 8px;
                        padding: 6px 8px;
                        border-radius: 4px;
                        background: #fef2f2;
                        border: 1px solid #f97373;
                        color: #b91c1c;
                    }
                </style>
                <?php
            }

            // Simple JS toggler – printed once
            if ( ! self::$inline_box_js_printed ) {
                self::$inline_box_js_printed = true;
                ?>
                <script>
                    (function() {
                        document.addEventListener('click', function(e) {
                            var header = e.target.closest('.uupd-license-inline-header');
                            if (!header) return;

                            var box  = header.closest('.uupd-license-inline-box');
                            if (!box) return;

                            var body = box.querySelector('.uupd-license-inline-body');
                            if (!body) return;

                            var collapsed = box.getAttribute('data-collapsed') === '1';
                            box.setAttribute('data-collapsed', collapsed ? '0' : '1');
                            body.style.display = collapsed ? '' : 'none';
                        });
                    })();
                </script>
                <?php
            }

            // Default: collapse when active, expanded when inactive
            $collapsed   = $is_active ? '1' : '0';
            $body_style  = $is_active ? 'style="display:none;"' : '';

            // Build redirect URL (back to current page)
            $scheme      = is_ssl() ? 'https://' : 'http://';
            $host_header = isset( $_SERVER['HTTP_HOST'] ) ? wp_unslash( $_SERVER['HTTP_HOST'] ) : '';
            $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
            $current_url = ( $host_header && $request_uri ) ? $scheme . $host_header . $request_uri : '';
            ?>
            <div class="uupd-license-inline-box <?php echo esc_attr( $args['box_class'] ); ?>"
                id="<?php echo esc_attr( $args['id'] ); ?>"
                data-collapsed="<?php echo esc_attr( $collapsed ); ?>">

                <div class="uupd-license-inline-header" tabindex="0">
                    <h2 class="uupd-license-inline-title">
                        <?php echo esc_html( $args['title'] ); ?>
                    </h2>
                    <div class="uupd-license-inline-right">
                        <?php if ( $license_masked ) : ?>
                            <span style="font-size:11px;color:#6b7280;"><?php echo esc_html( $license_masked ); ?></span>
                        <?php endif; ?>
                        <span class="uupd-license-status <?php echo esc_attr( $status_class ); ?>">
                            <?php echo esc_html( $status_label ); ?>
                        </span>
                        <span class="uupd-license-toggle-icon" aria-hidden="true">▾</span>
                    </div>
                </div>

                <div class="uupd-license-inline-body" <?php echo $body_style; ?>>
                    <?php if ( ! empty( $args['description'] ) ) : ?>
                        <p class="uupd-license-inline-description">
                            <?php echo esc_html( $args['description'] ); ?>
                        </p>
                    <?php endif; ?>

                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <?php wp_nonce_field( 'uupd_license_action_' . $slug, 'uupd_license_nonce' ); ?>
                        <input type="hidden" name="action" value="uupd_license_action" />
                        <input type="hidden" name="uupd_slug" value="<?php echo esc_attr( $slug ); ?>" />
                        <?php if ( $current_url ) : ?>
                            <input type="hidden" name="uupd_redirect" value="<?php echo esc_attr( $current_url ); ?>" />
                        <?php endif; ?>

                        <div class="uupd-license-field">
                            <?php if ( ! $is_active ) : ?>
                                <label for="uupd_license_key_<?php echo esc_attr( $slug ); ?>">
                                    <?php esc_html_e( 'License Key', 'default' ); ?>
                                </label>
                                <input
                                    type="text"
                                    id="uupd_license_key_<?php echo esc_attr( $slug ); ?>"
                                    name="uupd_license_key"
                                    class="regular-text"
                                    value=""
                                    placeholder="<?php echo esc_attr( $args['placeholder'] ); ?>"
                                />
                                <?php if ( $license_masked ) : ?>
                                    <p class="description">
                                        <?php
                                        printf(
                                            esc_html__( 'Current key: %s', 'default' ),
                                            esc_html( $license_masked )
                                        );
                                        ?>
                                    </p>
                                <?php endif; ?>
                            <?php else : ?>
                                <p><?php esc_html_e( 'Your license is active.', 'default' ); ?></p>
                                <?php if ( $license_masked ) : ?>
                                    <p class="description">
                                        <?php
                                        printf(
                                            esc_html__( 'Current key: %s', 'default' ),
                                            esc_html( $license_masked )
                                        );
                                        ?>
                                    </p>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>

                        <div class="uupd-license-actions">
                            <?php if ( ! $is_active ) : ?>
                                <button type="submit" name="uupd_action" value="activate" class="button button-primary">
                                    <?php echo esc_html( $args['activate_label'] ); ?>
                                </button>
                            <?php endif; ?>

                            <?php if ( $is_active ) : ?>
                                <button type="submit" name="uupd_action" value="deactivate" class="button">
                                    <?php echo esc_html( $args['deactivate_label'] ); ?>
                                </button>
                            <?php endif; ?>
                        </div>


                       

                        <div class="uupd-license-meta">
                            <small><?php printf( esc_html__( 'Site: %s', 'default' ), esc_html( $host ) ); ?></small>
                            <small><?php printf( esc_html__( 'Last checked: %s', 'default' ), esc_html( $last_checked ) ); ?></small>
                            <?php if ( $expires ) : ?>
                                <small><?php printf( esc_html__( 'Expires: %s', 'default' ), esc_html( $expires ) ); ?></small>
                            <?php endif; ?>
                            <?php if ( $activations !== null && $max_activations !== null ) : ?>
                                <small>
                                    <?php
                                    printf(
                                        esc_html__( 'Activations: %1$d / %2$d', 'default' ),
                                        $activations,
                                        $max_activations
                                    );
                                    ?>
                                </small>
                            <?php endif; ?>
                        </div>

                        <?php if ( $last_error ) : ?>
                            <div class="uupd-license-error">
                                <?php echo esc_html( $last_error ); ?>
                            </div>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
            <?php
        }


        /**
         * Basic logger (mirrors UUPD_Updater_V1 logging).
         *
         * @param string $msg
         * @param array  $context
         */
        protected function log( $msg, array $context = [] ) {
            if ( ! apply_filters( 'updater_enable_debug', false ) ) {
                return;
            }

            $slug   = $this->config['slug'] ?? '';
            $prefix = "[UUPD License][{$slug}] ";

            if ( ! empty( $context ) && function_exists( 'wp_json_encode' ) ) {
                $msg .= ' | ' . wp_json_encode( $context );
            }

            error_log( $prefix . $msg );

            /**
             * Mirror UUPD_Updater_V1 logging hook.
             *
             * @param string $msg
             * @param string $slug
             * @param array  $context
             */
            do_action( 'uupd/log', $msg, $slug, $context );
        }
    }
}
