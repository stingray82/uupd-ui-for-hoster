<?php 

/**
 * Fix Download limit this is effectively Unlimited could just return a number i.e 100.
 */

add_filter( 'hoster_max_downloads', function( $value ) {
    return PHP_INT_MAX; // effectively unlimited
});

/**
 * Add a 32-digit hash to uploaded zips to make them harder to guess.
 */

add_filter( 'wp_handle_upload_prefilter', function ( $file ) {

    $pathinfo = pathinfo( $file['name'] );
    $ext      = isset( $pathinfo['extension'] ) ? strtolower( $pathinfo['extension'] ) : '';

    if ( $ext !== 'zip' ) {
        return $file; // ignore other file types
    }

    $name   = $pathinfo['filename'];
    $suffix = wp_generate_password( 32, false, false );

    $file['name'] = $name . '_' . $suffix . '.zip';

    return $file;
});

/**
 * Add a custom column (Download ID) to the 'downloads' post type list,
 * and display a clipboard icon for copying.
 */

// 1. Add a new column
add_filter( 'manage_edit-downloads_columns', 'my_custom_downloads_columns' );
function my_custom_downloads_columns( $columns ) {
    // Insert a new column titled "Download ID" after the Title column
    $new_columns = array();
    foreach ( $columns as $key => $value ) {
        $new_columns[ $key ] = $value;
        if ( 'title' === $key ) {
            $new_columns['download_id'] = __( 'Download ID', 'text_domain' );
        }
    }
    return $new_columns;
}

// 2. Populate the column with the post ID and a clipboard icon button
add_action( 'manage_downloads_posts_custom_column', 'my_custom_downloads_column_content', 10, 2 );
function my_custom_downloads_column_content( $column, $post_id ) {
    if ( 'download_id' === $column ) {
        // Display the post ID with a button to copy
        echo '<span class="download-id" style="margin-right:10px;">' . esc_html( $post_id ) . '</span>';
        // Use dashicons for the clipboard icon
        echo '<button class="copy-btn" data-clipboard-text="' . esc_attr( $post_id ) . '" title="' . esc_attr__( 'Copy ID', 'text_domain' ) . '">';
        echo '<span class="dashicons dashicons-clipboard"></span>';
        echo '</button>';
    }
}

// 3. Enqueue a small script for copying functionality
add_action( 'admin_footer', 'my_downloads_id_copy_script' );
function my_downloads_id_copy_script() {
    // Only load this script on the "downloads" listing screen
    $screen = get_current_screen();
    if ( isset( $screen->id ) && 'edit-downloads' === $screen->id ) :
        // Make sure Dashicons are available (usually they are in the admin, but just in case):
        wp_enqueue_style( 'dashicons' );
        ?>
        <script type="text/javascript">
        (function() {
            document.addEventListener('DOMContentLoaded', function() {
                document.querySelectorAll('.copy-btn').forEach(function(button) {
                    button.addEventListener('click', function() {
                        var text = button.getAttribute('data-clipboard-text');
                        if (navigator.clipboard) {
                            navigator.clipboard.writeText(text).then(function() {
                                showCopiedState(button);
                            });
                        } else {
                            // Fallback for older browsers
                            var tempInput = document.createElement('input');
                            tempInput.style.position = 'absolute';
                            tempInput.style.left = '-9999px';
                            tempInput.value = text;
                            document.body.appendChild(tempInput);
                            tempInput.select();
                            document.execCommand('copy');
                            document.body.removeChild(tempInput);
                            showCopiedState(button);
                        }
                    });
                });

                function showCopiedState(button) {
                    // Temporarily show "Copied!" text
                    button.innerHTML = 'Copied!';
                    setTimeout(function() {
                        // Revert to the clipboard icon
                        button.innerHTML = '<span class="dashicons dashicons-clipboard"></span>';
                    }, 2000);
                }
            });
        })();
        </script>
        <?php
    endif;
}
/**
 * 1) Remove the default Title column in the 'hoster_license' post type list.
 * 2) Add a new "Licence Key" column (showing the license_key meta) that links to edit
 *    and has a copy button for the post ID.
 * 3) Restore the download, user, and status columns (if defined by the plugin).
 * 4) Add Activation Limit and Expiry Date columns.
 * 5) Adjust column widths via CSS.
 */

/**
 * Modify columns for the hoster_license post type.
 */
add_filter( 'manage_edit-hoster_license_columns', 'custom_hoster_license_columns' );
function custom_hoster_license_columns( $columns ) {

    // Store references to the columns we want to keep
    $cb      = isset( $columns['cb'] )      ? $columns['cb']      : '';
    $download= isset( $columns['download'] )? $columns['download'] : '';
    $user    = isset( $columns['user'] )    ? $columns['user']    : '';
    $status  = isset( $columns['status'] )  ? $columns['status']  : '';
    $date    = isset( $columns['date'] )    ? $columns['date']    : '';

    // Build a fresh set of columns
    $new_columns = array();

    // 1. Checkbox (bulk actions)
    if ( $cb ) {
        $new_columns['cb'] = $cb;
    }

    // 2. Our new "Licence Key" column (replaces the default Title)
    $new_columns['licence_key'] = __( 'Licence Key', 'text_domain' );

    // 3. Restore the 'download', 'user', and 'status' columns if they exist
    if ( $download ) {
        $new_columns['download'] = $download;
    }
    if ( $user ) {
        $new_columns['user'] = $user;
    }
    if ( $status ) {
        $new_columns['status'] = $status;
    }

    // 4. Activation Limit
    $new_columns['activation_limit'] = __( 'Activation Limit', 'text_domain' );

    // 5. Expiry Date
    $new_columns['expiry_date'] = __( 'Expiry Date', 'text_domain' );

    // 6. Date (Published)
    if ( $date ) {
        $new_columns['date'] = $date;
    }

    return $new_columns;
}

/**
 * Populate the custom columns, including the new Licence Key column.
 */
add_action( 'manage_hoster_license_posts_custom_column', 'custom_hoster_license_column_content', 10, 2 );
function custom_hoster_license_column_content( $column, $post_id ) {
    switch ( $column ) {

        case 'licence_key':
            // Retrieve the license_key meta
            $license_key = get_post_meta( $post_id, 'license_key', true );

            // Fallback if empty (optional)
            if ( empty( $license_key ) ) {
                $license_key = __( '(No Licence Key)', 'text_domain' );
            }

            // Create a link to edit the post, but show the license_key text
            $edit_link = get_edit_post_link( $post_id );

            // The clickable licence_key linking to edit
            echo '<strong><a class="row-title" href="' . esc_url( $edit_link ) . '">'
                 . esc_html( $license_key ) . '</a></strong>';

            // Add a copy button (with stopPropagation so it doesn't click the link)
            // Instead of copying the post ID, copy the license key
            echo ' <button onclick="event.stopPropagation();" class="copy-btn" 
                data-clipboard-text="' . esc_attr( $license_key ) . '" 
                title="' . esc_attr__( 'Copy Licence Key', 'text_domain' ) . '" 
                style="border:none; background:none; cursor:pointer;">
                <span class="dashicons dashicons-clipboard"></span>
                </button>';
            break;

        case 'activation_limit':
            $limit = get_post_meta( $post_id, 'activation_limit', true );
            echo ( intval( $limit ) < 1 ) ? 'Unlimited' : esc_html( $limit );
            break;

        case 'expiry_date':
            $date = get_post_meta( $post_id, 'expiry_date', true );
            if ( empty( $date ) ) {
                echo 'Lifetime';
            } else {
                // Format date as dd/mm/yyyy (UK format)
                echo date( 'd/m/Y', strtotime( $date ) );
            }
            break;

        // For download, user, and status, if they're restored from the plugin,
        // the plugin's own column logic should handle them automatically.
        // If you need custom output, handle them here as well.
    }
}

/**
 * Enqueue the script for copy functionality on the hoster_license listing screen.
 */
add_action( 'admin_footer', 'hoster_license_copy_script' );
function hoster_license_copy_script() {
    $screen = get_current_screen();
    if ( isset( $screen->id ) && 'edit-hoster_license' === $screen->id ) :
        // Ensure Dashicons are available
        wp_enqueue_style( 'dashicons' );
        ?>
        <script type="text/javascript">
        (function() {
            document.addEventListener('DOMContentLoaded', function() {
                document.querySelectorAll('.copy-btn').forEach(function(button) {
                    button.addEventListener('click', function(e) {
                        e.preventDefault();
                        var text = button.getAttribute('data-clipboard-text');
                        if (navigator.clipboard) {
                            navigator.clipboard.writeText(text).then(function() {
                                showCopiedState(button);
                            });
                        } else {
                            // Fallback for older browsers
                            var tempInput = document.createElement('input');
                            tempInput.style.position = 'absolute';
                            tempInput.style.left = '-9999px';
                            tempInput.value = text;
                            document.body.appendChild(tempInput);
                            tempInput.select();
                            document.execCommand('copy');
                            document.body.removeChild(tempInput);
                            showCopiedState(button);
                        }
                    });
                });
                function showCopiedState(button) {
                    var original = button.innerHTML;
                    button.innerHTML = 'Copied!';
                    setTimeout(function() {
                        button.innerHTML = original;
                    }, 2000);
                }
            });
        })();
        </script>
        <?php
    endif;
}

/**
 * Adjust column widths on the hoster_license listing screen.
 */
add_action( 'admin_head', 'hoster_license_column_width_css' );
function hoster_license_column_width_css() {
    $screen = get_current_screen();
    if ( 'edit-hoster_license' === $screen->id ) {
        ?>
        <style>
            /* Make our custom Licence Key column wider */
            .wp-list-table .column-licence_key {
                width: 200px; /* Adjust as desired */
            }
            /* Narrower columns for download, user, status (if they exist) */
            .wp-list-table .column-download {
                width: 200px; /* Adjust as desired */
            }
            .wp-list-table .column-user {
                width: 150px; /* Adjust as desired */
            }
            .wp-list-table .column-status {
                width: 150px; /* Adjust as desired */
            }
            /* Activation Limit and Expiry Date narrower as well */
            .wp-list-table .column-activation_limit {
                width: 150px; /* Adjust as desired */
            }
            .wp-list-table .column-expiry_date {
                width: 120px; /* Adjust as desired */
            }
        </style>
        <?php
    }
}
