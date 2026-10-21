<?php
 /**
  * Generate a colorbar from URL parameters.
  *
  * @file cb.php
  * @date 2013-08-08 09:52 PDT
  * @author Paul Reuter
  * @version 1.0.6
  *
  * @modifications
  * 1.0.0 - 2010-02-04 - Created (from scratch) to read url params.
  * 1.0.1 - 2010-02-04 - BugFix: getFontFile appends .ttf now.
  * 1.0.2 - 2010-02-05 - BugFix: fmod rounding error.
  * 1.0.3 - 2010-02-05 - Add: hex2int parsing of bg and fg properties.
  * 1.0.4 - 2011-01-05 - Add: support for units
  * 1.0.5 - 2013-07-29 - BugFix: better log support.
  * 1.0.6 - 2013-08-08 - Add: support for vertical=1
  */

// Required includes:
require($p->site_path."/var/www/lib/inc/colormap.inc.php");

// Defined constants:
define("FONTS_DIR","/var/www/lib/fonts");


function initParams() { 
  $params = array(
    "range_min" => 0,
    "range_max" => 1,
    "xvals" => null,
    "xlabs" => null,
    "scheme" => 0,
    "vertical" => false,
    "scale" => "linear",
    "reverse" => false,
    "title" => '',
    "units" => '',
    "font_family" => "Arial",
    "font_size" => 8,
    "width" => 200,
    "height" => 30,
    "ticks"  => "auto",
    "printf" => "auto",
    "pad_top"    => 2,
    "pad_right"  => 2,
    "pad_bottom" => 2,
    "pad_left"   => 2,
    "bg" => 0xffffff,
    "fg" => 0x000000
  );

  // Bulk set.
  foreach( array_keys($params) as $k ) { 
    if( isset($_REQUEST[$k]) ) { 
      $params[$k] = $_REQUEST[$k];
    }
  }
  // Parse
  if( isset($_REQUEST["padding"]) || isset($_REQUEST["pad"]) ) { 
    if( isset($_REQUEST["padding"]) ) { 
      $padding = explode(",",$_REQUEST["padding"]);
    } else { 
      $padding = explode(",",$_REQUEST["pad"]);
    }
    if( count($padding) == 1 ) { 
      $px = intVal($padding[0]);
      $padding = array($px,$px,$px,$px);
    }
    if( count($padding) == 2 ) { 
      $py = intVal($padding[0]);
      $px = intVal($padding[1]);
      $padding = array($py,$px,$py,$px);
    }
    if( count($padding) == 4 ) { 
      $params["pad_top"]    = $padding[0];
      $params["pad_right"]  = $padding[1];
      $params["pad_bottom"] = $padding[2];
      $params["pad_left"]   = $padding[3];
    }
  }
  if( isset($_REQUEST["range"]) ) { 
    $range = explode(",",$_REQUEST["range"]);
    if( count($range) == 2 ) { 
      $params["range_min"] = floatVal($range[0]);
      $params["range_max"] = floatVal($range[1]);
    }
  }
  if( isset($_REQUEST["xvals"]) ) { 
    $xvals = explode(",",$_REQUEST["xvals"]);
    if( count($xvals) >= 2 ) { 
      $params["xvals"] = array_map('floatVal',$xvals);
    }
  }

  // Normalize
  if( !intVal($params["bg"]) && colormap_hex2int($params["bg"]) ) { 
    $params["bg"] = colormap_hex2int($params["bg"]);
  } else { 
    $params["bg"] = intVal($params["bg"]);
  }

  if( !intVal($params["fg"]) && colormap_hex2int($params["fg"]) ) { 
    $params["fg"] = colormap_hex2int($params["fg"]);
  } else { 
    $params["fg"] = intVal($params["fg"]);
  }

  return $params;
} // END: function initParams()


function getFontFile($family) { 
  if( file_exists(FONTS_DIR."/${family}.ttf") ) { 
    return FONTS_DIR."/${family}.ttf";
  }
  return FONTS_DIR."/Arial.ttf";
} // END: function getFontFile($family)


function getTickCount(&$params) { 
  if( $params["ticks"]!=="auto" ) { 
    return $params["ticks"];
  }
  if( is_array($params["xvals"]) ) { 
    return count($params["xvals"]);
  }
  $lo = $params["range_min"];
  $hi = $params["range_max"];
  if( strtoupper(substr($params["scale"],0,3)) == "LOG" ) { 
    $base = floatVal(substr($scale,3));
    if( $base <= 0 ) {
      // recover from silly error.
      $base = exp(1);
    }

    // take log of base.  We'll divide by this often.
    $base_ilog = ($base>0) ? 1/log($base) : 1;

    $hi = ($hi<=0) ? 0 : log($hi)*$base_ilog;
    $lo = ($lo<=0) ? 0 : log($lo)*$base_ilog;
  }

  $dt = abs($hi-$lo);
  foreach( array(1,2,4,5,8,10,16,1) as $den ) { 
    if( ($den*$dt)-intVal($den*$dt) < 0.000001 ) { 
      break;
    }
  }
  if( ($den*$dt)-intVal($den*$dt) < 0.00001 ) { 
    $target = $diff = $nt = max(2,round($params["width"]/30));
    $dt = round($den*abs($hi-$lo));
    for($j=1,$i=2,$n=2*$nt; $i<=$n; $j++,$i++) { 
      if( $dt%$j == 0 & abs($nt-$i) < $diff ) { 
        $target = $i;
        $diff = abs($nt-$i);
      }
    }
    return $target;
  }
  return max(2,round($params["width"]/30));
} // END: function getTickCount(&$params)


function getTickValues(&$params) { 
  $n = getTickCount($params);
  if( $n < 1 ) { 
    return array();
  }

  $min = $params["range_min"];
  $max = $params["range_max"];
  $scale = $params["scale"];

  if( strcasecmp(substr($scale,0,3),"lin")===0 ) { 
    if( is_array($params["xvals"]) ) { 
      return $params["xvals"];
    }
    $dv = ($max-$min) / max(1,$n-1);
    $ticks = array();
    for($i=0; $i<$n; $i++) { 
      $ticks[] = $min + $i*$dv;
    }
    return $ticks;
  }

  if( strcasecmp(substr($scale,0,3),"log")===0 ) { 
    $base = exp(1);
    if( strcasecmp($scale,"log") !== 0 ) { 
      $base = floatVal(substr($scale,3));
      if( $base <= 0 ) { 
        // recover from silly error.
        $base = exp(1);
      }
    }

    // take log of base.  We'll divide by this often.
    $base_ilog = ($base>0) ? 1/log($base) : 1;

    if( $min<=0 ) { 
      $rem = $n - ceil(log($max)*$base_ilog);
      if( $rem>0 ) { 
        $min = pow($base,-$rem);
      } else { 
        $min = 1;
      }
    }
    if( $max<=0 ) { 
      $rem = $n - ceil(log($max)*$base_ilog);
      if( $rem>0 ) { 
        $max = pow($base,-$rem);
      } else { 
        $max = 1;
      }
    }

    if( is_array($params["xvals"]) ) { 
      $ticks = $params["xvals"];
      foreach( array_keys($ticks) as $i ) { 
        // convert user ticks to interal log scale
        $ticks[$i] = log($ticks[$i])*$base_ilog;
      }
    } else { 
      $min = log($min)*$base_ilog;
      $max = log($max)*$base_ilog;
      $dv = ($max-$min) / max(1,$n-1);
      $ticks = array();
      for($i=0; $i<$n; $i++) { 
        $exp = $min + $i*$dv;
        if( abs(fmod($exp,1.0)) < 0.000005 ) { 
          $ticks[] = "$base^$exp";
        } else { 
          $ticks[] = pow($base,$exp);
        }
      }
    }
    return $ticks;
  }

  return array();
} // END: function getTickValues(&$params)


function getDisplayTicks(&$params) { 
  if( is_array($params["xlabs"]) ) { 
    $ticks = $params["xlabs"];
  } else if( is_array($params["xvals"]) ) { 
    $ticks = $params["xvals"];
  } else { 
    // Get raw values for ticks.
    $ticks = getTickValues($params);
  }

  // Perform printf conversion
  if( $params["printf"] === "auto" ) {
    // TODO: Figure out why it displays wrong for -0.75 to +0.75 range.
return $ticks;
    $maxTickWidth = floor($params['width']/count($ticks));

    $font = getFontFile($params["font_family"]);

    $tickCount = 0;
    $lastTick = count($ticks);

    $aticks = array();
    foreach( $ticks as $tick ) {
      $tickCount++;
      $tickstr = (string)$tick;
      $box = imageTTFBBox($params['font_size'],0,$font,$tickstr);
      $textWidth = $box[2] - $box[0];

      if( $tickCount == 1 ) {
        $maxTick = 2*min(($maxTickWidth>>1) , $params['pad_left']);
      } else if( $tickCount == $lastTick ) {
        $maxTick = 2*min(($maxTickWidth>>1) , $params['pad_right']);
      } else {
        $maxTick = $maxTickWidth;
      }

      if( $textWidth <= $maxTick
      ||  $tickstr === "inf"
      ||  strpos($tickstr,'.') === false
      ){
        $aticks[] = $tickstr;

      } else {
        $aval = (string)abs($tick);
        $pos = strpos($aval,'.');
        $len = strlen($aval);
        $value = (string)round($aval * pow(10,$len-$pos-1));
        if( $tick<0 ) {
          $value = '-'.$value;
        }

        while( true ) {
          $len--;
          $tickstr = substr($value,0,$pos).'.'.substr($value,$pos,$len-$pos);
          $box = imageTTFBBox($params['font_size'],0,$font,$tickstr);
          $textWidth = $box[2] - $box[0];
          if( $textWidth <= $maxTick ) {
            $aticks[] = $tickstr;
            break;
          } else if( $pos >= $len-1 ) {
            $aticks[] = substr($tickstr,0,$pos);
            break;
          }
        }

      }
    }
    return $aticks;
  }


  foreach( array_keys($ticks) as $i ) { 
    if( $ticks[$i] !== "inf" ) { 
      $ticks[$i] = sprintf($params["printf"],$ticks[$i]);
    }
  }

  return $ticks;
} // END: function getDisplayTicks($params)


function generateColorbar(&$params) { 
  $width = $params["width"] + $params["pad_left"] + $params["pad_right"];
  $height = $params["height"] + $params["pad_top"] + $params["pad_bottom"];
 
  $img = imageCreateTrueColor($width,$height);

  // Allocate, fill background
  list($r,$g,$b,$a) = colormap_int2rgba($params["bg"]);
  $color_bg = imageColorAllocateAlpha($img,$r,$g,$b,$a);
  imageFill($img,1,1,$color_bg);

  // Allocate foreground
  list($r,$g,$b,$a) = colormap_int2rgba($params["fg"]);
  $color_fg = imageColorAllocateAlpha($img,$r,$g,$b,$a);

  // BOX Model:
  // inside = width - pad_left - pad_right - (2 * border_width)
  // Image pixels begin at zero (except for image fill).
  // Image is expanded to accomodate padding, not border.
  $x0 = $params["pad_left"];
  $x1 = $width - $params["pad_right"] - 1;
  $y0 = $params["pad_top"];
  $y1 = $height - $params["pad_bottom"] - 1;

  // Add title
  if( strlen($params["title"]) > 0 ) { 
    $size = $params["font_size"];
    $font = getFontFile($params["font_family"]);
    $text = $params["title"];
    if( strlen($params["units"]) > 0 ) {
      $text = sprintf("%s (%s)",$params["title"],$params["units"]);
    }
    if( $params["vertical"] ) { 
      $bbox = imageTtfBbox($size,0,$font,$text);
      $x = $width - ($params["pad_right"] - abs($bbox[7]-$bbox[1]))/2 + $bbox[7] + 1;
      $y = ($height - abs($bbox[2]-$bbox[0]))/2 - $bbox[0];
      imageTtfText($img,$size,-90,$x,$y,-$color_fg,$font,$text);
    } else { 
      // identify text bounds: [LLX,LLY,LRX,LRY,URX,URY,ULX,ULY]
      $bbox = imageTtfBbox($size,0,$font,$params["title"]);
      $x = ($width             - ($bbox[2] - $bbox[0]) ) / 2 - $bbox[0];
      $y = ($params["pad_top"] - ($bbox[7] - $bbox[1]) ) / 2 - $bbox[1]; 
      imageTtfText($img,$size,0,$x,$y,$color_fg,$font,$params["title"]);
    }
  }

  // Draw the color scale
  if( $params["vertical"] ) { 
    $innerHeight = $params["height"] - 2;
    $map = colormap_generate($innerHeight,$params["scheme"]);
    if( $params["reverse"] ) { 
      $map = array_reverse($map);
    }
    for($i=0,$n=$innerHeight; $i<$n; $i++) { 
      list($r,$g,$b,$a) = $map[$i];
      $color = imageColorAllocateAlpha($img,$r,$g,$b,$a);
      imageLine($img,$x0+1,$y0+$i+1, $x1-1, $y0+$i+1, $color);
    }
  } else { 
    $innerWidth = $params["width"] - 2;
    $map = colormap_generate($innerWidth,$params["scheme"]);
    if( $params["reverse"] ) { 
      $map = array_reverse($map);
    }
    for($i=0,$n=$innerWidth; $i<$n; $i++) { 
      list($r,$g,$b,$a) = $map[$i];
      $color = imageColorAllocateAlpha($img,$r,$g,$b,$a);
      imageLine($img,$x0+$i+1, $y0+1, $x0+$i+1, $y1-1, $color);
    }
  }
    
  // Add tick marks
  $xvals = getTickValues($params);
  $xlabs = getDisplayTicks($params);
  if( !empty($xvals) ) { 
    $lo = min($xvals);
    $hi = max($xvals);

    if( $params["vertical"] ) { 
      $dy = ($hi==$lo) ? ($y1-$y0+1) : ($y1-$y0+1) / ($hi-$lo);
      $stick = round( ($x1-$x0-2)/8 );
      $cx = ($x1+$x0)>>1;
      $cxr = ($x1+$x0)%2;
      for($i=1,$n=count($xvals)-1; $i<$n; $i++) { 
        $y = min($y1, $y0 + ($xvals[$i]-$lo)*$dy);
        imageLine($img,$x0+1,$y, $x0+1+$stick,$y, $color_fg);
        imageSetPixel($img,$cx,$y,$color_fg);
        imageSetPixel($img,$cx+$cxr,$y,$color_fg);
        imageLine($img,$x1-1-$stick,$y,$x1-1,$y,$color_fg);
      }

      // Add text below ticks
      $size = $params["font_size"] - 2;
      $font = getFontFile($params["font_family"]);
      if( $params["pad_left"] >= $size) { 
      // If there's room on the bottom, do that.
        foreach( array_keys($xvals) as $i ) { 
          $bbox = imageTtfBbox($size,0,$font,$xlabs[$i]);
          $x = ($params["pad_left"] - ($bbox[4]-$bbox[0])) / 2 + 1;
          $cy = min($y1, $y0 + ($xvals[$i]-$lo)*$dy);
          $y = $cy - abs($bbox[5] - $bbox[1]) / 2 - $bbox[5];
          imageTtfText($img,$size,0,$x,$y,$color_fg,$font,$xlabs[$i]);
        }

      } else {
      // Else put text in the center (whattayagonnadoboudit?)
        $cx = ($x1+$x0)/2 + 1;
        foreach( array_keys($xvals) as $i ) { 
          $bbox = imageTtfBbox($size,-90,$font,$xlabs[$i]);
          $cy = min($y1, $y0 + ($xvals[$i]-$lo)*$dy);
          $x = $cx - abs($bbox[4] - $bbox[0]) / 2;
          $y = $cy - abs($bbox[5] - $bbox[1]) / 2 + 1;
          imageTtfText($img,$size,-90,$x,$y,$color_fg,$font,$xlabs[$i]);
        }
      }

    } else { // do horizontal

      $dx = ($hi==$lo) ? ($x1-$x0+1) : ($x1-$x0+1) / ($hi-$lo);
      $stick = round( ($y1-$y0-2)/8 );
      $cy = ($y1+$y0)>>1;
      $cyr = ($y1+$y0)%2;
      for($i=1,$n=count($xvals)-1; $i<$n; $i++) { 
        $x = min($x1, $x0 + ($xvals[$i]-$lo)*$dx);
        imageLine($img,$x, $y0+1, $x, $y0+1+$stick, $color_fg);
        imageSetPixel($img,$x,$cy,$color_fg);
        imageSetPixel($img,$x,$cy+$cyr,$color_fg);
        imageLine($img,$x, $y1-1-$stick, $x, $y1-1, $color_fg);
      }

      // Add text below ticks
      $size = $params["font_size"] - 2;
      $font = getFontFile($params["font_family"]);
      if( $params["pad_bottom"] >= $size) { 
      // If there's room on the bottom, do that.
        foreach( array_keys($xvals) as $i ) { 
          $bbox = imageTtfBbox($size,0,$font,$xlabs[$i]);
          $cx = min($x1, $x0 + ($xvals[$i]-$lo)*$dx);
          $x = $cx - ($bbox[2] - $bbox[0]) / 2;
          $y = $y1 + ($params["pad_bottom"] - ($bbox[7]-$bbox[1])) / 2;
          imageTtfText($img,$size,0,$x,$y,$color_fg,$font,$xlabs[$i]);
        }

      } else {
      // Else put text in the center (whattayagonnadoboudit?)
        $cy = ($y1+$y0)/2;
        foreach( array_keys($xvals) as $i ) { 
          $bbox = imageTtfBbox($size,0,$font,$xlabs[$i]);
          $cx = min($x1, $x0 + ($xvals[$i]-$lo)*$dx);
          $x = $cx - ($bbox[2] - $bbox[0]) / 2;
          $y = $cy - ($bbox[7] - $bbox[1]) / 2;
          imageTtfText($img,$size,0,$x,$y,$color_fg,$font,$xlabs[$i]);
        }
      }
    } // else horizontal
  } // !empty(xvals)


  // Draw border
  imageRectangle($img,$x0,$y0,$x1,$y1,$color_fg);

  return $img;
} // END: function generateColorbar(&$params)


function main() { 
  $params = initParams();
  $img = generateColorbar($params);
  if( $img instanceof GdImage ) { 
    header("Content-Type: image/png");
    imageSaveAlpha($img,true);
    imagePng($img);
    imageDestroy($img);
    exit(0);
  }
  header("Content-Type: text/plain");
  echo("An error occurred.");
  exit(1);
}


main();

// EOF -- cb.php
?>
