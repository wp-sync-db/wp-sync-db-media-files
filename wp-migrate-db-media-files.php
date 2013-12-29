<?php
/*
Plugin Name: WP Migrate DB Media Files
Plugin URI: http://deliciousbrains.com/wp-migrate-db-pro/
Description: An extension to WP Migrate DB, allows the migration of media files.
Author: Delicious Brains
Version: 1.0
Author URI: http://deliciousbrains.com
*/

// Copyright (c) 2013 Delicious Brains. All rights reserved.
//
// Released under the GPL license
// http://www.opensource.org/licenses/gpl-license.php
//
// **********************************************************************
// This program is distributed in the hope that it will be useful, but
// WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
// **********************************************************************

function wp_migrate_db_media_files_init() {
	if ( ! is_admin() || ! class_exists( 'WPMDB_Addon' ) ) return;

	require_once 'class/wpmdb-media-files.php';

	global $wpmdb_media_files;
	$wpmdb_media_files = new WPMDB_Media_Files( __FILE__ );
}

add_action( 'admin_init', 'wp_migrate_db_media_files_init' );
