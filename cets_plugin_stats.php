<?php
/*
Plugin Name: WPMU Plugin Stats
Plugin URI: http://wordpress.org/extend/plugins/wpmu-plugin-stats/
Description: WordPress plugin for letting site admins easily see what plugins are actively used on which sites
Version: 1.7-dev
Author: Kevin Graeme, <a href="http://deannaschneider.wordpress.com/" target="_target">Deanna Schneider</a> & <a href="http://www.jasonlemahieu.com/" target="_target">Jason Lemahieu</a>
Contributor: <a href="http://www.joemotacekj.com" target="_target">Joe Motacek</a>
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
Text Domain: wpmu-plugin-stats
Domain Path: /languages
Network: true
      
	WPMU Plugin Stats

	Copyright (C) 2009 - 2013 Board of Regents of the University of Wisconsin System
	Cooperative Extension Technology Services
	University of Wisconsin-Extension

	This program is free software; you can redistribute it and/or
	modify it under the terms of the GNU General Public License
	as published by the Free Software Foundation; either version 2
	of the License, or (at your option) any later version.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.

	You should have received a copy of the GNU General Public License
	along with this program.  If not, see <http://www.gnu.org/licenses/>.        
*/

//avoid direct calls to this file
if ( ! function_exists( 'add_filter' ) ) {
	header('Status: 403 Forbidden');
	header('HTTP/1.1 403 Forbidden');
	exit();
}

if ( ! class_exists('cets_Plugin_Stats') ) {
	
	add_action(
		'plugins_loaded', 
		array ( 'cets_Plugin_Stats', 'get_instance' )
	);

	class cets_Plugin_Stats {

		const VERSION	= '1.6-dev';
		
		// Plugin instance
		protected static $instance = NULL;

		function __construct() {
			load_plugin_textdomain( 'wpmu-plugin-stats', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/');
			add_action( 'network_admin_menu', array( &$this, 'add_page'));

			if ( is_network_admin() ) {
				add_action( 'current_screen', array( &$this, 'load_scripts'));
				add_filter( 'plugin_row_meta', array( $this, 'set_plugin_meta' ), 10, 2);
			}
			
			register_deactivation_hook( __FILE__, array( 'cets_Plugin_Stats', 'deactivate_plugin') );
		}	
		
		// Access this plugin’s working instance
		public static function get_instance() {	
			if ( NULL === self::$instance )
				self::$instance = new self;

			return self::$instance;
		}
		
		function generate_plugin_blog_list() {
			global $wpdb, $current_site;

			$blogs  = $wpdb->get_results("SELECT blog_id, domain, path FROM " . $wpdb->blogs . " WHERE site_id = {$current_site->id} ORDER BY domain ASC");
			$blogplugins = array();
			$processedplugins = array();
			$plugins = get_plugins();

			if ($blogs) {
				foreach ($blogs as $blog) {
					switch_to_blog($blog->blog_id);

					if( constant( 'VHOST' ) == 'yes' ) {
						$blogurl = $blog->domain;			
					} else {
						$blogurl =  trailingslashit( $blog->domain . $blog->path );
					}

					$blog_info = array('name' => get_bloginfo('name'), 'url' => $blogurl);
					$active_plugins = get_option('active_plugins');

					if (sizeOf($active_plugins) > 0) {
						foreach ($active_plugins as $plugin) {

							//jason adding check for plugin existing on system
							if (isset($plugins[$plugin])) {
								$this_plugin = $plugins[$plugin];
								if (isset($this_plugin['blogs']) && is_array($this_plugin['blogs'])) {
									array_push($this_plugin['blogs'], $blog_info);
								} else {
									$this_plugin['blogs'] = array();
									array_push($this_plugin['blogs'], $blog_info);
								}
								unset($plugins[$plugin]);
								$plugins[$plugin] = $this_plugin;
							} else {
								//this 'active' plugin is no longer on the system, so do nothing here?  (or could theoretically deactivate across all sites)
								//unset($plugins[$plugin]);
							//
							}
						}
					}		//foreach ($active_plugin as $plugin)

					restore_current_blog();
				}
			}

			// Set the site option to hold all this
			/*
			$old_stats = get_site_option('cets_plugin_stats_data', 'not-yet-set');
			if ($old_stats == 'not-yet-set') {
					add_site_option('cets_plugin_stats_data', $plugins);
			} else {  */
			update_site_option('cets_plugin_stats_data', $plugins);
			//} 

			$this->derelict_check();

			update_site_option('cets_plugin_stats_data_freshness', time());

		}
		
		/**
		* Author: Joe Motacek
		* Since: 1.6
		* This function checks all the plugins to see if they are maintained
		* Current Issues:  With a large amount of plugins to check it will easily hit the php max excution time.
		* 					I would like to write some kind of ajax based status bar addition to this but
		* 					I'm not sure where to start at the moment...
		*/
		function derelict_check() {
			ini_set('default_socket_timeout', 3); //helps if you're hitting max-execution... @todo find better solution
			$derelict_data = array();
			$plugins = get_plugins();
						
			$slugs = array();
			foreach ($plugins as $name => $data){
				preg_match("/(?<=\/).*[a-zA-Z0-9-](?=.php)/", $name, $slug);
				$plugin_wp_page = @file_get_contents('http://www.wordpress.org/plugins/' . $slug[0] . "/");
							
				if($plugin_wp_page !== false){
					preg_match('/(?<=<meta itemprop="dateModified" content=").*[0-9-](?=")/', $plugin_wp_page ,$date_string);

					$plugin_date = strtotime($date_string[0]);
					$two_years = strtotime ( '-2 year' , strtotime( date("Y-m-d") ) ) ;
						
					$status = ($plugin_date < $two_years ?  "0":  "3");
				}

				else{
					$plugin_author_page = @get_headers($data['PluginURI']);
					if($plugin_author_page !== false){
						if($plugin_author_page[0] == 'HTTP/1.1 404 Not Found') {
							$status = "0";
						}
						elseif($plugin_author_page[0] == 'HTTP/1.1 301 Moved Permanently'){
							$status = "1";
						}
						else{
							$status = "2";
						}
					}else{
						$status = "0";
					}
				}					
			
				$derelict_data[$name] = $status;
			}
				
			update_site_option('cets_plugin_derelict_data', $derelict_data);
		}

		// Create a function to add a menu item for site admins
		function add_page() {
			$this->page = add_submenu_page(
				'plugins.php',
				__( 'Plugin Stats', 'wpmu-plugin-stats'),
				__( 'Plugin Stats', 'wpmu-plugin-stats'),
				'manage_network',
				'wpmu-plugin-stats',
				array(&$this, 'plugin_stats_page')
			);

			add_action("load-$this->page", array( &$this, 'help_tabs'));
		}

		function help_tabs() {
			$screen = get_current_screen();
			$screen->add_help_tab( array(
				'id'        => 'cets_plugin_stats_tab_about',
				'title'     => __('About', 'wpmu-plugin-stats'),
				'callback'  => array( &$this, 'about_tab')
			));
			$screen->add_help_tab( array(
				'id'        => 'cets_plugin_stats_tab_status',
				'title'     => __('Status Definitions', 'cets-plugin-stats'),
				'callback'  => array( &$this, 'status_tab')
			));  
		}

		function about_tab() { ?>
			<style>.tab-about li { list-style: none; }</style>
			<h1>WPMU Plugin Stats</h1>
			<p>
				<a href="http://wordpress.org/extend/plugins/wpmu-plugin-stats/" target="_blank">WordPress.org</a> | 
				<a href="https://github.com/wp-repository/wpmu-plugin-stats" target="_blank">GitHub Repository</a> | 
				<a href="https://github.com/wp-repository/wpmu-plugin-stats/issues" target="_blank">Issue Tracker</a>
			</p>
			<ul class="tab-about">
				<li><b><?php _e( 'Development', 'wpmu-plugin-stats'); ?>:</b>
					<ul>
						<li>Kevin Graeme | <a href="http://profiles.wordpress.org/kgraeme/" target="_blank">kgraeme@WP.org</a></li>
						<li><a href="http://deannaschneider.wordpress.com/" target="_blank">Deanna Schneider</a> | <a href="http://profiles.wordpress.org/deannas/" target="_blank">deannas@WP.org</a></li>
						<li><a href="http://www.jasonlemahieu.com/" target="_blank">Jason Lemahieu</a> | <a href="http://profiles.wordpress.org/MadtownLems/" target="_blank">MadtownLems@WP.org</a></li>
					</ul>
				</li>
				<li><b><?php _e( 'Languages', 'wpmu-plugin-stats'); ?>:</b> English (development), German, Spanish, <a href="https://translate.foe-services.de/projects/cets-plugin-stats" target="_blank">more...</a></li> 
				<li><b><?php _e( 'License', 'wpmu-plugin-stats'); ?>:</b> <a href="http://www.gnu.org/licenses/gpl-2.0.html" target="_blank">GPLv2 or later</a></li>
			</ul>
		<?php 
		}
		
		function status_tab() { ?>
			<style>.tab-about li { list-style: none; }</style>
			<h1>WPMU Plugin Stats</h1>
			<h2>Status Definitions</h2>
			<ul class="tab-about">
				<li><span style="color:green;font-weight: bold;"><?php _e( 'Diligent', 'cets-plugin-stats'); ?>:</span>
					<ul>
						<li>Indicates that this plugin is diligently updated.</li>
						<li>This plugin is easily identified on the WordPress Plugin Directory (WPPD) by its slug.</li>	
						<li>This plugin has been updated within the last 2 years.</li>						
					</ul>
				</li>
				<li><span style="color:yellow; text-shadow: 0 0 3px #000;font-weight: bold;"><?php _e( 'Questionable', 'cets-plugin-stats'); ?>:</span>
					<ul>
						<li>Indicates that we could not find the plugin on the WPPD by its slug.</li>
						<li>We were able to find the Developers site intact.</li>	
						<li>Good plugin developers know to use the same slug name for their main php file as the one they have in the WWPPD</li>						
					</ul>
				</li>
				<li><span style="color:orange;font-weight: bold;"><?php _e( 'Outdated', 'cets-plugin-stats'); ?>:</span>
					<ul>
						<li>Indicates that could not find the plugin on the WPPD by its slug.</li>
						<li>We were able to find the Developers site however it was recently moved.</li>	
						<li>It might be prudent to update if possible or at least follow up.</li>						
					</ul>
				</li>
				<li><span style="color:red;font-weight: bold;"><?php _e( 'Derelict', 'cets-plugin-stats'); ?>:</span>
					<ul>
						<li>Indicates that could not find the plugin on the WPPD by its slug.</li>
						<li>When we tried to load the developers site it either timed out or returned a 404.</li>	
						<li>It is strongly recommended that your remove or replace this plugin.</li>						
					</ul>
				</li>
			</ul>
		<?php 
		}

		// Create a function to actually display stuff on plugin usage
		function plugin_stats_page() {
			// Get the time when the plugin list was last generated
			$gen_time = get_site_option('cets_plugin_stats_data_freshness');

			if ((time() - $gen_time) > 86400 || (isset($_POST['action']) && $_POST['action'] == 'update'))  {
				// an hour was too short with my additions :( once a day is a bit more realistic
				$this->generate_plugin_blog_list();	
			}
			$list = get_site_option('cets_plugin_stats_data');
			ksort($list);

			// if you're using plugin commander, these two values will be populated
			$auto_activate = explode(',',get_site_option('pc_auto_activate_list'));
			$user_control = explode(',',get_site_option('pc_user_control_list'));

			// if you're using plugin manager, these values will be populated
			$pm_auto_activate = explode(',',get_site_option('mp_pm_auto_activate_list'));
			$pm_user_control = explode(',',get_site_option('mp_pm_user_control_list'));
			$pm_supporter_control = explode(',',get_site_option('mp_pm_supporter_control_list'));
			$pm_auto_activate_status = ($pm_auto_activate[0] == '' || $pm_auto_active[0] == 'EMPTY' ? 0 : 1);
			$pm_user_control_status = ($pm_user_control[0] == '' || $pm_user_control == 'EMPTY' ? 0 : 1);
			$pm_supporter_control_status = ($pm_supporter_control[0] == '' || $pm_supporter_control == 'EMPTY' ? 0 : 1);
			
			// add supprt for displaying upgradability on same screen
			$site_transient_update_plugins = get_site_option('_site_transient_update_plugins');
			$upgradeable = maybe_unserialize($site_transient_update_plugins->response);

			// this is the built-in sitewide activation
			$active_sitewide_plugins = maybe_unserialize( get_site_option( 'active_sitewide_plugins') );
			?>
			<style type="text/css">
				table#cets_active_plugins {
					margin-top: 6px;
				}
				.bloglist {
					display:none;
				}
				.pc_settings_heading {
					text-align: center; 
					border-right: 3px solid black;
					border-left: 3px solid black;

				}
				.pc_settings_left {
					border-left: 3px solid black;
				}
				.pc_settings_right {
					border-right: 3px solid black;
				}
				span.plugin-not-found {
					color: red;
				}
				.widefat tr:hover td {
					background-color: #DDD;
				}
			</style>

			<div class="wrap">
				<?php screen_icon( 'plugins' ); ?>
				<h2><?php _e( 'Plugin Stats', 'wpmu-plugin-stats'); ?></h2>
				<table class="widefat" id="cets_active_plugins">
					<thead>
						<?php if ( sizeOf($auto_activate) > 1 || sizeOf($user_control) > 1 || $pm_auto_activate_status == 1 || $pm_user_control_status == 1 || $pm_supporter_control_status == 1 ) { ?>
						<tr>
							<th style="width: 25%;" >&nbsp;</th>
							<?php if ( sizeOf($auto_activate) > 1 || sizeOf($user_control) > 1 ) { ?>
								<th colspan="2" class="pc_settings_heading"><?php printf( '%s %s', __( 'Plugin Commander', 'wpmu-plugin-stats'), __( 'Settings') ); ?></th>
							<?php }
							if ( $pm_auto_activate_status == 1 || $pm_user_control_status == 1|| $pm_supporter_control_status == 1 ) { ?>
								<th colspan="3" align="center" class="pc_settings_heading"><?php printf( '%s %s', __( 'Plugin Manager', 'wpmu-plugin-stats'), __( 'Settings')); ?></th>
							<?php } ?>
							<th>&nbsp;</th>
							<th>&nbsp;</th>
							<th style="width: 20%;">&nbsp;</th>
						</tr>
						<?php } ?>
						<tr>
							<th class="nocase"><?php _e( 'Plugin', 'wpmu-plugin-stats'); ?></th>
							<?php 
							if ( sizeOf($auto_activate) > 1 || sizeOf($user_control) > 1 ) { ?>
								<th class="nocase pc_settings_left">
									<?php _e( 'Auto Activate', 'wpmu-plugin-stats'); ?>
								</th>
								<th class="nocase pc_settings_right">
									<?php _e( 'User Controlled', 'wpmu-plugin-stats'); ?>
								</th>
								<?php	
							}
							if ( $pm_auto_activate_status == 1 || $pm_user_control_status == 1 || $pm_supporter_control_status == 1 ) {
								?>
								<th class="nocase pc_settings_left"><?php _e( 'Auto Activate', 'wpmu-plugin-stats'); ?></th>
								<th class="nocase"><?php _e( 'User Controlled', 'wpmu-plugin-stats'); ?></th>
								<th class="nocase pc_settings_right"><?php _e( 'Supporter Controlled', 'wpmu-plugin-stats'); ?></th>
								<?php		
								}?>
							<th class="case" style="text-align: center !important">
								<?php _e( 'Ugradeable', 'wpmu-plugin-stats'); ?>
							</th>
							<th class="case" style="text-align: center !important">
								<?php _e( 'Status', 'wpmu-plugin-stats'); ?>
							</th>
							<th class="case" style="text-align: center !important">
								<?php _e( 'Activated Sitewide', 'wpmu-plugin-stats'); ?>
							</th>
							<th class="num">
								<?php _e( 'Total Blogs', 'wpmu-plugin-stats'); ?>
							</th>
							<th width="200px">
								<?php _e( 'Blog Titles', 'wpmu-plugin-stats'); ?>
							</th>
						</tr>
					</thead>
					<tbody id="plugins">
						<?php
						$counter = 0;
						foreach ($list as $file => $info) {
							$counter = $counter + 1;

							echo('<tr valign="top"><td>');

							//jason checking for non-existant plugins
							if (isset($info['Name'])) {
								if (strlen($info['Name'])) {
									$thisName = $info['Name'];
								} else {
									$thisName = $file;
								}
							} else {
								$thisName = $file . " <span class='plugin-not-found'>(" . __( 'Plugin File Not Found!', 'wpmu-plugin-stats') . ")</span>";
							}

							echo ($thisName . '</td>');
							// plugin commander columns	
							if ( sizeOf($auto_activate) > 1 || sizeOf($user_control) > 1 ) {
								echo ('<td align="center" class="pc_settings_left">');
								if (in_array($file, $auto_activate)) { 
									_e( 'Yes');
								}
								else {
									_e( 'No');
								}
								echo( '</td><td align="center" class="pc_settings_right">');
								if (in_array($file, $user_control)) {
									_e( 'Yes');
								}
								else {
									_e( 'No');
								}
								echo("</td>");
							}
							// plugin manager columns
							if ( $pm_auto_activate_status == 1 || $pm_user_control_status == 1 || $pm_supporter_control_status == 1 ) {
								echo ( '<td align="center" class="pc_settings_left">');
								if (in_array($file, $pm_auto_activate)) {
									_e( 'Yes');
								}
								else {
									_e( 'No');
								}
								echo('</td><td align="center">');
								if (in_array($file, $pm_user_control)) {
									_e( 'Yes');
								}
								else {
									_e( 'No');
								}
								echo( '</td><td align="center" class="pc_settings_right">');
								if (in_array($file, $pm_supporter_control)) {
									_e( 'Yes');
								}
								else {
									_e( 'No');
								}
								echo("</td>");
							}
							
							// Upgradeable
							echo ('<td align="center">');
							if (is_array($upgradeable) && array_key_exists($file, $upgradeable)) {
								?>
								<span style="color:green; font-weight:bold;">
								<?php _e( 'Yes');?>
								</span>
								<?php
							}
							else {
								_e( 'No');
							}
							
							//Status
							echo ('<td align="center">');
							if (is_array($derelict_data) && array_key_exists($file, $derelict_data)) {
								$status = $derelict_data[$file];
								switch ($status){
									case "0":?>
										<span style="color:red; font-weight:blod;">Derelict</span>
									<?php break;
									case "1":?>
										<span style="color:orange;">Outdated</span>
									<?php break;
									case "2":?>
										<span style="color:yellow; text-shadow: 0 0 2px #000;">Questionable</span>
									<?php break;
									case "3":?>
										<span style="color:green;">Diligent</span>
									<?php break;
								}
									
							}
							else {
								_e( 'Not Found');
							}

							echo ('<td align="center">');
							if (is_array($active_sitewide_plugins) && array_key_exists($file, $active_sitewide_plugins)) {
								_e( 'Yes');
							}
							else {
								_e( 'No');
							}

							if (isset($info['blogs'])) {
								$numBlogs = sizeOf($info['blogs']);
							} else {
								$numBlogs = 0;
							}

							echo ('</td><td align="center">' . $numBlogs . '</td><td>');
							?>
							<a href="javascript:void(0)" onClick="jQuery('#bloglist_<?php echo $counter; ?>').toggle(400);"><?php _e( 'Show/Hide Blogs', 'wpmu-plugin-stats'); ?></a>

							<?php
							echo ('<ul class="bloglist" id="bloglist_' . $counter  . '">');
							if ( isset($info['blogs']) && is_array($info['blogs']) ) {
								foreach($info['blogs'] as $blog) {
									echo ('<li><a href="http://' . $blog['url'] . '" target="new">' . $blog['name'] . '</a></li>');
								}
							}
							else
								echo ("<li>" . __( 'N/A', 'wpmu-plugin-stats') . "</li>");	
							echo ('</ul></td>');
						} ?>
					</tbody>
					<tfoot>
						<?php if ( sizeOf($auto_activate) > 1 || sizeOf($user_control) > 1 || $pm_auto_activate_status == 1 || $pm_user_control_status == 1 || $pm_supporter_control_status == 1 ) { ?>
						<tr>
							<th style="width: 25%;" >&nbsp;</th>
							<?php if (sizeOf($auto_activate) > 1 || sizeOf($user_control) > 1) { ?>
								<th colspan="2" class="pc_settings_heading"><?php printf( '%s %s', __( 'Plugin Commander', 'wpmu-plugin-stats'), __( 'Settings')); ?></th>
							<?php	
							}
							if ($pm_auto_activate_status == 1 || $pm_user_control_status == 1|| $pm_supporter_control_status == 1) { ?>
								<th colspan="3" align="center" class="pc_settings_heading"><?php printf( '%s %s', __( 'Plugin Manager', 'wpmu-plugin-stats'), __( 'Settings')); ?></th>
							<?php	
							}
							?>
							<th>&nbsp;</th>
							<th>&nbsp;</th>
							<th style="width: 20%;">&nbsp;</th>
						</tr>
						<?php }	?>
						<tr>
							<th class="nocase"><?php _e( 'Plugin', 'wpmu-plugin-stats'); ?></th>
							<?php if (sizeOf($auto_activate) > 1 || sizeOf($user_control) > 1) { ?>
								<th class="nocase pc_settings_left"><?php _e( 'Auto Activate', 'wpmu-plugin-stats'); ?></th>
								<th class="nocase pc_settings_right"><?php _e( 'User Controlled', 'wpmu-plugin-stats'); ?></th>
							<?php	
							}
							if ($pm_auto_activate_status == 1 || $pm_user_control_status == 1|| $pm_supporter_control_status == 1) { ?>
								<th class="nocase pc_settings_left"><?php _e( 'Auto Activate', 'wpmu-plugin-stats'); ?></th>
								<th class="nocase"><?php _e( 'User Controlled', 'wpmu-plugin-stats'); ?></th>
								<th class="nocase pc_settings_right"><?php _e( 'Supporter Controlled', 'wpmu-plugin-stats'); ?></th>
							<?php		
							}?>
							<th class="case" style="text-align: center !important">
								<?php _e( 'Ugradeable', 'wpmu-plugin-stats'); ?>
							</th>
							<th class="case" style="text-align: center !important">
								<?php _e( 'Status', 'wpmu-plugin-stats'); ?>
							</th>
							<th class="case" style="text-align: center !important">
								<?php _e( 'Activated Sitewide', 'wpmu-plugin-stats'); ?>
							</th>
							<th class="num">
								<?php _e( 'Total Blogs', 'wpmu-plugin-stats'); ?>
							</th>
							<th width="200px">
								<?php _e( 'Blog Titles', 'wpmu-plugin-stats'); ?>
							</th>
						</tr>
					</tfoot>
				</table>
					<p>
						<?php 
							if (time()-$gen_time > 60) { 
								$lastregen = (round((time() - $gen_time)/60, 0)) . " " . __( 'minutes', 'wpmu-plugin-stats');
							} 
							else { 
								$lastregen = __( 'less than 1 minute', 'wpmu-plugin-stats'); 
							}
							printf( __( 'This data is not updated as blog users update their plugins. It was last generated %s ago.', 'wpmu-plugin-stats'), $lastregen ) ; ?> 
							<form name="plugininfoform" action="" method="post">
								<input type="submit" class="button-primary" value="<?php _e( 'Regenerate', 'wpmu-plugin-stats'); ?>"><input type="hidden" name="action" value="update" />
							</form>
					</p>
		<?php
		}

		function load_scripts( $screen ) {
			if ( 'plugins_page_wpmu-plugin-stats-network' == $screen->id ) {
				wp_register_script( 'tablesort', plugins_url('js/tablesort-2.4.min.js', __FILE__), array(), '2.4', true);
				wp_enqueue_script( 'tablesort' );
			}
		}
		
		function set_plugin_meta( $links, $file ) {
			
			if ( $file == plugin_basename( __FILE__ ) ) {
				return array_merge(
					$links,
					array( '<a href="https://github.com/wp-repository/wpmu-plugin-stats" target="_blank">GitHub</a>' )
				);
			}
			
			return $links;
		}
		
		function deactivate_plugin() {
			delete_site_option( 'cets_plugin_stats_data' );
			delete_site_option( 'cets_plugin_stats_data_freshness' );
		}

	} // END class cets_Plugin_Stats

}// END if class_exists
