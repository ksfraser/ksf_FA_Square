<?php
/**
 * KSF FrontAccounting Module Hooks
 * 
 * @package ksf_FA_Square
 * @version 2.4.3
 */
define ('SS_ksf_FA_Square', 108<<8);

class hooks_ksf_FA_Square extends hooks {

	function __construct() {
		$this->module_name = 'ksf_FA_Square';
	}
/**//**
* Add new tabs if we are creating an APP
*
* Example Calendar app at bottom!
*
* @param unknown
* @returns none
*/
    function install_tabs($app) {
      /*
        set_ext_domain('modules/ksf_FA_Square');
        $app->add_application(new calendar_app());
        set_ext_domain();
        */
    }
/**//**
* Install access controls
*
* Role based
*
* @param none
* @returns array
*/
    function install_access() {
        $security_sections[SS_ksf_FA_Square] = _("SquareUp");
        $security_areas['SA_ksf_FA_Square'] = array(SS_ksf_FA_Square|108, _("Square POS Connector"));
        $security_areas['SA_ksf_FA_SquareVIEW'] = array(
            SS_ksf_FA_Square | 1, 
            _("View Square POS")
        );
        $security_areas['SA_ksf_FA_SquareMANAGE'] = array(
            SS_ksf_FA_Square | 2, 
            _("Manage Square POS")
        );
        return array($security_areas, $security_sections);
    }
/**//**
* Activate the extension
*
* Insert DB tables
*
* @param int company
* @param bool check
* @returns bool
*/
    function activate_extension($company, $check_only=true) {
        if (file_exists(dirname(__FILE__) . '/sql/install.sql')) {
            $updates = array('install.sql' => array($this->module_name));
            return $this->update_databases($company, $updates, $check_only);
        }
      try {
        $this->ensure_composer_dependencies();
      } catch (Exception $e )
      {
      } 
        return true;
    }
	/*
		Install additional menu options provided by module
	*/
	function install_options($app) {
		global $path_to_root;

    switch($app->id) {
			case 'orders':
				$app->add_rapp_function(2, _('Square POS Connector'), 
					$path_to_root.'/modules/'.$this->module_name.'/square.php', 'SA_ksf_FA_Square');
		}
	}
  /**//**
  * Install Composer dependencies
  *
  * @param none
  * @return none
  */
  private function ensure_composer_dependencies() {
        $module_dir = dirname(__FILE__);
        $autoload_path = $module_dir . '/vendor/autoload.php';
        
        if (file_exists($autoload_path)) {
            return;
        }
        
        $composer_path = $module_dir . '/composer.json';
        if (!file_exists($composer_path)) {
            return;
        }
        
        $composer_lock = $module_dir . '/composer.lock';
        if (!file_exists($composer_lock)) {
            return;
        }
        
        chdir($module_dir);
        $output = array();
        $return_code = 0;
        exec('composer install --no-interaction --prefer-dist --ignore-platform-req=php 2>&1', $output, $return_code);
        if ($return_code !== 0) {
            error_log('KSF Module: composer install failed: ' . implode("\n", $output));
        }
    }
}

/**
class calendar_app extends application {
    function __construct() {
        parent::__construct("Calendar", _($this->help_context = "&Calendar"));
        
        $this->add_module(_("Calendar"));
        $this->add_lapp_function(0, _("&Calendar"),
            "modules/ksf_FA_Calendar/cal.php", 'SA_ksf_FA_CalendarVIEW', MENU_MAIN);
        $this->add_lapp_function(0, _("My Events"),
            "modules/ksf_FA_Calendar/cal.php?assigned=1", 'SA_ksf_FA_CalendarVIEW', MENU_ENTRY);
        
        $this->add_extensions();
    }
}
*/
