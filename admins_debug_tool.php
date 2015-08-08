<?php
/*
Plugin Name: Admin's Debug Tool
Plugin URI: http://http://measurablewins.blogspot.com/
Description: Admin-only tool for checking execution times and error output of current theme/plugins 
Author: Greg Jackson	
Version: 0.1
*/

AdminDebugTool::instance();

class AdminDebugTool
{
	protected $options;
	protected $data = array();
	protected $hook_log = array();
	protected $errors = array();
	protected $core_hooks;
	protected $starttime;	

	public function instance()
	{
		static $self = false;
		if (!$self) {
			$self = new AdminDebugTool();	
		}
		return $self;
	}


	public function __construct()
	{
		$this->starttime = microtime(true);
		$this->log_internal_ms('__construct');
		add_action('init', array($this, 'init'));
	}


	public function init()
	{
		$this->log_internal_ms('init');
		
		$this->loadOptions();

		$is_administrator = current_user_can('administrator');

		if (is_admin()) {
			add_action('admin_menu', array($this, 'adminMenu'));
		}
		elseif($is_administrator){
		
			// soft-enable WP_DEBUG ??
			if($this->options['WP_DEBUG']) { 
				$this->enable_WP_DEBUG();  
			}
			
			
			$this->data['pid'] = getmypid();
			$this->data['hostname'] = php_uname('n');
			$this->data['sitename'] = get_bloginfo('name');
			$this->data['count'] = array ( 'hook'=> 0, 'query'=> 0, 
				'cache_ask'=> 0, 'cache_get'=> 0, 'cache_set'=> 0,
				 'sidebar'=> 0, 'widget' => 0 );
			
			// set up the hooks	
			if($this->options['catchHooks']) {
				add_filter('all', array($this,'catchHooks'),10,2);
			}
			
			// this is the last or the last...
			add_action('shutdown', array($this,'FooterDump'), 9999 );				
		
		}
				
	}
	
	
	public function loadOptions()
	{
		$this->log_internal_ms('loadOptions');
		
		$this->core_hooks = array('parse_request','wp-head','wp_meta', 'the_content',
			'loop_start','loop_end','get_header','get_sidebar','get_footer','wp_footer',
			'template_include','shutdown','dynamic_sidebar','the_widget','widget_display_callback',
			'print_footer_scripts','wp_before_admin_bar_render');

		
		static $done = false;

		if (!$done) {
			$done     = true;
			$defaults = array(
				'enabled'   => 1,
				'show_summary' => 1,
				'WP_DEBUG'	=> 0,
				'catchHooks' =>0,
				'QUERYLOG' => 0,
				'CACHELOG' => 0,
				'SLOWHOOKS' => 0,
				'echoHooks' => 0,
				'customcorehooks' => '',
				'fullHookLog' => 0,
				'echoAllHooks' => 0,
			);

			$options = get_option('AdminsDebugTool');
			if (!is_array($options)) {
				$options = array();
			}

			$update = false;
			foreach ($defaults as $k => $v) {
				if (!isset($options[$k])) {
					$options[$k] = $v;
					$update = true;
				}
			}

			foreach ($options as $k => $v) {
				if (!isset($defaults[$k])) {
					unset($options[$k]);
					$update = true;
				}
			}

			if ($update) {
				$this->writeOptions($options);
			}

			$this->options = $options;
			
			// add any custom CORE HOOKS here
			if(!empty($this->options['customcorehooks'])){
				$customcorehooks = explode("\n", $this->options['customcorehooks']);
				foreach($customcorehooks as $customhook) {	
					$this->core_hooks[] = trim($customhook); 
				}
			}
						
			$this->options['logHooks'] = ($this->options['fullHookLog'] || $this->options['echoAllHooks'] || $this->options['QUERYLOG'] );

		}
	}

	public function writeOptions($options) 
	{
		update_option('AdminsDebugTool', $options);
		return false;
	}


	public function activate() 
	{
		$this->loadOptions();
	}
	
	
	public function deactivate() 
	{
		// Do Nothing For Now.
	}
	
	private function log_internal_ms($tag,$microtime=FALSE) 
	{
		if(!$microtime) { $microtime = microtime(true); }
		$this->data['ADT'][$tag] = $microtime;
		$this->logHookTimestamp('ADT_'.$tag);
	}
	
	
	public function catchHooks($tag,$value=NULL) {

		global $wp_current_filter;
		$tag = is_array($wp_current_filter) ? array_pop($wp_current_filter) : $wp_current_filter;

		$this->data['count']['hook']++;
		
		$this->logHookTimestamp($tag,$value);
		
		switch($tag) {
			case 'query':
				$this->data['count']['query']++;
				break;
			case 'get_sidebar':
				$this->data['count']['sidebar']++;
				break;
			case 'widget_display_callback':
				$this->data['count']['widget']++;
				break;
			case 'template_include':
				$this->data['template']=basename($value);
				break;
			default:
				if(substr($tag,0,14)=='pre_transient_') {
					$this->data['count']['cache_ask']++;
				}
				elseif(substr($tag,0,10)=='transient_') {
					if($value!==FALSE) {
						$this->data['count']['cache_get']++;
					}
				}
				elseif(substr($tag,0,18)=='pre_set_transient_') {
					$this->data['count']['cache_set']++;
				}
				break;
		}
		
		return $value;
	}
	
	
	public function logHookTimestamp($hookname,$value=FALSE) {
		
		$timestamp = microtime(true);
		$hookdata = FALSE;
		
		if( $this->options['catchHooks'] ) {
		
			if($this->options['echoAllHooks']) {
				$this->echo_hook_in_source($hookname,$value,$timestamp);
			}
			if(in_array($hookname,$this->core_hooks)) {
				$this->data['core_hooks'][] = array('hookname' =>$hookname, 'ts' => $timestamp);
				if($this->options['echoHooks'] && !$this->options['echoAllHooks']) {	
					$this->echo_hook_in_source($hookname,$value,$timestamp);
				}	
			}
			
			if($this->options['logHooks']) {
				$val = !is_array($value) ? $value : 'array()';
				$hookdata = array( 'ts' => $timestamp, 'hookname' =>$hookname, 'value' => $val);
				$this->hook_log[] = $hookdata;
			} 
			
		}
		return $timestamp;
	}
	
	
	private function reprocessLogData(){
		
		$this->log_internal_ms('ADT_processHooks');
		
		// $start_timestamp = $this->data['ADT']['__construct'];
		$this->data['page_ms'] = $this->data['ADT']['total_ms'] = ($this->data['ADT']['footerDump'] - $this->starttime)*1000;
		$this->data['query_log'] = array('total_ms' => 0,'slowest' => array('ms'=> 0));
		
		if(!$this->options['catchHooks']) { return; }
		
		// first calculate the ms as best we can
		$this->data['core_hooks'] = $this->calc_hook_ms($this->data['core_hooks']);

		if(!$this->options['logHooks']) {
			return FALSE;
		}
		
		
		// we only do this if we need to...
		$this->hook_log = $this->calc_hook_ms($this->hook_log);
	
		if($this->options['QUERYLOG']) {
			// catch slowest query
			foreach($this->hook_log as $key => $hookdata) {
				if($hookdata['hookname']=='query') {
					$this->data['query_log']['total_ms'] += $hookdata['ms'];
					if($hookdata['ms']>$this->data['query_log']['slowest']['ms']) {
						$this->data['query_log']['slowest']= $hookdata;
					}
				}			
			}
		}

		
	}
	
	
	private function echo_hook_in_source($hookname,$value,$timestamp) {
		$theHookname = $hookname;		
		if($hookname=='dynamic_sidebar') { $theHookname = $hookname.':'.$value['id']; }
		if($hookname=='the_widget') { $theHookname = $hookname.':'.$value['id']; }
		$ts = number_format(($timestamp - $this->data['ADT']['__construct'])*1000,3,'.','').'ms';
		echo "\n<!-- ADT:$theHookname:$ts -->";
	}
	
	
	private function calc_hook_ms($hook_array) {
		// we calc the hook's execution time from it's timestamp 
		// until the timestamp of the next sequntial hook.
		$prev_hookdata = $prev_hookkey = FALSE;
		foreach($hook_array as $key => $hookdata) {
			$hookdata['page_ms'] = ($hookdata['ts'] - $this->starttime)*1000;
			if($prev_hookkey!==FALSE) {
				$prev_hookdata['ms'] = ($hookdata['ts'] - $prev_hookdata['ts'])*1000;
				$hook_array[$prev_hookkey] = $prev_hookdata;
			}
			$prev_hookkey = $key;
			$prev_hookdata = $hookdata;
		}
		// don't forget to update the final element
		if($prev_hookkey!==FALSE) {
			$prev_hookdata['ms'] = ($hookdata['ts'] - $prev_hookdata['ts'])*1000;
			$hook_array[$prev_hookkey] = $prev_hookdata;
		}
		return $hook_array;
	}
	
	
	public function footerDump() {
		
		$this->log_internal_ms('footerDump');
		
		$this->reprocessLogData();
		
		$output_text  = "\n\nAdmin Debug Tool Output\n=======================";
		$output_text .= "\nSitename: ".$this->data['sitename'];
		$output_text .= "\nHostname: ".$this->data['hostname'];
		$output_text .= "\nProcess ID: ".$this->data['pid'];
		if(isset($_SERVER)) {
			$output_text .= "\nServer Address: ".$_SERVER['SERVER_ADDR'].':'.$_SERVER['SERVER_PORT'];
			$output_text .= "\nRequest Time: ".date("Y/m/d H:i:s e",$_SERVER['REQUEST_TIME']);
			$output_text .= "\nRequest URI: ".$_SERVER['REQUEST_URI'];
		}
		
		if( $this->options['catchHooks']) {
			$output_text .= "\nTemplate: {$this->data['template']}";
			$output_text .= "\nSidebars: ".$this->data['count']['sidebar'];
			$output_text .= "\nWidgets displayed: ".$this->data['count']['widget'];
			$output_text .= "\nHooks observed: ".$this->data['count']['hook'];
			$output_text .= "\nQueries executed: ".$this->data['count']['query'];
			$output_text .= "\nCache requests/reads/writes: ".$this->data['count']['cache_ask'].'/'.$this->data['count']['cache_get'].'/'.$this->data['count']['cache_set'];
		}
		$output_text .= "\nPage ms: ".number_format($this->data['page_ms'],3,'.','');
		$output_text .= "\n";
		
		
		if($this->options['catchHooks']) {
		
			if($this->options['QUERYLOG']) {
			
				$this->data['query_log']['slowest']['rel_ms'] = ($this->data['query_log']['slowest']['ts']- $this->starttime)*1000;			

				$output_text  .= "\n\nQuery Summary\n=============";				
				$output_text .= "\nQueries executed: ".$this->data['count']['query'];
				$output_text .= "\nTotal Query exec time: ".number_format($this->data['query_log']['total_ms'],3).'ms ('.number_format(($this->data['query_log']['total_ms']/$this->data['page_ms'])*100,1).'% of Page ms)';
				$output_text .= "\nAverage Query exec time: ".number_format($this->data['query_log']['total_ms']/$this->data['count']['query'],3).'ms';
				$output_text .= "\nSlowest Query exec time: ".number_format($this->data['query_log']['slowest']['ms'],3).'ms ('.number_format(($this->data['query_log']['slowest']['ms']/$this->data['page_ms'])*100,1).'% of Page ms)';
				$output_text .= "\nSlowest Query SQL: ".$this->data['query_log']['slowest']['value'];
				$output_text .= "\nSlowest Query page timestamp: ".number_format($this->data['query_log']['slowest']['rel_ms'],3).'ms';
				$output_text .= "\n";

			}
		
		
			$hookset = $this->data['core_hooks'];
			if($this->options['fullHookLog'] ) {
				$hookset = $this->hook_log;
			}
		
			if(!empty($hookset)) {
				// core hook relative ms 
				$output_text  .= "\n\nHooks observed, timestamps and times\n====================================";
				$prev_ts = 0;
				$avg_ms = ($this->data['page_ms']/count($hookset));
				$slowquery_done = !($this->options['QUERYLOG']);
				foreach($hookset as $hook) {
					$ts = (($hook['ts'] - $this->starttime)*1000);
					if(!$slowquery_done){
						if($ts>$this->data['query_log']['slowest']['rel_ms']) {
							$output_text .= "\n>>> SLOWEST QUERY executed here <<<";
							$slowquery_done = true;
						}
					}
					
					$ts = number_format($ts,2);
					$ms = number_format($hook['ms'],2);
					$slow = ($hook['ms'] > $avg_ms) ? '***' : '';
					$output_text .= "\n".$hook['hookname'].": $ts (+$ms) $slow";
					$prev_ts = $hook['ts'];
				}
				$output_text .= "\n*** indicates slower than average (".number_format($avg_ms,2)."ms)\n";
			}
		
		}
		

		$hidden = $this->options['show_summary'] ? '' : ' style="display:none"';
		echo "<pre{$hidden}>$output_text</pre>";
	
	}


	private function enable_WP_DEBUG() {
		if ( defined( 'E_DEPRECATED' ) ) {
			error_reporting( E_ALL & ~E_DEPRECATED & ~E_STRICT );	// for PHP 5.3+
		} else {
			error_reporting( E_ALL );	// for pre PHP 5.3
		}
		ini_set( 'display_errors', 1 );
	}


	function adminMenu() {
		add_submenu_page( 'tools.php', 'Admin\'s Debug Tool', 'Admin\'s Debug Tool', 'update_plugins', 'admindebugtool', array($this,'adminForm') );
	}
	
	
	function adminPost() {
		
		if(!current_user_can('administrator')){	
			return false; 
		}

		// load up FALSE checkboxes		
		
		if(!isset($_POST['options']['show_summary'])){ 	$_POST['options']['show_summary'] = 0;  }
		if(!isset($_POST['options']['WP_DEBUG'])){ 	$_POST['options']['WP_DEBUG'] = 0;  }
		if(!isset($_POST['options']['catchHooks'])){  $_POST['options']['catchHooks'] = 0;  }
		if(!isset($_POST['options']['echoHooks'])){  $_POST['options']['echoHooks'] = 0;  }
		if(!isset($_POST['options']['fullHookLog'])){  $_POST['options']['fullHookLog'] = 0;  }
		if(!isset($_POST['options']['echoAllHooks'])){  $_POST['options']['echoAllHooks'] = 0;  }
		if(!isset($_POST['options']['QUERYLOG'])){ 	$_POST['options']['QUERYLOG'] = 0;  }
		
		foreach($_POST['options'] as $key => $value){
			if(isset($this->options[$key])){
				$this->options[$key] = $value;
			}
		}
		
		$this->writeOptions($this->options);
		
	}
	
	
	function adminForm() {

		// process the POST first
		if(isset($_POST['_wpnonce']) && isset($_POST['submit'])) {
			$this->adminPost();	
		}

		echo '<div class="wrap">
		<div id="icon-options-general" class="icon32"><br></div>
		<h2>Admin\'s Debug Tool</h2>
		
		<p class="description">IMPORTANT: 
			These settings will only enable output on pages for a logged in Administrator, however, that output <em>may</em> be cached and displayed for other users depending on your WordPress environment.</p>
		
		<hr/>
		<form method="post">
		
		<h3>Runtime DEBUG mode</h3>
		<p><input type="checkbox" value="1" name="options[WP_DEBUG]" '.checked(1, $this->options['WP_DEBUG'],false).'> Enable DEBUG mode.</p>
		<p class="description">Debug mode is only enabled for logged in Administrators from the point that this plugin is initialized.<br/>
		For a description of WP_DEBUG and how to enable refer to <a href="http://codex.wordpress.org/Debugging_in_WordPress">Debugging in WordPress</a>.</p>

		<hr/>
		<h3>Display Page Summary Data</h3>
		<p>Output the data summary <select name="options[show_summary]">
			<option value="1" '.selected( $this->options['show_summary'], 1, 0).'>visibly at the bottom of the page</option>
			<option value="0" '.selected( $this->options['show_summary'], 0, 0).'>hidden in the page source</option>
			</select> <br/><i>Note: This does NOT control any DEBUG output.</i><p>		
		
		<hr/>
		<h3>HOOK Based Functions</h3>
		<p><input type="checkbox" value="1" name="options[catchHooks]" '.checked(1, $this->options['catchHooks'],false).'> Enable monitoring of Action/Filter hooks.</p>
		<p class="description">Enabling this option is likely to cause a small increase in page execution time as every hook callback is caught and analyzed.<br/>
		Default behavior displays the counts for hooks, sidebars, widgets, queries and cache requests (using <a href="Transients_API">Transients API</a>) and execution times for "core" Hooks in the page summary data.
		</p>
		';
		
		$disabled = ($this->options['catchHooks']) ? '' : ' disabled="disabled" ' ;
		echo '	
		<p><input type="checkbox" value="1" name="options[echoHooks]" '.checked(1, $this->options['echoHooks'],false).$disabled.'> Echo core hooks and timestamps in page source.</p>
		<p class="description">
		This can give you a better idea of what is happening and when compared to the actual page output. 
		These hooks should not corrupt your page html or layout.</p>
		
		<p><input type="checkbox" value="1" name="options[QUERYLOG]" '.checked(1, $this->options['QUERYLOG'],false).$disabled.' > Output QUERY summary.</p>
		<p class="description">This option will display total and average query execution times, and detail the slowest query executed.</p>
		
		<hr/>
		<h3>Advanced Options</h3>
		<p><b>Custom Core Hooks</b><p/>
		<p class="description">You can add other hooks to the core hook set by entering the hook name(s) here, one per line.<br/>
		<textarea name="options[customcorehooks]"  rows="4" cols="50">'.$this->options['customcorehooks'].'</textarea></p>
		
		
		
		<p><input type="checkbox" value="1" name="options[fullHookLog]" '.checked(1, $this->options['fullHookLog'],false).'>
		<b>Output Complete Hook Log</b></p>
		<p class="description">
		Output the full hook log in the page summary and not just core hooks. 
		This will result in a LOT more output, but also a much finer granularity.</p>

		<p><input type="checkbox" value="1" name="options[echoAllHooks]" '.checked(1, $this->options['echoAllHooks'],false).'>
		<b>Echo ALL Hooks In Page Source</b></p>
		<p class="description">
		WARNING: This will corrupt html output and page layout, but it will display every hook in relative position to page output.
		</p>' ;
	
		echo '
		<p class="submit submit-top">
			'.wp_nonce_field('admindebugtool').'
			<input type="submit" name="submit" value="Save Changes" class="button-primary"/>
		</p>
		</form>';
		
	?><hr/>
	<h3>Notes:</h3>
	<ol>
		<li><i>The ms times are milliseconds relative to the init time of this plugin, so they do not include the loading of earlier WP core components.
		These times will give a good indication of where page execution time is being spent, but can vary due to server activity, cache states, and plugin options selected.</i></li>
	<li><i>Hook duration times calculated from the time a hook is fired until the next hook is fired. This can produce false positives if an expensive piece of code is executed before the next hook.</i></li>
	<li><i>Processing of Filter and Action Hook data will slightly impact the page speed due to increased memory usage, processing and output size.</i></li> 
	</ol>
	
	<?php

		echo '</div>';
	}

}