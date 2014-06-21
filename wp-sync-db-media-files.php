<?php
/*
Plugin Name: WP Sync DB Media Files
Description: An extension of WP Sync DB that allows the migration of media files.
Author: Sean Lang
Version: 1.1
Author URI: http://slang.cx
GitHub Plugin URI: wp-sync-db/wp-sync-db-media-files
*/

function wp_sync_db_media_files_init() {
	if ( ! is_admin() || ! class_exists( 'WPSDB_Addon' ) ) return;

	require_once 'class/wpsdb-media-files.php';

	global $wpsdb_media_files;
	$wpsdb_media_files = new WPSDB_Media_Files( __FILE__ );
}

add_action( 'admin_init', 'wp_sync_db_media_files_init' );
