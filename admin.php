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
 * @todo
 */
function optimizescripts_register_settings(){
	register_setting(
		'optimizescripts_settings',
		'optimizescripts_settings',
		'optimizescripts_validate_options'
	);
	//register_setting( 'optimizescripts', 'compilation_level' );
	//register_setting( 'optimizescripts', 'minimum_expires_time', 'intval');
}

/**
 * Validate and store settings for storage
 */
function optimizescripts_validate_options($input){
	$settings = get_option('optimizescripts_settings');
	$settings['disabled'] = !empty($input['disabled']);
	if(!$settings['disabled'])
		$settings['disabled_reason'] = '';
	
	$settings['compilation_level'] = $input['compilation_level'];
	$settings['minimum_expires_time'] = intval($input['minimum_expires_time']);
	$settings['use_cron'] = !empty($input['use_cron']);
	return $settings;
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
	?>
	<div class="wrap">
		<h2><?php _e(__('Optimize Scripts Options', OPTIMIZESCRIPTS_TEXT_DOMAIN)) ?></h2>
		<form method="post" action="options.php">
			<?php settings_fields( 'optimizescripts_settings' ); ?>
			<input type="hidden" name="action" value="update" />
<?php _e(<<<ECTEXT
			<p>Please read the <a href="http://wordpress.org/extend/plugins/optimize-scripts/" target="_blank">plugin documentation</a>.</p>
ECTEXT
, OPTIMIZESCRIPTS_TEXT_DOMAIN); ?>
			
			
			<table class="form-table">
				<tr class="<?php echo @$settings['optimizescripts_disabled'] ? 'disabled' : 'enabled'; ?>">
					<th><label for="optimizescripts_disabled"><?php _e('Functionality Disabled', OPTIMIZESCRIPTS_TEXT_DOMAIN); ?></label></th>
					<td>
						<input type="checkbox" name="optimizescripts_settings[disabled]" id="optimizescripts_disabled" <?php if(@$settings['disabled']): ?>checked="checked"<?php endif; ?> />
						<span class="description">
<?php _e(<<<ECTEXT
						You may manually enable or disable this
						plugin be changing this option. If an exception is raised during the rebuilding process (concatenation/compilation)
						then disabled option is set. 
ECTEXT
, OPTIMIZESCRIPTS_TEXT_DOMAIN); ?>
						</span>						
						<?php if(@$settings['disabled']): ?>
						<p>
							<strong><?php _e('Reason disabled:', OPTIMIZESCRIPTS_TEXT_DOMAIN) ?></strong>
							<?php
							echo (@$settings['disabled_reason'] ?
								  esc_attr(@$settings['disabled_reason']) :
								  '<i>' . __('Unknown', OPTIMIZESCRIPTS_TEXT_DOMAIN) . '</i>'
							); ?>
						</p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="optimizescripts_compilation_level"><?php _e("Google Closure Compiler Compilation Level", OPTIMIZESCRIPTS_TEXT_DOMAIN) ?></label></th>
					<td>
						<select id="optimizescripts_compilation_level" name="optimizescripts_settings[compilation_level]">
							<?php
							$options = array(
								'' => __('None (concatenation only)', OPTIMIZESCRIPTS_TEXT_DOMAIN),
								'WHITESPACE_ONLY' => __('Whitespace only', OPTIMIZESCRIPTS_TEXT_DOMAIN),
								'SIMPLE_OPTIMIZATIONS' => __('Simple optimizations', OPTIMIZESCRIPTS_TEXT_DOMAIN),
								'ADVANCED_OPTIMIZATIONS' => __('Advanced optimizations', OPTIMIZESCRIPTS_TEXT_DOMAIN),
							);
							foreach($options as $value => $text){
								print "<option value='" . esc_attr($value) . "'";
								if($value == $settings['compilation_level'])
									print " selected='selected'";
								print ">";
								print esc_attr($text);
								print "</option>";
							}
							?>
						</select>
						<p class="description">
<?php _e(<<<ECTEXT
						Read about the compilation level options <a href="http://code.google.com/closure/compiler/docs/api-ref.html#level">Closure Compiler Service API Reference</a>.
						<em><strong>Warning!</strong> Advanced compilation should only be employed with care; simple optimizations are recommended otherwise. <a href="http://code.google.com/closure/compiler/docs/api-tutorial3.html" title="Advanced Compilation and Externs">Read more</a>.</em>
ECTEXT
, OPTIMIZESCRIPTS_TEXT_DOMAIN); ?>
						</p>
					</td>
				</tr>
				<th scope="row"><label for="optimizescripts_minimum_expires_time"><?php _e("Minimum Expires Time", OPTIMIZESCRIPTS_TEXT_DOMAIN) ?></label></th>
				<td>
					<input type="number" id="optimizescripts_minimum_expires_time" name="optimizescripts_settings[minimum_expires_time]" min="0" step="1" value="<?php echo esc_attr(@$settings['minimum_expires_time']) ?>" /><?php esc_attr_e(" seconds (345600 is six days)", OPTIMIZESCRIPTS_TEXT_DOMAIN) ?>
						<!--<br />-->
						<!--<span>--><p class="description">
<?php _e(<<<ECTEXT
						This specifies the minimum amount of time into the
						future that a requested script may expire. For example,
						Google Analytics' <a href="http://www.google-analytics.com/ga.js" target="_blank">ga.js</a>
						is set to expire after 7 days,
						but <a href="http://ajax.googleapis.com/ajax/libs/jquery/1.3.2/jquery.min.js" target="_blank">jQuery 1.3.2</a>
						on Google Ajax APIs expires after one year. The sooner
						the expiration time, the sooner that the script will
						need to be refetched and the concatenated script bundle
						potentially recompiled. Thus if a script has an
						expiration time that is not far enough into the future
						according to this option, then an exception will be
						raised which will prevent the scripts from getting
						concatenated and compiled. This is a performance
						safeguard. It is a performance <a href="http://developer.yahoo.com/performance/rules.html#expires" target="_blank">best practice</a>
						best to serve static scripts with a far-future
						<code>expires</code> header. Otherwise, if the script is
						dynamic, be able to return <code>304 Not Modified</code>
						responses: if a resource does expire and the server
						indicates that it has not been modified, then the
						concatenated scripts will not need to be recompiled.
						Dynamic scripts that change frequently should be prevented 
						from being selected for concatenation by using the <code>optimizescripts_concatenable</code>
						filter or by disabling them in the cache below.
ECTEXT
, OPTIMIZESCRIPTS_TEXT_DOMAIN); ?>
						</p>
						<!--</span>-->
					</td>
				</tr>
				<tr>
					<th><label for="optimizescripts_use_cron"><?php _e('Use Cron for Rebuilding', OPTIMIZESCRIPTS_TEXT_DOMAIN); ?></label></th>
					<td>
						<input type="checkbox" name="optimizescripts_settings[use_cron]" id="optimizescripts_use_cron" <?php if(@$settings['minimum_expires_time']): ?>checked="checked"<?php endif; ?> />
						<p class="description">
<?php _e(<<<ECTEXT
						In a production environment (without <code>define('SCRIPT_DEBUG', true)</code>),
						with this option enabled, requests for pages
						which have has concatenable scripts which need to be rebuilt
						will not respond immediately with scripts optimized (they
						will all be returned without any concatenation or compilation); instead,
						that request will schedule an immediate cron job which will
						be executed in the background to rebuild the scripts
						and once it finishes then subsequent responses will include
						optimized scripts. <wrong>Warning!</wrong> Should not be
						enabled if using a caching plugin like WP Super Cache.
ECTEXT
, OPTIMIZESCRIPTS_TEXT_DOMAIN); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th><?php _e('Current Optimized Scripts', OPTIMIZESCRIPTS_TEXT_DOMAIN); ?></th>
					<td>
						Show list of all, their statuses, their contents,
						delete them (what happens when DB entry is deleted but filesystem remains?), 
						disabled.
						<pre><?php echo esc_attr(print_r($settings['concatenated'], true)); ?></pre>
					</td>
				</tr>
				<tr>
					<th><?php _e('Registered Scripts (Cache)', OPTIMIZESCRIPTS_TEXT_DOMAIN); ?></th>
					<td>
						Inspect the cache, allow each to be disabled individually:
						this individual disabling
						<pre><?php echo esc_attr(print_r($settings['cache'], true)); ?></pre>
					</td>
				</tr>
			</table>
			<p class="submit">
				<input type="submit" class="button-primary" value="<?php esc_attr_e('Save Changes') ?>" />
			</p>
			<pre><?php echo esc_attr(print_r($settings, true)); ?></pre>
		</form>
	</div>
	<?php
}
