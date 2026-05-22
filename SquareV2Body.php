<?php
/**********************************************
Author: Braath Waate (Original)
Author: Kevin Fraser
Name: Square POS Connector
Free software under GNU GPL
***********************************************/
/*
use Square\SquareClient;
use Square\LocationsApi;
use Square\Exceptions\ApiException;
use Square\Http\ApiResponse;
use Square\Models\ListLocationsResponse;
use Square\Environment;

define ("SQUARE_RFC3339", "Y-m-d\TH:i:s.u\Z");
*/

/**
 * @deprecated since v2.5.0
 * @see CatalogExporter::upsertProduct()
 * Replaced by Square SDK v40 model objects via CatalogExporter.
 * Will be removed in v3.0.0
 */
class SquareV2Body
{
	protected $stockId;
	protected $squareCat;
	protected $sqareItem;
	protected $trans;
	protected $locationId;
	protected $locationName;
	protected $taxName;

	function construct($stock_id, $sq_cat, $sq_item, $trans, $locationId, $locationName, $taxName)
	{
		$this->stockId = $stock_id;
		$this->squareCat = $sq_cat;
		$this->trans = $trans;
		$this->locationId = $locationId;
		$this->locationName = $locationName;
		$this->taxName = $taxName;
	}

	/**//**
	* Build the Square Item including variations
	*
	* @since 20260521
	* @param none
	* @returns array
	*/
	function build() 
	{
		//TODO: Build an OBJ initiator since this is now class 2 or 3 that does this
		if (isset($this->squareItem))
			$obj = $this->squareItem;
		else {
			$obj = array(
				"type" => "ITEM",
				"id" => "#foo",
				"present_at_all_locations" => ($this->locationId == '0' ? true : false),
				"item_data" => array()
			);
		}
	
		$obj["item_data"] = array_merge($obj["item_data"],
			array("name" => str_replace("Whitewater Hill ", "", $this->trans['description']),
				"category_id" => $this->squareCat,
				"variations" => array(square_variation($this->stockId, $this->squareItem, $this->locationId))
			));
	
		//TODO: Make into SRP class since used a few places
		if ($this->locationId != '0')
			$obj = array_merge($obj, array("present_at_location_ids" => array($this->locationId)));
	
	    	$tax_array = array();
		if ( ! $this->trans['exempt']) 
		{
			foreach ($this->locationName as $loc_key => $loc_name)
			{
				if ($this->locationId == '0' || $loc_key == $this->locationId) 
				{
					$tax_name = $loc_name . " " . $this->trans['tax_name'];
	
					// if "location taxrate" is not defined in Square Taxes
					// check for just "location" to apply Square tax rate to all non-exempt items
					if ( ! isset($this->taxName[$tax_name]))
					{
						$tax_name = $loc_name;
					}
					else
					{
						$tax_array = array_merge($tax_array, array($this->taxName[$tax_name]));
					}
				}
			}
			// $obj["item_data"] = array_merge($obj["item_data"], array("tax_ids" => $tax_array));
		}
	    	$obj["item_data"]["tax_ids"] = $tax_array;
		return $obj;
	}
}
