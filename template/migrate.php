<?php global $loaded_profile; ?>
<div class="option-section media-files-options">

	<label class="media-files checkbox-label" for="media-files">
		<input type="checkbox" name="media_files" value="1" data-available="1" id="media-files"<?php echo ( isset( $loaded_profile['media_files'] ) ? ' checked="checked"' : '' ); ?> />
		<?php _e( 'Media Files', 'wp-migrate-db-pro-media-files' ); ?>
	</label>
	
	<div class="indent-wrap expandable-content">
		<?php
		$media_migration_option = isset( $loaded_profile['media_migration_option'] ) ? $loaded_profile['media_migration_option'] : 'compare';
		?>
		<ul>
			<li id="compare-media-list-item">
				<label for="compare-media" class="compare-media">
					<input type="radio" name="media_migration_option" value="compare" id="compare-media"<?php checked( $media_migration_option, 'compare', true ); ?> />
					<span class="action-text push">
						<?php _e( 'Compare remote and local media files and only upload those missing or updated', 'wp-migrate-db-pro-media-files' ); ?>
					</span>
					<span class="action-text pull">
						<?php _e( 'Compare remote and local media files and only download those missing or updated', 'wp-migrate-db-pro-media-files' ); ?>
					</span>
				</label>
				<label for="remove-local-media" class="remove-local-media sub-option">
					<input type="checkbox" name="remove_local_media" value="1" id="remove-local-media"<?php echo( isset( $loaded_profile['remove_local_media'] ) ? ' checked="checked"' : '' ); ?> />
					<span class="action-text push">
						<?php _e( 'Remove remote media files that are not found on the local site', 'wp-migrate-db-pro-media-files' ); ?>
					</span>
					<span class="action-text pull">
						<?php _e( 'Remove local media files that are not found on the remote site', 'wp-migrate-db-pro-media-files' ); ?>
					</span>
				</label>
			</li>
			<li id="copy-entire-media-list-item">
				<label for="copy-entire-media" class="copy-entire-media">
					<input type="radio" name="media_migration_option" value="entire" id="copy-entire-media"<?php checked( $media_migration_option, 'entire', true ); ?> />
					<span class="action-text push">
						<?php _e( 'Remove all remote media files and upload all local media files (skips comparison)', 'wp-migrate-db-pro-media-files' ); ?>
					</span>
					<span class="action-text pull">
						<?php _e( 'Remove all local media files and download all remote media files (skips comparison)', 'wp-migrate-db-pro-media-files' ); ?>
					</span>
				</label>
			</li>
		</ul>

	</div>

	<p class="media-migration-unavailable inline-message warning" style="display: none; margin: 10px 0 0 0;">
		<strong><?php _e( 'Addon Missing', 'wp-migrate-db-pro-media-files' ); ?></strong> &mdash; <?php _e( 'The Media Files addon is inactive on the <strong>remote site</strong>. Please install and activate it to enable media file migration.', 'wp-migrate-db-pro-media-files' ); ?>
	</p>

	<p class="media-files-different-plugin-version-notice inline-message warning" style="display: none; margin: 10px 0 0 0;">
		<strong><?php _e( 'Version Mismatch', 'wp-migrate-db-pro-media-files' ); ?></strong> &mdash; <?php _e( sprintf( 'We have detected you have version <span class="media-file-remote-version"></span> of WP Migrate DB Pro Media Files at <span class="media-files-remote-location"></span> but are using %1$s here. Please go to the <a href="%2$s">Plugins page</a> on both installs and check for updates.', $GLOBALS['wpmdb_meta'][$this->plugin_slug]['version'], network_admin_url( 'plugins.php' ) ), 'wp-migrate-db-pro-media-files' ); ?> 
	</p>

</div>
