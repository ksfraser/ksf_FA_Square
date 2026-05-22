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
 * @see CatalogExporter (image upload via Square Catalog API)
 * Replaced by Square SDK v40 CreateCatalogImageRequest.
 * Will be removed in v3.0.0
 */
class SquareUploadItemImage
{
	protected $squareId;
	prtoected $path;

	function construct( $sq_id, $image_path_on_server )
	{
		$this->squareId = $sq_id;
		$this->path = $image_path_on_server;
	}

	function uploadItemImage($access_token) {
		global $accessToken;
		$output = tempnam( sys_get_temp_dir(), "sq") . ".jpeg";
		if (!square_thumbnail_with_proportion($this->path, $output, 600)) {
			display_error("$this->path not a valid image file");
			return;
		}
	
		$idem=uniqid();
		$command=<<<EOT
	#!/bin/bash
	curl -v -X POST \
	-H 'Accept: application/json' \
	-H 'Authorization: Bearer $accessToken' \
	-H 'Cache-Control: no-cache' \
	-H 'Square-Version:  2019-03-27' \
	-F 'file=@$output' \
	-F 'request=
	{
	    "idempotency_key":"$idem",
	    "object_id":"$this->squareId",
	    "image":{
	        "id":"#TEMP_ID",
	        "type":"IMAGE",
	        "image_data":{
	            "caption":"Image"
	        }
	    }
	}' \
	'https://connect.squareup.com/v2/catalog/images'
	
	EOT;
	
		$result = array();
		exec($command, $result);
		// display_notification(print_r($result, true));
	
	/********  SANDBOX *************
	curl https://connect.squareupsandbox.com/v2/locations \
	  -H 'Square-Version: 2024-07-17' \
	  -H 'Authorization: Bearer {SANDBOX_ACCESS_TOKEN}' \
	  -H 'Content-Type: application/json'
	********** PROD ******************
	curl https://connect.squareup.com/v2/locations \
	  -H 'Square-Version: 2024-07-17' \
	  -H 'Authorization: Bearer {PRODUCTION_ACCESS_TOKEN}' \
	  -H 'Content-Type: application/json'
	
	******************************************
	$client = SquareClientBuilder::init()
	  ->bearerAuthCredentials(
	      BearerAuthCredentialsBuilder::init(
	          $_ENV['SANDBOX_ACCESS_TOKEN']
	      )
	  )
	  ->environment(Environment::SANDBOX)
	  ->build();
	
	
	**********************************/
	
		/*
	$cfile = new CURLFile($output, 'image/jpeg', 'image_data');
	$image_data = array('image_data' => $cfile);
	
	$curl = curl_init();
	curl_setopt($curl, CURLOPT_HTTPHEADER, array(
	  'Authorization: Bearer ' . $access_token,
	  'Accept: application/json',
	));
	curl_setopt($curl, CURLOPT_POST, TRUE);
	curl_setopt($curl, CURLOPT_POSTFIELDS, $image_data);
	curl_setopt($curl, CURLOPT_URL, $square_url);
	curl_setopt($curl, CURLOPT_SAFE_UPLOAD, TRUE);
	curl_setopt($curl, CURLOPT_BINARYTRANSFER, TRUE);
	curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);
	curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, FALSE);
	curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, FALSE);
	curl_setopt($curl, CURLOPT_VERBOSE, TRUE);
	$json = curl_exec($curl);
	curl_close($curl);
	*/
	
		unlink($output);
	}
}

