<?php
/**
 * KSF FrontAccounting Module Hooks
 * 
 * @package ksf_FA_Square
 * @version 2.4.3
 */
define ('SS_ksf_FA_Square', 108<<8);

/**
 * Constants defined by this module for inter-module communication
 * These can be discovered by other modules using hook_invoke
 */
define('KSF_SQUARE_MODULE_NAME', 'ksf_FA_Square');
define('KSF_SQUARE_CAPABILITIES', 'export,import,payments,config');

class hooks_ksf_FA_Square extends hooks {

	function __construct() {
		$this->module_name = 'ksf_FA_Square';
	}

	// =========================================================================
	// INTER-MODULE COMMUNICATION METHODS
	// These methods allow other ksf modules to discover and communicate with
	// this module using FrontAccounting's hook_invoke function.
	// =========================================================================

	/**
	 * Gets all constants defined by this module.
	 * 
	 * This method can be called by other modules using:
	 * hook_invoke('ksf_FA_Square', 'getModuleConstants', $data)
	 * 
	 * @param array &$data Reference to data array (can be modified)
	 * @param array|null $opts Additional options
	 * @return array Array of constants defined by this module
	 */
	public function getModuleConstants(&$data, $opts = null) {
		$constants = [
			'KSF_SQUARE_MODULE_NAME' => KSF_SQUARE_MODULE_NAME,
			'KSF_SQUARE_CAPABILITIES' => KSF_SQUARE_CAPABILITIES,
		];

		// Also return via reference for flexibility
		$data['constants'] = $constants;

		return $constants;
	}

	/**
	 * Gets all capabilities provided by this module.
	 * 
	 * This method can be called by other modules using:
	 * hook_invoke('ksf_FA_Square', 'getModuleCapabilities', $data)
	 * 
	 * @param array &$data Reference to data array (can be modified)
	 * @param array|null $opts Additional options
	 * @return array Array of capabilities with descriptions
	 */
	public function getModuleCapabilities(&$data, $opts = null) {
		$capabilities = [
			'export' => [
				'description' => 'Export products from FrontAccounting to Square',
				'methods' => ['exportProducts', 'syncInventory'],
			],
			'import' => [
				'description' => 'Import orders from Square to FrontAccounting',
				'methods' => ['importOrders'],
			],
			'payments' => [
				'description' => 'Collect payments via Square Terminal',
				'methods' => ['createTerminalCheckout'],
			],
			'config' => [
				'description' => 'Configure Square API settings',
				'methods' => ['getSettings', 'saveSettings'],
			],
		];

		// Also return via reference for flexibility
		$data['capabilities'] = $capabilities;

		return $capabilities;
	}

	/**
	 * Checks if this module provides a specific capability.
	 * 
	 * This method can be called by other modules using:
	 * hook_invoke('ksf_FA_Square', 'hasCapability', $data, ['capability' => 'export'])
	 * 
	 * @param array &$data Reference to data array
	 * @param array|null $opts Options including 'capability' to check
	 * @return bool True if module has the capability, false otherwise
	 */
	public function hasCapability(&$data, $opts = null) {
		$capability = $opts['capability'] ?? $data['capability'] ?? null;

		if ($capability === null) {
			$data['has_capability'] = false;
			$data['error'] = 'No capability specified';
			return false;
		}

		$capabilities = ['export', 'import', 'payments', 'config'];
		$hasCapability = in_array($capability, $capabilities);

		$data['has_capability'] = $hasCapability;
		$data['capability_checked'] = $capability;

		return $hasCapability;
	}

	/**
	 * Responds to a capability request from another module.
	 * 
	 * This is a generic responder method that other modules can call
	 * to discover what this module provides.
	 * 
	 * Pattern: Other modules can call:
	 * hook_invoke('ksf_FA_Square', 'respondToCapabilityRequest', $data, ['request' => 'capabilities|constants|has:export'])
	 * 
	 * @param array &$data Reference to data array
	 * @param array|null $opts Options including 'request' type
	 * @return mixed Response based on request type
	 */
	public function respondToCapabilityRequest(&$data, $opts = null) {
		$request = $opts['request'] ?? $data['request'] ?? 'capabilities';

		$data['request'] = $request;
		$data['module'] = $this->module_name;

		switch ($request) {
			case 'capabilities':
				return $this->getModuleCapabilities($data, $opts);

			case 'constants':
				return $this->getModuleConstants($data, $opts);

			case (strpos($request, 'has:') === 0):
				$capability = substr($request, 4);
				return $this->hasCapability($data, ['capability' => $capability]);

			default:
				$data['error'] = 'Unknown request type: ' . $request;
				return null;
		}
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
        $app->add_rapp_function(2, _('Square Dashboard'),
          $path_to_root.'/modules/'.$this->module_name.'/pages/dashboard.php', 'SA_ksf_FA_SquareVIEW');
        $app->add_rapp_function(2, _('Square Configuration'),
          $path_to_root.'/modules/'.$this->module_name.'/pages/config.php', 'SA_ksf_FA_SquareMANAGE');
        $app->add_rapp_function(2, _('Import Square Orders'),
          $path_to_root.'/modules/'.$this->module_name.'/pages/import.php', 'SA_ksf_FA_SquareMANAGE');
        $app->add_rapp_function(2, _('Export to Square'),
          $path_to_root.'/modules/'.$this->module_name.'/pages/export.php', 'SA_ksf_FA_SquareMANAGE');
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
