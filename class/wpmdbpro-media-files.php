<?php

class WPMDBPro_Media_Files extends WPMDBPro_Addon {
	protected $files_to_migrate;
	protected $responding_to_get_remote_media_listing = false;
	protected $media_diff_batch_time;
	protected $media_diff_batch_limit;
	protected $media_files_batch_time_limit;
	protected $media_strings;


	function __construct( $plugin_file_path ) {
		parent::__construct( $plugin_file_path );

		$this->plugin_slug    = 'wp-migrate-db-pro-media-files';
		$this->plugin_version = $GLOBALS['wpmdb_meta']['wp-migrate-db-pro-media-files']['version'];

		if ( ! $this->meets_version_requirements( '1.4.6' ) ) {
			return;
		}

		$this->media_diff_batch_time  = apply_filters( 'wpmdb_media_diff_batch_time', 10 ); //seconds
		$this->media_diff_batch_limit = apply_filters( 'wpmdb_media_diff_batch_limit', 500 ); //number of attachments
		$this->media_files_batch_time_limit = apply_filters( 'wpmdb_media_files_batch_time_limit', 15 ); //seconds

		add_action( 'wpmdb_after_advanced_options', array( $this, 'migration_form_controls' ) );
		add_action( 'wpmdb_load_assets', array( $this, 'load_assets' ) );
		add_action( 'wpmdb_js_variables', array( $this, 'js_variables' ) );
		add_action( 'wpmdb_diagnostic_info', array( $this, 'diagnostic_info' ) );
		add_action( 'wpmdb_template_progress_after_bar', array( $this, 'progress_template' ) );
		add_filter( 'wpmdb_accepted_profile_fields', array( $this, 'accepted_profile_fields' ) );
		add_filter( 'wpmdb_establish_remote_connection_data', array( $this, 'establish_remote_connection_data' ) );
		add_filter( 'wpmdb_nonces', array( $this, 'add_nonces' ) );

		// internal AJAX handlers
		add_action( 'wp_ajax_wpmdbmf_remove_files_recursive', array( $this, 'ajax_remove_files_recursive' ) );
		add_action( 'wp_ajax_wpmdbmf_prepare_determine_media', array( $this, 'ajax_prepare_determine_media' ) );
		add_action( 'wp_ajax_wpmdbmf_determine_media_to_migrate_recursive', array( $this, 'ajax_determine_media_to_migrate_recursive' ) );
		add_action( 'wp_ajax_wpmdbmf_migrate_media', array( $this, 'ajax_migrate_media' ) );

		// external AJAX handlers
		add_action( 'wp_ajax_nopriv_wpmdbmf_get_remote_media_info', array( $this, 'respond_to_get_remote_media_info' ) );
		add_action( 'wp_ajax_nopriv_wpmdbmf_get_remote_attachment_batch', array( $this, 'respond_to_get_remote_attachment_batch' ) );
		add_action( 'wp_ajax_nopriv_wpmdbmf_compare_remote_attachments', array( $this, 'respond_to_compare_remote_attachments' ) );
		add_action( 'wp_ajax_nopriv_wpmdbmf_push_request', array( $this, 'respond_to_push_request' ) );
		add_action( 'wp_ajax_nopriv_wpmdbmf_get_local_media_files_batch', array( $this, 'respond_to_get_local_media_files_batch' ) );
		add_action( 'wp_ajax_nopriv_wpmdbmf_compare_local_media_files', array( $this, 'respond_to_compare_local_media_files' ) );
		add_action( 'wp_ajax_nopriv_wpmdbmf_remove_local_media_files', array( $this, 'respond_to_remove_local_media_files' ) );
	}

	/**
	 * Wrapper for retrieving attachments
	 *
	 * @param        $prefix
	 * @param string $result_type 'count', 'rows', 'row'
	 * @param array  $args Dependant of $result_type.
	 *                     'count'  - none
	 *                     'rows'   - $blog_id, $offset, $limit
	 *                     'row'    - $blog_id, $filename
	 *
	 * @return array
	 */
	function get_attachment_results( $prefix, $result_type = 'rows', $args = array() ) {
		global $wpdb;

		$core =
			" FROM `{$prefix}posts`
			INNER JOIN `{$prefix}postmeta` pm1 ON `{$prefix}posts`.`ID` = pm1.`post_id` AND pm1.`meta_key` = '_wp_attached_file'
			LEFT OUTER JOIN `{$prefix}postmeta` pm2 ON `{$prefix}posts`.`ID` = pm2.`post_id` AND pm2.`meta_key` = '_wp_attachment_metadata'
			WHERE `{$prefix}posts`.`post_type` = 'attachment' ";

		if ( 'count' == $result_type ) {
			$sql = 'SELECT COUNT(*)' . $core;

			return $wpdb->get_var( $sql );
		}

		$select = "SELECT `{$prefix}posts`.`ID` AS 'ID', `{$prefix}posts`.`post_modified_gmt` AS 'date', pm1.`meta_value` AS 'file', pm2.`meta_value` AS 'metadata', %d AS 'blog_id'";
		$sql = $select . $core;

		if ( 'rows' == $result_type ) {
			$action = 'get_results';
			$sql .=
				"AND `{$prefix}posts`.`ID` > %d
				ORDER BY `{$prefix}posts`.`ID`
				LIMIT %d";
		} else {
			$action = 'get_row';
			$sql .= 'AND pm1.`meta_value` = %s';
		}

		$sql = $wpdb->prepare( $sql, $args );

		$results = $wpdb->$action( $sql, ARRAY_A );

		return $results;
	}

	/**
	 * Get attachments for a blog
	 *
	 * @param string $prefix
	 * @param int $blog Blog ID
	 * @param int $limit
	 * @param int $offset Post ID to query from
	 *
	 * @return mixed
	 */
	function get_attachments( $prefix, $blog, $limit, $offset ) {
		if ( 1 != $blog ) {
			$prefix = $prefix . $blog . '_';
		}

		return $this->get_attachment_results( $prefix, 'rows', array( $blog, $offset, $limit ) );
	}

	/**
	 * Find an attachment in a specific blog
	 *
	 * @param array $attachment
	 * @param string $prefix
	 * @param array $local_blog_ids
	 *
	 * @return array|bool|mixed
	 */
	function find_attachment( $attachment, $prefix, $local_blog_ids ) {
		if ( 1 != $attachment['blog_id'] ) {
			// check the blog exists
			if ( ! in_array( $attachment['blog_id'], $local_blog_ids ) ) {
				return false;
			}
			$prefix = $prefix . $attachment['blog_id'] . '_';
		}

		$filename = $attachment['file'];
		if ( 1 != $attachment['blog_id'] ) {
			// if not default blog strip the site dir prefix from the filename for searching
			$site_prefix = $this->get_dir_prefix( $attachment );
			$filename    = str_replace( $site_prefix, '', $filename );
		}

		$local_attachment = $this->get_attachment_results( $prefix, 'row', array( $attachment['blog_id'], $filename ) );

		if ( empty( $local_attachment ) ) {
			return false;
		}

		$local_attachment = $this->process_attachment_data( $local_attachment );

		return $local_attachment;
	}

	/**
	 * Return a batch of attachments across all blogs
	 *
	 * @param $blogs
	 * @param $limit
	 * @param $prefix
	 *
	 * @return array
	 */
	function get_local_attachments_batch( $blogs, $limit, $prefix ) {
		$all_limit = $limit;

		if ( ! is_array( $blogs ) ) {
			$blogs = unserialize( stripslashes( $blogs ) );
		}

		$all_attachments = array();
		$all_count       = 0;

		foreach ( $blogs as $blog_id => $blog ) {
			if ( 1 == $blog['processed'] ) {
				continue;
			}

			$attachments = $this->get_attachments( $prefix, $blog_id, $limit, $blog['last_post'] );
			$count       = count( $attachments );

			if ( 0 == $count ) {
				// no more attachments, record the blog ID to skip next time
				$blogs[ $blog_id ]['processed'] = 1;
			} else {
				$all_count += $count;
				// process attachments for sizes files
				$attachments = array_map( array( $this, 'process_attachment_data' ), $attachments );
				$attachments = array_filter( $attachments );

				$all_attachments[ $blog_id ] = $attachments;
			}

			if ( $all_count >= $all_limit ) {
				break;
			}

			$limit = $limit - $count;
		}

		$return = array(
			'attachments' => $all_attachments,
			'blogs'       => $blogs,
		);

		return $return;
	}

	/**
	 * Return total number of local attachments
	 *
	 * @param string $prefix
	 *
	 * @return int
	 */
	function get_local_attachments_count( $prefix = null ) {
		if ( is_null( $prefix ) ) {
			global $wpdb;
			$prefix = $wpdb->prefix;
		}
		$count = 0;
		$count += $this->get_attachments_count( $prefix );
		if ( is_multisite() ) {
			$blogs = $this->get_blog_ids();
			foreach ( $blogs as $blog ) {
				$blog_prefix = $prefix . $blog . '_';
				$count += $this->get_attachments_count( $blog_prefix );
			}
		}

		return $count;
	}

	/**
	 * Retrieve the count of attachments for a blog
	 *
	 * @param string $prefix
	 *
	 * @return int
	 */
	function get_attachments_count( $prefix ) {
		return $this->get_attachment_results( $prefix, 'count' );
	}

	/**
	 * Get the directory prefix for a file for multisite installs
	 *
	 * @param $attachment
	 *
	 * @return string
	 */
	function get_dir_prefix( $attachment ) {
		$dir_prefix = ''; // nothing for default blogs
		if ( isset( $attachment['blog_id'] ) && 1 != $attachment['blog_id'] ) { // used for multisite
			if ( defined( 'UPLOADBLOGSDIR' ) ) {
				$dir_prefix = sprintf( '%s/files/', $attachment['blog_id'] );
			} else {
				$dir_prefix = sprintf( 'sites/%s/', $attachment['blog_id'] );
			}
		}

		return $dir_prefix;
	}

	/**
	 * Prepare an attachment to expose any resized images
	 * and retrieve size on disk for all images
	 *
	 * @param $attachment
	 *
	 * @return mixed
	 */
	function process_attachment_data( $attachment ) {
		// get any site directory prefix for multisite blogs
		$upload_dir         = $this->get_dir_prefix( $attachment );
		$attachment['file'] = $upload_dir . $attachment['file'];
		// use the correct directory to use for image size files
		$upload_dir = str_replace( basename( $attachment['file'] ), '', $attachment['file'] );
		if ( ! empty( $attachment['metadata'] ) ) {
			$attachment['metadata'] = @unserialize( $attachment['metadata'] );
			if ( ! empty( $attachment['metadata']['sizes'] ) && is_array( $attachment['metadata']['sizes'] ) ) {
				foreach ( $attachment['metadata']['sizes'] as $size ) {
					if ( empty( $size['file'] ) ) {
						continue;
					}
					$size_data = array( 'file' => $upload_dir . $size['file'] );
					$size_data = $this->apply_file_size( $size_data );

					$attachment['sizes'][] = $size_data;
				}
			}
		}
		unset( $attachment['metadata'] );

		// get size of image on disk
		$attachment = $this->apply_file_size( $attachment );

		return $attachment;
	}

	/**
	 * Return the base uploads directory
	 *
	 * @return string
	 */
	function uploads_dir() {
		static $upload_dir;

		if ( ! is_null( $upload_dir ) ) {
			return $upload_dir;
		}

		if ( defined( 'UPLOADBLOGSDIR' ) ) {
			$upload_dir = trailingslashit( ABSPATH ) . UPLOADBLOGSDIR;
		} else {
			$upload_dir = wp_upload_dir();
			$upload_dir = $upload_dir['basedir'];
		}

		$upload_dir = trailingslashit( $upload_dir );

		return $upload_dir;
	}

	/**
	 * Finds and store the size on disk for a file array
	 * Used for attachments files and resized images files
	 *
	 * @param $attachment
	 *
	 * @return mixed
	 */
	function apply_file_size( $attachment ) {
		if ( ! isset( $attachment['file'] ) ) {
			return $attachment;
		}
		// get size of image on disk
		$size = $this->get_file_size( $attachment['file'] );
		if ( false !== $size ) {
			$attachment['file_size'] = $size;
		}

		return $attachment;
	}

	/**
	 * Calculated size on disk of a file if it exists
	 *
	 * @param $file
	 *
	 * @return bool|int
	 */
	function get_file_size( $file ) {
		$upload_dir = untrailingslashit( $this->uploads_dir() );
		if ( ! file_exists( $upload_dir ) ) {
			return false;
		}
		$file = $upload_dir . DIRECTORY_SEPARATOR . $file;
		if ( ! file_exists( $file ) ) {
			return false;
		}

		return filesize( $file );
	}

	/**
	 * AJAX callback for returning a batch of local media files
	 *
	 * @scope remote
	 *
	 * @return bool|null
	 */
	function respond_to_get_local_media_files_batch() {
		$filtered_post = $this->filter_post_elements( $_POST, array(
			'action',
			'compare',
			'offset',
		) );
		if ( ! $this->verify_signature( $filtered_post, $this->settings['key'] ) ) {
			$return = array(
				'wpmdb_error' => 1,
				'body'        => $this->invalid_content_verification_error . ' (#109mf)',
			);
			$this->log_error( $return['body'], $filtered_post );
			$result = $this->end_ajax( serialize( $return ) );

			return $result;
		}

		$offset = isset( $filtered_post['offset'] ) ? $filtered_post['offset'] : '0';

		$local_media_files = $this->get_local_media_files_batch( $offset );

		$return = array(
			'success'         => 1,
			'local_media_files' => $local_media_files,
		);

		$result = $this->end_ajax( serialize( $return ) );

		return $result;
	}

	/**
	 * Wrapper for getting a batch of local media files
	 *
	 * @param string $start_file The file or directory to start at
	 *
	 * @return array
	 */
	function get_local_media_files_batch( $start_file ) {
		$local_media_files = array();

		$upload_dir = $this->uploads_dir();

		if ( ! file_exists( $upload_dir ) ) {
			return $local_media_files;
		}

		// Check if we're just kicking off with the root uploads dir
		if ( empty( $start_file ) ) {
			$this->get_local_media_files_batch_recursive( '', '', $local_media_files );
		} else {
			$dir = dirname( $start_file );
			$start_filename = basename( $start_file );
			$this->get_local_media_files_batch_recursive( trailingslashit( $dir ), $start_filename, $local_media_files );

			$dirs = explode( '/', $dir );
			while ( $dirs ) {
				$start_filename = array_pop( $dirs );
				$dir = trailingslashit( implode( '/', $dirs ) );
				$this->get_local_media_files_batch_recursive( $dir, $start_filename, $local_media_files );
			}
		}

		return $local_media_files;
	}

	/**
	 * Recursively go through uploads directories and get a batch of media files.
	 * Stops when it has scanned all files/directories or after it has run for
	 * $this->media_files_batch_time_limit seconds, whichever comes first.
	 *
	 * @param string $dir The directory to start in
	 * @param string $start_filename The file or directory to start at within $dir
	 * @param array $local_media_files Array to populate with media files found
	 *
	 * @return void
	 */
	function get_local_media_files_batch_recursive( $dir, $start_filename, &$local_media_files ) {
		$upload_dir = $this->uploads_dir();

		static $allowed_mime_types;
		if ( is_null( $allowed_mime_types ) ) {
			$allowed_mime_types = array_flip( get_allowed_mime_types() );
		}

		static $finish_time;
		if ( is_null( $finish_time ) ) {
			$finish_time = microtime( true ) + $this->media_files_batch_time_limit;
		}

		$dir = ( '/' == $dir ) ? '' : $dir;
		$dir_path = $upload_dir . $dir;

		$files = glob( $dir_path . '*', GLOB_MARK );

		$reached_start_file = false;

		foreach ( $files as $file_path ) {
			if ( microtime( true ) >= $finish_time ) {
				break;
			}

			// Are we starting from a certain file within the directory?
			// If so, we skip all the files that come before it.
			if ( $start_filename ) {
				if ( basename( $file_path ) == $start_filename ) {
					$reached_start_file = true;
					continue;
				} elseif ( ! $reached_start_file ) {
					continue;
				}
			}

			$short_file_path = str_replace( array( $upload_dir, '\\' ), array( '', '/' ), $file_path );

			// Is directory? We use this instead of is_dir() to save us an I/O call
			if ( substr( $file_path, -1 ) == DIRECTORY_SEPARATOR ) {
				$this->get_local_media_files_batch_recursive( $short_file_path, '', $local_media_files );
				continue;
			}

			// ignore files that we shouldn't touch, e.g. .php, .sql, etc
			$filetype = wp_check_filetype( $short_file_path );
			if ( ! isset( $allowed_mime_types[ $filetype['type'] ] ) ) {
				continue;
			}

			$local_media_files[] = $short_file_path;
		}
	}

	/**
	 * AJAX wrapper for the push/pull migration of media files,
	 *
	 * @return bool|null
	 */
	function ajax_migrate_media() {
		$this->check_ajax_referer( 'migrate-media' );
		$this->set_time_limit();

		if ( 'pull' == $_POST['intent'] ) {
			$result = $this->process_pull_request();
		} else {
			$result = $this->process_push_request();
		}

		return $result;
	}

	/**
	 * Download files from the remote site
	 *
	 * @return bool|null
	 */
	function process_pull_request() {
		$files_to_download  = $_POST['file_chunk'];
		$remote_uploads_url = trailingslashit( $_POST['remote_uploads_url'] );
		$parsed             = $this->parse_url( $_POST['url'] );
		if ( ! empty( $parsed['user'] ) ) {
			$credentials        = sprintf( '%s:%s@', $parsed['user'], $parsed['pass'] );
			$remote_uploads_url = str_replace( '://', '://' . $credentials, $remote_uploads_url );
		}

		$upload_dir = $this->uploads_dir();

		$errors = array();
		foreach ( $files_to_download as $file_to_download ) {
			$temp_file_path = $this->download_url( $remote_uploads_url . $file_to_download );

			if ( is_wp_error( $temp_file_path ) ) {
				$download_error = $temp_file_path->get_error_message();
				$errors[]       = __( sprintf( 'Could not download file: %1$s - %2$s', $remote_uploads_url . $file_to_download, $download_error ), 'wp-migrate-db-pro-media-files' );
				continue;
			}

			$date     = str_replace( basename( $file_to_download ), '', $file_to_download );
			$new_path = $upload_dir . $date . basename( $file_to_download );

			$move_result = @rename( $temp_file_path, $new_path );

			if ( false === $move_result ) {
				$folder = dirname( $new_path );
				if ( @file_exists( $folder ) ) {
					$errors[] = __( sprintf( 'Error attempting to move downloaded file. Temp path: %1$s - New Path: %2$s', $temp_file_path, $new_path ), 'wp-migrate-db-pro-media-files' ) . ' (#103mf)';
				} else {
					if ( false === @mkdir( $folder, 0755, true ) ) {
						$errors[] = __( sprintf( 'Error attempting to create required directory: %s', $folder ), 'wp-migrate-db-pro-media-files' ) . ' (#104mf)';
					} else {
						$move_result = @rename( $temp_file_path, $new_path );
						if ( false === $move_result ) {
							$errors[] = __( sprintf( 'Error attempting to move downloaded file. Temp path: %1$s - New Path: %2$s', $temp_file_path, $new_path ), 'wp-migrate-db-pro-media-files' ) . ' (#105mf)';
						}
					}
				}
			}
		}

		$return = array( 'success' => 1 );

		if ( ! empty( $errors ) ) {
			$return['wpmdb_non_fatal_error'] = 1;

			$return['cli_body'] = $errors;
			$return['body']     = implode( '<br />', $errors ) . '<br />';
			$error_msg          = __( 'Failed attempting to process pull request', 'wp-migrate-db-pro-media-files' ) . ' (#112mf)';
			$this->log_error( $error_msg, $errors );
		}

		$result = $this->end_ajax( json_encode( $return ) );

		return $result;
	}

	/**
	 * Upload files to the remote site
	 *
	 * @return bool|null
	 */
	function process_push_request() {
		$files_to_migrate = $_POST['file_chunk'];

		$upload_dir = $this->uploads_dir();

		$body = '';
		foreach ( $files_to_migrate as $file_to_migrate ) {
			$body .= $this->file_to_multipart( $upload_dir . $file_to_migrate );
		}

		$post_args = array(
			'action' => 'wpmdbmf_push_request',
			'files'  => serialize( $files_to_migrate )
		);

		$post_args['sig'] = $this->create_signature( $post_args, $_POST['key'] );

		$body .= $this->array_to_multipart( $post_args );

		$args['body'] = $body;
		$ajax_url     = trailingslashit( $_POST['url'] ) . 'wp-admin/admin-ajax.php';
		$response     = $this->remote_post( $ajax_url, '', __FUNCTION__, $args );
		$response     = $this->verify_remote_post_response( $response );
		if ( isset( $response['wpmdb_error'] ) ) {
			return $response;
		}

		$result = $this->end_ajax( json_encode( $response ) );

		return $result;
	}

	/**
	 * Move uploaded local site files from tmp to uploads directory
	 *
	 * @scope remote
	 *
	 * @return bool|null
	 */
	function respond_to_push_request() {
		$filtered_post          = $this->filter_post_elements( $_POST, array( 'action', 'files' ) );
		$filtered_post['files'] = stripslashes( $filtered_post['files'] );
		if ( ! $this->verify_signature( $filtered_post, $this->settings['key'] ) ) {
			$return = array(
				'wpmdb_error' => 1,
				'body'        => $this->invalid_content_verification_error . ' (#111mf)',
			);
			$this->log_error( $return['body'], $filtered_post );
			$result = $this->end_ajax( serialize( $return ) );

			return $result;
		}

		if ( ! isset( $_FILES['media'] ) ) {
			$return = array(
				'wpmdb_error' => 1,
				'body'        => __( '$_FILES is empty, the upload appears to have failed', 'wp-migrate-db-pro-media-files' ) . ' (#106mf)',
			);
			$this->log_error( $return['body'] );
			$result = $this->end_ajax( serialize( $return ) );

			return $result;
		}

		$upload_dir = $this->uploads_dir();

		$files      = $this->diverse_array( $_FILES['media'] );
		$file_paths = unserialize( $filtered_post['files'] );
		$i          = 0;
		$errors     = array();
		foreach ( $files as &$file ) {
			$destination = $upload_dir . $file_paths[ $i ];
			$folder      = dirname( $destination );

			if ( false === @file_exists( $folder ) && false === @mkdir( $folder, 0755, true ) ) {
				$errors[] = __( sprintf( 'Error attempting to create required directory: %s', $folder ), 'wp-migrate-db-pro-media-files' ) . ' (#108mf)';
				++ $i;
				continue;
			}

			if ( false === @move_uploaded_file( $file['tmp_name'], $destination ) ) {
				$errors[] = __( sprintf( 'A problem occurred when attempting to move the temp file "%1$s" to "%2$s"', $file['tmp_name'], $destination ), 'wp-migrate-db-pro-media-files' ) . ' (#107mf)';
			}
			++ $i;
		}

		$return = array( 'success' => 1 );

		if ( ! empty( $errors ) ) {
			$return['wpmdb_non_fatal_error'] = 1;

			$return['cli_body'] = $errors;
			$return['body']     = implode( '<br />', $errors ) . '<br />';
			$error_msg          = __( 'Failed attempting to respond to push request', 'wp-migrate-db-pro-media-files' ) . ' (#113mf)';
			$this->log_error( $error_msg, $errors );
		}

		$result = $this->end_ajax( serialize( $return ) );

		return $result;
	}

	/**
	 *  AJAX recursive request to remove all media files if skipping comparison in batches
	 *
	 * @return bool|null
	 */
	function ajax_remove_files_recursive() {
		$this->check_ajax_referer( 'remove-files-recursive' );
		$this->set_time_limit();

		$compare = $_POST['compare'];
		$offset  = $_POST['offset'];

		if ( 'pull' == $_POST['intent'] ) {
			// send batch of files to be compared on the remote
			// receive batch of files to be deleted
			$return = $this->remove_local_files_recursive( $_POST['url'], $_POST['key'], $compare, $offset );
		} else {
			// request a batch from the remote
			// compare received batch of files with local filesystem
			// send files to be deleted to the remote for deletion
			$return = $this->remove_remote_files_recursive( $_POST['url'], $_POST['key'], $compare, $offset );
		}

		// persist the comparison flag across recursive requests
		$return['compare'] = $compare;

		$result = $this->end_ajax( json_encode( $return ) );

		return $result;
	}

	/**
	 * Removal of local media files in batches that can be called recursively
	 * PULL requests
	 *
	 * @param        $remote_url
	 * @param        $remote_key
	 * @param        $compare_with_remote 1 = Will compare files existence on remote, 0 = no comparison
	 * @param string $start_file              Last file in previous batch to start this batch from
	 *
	 * @return array
	 */
	function remove_local_files_recursive( $remote_url, $remote_key, $compare_with_remote, $start_file = '0' ) {
		// get batch of local files
		$local_media_files = $this->get_local_media_files_batch( $start_file );

		if ( ! $local_media_files ) {
			return array( 'offset' => '', 'remove_files' => 0 );
		}

		if ( "1" == $compare_with_remote ) {
			// send batch of files to be compared on the remote
			$data           = array();
			$data['action'] = 'wpmdbmf_compare_local_media_files';
			$data['files']  = serialize( $local_media_files );
			$data['sig']    = $this->create_signature( $data, $remote_key );
			$ajax_url       = trailingslashit( $remote_url ) . 'wp-admin/admin-ajax.php';
			$response       = $this->remote_post( $ajax_url, $data, __FUNCTION__ );
			$response       = $this->verify_remote_post_response( $response );
			if ( isset( $response['wpmdb_error'] ) ) {
				return $response;
			}
			// files that don't exist returned as new batch to delete
			$files_to_remove  = isset( $response['files_to_remove'] ) ? $response['files_to_remove'] : array();
		} else {
			$files_to_remove = $local_media_files;
		}

		$errors = $this->remove_local_media_files( $files_to_remove );

		$return =  array(
			'offset' => end( $local_media_files ),
			'remove_files' => 1,
		);

		if ( ! empty( $errors ) ) {
			$return['wpmdb_non_fatal_error'] = 1;

			$return['cli_body'] = $errors;
			$return['body']     = implode( '<br />', $errors ) . '<br />';
			$error_msg          = __( 'There were errors when removing local media files', 'wp-migrate-db-pro-media-files' ) . ' (#123mf)';
			$this->log_error( $error_msg, $errors );
		}

		return $return;
	}

	/**
	 * Removal of remote media files in batches that can be called recursively
	 * PUSH requests
	 *
	 * @param        $remote_url
	 * @param        $remote_key
	 * @param        $compare_with_remote
	 * @param string $start_file
	 *
	 * @return array
	 */
	function remove_remote_files_recursive( $remote_url, $remote_key, $compare_with_remote, $start_file = '0' ) {
		// request a batch from the remote
		$data               = array();
		$data['action']     = 'wpmdbmf_get_local_media_files_batch';
		$data['compare']    = $compare_with_remote;
		$data['offset']     = $start_file;
		$data['sig']        = $this->create_signature( $data, $remote_key );
		$ajax_url           = trailingslashit( $remote_url ) . 'wp-admin/admin-ajax.php';
		$response           = $this->remote_post( $ajax_url, $data, __FUNCTION__ );
		$response           = $this->verify_remote_post_response( $response );
		if ( isset( $response['wpmdb_error'] ) ) {
			return $response;
		}

		$remote_media_files = $response['local_media_files'];

		if ( ! $remote_media_files ) {
			return array( 'offset' => '', 'remove_files' => 0 );
		}

		if ( "1" == $compare_with_remote ) {
			// compare received batch of files with local filesystem
			$files_to_remove = $this->get_files_not_on_local( $remote_media_files );
		} else {
			$files_to_remove = $remote_media_files;
		}

		// send files not found on local to the remote for deletion
		$data                    = array();
		$data['action']          = 'wpmdbmf_remove_local_media_files';
		$data['files_to_remove'] = serialize( $files_to_remove );
		$data['sig']             = $this->create_signature( $data, $remote_key );
		$ajax_url                = trailingslashit( $remote_url ) . 'wp-admin/admin-ajax.php';
		$response                = $this->remote_post( $ajax_url, $data, __FUNCTION__ );
		$response                = $this->verify_remote_post_response( $response );
		if ( isset( $response['wpmdb_error'] ) ) {
			return $response;
		}

		$response['offset' ] = end( $remote_media_files );
		$response['remove_files' ] = 1;

		return $response;
	}

	/**
	 * AJAX initial request before determining media to migrate
	 *
	 * @return bool|null
	 */
	function ajax_prepare_determine_media() {
		$this->check_ajax_referer( 'prepare-determine-media' );
		$this->set_time_limit();

		$data                = array();
		$data['action']      = 'wpmdbmf_get_remote_media_info';
		$data['temp_prefix'] = $this->temp_prefix;
		$data['intent']      = $_POST['intent'];
		$data['sig']         = $this->create_signature( $data, $_POST['key'] );
		$ajax_url            = trailingslashit( $_POST['url'] ) . 'wp-admin/admin-ajax.php';
		$response            = $this->remote_post( $ajax_url, $data, __FUNCTION__ );
		$response            = $this->verify_remote_post_response( $response );
		if ( isset( $response['wpmdb_error'] ) ) {
			return $response;
		}

		$return['attachment_batch_limit'] = $this->media_diff_batch_limit;
		$return['remote_uploads_url']     = $response['remote_uploads_url'];

		// determine the size of the attachments in scope for migration
		if ( 'pull' == $_POST['intent'] ) {
			$return['attachment_count'] = $response['remote_total_attachments'];
			$return['prefix']           = $response['prefix'];
			$return['blogs']            = $response['blogs'];
		} else {
			$return['prefix']           = $this->get_table_prefix();
			$return['attachment_count'] = $this->get_local_attachments_count( $return['prefix'] );
			$return['blogs']            = serialize( $this->get_blogs() );
		}

		$result = $this->end_ajax( json_encode( $return ) );

		return $result;
	}

	/**
	 * Callback used by the recursive AJAX request to determine media to migrate
	 *
	 * @return bool|null
	 */
	function ajax_determine_media_to_migrate_recursive() {
		$this->check_ajax_referer( 'determine-media-to-migrate-recursive' );
		$this->set_time_limit();

		$intent = sanitize_key( $_POST['intent'] );
		if ( ! in_array( $intent, array( 'pull', 'push' ) ) ) {
			$error_msg = __( 'Incorrect migration type supplied', 'wp-migrate-db-pro-media-files' ) . ' (#120mf)';
			$return    = array( 'wpmdb_error' => 1, 'body' => $error_msg );
			$this->log_error( $error_msg );
			$result = $this->end_ajax( json_encode( $return ) );

			return $result;
		}

		// get batch of attachments and check if they need migrating
		if ( 'pull' == $intent ) {
			// get the remote batch
			$data                           = array();
			$data['action']                 = 'wpmdbmf_get_remote_attachment_batch';
			$data['temp_prefix']            = $this->temp_prefix;
			$data['intent']                 = $intent;
			$data['sig']                    = $this->create_signature( $data, $_POST['key'] );
			$data['blogs']                  = stripslashes( $_POST['blogs'] );
			$data['prefix']                 = $_POST['prefix'];
			$data['attachment_batch_limit'] = $_POST['attachment_batch_limit'];
			$ajax_url                       = trailingslashit( $_POST['url'] ) . 'wp-admin/admin-ajax.php';
			$response                       = $this->remote_post( $ajax_url, $data, __FUNCTION__ );
			$response                       = $this->verify_remote_post_response( $response );
			if ( isset( $response['wpmdb_error'] ) ) {
				return $response;
			}

			if ( "1" == $_POST['copy_entire_media'] ) {
				// skip comparison
				$return = $this->queue_all_attachments( $response['blogs'], $response['remote_attachments'], $_POST['determine_progress'] );
			} else {
				// compare batch against local attachments
				$return = $this->compare_remote_attachments( $response['blogs'], $response['remote_attachments'], $_POST['prefix'], $_POST['determine_progress'] );
			}

		} else {
			// get the local batch
			$batch = $this->get_local_attachments_batch( $_POST['blogs'], $_POST['attachment_batch_limit'], $_POST['prefix'] );

			if ( "1" == $_POST['copy_entire_media'] ) {
				// skip comparison
				$return = $this->queue_all_attachments( $batch['blogs'], $batch['attachments'], $_POST['determine_progress'] );
			} else {
				// send batch to remote to compare against remote attachments
				$data                       = array();
				$data['action']             = 'wpmdbmf_compare_remote_attachments';
				$data['temp_prefix']        = $this->temp_prefix;
				$data['intent']             = $intent;
				$data['sig']                = $this->create_signature( $data, $_POST['key'] );
				$data['prefix']             = $_POST['prefix'];
				$data['blogs']              = serialize( $batch['blogs'] );
				$data['determine_progress'] = $_POST['determine_progress'];
				$data['remote_attachments'] = serialize( $batch['attachments'] );
				$ajax_url                   = trailingslashit( $_POST['url'] ) . 'wp-admin/admin-ajax.php';
				$response                   = $this->remote_post( $ajax_url, $data, __FUNCTION__ );
				$return                     = $this->verify_remote_post_response( $response );
				if ( isset( $return['wpmdb_error'] ) ) {
					return $return;
				}
			}
		}

		// persist settings across requests
		$return['copy_entire_media']  = $_POST['copy_entire_media'];
		$return['remove_local_media'] = $_POST['remove_local_media'];
		$return['remote_uploads_url'] = $_POST['remote_uploads_url'];
		$return['attachment_count']   = $_POST['attachment_count'];
		$return['determine_progress'] = $return['determine_progress'];
		$return['blogs']              = serialize( $return['blogs'] );
		$return['total_size']         = array_sum( $return['files_to_migrate'] );
		$return['files_to_migrate']   = $return['files_to_migrate'];

		$result = $this->end_ajax( json_encode( $return ) );

		return $result;
	}

	/**
	 * Compare posted local files with those on the remote server
	 *
	 * @scope remote
	 *
	 * @return bool|null
	 */
	function respond_to_compare_remote_attachments() {
		$filtered_post = $this->filter_post_elements( $_POST, array( 'action', 'temp_prefix', 'intent' ) );
		if ( ! $this->verify_signature( $filtered_post, $this->settings['key'] ) ) {
			$return = array(
				'wpmdb_error' => 1,
				'body'        => $this->invalid_content_verification_error . ' (#118mf)',
			);
			$this->log_error( $return['body'], $filtered_post );
			$result = $this->end_ajax( serialize( $return ) );

			return $result;
		}

		$return = $this->compare_remote_attachments( $_POST['blogs'], $_POST['remote_attachments'], $_POST['prefix'], $_POST['determine_progress'] );
		$result = $this->end_ajax( serialize( $return ) );

		return $result;
	}

	/**
	 * AJAX callback to compare a posted batch of files with those on local site
	 *
	 * @scope remote
	 *
	 * @return bool|null
	 */
	function respond_to_compare_local_media_files() {
		$filtered_post          = $this->filter_post_elements( $_POST, array( 'action', 'files' ) );
		$filtered_post['files'] = stripslashes( $filtered_post['files'] );

		if ( ! $this->verify_signature( $filtered_post, $this->settings['key'] ) ) {
			$return = array(
				'wpmdb_error' => 1,
				'body'        => $this->invalid_content_verification_error . ' (#117mf)',
			);
			$this->log_error( $return['body'], $filtered_post );
			$result = $this->end_ajax( serialize( $return ) );

			return $result;
		}

		// compare files to those on the local filesystem
		$files_to_remove = $this->get_files_not_on_local( $filtered_post['files'] );

		$return = array(
			'success'         => 1,
			'files_to_remove' => $files_to_remove
		);

		$result = $this->end_ajax( serialize( $return ) );

		return $result;
	}

	/**
	 * Compare a set of files with those on the filesystem
	 *
	 * @param $files
	 *
	 * @return array $files_to_remove Files that do not exist locally
	 */
	function get_files_not_on_local( $files ) {
		if ( ! is_array( $files ) ) {
			$files = @unserialize( $files );
		}
		$upload_dir = $this->uploads_dir();

		$files_to_remove = array();

		foreach ( $files as $file ) {
			if ( ! file_exists( $upload_dir . $file ) ) {
				$files_to_remove[] = $file;
			}
		}

		return $files_to_remove;
	}

	/**
	 * AJAX callback to remove files for the local filesystem
	 *
	 * @scope remote
	 *
	 * @return bool|null
	 */
	function respond_to_remove_local_media_files() {
		$filtered_post = $this->filter_post_elements( $_POST, array(
			'action',
			'files_to_remove'
		) );

		$filtered_post['files_to_remove'] = stripslashes( $filtered_post['files_to_remove'] );

		if ( ! $this->verify_signature( $filtered_post, $this->settings['key'] ) ) {
			$return = array(
				'wpmdb_error' => 1,
				'body'        => $this->invalid_content_verification_error . ' (#119mf)',
			);
			$this->log_error( $return['body'], $filtered_post );
			$result = $this->end_ajax( serialize( $return ) );

			return $result;
		}

		$errors = $this->remove_local_media_files( $filtered_post['files_to_remove'] );

		$return['success'] = 1;

		if ( ! empty( $errors ) ) {
			$return['wpmdb_non_fatal_error'] = 1;

			$return['cli_body'] = $errors;
			$return['body']     = implode( '<br />', $errors ) . '<br />';
			$error_msg          = __( 'There were errors when removing local media files from the remote site', 'wp-migrate-db-pro-media-files' ) . ' (#121mf)';
			$this->log_error( $error_msg, $errors );
		}

		$result = $this->end_ajax( serialize( $return ) );

		return $result;
	}

	/**
	 * Remove files if they exist in the uploads directory
	 *
	 * @param string|array $local_files
	 *
	 * @return array $errors
	 */
	function remove_local_media_files( $local_files ) {
		if ( ! is_array( $local_files ) ) {
			$local_files = @unserialize( $local_files );
		}

		$errors = array();

		if ( empty( $local_files ) ) {
			return $errors;
		}

		$upload_dir = $this->uploads_dir();

		foreach ( $local_files as $local_file ) {
			if ( false === @unlink( $upload_dir . $local_file ) ) {
				$errors[] = __( sprintf( 'Could not delete "%s"', $upload_dir . $local_file ), 'wp-migrate-db-pro-media-files' ) . ' (#122mf)';
			}
		}

		return $errors;
	}

	/**
	 * Determine the table prefix we should be using in media related queries
	 *
	 * @return string
	 */
	function get_table_prefix() {
		global $wpdb;
		$prefix      = $wpdb->prefix;
		$temp_prefix = isset( $_POST['temp_prefix'] ) ? stripslashes( $_POST['temp_prefix'] ) : '';
		$intent      = isset( $_POST['intent'] ) ? $_POST['intent'] : '';

		/*
		* We determine which media files need migrating BEFORE the database migration is finalized.
		* Because of this we need to scan the *_post & *_postmeta that are prefixed using the temporary prefix.
		* Though this should only happen when we're responding to a get_remote_media_listing() call AND it's a push OR
		* we're scanning local files AND it's a pull.
		*/

		if (
			( true == $this->responding_to_get_remote_media_listing && 'push' == $intent ) ||
			( false == $this->responding_to_get_remote_media_listing && 'pull' == $intent )
		) {

			$local_tables = array_flip( $this->get_tables() );

			$posts_table_name    = "{$temp_prefix}{$prefix}posts";
			$postmeta_table_name = "{$temp_prefix}{$prefix}postmeta";

			if ( isset( $local_tables[ $posts_table_name ] ) && isset( $local_tables[ $postmeta_table_name ] ) ) {
				$prefix = $temp_prefix . $prefix;
			}

		}

		return $prefix;
	}

	/**
	 * Compare a batch of attachments with those on local site
	 *
	 * @param $blogs
	 * @param $all_attachments
	 * @param $prefix
	 * @param $progress
	 *
	 * @return array
	 */
	function compare_remote_attachments( $blogs, $all_attachments, $prefix, $progress ) {
		if ( ! is_array( $blogs ) ) {
			$blogs = unserialize( stripslashes( $blogs ) );
		}
		if ( ! is_array( $all_attachments ) ) {
			$all_attachments = unserialize( stripslashes( $all_attachments ) );
		}

		$files_to_migrate = array();
		$finish           = time() + $this->media_diff_batch_time;

		$local_blog_ids = ( is_multisite() ) ? $this->get_blog_ids() : array();

		foreach ( $all_attachments as $blog_id => $attachments ) {
			foreach ( $attachments as $remote_attachment ) {

				if ( time() >= $finish ) {
					break;
				}

				// find local attachment
				$local_attachment = $this->find_attachment( $remote_attachment, $prefix, $local_blog_ids );
				if ( false === $local_attachment ) {
					// local attachment doesn't exist, definitely migrate remote
					$this->maybe_queue_attachment( $files_to_migrate, $remote_attachment );
				} else {
					// local attachment already exists
					// check the timestamps on the attachment
					$remote_timestamp = strtotime( $remote_attachment['date'] );
					$local_timestamp  = strtotime( $local_attachment['date'] );

					if ( $remote_timestamp != $local_timestamp ) {
						// timestamps are different, let's migrate remote
						$this->maybe_queue_attachment( $files_to_migrate, $remote_attachment );
					} else {
						// only migrate if the local files are missing
						$this->maybe_queue_attachment( $files_to_migrate, $remote_attachment, $local_attachment );
					}
				}

				$blogs[ $blog_id ]['last_post'] = $remote_attachment['ID'];
				$progress ++;
			}
		}

		$return = array(
			'files_to_migrate'   => $files_to_migrate,
			'blogs'              => $blogs,
			'determine_progress' => $progress
		);

		return $return;
	}

	/**
	 * Queue up all attachments in batch to be migrated
	 *
	 * @param $blogs
	 * @param $all_attachments
	 * @param $progress
	 *
	 * @return array
	 */
	function queue_all_attachments( $blogs, $all_attachments, $progress ) {
		if ( ! is_array( $blogs ) ) {
			$blogs = unserialize( stripslashes( $blogs ) );
		}
		if ( ! is_array( $all_attachments ) ) {
			$all_attachments = unserialize( stripslashes( $all_attachments ) );
		}

		$files_to_migrate = array();
		$finish           = time() + $this->media_diff_batch_time;

		foreach ( $all_attachments as $blog_id => $attachments ) {
			foreach ( $attachments as $remote_attachment ) {

				if ( time() >= $finish ) {
					break;
				}

				$this->maybe_queue_attachment( $files_to_migrate, $remote_attachment );

				$blogs[ $blog_id ]['last_post'] = $remote_attachment['ID'];
				$progress ++;
			}
		}

		$return = array(
			'files_to_migrate'   => $files_to_migrate,
			'blogs'              => $blogs,
			'determine_progress' => $progress
		);

		return $return;
	}

	/**
	 * Queues attachment file and image size files for migration
	 * if they exist on the source filesystem
	 *
	 * @param      $files_to_migrate
	 * @param      $attachment
	 * @param bool $local_attachment - use to compare if files actually exist locally
	 */
	function maybe_queue_attachment( &$files_to_migrate, $attachment, $local_attachment = false ) {
		if ( isset( $attachment['file_size'] ) && ( ! $local_attachment || ( $local_attachment && ! isset( $local_attachment['file_size'] ) ) ) ) {
			// if the remote attachment exists on the remote file system
			// and if a local attachment is supplied, if the file doesn't exist on local file system
			$files_to_migrate[ $attachment['file'] ] = $attachment['file_size'];
		}
		// check other image sizes of the attachment
		if ( empty( $attachment['sizes'] ) || apply_filters( 'wpmdb_exclude_resized_media', false ) ) {
			return;
		}
		foreach ( $attachment['sizes'] as $size ) {
			if ( isset( $size['file_size'] ) && ( ! $local_attachment || ( $local_attachment && ! $this->local_image_size_file_exists( $size, $local_attachment ) ) ) ) {
				// if the remote image size file exists on the remote file system
				$files_to_migrate[ $size['file'] ] = $size['file_size'];
			}
		}
	}

	/**
	 * Check a remote image size file exists on the local filesystem
	 *
	 * @param $remote_size
	 * @param $local_attachment
	 *
	 * @return bool
	 */
	function local_image_size_file_exists( $remote_size, $local_attachment ) {
		if ( empty( $local_attachment['sizes'] ) ) {
			return false;
		}

		foreach ( $local_attachment['sizes'] as $size ) {
			if ( $size['file'] == $remote_size['file'] ) {
				if ( isset( $size['file_size'] ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Return information about remote site for use in media migration
	 *
	 * @scope remote
	 *
	 * @return bool|null
	 */
	function respond_to_get_remote_media_info() {
		$filtered_post = $this->filter_post_elements( $_POST, array( 'action', 'temp_prefix', 'intent' ) );
		if ( ! $this->verify_signature( $filtered_post, $this->settings['key'] ) ) {
			$return = array(
				'wpmdb_error' => 1,
				'body'        => $this->invalid_content_verification_error . ' (#100mf)',
			);
			$this->log_error( $return['body'], $filtered_post );
			$result = $this->end_ajax( serialize( $return ) );

			return $result;
		}

		if ( defined( 'UPLOADBLOGSDIR' ) ) {
			$upload_url = home_url( UPLOADBLOGSDIR );
		} else {
			$upload_dir = wp_upload_dir();
			$upload_url = $upload_dir['baseurl'];
		}

		$this->responding_to_get_remote_media_listing = true;

		$return['prefix']                   = $this->get_table_prefix();
		$return['remote_total_attachments'] = $this->get_local_attachments_count( $return['prefix'] );
		$return['remote_uploads_url']       = $upload_url;
		$return['blogs']                    = serialize( $this->get_blogs() );


		$result = $this->end_ajax( serialize( $return ) );

		return $result;
	}

	/**
	 * Return a batch of attachments from the remote site
	 *
	 * @scope remote
	 *
	 * @return bool|null
	 */
	function respond_to_get_remote_attachment_batch() {
		$filtered_post = $this->filter_post_elements( $_POST, array( 'action', 'temp_prefix', 'intent' ) );
		if ( ! $this->verify_signature( $filtered_post, $this->settings['key'] ) ) {
			$return = array(
				'wpmdb_error' => 1,
				'body'        => $this->invalid_content_verification_error . ' (#116mf)'
			);
			$this->log_error( $return['body'], $filtered_post );
			$result = $this->end_ajax( serialize( $return ) );

			return $result;
		}
		$batch                        = $this->get_local_attachments_batch( $_POST['blogs'], $_POST['attachment_batch_limit'], $_POST['prefix'] );
		$return['remote_attachments'] = serialize( $batch['attachments'] );
		$return['blogs']              = serialize( $batch['blogs'] );


		$result = $this->end_ajax( serialize( $return ) );

		return $result;
	}

	/**
	 * Adds the media settings to the migration setting page in core
	 */
	function migration_form_controls() {
		$this->template( 'migrate' );
	}

	/**
	 * Whitelist media setting fields for use in AJAX save in core
	 *
	 * @param $profile_fields
	 *
	 * @return array
	 */
	function accepted_profile_fields( $profile_fields ) {
		$profile_fields[] = 'media_files';
		$profile_fields[] = 'remove_local_media';
		$profile_fields[] = 'media_migration_option';

		return $profile_fields;
	}

	/**
	 * Get translated strings for javascript and other functions
	 *
	 * @return array
	 */
	function get_strings() {
		$strings = array(
			'removing_all_files_pull'  => __( "Removing all local files before download of remote media", 'wp-migrate-db-pro-media-files' ),
			'removing_all_files_push'  => __( "Removing all remote files before upload of local media", 'wp-migrate-db-pro-media-files' ),
			'removing_files_pull'      => __( "Removing local files that are not found on the remote site", 'wp-migrate-db-pro-media-files' ),
			'removing_files_push'      => __( "Removing remote files that are not found on the local site", 'wp-migrate-db-pro-media-files' ),
			'determining'              => __( "Determining media to migrate", 'wp-migrate-db-pro-media-files' ),
			'determining_progress'     => __( 'Determining media to migrate - %1$d of %2$d attachments (%3$d%%)', 'wp-migrate-db-pro-media-files' ),
			'error_determining'        => __( "Error while attempting to determine which attachments to migrate.", 'wp-migrate-db-pro-media-files' ),
			'migration_failed'         => __( "Migration failed", 'wp-migrate-db-pro-media-files' ),
			'problem_migrating_media'  => __( "A problem occurred when migrating the media files.", 'wp-migrate-db-pro-media-files' ),
			'media_attachments'        => __( "Media Attachments", 'wp-migrate-db-pro-media-files' ),
			'media_files'              => __( "Files", 'wp-migrate-db-pro-media-files' ),
			'migrate_media_files_pull' => __( "Downloading files", 'wp-migrate-db-pro-media-files' ),
			'migrate_media_files_push' => __( "Uploading files", 'wp-migrate-db-pro-media-files' ),
			'files_uploaded'           => __( "Files Uploaded", 'wp-migrate-db-pro-media-files' ),
			'files_downloaded'         => __( "Files Downloaded", 'wp-migrate-db-pro-media-files' ),
		);

		if ( is_null( $this->media_strings ) ) {
			$this->media_strings = $strings;
		}

		return $this->media_strings;
	}

	/**
	 * Retrieve a specifc translated string
	 *
	 * @param $key
	 *
	 * @return string
	 */
	function get_string( $key ) {
		$strings = $this->get_strings();

		return ( isset( $strings[ $key ] ) ) ? $strings[ $key ] : '';
	}

	/**
	 * Load media related assets in core plugin
	 */
	function load_assets() {
		$plugins_url = trailingslashit( plugins_url() ) . trailingslashit( $this->plugin_folder_name );
		$version     = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? time() : $this->plugin_version;
		$src         = $plugins_url . 'asset/css/styles.css';
		wp_enqueue_style( 'wp-migrate-db-pro-media-files-styles', $src, array( 'wp-migrate-db-pro-styles' ), $version );
		$src = $plugins_url . 'asset/js/script.js';
		wp_enqueue_script( 'wp-migrate-db-pro-media-files-script', $src, array(
				'jquery',
				'wp-migrate-db-pro-common',
				'wp-migrate-db-pro-hook',
				'wp-migrate-db-pro-script'
			), $version, true );

		wp_localize_script( 'wp-migrate-db-pro-media-files-script', 'wpmdbmf_strings', $this->get_strings() );
	}

	/**
	 * Check the remote site has the media addon setup
	 *
	 * @param $data
	 *
	 * @return mixed
	 */
	function establish_remote_connection_data( $data ) {
		$data['media_files_available'] = '1';
		$data['media_files_version']   = $this->plugin_version;
		if ( function_exists( 'ini_get' ) ) {
			$max_file_uploads = ini_get( 'max_file_uploads' );
		}
		$max_file_uploads                     = ( empty( $max_file_uploads ) ) ? 20 : $max_file_uploads;
		$data['media_files_max_file_uploads'] = apply_filters( 'wpmdbmf_max_file_uploads', $max_file_uploads );

		return $data;
	}

	/**
	 * Get an array of the blogs in the site to be processed by the addon
	 *
	 * @return array
	 */
	function get_blogs() {
		$blogs    = array();
		$blogs[1] = array(
			'last_post' => 0, // record last post id process to be used as an offset in the next batch for the blog
			'processed' => 0 // flag to record if we have processed all attachments for the blog
		);

		if ( is_multisite() ) {
			$blog_ids = $this->get_blog_ids();
			foreach ( $blog_ids as $blog_id ) {
				$blogs[ $blog_id ] = array(
					'last_post' => 0,
					'processed' => 0
				);
			}
		}

		return $blogs;
	}

	/**
	 * Get all the IDs of the blogs for the site.
	 *
	 * @return array
	 */
	function get_blog_ids() {
		global $wpdb;

		$blogs = wp_get_sites( array(
			'archived'   => 0,
			'spam'       => 0,
			'deleted'    => 0
		) );

		$blog_ids = array();
		foreach ( $blogs as $blog ) {
			if ( 1 == $blog['blog_id'] ) {
				continue;
			}

			$blog_ids[] = $blog['blog_id'];
		}

		return $blog_ids;
	}

	/**
	 * Download a local copy of a remote media file
	 *
	 * @param     $url
	 * @param int $timeout
	 *
	 * @return array|string|WP_Error
	 */
	function download_url( $url, $timeout = 300 ) {
		//WARNING: The file is not automatically deleted, The script must unlink() the file.
		if ( ! $url ) {
			return new WP_Error( 'http_no_url', __( 'Invalid URL Provided.' ) );
		}

		$tmpfname = wp_tempnam( $url );
		if ( ! $tmpfname ) {
			return new WP_Error( 'http_no_file', __( 'Could not create Temporary file.' ) );
		}

		$sslverify = ( 1 == $this->settings['verify_ssl'] ) ? true : false;
		$args      = array(
			'timeout'            => $timeout,
			'stream'             => true,
			'filename'           => $tmpfname,
			'reject_unsafe_urls' => false,
			'sslverify'          => $sslverify,
		);

		$response = wp_remote_get( $url, $args );

		if ( is_wp_error( $response ) ) {
			unlink( $tmpfname );

			return $response;
		}

		if ( 200 != wp_remote_retrieve_response_code( $response ) ) {
			unlink( $tmpfname );

			return new WP_Error( 'http_404', trim( wp_remote_retrieve_response_message( $response ) ) );
		}

		return $tmpfname;
	}

	/**
	 * Add media related javascript variables to the page
	 */
	function js_variables() {
		?>
		var wpmdb_media_files_version = '<?php echo $this->plugin_version; ?>';
	<?php
	}

	/**
	 * Adds extra information to the core plugin's diagnostic info
	 */
	function diagnostic_info() {
		// store the count of local attachments in a transient
		// so not to impact performance with sites with large media libraries
		if ( false === ( $attachment_count = get_transient( 'wpmdb_local_attachment_count' ) ) ) {
			$attachment_count = $this->get_local_attachments_count();
			set_transient( 'wpmdb_local_attachment_count', $attachment_count, 2 * HOUR_IN_SECONDS );
		}

		echo 'Media Files: ';
		echo number_format( $attachment_count );
		echo "\r\n";

		echo 'Number of Image Sizes: ';
		$sizes = count( get_intermediate_image_sizes() );
		echo number_format( $sizes );
		echo "\r\n";
		echo "\r\n";
	}

	function verify_remote_post_response( $response ) {
		if ( false === $response ) {
			$return    = array( 'wpmdb_error' => 1, 'body' => $this->error );
			$error_msg = 'Failed attempting to verify remote post response (#114mf)';
			$this->log_error( $error_msg, $this->error );
			$result = $this->end_ajax( json_encode( $return ) );

			return $result;
		}

		if ( ! is_serialized( trim( $response ) ) ) {
			$return    = array( 'wpmdb_error' => 1, 'body' => $response );
			$error_msg = 'Failed as the response is not serialized string (#115mf)';
			$this->log_error( $error_msg, $response );
			$result = $this->end_ajax( json_encode( $return ) );

			return $result;
		}

		$response = unserialize( trim( $response ) );

		if ( isset( $response['wpmdb_error'] ) ) {
			$this->log_error( $response['wpmdb_error'], $response );
			$result = $this->end_ajax( json_encode( $response ) );

			return $result;
		}

		return $response;
	}

	/**
	 * Media addon nonces for core javascript variable
	 *
	 * @param $nonces
	 *
	 * @return mixed
	 */
	function add_nonces( $nonces ) {
		$nonces['migrate_media']                        = wp_create_nonce( 'migrate-media' );
		$nonces['remove_files_recursive']               = wp_create_nonce( 'remove-files-recursive' );
		$nonces['prepare_determine_media']              = wp_create_nonce( 'prepare-determine-media' );
		$nonces['determine_media_to_migrate_recursive'] = wp_create_nonce( 'determine-media-to-migrate-recursive' );

		return $nonces;
	}

	/**
	 * Extend the progress modal core template with our second progress bar
	 */
	function progress_template() {
		$this->template( 'progress' );
	}
}