<?php global $loaded_profile; ?>
<div class="option-section media-files-options">

	<label class="media-files checkbox-label" for="media-files">
		<input type="checkbox" name="media_files" value="1" data-available="1" id="media-files"<?php echo ( isset( $loaded_profile['media_files'] ) ? ' checked="checked"' : '' ); ?> />
		Media Files
	</label>
	
	<div class="indent-wrap expandable-content">
		
		<ul>
			<li id="remove-local-media-list-item">
				<label for="remove-local-media" class="remove-local-media">
				<input type="checkbox" name="remove_local_media" value="1" id="remove-local-media"<?php echo ( isset( $loaded_profile['remove_local_media'] ) ? ' checked="checked"' : '' ); ?> />
				Remove <span class="remove-scope-1">local</span> media files that are not found on the <span class="remove-scope-2">remote</span> site
				</label>
			</li>
		</ul>

	</div>

	<p class="media-migration-unavailable inline-message warning" style="display: none; margin: 10px 0 0 0;">
		<strong>Addon Missing</strong> &mdash; The Media Files addon is
		inactive on the <strong>remote site</strong>. Please install and activate it 
		to enable media file&nbsp;migration.
	</p>

	<p class="media-files-different-plugin-version-notice inline-message warning" style="display: none; margin: 10px 0 0 0;">
		<strong>Version Mismatch</strong> &mdash; We've detected you have version <span class="media-file-remote-version"></span> of WP Migrate DB Pro Media Files at <span class="media-files-remote-location"></span> but are using <?php echo $GLOBALS['wpmdb_meta'][$this->plugin_slug]['version']; ?> here. Please go to the <a href="<?php echo network_admin_url( 'plugins.php' ); ?>">Plugins page</a> on both installs and check for updates.
	</p>

</div>
