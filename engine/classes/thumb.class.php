<?php
/*
=====================================================
 DataLife Engine - by SoftNews Media Group 
-----------------------------------------------------
 https://dle-news.ru/
-----------------------------------------------------
 Copyright (c) 2004-2026 SoftNews Media Group
=====================================================
 This code is protected by copyright
=====================================================
 File: thumb.class.php
-----------------------------------------------------
 Use: Thumbnail class
=====================================================
*/

use DleImages\ImageManagerStatic as Image;

if( !defined( 'DATALIFEENGINE' ) ) {
	header( "HTTP/1.1 403 Forbidden" );
	header ( 'Location: ../../' );
	die( "Hacking attempt!" );
}

include_once(DLEPlugins::Check(ENGINE_DIR . '/classes/images/images.php'));

class thumbnail {
	
	public $width;
	public $height;
	public $quality = 90;
	public $re_save = false;
	public $format = '';

	private $file = '';	
	private $diver = 'gd';
	private $backup = '';
	private $image;
	private $watermarkimage;
	private $watermark = false;
	private $hidpiwatermarkimage;
	private $hidpiwatermark = false;

	public $tinypng = false;
	public $tinypng_method = false;
	public $tinypng_resize = false;
	public $tinypng_error = false;
	public $tinypng_width = 0;
	public $tinypng_height = 0;
		
	public $error = false;

	function __construct( $file, $backup = false, $min_uploads = false) {
		global $lang, $config;
		
		if( is_array($file) ) {
			
			$this->file = $file['tmp_name'];
			$file_parts = pathinfo($file['name']);
			
		} else {
			
			$this->file = $file;
			$file_parts = pathinfo($file);
		}

		$this->backup  = $backup;
		$this->quality = $config['jpeg_quality'];

		try {

			if($config['image_driver'] != "2") {
				
				if(extension_loaded('imagick') && class_exists('Imagick'))	{
					
					$this->diver  =  'imagick';
					
					if ( ! \Imagick::queryFormats('WEBP') AND function_exists('imagewebp') AND $config['image_driver'] != "1" ) {
						
						$this->diver  =  'gd';
					
					}
		
				}
				
			}
			
			Image::configure(array('driver' => $this->diver));
			$this->image = Image::make($this->file)->orientate();
			
			if( $this->backup ) {
				$this->image->backup();
			}

		
		} catch(\Throwable $e) {
			
			$message = $e->getMessage();

			if( stripos($message, "Unsupported image type" ) !== false OR stripos($message, "Unable to read image" ) !== false ) $message = $lang['file_not_image'];
			
			$this->error( $message );
			return false;

		}

		$this->width = $this->image->width();
		$this->height = $this->image->height();
		$mime = $this->image->mime();

		switch ($mime) {
            case 'image/png':
            case 'image/x-png':
				$this->format = "png";
				break;
			case 'image/gif':
				$this->format = "gif";
				break;
			case 'image/avif':
			case 'image/heif':
				$this->format = "avif";
				break;
            case 'image/webp':
            case 'image/x-webp':
			case 'image/heic':
			case 'image/heic-sequence':
				$this->format = "webp";
				break;
			default:
				$this->format = "jpg";
		}

		$file_parts['extension'] = isset($file_parts['extension']) ? $file_parts['extension'] : '';
		
		if($file_parts['extension'] != $this->format) {
			
			$this->re_save = true;
			
		}
		
		if( $config['force_webp'] AND $this->format != $config['force_webp'] ) {
			$this->re_save = true;
			$this->format = $config['force_webp'];
		}
		
		if( intval( $config['min_up_side'] ) AND $min_uploads) {

			$min_size = explode ("x", $config['min_up_side']);
			
			$allowed = true;
			
			if ( count($min_size) == 2 ) {
				
				$min_size[0] = intval($min_size[0]);
				$min_size[1] = intval($min_size[1]);
	
				if( $this->width < $min_size[0] OR $this->height < $min_size[1] ) {

					$allowed = false;
				
				}
				
			} else {
				
				$min_size[0] = intval($min_size[0]);
				
				if( $this->width < $min_size[0] OR $this->height < $min_size[0] ) {
					
					$allowed = false;
				
				}
				
			}
			
			if( !$allowed ) {
				
				$lang['upload_error_7'] = str_ireplace("{minsize}", $config['min_up_side'], $lang['upload_error_7']);
				
				$this->error( $lang['upload_error_7'] );
				return false;
				
			}
		
		}
		
		if( $config['image_tinypng'] AND $config['tinypng_key'] AND ($this->format == "png" OR $this->format == "jpg" OR $this->format == "webp") ) {
			
			try {
				
				\Tinify\Tinify::setKey( $config['tinypng_key'] );
				
				$this->tinypng = true;
				$this->tinypng_method = false;
				$this->tinypng_resize = $config['tinypng_resize'];
				$this->re_save = true;
				
			} catch(\Throwable $e) {
			
				$this->tinypng = false;
				$this->tinypng_error = $e->getMessage();
			}
			
		}
	
			
	}

	private function reset_image() {

		if( !$this->backup OR !$this->image ) {
			return true;
		}

		try {

			$this->image->reset();
			$this->width = $this->image->width();
			$this->height = $this->image->height();

		} catch(\Throwable $e) {

			$this->error( $e->getMessage() );
			return false;

		}

		return true;

	}
	
	function size_auto($size = 100, $site = 0, $hidpi = false) {
		
		if( $this->error ) return false;

		$size = explode ("x", $size);

		if ( count($size) == 2 ) {
			
			$size[0] = intval($size[0]);
			$size[1] = intval($size[1]);

			if ( $size[0] < 10 ) return false;
			if ( $size[1] < 10 ) return false;

			return $this->crop( $size[0], $size[1], $hidpi );

		} else {
			
			$size[0] = intval($size[0]);

			if ( $size[0] < 10 ) return false;

			return $this->scale( $size[0], $site, $hidpi);

		}

	}

	function crop($nw , $nh, $hidpi = false) {
		
		if( $this->error ) return false;

		if ($hidpi) {
			$nw = $nw * 2;
			$nh = $nh * 2;
		}

		if( $this->width <= $nw AND $this->height <= $nh ) {
			return false;
		}

		if( $this->tinypng AND $this->tinypng_resize ) {
			
			$this->tinypng_method = "cover";
			$this->tinypng_width = $nw;
			$this->tinypng_height = $nh;
			
		}

		try {
			
			$this->image->fit($nw, $nh, true);
			
			$this->re_save = true;
		
		} catch(\Throwable $e) {
			
			$this->error( $e->getMessage() );
			return false;

		}
		
		$this->width = $this->image->width();
		$this->height = $this->image->height();
		
		return true;
	}

	function scale($size = 100, $site = 0, $hidpi = false) {
		
		if( $this->error ) return false;

		$site = intval( $site );
		$size = intval( $size );

		if($hidpi) $size = $size * 2;

		if( $this->width <= $size AND $this->height <= $size ) {
			return false;
		}
		
		switch ($site) {
			
			case "1" :
				
				if( $this->width <= $size ) {
					
					return false;
				
				} else {
					
					try {
						
						$this->image->widen($size, true);
					
					} catch(\Throwable $e) {
						
						$this->error( $e->getMessage() );
						return false;
			
					}
		
				}
				
				break;
			
			case "2" :
				
				if( $this->height <= $size ) {
					
					return false;
				
				} else {
					
					try {
						
						$this->image->heighten($size, true);
					
					} catch(\Throwable $e) {
						
						$this->error( $e->getMessage() );
						return false;
			
					}

					
				}
				
				break;
			
			default :
				
				if( $this->width >= $this->height ) {
					
					try {
						
						$this->image->resize($size, null, true, true);
					
					} catch(\Throwable $e) {
						
						$this->error( $e->getMessage() );
						return false;
			
					}
					
					
				} else {
					
					try {
						
						$this->image->resize(null, $size, true, true);
					
					} catch(\Throwable $e) {
						
						$this->error( $e->getMessage() );
						return false;
			
					}
				
				}
				
				break;
		}
		
		$this->width = $this->image->width();
		$this->height = $this->image->height();
		$this->re_save = true;
		
		return true;

	}

	function get_watermark_area ( $temp_x, $temp_y, $position, $margin = 0 ) {

		$temp_x = min(max(1, intval($temp_x)), $this->width);
		$temp_y = min(max(1, intval($temp_y)), $this->height);
		$margin = max(0, intval($margin));

		list($x, $y) = \DleImages\image_anchor_offset($this->width, $this->height, $temp_x, $temp_y, $position, $margin, $margin);

		return array(
			'x' => max(0, min($this->width - $temp_x, intval($x))),
			'y' => max(0, min($this->height - $temp_y, intval($y))),
			'width' => $temp_x,
			'height' => $temp_y
		);

	}

	function get_rotated_dimensions ( $width, $height, $angle = 0 ) {

		$width = max(1, intval($width));
		$height = max(1, intval($height));
		$angle = abs(fmod(floatval($angle), 360));

		if( !$angle ) {
			return array($width, $height);
		}

		$radians = deg2rad($angle);

		return array(
			max(1, intval(ceil(abs($width * cos($radians)) + abs($height * sin($radians))))),
			max(1, intval(ceil(abs($width * sin($radians)) + abs($height * cos($radians)))))
		);

	}

	function get_text_watermark_sizes ( $text, $watermark_size, $outline_size = 1 ) {
		$pad = 4;
		$text_sizes = Image::measureText($text, array(
			'file' => ROOT_DIR . '/public/fonts/inter.ttf',
			'size' => intval($watermark_size)
		));

		$outline_size = max(0, intval($outline_size));

		return array(
			'text_width' => $text_sizes['width'],
			'text_height' => $text_sizes['height'],
			'width' => $text_sizes['width'] + ($outline_size * 2) + $pad,
			'height' => $text_sizes['height'] + ($outline_size * 2) + $pad,
			'offset' => $outline_size
		);

	}

	function create_text_watermark_image ( $text, $watermark_size, $text_color, $outline_color, $outline_size = 1 ) {

		$scale = 3; 

		$outline_size = max(0, intval($outline_size));
		$w_sizes = $this->get_text_watermark_sizes($text, $watermark_size, $outline_size);
		
		$watermark_image = Image::canvas($w_sizes['width'] * $scale, $w_sizes['height'] * $scale);
		
		$text_options = array(
			'file' => ROOT_DIR . '/public/fonts/inter.ttf',
			'size' => intval($watermark_size) * $scale,
			'valign' => 'top'
		);

		if( $outline_size ) {
			
			$scaled_outline = $outline_size * $scale;

			for( $offset_x = ($scaled_outline * -1); $offset_x <= $scaled_outline; $offset_x++ ) {
				for( $offset_y = ($scaled_outline * -1); $offset_y <= $scaled_outline; $offset_y++ ) {

					if( !$offset_x AND !$offset_y ) {
						continue;
					}

					if( sqrt(($offset_x * $offset_x) + ($offset_y * $offset_y)) > ($scaled_outline + 0.1) ) {
						continue;
					}

					$watermark_image->text($text, ($w_sizes['offset'] * $scale) + $offset_x, ($w_sizes['offset'] * $scale) + $offset_y, $text_options + array(
						'color' => $outline_color
					));
				}
			}

		}

		$watermark_image->text($text, $w_sizes['offset'] * $scale, $w_sizes['offset'] * $scale, $text_options + array(
			'color' => $text_color
		));

		$watermark_image->resize($w_sizes['width'], $w_sizes['height'], true, true);

		return $watermark_image;

	}

	function get_histogram_median ( $histogram, $count ) {

		$count = intval($count);

		if( $count < 1 ) {
			return 127;
		}

		$limit = intdiv($count, 2) + 1;
		$current = 0;

		foreach( $histogram as $value => $amount ) {
			$current += intval($amount);

			if( $current >= $limit ) {
				return intval($value);
			}
		}

		return 127;

	}

	function analyze_color ( $temp_x, $temp_y, $position, $margin = 0 ) {

		$area = $this->get_watermark_area($temp_x, $temp_y, $position, $margin);
		$sample_limit = min(96, max(48, intval(ceil(max($area['width'], $area['height']) / 4))));
		$sample_width = $area['width'];
		$sample_height = $area['height'];

		if( $sample_width > $sample_limit OR $sample_height > $sample_limit ) {
			$ratio = min($sample_limit / $sample_width, $sample_limit / $sample_height);
			$sample_width = max(1, intval(round($sample_width * $ratio)));
			$sample_height = max(1, intval(round($sample_height * $ratio)));
		}

		$luminance_histogram = array_fill(0, 256, 0);
		$luminance_bands = array_fill(0, 16, 0);
		$color_histogram = array_fill(0, 512, 0);
		$sum = 0.0;
		$sum_square = 0.0;
		$weighted_sum = 0.0;
		$weight_sum = 0.0;
		$dark_pixels = 0;
		$light_pixels = 0;
		$neighbor_checks = 0;
		$neighbor_changes = 0;
		$strong_neighbor_changes = 0;
		$max_color_bucket = 0;
		$previous_row = array();

		try {

			$width = $sample_width;
			$height = $sample_height;
			$center_x = ($width - 1) / 2;
			$center_y = ($height - 1) / 2;
			$max_distance = sqrt(($center_x * $center_x) + ($center_y * $center_y)) ?: 1.0;

				for( $y = 0; $y < $height; $y++ ) {
					$left_pixel = null;
					$source_y = $area['y'] + min($area['height'] - 1, intval(floor((($y + 0.5) * $area['height']) / $height)));

					for( $x = 0; $x < $width; $x++ ) {
						$source_x = $area['x'] + min($area['width'] - 1, intval(floor((($x + 0.5) * $area['width']) / $width)));
						$rgb = $this->image->pickColor($source_x, $source_y);
						$red = intval($rgb[0]);
						$green = intval($rgb[1]);
						$blue = intval($rgb[2]);
						$luminance = 0.2126 * $red + 0.7152 * $green + 0.0722 * $blue;
						$luminance_index = max(0, min(255, intval(round($luminance))));
						$distance = sqrt((($x - $center_x) * ($x - $center_x)) + (($y - $center_y) * ($y - $center_y)));
						$weight = 1 + (1 - min(1, $distance / $max_distance));
						$color_index = (($red >> 5) << 6) | (($green >> 5) << 3) | ($blue >> 5);

						$sum += $luminance;
						$sum_square += ($luminance * $luminance);
						$weighted_sum += $luminance * $weight;
						$weight_sum += $weight;
						$luminance_histogram[$luminance_index]++;
						$luminance_bands[$luminance_index >> 4]++;
						$color_histogram[$color_index]++;

						if( $luminance <= 110 ) {
							$dark_pixels++;
						} elseif( $luminance >= 170 ) {
							$light_pixels++;
						}

						if( $color_histogram[$color_index] > $max_color_bucket ) {
							$max_color_bucket = $color_histogram[$color_index];
						}

						if( $left_pixel !== null ) {
							$neighbor_checks++;

							$color_delta = abs($red - $left_pixel[0]) + abs($green - $left_pixel[1]) + abs($blue - $left_pixel[2]);
							$luminance_delta = abs($luminance - $left_pixel[3]);

							if( $color_delta >= 36 OR $luminance_delta >= 18 ) {
								$neighbor_changes++;
							}

							if( $color_delta >= 72 OR $luminance_delta >= 32 ) {
								$strong_neighbor_changes++;
							}
						}

						if( isset($previous_row[$x]) ) {
							$neighbor_checks++;

							$color_delta = abs($red - $previous_row[$x][0]) + abs($green - $previous_row[$x][1]) + abs($blue - $previous_row[$x][2]);
							$luminance_delta = abs($luminance - $previous_row[$x][3]);

							if( $color_delta >= 36 OR $luminance_delta >= 18 ) {
								$neighbor_changes++;
							}

							if( $color_delta >= 72 OR $luminance_delta >= 32 ) {
								$strong_neighbor_changes++;
							}
						}

						$left_pixel = array($red, $green, $blue, $luminance);
						$previous_row[$x] = $left_pixel;
					}
				}

			} catch(\Throwable $e) {

				return array(
					'lightness' => true,
					'needs_outline' => false
				);

			}

		$count = $sample_width * $sample_height;

		if( !$count ) {
			return array(
				'lightness' => true,
				'needs_outline' => false
			);
		}

		$average = $sum / $count;
		$weighted_average = $weight_sum ? ($weighted_sum / $weight_sum) : $average;
		$median = $this->get_histogram_median($luminance_histogram, $count);
		$dominance = ($dark_pixels - $light_pixels) / $count;
		$dark_ratio = $dark_pixels / $count;
		$light_ratio = $light_pixels / $count;
		$contrast_deviation = sqrt(max(0, ($sum_square / $count) - ($average * $average)));
		$texture_ratio = $neighbor_checks ? ($neighbor_changes / $neighbor_checks) : 0.0;
		$strong_texture_ratio = $neighbor_checks ? ($strong_neighbor_changes / $neighbor_checks) : 0.0;
		$color_bucket_threshold = max(2, intdiv($count, 280));
		$luminance_band_threshold = max(1, intdiv($count, 320));
		$active_colors = 0;
		$active_luminance_bands = 0;
		$lightness = ((($median * 0.55) + ($weighted_average * 0.45)) < 128);

		if( $dark_pixels >= ($count * 0.60) ) {
			$lightness = true;
		} elseif( $light_pixels >= ($count * 0.60) ) {
			$lightness = false;
		} elseif( abs($dominance) >= 0.12 ) {
			$lightness = $dominance > 0;
		}

		foreach( $color_histogram as $bucket_count ) {
			if( $bucket_count >= $color_bucket_threshold ) {
				$active_colors++;
			}
		}

		foreach( $luminance_bands as $bucket_count ) {
			if( $bucket_count >= $luminance_band_threshold ) {
				$active_luminance_bands++;
			}
		}

		$dominant_color_ratio = $max_color_bucket / $count;
		$palette_texture_ratio = $active_luminance_bands ? ($active_colors / $active_luminance_bands) : $active_colors;
		$palette_limit = max(10, intval(round(sqrt($count) * 0.75)));
		$strong_palette_limit = max(16, intval(round(sqrt($count) * 1.05)));
		$mixed_lightness = (
			($dark_ratio >= 0.16 AND $light_ratio >= 0.16)
			OR ($contrast_deviation >= 38)
		);
		$textured_photo_area = (
			$active_colors >= 12
			AND $active_luminance_bands >= 8
			AND $dominant_color_ratio <= 0.42
			AND $texture_ratio >= 0.16
			AND $strong_texture_ratio >= 0.06
		);
		$uniform_palette = (
			!$mixed_lightness
			AND $active_colors <= 6
			AND $dominant_color_ratio >= 0.34
			AND $texture_ratio < 0.10
		);
		$photo_like = (
			(
				$active_colors >= $palette_limit
				AND $dominant_color_ratio <= 0.24
				AND $palette_texture_ratio >= 1.35
				AND ($texture_ratio >= 0.10 OR $strong_texture_ratio >= 0.04)
			)
			OR (
				$active_colors >= $strong_palette_limit
				AND $dominant_color_ratio <= 0.14
				AND $palette_texture_ratio >= 1.45
			)
			OR (
				$active_colors >= 8
				AND $palette_texture_ratio >= 1.25
				AND $strong_texture_ratio >= 0.18
			)
			OR (
				$active_colors >= 10
				AND $active_luminance_bands >= 5
				AND $dominant_color_ratio <= 0.32
				AND $palette_texture_ratio >= 1.45
				AND $texture_ratio >= 0.22
				AND $strong_texture_ratio >= 0.08
			)
			OR $textured_photo_area
		);

		return array(
			'lightness' => $lightness,
			'needs_outline' => (!$uniform_palette AND ($mixed_lightness OR $photo_like))
		);

	}

	function detect_color ( $temp_x, $temp_y, $position, $margin = 0 ) {

		$analysis = $this->analyze_color($temp_x, $temp_y, $position, $margin);

		return $analysis['lightness'];

	}

	function insert_watermark($min_image, $hidpi = false) {
		global $config, $lang;
		
		if( $this->error ) return false;
		
		$margin = 10;
		$min_image = intval($min_image);
		$watermark_rotate = intval($config['watermark_rotate']);

		if (!$config['watermark_type']) $hidpi = false;

		if ($hidpi) {
			$margin = $margin * 2;
			$min_image = $min_image * 2;
		}

		if( $this->width < $min_image OR $this->height < $min_image ) {
			return false;
		}

		$watermark_image_light = 'watermark_light.png';
		$watermark_image_dark = 'watermark_dark.png';

		if($config['watermark_seite'] == 1) {
			
			$position = 'top-left';
			
		} elseif($config['watermark_seite'] == 2) {
			
			$position = 'top-right';
			
		} elseif($config['watermark_seite'] == 3) {
			
			$position = 'bottom-left';
			
		} elseif($config['watermark_seite'] == 4) {
			
			$position = 'bottom-right';
			
		} else {
	
			$position = 'center';
			$margin = 0;
			
		}

		$make_watermark = false;

		if(!$hidpi AND !$this->watermark) $make_watermark = true;

		if ($hidpi AND !$this->hidpiwatermark) $make_watermark = true;

		if( ( $make_watermark ) ) {

			if( !$config['watermark_type'] ) {

				try {

					list($temp_x, $temp_y) = getimagesize(ROOT_DIR . '/templates/' . $config['skin'] . '/dleimages/' . $watermark_image_dark);
					list($temp_x, $temp_y) = $this->get_rotated_dimensions($temp_x, $temp_y, $watermark_rotate);
					$lightness = $this->detect_color($temp_x, $temp_y, $position, $margin);
					
					$watermark_image = $lightness ? $watermark_image_light : $watermark_image_dark;

					$this->watermarkimage = Image::make( ROOT_DIR . '/templates/' . $config['skin'] . '/dleimages/'. $watermark_image );
					
				} catch(\Throwable $e) {
					
					$lang['images_uperr_5'] = str_ireplace('{file}', '/templates/' . $config['skin'] . '/dleimages/'. $watermark_image_light, $lang['images_uperr_5']); 
					$this->error( $lang['images_uperr_5'] );
					return false;
		
				}
				
				} else {
					
					try {

						$watermark_size = intval($config['watermark_font']);
						$outline_size = 0;
						$outline_base_size = $hidpi ? 2 : 1;

						if ($hidpi) {
							$watermark_size = $watermark_size * 2;
						}

						$w_sizes = $this->get_text_watermark_sizes($config['watermark_text'], $watermark_size, 0);
						list($temp_x, $temp_y) = $this->get_rotated_dimensions($w_sizes['width'], $w_sizes['height'], $watermark_rotate);
						$analysis = $this->analyze_color($temp_x, $temp_y, $position, $margin);

						if( $analysis['needs_outline'] ) {
							$outline_size = $outline_base_size;
							$w_sizes = $this->get_text_watermark_sizes($config['watermark_text'], $watermark_size, $outline_size);
							list($temp_x, $temp_y) = $this->get_rotated_dimensions($w_sizes['width'], $w_sizes['height'], $watermark_rotate);
							$analysis = $this->analyze_color($temp_x, $temp_y, $position, $margin);
						}
						
						$lightness = $analysis['lightness'];
						
						$watermark_color = $lightness ? $config['watermark_color_light'] : $config['watermark_color_dark'];
						$watermark_outline_color = $lightness ? $config['watermark_color_dark'] : $config['watermark_color_light'];

						if( strtolower(trim((string)$watermark_color)) == strtolower(trim((string)$watermark_outline_color)) ) {
							$watermark_outline_color = $lightness ? '#000000' : '#FFFFFF';
						}

						if ( $hidpi ) {

							$this->hidpiwatermarkimage = $this->create_text_watermark_image($config['watermark_text'], $watermark_size, $watermark_color, $watermark_outline_color, $outline_size);

						} else {

							$this->watermarkimage = $this->create_text_watermark_image($config['watermark_text'], $watermark_size, $watermark_color, $watermark_outline_color, $outline_size);

						}

					
				} catch(\Throwable $e) {
					
					$this->error( $lang['images_uperr_6'] );
					return false;
		
				}
				
			}

			try {
				
				$config['watermark_rotate'] = intval($config['watermark_rotate']);
				$config['watermark_opacity'] = intval($config['watermark_opacity']);
				
				if($config['watermark_opacity'] < 0 OR $config['watermark_opacity'] > 100 ) {
					$config['watermark_opacity'] = 100;
				}

				if( $config['watermark_rotate'] ) {

					if ($hidpi) {
						$this->hidpiwatermarkimage->rotate($config['watermark_rotate']);
					} else {
						$this->watermarkimage->rotate($config['watermark_rotate']);
					}

				}
				
				if( $config['watermark_opacity'] != 100 ) {

					if ($hidpi) {
						$this->hidpiwatermarkimage->opacity($config['watermark_opacity']);
					} else {
						$this->watermarkimage->opacity($config['watermark_opacity']);
					}
				}
				
			} catch(\Throwable $e) {
				
				$this->error( $lang['images_uperr_6'] );
				return false;

			}

			if( $hidpi ) {
				$this->hidpiwatermark = true;
			} else {
				$this->watermark = true;
			}

		}

		if ($hidpi) {
			$watermark_width = $this->hidpiwatermarkimage->width() + $margin;
			$watermark_height = $this->hidpiwatermarkimage->height() + $margin;
		} else {
			$watermark_width = $this->watermarkimage->width() + $margin;
			$watermark_height = $this->watermarkimage->height() + $margin;
		}

		if( $this->width < $min_image OR $this->height < $min_image OR $this->width < $watermark_width OR $this->height < $watermark_height ) {
			
			return false;
		}
		
		try {

			if ($hidpi) {
				$this->image->insert($this->hidpiwatermarkimage, $position, $margin, $margin);
			} else {
				$this->image->insert($this->watermarkimage, $position, $margin, $margin);
			}

			$this->re_save = true;
			
		} catch(\Throwable $e) {
			
			$this->error( $e->getMessage() );
			return false;

		}
		
		return true;
	
	}

	function save($save = "", $autoprefix = false) {
		global $config;

		if( $this->error ) return false;

		$file_parts = pathinfo($save);

		if( isset( $file_parts['dirname'] ) AND $file_parts['dirname'] ) {
			
			$save_path = $file_parts['dirname'].'/';
			
		} else $save_path = '';
		
		if( isset( $file_parts['filename'] ) AND $file_parts['filename'] ) {
			
			$file_name = $file_parts['filename'].'.'.$this->format;
			
		} else $file_name = UniqIDReal().'.'.$this->format;
		
		if( $autoprefix ) {
			
			if( (DLEFiles::FileExists( $save_path.$file_name ) AND !$config['images_uniqid']) OR  $config['images_uniqid'] ) {
				$file_name = UniqIDReal()."_".$file_name;
			}
			
		}

			try {
				
				$imagesource = (string) $this->image->encode($this->format, $this->quality);
				$this->image->encoded = '';
					
			} catch(\Throwable $e) {
				
			$this->error( $e->getMessage() );
			return false;
	
		}
		
			if( $this->tinypng ) {

				$imagesource = $this->tinypng_compress( $imagesource );
			}

			$save_error = '';
			
			if( !DLEFiles::Save( $save_path.$file_name,  $imagesource ) ) {
				$save_error = DLEFiles::$error;
			}

			$imagesource = '';
			unset($imagesource);

			if( !$this->reset_image() AND !$save_error ) {
				return false;
			}

			if( $save_error ) {
				$this->error( $save_error );
				return false;
			}

			return $file_name;

	}
	
	function tinypng_compress( $imagesource ) {
	
		if( $this->error ) return false;
		
		try {
			
			if( $this->tinypng_method ) {
				
				if( stripos($this->file, "https://" ) === 0 OR stripos($this->file, "http://" ) === 0 ) {
						$source = \Tinify\Source::fromUrl( $this->file );
					} else {
						$source = \Tinify\Source::fromFile( $this->file );
					}

				
				$options = array("method" => $this->tinypng_method);
				
				if( $this->tinypng_width ) $options['width'] = $this->tinypng_width;
				if( $this->tinypng_height ) $options['height'] = $this->tinypng_height;
			
				$resized = $source->resize($options);
				$tinypng_buffer = $resized->toBuffer();

			} else {
				
				$source = \Tinify\Source::fromBuffer( $imagesource );
				$tinypng_buffer = $source->toBuffer();
				
			}
			
			return $tinypng_buffer;
			
		} catch(\Throwable $e) {
			
			$this->tinypng = false;
		
			$this->tinypng_error = $e->getMessage();
			
			return $imagesource;
			
		}

	}

	function error( $text ) {
		
		$this->error = (string)$text;
		
	}

	function __destruct() {

		if( $this->image ) {
			$this->image->destroy();
			$this->image = null;
		}

		if( $this->watermarkimage ) {
			$this->watermarkimage->destroy();
			$this->watermarkimage = null;
		}

		if( $this->hidpiwatermarkimage ) {
			$this->hidpiwatermarkimage->destroy();
			$this->hidpiwatermarkimage = null;
		}

	}
	
}
