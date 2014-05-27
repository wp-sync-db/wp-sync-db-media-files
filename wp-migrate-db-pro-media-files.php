<?php
/*
Plugin Name: WP Migrate DB Pro Media Files
Plugin URI: http://deliciousbrains.com/wp-migrate-db-pro/
Description: An extension to WP Migrate DB Pro, allows the migration of media files.
Author: Delicious Brains
Version: 1.1.3
Author URI: http://deliciousbrains.com
Network: True
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

$GLOBALS['wpmdb_meta']['wp-migrate-db-pro-media-files']['version'] = '1.1.3';
$GLOBALS['wpmdb_meta']['wp-migrate-db-pro-media-files']['folder'] = basename( plugin_dir_path( __FILE__ ) );

function wp_migrate_db_pro_media_files_init() {
	if ( ! is_admin() || ! class_exists( 'WPMDBPro_Addon' ) ) return;

	require_once 'class/wpmdbpro-media-files.php';

	global $wpmdbpro_media_files;
	$wpmdbpro_media_files = new WPMDBPro_Media_Files( __FILE__ );
}

add_action( 'admin_init', 'wp_migrate_db_pro_media_files_init' );