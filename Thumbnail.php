<?php
/**********************************************
Author: Braath Waate (Original)
Author: Kevin Fraser
Name: Square POS Connector
Free software under GNU GPL
***********************************************/

/**
 * @deprecated since v2.5.0
 * Image resizing logic now inline in pages/export.php.
 * Will be removed in v3.0.0
 */
class Thumbnail
{
	protected $src_file;
	protected $dest_file;
	protected $dest_dim;
	protected $jpeg_quality;
	protected $thumb_w;
	protected $thumb_h;
	protected $resampledImage;
	protected $srcImage;

	function construct($src_file, $destination_file, $square_dimensions, $jpeg_quality=90) 
	{
		$this->src_file = $src_file;
		$this->dest_file = $destination_file;
		$this->dest_dim = $square_dimensions;
		$this->jpeg_quality = $jpeg_quality;
	}
	/**//**
	* Rezise with proportion the src_file
	*
	* @param none
	* @throws Exception	Should make custom exception
	* @return self fluent interface
	*/
	function resample()
	{
		
		$this->srcImage = imagecreatefromjpeg($this->src_file);
		if ($this->srcImage === false)
			throw new Exception( "couldn't create new image" );

		$old_x=imagesx($this->srcImage);
		$old_y=imagesy($this->srcImage);

		$ratio1=$old_x/$this->dest_dim;
		$ratio2=$old_y/$this->dest_dim;

		if ($ratio1>$ratio2) {
			$this->thumb_w=$this->dest_dim;
			$this->thumb_h=$old_y/$ratio1;
		}
		else {
			$this->thumb_h=$this->dest_dim;
			$this->thumb_w=$old_x/$ratio2;
		}

		// we create a new image with the new dimmensions
		$this->resampledImage=imagecreatetruecolor($this->thumb_w, $this->thumb_h);

		// resize the big image to the new created one
		imagecopyresampled($this->resampledImage, $this->srcImage, 0, 0, 0, 0, $this->thumb_w, $this->thumb_h, $old_x, $old_y);

		return $this;
	}
	/**//**
	* Copy and Paste" the $this->resampledImage in the center of a white image of the desired square dimensions
	*
	* @param none
	* @throws Exception	Should make custom exception
	* @return self fluent interface
	*/
	function copyToWhite()
	{
		// Create image of $this->dest_dim x $this->dest_dim in white color (white background)
		$final_image = imagecreatetruecolor($this->dest_dim, $this->dest_dim);
		$bg = imagecolorallocate( $final_image, 255, 255, 255 );
		imagefilledrectangle($final_image, 0, 0, $this->dest_dim, $this->dest_dim, $bg);

		// need to center the small image in the squared new white image
		if ($this->thumb_w > $this->thumb_h) {
			// more width than height we have to center height
			$dst_x=0;
			$dst_y=($this->dest_dim - $this->thumb_h)/2;
		}
		elseif ($this->thumb_h > $this->thumb_w) {
			// more height than width we have to center width
			$dst_x=($this->dest_dim - $this->thumb_w)/2;
			$dst_y=0;
	
		}
		else {
			$dst_x=0;
			$dst_y=0;
		}
	
		$src_x = 0; // we copy the src image complete
		$src_y = 0; // we copy the src image complete
	
		$src_w = $this->thumb_w; // we copy the src image complete
		$src_h = $this->thumb_h; // we copy the src image complete
	
		$pct = 100; // 100% over the white color ... here you can use transparency. 100 is no transparency.
	
		imagecopymerge($final_image, $this->resampledImage, $dst_x, $dst_y, $src_x, $src_y, $src_w, $src_h, $pct);
	
		imagejpeg($final_image, $this->dest_file, $this->jpeg_quality);
	
		// destroy aux images (free memory)
		imagedestroy($this->srcImage);
		imagedestroy($this->resampledImage);
		imagedestroy($final_image);
	
		return $this;
	}
}

