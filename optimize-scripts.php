<?php
/*
Plugin Name: Optimize Scripts
Plugin URI: http://wordpress.org/extend/plugins/optimize-scripts/
Description: Concatenates scripts and then minifies and optimizes them using Google's Closure Compiler (but not if <code>define('SCRIPT_DEBUG', true)</code> or <code>define('CONCATENATE_SCRIPTS', false)</code>). For non-concatenable scripts, removes default WordPress 'ver' query param so that Web-wide cacheability isn't broken for scripts hosted on ajax.googleapis.com, for example. No admin page yet provided.
Version: 0.5 (alpha)
Author: Weston Ruter
Author URI: http://weston.ruter.net/
Copyright: 2009, Weston Ruter, Shepherd Interactive <http://shepherd-interactive.com/>. GPL license.

GNU General Public License, Free Software Foundation <http://creativecommons.org/licenses/GPL/2.0/>
This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
*/

/*
@todo: Handle inline scripts?
@todo: Keep track of the page that the script as built on
*/

 //* define('SCRIPT_DEBUG', true); loads the development (non-minified) versions of all scripts and disables compression and concatenation
 //* define('CONCATENATE_SCRIPTS', false); disables compression and concatenation,

//delete_option('optimizescripts_settings');
//$settings = get_option('optimizescripts_settings');
//if($settings){
//	foreach($settings['concatenated'] as &$i){
//		$i['disabled'] = false;
//		$i['disabled_reason'] = '';
//	}
//	update_option('optimizescripts_settings', $settings);
//}

// Load up the localization file if we're using WordPress in a different language
// Place it in the "localization" folder and name it "optimize-scripts-[value in wp-config].mo"
define('OPTIMIZESCRIPTS_TEXT_DOMAIN', 'optimze-scripts');
load_plugin_textdomain(OPTIMIZESCRIPTS_TEXT_DOMAIN, plugin_dir_path(__FILE__) . '/i18n');


/**
 * Do the initial setup of the plugin, including the creation of the directory
 * where the concatenated JS files will be stored
 */
function optimizescripts_activate(){
	if(version_compare(PHP_VERSION, '5.0.0', '<'))
		throw new Exception(sprintf(__("This plugin requires PHP5, but you have %s", OPTIMIZESCRIPTS_TEXT_DOMAIN), PHP_VERSION));
	
	/**
	 * Settings for Plugin
	 */
	add_option('optimizescripts_settings', array(
		'version' => '0.5',
		
		//This option is appended to WP_CONTENT_DIR and WP_CONTENT_URL
		'dirname' => 'js',
		
		//In the case that something goes wrong:
		'disabled' => false,
		'disabled_until' => 0,
		'disabled_reason' => '',
		'minimum_expires_time' => 3600*24*4, //6 days
		
		//When an error happens during a rebuild, a concatenated script is disabled
		//  until time + this value.
		'rebuild_wait_period' => 3600, //temp_disabled_duration
		
		//Google Closure Compiler option
		'compilation_level' => 'SIMPLE_OPTIMIZATIONS',
		
		//Include a list of the files contained in the the script by means of a header comment
		'include_manifest' => true,
		
		//When not WP_DEBUG, if this is true, then when a script is needing to be
		// re-concatenated, then the first time the page loads it will be served
		// without any script optimizations, and a cron will be scheduled which will
		// then do the necessary optimizations, and once it finishes, then the pages
		// will be served with the optimized scripts. This should be false if you
		// are using WP Super Cache or another caching plugin.
		'use_cron' => true,
		
		//Cached scripts
		'cache' => array(
			//see optimizescripts_rebuild_scripts() for contents
		),
		
		//List of all of the scripts that have been concatenated
		//@todo: use 'optimized'
		'concatenated' => array(
			//see optimizescripts_rebuild_scripts() for contents
		),
	));
	
	$settings = get_option('optimizescripts_settings');
	try {
		$dir = trailingslashit(WP_CONTENT_DIR) . basename(trim($settings['dirname'], '/'));
		if(!file_exists($dir) && !@mkdir($dir, 0777))
			throw new Exception(sprintf(__("Unable to create directory (%s) for optimized scripts. Create it and make it writable.", OPTIMIZESCRIPTS_TEXT_DOMAIN), $dir));
		if(!is_writable($dir))
			throw new Exception(sprintf(__("Directory where scripts will be stored is not writable (%s)", OPTIMIZESCRIPTS_TEXT_DOMAIN), $dir));
		
		$cacheDir = trailingslashit($dir) . 'cache';
		if(!file_exists($cacheDir) && !@mkdir($cacheDir, 0777))
			throw new Exception(sprintf(__("Unable to create script cache directory (%s). Create it and make it writable.", OPTIMIZESCRIPTS_TEXT_DOMAIN), $cacheDir));
		if(!is_writable($cacheDir))
			throw new Exception(sprintf(__("Directory where cached scripts will be stored is not writable (%s).", OPTIMIZESCRIPTS_TEXT_DOMAIN), $cacheDir));
	}
	catch(Exception $e){
		$settings['disabled'] = true;
		$settings['disabled_reason'] = $e->getMessage();
		update_option('optimizescripts_settings', $settings);
		
		die("Optimize Scripts: " . $e->getMessage()); //@todo Will this show an error?
	}
}
register_activation_hook(__FILE__, 'optimizescripts_activate');



/**
 * Determine if script concatenation is desired, and if so, add the hooks to do so
 */
function optimizescripts_init(){
	global $wp_scripts, $wp_query, $concatenate_scripts, $compress_scripts;
	$settings = get_option('optimizescripts_settings');
	
	//Only do this for the site frontend
	if(is_admin() || is_feed()) //implied by template_redirect
		return;
	
	//The plugin has been disabled, perhaps because of an error
	if(@$settings['disabled'])
		return;

	//Abort if WP is explicitly disabling script concatenation
	if((defined('SCRIPT_DEBUG') && SCRIPT_DEBUG) ||
	   (defined('CONCATENATE_SCRIPTS') && !CONCATENATE_SCRIPTS) ||
	   (isset($concatenate_scripts) && !$concatenate_scripts))
	{
		return;
	}
	
	//Now we know that WP really is wanting scripts concatenated, so now disable
	// any other plugins and WordPress itself from doing any concatenation
	$concatenate_scripts = false;
	$compress_scripts = false; //this should be done by web server when serving static .js
	
	if(!is_admin() && !is_feed()){
		add_filter('script_loader_src', 'optimizescripts_set_src_query_params', 10, 2); 
		add_filter('print_scripts_array', 'optimizescripts_compile');
	}
}
add_action('template_redirect', 'optimizescripts_init'); //@todo: template_redirect ?




/**
 * Make sure that only .js (static) files are concatenated, since dynamic
 * scripts may be generated from .php files.
 * @todo Should we allow any query parameters?
 */
//function optimizescripts_is_static_file($ok, $src, $parsedSrc = null){
//	if(!$parsedSrc)
//		$parsedSrc = optimizescripts_parse_url($src);
//	return preg_match('/\.js$/', $parsedSrc['path']);
//}
//add_filter('optimizescripts_concatenable', 'optimizescripts_is_static_file', 10, 3);



/**
 * Improve cacheability of scripts: Removes 'ver' query parameter if it is empty
 * (false or null). This is important for scripts that are loaded from Google's
 * CDN, for example, because with the query arg it breaks Web-wide caching.
 * If desiring jQuery, you'll need to replace the script URL, for example:
 *   wp_deregister_script('jquery');
 *   wp_enqueue_script('jquery',
 *       'http://ajax.googleapis.com/ajax/libs/jquery/1.3/jquery.min.js',
 *       array(),
 *       false, //version (set to empty so that ver param not included)
 *       true   //in_footer
 *   );
 * When enqueueing other scripts, be sure to include the filemtime as the
 * version so that when the file is modified, clients will not continue to use
 * the previously cached far-future expires version, for example:
 *   wp_enqueue_script(
 *       'mymain',
 *       get_template_directory_uri().'/main.js'
 *       filemtime(TEMPLATEPATH . '/main.js'), //don't allow stale cache
 *       true //in_footer
 *   );
 * @todo We could iterate over all handles and just supply a filemtime as the ver
 */
function optimizescripts_set_src_query_params($src, $handle = null){
	global $wp_scripts;
	
	//Always remove the WordPress-supplied ver query parameter if version is empty
	if($handle){
		if(empty($wp_scripts->registered[$handle]->ver))
			$src = remove_query_arg('ver', $src);
		else
			$src = add_query_arg(array('ver' => $wp_scripts->registered[$handle]->ver), $src);
	}
	
	return $src;
}


/**
 * Same as parse_url without any component parameter, but if the host or scheme
 * isn't available, supply from environment
 */
function optimizescripts_parse_url($url){
	$parsed = parse_url($url);
	if(!isset($parsed['host']))
		$parsed['host'] = $_SERVER['HTTP_HOST'];
	if(!isset($parsed['scheme']))
		$parsed['scheme'] = ($_SERVER['SERVER_PORT'] == 443 || @$_SERVER['HTTPS'] == "on") ? 'https' : 'http';
	return $parsed;
}


/**
 * This is an array with keys which are the concatenated scripts key (i.e. handlehash).
 * Each value is an array, whose key is a script handle and whose value is a src.
 *   The order of the handles in the keys is the order that the resulting
 *   concatenated script file should be.
 * This array is passed into {@see optimizescripts_rebuild_scripts}
 * @global $optimizescripts_pending_rebuild
 */
$optimizescripts_pending_rebuild = array();


/**
 * Concatenate and compile scripts with Google Closure Compiler
 */
function optimizescripts_compile($oldHandles){
	global $wp_scripts, $optimizescripts_pending_rebuild;
	$settings = get_option('optimizescripts_settings');
	static $count = 0;
	try {
		$dirname = basename(trim($settings['dirname'], '/'));
		$baseDir = trailingslashit(WP_CONTENT_DIR) . $dirname;
		$baseUrl = trailingslashit(WP_CONTENT_URL) . $dirname;
		
		//List of domains whose scripts will be attempted to be concatenated. Passed
		// into 'optimizescripts_concatenable' filter as follows:
		// in_array($scripthost, $concatenable_domains)
		// The default domains are this server to improve performance? @todo
		$concatenable_domains = apply_filters('optimizescripts_concatenable_domains', array(
			$_SERVER['HTTP_HOST'],
			parse_url(get_option('siteurl'), PHP_URL_HOST)
		));
		
		//Iterate over all of the registered handles, collecting the URLs of each,
		// and then invoke Google's Closure Compiler
		//$pendingHandles = array();
		//$pendingSrcs = array();
		$pendingScripts = array(); //key=handle, value=src
		
		$pendingDependencies = array();
		$pendingMaxLastModified = 0;
		$pendingMustRebuild = false;
		
		$oldHandles = array_values($oldHandles);
		$handleCount = count($oldHandles);
		$newHandles = array();
		for($i = 0; $i < $handleCount; $i++){
			$handle = $oldHandles[$i];
			$isFirst = ($i == 0);
			$isLast = ($i == $handleCount-1);
			//@todo $this->registered[$handle]->extra['l10n']
			$isLastOfGroup = ($isLast || $wp_scripts->registered[$handle]->extra != $wp_scripts->registered[$oldHandles[$i+1]]->extra);
			
			$src = apply_filters('script_loader_src', $wp_scripts->registered[$handle]->src, $handle); //@todo Or just optimizescripts_set_src_query_params ?
			$parsedSrc = optimizescripts_parse_url($src);
			
			$isConcatenable = (
				//We must not allow scripts without far-future expires to be concatenated
				// because then they will need to be fetched each time the page is
				// loaded. (An expires of zero means it had no expires provided.)
				(empty($settings['cache'][$handle]) || !empty($settings['cache'][$handle]['expires']))
				&&
				//Furthermore, only allow scripts that are on the approved
				// list of domains to be concatenable, or if a filter explicitly
				// permits it.
				(apply_filters('optimizescripts_concatenable',
					in_array($parsedSrc['host'], $concatenable_domains),
					$src,
					$parsedSrc))
				&&
				//Make sure we don't accidentally do anything recursively here
				(strpos($handle, OPTIMIZESCRIPTS_TEXT_DOMAIN) !== 0)
				&&
				!empty($settings['cache'][$handle]['disabled'])
			);
			
			//Add the script's src to the list of srcs pending for concatenation/compilation
			if($isConcatenable){
				//If the URL associated with this handle has changed, we must do a rebuild
				// if if it hasn't been cached yet
				if(empty($settings['cache'][$handle]) || $settings['cache'][$handle]['src'] != $src){
					optimizescripts_print_debug_log("URL Changed! $src ");
					$pendingMustRebuild = true;
				}
				//Get the Last-Modified time to compare with the mtime of
				//  the already-concatenated script: only do this if cached
				//  script is not stale (expired). Otherwise, rebuild must happen.
				else if($settings['cache'][$handle]['expires'] > time())
				{
					$pendingMaxLastModified = max($pendingMaxLastModified, $settings['cache'][$handle]['mtime']);
				}
				//Since script has expired, we must now rebuild the concatenated
				//  script. We'll fetch the script and store it in the cache. If
				//  a resource doesn't have an expires header set and one is not
				//  provided by a filter, then this parent if($isConcatenable)
				//  conditional is never reached (see above where
				//  $isConcatenable is set). The expires is set when the
				//  resource is requested along with the other pending requests.
				else {
					optimizescripts_print_debug_log("pendingMustRebuild = true");
					$pendingMustRebuild = true;
				}
				
				//Add this script's information to the list of scripts to concatenate
				//$pendingHandles[] = $handle;
				//$pendingSrcs[] = $src;
				$pendingScripts[$handle] = $src;
				foreach($wp_scripts->registered[$handle]->deps as $dep){
					$pendingDependencies[] = $dep;
				}
			}
			
			//If this conditional is true, then we either must register the new
			//  concatenated script. If it doesn't yet exist or if it's stale,
			//  then we must rebuild it as well.
			//  NOTE: It would make sense to do the script rebuilding in a cron
			//        job, but this would break with WP Super Cache for example,
			//        since the original scripts would be output the first time
			//        and then subsequent requests would include the newly
			//        concatenated script.
			if((!$isConcatenable || $isLastOfGroup) && !empty($pendingScripts)){
				
				//Generate filename for script containing concatenated scripts
				//Note: We can't just use this basename straight out since it
				// may be too long. So we need to generate an MD5 hash.
				$pendingHandles = array_keys($pendingScripts);
				$pendingSrcs = array_values($pendingScripts);
				
				$signature = join(
					apply_filters('optimizescripts_handle_separator', ';'),
					$pendingHandles
				);
				$keygen = apply_filters('optimizescripts_hash_function', 'md5', $pendingScripts);
				$handleshash = $keygen ? $keygen($signature) : $signature;
				$filename = $handleshash . '.js';
				//@todo We could use $handleshash as the handle for the script
				
				$isDisabled = (isset($settings['concatenated'][$handleshash]) &&
					(
						!empty($settings['concatenated'][$handleshash]['disabled_until']) &&
						$settings['concatenated'][$handleshash]['disabled_until'] < time()
					) || (
						!empty($settings['concatenated'][$handleshash]['disabled'])
					)
				);
				
				if(!$isDisabled) {
					//We rebuild the concatenated script if the file doesn't
					// currently exist or if one of the $pendingScripts has a mtime
					// greater than filemtime($filename)
					$pendingMustRebuild = $pendingMustRebuild
						|| !file_exists("$baseDir/$filename")
						|| $pendingMaxLastModified > filemtime("$baseDir/$filename");
					
					$isRebuildWithCron = $settings['use_cron'] && !(defined('WP_DEBUG') && WP_DEBUG);
					
					//Invoke Google's Closure Compiler to concatenate the scripts
					// This is done in an immediate cron job. Until it finishes
					// additional cron jobs will be blocked, and once it finishes
					// then subsequent requests will have $pendingMustRebuild == false
					// and will be able to use the newly written script.
					if($pendingMustRebuild){
						//if(isset($settings['concatenated'][$handleshash]))
						//	$settings['concatenated'][$handleshash]['status'] = 'pending';
						
						if($isRebuildWithCron){
							//Add the pending scripts for the shutdown function to
							// pass to a new scheduled cron job
							$optimizescripts_pending_rebuild[$handleshash] = $pendingScripts;
							add_action('wp_footer', 'optimizescripts_schedule_rebuild_cron'); //@todo Is this best action?
							
							//Since we have to rebuild and the cron is doing this, now
							// just push all of the pendingHandles onto newHandles.
							// Otherwise (the following conditional)
							$isDisabled = true;
						}
						else {
							//Rebuild everything immediately
							//Suppress warnings because: plugin.php: if ( is_array($arg) && 1 == count($arg) && is_object($arg[0]) ) 
							@do_action('optimizescripts_rebuild_scripts', array($handleshash => $pendingScripts));
							
							//Check to see if the action failed to rebuild and
							// disabled this concatenated script
							$settings = get_option('optimizescripts_settings');
							$isDisabled = (isset($settings['concatenated'][$handleshash]) &&
								(
									!empty($settings['concatenated'][$handleshash]['disabled_until']) &&
									$settings['concatenated'][$handleshash]['disabled_until'] < time()
								) || (
									!empty($settings['concatenated'][$handleshash]['disabled'])
								)
							);
						}
					}
					
					//Either no rebuild was necessary or they were rebuilt immediately
					// so we can enqueue the new script
					if(!$isDisabled && (!$pendingMustRebuild || !$isRebuildWithCron)){
						$count++;
						$newHandle = "optimizescripts$count";
						$group = isset($wp_scripts->registered[$handle]->extra['group']) ?
							$wp_scripts->registered[$handle]->extra['group'] : false;
						
						if(!$pendingMustRebuild)
							optimizescripts_print_debug_log("No rebuild needed!");
						wp_register_script(
							$newHandle,
							"$baseUrl/$filename",
							$pendingDependencies,
							filemtime("$baseDir/$filename"), //@todo This should simply be the max time we've already determined? 
							$group //aka in_footer
						);
						
						//Set the group (for some reason this isn't being done by the 5th param to wp_register_script())
						$wp_scripts->set_group(
							$newHandle,
							false, //recursive
							isset($wp_scripts->registered[$handle]->extra['group']) ?
								$wp_scripts->registered[$handle]->extra['group'] :
								false
						);
						$newHandles[] = $newHandle; //in Cron, this would be disabled
						
						//The list of scripts concatenated are located in a comment in the concatenated script
						//print "<!--\n$baseUrl/$filename\n = " . join("\n + ", $pendingSrcs) . "\n-->";
					}
				}
				
				//If this concatenated script has been disabled, then don't do
				// any concatenation: push on the pending handles as if nothing
				// has happened.
				if($isDisabled){
					foreach($pendingHandles as $pendingHandle)
						$newHandles[] = $pendingHandle;
				}
				
				//Reset
				//$pendingHandles = array();
				//$pendingSrcs = array();
				$pendingScripts = array();
				$pendingDependencies = array();
				$pendingMaxLastModified = 0;
				$pendingMustRebuild = false;
			}
			
			//As the reverse of if(!$isConcatenable), simply pass through the handle
			// after any pendingSrcs have potentially been concatenated/compiled
			if(!$isConcatenable) //|| $pendingMustRebuild
				$newHandles[] = $handle;
		}
		//file_put_contents(ABSPATH . '/~optimizescripts.txt', print_r($settings, true)); //@todo
		return $newHandles;
	}
	catch(Exception $err){
		//@todo Is it bad to print from this action?
		#print "\n<!--\n";
		#print preg_replace('/--+/', '-', $err->getMessage());
		#print "-->\n";
		$settings['disabled'] = true;
		$settings['disabled_reason'] = $err->getMessage();
	}
	file_put_contents(ABSPATH . '/~optimizescripts.txt', print_r($settings, true)); //@todo
	return $oldHandles;
}


/**
 * If the global $optimizescripts_pending_rebuild is not empty, then here we
 * schedule an immediate cron job which will do the heavy-lifting.
 */
function optimizescripts_schedule_rebuild_cron(){
	global $optimizescripts_pending_rebuild;
	if(!empty($optimizescripts_pending_rebuild)){
		wp_schedule_single_event(time(), 'optimizescripts_rebuild_scripts', array($optimizescripts_pending_rebuild));
		//print "<pre>" . print_r($optimizescripts_pending_rebuild, true) . "</pre>";
		$optimizescripts_pending_rebuild = array(); //reset
	}
}


/**
 * Action which is handled when the cron job happens: heavy-lifting of requesting
 * the scripts, updating the cache, concatenating the scripts, and compiling
 * them with Google Closure Compiler
 */
function optimizescripts_rebuild_scripts($scriptGroups){
	$settings = get_option('optimizescripts_settings');
	
	try {
		$dirname = basename(trim($settings['dirname'], '/'));
		$baseDir = trailingslashit(WP_CONTENT_DIR) . $dirname;
		$cacheDir = "$baseDir/cache";
		$baseUrl = trailingslashit(WP_CONTENT_URL) . $dirname;
		
		$useragent = new WP_Http();
		
		//Iterate over each of the script groups and re-fetch the scripts if
		// required (i.e. not cached or expired), and then rebuild the
		// concatenated script.
		foreach($scriptGroups as $handleshash => $scriptsToConcatenate){
			if(!isset($settings['concatenated'][$handleshash])){
				/**
				 *  - created: When the script was first built.
				 *  - modified: Last time that this script was rebuilt (filemtime)
				 *  - build_count: Number of times that this script has been recompiled
				 *  - manifest_handles: Array of the script handles that this this compiled script contains (c.f. with cache)
				 *  - last_build_reason: Explanation for why the last rebuild happened,
				 *                       for example a script expired
				 *  - status: success|pending|error
				 *  - disabled_until: When an error happens during a rebuild, this will
				 *                    be set to time() + rebuild_wait_period
				 *  - disabled: Force this to be disabled forever.
				 */
				$settings['concatenated'][$handleshash] = array(
					'ctime' => time(),
					'mtime' => 0,
					'build_count' => 0,
					'last_build_reason' => '',
					//'status' => 'pending',
					'disabled_until' => 0,
					'disabled' => false
				);
			}
			else {
				$settings['concatenated'][$handleshash]['mtime'] = time();
			}
			$settings['concatenated'][$handleshash]['build_count']++;
			$settings['concatenated'][$handleshash]['manifest_handles'] = array_keys($scriptsToConcatenate);
			
			try {
				$scriptBuffer = array();
				
				//Iterate over each of the script
				foreach($scriptsToConcatenate as $handle => $srcUrl){
					$srcUrl = apply_filters('script_loader_src', $srcUrl, $handle);
					
					if(!isset($settings['cache'][$handle])){
						$settings['cache'][$handle] = array(
							'ctime' => time(),
							'mtime' => 0,
							'expires' => 0,
							'etag' => null,
							'request_count' => 0,
							'src' => $srcUrl,
							'disabled' => false
							//'last_request_reason' => ''
						);
					}
					
					$downloadSource = '?';
					$cacheScriptFile = "$cacheDir/$handle.js";
					
					//Check expires header and if we have the latest info
					$mustRefetchFile = (
						//true || //@debug
						empty($settings['cache'][$handle]['expires']) ||
						$settings['cache'][$handle]['expires'] < time() ||
						$settings['cache'][$handle]['src'] != $srcUrl ||
						!file_exists($cacheScriptFile)
					);
					
					//Get the file over HTTP
					if($mustRefetchFile){
						$settings['cache'][$handle]['src'] = $srcUrl;
						$settings['cache'][$handle]['request_count']++;
						$requestHeaders = array(
							'Referer' => "http://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]",
							//'Cache-Control' => "max-age=0"
						);
						if(file_exists($cacheScriptFile)){
							if(!empty($settings['cache'][$handle]['etag']))
								$requestHeaders['If-None-Match'] = $settings['cache'][$handle]['etag'];
							if(!empty($settings['cache'][$handle]['mtime']))
								$requestHeaders['If-Modified-Since'] = str_replace('+0000', 'GMT', gmdate('r', $settings['cache'][$handle]['mtime']));
						}
						
						//Make the request
						$result = @$useragent->request($srcUrl, array(
							'headers' => $requestHeaders,
							//'httpversion' => '1.1'
						));
						$result['request_headers'] = $requestHeaders;
						
						$downloadSource = 'HTTP';
						
						//Check to see if the HTTP request failed
						if(is_wp_error($result))
							throw new Exception(join("\n", $result->get_error_messages()));
						
						//Not Modified
						else if($result['response']['code'] == 304){
							$downloadSource = "HTTP+Cache";
							$result['body'] = @file_get_contents($cacheScriptFile);
							if(!$result['body'] && @filesize($cacheScriptFile) > 0){
								$error = error_get_last();
								throw new Exception($error ? $error['message'] : sprintf(__("Unable to read from: %s", OPTIMIZESCRIPTS_TEXT_DOMAIN), $cacheScriptFile));
							}
						}
						//If not successful
						else if($result['response']['code'] != 200) {
							throw new Exception("HTTP " . $result['response']['code']);
						}
						
						//Save the file to the
						$cacheScriptFile = ($cacheScriptFile);
						if(!@file_put_contents($cacheScriptFile, $result['body'])){
							$error = error_get_last();
							throw new Exception($error ? $error['message'] : sprintf(__("Unable to write to cache: %s", OPTIMIZESCRIPTS_TEXT_DOMAIN), $cacheScriptFile));
						}
						
						$scriptBuffer[] = $result['body'];
						
						//Get the last modified time
						$mtime = !empty($result['headers']['last-modified']) ?
							strtotime($result['headers']['last-modified']) :
							time();
						$settings['cache'][$handle]['mtime'] = $mtime;
						
						//Get the etag
						$settings['cache'][$handle]['etag'] = isset($result['headers']['etag']) ? $result['headers']['etag'] : null;
						
						//Get the expires time
						$expires = 0;
						if(!empty($result['headers']['expires'])){
							$expires = strtotime($result['headers']['expires']);
						}
						//else if(!empty($result['headers']['cache-control']) &&
						//		preg_match('/max-age=(\d+)/', $result['headers']['cache-control'], $maxAgeMatch))
						//{}
						$expires = apply_filters('optimizescripts_expires', $expires, $srcUrl, $scriptsToConcatenate);
						//$expires = time() + 3600;
						$settings['cache'][$handle]['expires'] = $expires;
						
						if($expires-time() < $settings['minimum_expires_time']){
							throw new Exception(str_replace(
								array('%url', '%handle', '%minimum_expires_time'),
								array($srcUrl, $handle, $settings['minimum_expires_time']),
								__("The script %handle (%url) does not have a minimum expires time (%minimum_expires_time seconds)", OPTIMIZESCRIPTS_TEXT_DOMAIN)
							));
						}
					}
					//Get the file from the cache
					else {
						$downloadSource = "Cache";
						$contents = @file_get_contents($cacheScriptFile);
						if(!$contents && filesize($cacheScriptFile) > 0){
							$error = error_get_last();
							throw new Exception($error ? $error['message'] : sprintf(__("Unable to read from: %s", OPTIMIZESCRIPTS_TEXT_DOMAIN), $cacheScriptFile));
						}
						$scriptBuffer[] = $contents;
					}
					
					//TEMP @todo
					optimizescripts_print_debug_log(
						$downloadSource,
						$handleshash,
						$handle,
						$srcUrl
					);
				}
				
				//Now write out the concatenated script!
				$output = '';
				
				//Prepend the manufest to the concatenated script
				if(!empty($settings['include_manifest'])){
					$output .= "/*\n";
					$output .= __("This file contains the following URLs concatenated together.", OPTIMIZESCRIPTS_TEXT_DOMAIN) . "\n";
					if(!empty($settings['compilation_level']))
						$output .= sprintf(__("They were also optimized by Google's Closure Compiler with compilation_level %s.", OPTIMIZESCRIPTS_TEXT_DOMAIN), $settings['compilation_level']) . "\n";
					//$concatenated .= join("\n", array_values($scriptsToConcatenate));
					$i = 0;
					foreach(array_values($scriptsToConcatenate) as $url){
						$i++;
						$output .= " $i. $url\n";
					}
					$output .= "*/\n";
				}
				
				//Concatenate all of the scripts together
				$optimized .= join("\r\n", $scriptBuffer);
				
				//Now compile the scripts using Google Closure Compiler
				if(!empty($settings['compilation_level'])){
					try {
						$result = $useragent->post(
							'http://closure-compiler.appspot.com/compile',
							array(
								'headers' => array(
									"Accept" => "text/javascript",
									"Content-Type" => "application/x-www-form-urlencoded",
									"Referer" => "http://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]",
								),
								'body' => join('&', array(
									'js_code=' . urlencode($optimized),
									'compilation_level=' . $settings['compilation_level'],
									'output_format=xml', //json_decode has issues with json here :-(
									'warning_level=VERBOSE',
									'output_info=compiled_code',
									'output_info=warnings',
									'output_info=errors',
									'output_info=statistics',
								))
							)
						);
						
						//Check to see if error happens
						if(is_wp_error($result))
							throw new Exception("$handleshash: " . join("\n", $result->get_error_messages()));
						if($result['response']['code'] != 200)
							throw new Exception("$handleshash: HTTP " . $result['response']['code']);
						
						//Save the raw compilationResult
						@file_put_contents("$baseDir/$handleshash.compilationResult.xml", $result['body']);
						
						$doc = new DOMDocument();
						if(!$doc->loadXML($result['body'])){
							$error = error_get_last();
							throw new Exception($error ? $error['message'] : 'XML parse error from Google Closure Compiler');
						}
						
						//Get the compiled code, otherwise show error
						$compiledCodeEl = $doc->getElementsByTagName('compiledCode')->item(0);
						if(!$compiledCodeEl)
							throw new Exception('XML validation error from Google Closure Compiler');
						
						//Get the minified code
						$compiledCode = $compiledCodeEl->textContent;
						if(!$compiledCode)
							throw new Exception('No script data');
						
						$optimized = $compiledCode;
						
						optimizescripts_print_debug_log(
							'Google Code Compiled',
							join(' - ', array_keys($scriptsToConcatenate))
						);
					}
					catch(Exception $e){
						optimizescripts_print_debug_log(
							'Closure Exception!',
							$e->getMessage(),
							strlen($optimized),
							join('&', array_keys($scriptsToConcatenate))
						);
					}
				}
				
				$output .= $optimized;
				
				//Write out the concatenated+compiled script
				if(!@file_put_contents("$baseDir/$handleshash.js", $output)){
					$error = error_get_last();
					throw new Exception($error ? $error['message'] : sprintf(__("Unable to write to file %s", OPTIMIZESCRIPTS_TEXT_DOMAIN), "$baseDir/$handleshash.js"));
				}
			}
			//If an exception is thrown, then we should update the settings to
			//  wait for this set of scripts to be re-fetched
			catch(Exception $e){
				$settings['concatenated'][$handleshash]['disabled'] = true;
				$settings['concatenated'][$handleshash]['disabled_reason'] = $e->getMessage();
				
				optimizescripts_print_debug_log(
					"Exception!",
					$handleshash,
					$handle,
					//$srcUrl,
					$e->getMessage()
				);
				//throw $e;
			}
		
		}#end foreach($scriptGroups as $handleshash => $scriptsToConcatenate)
		
		
	}
	catch(Exception $e){
		optimizescripts_print_debug_log(
			join('   ', array(
				"Exception!",
				$e->getMessage()
			))
		);
		
		$settings['disabled'] = true;
		$settings['disabled_reason'] = $e->getMessage();
		//update_option('optimizescripts_settings', $settings);
		$temp_my_exception = $e->getMessage(); //temp
	}
	file_put_contents(ABSPATH . '/~optimizescripts.txt', print_r($settings, true)); //@todo
	update_option('optimizescripts_settings', $settings);
	//if(isset($temp_my_exception))
	//	file_put_contents(ABSPATH . '/~optimizescripts.txt', print_r($settings, true), FILE_APPEND);
	
}
add_action('optimizescripts_rebuild_scripts', 'optimizescripts_rebuild_scripts');



/**
 * Print debug log if WP_DEBUG
 */
function optimizescripts_print_debug_log($msg){
	if(!defined('WP_DEBUG') || !WP_DEBUG)
		return;
	$settings = get_option('optimizescripts_settings');
	$dirname = basename(trim($settings['dirname'], '/'));
	$baseDir = trailingslashit(WP_CONTENT_DIR) . $dirname;
	
	$sep = "\t\t";
	$entry = date('c');
	
	if(!is_array($msg))
		$msg = func_get_args();
	foreach($msg as $m)
		$entry .= $sep . $m;
	
	@file_put_contents("$baseDir/debugLog.txt", "$entry\n", FILE_APPEND);
}

include(plugin_dir_path(__FILE__) . 'admin.php');