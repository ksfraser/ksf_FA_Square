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

  /**//**
  * Stock item lifecycle listeners
  *
  * These methods are invoked by ksf_FA_Common's shared ItemEventPublisher
  * via hook_invoke_all('item_created' / 'item_updated', $data). Payload:
  *   ['stock_id' => string, 'event' => 'created'|'updated', 'trigger' => string, ...]
  *
  * @param array &$data Event payload
  * @param array|null $opts Options
  * @return mixed
  */
  public function item_created(&$data, $opts = null) {
      $this->handleItemEvent($data, 'created');
      return null;
  }

  public function item_updated(&$data, $opts = null) {
      $this->handleItemEvent($data, 'updated');
      return null;
  }

  /**//**
  * Handle an item lifecycle event by pushing the item to Square.
  *
  * @param array $data Event payload
  * @param string $event 'created' or 'updated'
  * @return void
  */
  private function handleItemEvent($data, $event) {
      if (!isset($data['stock_id']) || $data['stock_id'] === '') {
          return;
      }
      if (!function_exists('db_query')) {
          return;
      }
      $stockId = (string) $data['stock_id'];
      $service = $this->buildItemEventSyncService();
      if ($service === null) {
          return;
      }
      try {
          $result = $service->sync($stockId, $event);
          if ($result['status'] === 'failed') {
              error_log('ksf_FA_Square: item_' . $event . ' sync failed for ' . $stockId . ': ' . $result['reason']);
          }
      } catch (\Throwable $e) {
          error_log('ksf_FA_Square: item_' . $event . ' sync error for ' . $stockId . ': ' . $e->getMessage());
      }
  }

  /**//**
  * Build the item event sync service bound to the current FA company.
  *
  * @return \ksfraser\FrontAccounting\Square\Push\ItemEventSyncService|null
  */
  private function buildItemEventSyncService() {
      $autoload = dirname(__FILE__) . '/vendor/autoload.php';
      if (file_exists($autoload)) {
          require_once $autoload;
      }
      if (!class_exists('\ksfraser\FrontAccounting\Square\Push\ItemEventSyncService')) {
          return null;
      }
      try {
          $tablePrefix = defined('TB_PREF') ? TB_PREF : '0_';
          $settings = \ksfraser\FrontAccounting\Square\Config\Settings::fromFADatabase($tablePrefix);
          $accessToken = $settings->getAccessToken();
          if ($accessToken === null || $accessToken === '') {
              return null;
          }
          $client = \ksfraser\FrontAccounting\Square\Infrastructure\SquareClientFactory::create($settings);
          $exporter = new \ksfraser\FrontAccounting\Square\Push\CatalogExporter($client, $settings);
          $currency = function_exists('get_company_pref') ? (string) get_company_pref('curr_default') : '';
          return new \ksfraser\FrontAccounting\Square\Push\ItemEventSyncService(
              $settings,
              $exporter,
              new \ksfraser\FrontAccounting\Square\DAO\StockMasterDAO($tablePrefix),
              new \ksfraser\FrontAccounting\Square\DAO\SquareTokenDAO($tablePrefix, $settings->getEnvironment()),
              $currency,
              0,
              null,
              new \ksfraser\FrontAccounting\Square\DAO\ProductAttributesDAO($tablePrefix)
          );
      } catch (\Throwable $e) {
          error_log('ksf_FA_Square: item event sync unavailable: ' . $e->getMessage());
          return null;
      }
  }

  // =========================================================================
  // SQUARE-INVOICE DESTINATION HOOKS
  // Intercept ST_SALESINVOICE for square_invoice* payment destinations.
  // Must fire BEFORE ksf_FA_PaymentDestinations to suppress cash_sale.
  // =========================================================================

  /** @var array Temporary storage for cart data between prewrite and postwrite */
  private static $pendingSquareInvoice = [];

  /**
   * Intercept ST_SALESINVOICE for square_invoice* destinations.
   *
   * When the payment term maps to a square_invoice* destination:
   * - Suppresses the auto-payment (cash_sale=0) so FA doesn't create a payment
   * - Stores cart data for db_postwrite to create the Square Invoice
   *
   * For non-square_invoice destinations, returns null to let other modules handle.
   */
  function db_prewrite(&$cart, $trans_type)
  {
      if ($trans_type !== ST_SALESINVOICE) {
          return null;
      }

      if (!isset($cart->payment_terms['terms_indicator'])) {
          return null;
      }

      // Check if this payment term is a square_invoice* destination
      $destination = $this->resolvePaymentDestination((int)$cart->payment_terms['terms_indicator']);
      if ($destination === null || strpos($destination, 'square_invoice') !== 0) {
          return null; // Not our destination — pass to next handler
      }

      // Suppress auto-payment — Square Invoice is the payment mechanism
      $cart->payment_terms['cash_sale'] = 0;

      // Store cart data for db_postwrite
      self::$pendingSquareInvoice = [
          'customer_id'  => (int)$cart->customer_id,
          'line_items'   => $this->extractLineItems($cart),
          'destination'  => $destination,
          'terms_indicator' => (int)$cart->payment_terms['terms_indicator'],
      ];

      return true; // We handled this — stop other handlers
  }

  /**
   * Post-write hook: create the Square Invoice after the FA invoice is committed.
   */
  function db_postwrite(&$cart, $trans_type)
  {
      if ($trans_type !== ST_SALESINVOICE) {
          return null;
      }

      if (empty(self::$pendingSquareInvoice)) {
          return null;
      }

      $data = self::$pendingSquareInvoice;
      self::$pendingSquareInvoice = [];

      // Get the invoice number from the cart
      $faInvoiceNo = 0;
      if (isset($cart->trans_no) && $cart->trans_no > 0) {
          $faInvoiceNo = (int)$cart->trans_no;
      } elseif (isset($cart->order_no)) {
          $faInvoiceNo = (int)$cart->order_no;
      }

      if ($faInvoiceNo <= 0) {
          error_log('ksf_FA_Square: db_postwrite could not determine invoice number');
          return null;
      }

      try {
          $service = $this->buildSquareInvoiceService();
          if ($service === null) {
              error_log('ksf_FA_Square: SquareInvoiceService unavailable for invoice #' . $faInvoiceNo);
              return null;
          }

          $dueDate = date('Y-m-d', strtotime('+30 days'));
          $deliveryMethod = $data['destination'] === 'square_invoice_email'
              ? 'EMAIL'
              : 'SHARE_MANUALLY';

          $autoPaymentSource = $data['destination'] === 'square_invoice_card'
              ? 'CARD_ON_FILE'
              : null;

          $result = $service->createInvoiceFromFA(
              $faInvoiceNo,
              $data['customer_id'],
              $data['line_items'],
              $dueDate,
              $deliveryMethod,
              $autoPaymentSource
          );

          // Log the activity
          if (function_exists('display_notification')) {
              $url = $result['public_url'] ?? '';
              $msg = _("Square Invoice created") . ': #' . $faInvoiceNo;
              if ($url) {
                  $msg .= ' — <a href="' . htmlspecialchars($url) . '" target="_blank">' . _("View") . '</a>';
              }
              display_notification($msg);
          }

          return $result;
      } catch (\Throwable $e) {
          error_log('ksf_FA_Square: Square Invoice creation failed for #' . $faInvoiceNo . ': ' . $e->getMessage());
          if (function_exists('display_error')) {
              display_error(_("Square Invoice creation failed") . ': ' . $e->getMessage());
          }
          return null;
      }
  }

  /**
   * Look up the payment destination name for a given payment term ID.
   *
   * Checks the 0_ksf_payment_destinations table for a mapping,
   * then looks up the payment term name to detect square_invoice* destinations.
   */
  private function resolvePaymentDestination(int $termsIndicator): ?string
  {
      if (!function_exists('db_query')) {
          return null;
      }

      $tablePrefix = defined('TB_PREF') ? TB_PREF : '0_';

      // Check our custom destination mapping table
      $sql = "SELECT payment_term_name FROM {$tablePrefix}ksf_payment_destinations
              WHERE payment_term = " . (int)$termsIndicator;
      $result = @db_query($sql);
      if ($result) {
          $row = db_fetch($result);
          if ($row && !empty($row['payment_term_name'])) {
              $name = strtolower(trim($row['payment_term_name']));
              if (strpos($name, 'square_invoice') === 0) {
                  return $name;
              }
          }
      }

      // Fallback: check FA's payment_terms table directly
      $sql = "SELECT terms FROM " . TB_PREF . "payment_terms
              WHERE terms_indicator = " . (int)$termsIndicator;
      $result = @db_query($sql);
      if ($result) {
          $row = db_fetch($result);
          if ($row && !empty($row['terms'])) {
              $name = strtolower(trim($row['terms']));
              if (strpos($name, 'square_invoice') === 0) {
                  return $name;
              }
          }
      }

      return null;
  }

  /**
   * Extract line items from the cart for Square Invoice creation.
   */
  private function extractLineItems($cart): array
  {
      $items = [];
      if (isset($cart->line_items) && is_array($cart->line_items)) {
          foreach ($cart->line_items as $line) {
              $items[] = [
                  'stock_id'         => $line->stock_id ?? '',
                  'item_description' => $line->item_description ?? $line->stock_id ?? '',
                  'quantity'         => $line->quantity ?? 1,
                  'price'            => $line->price ?? 0,
                  'tax_type_id'      => $line->tax_type_id ?? 0,
              ];
          }
      }
      return $items;
  }

  /**
   * Build the SquareInvoiceService bound to the current FA company.
   */
  private function buildSquareInvoiceService()
  {
      $autoload = dirname(__FILE__) . '/vendor/autoload.php';
      if (file_exists($autoload)) {
          require_once $autoload;
      }

      if (!class_exists('\ksfraser\FrontAccounting\Square\Services\SquareInvoiceService')) {
          return null;
      }

      try {
          $tablePrefix = defined('TB_PREF') ? TB_PREF : '0_';
          $settings = \ksfraser\FrontAccounting\Square\Config\Settings::fromFADatabase($tablePrefix);
          $accessToken = $settings->getAccessToken();
          if (empty($accessToken)) {
              return null;
          }

          $client = \ksfraser\FrontAccounting\Square\Infrastructure\SquareClientFactory::create($settings);
          $mapDao = new \ksfraser\FrontAccounting\Square\DAO\SquareInvoiceMapDAO($tablePrefix);
          $locationId = $settings->getDefaultLocation() ?? '';

          return new \ksfraser\FrontAccounting\Square\Services\SquareInvoiceService(
              $client,
              $mapDao,
              $locationId
          );
      } catch (\Throwable $e) {
          error_log('ksf_FA_Square: SquareInvoiceService init failed: ' . $e->getMessage());
          return null;
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
