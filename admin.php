<?php
/**
 * Optimize Scripts admin interface
 *
 * @todo: Clicking all-checkbox checks checkboxes in both tables
 */

/**
 * Add Optimize Scripts options page to menu
 */
function optimizescripts_action_admin_menu() {
	$plugin_page = add_options_page(
		__('Optimize Scripts Settings', OPTIMIZESCRIPTS_TEXT_DOMAIN),
		__('Optimize Scripts', OPTIMIZESCRIPTS_TEXT_DOMAIN),
		10, //admin
		'optimize-scripts-settings',
		'optimizescripts_admin_options'
	);
	//add_action('admin_head-' . $plugin_page, 'optimizescripts_option_page_header', 1);
	//if(is_page($plugin_page)){
	//	exit;
	//}
}
add_action('admin_menu', 'optimizescripts_action_admin_menu');


/**
 * Register Optimize Scripts settings and add script and stylesheet
 */
function optimizescripts_admin_init(){
	global $plugin_page;
	register_setting(
		'optimizescripts_settings',
		'optimizescripts_settings',
		'optimizescripts_validate_options'
	);
	if($plugin_page == 'optimize-scripts-settings'){
		
		$plugindir = plugin_dir_path(__FILE__);
		$pluginurl = plugin_dir_url(__FILE__);
		wp_enqueue_style('optimizescripts', $pluginurl."admin.css", array(), filemtime($plugindir."admin.css"));
		wp_enqueue_script('optimizescripts', $pluginurl."admin.js", array('jquery'), filemtime($plugindir."admin.js"), true);
	}
}
add_action('admin_init', 'optimizescripts_admin_init' );


/**
 * Validate and store settings for storage
 */
function optimizescripts_validate_options($input){
	$settings = get_option('optimizescripts_settings');
	if(!isset($input['section']))
		return $settings;
	
	$dirname = basename(trim($settings['dirname'], '/'));
	$baseUrl = trailingslashit(WP_CONTENT_URL) . $dirname;
	$baseDir = trailingslashit(WP_CONTENT_DIR) . $dirname;
	
	switch($input['section']){
		
		case 'disabled':
			$settings['disabled'] = !empty($input['disabled']);
			if(!$settings['disabled']){
				$settings['disabled_reason'] = '';
				$settings['disabled_until'] = null;
			}
			break;
		
		
		case 'optimized':
			//Bulk actions on optimized scripts
			if(!empty($input['optimized_handlehash'])){
				switch(@$input['optimized_action']){
					
					//Delete an optimized script, both from DB and from file system
					case 'delete':
						foreach((array)$input['optimized_handlehash'] as $hashhandle){
							if(isset($settings['optimized'][$hashhandle])){
								@unlink("$baseDir/$hashhandle.js");
								unset($settings['optimized'][$hashhandle]);
							}
						}
						break;
					
					//Disable an optimized script
					case 'disable':
						foreach((array)$input['optimized_handlehash'] as $hashhandle){
							if(isset($settings['optimized'][$hashhandle])){
								$settings['optimized'][$hashhandle]['disabled'] = true;
								$settings['optimized'][$hashhandle]['disabled_reason'] = __("User disabled", OPTIMIZESCRIPTS_TEXT_DOMAIN);
								//$settings['optimized'][$hashhandle]['disabled_until'] = 0;
							}
						}
						break;
					
					//Enable an optimized script
					case 'enable':
						foreach((array)$input['optimized_handlehash'] as $hashhandle){
							if(isset($settings['optimized'][$hashhandle])){
								$settings['optimized'][$hashhandle]['disabled'] = false;
								$settings['optimized'][$hashhandle]['disabled_reason'] = '';
								$settings['optimized'][$hashhandle]['disabled_until'] = 0;
							}
						}
						break;
					
					//Expire an optimized script by expiring all of its contained scripts
					case 'expire':
						foreach((array)$input['optimized_handlehash'] as $hashhandle){
							if(isset($settings['optimized'][$hashhandle]['manifest_handles'])){
								foreach($settings['optimized'][$hashhandle]['manifest_handles'] as $handle){
									$settings['cache'][$handle]['expires'] = time()-1;
								}
							}
						}
						break;
				}
			}
			break;
		
		
		case 'cached':
			//Bulk actions on cached scripts
			if(!empty($input['cached_handle'])){
				switch(@$input['cached_action']){
					
					//Delete a cached script, both from DB and from file system
					case 'delete':
						foreach((array)$input['cached_handle'] as $handle){
							if(isset($settings['cache'][$handle])){
								@unlink("$baseDir/cache/$handle.js");
								unset($settings['cache'][$handle]);
							}
						}
						break;
					
					//Disable a cached script
					case 'disable':
						foreach((array)$input['cached_handle'] as $handle){
							if(isset($settings['cache'][$handle])){
								$settings['cache'][$handle]['disabled'] = true;
								$settings['cache'][$handle]['disabled_reason'] = __("User disabled", OPTIMIZESCRIPTS_TEXT_DOMAIN);
								//$settings['optimized'][$hashhandle]['disabled_until'] = 0;
							}
						}
						break;
					
					//Enable a cached script
					case 'enable':
						foreach((array)$input['cached_handle'] as $handle){
							if(isset($settings['cache'][$handle])){
								$settings['cache'][$handle]['disabled'] = false;
								$settings['cache'][$handle]['disabled_reason'] = '';
								//$settings['cached'][$handle]['disabled_until'] = 0;
							}
						}
						break;
					
					//Expire a cached script
					case 'expire':
						foreach((array)$input['cached_handle'] as $handle){
							if(isset($settings['cache'][$handle])){
								$settings['cache'][$handle]['expires'] = time()-1;
							}
						}
						break;
				}
			}
			break;
		
		
		case 'advanced':
			if(isset($input['fetch_timeout']))
				$settings['fetch_timeout'] = (int)$input['fetch_timeout'];
			
			if(isset($input['build_timeout']))
				$settings['build_timeout'] = (int)$input['build_timeout'];
				
			if(isset($input['compilation_level']))
				$settings['compilation_level'] = $input['compilation_level'];
			
			if(isset($input['minimum_expires_time']))
				$settings['minimum_expires_time'] = (int)(floatval($input['minimum_expires_time']) * 60*60*24);
				
			$settings['use_cron'] = !empty($input['use_cron']);
			break;
	}

	return $settings;
}




/**
 * Optimize Scripts options page
 */
function optimizescripts_admin_options() {
	$settings = get_option('optimizescripts_settings');
	$dirname = basename(trim($settings['dirname'], '/'));
	$baseUrl = trailingslashit(WP_CONTENT_URL) . $dirname;
	$baseDir = trailingslashit(WP_CONTENT_DIR) . $dirname;
	
	
	//Now make an HTTP request for each of the optimized scripts' requesting_urls
	//Check to see if any of the optimized scripts have expired, and if so, make
	// a request for their associated requesting_urls so that they can be rebuilt
	$scriptsRebuilt = 0;
	foreach($settings['optimized'] as $handlehash => $optimized){
		if(!empty($optimized['requesting_url'])){
			//Get the expires time
			$expires = 0;
			foreach($optimized['manifest_handles'] as $_handle){
				if(isset($settings['cache'][$_handle])){
					$expires = $expires ? min($expires, (int)$settings['cache'][$_handle]['expires']) : (int)$settings['cache'][$_handle]['expires'];
				}
				//If it was deleted, force a rebuild
				else {
					$expires = 0;
					break;
				}
			}
			
			//If expired, then make a request for the requesting_url
			if($expires < time()){
				$useragent = new WP_Http();
				$url = add_query_arg(
					array('optimize-scripts-rebuild-nonce' => time()),
					$optimized['requesting_url']
				);
				$result = $useragent->request($url);
				//print "<pre>" . esc_attr(print_r($result, true))  . "</pre>";
				$scriptsRebuilt++;
			}
		}
	}
	
	//In case one of the HTTP requests made a change to the settings, to a raw refetch
	if($scriptsRebuilt){
		//@todo Something like this should work:
		//wp_cache_delete('optimizescripts_settings', 'options');
		//$settings = get_option('optimizescripts_settings');
		global $wpdb;
		$settings = maybe_unserialize($wpdb->get_var("SELECT option_value FROM $wpdb->options WHERE option_name = 'optimizescripts_settings'"));
	}
	
	//page=optimize-scripts-settings
	
	//$settings['disabled'] = true;
	//$settings['disabled_until'] = time()+rand()*10000;
	//$settings['disabled_reason'] = "You are cool!";
	?>
	<div class="wrap">
		<h2><?php _e(__('Optimize Scripts Settings', OPTIMIZESCRIPTS_TEXT_DOMAIN)) ?></h2>
<?php _e(<<<ECTEXT
			<p>Please read the <a href="http://wordpress.org/extend/plugins/optimize-scripts/" target="_blank">plugin documentation</a>.</p>
ECTEXT
, OPTIMIZESCRIPTS_TEXT_DOMAIN); ?>
			
		<form method="post" action="options.php" id="optimizescripts_disabled_field" class="<?php echo @$settings['disabled'] ? 'disabled' : 'enabled'; ?>">
			<?php settings_fields( 'optimizescripts_settings' ); ?>
			<input type="hidden" name="optimizescripts_settings[section]" value="disabled" />
			<!--<p>-->
				<input type="checkbox" name="optimizescripts_settings[disabled]" id="optimizescripts_disabled" <?php if(@$settings['disabled']): ?>checked="checked"<?php endif; ?> />
				<label for="optimizescripts_disabled"><?php _e('Functionality disabled', OPTIMIZESCRIPTS_TEXT_DOMAIN); ?></label>
			<!--</p>-->
			<?php if(!empty($settings['disabled_until'])): ?>
			<p>
				<strong><?php _e('Disabled until:', OPTIMIZESCRIPTS_TEXT_DOMAIN) ?></strong>
				<time datetime="<?php echo gmdate('c', $settings['disabled_until']) ?>"><?php echo date('c', $settings['disabled_until']) ?></time>
			</p>
			<?php endif; ?>
			
			<?php if(@$settings['disabled_reason']): ?>
			<p>
				<strong><?php _e('Reason:', OPTIMIZESCRIPTS_TEXT_DOMAIN) ?></strong>
				<?php echo @$settings['disabled_reason']; ?>
			</p>
			
			<?php endif; ?>
			<p class="description">
<?php _e(<<<ECTEXT
			You may manually enable or disable this plugin be changing this
			option. If an exception is raised during the rebuilding process
			(concatenation/compilation) then disabled option is set. 
ECTEXT
, OPTIMIZESCRIPTS_TEXT_DOMAIN); ?>
			</p>
			<p class='submit'><input type="submit" value="Update »" /></p>
		</form>
			

		<form method="post" action="options.php">
			<?php settings_fields( 'optimizescripts_settings' ); ?>
			<input type="hidden" name="optimizescripts_settings[section]" value="optimized" />
			<h3 id="current_optimized_scripts"><?php _e('Current Optimized Scripts', OPTIMIZESCRIPTS_TEXT_DOMAIN); ?></h3>
			<?php if(!empty($settings['optimized'])): ?>
			<table id="optimized_scripts_table" class="widefat page " cellspacing="0">
				<thead>
					<tr>
						<th class="manage-column column-cb check-column" scope="col"><input type="checkbox" /></th>
						<th class="manage-column" scope="col"><?php _e('Status', OPTIMIZESCRIPTS_TEXT_DOMAIN) ?></th>
						<th class="manage-column" scope="col"><?php _e('Contents', OPTIMIZESCRIPTS_TEXT_DOMAIN) ?></th>
						<th class="manage-column" scope="col"><?php _e('Created', OPTIMIZESCRIPTS_TEXT_DOMAIN) ?></th>
						<th class="manage-column" scope="col"><?php _e('Modified', OPTIMIZESCRIPTS_TEXT_DOMAIN) ?></th>
						<th class="manage-column" scope="col"><?php _e('Expires', OPTIMIZESCRIPTS_TEXT_DOMAIN) ?></th>
					</tr>
				</thead>
				<tbody>
				<?php
				$alt = false;
				foreach($settings['optimized'] as $handlehash => $optimized): ?>
					<?php
					//$optimizedScriptExists = file_exists("$baseDir/$handlehash.js");
					$isCronBuildingForFirstTime = !empty($optimized['pending_cron_build']) && empty($optimized['ctime']);
					?>
					<tr class="<?php if($alt) echo 'alternate '; $alt=!$alt; if(!empty($optimized['disabled'])) echo ' disabled'; ?>">
						<th class='check-column'><input type="checkbox" <?php if($isCronBuildingForFirstTime): ?>disabled=""<?php endif; ?> scope="row" name="optimizescripts_settings[optimized_handlehash][]" value="<?php echo esc_attr($handlehash) ?>" /></th>
						<td>
							<?php if(!$isCronBuildingForFirstTime):?>
								<p><strong><a title="<?php esc_attr_e("View optimized script (opens in new window)") ?>" href="<?php echo esc_attr("$baseUrl/$handlehash.js") ?>" target="_blank"><?php echo esc_attr($handlehash); ?></a></strong></p>
							<?php else: ?>
								<p><strong><?php echo esc_attr($handlehash); ?></strong></p>
							<?php endif; ?>
							
							<!-- status + (disabled_until) + (disabled_until) -->
							<?php
							//$optimized['disabled'] = true;
							//$optimized['disabled_until'] = time()-3400;
							//$optimized['disabled_reason'] = "You!";
							?>
							<?php if(!empty($optimized['disabled'])): ?>
								<div class='optimized_disabled_info'>
								<p><strong><em><?php _e('Disabled', OPTIMIZESCRIPTS_TEXT_DOMAIN); ?></em></strong></p>
								<?php if($optimized['disabled_reason'] || $optimized['disabled_until']): ?>
									<?php
									if($optimized['disabled_until']): ?>
										<p><?php _e('Duration:', OPTIMIZESCRIPTS_TEXT_DOMAIN); ?>
										<time datetime="<?php echo gmdate('c', $optimized['disabled_until']) ?>"
											  title="<?php echo esc_attr(sprintf(__('Until %s', OPTIMIZESCRIPTS_TEXT_DOMAIN), date('c', $optimized['ctime']))) ?>">
											<?php
											$disabledRemaining = time() - $optimized['disabled_until'];
											if($disabledRemaining < 60)
												printf(__("For %s more second(s).", OPTIMIZESCRIPTS_TEXT_DOMAIN), $disabledRemaining);
											else if($disabledRemaining < 60*60)
												printf(__("For %s more minute(s).", OPTIMIZESCRIPTS_TEXT_DOMAIN), round($disabledRemaining/60, 1));
											else if($disabledRemaining < 60*60*24)
												printf(__("For %s more hour(s).", OPTIMIZESCRIPTS_TEXT_DOMAIN), round($disabledRemaining/60/60, 1));
											else
												printf(__("For %s more day(s).", OPTIMIZESCRIPTS_TEXT_DOMAIN), round($disabledRemaining/60/60/24, 1));
											?>
										</time>
										</p>
									<?php endif; ?>
									<?php if($optimized['disabled_reason']): ?>
										<p><?php _e('Reason:', OPTIMIZESCRIPTS_TEXT_DOMAIN); ?>
										<?php print $optimized['disabled_reason']; ?>
										</p>
									<?php endif; ?>
								<?php endif; ?>
								</p>
								</div>
							<?php elseif(!$isCronBuildingForFirstTime): ?>
								<p><em><?php _e('Enabled', OPTIMIZESCRIPTS_TEXT_DOMAIN) ?></em></p>
							<?php endif; ?>
							
							<?php
							$compilationResultFilename = "$handlehash.compilationResult.xml"; 
							if(!empty($settings['compilation_level']) && file_exists("$baseDir/$compilationResultFilename")):
							?>
							<p>
								<a href="<?php echo "$baseUrl/$compilationResultFilename" ?>" target="_blank"><?php _e("View compilation results from last build", OPTIMIZESCRIPTS_TEXT_DOMAIN) ?></a>
								<!-- insert warnings and errors here in the future -->
							</p>
							<?php endif; ?>
						</td>
						<td>
							<?php if(!empty($optimized['pending_cron_build'])): ?>
								<p><em><?php _e("Pending cron build", OPTIMIZESCRIPTS_TEXT_DOMAIN) ?></em></p>
							<?php else: ?>
								<ol>
									<?php foreach($optimized['manifest_handles'] as $_handle): ?>
										<li>
											<?php if(!empty($settings['cache'][$_handle])): ?>
												<a href="#optimizescripts-cache-<?php echo esc_attr($_handle) ?>"><abbr title="<?php echo esc_attr($settings['cache'][$_handle]['src']) ?>"><?php echo esc_attr($_handle) ?></abbr></a>
											<?php else: ?>
												<?php echo esc_attr($_handle) ?><abbr title="<?php esc_attr_e("This doesn't appear in the cache (below)", OPTIMIZESCRIPTS_TEXT_DOMAIN) ?>">?</abbr>
											<?php endif; ?>
										</li>
									<?php endforeach; ?>
								</ol>
							<?php endif; ?>
						</td>
						<td>
							<?php if(empty($optimized['ctime'])): ?>
								<?php if(!empty($optimized['pending_cron_build'])): ?>
									<em><?php _e("Pending cron build", OPTIMIZESCRIPTS_TEXT_DOMAIN) ?></em>
								<?php else: ?>
									<em><?php _e("Unknown", OPTIMIZESCRIPTS_TEXT_DOMAIN) ?></em>
								<?php endif; ?>
							<?php else: ?>
								<time datetime="<?php echo gmdate('c', $optimized['ctime']) ?>"
									  title="<?php echo esc_attr(date('c', $optimized['ctime'])) ?>">
									<?php echo date(get_option('date_format'), $optimized['ctime']) ?>
								</time>
							<?php endif; ?>
						</td>
						<td>
							<?php if(empty($optimized['mtime'])): ?>
								<?php if(!empty($optimized['pending_cron_build'])): ?>
									<em><?php _e("Pending cron build", OPTIMIZESCRIPTS_TEXT_DOMAIN) ?></em>
								<?php else: ?>
									<em><?php _e("Unknown", OPTIMIZESCRIPTS_TEXT_DOMAIN) ?></em>
								<?php endif; ?>
							<?php else: ?>
								<time datetime="<?php echo gmdate('c', $optimized['mtime']) ?>" title="<?php echo date('c', $optimized['mtime']) ?>">
									<?php //echo gmdate('c', $optimized['mtime'])
									$timeModifiedAgo = time() - $optimized['mtime'];
									if($timeModifiedAgo < 60)
										printf(__("%s second(s) ago", OPTIMIZESCRIPTS_TEXT_DOMAIN), $timeModifiedAgo);
									elseif($timeModifiedAgo < 60*60)
										printf(__("%s minute(s) ago", OPTIMIZESCRIPTS_TEXT_DOMAIN), round($timeModifiedAgo/60, 1));
									elseif($timeModifiedAgo < 60*60*24)
										printf(__("%s hour(s) ago", OPTIMIZESCRIPTS_TEXT_DOMAIN), round($timeModifiedAgo/60/60, 1));
									else
										printf(__("%s day(s) ago", OPTIMIZESCRIPTS_TEXT_DOMAIN), round($timeModifiedAgo/60/60/24, 1));
									?>
								</time>
							
								<?php if(!empty($optimized['requesting_url'])): ?>
									<?php _e("@", OPTIMIZESCRIPTS_TEXT_DOMAIN) ?>
									<a href="<?php echo esc_attr($optimized['requesting_url']) ?>" target="_blank" title="<?php esc_attr_e("URL of the page that requested this script last") ?>"><?php _e("URL", OPTIMIZESCRIPTS_TEXT_DOMAIN) ?></a>
								<?php endif; ?>
								
								<?php if(!empty($optimized['pending_cron_build'])): ?>
									<p><em><?php _e("Pending cron rebuild", OPTIMIZESCRIPTS_TEXT_DOMAIN) ?></em></p>
								<?php endif; ?>
							<?php endif; ?>
						</td>
						<td>
							<?php if(empty($optimized['manifest_handles'])): ?>
								<?php if(!empty($optimized['pending_cron_build'])): ?>
									<em><?php _e("Pending cron build", OPTIMIZESCRIPTS_TEXT_DOMAIN) ?></em>
								<?php else: ?>
									<em><?php _e("Unknown", OPTIMIZESCRIPTS_TEXT_DOMAIN) ?></em>
								<?php endif; ?>
							<?php else: 
								//Get the expires date by iterating over all of he scripts
								// in the optimized script and getting the min expires
								$expires = 0;
								foreach($optimized['manifest_handles'] as $_handle){
									if(isset($settings['cache'][$_handle])){
										$expires = $expires ? min($expires, (int)$settings['cache'][$_handle]['expires']) : (int)$settings['cache'][$_handle]['expires'];
									}
								}
								if(!$expires/* && empty($optimized['pending_cron_build'])*/): ?>
									<em><?php _e("Unknown", OPTIMIZESCRIPTS_TEXT_DOMAIN) ?></em>
								<?php else: ?>
									<time datetime="<?php echo gmdate('c', $expires) ?>"
										  title="<?php echo esc_attr(date('c', $expires)) ?>">
										<?php
										$lifeRemaining = $expires - time();
										//if($lifeRemaining <= 0 && !empty($optimized['pending_cron_build']))
										//	_e("expired &amp; rebuilding", OPTIMIZESCRIPTS_TEXT_DOMAIN);
										//else if(!empty($optimized['pending_cron_build']))
										//	_e("rebuilding", OPTIMIZESCRIPTS_TEXT_DOMAIN);
										//else
										if($lifeRemaining <= 0)
											_e("expired", OPTIMIZESCRIPTS_TEXT_DOMAIN);
										else if($lifeRemaining < 60)
											printf(__("%d second(s)", OPTIMIZESCRIPTS_TEXT_DOMAIN), $lifeRemaining);
										else if($lifeRemaining < 60*60)
											printf(__("%s minute(s)", OPTIMIZESCRIPTS_TEXT_DOMAIN), round($lifeRemaining/60, 1));
										else if($lifeRemaining < 60*60*24)
											printf(__("%s hour(s)", OPTIMIZESCRIPTS_TEXT_DOMAIN), round($lifeRemaining/60/60, 1));
										else
											printf(__("%s day(s)", OPTIMIZESCRIPTS_TEXT_DOMAIN), round($lifeRemaining/60/60/24, 1));
										?>
									</time>
									<abbr class='rebuildCount' title="<?php
											echo esc_attr(str_replace(
												array('%build_count'),
												array($optimized['build_count']),
												__("Rebuilt %build_count time(s) since initial creation", OPTIMIZESCRIPTS_TEXT_DOMAIN)));
											if($optimized['last_build_reason']){
												echo ' ' . esc_attr(sprintf(__("Reason for last rebuild: %s", OPTIMIZESCRIPTS_TEXT_DOMAIN), $optimized['last_build_reason']));
											}
										?>">
										<?php echo esc_attr(str_replace(
											'%build_count',
											$optimized['build_count'],
											__('%build_count×', OPTIMIZESCRIPTS_TEXT_DOMAIN))
										); ?>
									</abbr>
								<?php endif; ?>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<select name="optimizescripts_settings[optimized_action]">
				<option value=''><?php _e('Bulk actions') ?></option>
				<option value='disable'><?php _e('Disable', OPTIMIZESCRIPTS_TEXT_DOMAIN) ?></option>
				<option value='enable'><?php _e('Enable', OPTIMIZESCRIPTS_TEXT_DOMAIN) ?></option>
				<option value='expire'><?php _e('Expire (revalidate)', OPTIMIZESCRIPTS_TEXT_DOMAIN) ?></option>
				<option value='delete'><?php _e('Delete (force rebuild)', OPTIMIZESCRIPTS_TEXT_DOMAIN) ?></option>
			</select>
			<input class='button-secondary action' type="submit" value="<?php esc_attr_e('Apply') ?>" />
			<?php else: ?>
				<p><em>
<?php _e(<<<ECTEXT
				No scripts have been optimized. Perhaps you don't have any
				scripts enqueued, or none of them are concatenable? Otherwise,
				perhaps you haven't made a request on a page that prints the
				scripts as this is where the optimization logic occurs.
ECTEXT
, OPTIMIZESCRIPTS_TEXT_DOMAIN); ?>
				</em></p>
			<?php endif; ?>
			
			<!--<pre><?php //echo esc_attr(print_r($settings['optimized'], true)); ?></pre>-->
		</form>
			
			
			
		<form method="post" action="options.php">
			<?php settings_fields( 'optimizescripts_settings' ); ?>
			<input type="hidden" name="optimizescripts_settings[section]" value="cached" />
			
			<h3 id="registered_script_cache"><?php _e('Registered Script Cache', OPTIMIZESCRIPTS_TEXT_DOMAIN); ?></h3>
			<?php if(!empty($settings['cache'])): ?>
				<table id="cached_scripts_table" class="widefat page " cellspacing="0">
					<thead>
						<tr>
							<th class="manage-column column-cb check-column" scope="col"><input type="checkbox" /></th>
							<th class="manage-column" scope="col"><?php _e('Status', OPTIMIZESCRIPTS_TEXT_DOMAIN) ?></th>
							<th class="manage-column" scope="col"><?php _e('Created', OPTIMIZESCRIPTS_TEXT_DOMAIN) ?></th>
							<th class="manage-column" scope="col"><?php _e('Modified', OPTIMIZESCRIPTS_TEXT_DOMAIN) ?></th>
							<th class="manage-column" scope="col"><?php _e('Expires', OPTIMIZESCRIPTS_TEXT_DOMAIN) ?></th>
						</tr>
					</thead>
					<tbody>
					<?php
					$alt = false;
					foreach($settings['cache'] as $handle => $cached): ?>
						<tr id="optimizescripts-cache-<?php echo $handle; ?>" class="<?php if($alt) echo 'alternate '; $alt=!$alt; if($cached['disabled']) echo ' disabled'; ?>">
							<th class='check-column'><input type="checkbox" scope="row" name="optimizescripts_settings[cached_handle][]" value="<?php echo esc_attr($handle) ?>" /></th>
							<td>
								<p><strong><a title="<?php esc_attr_e("View optimized script (opens in new window)") ?>" href="<?php echo esc_attr("$baseUrl/cache/$handle.js") ?>" target="_blank"><?php echo esc_attr($handle); ?></a></strong></p>
								
								<!-- status + (disabled_until) + (disabled_until) -->
								<?php
								//$cached['disabled'] = true;
								//$cached['disabled_until'] = time()-3400;
								//$cached['disabled_reason'] = "You!";
								?>
								<?php if($cached['disabled']): ?>
									<div class='cached_disabled_info'>
									<p><strong><em><?php _e('Disabled', OPTIMIZESCRIPTS_TEXT_DOMAIN); ?></em></strong></p>
									<?php if(!empty($cached['disabled_reason']) || !empty($cached['disabled_until'])): ?>
										<?php
										if(!empty($cached['disabled_until'])): ?>
											<p><?php _e('Duration:', OPTIMIZESCRIPTS_TEXT_DOMAIN); ?>
											<time datetime="<?php echo gmdate('c', $cached['disabled_until']) ?>"
												  title="<?php echo esc_attr(sprintf(__('Until %s', OPTIMIZESCRIPTS_TEXT_DOMAIN), date('c', $cached['ctime']))) ?>">
												<?php
												$disabledRemaining = time() - $cached['disabled_until'];
												if($disabledRemaining < 60)
													printf(__("For %s more second(s).", OPTIMIZESCRIPTS_TEXT_DOMAIN), $disabledRemaining);
												else if($disabledRemaining < 60*60)
													printf(__("For %s more minute(s).", OPTIMIZESCRIPTS_TEXT_DOMAIN), round($disabledRemaining/60, 1));
												else if($disabledRemaining < 60*60*24)
													printf(__("For %s more hour(s).", OPTIMIZESCRIPTS_TEXT_DOMAIN), round($disabledRemaining/60/60, 1));
												else
													printf(__("For %s more day(s).", OPTIMIZESCRIPTS_TEXT_DOMAIN), round($disabledRemaining/60/60/24, 1));
												?>
											</time>
											</p>
										<?php endif; ?>
										<?php if($cached['disabled_reason']): ?>
											<p><?php _e('Reason:', OPTIMIZESCRIPTS_TEXT_DOMAIN); ?>
											<?php print $cached['disabled_reason']; ?>
											</p>
										<?php endif; ?>
									<?php endif; ?>
									</p>
									</div>
								<?php else: ?>
									<p><em><?php _e('Enabled', OPTIMIZESCRIPTS_TEXT_DOMAIN) ?></em></p>
								<?php endif; ?>
							</td>
							<td>
								<?php if(!$cached['ctime']): ?>
									<em><?php _e("Unknown", OPTIMIZESCRIPTS_TEXT_DOMAIN) ?></em>
								<?php else: ?>
									<time datetime="<?php echo gmdate('c', $cached['ctime']) ?>"
										  title="<?php echo esc_attr(date('c', $cached['ctime'])) ?>">
										<?php echo date(get_option('date_format'), $cached['ctime']) ?>
									</time>
								<?php endif; ?>
							</td>
							<td>
								<?php if(!$cached['mtime']): ?>
									<em><?php _e("Unknown", OPTIMIZESCRIPTS_TEXT_DOMAIN) ?></em>
								<?php else: ?>
									<time datetime="<?php echo gmdate('c', $cached['mtime']) ?>" title="<?php echo date('c', $cached['mtime']) ?>">
										<?php //echo gmdate('c', $cached['mtime'])
										$timeModifiedAgo = time() - $cached['mtime'];
										if($timeModifiedAgo < 60)
											printf(__("%s second(s) ago", OPTIMIZESCRIPTS_TEXT_DOMAIN), $timeModifiedAgo);
										elseif($timeModifiedAgo < 60*60)
											printf(__("%s minute(s) ago", OPTIMIZESCRIPTS_TEXT_DOMAIN), round($timeModifiedAgo/60, 1));
										elseif($timeModifiedAgo < 60*60*24)
											printf(__("%s hour(s) ago", OPTIMIZESCRIPTS_TEXT_DOMAIN), round($timeModifiedAgo/60/60, 1));
										else
											printf(__("%s day(s) ago", OPTIMIZESCRIPTS_TEXT_DOMAIN), round($timeModifiedAgo/60/60/24, 1));
										?>
									</time>
								<?php endif; ?>
							
								<?php if(!empty($cached['requesting_url'])): ?>
									@ <a href="<?php echo esc_attr($cached['requesting_url']) ?>" target="_blank" title="<?php esc_attr_e("URL of the page that requested this script last") ?>"><?php _e("URL", OPTIMIZESCRIPTS_TEXT_DOMAIN) ?></a>
								<?php endif; ?>
							</td>
							<td>
								<?php if(!$cached['expires']): ?>
									<em><?php _e("Unknown", OPTIMIZESCRIPTS_TEXT_DOMAIN) ?></em>
								<?php else: ?>
									<time datetime="<?php echo gmdate('c', (int)$cached['expires']) ?>"
										  title="<?php echo esc_attr(date('c', (int)$cached['expires'])) ?>">
										<?php
										$lifeRemaining = (int)$cached['expires'] - time();
										if($lifeRemaining <= 0)
											_e("expired", OPTIMIZESCRIPTS_TEXT_DOMAIN);
										else if($lifeRemaining < 60)
											printf(__("%d second(s)", OPTIMIZESCRIPTS_TEXT_DOMAIN), $lifeRemaining);
										else if($lifeRemaining < 60*60)
											printf(__("%s minute(s)", OPTIMIZESCRIPTS_TEXT_DOMAIN), round($lifeRemaining/60, 1));
										else if($lifeRemaining < 60*60*24)
											printf(__("%s hour(s)", OPTIMIZESCRIPTS_TEXT_DOMAIN), round($lifeRemaining/60/60, 1));
										else
											printf(__("%s day(s)", OPTIMIZESCRIPTS_TEXT_DOMAIN), round($lifeRemaining/60/60/24, 1));
										?>
									</time>
									<abbr class='requestCount' title="<?php
											echo esc_attr(str_replace(
												array('%request_count'),
												array($cached['request_count']),
												__("Requested %request_count time(s) since initial creation", OPTIMIZESCRIPTS_TEXT_DOMAIN)));
											//if($cached['last_build_reason']){
											//	echo ' ' . esc_attr(sprintf(__("Reason for last rebuild: %s", OPTIMIZESCRIPTS_TEXT_DOMAIN), $cached['last_build_reason']));
											//}
										?>">
										<?php echo esc_attr(str_replace(
											'%request_count',
											$cached['request_count'],
											__('%request_count×', OPTIMIZESCRIPTS_TEXT_DOMAIN))
										); ?>
									</abbr>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<select name="optimizescripts_settings[cached_action]">
					<option value=''><?php _e('Bulk actions') ?></option>
					<option value='disable'><?php _e('Disable', OPTIMIZESCRIPTS_TEXT_DOMAIN) ?></option>
					<option value='enable'><?php _e('Enable', OPTIMIZESCRIPTS_TEXT_DOMAIN) ?></option>
					<option value='expire'><?php _e('Expire (revalidate)', OPTIMIZESCRIPTS_TEXT_DOMAIN) ?></option>
					<option value='delete'><?php _e('Delete', OPTIMIZESCRIPTS_TEXT_DOMAIN) ?></option>
				</select>
				<input class='button-secondary action' type="submit" value="<?php esc_attr_e('Apply') ?>" />
			
			<?php else: ?>
				<p><em>
<?php _e(<<<ECTEXT
				No scripts exist in the cache. Either you don't have any scripts
				enqueued, haven't made a request on a page that prints the
				scripts as this is where the optimization logic occurs.
ECTEXT
, OPTIMIZESCRIPTS_TEXT_DOMAIN); ?>
				</em></p>
			<?php endif; ?>
		</form>
			
			<!--<pre><?php //echo esc_attr(print_r($settings['cache'], true)); ?></pre>-->
			
		<form method="post" action="options.php">
			<?php settings_fields( 'optimizescripts_settings' ); ?>
			<input type="hidden" name="optimizescripts_settings[section]" value="advanced" />
			
			<h3 id="optimizescripts_advanced_options"><?php _e('Advanced Options', OPTIMIZESCRIPTS_TEXT_DOMAIN); ?></h3>
			<table class="form-table">
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
				<tr>
					<th scope="row"><label for="optimizescripts_fetch_timeout"><?php _e("Script fetch timeout", OPTIMIZESCRIPTS_TEXT_DOMAIN) ?></label></th>
					<td>
						<input type="number" id="optimizescripts_fetch_timeout" name="optimizescripts_settings[fetch_timeout]" min="0" step="1" class="small-text" value="<?php echo esc_attr(@$settings['fetch_timeout']) ?>" /><?php esc_attr_e(" seconds", OPTIMIZESCRIPTS_TEXT_DOMAIN) ?>
						<p class="description">
							<?php _e("Maximum amount of time permitted to fetch a script for optimization (HTTP GET).", OPTIMIZESCRIPTS_TEXT_DOMAIN); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="optimizescripts_build_timeout"><?php _e("Script build timeout", OPTIMIZESCRIPTS_TEXT_DOMAIN) ?></label></th>
					<td>
						<input type="number" id="optimizescripts_build_timeout" name="optimizescripts_settings[build_timeout]" min="0" step="1" class="small-text" value="<?php echo esc_attr(@$settings['build_timeout']) ?>" /><?php esc_attr_e(" seconds", OPTIMIZESCRIPTS_TEXT_DOMAIN) ?>
						<p class="description">
							<?php _e("Maximum amount of time permitted to build fetched scripts via Google's Closure Compiler (HTTP POST).", OPTIMIZESCRIPTS_TEXT_DOMAIN); ?>
						</p
					</td>
				</tr>
				<th scope="row"><label for="optimizescripts_minimum_expires_time"><?php _e("Minimum Expires Time", OPTIMIZESCRIPTS_TEXT_DOMAIN) ?></label></th>
				<td>
					<input type="number" id="optimizescripts_minimum_expires_time" name="optimizescripts_settings[minimum_expires_time]" min="0" class="small-text" value="<?php echo esc_attr(@$settings['minimum_expires_time']/60/60/24) ?>" /><?php esc_attr_e(" days", OPTIMIZESCRIPTS_TEXT_DOMAIN) ?>
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
						<input type="checkbox" name="optimizescripts_settings[use_cron]" id="optimizescripts_use_cron" <?php if(@$settings['use_cron']): ?>checked="checked"<?php endif; ?> />
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
						optimized scripts. This improves load time as the page doesn't have to wait
						for the scripts to be optimized. <strong>Warning!</strong>
						Should not be enabled if using a caching plugin like WP Super Cache.
ECTEXT
, OPTIMIZESCRIPTS_TEXT_DOMAIN); ?>
						</p>
					</td>
				</tr>
			</table>
			<p class="submit">
				<input type="submit" class="button-primary" value="<?php esc_attr_e('Save Changes') ?>" />
			</p>
		</form>
		
		<pre><?php echo esc_attr(print_r($settings, true)); ?></pre>
	</div>
	<?php
}
