# UUPD + Hoster License UI

A reusable, drop‑in **license UI + updater ** for WordPress plugins and themes that use:

- **[UUPD](https://github.com/stingray82/uupd)** for plugin/theme update delivery, and  
- **[Hoster by DPlugins](https://dplugins.com/downloads/hoster/)** for license management and secure downloads.

This provides:

- A reusable **`UUPD_License_UI`** class (namespace `\UUPD\V1`)  
- A WordPress admin **License** UI (page + inline box)  
- A direct integration with **Hoster’s** endpoints for:
  - Activate license
  - Deactivate license
  - Check license
- Automatic **UUPD metadata URL rewriting** to use Hoster’s `secure-download.php` with token
- Helpers for **debugging**, **cron checks**, and **local vs remote** license status

It is a fully working example of how UUPD and this reusable class could be used to sell licenced products It has been built for Hoster by DPlugins because its affordable, available and works I have other version of this in the pipeline to work with other licence servers.

---

## 1. Requirements

You need **all** of the following installed/available:

1. **UUPD (latest GitHub version)**  
   Used as the update engine for your plugin/theme.  
   Repo: https://github.com/stingray82/uupd
2. **Hoster by dPlugins**  
   Provides the license API and secure download endpoints.  
   Product: https://dplugins.com/downloads/hoster/

---

## 2. What it Does

### 2.1 Hoster API Integration

The class calls these Hoster endpoints:

- `POST /wp-json/hoster/v1/hoster-activate-license`
- `POST /wp-json/hoster/v1/hoster-deactivate-license`
- `POST /wp-json/hoster/v1/hoster-check-license`

Payload (JSON):

```jsonc
{
  "download_id": 7,
  "license_key": "xxxx",
  "site_url": "https://client-site.com"
}
```

Behaviour:

- On activation:
  - If valid → returns `success: true`, `license_id`, `expiry_date`, `token`, etc.
  - If expired/invalid → returns `success: false` and `status: "expired" | "invalid"`
- Before each activation, the class will **attempt a pre‑deactivation** to avoid duplicate activation errors.
- The activation response is stored in `wp_options` under `uupd_license_{slug}`.

### 2.2 UUPD Metadata / Update Integration

The bridge rewrites the updater “server” URL to Hoster’s secure download script:

```text
{metadata_base}?file=json&download={item_id}&token={token}
```

Typical example:

```text
https://hoster.example.com/wp-content/plugins/hoster/inc/secure-download.php
    ?file=json
    &download=7
    &token=32cae3e...
```

Notes:

- `{token}` comes from the last successful activation response.
- Only **status `active`** is treated as a valid license.  
  `invalid`, `expired`, `inactive`, etc. are **not** treated as active.

### 2.3 Local Storage

For each slug you register, the class stores an option:

```php
$option_name = 'uupd_license_' . $slug;
$option = get_option( $option_name );
```

Structure roughly:

```php
[
    'license_key'   => 'xxxx',
    'status'        => 'active' | 'invalid' | 'expired' | 'inactive' | 'unknown',
    'license_id'    => 10,
    'item_id'       => 7,
    'last_response' => [ /* raw Hoster API response array */ ],
    'last_check'    => 1733539200, // timestamp
    'last_error'    => '',         // last error message, if any
]
```

**Local activation** = `status === 'active'` in this option.  
**Remote activation** = Hoster’s API says the key is active for this site.

---

## 3. Basic Integration

### 3.1 Include the Files

In your plugin (on the **client site**, not on the Hoster server plugin), you need to include the UI class and the updater class from this bridge:

```php
require_once __DIR__ . '/inc/class-uupd-license-ui.php';
require_once __DIR__ . '/inc/updater.php';
```

Adjust the path as needed (this assumes you copied the bridge `inc/` folder into your plugin).

### 3.2 Register the UI and Updater (minimal example)

```php
add_action( 'plugins_loaded', function() {

    $slug      = 'example-plugin';
    $item_id   = 7; // Hoster "download_id"
    $server    = 'https://hoster.example.uk'; // your Hoster site
    $friendly  = 'Example Plugin';

    \UUPD\V1\UUPD_License_UI::register( [
        'slug'           => $slug,
        'item_id'        => $item_id,
        'license_server' => $server,
        'metadata_base'  => $server . '/wp-content/plugins/hoster/inc/secure-download.php',

        'plugin_name'    => $friendly,
        'menu_parent'    => 'options-general.php',
        'menu_slug'      => $slug . '-license',
        'page_title'     => $friendly . ' License',
        'menu_title'     => 'License',

        'cache_prefix'   => 'upd_',
        'pass_token'     => true,
        'token_param'    => 'token',
    ] );

    \UUPD\V1\UUPD_Updater_V1::register( [
        'plugin_file' => plugin_basename( __FILE__ ),
        'slug'        => $slug,
        'name'        => $friendly,
        'version'     => '1.0.0',
        'server'      => '', // dynamically rewritten by License UI
    ] );
}, 1 );
```

---

## 4. UI Integration Patterns

Below are **four** common ways to expose the license UI.

### 4.1 Top-Level Main Menu (Own Admin Menu)

```php
\UUPD\V1\UUPD_License_UI::register( [
    'slug'           => 'example-plugin',
    'item_id'        => 7,
    'license_server' => 'https://hoster.example.uk',
    'metadata_base'  => 'https://hoster.example.uk/wp-content/plugins/hoster/inc/secure-download.php',

    'plugin_name'    => 'Example Plugin',

    // Top-level menu:
    'menu_parent'    => 'top_level',          // special value
    'menu_slug'      => 'example-plugin-license',
    'page_title'     => 'Example Plugin License',
    'menu_title'     => 'Example Plugin License',
    'icon_url'       => 'dashicons-admin-network',
    'position'       => 65,
] );
```

Result:  

- A top-level **“Example Plugin License”** item in the admin menu.
- The page shows the full license UI (key input, activate/deactivate, status, etc.).


### 4.2 Submenu Under **Settings**

```php
\UUPD\V1\UUPD_License_UI::register( [
    'slug'           => 'example-plugin',
    'item_id'        => 7,
    'license_server' => 'https://hoster.example.uk',
    'metadata_base'  => 'https://hoster.example.uk/wp-content/plugins/hoster/inc/secure-download.php',

    'plugin_name'    => 'Example Plugin',

    // As a submenu of “Settings”:
    'menu_parent'    => 'options-general.php',
    'menu_slug'      => 'example-plugin-license',
    'page_title'     => 'Example Plugin License',
    'menu_title'     => 'Example Plugin License',
] );
```

Result:  

- Appears under **Settings → Example Plugin License**.


### 4.3 Submenu Under **Tools**

```php
\UUPD\V1\UUPD_License_UI::register( [
    'slug'           => 'example-plugin',
    'item_id'        => 7,
    'license_server' => 'https://hoster.example.uk',
    'metadata_base'  => 'https://hoster.example.uk/wp-content/plugins/hoster/inc/secure-download.php',

    'plugin_name'    => 'Example Plugin',

    // As a submenu of “Tools”:
    'menu_parent'    => 'tools.php',
    'menu_slug'      => 'example-plugin-license',
    'page_title'     => 'Example Plugin License',
    'menu_title'     => 'Example Plugin License',
] );
```

Result:  

- Appears under **Tools → Example Plugin License**.


### 4.4 Inline Box Within an Existing Settings Page

If you already have your own settings page and you don’t want a standalone “License” menu, you can **disable the menu** and embed the inline license box:

```php
// Register UI with no menu:
\UUPD\V1\UUPD_License_UI::register( [
    'slug'           => 'example-plugin',
    'item_id'        => 7,
    'license_server' => 'https://hoster.example.uk',
    'metadata_base'  => 'https://hoster.example.uk/wp-content/plugins/hoster/inc/secure-download.php',
    'plugin_name'    => 'Example Plugin',

    'menu_parent'    => false, // no separate menu/page
] );
```

Then inside your own settings page callback:

```php
function my_plugin_render_settings_page() {
    ?>
    <div class="wrap">
        <h1>Example Plugin Settings</h1>

        <h2>License</h2>
        <?php \UUPD\V1\UUPD_License_UI::render_box_for( 'example-plugin' ); ?>

        <h2>Other Settings</h2>
        <!-- the rest of your settings UI here -->
    </div>
    <?php
}
```

The inline box will:

- Show as **expanded** when no valid license is present (to prompt activation)
- Collapse when active, showing status + masked key + deactivate button

**Please Remember:** That this box shouldn't be nested within another form

---

## 5. Debugging & Testing

### 5.1 Enable WordPress Debug Log

In `wp-config.php`:

```php
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false ); // recommended on production
```

Check logs in: `wp-content/debug.log`.

### 5.2 Log a Live License Check

Use the static helper `check_license_for( $slug, $flush_cache )`:

```php
add_action( 'admin_init', function () {
    $slug = 'example-plugin';

    $result = \UUPD\V1\UUPD_License_UI::check_license_for( $slug, false );

    if ( $result === null ) {
        error_log( '[Example Plugin] No license key stored for slug: ' . $slug );
        return;
    }

    $code = $result['code'] ?? 'NO_CODE';
    $data = $result['data'] ?? [];

    error_log( '[Example Plugin] Live check HTTP code: ' . $code );
    error_log( '[Example Plugin] Live check data: ' . print_r( $data, true ) );

    // Status is determined mainly by $data['status'] or success flag:
    $status = isset( $data['status'] ) ? strtolower( $data['status'] ) : 'unknown';

    if ( $status === 'active' ) {
        error_log( '[Example Plugin] License ACTIVE' );
    } else {
        error_log( '[Example Plugin] License NOT ACTIVE (' . $status . ')' );
    }
});
```

### 5.3 Direct Hoster Endpoint Debug (Optional)

You can also call Hoster’s endpoints directly for diagnostics (on the server where Hoster is installed). Example:

```php
add_action( 'admin_init', function () {
    if ( empty( $_GET['hoster_debug'] ) || ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $mode = sanitize_text_field( wp_unslash( $_GET['hoster_debug'] ) );

    $license_key = 'YOUR_TEST_KEY';
    $download_id = 7;
    $server      = 'https://hoster.example.uk';

    $endpoint_base = untrailingslashit( $server );

    if ( $mode === 'activate' ) {
        $url = $endpoint_base . '/wp-json/hoster/v1/hoster-activate-license';
    } elseif ( $mode === 'deactivate' ) {
        $url = $endpoint_base . '/wp-json/hoster/v1/hoster-deactivate-license';
    } elseif ( $mode === 'check' ) {
        $url = $endpoint_base . '/wp-json/hoster/v1/hoster-check-license';
    } else {
        return;
    }

    $payload = [
        'download_id' => $download_id,
        'license_key' => $license_key,
        'site_url'    => home_url(),
    ];

    $args = [
        'timeout' => 20,
        'headers' => [ 'Content-Type' => 'application/json' ],
        'body'    => wp_json_encode( $payload ),
    ];

    $response = wp_remote_post( $url, $args );

    if ( is_wp_error( $response ) ) {
        error_log( '[Hoster Direct Debug] WP_Error: ' . $response->get_error_message() );
        return;
    }

    $code = wp_remote_retrieve_response_code( $response );
    $body = wp_remote_retrieve_body( $response );
    $data = json_decode( $body, true );

    error_log( '[Hoster Direct Debug] Mode: ' . $mode );
    error_log( '[Hoster Direct Debug] HTTP code: ' . $code );
    error_log( '[Hoster Direct Debug] Raw body: ' . $body );
    error_log( '[Hoster Direct Debug] Decoded: ' . print_r( $data, true ) );
});
```

Call it via URL (admin only):

- `/wp-admin/?hoster_debug=activate`
- `/wp-admin/?hoster_debug=deactivate`
- `/wp-admin/?hoster_debug=check`

---

## 6. Local vs Remote Activation

### 6.1 Local Activation (Stored State)

Local state is what **WordPress knows** via `wp_options`.

```php
$slug        = 'example-plugin';
$option_name = 'uupd_license_' . $slug;
$option      = get_option( $option_name );

if ( ! empty( $option['status'] ) && $option['status'] === 'active' ) {
    // Locally active
}
```

You can also inspect this option through:

- WP‑CLI: `wp option get uupd_license_example-plugin`  
- Database (`wp_options` table)  
- PHPStorm / Xdebug watches

### 6.2 Remote Activation (Hoster Check)

Remote state is what **Hoster** says when you hit `hoster-check-license`:

```php
$result = \UUPD\V1\UUPD_License_UI::check_license_for( 'example-plugin', true );

if ( $result !== null ) {
    $data   = $result['data'] ?? [];
    $status = isset( $data['status'] ) ? strtolower( $data['status'] ) : 'unknown';

    if ( $status === 'active' ) {
        // Remotely active on Hoster
    } else {
        // Expired / invalid / inactive remotely
    }
}
```

The class will update `status` and `last_response` accordingly, keeping local and remote in sync.

---

## 7. Cron-Based Remote Checks

### 7.1 Using the Built-in Cron Hook

For each slug, the class registers a cron hook:

```text
uupd_license_check_{slug}
```

So for `example-plugin`, the hook is:

```text
uupd_license_check_example-plugin
```

You can schedule it like this:

```php
add_action( 'init', function () {
    $hook = 'uupd_license_check_example-plugin';

    if ( ! wp_next_scheduled( $hook ) ) {
        wp_schedule_event( time() + 3600, 'daily', $hook );
    }
});
```

The handler is provided by the class and will:

- Call the Hoster `check` endpoint
- Update local option status
- Flush UUPD caches if necessary

### 7.2 Custom Cron Logic (Manual)

If you prefer fully custom behaviour, you can define your own cron hook:

```php
add_action( 'my_plugin_license_cron', function () {
    $slug   = 'example-plugin';
    $result = \UUPD\V1\UUPD_License_UI::check_license_for( $slug, true );

    if ( $result === null ) {
        error_log( '[Example Plugin] Cron: no license stored for slug ' . $slug );
        return;
    }

    $data   = $result['data'] ?? [];
    $status = isset( $data['status'] ) ? strtolower( $data['status'] ) : 'unknown';

    if ( $status === 'active' ) {
        error_log( '[Example Plugin] Cron: license is active.' );
    } else {
        error_log( '[Example Plugin] Cron: license not active (' . $status . ').' );
        // e.g. send email, disable features, etc.
    }
});

// Schedule it:
add_action( 'init', function () {
    if ( ! wp_next_scheduled( 'my_plugin_license_cron' ) ) {
        wp_schedule_event( time() + 300, 'twicedaily', 'my_plugin_license_cron' );
    }
});
```

---

## 8. Summary

- Use **UUPD** to deliver plugin/theme updates.  
- Use **Hoster** to manage licenses and secure downloads.  
- Use this UI Class to:
  - Display a **License page/box**
  - Activate/deactivate/check licenses using Hoster
  - Automatically wire UUPD to Hoster’s `secure-download.php` via token
  - Keep local and remote license state in sync
  - Run scheduled remote license checks via cron

You now have a complete, production-ready pipeline for **licensing + updates** that can be reused across multiple plugins and themes.
