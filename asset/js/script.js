// functions
var prepare_remove_all_files;
var remove_files_recursive;
var prepare_determine_media;
var determine_media_to_migrate_recursive;
var remote_media_files_unavailable = false;
var remote_connection_data;
var connection_info;
var media_successfully_determined;
var finalise_media_migration;

(
	function( $ ) {

		// .length doesn't work on JS "associative arrays" i.e. objects with key/value elements, this does
		Object.size = function( obj ) {
			var size = 0, key;
			for ( key in obj ) {
				if ( obj.hasOwnProperty( key ) ) {
					size ++;
				}
			}
			return size;
		};

		$( document ).ready( function() {

			if ( migration_type() == 'savefile' ) {
				$( '.media-files-options' ).hide();
			}

			var disable_media_files_option = function() {
				$( '#media-files' ).attr( 'data-available', '0' );
				$( '#media-files' ).prop( 'checked', false );
				$( '#media-files' ).attr( 'disabled', 'disabled' );
				$( '.media-files' ).addClass( 'disabled' );
				$( '.media-files-options .expandable-content' ).hide();
			};

			var hide_show_options = function( unavailable ) {
				var mig_type = migration_type();

				if ( 'savefile' == mig_type ) {
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

				if ( typeof remote_connection_data != 'undefined' && wpmdb_media_files_version != remote_connection_data.media_files_version ) {
					$( '.media-files-remote-location' ).html( remote_connection_data.url );
					$( '.media-file-remote-version' ).html( remote_connection_data.media_files_version );
					$( '.media-files-different-plugin-version-notice' ).show();
					disable_media_files_option();
					return;
				}

				$( '.media-files-options ul' ).show();
				$( '.media-migration-unavailable' ).hide();
				$( '.media-files-different-plugin-version-notice' ).hide();
				$( '#media-files' ).removeAttr( 'disabled' );
				$( '.media-files' ).removeClass( 'disabled' );
				$( '#media-files' ).attr( 'data-available', '1' );
			};

			$.wpmdb.add_action( 'move_connection_info_box', function() {
				hide_show_options( remote_media_files_unavailable );
				action_text_toggle();
			} );

			$.wpmdb.add_action( 'verify_connection_to_remote_site', function( connection_data ) {
				remote_connection_data = connection_data;
				remote_media_files_unavailable = (
				typeof connection_data.media_files_available == 'undefined'
				);
				hide_show_options( remote_media_files_unavailable );
			} );

			$.wpmdb.add_filter( 'wpmdb_before_migration_complete_hooks', function( hooks ) {
				if ( false == is_media_migration() || 'savefile' == migration_type() ) {
					return hooks;
				}
				hooks.push( 'prepare_remove_all_files' );
				return hooks;
			} );

			prepare_remove_all_files = function() {
				connection_info = $.trim( $( '.pull-push-connection-info' ).val() ).split( "\n" );
				var media_type = $( 'input[name="media_migration_option"]:checked' ).val();

				$( '.progress-tables' ).empty();
				$( '.progress-tables-hover-boxes' ).empty();
				$( '.progress-bar' ).width( '0px' );

				// this only needs to be run if we are skipping the comparison
				if ( 'compare' != media_type ) {
					var title = 'removing_all_files_' + migration_type();
					$( '.progress-text' ).not( '.media' ).html( wpmdbmf_strings[ title ] );

					// start recursive batch delete of local files
					var args = {};
					args.remove_files = 1;
					args.compare = 0;
					args.offset = 0;
					args.next_step_in_migration = 'prepare_determine_media';
					next_step_in_migration = { fn: remove_files_recursive, args: [ args ] };
					execute_next_step();

				} else {
					// we are doing the comparison so lets start the determine
					next_step_in_migration = { fn: prepare_determine_media };
					execute_next_step();
				}
			}

			remove_files_recursive = function( args ) {
				if ( 0 == args.remove_files ) {
					// all files removed lets start the migration
					if ( false !== args.next_step_in_migration ) {
						next_step_in_migration = { fn: window[ args.next_step_in_migration ] };
						execute_next_step();
					}
					else {
						wpmdb_call_next_hook();
					}
					return;
				}
				connection_info = $.trim( $( '.pull-push-connection-info' ).val() ).split( "\n" );

				var old_args = args;

				$.ajax( {
					url     : ajaxurl,
					type    : 'POST',
					dataType: 'text',
					cache   : false,
					data    : {
						action : 'wpmdbmf_remove_files_recursive',
						compare: args.compare,
						offset : args.offset,
						intent : migration_type(),
						url    : connection_info[ 0 ],
						key    : connection_info[ 1 ],
						nonce  : wpmdb_nonces.remove_files_recursive
					},
					error   : function( jqXHR, textStatus, errorThrown ) {
						$( '.progress-title' ).html( wpmdbmf_strings.migration_failed );
						$( '.progress-text' ).html( wpmdbGetAjaxErrors( wpmdbmf_strings.error_determining, '(#101mf)', jqXHR.responseText, jqXHR ) );
						$( '.progress-text' ).addClass( 'migration-error' );
						console.log( jqXHR );
						console.log( textStatus );
						console.log( errorThrown );
						migration_error = true;
						migration_complete_events();
						return;
					},
					success : function( data ) {
						original_data = data;
						args = wpmdb_parse_json( $.trim( data ) );
						if ( false == args ) {
							migration_failed( original_data );
							return;
						}

						if ( typeof args.wpmdb_error != 'undefined' && args.wpmdb_error == 1 ) {
							migration_failed( args.body );
							return;
						}

						if ( typeof args.wpmdb_non_fatal_error != 'undefined' && args.wpmdb_non_fatal_error == 1 ) {
							non_fatal_errors += args.body;
						}

						// persist settings
						args.next_step_in_migration = old_args.next_step_in_migration;

						next_step_in_migration = { fn: remove_files_recursive, args: [ args ] };
						execute_next_step();
					}
				} );
			}

			prepare_determine_media = function() {
				connection_info = $.trim( $( '.pull-push-connection-info' ).val() ).split( "\n" );

				var remove_local_media = 0;
				var copy_entire_media = 0;
				var media_type = $( 'input[name="media_migration_option"]:checked' ).val();

				$( '.progress-tables' ).empty();
				$( '.progress-tables' ).html( '<div title="' + wpmdbmf_strings.media_files + '" style="width: 100%;" class="progress-chunk media_files"><span></div>' );
				$( '.progress-text' ).not( '.media' ).html( '0% - ' + wpmdbmf_strings.determining );

				if ( 'compare' == media_type ) {
					if ( $( '#remove-local-media' ).is( ':checked' ) ) {
						remove_local_media = 1;
					}
				} else {
					copy_entire_media = 1;
				}

				var args = {};

				$.ajax( {
					url     : ajaxurl,
					type    : 'POST',
					dataType: 'text',
					cache   : false,
					data    : {
						action     : 'wpmdbmf_prepare_determine_media',
						intent     : migration_type(),
						url        : connection_info[ 0 ],
						key        : connection_info[ 1 ],
						temp_prefix: connection_data.temp_prefix,
						nonce      : wpmdb_nonces.prepare_determine_media
					},
					error   : function( jqXHR, textStatus, errorThrown ) {
						$( '.progress-title' ).html( wpmdbmf_strings.migration_failed );
						$( '.progress-text' ).html( wpmdbGetAjaxErrors( wpmdbmf_strings.error_determining, '(#101mf)', jqXHR.responseText, jqXHR ) );
						$( '.progress-text' ).addClass( 'migration-error' );
						console.log( jqXHR );
						console.log( textStatus );
						console.log( errorThrown );
						migration_error = true;
						migration_complete_events();
						return;
					},
					success : function( data ) {
						original_data = data;
						args = wpmdb_parse_json( $.trim( data ) );
						if ( false == args ) {
							migration_failed( original_data );
							return;
						}

						if ( typeof args.wpmdb_error != 'undefined' && args.wpmdb_error == 1 ) {
							migration_failed( args.body );
							return;
						}

						args.determine_progress = 0;
						args.remove_local_media = remove_local_media;
						args.copy_entire_media = copy_entire_media;

						$( '.progress-tables' ).html( '<div title="' + wpmdbmf_strings.media_attachments + '" style="width: 100%;" class="progress-chunk media_files"><span>' + wpmdbmf_strings.media_attachments + ' (<span class="">0</span> / ' + wpmdb_add_commas( args.attachment_count ) + ')</span></div>' );

						next_step_in_migration = { fn: determine_media_to_migrate_recursive, args: [ args ] };
						execute_next_step();
					}
				} );
			}

			determine_media_to_migrate_recursive = function( args ) {
				if ( args.determine_progress == args.attachment_count ) {
					$( '.media-progress' ).hide();
					$( '.progress-bar' ).width( '0px' );
					$( '.progress-tables' ).empty();

					// finalise migration
					next_step_in_migration = { fn: finalise_media_migration, args: [ args ] };
					execute_next_step();
					return;
				}
				connection_info = $.trim( $( '.pull-push-connection-info' ).val() ).split( "\n" );

				$.ajax( {
					url     : ajaxurl,
					type    : 'POST',
					dataType: 'text',
					cache   : false,
					data    : {
						action                : 'wpmdbmf_determine_media_to_migrate_recursive',
						determine_progress    : args.determine_progress,
						attachment_count      : args.attachment_count,
						remote_uploads_url    : args.remote_uploads_url,
						remove_local_media    : args.remove_local_media,
						copy_entire_media     : args.copy_entire_media,
						prefix                : args.prefix,
						blogs                 : args.blogs,
						attachment_batch_limit: args.attachment_batch_limit,
						intent                : migration_type(),
						url                   : connection_info[ 0 ],
						key                   : connection_info[ 1 ],
						temp_prefix           : connection_data.temp_prefix,
						nonce                 : wpmdb_nonces.determine_media_to_migrate_recursive
					},
					error   : function( jqXHR, textStatus, errorThrown ) {
						$( '.progress-title' ).html( wpmdbmf_strings.migration_failed );
						$( '.progress-text' ).not( '.media' ).html( wpmdbmf_strings.error_determining + ' (#101mf)' );
						$( '.progress-text' ).not( '.media' ).addClass( 'migration-error' );
						console.log( jqXHR );
						console.log( textStatus );
						console.log( errorThrown );
						migration_error = true;
						migration_complete_events();
						return;
					},
					success : function( data ) {
						original_data = data;
						data = wpmdb_parse_json( $.trim( data ) );
						if ( false == data ) {
							migration_failed( original_data );
							return;
						}

						if ( typeof data.wpmdb_error != 'undefined' && data.wpmdb_error == 1 ) {
							migration_failed( data.body );
							return;
						}

						args.blogs = data.blogs;
						args.determine_progress = data.determine_progress;
						args.total_size = data.total_size;
						args.files_to_migrate = data.files_to_migrate;

						var percent = 100 * args.determine_progress / args.attachment_count;
						$( '.progress-bar' ).not( '.media' ).width( percent + '%' );
						overall_percent = Math.floor( percent );
						$( '.progress-text' ).not( '.media' ).html( overall_percent + '% - ' + wpmdbmf_strings.determining );
						$( '.progress-tables' ).not( '.media' ).html( '<div title="' + wpmdbmf_strings.media_attachments + '" style="width: 100%;" class="progress-chunk media_files"><span>' + wpmdbmf_strings.media_attachments + ' (<span class="">' + wpmdb_add_commas( args.determine_progress ) + '</span> / ' + wpmdb_add_commas( args.attachment_count ) + ')</span></div>' );

						next_step_in_migration = { fn: media_successfully_determined, args: [ args ] };
						execute_next_step();
					}

				} );
			}

			media_successfully_determined = function( args ) {
				if ( typeof args.wpmdb_error != 'undefined' && args.wpmdb_error == 1 ) {
					non_fatal_errors += data.body;
					next_step_in_migration = { fn: wpmdb_call_next_hook };
					execute_next_step();
					return;
				}

				args.media_progress = 0;
				args.media_progress_image_number = 0;
				args.bottleneck = wpmdb_max_request;
				args.total_files = Object.size( args.files_to_migrate );

				$( '.media-progress' ).show();
				set_media_progress( 0, 0, args.total_files );

				next_step_in_migration = { fn: migrate_media_files_recursive, args: [ args ] };
				execute_next_step();
			}

			function migrate_media_files_recursive( args ) {
				if ( 0 == Object.size( args.files_to_migrate ) ) {
					if ( 0 == args.total_size ) {
						set_media_progress( 0, 0, 0 );
					} else {
						current_media_progress( args );
					}

					delete args.files_to_migrate;
					delete args.total_size;

					next_step_in_migration = { fn: determine_media_to_migrate_recursive, args: [ args ] };
					execute_next_step();
					return;
				}

				var file_chunk_to_migrate = [];
				var file_chunk_size = 0;
				var number_of_files_to_migrate = 0;

				$.each( args.files_to_migrate, function( index, value ) {
					if ( ! file_chunk_to_migrate.length ) {
						file_chunk_to_migrate.push( index );
						file_chunk_size += value;
						delete args.files_to_migrate[ index ];
						++ args.media_progress_image_number;
						++ number_of_files_to_migrate;
					}
					else {
						if ( (
						     file_chunk_size + value
						     ) > args.bottleneck || number_of_files_to_migrate >= remote_connection_data.media_files_max_file_uploads ) {
							return false;
						}
						else {
							file_chunk_to_migrate.push( index );
							file_chunk_size += value;
							delete args.files_to_migrate[ index ];
							++ args.media_progress_image_number;
							++ number_of_files_to_migrate;
						}
					}
				} );

				var connection_info = $.trim( $( '.pull-push-connection-info' ).val() ).split( "\n" );

				$.ajax( {
					url     : ajaxurl,
					type    : 'POST',
					dataType: 'text',
					cache   : false,
					data    : {
						action            : 'wpmdbmf_migrate_media',
						file_chunk        : file_chunk_to_migrate,
						remote_uploads_url: args.remote_uploads_url,
						intent            : migration_type(),
						url               : connection_info[ 0 ],
						key               : connection_info[ 1 ],
						nonce             : wpmdb_nonces.migrate_media,
					},
					error   : function( jqXHR, textStatus, errorThrown ) {
						$( '.progress-title' ).html( 'Migration failed' );
						$( '.progress-text' ).not( '.media' ).html( wpmdbGetAjaxErrors( wpmdbmf_strings.problem_migrating_media, '(#102mf)', jqXHR.responseText, jqXHR ) );
						$( '.progress-text' ).not( '.media' ).addClass( 'migration-error' );
						console.log( jqXHR );
						console.log( textStatus );
						console.log( errorThrown );
						migration_error = true;
						migration_complete_events();
						return;
					},
					success : function( data ) {
						original_data = data;
						data = wpmdb_parse_json( $.trim( data ) );
						if ( false == data ) {
							migration_failed( original_data );
							return;
						}

						if ( typeof data.wpmdb_error != 'undefined' && data.wpmdb_error == 1 ) {
							migration_failed( data.body );
							return;
						}

						if ( typeof data.wpmdb_non_fatal_error != 'undefined' && data.wpmdb_non_fatal_error == 1 ) {
							non_fatal_errors += data.body;
						}

						args.media_progress += file_chunk_size;

						current_media_progress( args );

						next_step_in_migration = { fn: migrate_media_files_recursive, args: [ args ] };
						execute_next_step();
					}

				} );
			}

			finalise_media_migration = function( args ) {
				// if removing local media not found on remote
				if ( 1 == args.remove_local_media ) {
					// start recursive batch delete of local files not found on remote
					var title = 'removing_files_' + migration_type();
					$( '.progress-text' ).not( '.media' ).html( wpmdbmf_strings[ title ] );
					var args = {};
					args.remove_files = 1;
					args.compare = 1;
					args.offset = '';
					args.next_step_in_migration = false;
					next_step_in_migration = { fn: remove_files_recursive, args: [ args ] };
					execute_next_step();
					return;
				}

				wpmdb_call_next_hook();
			}

			function migration_failed( data ) {
				$( '.progress-title' ).html( wpmdbmf_strings.migration_failed );
				$( '.progress-text' ).not( '.media' ).html( wpmdbGetAjaxErrors( '', '', data ) );
				$( '.progress-text' ).not( '.media' ).addClass( 'migration-error' );
				$( '.media-progress' ).fadeOut();
				migration_error = true;
				migration_complete_events();
			}

			function current_media_progress( args ) {
				var percent = 100 * args.media_progress / args.total_size;
				var files_migrated = ( percent / 100 ) * args.total_files;
				set_media_progress( percent, Math.round( files_migrated ), args.total_files );
			}

			function set_media_progress( percent, progress, total ) {
				var overall_percent = Math.floor( percent );
				var title = 'migrate_media_files_' + migration_type();
				$( '.media.progress-text' ).html( overall_percent + '% - ' + wpmdbmf_strings[ title ] );
				var unit = '%';
				if ( 0 == percent ) {
					unit = 'px';
				}
				$( '.media.progress-bar' ).width( percent + unit );
				var text = '';
				if ( total > 0 ) {
					text += '<span>' + wpmdbmf_strings.media_files + ' (<span class="">' + wpmdb_add_commas( progress ) + '</span> / ' + wpmdb_add_commas( total ) + ')</span>'
				}
				$( '.media.progress-tables' ).html( '<div title="" style="width: 100%;" class="progress-chunk media_files">' + text + '</div>' );
			}

			function is_media_migration() {
				return $( '#media-files' ).attr( 'data-available' ) == '1' && $( '#media-files' ).is( ':checked' ) ? true : false;
			}

			function migration_type() {
				return $( 'input[name=action]:checked' ).val();
			}

			function action_text_toggle() {
				$( '.action-text' ).hide();
				$( '.action-text.' + migration_type() ).show();
			}

			function media_options_toggle( element ) {
				if ( $( element ).is( ':checked' ) && $( element ).val() == 'entire' ) {
					$( '#remove-local-media' ).prop( "disabled", true );
					$( '#remove-local-media' ).prop( "checked", false );
				} else {
					$( '#remove-local-media' ).prop( "disabled", false );
				}
			}

			$( 'input[name="media_migration_option"]' ).each( function() {
				media_options_toggle( this );
			} );

			$( 'input[name="media_migration_option"]' ).change( function() {
				media_options_toggle( this );
			} );

		} );

	}
)( jQuery );