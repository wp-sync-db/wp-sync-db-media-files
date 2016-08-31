var wpmdb = wpmdb || {};
wpmdb.mediaFiles = {
	remote_media_files_unavailable: false
};

(function( $, wpmdb ) {
	var remote_max_upload_size = 0;
	var $mf_select_subsites_section = $( '#mf-select-subsites-section' );
	var $mf_select_subsites = $( '#mf-select-subsites' );

	// .length doesn't work on JS "associative arrays" i.e. objects with key/value elements, this does
	Object.size = function( obj ) {
		var size = 0, key;
		for ( key in obj ) {
			if ( obj.hasOwnProperty( key ) ) {
				size++;
			}
		}
		return size;
	};

	var disable_media_files_option = function() {
		$( '#media-files' ).attr( 'data-available', '0' );
		$( '#media-files' ).prop( 'checked', false );
		$( '#media-files' ).attr( 'disabled', 'disabled' );
		$( '.media-files' ).addClass( 'disabled' );
		$( '.media-files-options .expandable-content' ).hide();
	};

	var hide_show_options = function( unavailable ) {
		var mig_type = wpmdb_migration_type();

		if ( 'savefile' === mig_type ) {
			$( '.media-files-options' ).hide();
			return;
		}

		$( '.media-files-options' ).show();
		$( '.media-files-push' ).hide();

		if ( unavailable ) {
			$( '.media-files-options ul' ).hide();
			$( '.media-migration-unavailable' ).show();
			disable_media_files_option();
			return;
		}

		if ( 'undefined' !== typeof wpmdb.mediaFiles.remote_connection_data && wpmdb_data.media_files_version !== wpmdb.mediaFiles.remote_connection_data.media_files_version ) {
			$( '.media-files-remote-location' ).html( wpmdb.mediaFiles.remote_connection_data.url );
			$( '.media-file-remote-version' ).html( wpmdb.mediaFiles.remote_connection_data.media_files_version );
			$( '.media-files-different-plugin-version-notice' ).show();
			disable_media_files_option();
			return;
		}

		if ( 'true' === wpmdb_data.is_multisite ) {
			var subsites = get_subsites();

			var $_mf_selected_subsites = $( '#_mf-selected-subsites' );
			var selected_subsites = $( '#mf-selected-subsites' ).val();
			if ( 'pull' === mig_type && 0 < Object.size( subsites ) && $_mf_selected_subsites.length ) {
				selected_subsites = $.parseJSON( $_mf_selected_subsites.val() );
				$_mf_selected_subsites.remove();
			}
			wpmdb.multisite.update_multiselect( '#mf-selected-subsites', subsites, selected_subsites );

			var enable_select_subsites = $.wpmdb.apply_filters( 'wpmdbmf_enable_select_subsites', true );

			if ( enable_select_subsites ) {
				$mf_select_subsites_section.show();
			} else {
				$mf_select_subsites.prop( 'checked', false );
				$mf_select_subsites_section.hide();
			}
			$mf_select_subsites.change();

			maybe_show_data_and_files_differ_notice();
		}

		$( '.media-files-options ul' ).show();
		$( '.media-migration-unavailable' ).hide();
		$( '.media-files-different-plugin-version-notice' ).hide();
		$( '#media-files' ).removeAttr( 'disabled' );
		$( '.media-files' ).removeClass( 'disabled' );
		$( '#media-files' ).attr( 'data-available', '1' );
	};

	wpmdb.functions.prepare_remove_all_files = function() {
		wpmdb.mediaFiles.connection_info = $.trim( $( '.pull-push-connection-info' ).val() ).split( '\n' );
		var media_type = $( 'input[name="media_migration_option"]:checked' ).val();

		$( '.progress-tables' ).empty();
		$( '.progress-tables-hover-boxes' ).empty();
		$( '.progress-bar' ).width( '0px' );

		// this only needs to be run if we are skipping the comparison
		if ( 'entire' === media_type ) {
			var title = 'removing_all_files_' + wpmdb_migration_type();
			$( '.progress-text', '.progress-wrapper-primary' ).html( wpmdbmf_strings[ title ] );

			// start recursive batch delete of local files
			var args = {};
			args.remove_files = 1;
			args.compare = 0;
			args.offset = 0;
			args.next_step_in_migration = wpmdb.functions.prepare_determine_media;
			wpmdb.common.next_step_in_migration = { fn: wpmdb.functions.remove_files_recursive, args: [ args ] };
			wpmdb.functions.execute_next_step();

		} else {

			// We are doing the comparison so lets start the determine
			wpmdb.common.next_step_in_migration = { fn: wpmdb.functions.prepare_determine_media };
			wpmdb.functions.execute_next_step();
		}
	};

	wpmdb.functions.remove_files_recursive = function( args ) {
		if ( 0 === args.remove_files ) {

			// All files removed lets start the migration
			if ( false !== args.next_step_in_migration ) {
				wpmdb.common.next_step_in_migration = { fn: args.next_step_in_migration };
				wpmdb.functions.execute_next_step();
			} else {
				wpmdb_call_next_hook();
			}

			return;
		}
		wpmdb.mediaFiles.connection_info = $.trim( $( '.pull-push-connection-info' ).val() ).split( '\n' );

		var old_args = args;

		$.ajax( {
			url: ajaxurl,
			type: 'POST',
			dataType: 'text',
			cache: false,
			data: {
				action: 'wpmdbmf_remove_files_recursive',
				migration_state_id: wpmdb.migration_state_id,
				compare: args.compare,
				offset: args.offset,
				nonce: wpmdb_data.nonces.remove_files_recursive
			},
			error: function( jqXHR, textStatus, errorThrown ) {
				$( '.progress-title' ).html( wpmdbmf_strings.migration_failed );
				$( '.progress-text' ).html( wpmdbGetAjaxErrors( wpmdbmf_strings.error_determining, '(#101mf)', jqXHR.responseText, jqXHR ) );
				$( '.progress-text' ).addClass( 'migration-error' );
				console.log( jqXHR );
				console.log( textStatus );
				console.log( errorThrown );
				wpmdb.common.migration_error = true;
				wpmdb.functions.migration_complete_events();
				return;
			},
			success: function( data ) {
				var original_data = data;
				args = wpmdb_parse_json( $.trim( data ) );
				if ( !args ) {
					migration_failed( original_data );
					return;
				}

				if ( 'undefined' !== typeof args.wpmdb_error && 1 === args.wpmdb_error ) {
					migration_failed( args.body );
					return;
				}

				if ( 'undefined' !== typeof args.wpmdb_non_fatal_error && 1 === args.wpmdb_non_fatal_error ) {
					wpmdb.common.non_fatal_errors += args.body;
				}

				// persist settings
				args.next_step_in_migration = old_args.next_step_in_migration;

				wpmdb.common.next_step_in_migration = {
					fn: wpmdb.functions.remove_files_recursive,
					args: [ args ]
				};
				wpmdb.functions.execute_next_step();
			}
		} );
	};

	wpmdb.functions.prepare_determine_media = function() {
		wpmdb.mediaFiles.connection_info = $.trim( $( '.pull-push-connection-info' ).val() ).split( '\n' );
		remote_max_upload_size = 0;

		var remove_local_media = 0;
		var copy_entire_media = 0;
		var media_type = $( 'input[name="media_migration_option"]:checked' ).val();

		$( '.progress-tables' ).empty();
		$( '.progress-tables' ).html( '<div title="' + wpmdbmf_strings.media_files + '" style="width: 100%;" class="progress-chunk media_files"><span></div>' );
		$( '.progress-text', '.progress-wrapper-primary' ).html( '0% - ' + wpmdbmf_strings.determining );

		if ( 'compare-remove' === media_type ) {
			media_type = 'compare';
			remove_local_media = 1;
		}
		if ( 'entire' === media_type ) {
			copy_entire_media = 1;
		}

		var args = {};

		$.ajax( {
			url: ajaxurl,
			type: 'POST',
			dataType: 'text',
			cache: false,
			data: {
				action: 'wpmdbmf_prepare_determine_media',
				migration_state_id: wpmdb.migration_state_id,
				nonce: wpmdb_data.nonces.prepare_determine_media
			},
			error: function( jqXHR, textStatus, errorThrown ) {
				$( '.progress-title' ).html( wpmdbmf_strings.migration_failed );
				$( '.progress-text' ).html( wpmdbGetAjaxErrors( wpmdbmf_strings.error_determining, '(#101mf)', jqXHR.responseText, jqXHR ) );
				$( '.progress-text' ).addClass( 'migration-error' );
				console.log( jqXHR );
				console.log( textStatus );
				console.log( errorThrown );
				wpmdb.common.migration_error = true;
				wpmdb.functions.migration_complete_events();
				return;
			},
			success: function( data ) {
				var original_data = data;
				args = wpmdb_parse_json( $.trim( data ) );
				if ( !args ) {
					migration_failed( original_data );
					return;
				}

				if ( 'undefined' !== typeof args.wpmdb_error && 1 === args.wpmdb_error ) {
					migration_failed( args.body );
					return;
				}

				remote_max_upload_size = args.remote_max_upload_size;

				args.determine_progress = 0;
				args.remove_local_media = remove_local_media;
				args.copy_entire_media = copy_entire_media;

				$( '.progress-tables' ).html( '<div title="' + wpmdbmf_strings.media_attachments + '" style="width: 100%;" class="progress-chunk media_files"><span>' + wpmdbmf_strings.media_attachments + ' (<span class="">0</span> / ' + wpmdb_add_commas( args.attachment_count ) + ')</span></div>' );

				wpmdb.common.next_step_in_migration = {
					fn: wpmdb.functions.determine_media_to_migrate_recursive,
					args: [ args ]
				};
				wpmdb.functions.execute_next_step();
			}
		} );
	};

	wpmdb.functions.determine_media_to_migrate_recursive = function( args ) {
		if ( args.determine_progress === args.attachment_count ) {
			$( '.progress-wrapper-secondary' ).hide();
			$( '.progress-bar' ).width( '0px' );
			$( '.progress-tables' ).empty();

			// finalise migration
			wpmdb.common.next_step_in_migration = { fn: wpmdb.functions.finalise_media_migration, args: [ args ] };
			wpmdb.functions.execute_next_step();
			return;
		}
		wpmdb.mediaFiles.connection_info = $.trim( $( '.pull-push-connection-info' ).val() ).split( '\n' );

		$.ajax( {
			url: ajaxurl,
			type: 'POST',
			dataType: 'text',
			cache: false,
			data: {
				action: 'wpmdbmf_determine_media_to_migrate_recursive',
				migration_state_id: wpmdb.migration_state_id,
				determine_progress: args.determine_progress,
				attachment_count: args.attachment_count,
				remote_uploads_url: args.remote_uploads_url,
				remove_local_media: args.remove_local_media,
				copy_entire_media: args.copy_entire_media,
				blogs: args.blogs,
				attachment_batch_limit: args.attachment_batch_limit,
				nonce: wpmdb_data.nonces.determine_media_to_migrate_recursive
			},
			error: function( jqXHR, textStatus, errorThrown ) {
				$( '.progress-title' ).html( wpmdbmf_strings.migration_failed );
				$( '.progress-text', '.progress-wrapper-primary' ).html( wpmdbmf_strings.error_determining + ' (#101mf)' );
				$( '.progress-text', '.progress-wrapper-primary' ).addClass( 'migration-error' );
				console.log( jqXHR );
				console.log( textStatus );
				console.log( errorThrown );
				wpmdb.common.migration_error = true;
				wpmdb.functions.migration_complete_events();
				return;
			},
			success: function( data ) {
				var original_data = data;
				data = wpmdb_parse_json( $.trim( data ) );
				if ( !data ) {
					migration_failed( original_data );
					return;
				}

				if ( 'undefined' !== typeof data.wpmdb_error && 1 === data.wpmdb_error ) {
					migration_failed( data.body );
					return;
				}

				args.blogs = data.blogs;
				args.determine_progress = data.determine_progress;
				args.total_size = data.total_size;
				args.files_to_migrate = data.files_to_migrate;

				var percent = 100 * args.determine_progress / args.attachment_count;
				var overall_percent = Math.floor( percent );
				$( '.progress-bar', '.progress-wrapper-primary' ).width( percent + '%' );
				$( '.progress-text', '.progress-wrapper-primary' ).html( overall_percent + '% - ' + wpmdbmf_strings.determining );
				$( '.progress-tables', '.progress-wrapper-primary' ).html( '<div title="' + wpmdbmf_strings.media_attachments + '" style="width: 100%;" class="progress-chunk media_files"><span>' + wpmdbmf_strings.media_attachments + ' (<span class="">' + wpmdb_add_commas( args.determine_progress ) + '</span> / ' + wpmdb_add_commas( args.attachment_count ) + ')</span></div>' );

				wpmdb.common.next_step_in_migration = {
					fn: wpmdb.functions.media_successfully_determined,
					args: [ args ]
				};
				wpmdb.functions.execute_next_step();
			}

		} );
	};

	wpmdb.functions.media_successfully_determined = function( args ) {
		if ( 'undefined' !== typeof args.wpmdb_error && 1 === args.wpmdb_error ) {
			wpmdb.common.non_fatal_errors += data.body;
			wpmdb.common.next_step_in_migration = { fn: wpmdb_call_next_hook };
			wpmdb.functions.execute_next_step();
			return;
		}

		args.media_progress = 0;
		args.media_progress_image_number = 0;
		args.bottleneck = wpmdb_data.max_request;
		args.total_files = Object.size( args.files_to_migrate );

		$( '.progress-wrapper-secondary' ).show();
		set_media_progress( 0, 0, args.total_files );

		wpmdb.common.next_step_in_migration = { fn: migrate_media_files_recursive, args: [ args ] };
		wpmdb.functions.execute_next_step();
	};

	function migrate_media_files_recursive( args ) {
		if ( 0 === Object.size( args.files_to_migrate ) ) {
			if ( 0 === args.total_size ) {
				set_media_progress( 0, 0, 0 );
			} else {
				current_media_progress( args );
			}

			delete args.files_to_migrate;
			delete args.total_size;

			wpmdb.common.next_step_in_migration = {
				fn: wpmdb.functions.determine_media_to_migrate_recursive,
				args: [ args ]
			};
			wpmdb.functions.execute_next_step();
			return;
		}

		var file_chunk_to_migrate = [];
		var file_chunk_size = 0;
		var number_of_files_to_migrate = 0;

		$.each( args.files_to_migrate, function( index, value ) {
			if ( 'push' === wpmdb_migration_type() && value > remote_max_upload_size ) {
				var error_msg = wpmdbmf_strings.file_too_large + ' ' + index + ' (#124mf)<br>';
				wpmdb.common.non_fatal_errors += error_msg;
			} else if ( !file_chunk_to_migrate.length ) {
				file_chunk_to_migrate.push( index );
				file_chunk_size += value;
			} else {
				if ( ( file_chunk_size + value ) > args.bottleneck ||
					number_of_files_to_migrate >= wpmdb.mediaFiles.remote_connection_data.media_files_max_file_uploads ) {
					return false;
				} else {
					file_chunk_to_migrate.push( index );
					file_chunk_size += value;
				}
			}

			delete args.files_to_migrate[ index ];
			++args.media_progress_image_number;
			++number_of_files_to_migrate;
		} );

		if ( wpmdb.common.migration_error ) {
			wpmdb.functions.migration_complete_events();
			return;
		}

		// If nothing made it into this batch let the top of the function determine whether to do another or move on a step.
		if ( !file_chunk_to_migrate.length ) {
			current_media_progress( args );

			wpmdb.common.next_step_in_migration = { fn: migrate_media_files_recursive, args: [ args ] };
			wpmdb.functions.execute_next_step();
			return;
		}

		$.ajax( {
			url: ajaxurl,
			type: 'POST',
			dataType: 'text',
			cache: false,
			data: {
				action: 'wpmdbmf_migrate_media',
				migration_state_id: wpmdb.migration_state_id,
				file_chunk: file_chunk_to_migrate,
				nonce: wpmdb_data.nonces.migrate_media
			},
			error: function( jqXHR, textStatus, errorThrown ) {
				$( '.progress-title' ).html( wpmdbmf_strings.migration_failed );
				$( '.progress-text', '.progress-wrapper-primary' ).html( wpmdbGetAjaxErrors( wpmdbmf_strings.problem_migrating_media, '(#102mf)', jqXHR.responseText, jqXHR ) );
				$( '.progress-text', '.progress-wrapper-primary' ).addClass( 'migration-error' );
				console.log( jqXHR );
				console.log( textStatus );
				console.log( errorThrown );
				wpmdb.common.migration_error = true;
				wpmdb.functions.migration_complete_events();
				return;
			},
			success: function( data ) {
				var original_data = data;
				data = wpmdb_parse_json( $.trim( data ) );
				if ( !data ) {
					migration_failed( original_data );
					return;
				}

				if ( 'undefined' !== typeof data.wpmdb_error && 1 === data.wpmdb_error ) {
					migration_failed( data.body );
					return;
				}

				if ( 'undefined' !== typeof data.wpmdb_non_fatal_error && 1 === data.wpmdb_non_fatal_error ) {
					wpmdb.common.non_fatal_errors += data.body;
				}

				args.media_progress += file_chunk_size;

				current_media_progress( args );

				wpmdb.common.next_step_in_migration = { fn: migrate_media_files_recursive, args: [ args ] };
				wpmdb.functions.execute_next_step();
			}

		} );
	}

	wpmdb.functions.finalise_media_migration = function( args ) {

		// If removing local media not found on remote
		if ( 1 === args.remove_local_media ) {

			// Start recursive batch delete of local files not found on remote
			var title = 'removing_files_' + wpmdb_migration_type();
			$( '.progress-text', '.progress-wrapper-primary' ).html( wpmdbmf_strings[ title ] );

			args = {};
			args.remove_files = 1;
			args.compare = 1;
			args.offset = '';
			args.next_step_in_migration = false;
			wpmdb.common.next_step_in_migration = { fn: wpmdb.functions.remove_files_recursive, args: [ args ] };
			wpmdb.functions.execute_next_step();
			return;
		}

		wpmdb_call_next_hook();
	};

	function migration_failed( data ) {
		$( '.progress-title' ).html( wpmdbmf_strings.migration_failed );
		$( '.progress-text', '.progress-wrapper-primary' ).html( wpmdbGetAjaxErrors( '', '', data ) );
		$( '.progress-text', '.progress-wrapper-primary' ).addClass( 'migration-error' );
		$( '.progress-wrapper-secondary' ).fadeOut();
		wpmdb.common.migration_error = true;
		wpmdb.functions.migration_complete_events();
	}

	function current_media_progress( args ) {
		var percent = 100 * args.media_progress / args.total_size;
		var files_migrated = ( percent / 100 ) * args.total_files;
		set_media_progress( percent, Math.round( files_migrated ), args.total_files );
	}

	function set_media_progress( percent, progress, total ) {
		var overall_percent = Math.floor( percent );
		var title = 'migrate_media_files_' + wpmdb_migration_type();
		$( '.progress-text', '.progress-wrapper-secondary' ).html( overall_percent + '% - ' + wpmdbmf_strings[ title ] );
		var unit = '%';
		if ( 0 === percent ) {
			unit = 'px';
		}
		$( '.progress-bar', '.progress-wrapper-secondary' ).width( percent + unit );
		var text = '';
		if ( 0 <= total ) {
			text += '<span>' + wpmdbmf_strings.media_files + ' (<span class="">' + wpmdb_add_commas( progress ) + '</span> / ' + wpmdb_add_commas( total ) + ')</span>';
		}
		$( '.progress-tables', '.progress-wrapper-secondary' ).html( '<div title="" style="width: 100%;" class="progress-chunk media_files">' + text + '</div>' );
	}

	function is_media_migration() {
		if ( '1' === $( '#media-files' ).attr( 'data-available' ) && $( '#media-files' ).is( ':checked' ) ) {
			return true;
		}

		return false;
	}

	function filter_migration_profile_ready( value, args ) {
		if ( 'savefile' !== wpmdb_migration_type() && $( '#media-files' ).is( ':checked' ) ) {

			// Check that at least one subsite is selected if using limit to selected subsites option.
			if ( 'true' === wpmdb_data.is_multisite && $mf_select_subsites.is( ':checked' ) ) {
				if ( null === $( '#mf-selected-subsites' ).val() ) {
					alert( wpmdbmf_strings.please_select_a_subsite );
					value = false;
				}
			}
		}

		return value;
	}

	function compare_remove_warning_toggle() {
		var element = $( 'input[name="media_migration_option"][value="compare-remove"]' );
		if ( $( element ).is( ':checked' ) ) {
			$( '.compare-remove-warning' ).show();
		} else {
			$( '.compare-remove-warning' ).hide();
		}
	}

	function get_subsites() {
		var subsites = {};
		if ( 'pull' === wpmdb_migration_type() ) {
			if ( 'undefined' !== typeof wpmdb.mediaFiles.remote_connection_data && 'undefined' !== typeof wpmdb.mediaFiles.remote_connection_data.subsites ) {
				subsites = wpmdb.mediaFiles.remote_connection_data.subsites;
			}
		} else if ( undefined !== wpmdb_data.subsites ) {
			subsites = wpmdb_data.subsites;
		}

		return subsites;
	}

	function maybe_show_data_and_files_differ_notice() {
		var $notice = $( '.mf-selected-subsites-tables-differ' );
		var selected_subsites = $( '#mf-selected-subsites' ).val();
		var selected_tables = $.wpmdb.apply_filters( 'wpmdb_get_tables_to_migrate', null, null );

		if ( 'true' === wpmdb_data.is_multisite &&
			$mf_select_subsites.is( ':checked' ) &&
			undefined !== selected_subsites &&
			null !== selected_subsites &&
			undefined !== selected_tables &&
			null !== selected_tables &&
			0 < selected_tables.length ) {
			var table_prefix = $.wpmdb.apply_filters( 'wpmdb_get_table_prefix', null, null );
			var files_differ = false;

			$.each( selected_tables, function( index, table_name ) {
				if ( wpmdb.table_is( table_prefix, 'posts', table_name ) || wpmdb.table_is( table_prefix, 'postmeta', table_name ) ) {
					var id = wpmdb.subsite_for_table( table_prefix, table_name );
					if ( 0 > $.inArray( id.toString(), selected_subsites ) ) {
						files_differ = true;
						return false;
					}
				}
			} );

			if ( files_differ ) {
				$notice.show();
			} else {
				$notice.hide();
			}
		} else {
			$notice.hide();
		}
	}

	$( document ).ready( function() {

		if ( 'savefile' === wpmdb_migration_type() ) {
			$( '.media-files-options' ).hide();
		}

		$.wpmdb.add_action( 'move_connection_info_box', function() {
			hide_show_options( wpmdb.mediaFiles.remote_media_files_unavailable );
			wpmdb_toggle_migration_action_text();
		} );

		$.wpmdb.add_action( 'verify_connection_to_remote_site', function( connection_data ) {
			wpmdb.mediaFiles.remote_connection_data = connection_data;
			wpmdb.mediaFiles.remote_media_files_unavailable = ( 'undefined' === typeof connection_data.media_files_available );
			hide_show_options( wpmdb.mediaFiles.remote_media_files_unavailable );
		} );

		$.wpmdb.add_action( 'wpmdbmst_select_subsite_changed', function() {
			hide_show_options( wpmdb.mediaFiles.remote_media_files_unavailable );
		} );

		$.wpmdb.add_filter( 'wpmdb_before_migration_complete_hooks', function( hooks ) {
			if ( false === is_media_migration() || 'savefile' === wpmdb_migration_type() ) {
				return hooks;
			}
			hooks.push( wpmdb.functions.prepare_remove_all_files );
			return hooks;
		} );

		$( 'body' ).on( 'change', '#mf-select-subsites', function() {
			$.wpmdb.do_action( 'wpmdbmf_selected_subsites_changed' );
		} );

		$( 'body' ).on( 'change', '#mf-selected-subsites', function() {
			$.wpmdb.do_action( 'wpmdbmf_selected_subsites_changed' );
		} );

		$.wpmdb.add_filter( 'wpmdb_migration_profile_ready', filter_migration_profile_ready );
		$.wpmdb.add_action( 'wpmdb_tables_to_migrate_changed', maybe_show_data_and_files_differ_notice );
		$.wpmdb.add_action( 'wpmdbmf_selected_subsites_changed', maybe_show_data_and_files_differ_notice );

		$( 'input[name="media_migration_option"]' ).change( function() {
			compare_remove_warning_toggle();
		} );

		compare_remove_warning_toggle();
	} );

})( jQuery, wpmdb );
