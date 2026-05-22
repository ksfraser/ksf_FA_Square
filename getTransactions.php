<?php
/**********************************************
Author: Kevin Fraser
Name: Square POS Connector getTransactions
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
*/


/**
 * @deprecated since v2.5.0
 * @see pages/export.php
 * Query logic now inline in export.php or via FA stock_master queries.
 * Will be removed in v3.0.0
 */
class getTransactions
{
	protected $category;
	proteted $location;
	protected $item_like;

	//function getTransactions($category, $location, $item_like) {
	function construct($category, $location, $item_like) {
		$this->category = category;
		$this->location = $location;
		$this->item_like = $item_like;
	}
	function do_query()
	{
		global $SysPrefs;
		$sql = "SELECT item.category_id,
	            category.description AS cat_description,
	            item.stock_id, item.units,
	            item.description, item.inactive,
	            IF(move.stock_id IS NULL, '', move.loc_code) AS loc_code,
	            SUM(IF(move.stock_id IS NULL,0,move.qty)) AS QtyOnHand,
	            tt.name as tax_name,
	            tt.exempt
	        FROM ("
			.TB_PREF."stock_master item,"
			.TB_PREF."stock_category category)
	            LEFT JOIN ".TB_PREF."stock_moves move ON item.stock_id=move.stock_id
	            LEFT JOIN ".TB_PREF."item_tax_types tt ON item.tax_type_id=tt.id
	        WHERE item.category_id=category.category_id
	        AND item.inactive = 0";
		if ($category  != -1)
			$sql .= " AND item.category_id = ".db_escape($category);
		if ($location != 'all')
			$sql .= " AND IF(move.stock_id IS NULL, '1=1',move.loc_code = ".db_escape($location).")";
		if ($item_like) {
			$regexp = null;
	
			if (sscanf($item_like, "/%s", $regexp)==1)
				$sql .= " AND item.stock_id RLIKE ".db_escape($regexp);
			else
				$sql .= " AND item.stock_id LIKE ".db_escape($item_like);
		}
		$sql .= " GROUP BY item.category_id,
	        category.description,
	        item.stock_id,
	        item.description
	        ORDER BY item.category_id,";
	
		if (@$SysPrefs->sort_item_list_desc)
			$sql .= "item.description";
		else
			$sql .= "item.stock_id";
	
		return db_query($sql, "No transactions were returned");
	}
}

