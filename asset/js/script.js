// functions
var determine_media_to_sync;
var remote_media_files_unavailable = false;
var remote_connection_data;
var connection_info;
var media_successfully_determined;

(function($) {

  // .length doesn't work on JS "associative arrays" i.e. objects with key/value elements, this does
  Object.size = function(obj) {
    var size = 0,
      key;
    for (key in obj) {
      if (obj.hasOwnProperty(key)) size++;
    }
    return size;
  };

  $(document).ready(function() {

    if (syncing_type() == 'savefile') {
      $('.media-files-options').hide();
    }

    var disable_media_files_option = function() {
      $('#media-files').attr('data-available', '0');
      $('#media-files').prop('checked', false);
      $('#media-files').attr('disabled', 'disabled');
      $('.media-files').addClass('disabled');
      $('.media-files-options .expandable-content').hide();
    };

    var hide_show_options = function(unavailable) {
      var mig_type = syncing_type();

      if ('savefile' == mig_type) {
        $('.media-files-options').hide();
        return;
      }

      $('.media-files-options').show();
      $('.media-files-push').hide();

      if (unavailable) {
        $('.media-files-options ul').hide();
        $('.media-syncing-unavailable').show();
        disable_media_files_option();
        return;
      }

      if (typeof remote_connection_data != 'undefined' &&
        wpsdb_media_files_version != remote_connection_data.media_files_version
      ) {
        $('.media-files-remote-location').html(remote_connection_data.url);
        $('.media-file-remote-version').html(remote_connection_data.media_files_version);
        $('.media-files-different-plugin-version-notice').show();
        disable_media_files_option();
        return;
      }

      $('.media-files-options ul').show();
      $('.media-syncing-unavailable').hide();
      $('.media-files-different-plugin-version-notice').hide();
      $('#media-files').removeAttr('disabled');
      $('.media-files').removeClass('disabled');
      $('#media-files').attr('data-available', '1');
    };

    $.wpsdb.add_action('move_connection_info_box', function() {
      hide_show_options(remote_media_files_unavailable);
      $('.remove-scope-1').html('remote');
      $('.remove-scope-2').html('local');
      if (syncing_type() == 'pull') {
        $('.remove-scope-1').html('local');
        $('.remove-scope-2').html('remote');
      }
    });

    $.wpsdb.add_action('verify_connection_to_remote_site', function(
      connection_data) {
      remote_connection_data = connection_data;
      remote_media_files_unavailable = (typeof connection_data.media_files_available ==
        'undefined');
      hide_show_options(remote_media_files_unavailable);
    });

    $.wpsdb.add_filter('wpsdb_before_syncing_complete_hooks', function(
      hooks) {
      if (false == is_media_syncing() || 'savefile' == syncing_type())
        return hooks;
      hooks.push('determine_media_to_sync');
      return hooks;
    });

    determine_media_to_sync = function() {
      connection_info = $.trim($('.pull-push-connection-info').val()).split(
        "\n");
      $('.progress-text').html(wpsdbmf_strings.determining);

      var remove_local_media = 0;

      if ($('#remove-local-media').is(':checked')) {
        remove_local_media = 1;
      }

      $.ajax({
        url: ajaxurl,
        type: 'POST',
        dataType: 'text',
        cache: false,
        data: {
          action: 'wpsdbmf_determine_media_to_sync',
          remove_local_media: remove_local_media,
          intent: syncing_type(),
          url: connection_info[0],
          key: connection_info[1],
          temp_prefix: connection_data.temp_prefix,
          nonce: wpsdb_nonces.determine_media_to_sync,
        },
        error: function(jqXHR, textStatus, errorThrown) {
          $('.progress-title').html(wpsdbmf_strings.syncing_failed);
          $('.progress-text').html(wpsdbmf_strings.error_determining +
            ' (#101mf)');
          $('.progress-text').addClass('syncing-error');
          console.log(jqXHR);
          console.log(textStatus);
          console.log(errorThrown);
          syncing_error = true;
          syncing_complete_events();
          return;
        },
        success: function(data) {
          original_data = data;
          data = JSON.parse(data.trim());
          if (false == data) {
            syncing_failed(original_data);
            return;
          }

          next_step_in_syncing = {
            fn: media_successfully_determined,
            args: [data]
          };
          execute_next_step();
        }

      });

    }

    function syncing_failed(data) {
      $('.progress-title').html(wpsdbmf_strings.syncing_failed);
      $('.progress-text').html(data);
      $('.progress-text').addClass('syncing-error');
      syncing_error = true;
      syncing_complete_events();
    }

    media_successfully_determined = function(data) {
      if (typeof data.wpsdb_error != 'undefined' && data.wpsdb_error == 1) {
        non_fatal_errors += data.body;
        next_step_in_syncing = {
          fn: wpsdb_call_next_hook
        };
        execute_next_step();
        return;
      }

      var args = {};
      args.media_progress = 0;
      args.media_progress_image_number = 0;
      args.media_total_size = data.total_size;
      args.remote_uploads_url = data.remote_uploads_url;
      args.files_to_sync = data.files_to_sync;

      args.bottleneck = wpsdb_max_request;

      if (Object.size(args.files_to_sync) > 0) {
        $('.progress-bar').width('0px');
      }

      $('.progress-tables').empty();
      $('.progress-tables-hover-boxes').empty();

      $('.progress-tables').prepend('<div title="' + wpsdbmf_strings.media_files +
        '" style="width: 100%;" class="progress-chunk media_files"><span>' +
        wpsdbmf_strings.media_files +
        ' (<span class="media-syncing-current-image">0</span> / ' +
        wpsdb_add_commas(Object.size(args.files_to_sync)) +
        ')</span></div>');

      next_step_in_syncing = {
        fn: sync_media_files_recursive,
        args: [args]
      };
      execute_next_step();
    }

    function sync_media_files_recursive(args) {
      if (0 == Object.size(args.files_to_sync)) {
        wpsdb_call_next_hook();
        return;
      }

      var file_chunk_to_sync = [];
      var file_chunk_size = 0;
      var number_of_files_to_sync = 0;

      $.each(args.files_to_sync, function(index, value) {
        if (!file_chunk_to_sync.length) {
          file_chunk_to_sync.push(index);
          file_chunk_size += value;
          delete args.files_to_sync[index];
          ++args.media_progress_image_number;
          ++number_of_files_to_sync;
        } else {
          if ((file_chunk_size + value) > args.bottleneck ||
            number_of_files_to_sync >= remote_connection_data.media_files_max_file_uploads
          ) {
            return false;
          } else {
            file_chunk_to_sync.push(index);
            file_chunk_size += value;
            delete args.files_to_sync[index];
            ++args.media_progress_image_number;
            ++number_of_files_to_sync;
          }
        }
      });

      var connection_info = $.trim($('.pull-push-connection-info').val()).split(
        "\n");

      $.ajax({
        url: ajaxurl,
        type: 'POST',
        dataType: 'text',
        cache: false,
        data: {
          action: 'wpsdbmf_sync_media',
          file_chunk: file_chunk_to_sync,
          remote_uploads_url: args.remote_uploads_url,
          intent: syncing_type(),
          url: connection_info[0],
          key: connection_info[1],
          nonce: wpsdb_nonces.sync_media,
        },
        error: function(jqXHR, textStatus, errorThrown) {
          $('.progress-title').html('Syncing failed');
          $('.progress-text').html(wpsdbmf_strings.problem_syncing_media +
            ' (#102mf)');
          $('.progress-text').addClass('syncing-error');
          console.log(jqXHR);
          console.log(textStatus);
          console.log(errorThrown);
          syncing_error = true;
          syncing_complete_events();
          return;
        },
        success: function(data) {
          original_data = data;
          data = JSON.parse(data.trim());
          if (false == data) {
            syncing_failed(original_data);
            return;
          }

          if (typeof data.wpsdb_error != 'undefined' && data.wpsdb_error ==
            1) {
            non_fatal_errors += data.body;
          }

          args.media_progress += file_chunk_size;

          var percent = 100 * args.media_progress / args.media_total_size;
          $('.progress-bar').width(percent + '%');
          overall_percent = Math.floor(percent);

          $('.progress-text').html(overall_percent + '% - ' +
            wpsdbmf_strings.syncing_media_files);
          $('.media-syncing-current-image').html(wpsdb_add_commas(args.media_progress_image_number));

          next_step_in_syncing = {
            fn: sync_media_files_recursive,
            args: [args]
          };
          execute_next_step();
        }
      });
    }

    function is_media_syncing() {
      return $('#media-files').attr('data-available') == '1' && $(
        '#media-files').is(':checked') ? true : false;
    }

    function syncing_type() {
      return $('input[name=action]:checked').val();
    }
  });
})(jQuery);
