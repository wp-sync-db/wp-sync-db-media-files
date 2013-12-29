<?php
class WPMDBPro_Media_Files extends WPMDBPro_Addon {

	function __construct( $plugin_file_path ) {
		parent::__construct( $plugin_file_path );

		if( ! $this->meets_version_requirements( '1.3' ) ) return;

		add_action( 'wpmdb_after_advanced_options', array( $this, 'migration_form_controls' ) );
		add_action( 'wpmdb_load_assets', array( $this, 'load_assets' ) );
		add_action( 'wpmdb_js_variables', array( $this, 'js_variables' ) );
		add_filter( 'wpmdb_accepted_profile_fields', array( $this, 'accepted_profile_fields' ) );
		add_filter( 'wpmdb_establish_remote_connection_data', array( $this, 'establish_remote_connection_data' ) );

		// internal AJAX handlers
		add_action( 'wp_ajax_wpmdbmf_determine_media_to_migrate', array( $this, 'ajax_determine_media_to_migrate' ) );
		add_action( 'wp_ajax_wpmdbmf_migrate_media', array( $this, 'ajax_migrate_media' ) );

		// external AJAX handlers
		add_action( 'wp_ajax_nopriv_wpmdbmf_get_remote_media_listing', array( $this, 'respond_to_get_remote_media_listing' ) );
	}

	function get_local_attachments() {
		global $wpdb;
		$prefix = $wpdb->prefix;

		$local_attachments = $wpdb->get_results(
			"SELECT `{$prefix}posts`.`post_modified_gmt` AS 'date', pm1.`meta_value` AS 'file', pm2.`meta_value` AS 'metadata'
			FROM `{$prefix}posts`
			INNER JOIN `{$prefix}postmeta` pm1 ON `{$prefix}posts`.`ID` = pm1.`post_id` AND pm1.`meta_key` = '_wp_attached_file'
			LEFT OUTER JOIN `{$prefix}postmeta` pm2 ON `{$prefix}posts`.`ID` = pm2.`post_id` AND pm2.`meta_key` = '_wp_attachment_metadata'
			WHERE `{$prefix}posts`.`post_type` = 'attachment'", ARRAY_A
		);

		if( is_multisite() ) {
			$blogs = $this->get_blogs();
			foreach( $blogs as $blog ) {
				$attachments = $wpdb->get_results(
					"SELECT `{$prefix}{$blog}_posts`.`post_modified_gmt` AS 'date', pm1.`meta_value` AS 'file', pm2.`meta_value` AS 'metadata', {$blog} AS 'blog_id'
					FROM `{$prefix}{$blog}_posts`
					INNER JOIN `{$prefix}{$blog}_postmeta` pm1 ON `{$prefix}{$blog}_posts`.`ID` = pm1.`post_id` AND pm1.`meta_key` = '_wp_attached_file'
					LEFT OUTER JOIN `{$prefix}{$blog}_postmeta` pm2 ON `{$prefix}{$blog}_posts`.`ID` = pm2.`post_id` AND pm2.`meta_key` = '_wp_attachment_metadata'
					WHERE `{$prefix}{$blog}_posts`.`post_type` = 'attachment'", ARRAY_A
				);

				$local_attachments = array_merge( $attachments, $local_attachments );
			}
		}

		$local_attachments = array_map( array( $this, 'process_attachment_data' ), $local_attachments );
		$local_attachments = array_filter( $local_attachments );

		return $local_attachments;
	}

	function process_attachment_data( $attachment ) {
		if( isset( $attachment['blog_id'] ) ) { // used for multisite
			if( defined( 'UPLOADBLOGSDIR' ) ) {
				$upload_dir = sprintf( '%s/files/', $attachment['blog_id'] );
			}
			else {
				$upload_dir = sprintf( 'sites/%s/', $attachment['blog_id'] );
			}
			$attachment['file'] = $upload_dir . $attachment['file'];
		}
		$upload_dir = substr( $attachment['file'], 0, strrpos( $attachment['file'], '/' ) + 1 );
		if( ! empty( $attachment['metadata'] ) ) {
			$attachment['metadata'] = @unserialize( $attachment['metadata'] );
			if( ! isset( $attachment['metadata']['sizes'] ) ) return;
			foreach( $attachment['metadata']['sizes'] as $size ) {
				$attachment['sizes'][] = $upload_dir . $size['file'];
			}
		}
		unset( $attachment['metadata'] );
		return $attachment;
	}

	function uploads_dir() {
		if( defined( 'UPLOADBLOGSDIR' ) ) {
			$upload_dir = trailingslashit( ABSPATH ) . UPLOADBLOGSDIR;
		} 
		else {
			$upload_dir = wp_upload_dir();
			$upload_dir = $upload_dir['basedir'];
		}
		return trailingslashit( $upload_dir );
	}

	function get_local_media() {
		$upload_dir = untrailingslashit( $this->uploads_dir() );

		$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $upload_dir ), RecursiveIteratorIterator::SELF_FIRST );
		$local_media = array();

		foreach( $files as $name => $object ){
			$name = str_replace( array( $upload_dir . DS, '\\' ), array( '', '/' ), $name );
			$local_media[$name] = $object->getSize();
		}

		return $local_media;
	}

	function ajax_migrate_media() {
		$this->set_time_limit();
		$files_to_download = $_POST['file_chunk'];
		$remote_uploads_url = trailingslashit( $_POST['remote_uploads_url'] );

		$upload_dir = $this->uploads_dir();

		$errors = array();
		foreach( $files_to_download as $file_to_download ) {
			$temp_file_path = $this->download_url( $remote_uploads_url . $file_to_download );

			if( is_wp_error( $temp_file_path ) ) {
				$download_error = $temp_file_path->get_error_message();
				$errors[] = 'Could not download file: ' . $remote_uploads_url . $file_to_download . ' - ' . $download_error;
				continue;
			}

			$date = substr( $file_to_download, 0, strrpos( $file_to_download, '/' ) + 1 );
			$new_path = $upload_dir . $date . basename( $file_to_download  );

			$move_result = @rename( $temp_file_path, $new_path );

			if( false === $move_result ) {
				$folder = dirname( $new_path );
				if( @file_exists( $folder ) ) {
					$errors[] =  'Error attempting to move downloaded file. Temp path: ' . $temp_file_path . ' - New Path: ' . $new_path . ' (#103mf)';
				}
				else{
					if( false === @mkdir( $folder, 0755, true ) ) {
						$errors[] =  'Error attempting to create required directory: ' . $folder . ' (#104mf)';
					}
					else {
						$move_result = @rename( $temp_file_path, $new_path );
						if( false === $move_result ) {
							$errors[] =  'Error attempting to move downloaded file. Temp path: ' . $temp_file_path . ' - New Path: ' . $new_path . ' (#105mf)';
						}
					}
				}
			}
		}

		if( ! empty( $errors ) ) {
			$return = array(
				'wpmdb_error'	=> 1,
				'body'			=> implode( '<br />', $errors )
			);
			echo json_encode( $return );
			exit;
		}

		// not required, just here because we have to return something otherwise the AJAX fails
		$return['success'] = 1;
		echo json_encode( $return );
		exit;
	}

	function ajax_determine_media_to_migrate() {
		$this->set_time_limit();

		$local_attachments = $this->get_local_attachments();
		$local_media = $this->get_local_media();

		$data = array();
		$data['action'] = 'wpmdbmf_get_remote_media_listing';
		$data['sig'] = $this->create_signature( $data, $_POST['key'] );
		$ajax_url = trailingslashit( $_POST['url'] ) . 'wp-admin/admin-ajax.php';
		$response = $this->remote_post( $ajax_url, $data, __FUNCTION__ );
		$response = $this->verify_remote_post_response( $response );

		$upload_dir = $this->uploads_dir();

		$remote_attachments = $response['remote_attachments'];
		$remote_media = $response['remote_media'];

		$files_to_migrate = array();
		foreach( $remote_attachments as $attachment ) {
			$local_attachment_key = $this->multidimensional_search( array( 'file' => $attachment['file'] ), $local_attachments );
			if( false === $local_attachment_key ) continue;

			$remote_timestamp = strtotime( $attachment['date'] );
			$local_timestamp = strtotime( $local_attachments[$local_attachment_key]['date'] );

			if( $local_timestamp >= $remote_timestamp ) {
				if( ! isset( $local_media[$attachment['file']] ) ) {
					$files_to_migrate = $this->add_files_to_migrate( $attachment, $files_to_migrate, $remote_media );
				}
				else {
					$files_to_migrate = $this->maybe_add_resized_images( $attachment, $files_to_migrate, $remote_media, $local_media );
				}
			}
			else {
				$files_to_migrate = $this->add_files_to_migrate( $attachment, $files_to_migrate, $remote_media );
			}
		}

		$return['files_to_migrate'] = $files_to_migrate;
		$return['total_size'] = array_sum( $files_to_migrate );
		$return['remote_uploads_url'] = $response['remote_uploads_url'];

		// remove local media if it doesn't exist on the remote site
		if( $_POST['remove_local_media'] == '1' ) {
			foreach( $local_attachments as $local_attachment ) {
				if( false !== $this->multidimensional_search( array( 'file' => $local_attachment['file'] ), $remote_attachments ) ) continue;
				@unlink( $upload_dir . $local_attachment['file'] );
				if( empty( $local_attachment['sizes'] ) ) continue;
				foreach( $local_attachment['sizes'] as $size ) {
					@unlink( $upload_dir . $size );
				}
			}
		}

		echo json_encode( $return );
		exit;
	}

	function add_files_to_migrate( $attachment, $files_to_migrate, $remote_media ) {
		if( isset( $remote_media[$attachment['file']] ) ) {
			$files_to_migrate[$attachment['file']] = $remote_media[$attachment['file']];
		}
		if( ! empty( $attachment['sizes'] ) ) {
			foreach( $attachment['sizes'] as $size ) {
				if( isset( $remote_media[$size] ) ) {
					$files_to_migrate[$size] = $remote_media[$size];
				}
			}
		}
		return $files_to_migrate;
	}

	function maybe_add_resized_images( $attachment, $files_to_migrate, $remote_media, $local_media ) {
		if( ! empty( $attachment['sizes'] ) ) {
			foreach( $attachment['sizes'] as $size ) {
				if( isset( $remote_media[$size] ) && ! isset( $local_media[$size] ) ) {
					$files_to_migrate[$size] = $remote_media[$size];
				}
			}
		}
		return $files_to_migrate;
	}

	function respond_to_get_remote_media_listing() {
		$filtered_post = $this->filter_post_elements( $_POST, array( 'action' ) );
		if ( ! $this->verify_signature( $filtered_post, $this->settings['key'] ) ) {
			$return = array(
				'wpmdb_error' 	=> 1,
				'body'			=> $this->invalid_content_verification_error . ' (#100mf)',
			);
			echo serialize( $return );
			exit;
		}

		if( defined( 'UPLOADBLOGSDIR' ) ) {
			$upload_url = home_url( UPLOADBLOGSDIR );
		}
		else {
			$upload_dir = wp_upload_dir();
			$upload_url = $upload_dir['baseurl'];
		}

		$return['remote_attachments'] = $this->get_local_attachments();
		$return['remote_media'] = $this->get_local_media();
		$return['remote_uploads_url'] = $upload_url;

		echo serialize( $return );
		exit;
	}

	function migration_form_controls() {
		$this->template( 'migrate' );
	}

	function accepted_profile_fields( $profile_fields ) {
		$profile_fields[] = 'media_files';
		$profile_fields[] = 'remove_local_media';
		return $profile_fields;
	}

	function load_assets() {
		$plugins_url = trailingslashit( plugins_url() ) . trailingslashit( $this->plugin_slug );
		$src = $plugins_url . 'asset/js/script.js';
		wp_enqueue_script( 'wp-migrate-db-pro-media-files-script', $src, array( 'jquery' ), $this->get_installed_version(), true );
	}

	function establish_remote_connection_data( $data ) {
		$data['media_files_available'] = '1';
		$data['media_files_version'] = $this->get_installed_version();
		return $data;
	}

	function multidimensional_search( $needle, $haystack ) {
		if( empty( $needle ) || empty( $haystack ) ) return false;

		foreach( $haystack as $key => $value ) {
			foreach ( $needle as $skey => $svalue ) {
				$exists = ( isset( $haystack[$key][$skey] ) && $haystack[$key][$skey] === $svalue );
			}
			if( $exists ) return $key;
		}

		return false;
	}

	function get_blogs() { 
		global $wpdb;

		$blogs = $wpdb->get_results(
			"SELECT blog_id
			FROM {$wpdb->blogs}
			WHERE site_id = '{$wpdb->siteid}'
			AND spam = '0'
			AND deleted = '0'
			AND archived = '0'
			AND blog_id != 1
		");

		$clean_blogs = array();
		foreach( $blogs as $blog ) {
			$clean_blogs[] = $blog->blog_id;
		}

		return $clean_blogs;
	}

	function download_url( $url, $timeout = 300 ) {
		//WARNING: The file is not automatically deleted, The script must unlink() the file.
		if ( ! $url )
			return new WP_Error('http_no_url', __('Invalid URL Provided.'));

		$tmpfname = wp_tempnam($url);
		if ( ! $tmpfname )
			return new WP_Error('http_no_file', __('Could not create Temporary file.'));

		$response = wp_remote_get( $url, array( 'timeout' => $timeout, 'stream' => true, 'filename' => $tmpfname, 'reject_unsafe_urls' => false ) );

		if ( is_wp_error( $response ) ) {
			unlink( $tmpfname );
			return $response;
		}

		if ( 200 != wp_remote_retrieve_response_code( $response ) ){
			unlink( $tmpfname );
			return new WP_Error( 'http_404', trim( wp_remote_retrieve_response_message( $response ) ) );
		}

		return $tmpfname;
	}

	function js_variables() {
		?>
		var wpmdb_media_files_version = '<?php echo $this->get_installed_version(); ?>';
		<?php
	}

	function verify_remote_post_response( $response ) {
		if ( false === $response ) {
			$return = array( 'wpmdb_error' => 1, 'body' => $this->error );
			echo json_encode( $return );
			exit;
		}

		if ( ! is_serialized( trim( $response ) ) ) {
			$return = array( 'wpmdb_error'	=> 1, 'body' => $response );
			echo json_encode( $return );
			exit;
		}

		$response = unserialize( trim( $response ) );

		if ( isset( $response['wpmdb_error'] ) ) {
			echo json_encode( $response );
			exit;
		}

		return $response;
	}

}