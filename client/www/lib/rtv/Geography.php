<?php
 /**
  * Provide a basic infrastructure for geographic transformations pertaining
  * to coordinates and images.
  *
  * @file Geography.php
  * @date 2009-11-21 14:04 HST
  * @author Paul Reuter
  * @version 1.0.3
  *
  * @dependencies
  *   GDAL Utilities' `gdaltransform` program
  *
  * @modifications
  * 1.0.0 - 2009-11-19 - Created from Decomposer.php before Tiler.php
  * 1.0.1 - 2009-11-19 - transform returns $points if s_crs==t_crs
  * 1.0.2 - 2009-11-20 - Added toPixel/fromPixel(x,y) methods and setScale()
  * 1.0.3 - 2009-11-21 - Bugfix: to/from Pixel: y is positive-up (*-1).
  */


// Change to the full path if gdaltransform is not in your system path.
define("GDALTRANSFORM","gdaltransform");

// Change to the full path if gdalwarp is not in your system path.
define("GDALWARP","gdalwarp");

// Maximum number of points to be transformed in batches.
// echo [point]* | gdaltransform -s_srs <srcproj> -t_srs <dstproj>
define("TRANSFORM_LIMIT",200);


/**
 * Basic geographic library for coordinate transformation and pixel resolution.
 *
 * @package Geography
 */
class Geography {

  var $srcproj = 'EPSG:4326';  // Projection of input coordinates/image/etc.
  var $dstproj = 'EPSG:4326';  // Projection of output coordinates/image

  var $originX = 0;       // Source coordinate-X to map to center of tile.
  var $originY = 0;       // Source coordinate-Y to map to center of tile.

  var $bounds = null;          // Array of (n,e,s,w) image edge coordinates
  var $scale = 1;              // For computing pixel location
  var $isWGS84Bounds = true;   // Flag indicating bounds are WGS84 coords
  var $m_bounds = null;        // Cached bounds in proj coordinates.

  /**
   * Assign the projection or coordinate system of the input and (optionally)
   * output.  If output is specified, this will be the default projection
   * used in calls to $this->transform().  It's good practice to specify the
   * src and dst projections for transform() here because the projection
   * strings are validated before executed.
   *
   * @param string $srcproj A proj4 projection string used throughout.
   * @param string $dstproj A proj4 projection string used by transform()
   * @return bool success or failure.
   */
  function setProj($srcproj,$dstproj=null) { 
    if( !$this->validateProj($srcproj) ) { 
      error_log("Invalid Projection: `$srcproj`");
      return false;
    }
    $this->srcproj = $srcproj;

    if( $dstproj !== null ) { 
      if( !$this->validateProj($dstproj) ) { 
        error_log("Invalid Projection: `$dstproj`");
        return false;
      }
      $this->dstproj = $dstproj;
    }
    return true;
  } // END: function setProj($srcproj,$dstproj=null)


  /**
   * Set the scale of an image (coordinate units per pixel).
   *
   * @param float $k A scale factor.
   * @return bool success or failure.
   */
  function setScale($k) { 
    if( !is_numeric($k) ) { 
      error_log("Scale `$k` must be numeric.");
      return false;
    }

    if( -0.000005 < $k && $k < 0.000005 ) { 
      error_log("Scale `$k` cannot be zero.");
      return false;
    }
    $this->scale = $k;
    return true;
  } // END: function setScale($k)


  /**
   * Allows you to override the origin of the image when sliced into tiles.
   * This is useful for centering non-geographic images in pixel coordinates.
   * Default is [0,0].
   *
   * @param float $x The x offset in source's CRS.
   * @param float $y The y offset in source's CRS.
   * @return bool True if both x and y are numeric, false otherwise.
   */
  function setOrigin($x,$y) {
    $this->originX = $x;
    $this->originY = $y;
    return (is_numeric($x) && is_numeric($y));
  } // END: function setOrigin($x,$y)


  function validateProj($proj) { 
    @system(
      'echo 0 0 | gdaltransform -s_srs EPSG:4326 -t_srs '.
      escapeshellarg($proj).' > /dev/null 2>&1',
      $retVal
    );
    return ($retVal===0);
  } // END: function validate($proj)


  /**
   * Assign known bounds.  The crs of the bounds is WGS84.  To set bounds
   * in the current crs, the one specified by setProj(), use setBounds().
   *
   * @param array $bounds An array of ($north,$east,$south,$west).  The values
   *   represent the edge of the pixel, not center like the world-file format.
   * @param float $n Northern edge boundary
   * @param float $e Eastern edge boundary
   * @param float $s Southern edge boundary
   * @param float $w Western edge boundary
   * @return bool success or failure.
   */
  function setBoundsWGS84($n,$e=null,$s=null,$w=null) {
    if( !$this->setBounds($n,$e,$s,$w) ) { 
      return false;
    }
    $this->isWGS84Bounds = true;
    return true;
  } // END: function setBoundsWGS84($n,$e,$s,$w)


  /**
   * Assign known bounds.  The crs of the bounds is the projection specified
   * by the user via setProj().  For WGS84 coordinates, use setBoundsWGS84.
   *
   * If only one parameter is specified, it is treated as a bounding box
   * containing the values (north,east,south,west) in that order.
   *
   * @param array $bounds An array of ($north,$east,$south,$west).  The values
   *   represent the edge of the pixel, not center like the world-file format.
   *
   * @param float $n Northern edge boundary
   * @param float $e Eastern edge boundary
   * @param float $s Southern edge boundary
   * @param float $w Western edge boundary
   * @return bool success or failure.
   */
  function setBounds($n,$e=null,$s=null,$w=null) {
    $this->m_bounds = null;
    if( is_array($n) && count($n) == 4) { 
      $this->bounds = array_values($n);
      return true;
    }
    if( !is_numeric($n) || !is_numeric($e) 
    || !is_numeric($s) || !is_numeric($w) ) { 
      return false;
    }
    $this->bounds = array($n,$e,$s,$w);
    $this->isWGS84Bounds = false;
    return true;
  } // END: function setBounds($n,$e,$s,$w)



  /**
   * Get the bounds in the CRS specified by setProj().  Default: WGS84.
   *
   * @return array An array of bounds (north,east,south,west).
   */
  function getBounds() { 
    if( $this->m_bounds !== null ) { 
      return $this->m_bounds;
    }
    if( empty($this->bounds) || !$this->isWGS84Bounds ) { 
      $this->m_bounds = $this->bounds;
    } else { 
      // Convert nesw to proj coordinates.
      $en = array($this->bounds[1],$this->bounds[0]);
      $ws = array($this->bounds[3],$this->bounds[2]);
      list($en,$ws) = $this->transform(
        array( $en, $ws ), 'EPSG:4326', $this->srcproj
      );
      $this->m_bounds = array($en[1],$en[0],$ws[1],$ws[0]);
    }
    return $this->m_bounds;
  } // END: function getBounds()



  /**
   * Return the coordinates of a location in an image identified by percent
   * offset from top-left.  The coordinates returned will be in the bounds CRS
   * provided.  
   * 
   * Note: This is only useful when the projection is not WGS84 and you've
   * provided WGS84 coordinates.  A non-linear transformation from pixel
   * offset to WGS84 is required.
   *
   * Note: Input must be percent, since offset/span is easily calculated.
   *
   * @param float $pct_x Left offset as percent [0,1] to convert to WGS84.
   * @param float $pct_x Top offset as percent [0,1] to convert to WGS84.
   * @return array A point containing ($x,$y), where $x,$y are in bounds' CRS.
   */ 
  function fromPercentToLonLat($pct_x,$pct_y) { 
    list($n,$e,$s,$w) = $this->getBounds();
    
    $proj_x = $w + $pct_x*($e-$w);
    $proj_y = $n + $pct_y*($s-$n);
    
    if( $this->isWGS84Bounds ) { 
      return $this->transform(array($proj_x,$proj_y),$this->srcproj,'EPSG:4326');
    }
    return array($proj_x,$proj_y);
  } // END: function fromPercentToLonLat($pct_x,$pct_y)



  /**
   * Convert an array of points from $s_crs to $t_crs.
   * Performs a system call to `gdaltranslate` for all points at once.
   * Limits are currently unknown, and have been arbitrarily chosen.
   *
   * @param array $points an array of arrays containing (x,y[,z]).
   * @param string $s_crs A proj4 supported CRS defining $points ref-sys.
   * @param string $t_crs A proj4 supported CRS to convert $points to.
   * @return array of arrays containing (x,y,z) in the $t_crs ref-sys.
   */
  function transform($points,$s_crs=null,$t_crs=null) {
    $s_crs = ($s_crs===null) ? $this->srcproj : $s_crs;
    $t_crs = ($t_crs===null) ? $this->dstproj : $t_crs;

    if( $s_crs === $t_crs ) { 
      // no transform necessary.
      return $points;
    }

    // Points must be an array of valid points.
    if( !is_array($points) || count($points) < 1 ) { 
      error_log("transform expects an array of points.");
      return false;
    }
    $points = array_values($points);

    // Was $points actually just one point?
    // If so, return exactly one point.
    $isSingle = false;
    if( !is_array($points[0]) ) { 
      $points = array($points);
      $isSingle = true;
    }


    // Do operation in small increments
    // because we're doing this on the command-line.
    $transformed = array();
    for($i=0,$n=count($points); $i<$n; $i+=TRANSFORM_LIMIT) { 
      $subpoints = array_slice($points,$i,TRANSFORM_LIMIT);

      // Build a space-by-newline delimited text string for $points.
      // Passed by echo, piped to gdaltranslate for all-in-one conversion.
      $coords = '';
      foreach($subpoints as $point) { 
        foreach($point as $v) { 
          // Ensure that unescaped numbers are command-line safe.
          if( !is_numeric($v) ) {
            error_log("NaN: $v");
            return false;
          }
        }
        $coords = $coords."\n".implode(" ",$point);
      }

      // Execute exactly one system call for N-points.
      $cmd = sprintf(
        "echo '%s' | %s -s_srs %s -t_srs %s",
        substr($coords,1),
        escapeshellarg(GDALTRANSFORM),
        escapeshellarg($s_crs),
        escapeshellarg($t_crs)
      );

      $result = @shell_exec($cmd);

      // pat0: match number followed by optional decimal
      // pat0: OR match optional number followed by decimal number
      // Examples: 0.1, .1, 1.
      $pat0 = '[+-]?(?:\d+(?:\.\d*)?|\d*\.\d+)';
      // pat1: match numbers with floating point exponents.
      $pat1 = $pat0.'(?:[eE]'.$pat0.')?';
      // pat2: match 2 or 3 floats separated by white space.
      $pat2 = '/^\s*('.$pat1.')\s+('.$pat1.')(?:\s+('.$pat1.'))?\s*$/';

      // Parse all points, line-by-line.
      // Each line has 3 values (x,y,z).
      foreach( explode("\n",rtrim($result)) as $record ) { 
        if( preg_match($pat2,$record,$pts) ) {
          array_shift($pts);
          $transformed[] = $pts;
        } else {
          $transformed[] = false;
        }
      }
    } // for(i..count(points) by 200)

    // Return either the first point or an array of points.
    return ($isSingle) ? $transformed[0] : $transformed;
  } // END: function transform($points,$s_crs=null,$t_crs=null)


  function fromPixel($x,$y) { 
    return array(
      $x*$this->scale + $this->originX, 
      ($y*$this->scale + $this->originY) * -1
    );
  }

  function toPixel($x,$y) { 
    return array(
      ($x-$this->originX)/$this->scale,
      (($y-$this->originY)/$this->scale) * -1
    );
  }

  /**
   * Warp an image using GDAL utilities.  Conversion between tif and <ext>
   * will be required.  setBounds, setProj($s_srs,$t_srs) must have been
   * called prior to calling this function.
   *
   * @param string $src An input image to warp.
   * @param string $dst An output file from the warp.
   * @return bool success or failure.
   */
  function warp($src,$dst) {
    // -----------------------------------------------------------------------
    // -- TODO -- 
    // -----------------------------------------------------------------------
  } // END: function warp($src,$dst)


} // END: class Geography()


?>
