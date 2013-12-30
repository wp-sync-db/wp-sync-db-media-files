<?php
/*
Plugin Name: WP Migrate DB Media Files
Description: An extension of WP Migrate DB that allows the migration of media files.
Author: Sean Lang
Version: 1.0
Author URI: http://slang.cx
GitHub Plugin URI: slang800/wp-migrate-db-media-files
*/

function wp_migrate_db_media_files_init() {
	if ( ! is_admin() || ! class_exists( 'WPMDB_Addon' ) ) return;

	require_once 'class/wpmdb-media-files.php';

	global $wpmdb_media_files;
	$wpmdb_media_files = new WPMDB_Media_Files( __FILE__ );
}

add_action( 'admin_init', 'wp_migrate_db_media_files_init' );
