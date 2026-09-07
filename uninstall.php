<?php
// Runs when the plugin is deleted from the admin: drop the API key and the upload cache.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'fakturaonline_settings' );
delete_option( 'fakturaonline_uploads' );
