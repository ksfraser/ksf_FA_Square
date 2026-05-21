<?php
/**********************************************
Author: Braath Waate (Original)
Author: Kevin Fraser
Name: Square POS Connector
Free software under GNU GPL
***********************************************/

/*
include_once $path_to_root . "/includes/ui.inc";
include_once $path_to_root . "/includes/data_checks.inc";
include_once $path_to_root . "/sales/includes/db/branches_db.inc";
include_once $path_to_root . "/sales/includes/db/customers_db.inc";
include_once $path_to_root . "/sales/includes/db/sales_order_db.inc";
include_once $path_to_root . "/sales/includes/cart_class.inc";
include_once $path_to_root . "/sales/includes/ui/sales_order_ui.inc";
include_once $path_to_root . "/inventory/includes/db/items_prices_db.inc";
include_once $path_to_root . "/taxes/db/item_tax_types_db.inc";

//include_once $path_to_root . "/modules/square/connect-php-sdk-master/autoload.php";

include_once __DIR__  . "/vendor/autoload.php";
//include_once $path_to_root . "/modules/square/vendor/autoload.php";

use Square\SquareClient;
use Square\LocationsApi;
use Square\Exceptions\ApiException;
use Square\Http\ApiResponse;
use Square\Models\ListLocationsResponse;
use Square\Environment;

define ("SQUARE_RFC3339", "Y-m-d\TH:i:s.u\Z");
*/

class SquareVariation
{
	protected $stockId;
	protected $squareItem;	//!<array
	protected $this->locationId;

	function construct($stock_id, $this->squareItem, $this->locationId)
	{
		$this->stockId = $stock_id;
		$this->squareItem = $this->squareItem;
		$this->this->locationId = $locaiotnId;
	}


	/**//**
	* Create an array for an item
	*
	* @param string $_POST['currency']
	* @param int $_POST['sales_type']
	* @return array
	*/
	function squareVariation( $currency = 'CAD', $sales_type = 0) 
	{
		//TODO:
		//should be moved to \Ksfraser\Frontaccounting namespace SRP class
		$myprice = get_kit_price($this->stockId, $currency, $sales_type);
	
		// items used for discounts are not supported in square
		if ($myprice < 0)
			$myprice = 0;
	
		//TODO:
		//should be moved to \Ksfraser\Frontaccounting namespace SRP class
	    	// get the bar code, if any
	    	$sku = null;
		$result = get_all_item_codes($this->stockId);
		$row    = db_fetch($result);
	    	if ($row)
	        	$sku = $row['item_code'];
	
		if (isset($this->squareItem))
			$obj = $this->squareItem["item_data"]["variations"][0];
		else {
			$obj = array(
				"type" => "ITEM_VARIATION",
				"id" => "#foovar",
				"version" => null,
				"item_variation_data" => array(
					"name" => $this->stockId,
					"pricing_type" => "FIXED_PRICING"),
			);
		}
		$obj["item_variation_data"] = array_merge($obj["item_variation_data"],
			array(
				// square searches for barcodes using the sku instead of upc
				// which is what I would have thought
				"sku" => $sku,
				"price_money" => array(
					"amount" => round(100 * $myprice),
				//TODO:
				//Is the CAD here the same as the currency passed in?  If so we should replace.
					"currency" => "CAD"
				)
			)
		);
	
		if ($_POST['online'] == 1)
			$obj = array_merge($obj, array("available online" => true));
		if ($this->locationId == '0')
		{
			$obj = array_merge($obj, array( "present_at_all_locations" => (true),) );
		}
		else
		{
			$obj = array_merge($obj, array("present_at_location_ids" => array($this->locationId)));
		}
	
		return $obj;
	}
}
