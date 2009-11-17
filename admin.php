<?php
/**
 * Optimize Scripts admin interface
 */

/**
 * Add Optimize Scripts options page to menu
 */
function optimizescripts_action_admin_menu() {
	add_options_page(
		__('Optimize Scripts Options', OPTIMIZESCRIPTS_TEXT_DOMAIN),
		__('Optimize Scripts', OPTIMIZESCRIPTS_TEXT_DOMAIN),
		10, //admin
		'optimize-scripts-options',
		'optimizescripts_admin_options'
	);
}


/**
 * Register Optimize Scripts settings
 */
function optimizescripts_register_settings(){
	//register_setting( 'optimizescripts-group', 'optimizescripts_api_key' );
}


if(is_admin()){
	add_action('admin_menu', 'optimizescripts_action_admin_menu');
	add_action('admin_init', 'optimizescripts_register_settings' );
}

/**
 * Optimize Scripts options page
 */
function optimizescripts_admin_options() {
	$settings = get_option('optimizescripts_settings');

	//$page_options = array(
	//	'optimizescripts_gcal_feed_url'
	//);
	//register_setting( 'my_options_group', 'my_option_name', 'intval' );
	?>
	<div class="wrap">
		<h2><?php _e(__('Optimize Scripts Options', OPTIMIZESCRIPTS_TEXT_DOMAIN)) ?></h2>
		<form method="post" action="options.php">
			<?php settings_fields( 'optimizescripts-group' ); ?>
			<input type="hidden" name="action" value="update" />
<?php _e(<<<ECTEXT
			<p>Please read the <a href="http://wordpress.org/extend/plugins/optimize-scripts/" target="_blank">plugin documentation</a>.</p>
ECTEXT
, OPTIMIZESCRIPTS_TEXT_DOMAIN); ?>
			
			
			<table class="form-table">
				<tr valign="top">
					<th scope="row"><label for="optimizescripts_compilation_level"><?php _e("Google Closure Compiler Compilation Level", OPTIMIZESCRIPTS_TEXT_DOMAIN) ?></label></th>
					<td>
						<select id="optimizescripts_compilation_level" name="optimizescripts_compilation_level">
							<?php
							$options = array(
								'' => __('None (concatenation only)', OPTIMIZESCRIPTS_TEXT_DOMAIN),
								'WHITESPACE_ONLY' => __('Whitespace only', OPTIMIZESCRIPTS_TEXT_DOMAIN),
								'SIMPLE_OPTIMIZATIONS' => __('Simple optimizations', OPTIMIZESCRIPTS_TEXT_DOMAIN),
								'ADVANCED_OPTIMIZATIONS' => __('Advanced optimizations', OPTIMIZESCRIPTS_TEXT_DOMAIN),
							);
							foreach($options as $value => $text){
								print "<option value='" . esc_attr($value) . "'";
								if($text == $settings['compilation_level'])
									print " selected='selected'";
								print ">";
								print esc_attr($text);
								print "</option>";
							}
							?>
						</select>
						<br />
						<span class="description">
<?php _e(<<<ECTEXT
						Read about the compilation level options <a href="http://code.google.com/closure/compiler/docs/api-ref.html#level">Closure Compiler Service API Reference</a>.
						<em><strong>Warning!</strong> Advanced compilation must only be employed with care; simple optimizations are recommended otherwise. <a href="http://code.google.com/closure/compiler/docs/api-tutorial3.html" title="Advanced Compilation and Externs">Read more</a>.</em>
ECTEXT
, OPTIMIZESCRIPTS_TEXT_DOMAIN); ?>
						</span>
					</td>
				</tr>
				<th scope="row"><label for="optimizescripts_minimum_expires_time"><?php _e("Minimum Expires Time", OPTIMIZESCRIPTS_TEXT_DOMAIN) ?></label></th>
				<td>
					<input type="number" id="optimizescripts_minimum_expires_time" name="optimizescripts_minimum_expires_time" min="0" step="1" value="<?php echo esc_attr(@$settings['minimum_expires_time']) ?>" /><?php esc_attr_e(" seconds (345600 is six days)", OPTIMIZESCRIPTS_TEXT_DOMAIN) ?>
						<br />
						<span class="description">
<?php _e(<<<ECTEXT
						This specifies the minimum amount of time into the future
						that a requested script may expire. For example, Google Analytics' <a href="http://www.google-analytics.com/ga.js" target="_blank">ga.js</a>
						is set to expire after 7 days, but <a href="http://ajax.googleapis.com/ajax/libs/jquery/1.3.2/jquery.min.js" target="_blank">jQuery 1.3.2</a> on Google Ajax APIs
						expires after one year. The sooner the expiration time, the sooner that the script will need to be
						refetched and the concatenated script bundle potentially recompiled. Thus if a script has an expiration time that
						is not far enough into the future according to this option, then
						an exception will be raised which will prevent the scripts
						from getting concatenated and compiled. This is a performance safeguard.
						It is a performance <a href="http://developer.yahoo.com/performance/rules.html#expires" target="_blank">best practice</a> best to serve static scripts with
						a far-future <code>expires</code> header. Otherwise, if the script is dynamic,
						be able to return <code>304 Not Modified</code> responses:
						if a resource does expire and the server indicates that it
						has not been modified, then the concatenated scripts will not need
						to be recompiled.
						
ECTEXT
, OPTIMIZESCRIPTS_TEXT_DOMAIN); ?>
						</span>
				</td>
			</table>
			<p class="submit">
				<input type="submit" class="button-primary" value="<?php _e('Save Changes') ?>" />
			</p>
			<pre><?php echo esc_attr(print_r($settings, true)); ?></pre>
		</form>
	</div>
	<?php
}
