<?php 

// This filter increases the max downloads to unlimited but could be adjusted to 10, 1000,, 10000
add_filter( 'hoster_max_downloads', function( $value ) {
    return PHP_INT_MAX; // effectively unlimited if you want to limit say to 100 then set it to 200 instead of PHP_INT_MAX;
});


// This appends a 32-digit random number to all uploads of ZIP making them harder to guess.
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
