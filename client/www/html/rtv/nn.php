<?php
 /**
  * Utility to return the closest lat,lon,u,v tuple to a requested
  * lat,lon request.
  *
  * @file nn.php
  * @date 2009-12-14 15:58 HST
  * @author Paul Reuter
  * @version 2.1.3
  * 
  * @modifications:
  * 1.0   - 2006-10-13 - Initial release
  * 1.1   - 2006-11-16 - Modified defined elements
  * 1.2   - 2008-08-15 - Turned inline into a function
  * 2.0   - 2009-07-25 - Converted to JSON for remote control.
  * 2.1.1 - 2009-12-10 - Renamed and ported to hfrnet.
  * 2.1.2 - 2009-12-14 - BugFix: USWC region missing negative sign.
  * 2.1.3 - 2009-12-14 - Extract lookup functions to lu.inc.php and included.
  */
 
// Global configuration settings.
define("LIB_DIR","/var/www/lib");
$config = parse_ini_file(LIB_DIR.'/rtv/rtv.ini',true);
require_once(LIB_DIR.'/rtv/lu.inc.php');


function nearestNeighbor($lat=null,$lon=null,$ts=null,$res="6km",$pfx=null) { 
  global $config;
  if( !is_numeric($lat) ) { 
    echo("// bad param: lat");
    return false;
  }
  if( !is_numeric($lon) ) { 
    echo("// bad param: lon");
    return false;
  }
  if( !is_numeric($ts) ) { 
    echo("// bad param: time");
    return false;
  }

  $reg = getRegionFromLatLon($lat,$lon);
  $pfx_txt = lookupFilePartFromPrefix($pfx);
  $res_txt = lookupFilePartFromResolution($res);

  // Create a file path
  $fpath = $config["gentuples"]["RTV_ROOT"].
    "/". getRelFilePath($reg, $pfx_txt, $res_txt, $ts );

  if(!file_exists($fpath)) { 
    echo("// no file ($fpath)");
    return false;
  }

  $lines = file($fpath);
  $n = count($lines);
  $row = null;
  $minDiff = 360;
  for($i=0;$i<$n;$i++) { 
    $t = preg_split("/\s+/",$lines[$i],-1,PREG_SPLIT_NO_EMPTY);
    $diff = abs($lat-$t[0]) + abs($lon-$t[1]);
    if($diff < $minDiff) { 
      $minDiff = $diff;
      $row = $t;
    }
  }
  unset($lines);

  // prepare json lat,lng,u,v
  if(is_array($row) && count($row) == 4) { 
    $inner = '{lat:'.$row[0].',lng:'.$row[1].',u:'.$row[2].',v:'.$row[3].'}';
  } else {
    $inner = 'empty or poorly formatted file';
  }

  if( isset($_REQUEST["callback"]) ) { 
    // Emit JSON-P ( with callback function )
    echo($_REQUEST["callback"].'('.$inner.');');
  } else { 
    // Emit JSON
    echo($inner);
  }
  return true;
} // END: function nearestNeighbor($lat,$lon,$time,$res,$pfx)




function main() { 
  header("Content-type: text/plain");
  $lat  = (isset($_REQUEST["lat"]))  ? $_REQUEST["lat"]  : null;
  $lon  = (isset($_REQUEST["lon"]))  ? $_REQUEST["lon"]  : null;
  $time = (isset($_REQUEST["time"])) ? $_REQUEST["time"] : null;
  $res  = (isset($_REQUEST["res"]))  ? $_REQUEST["res"]  : "6km";
  $pfx  = (isset($_REQUEST["pfx"]))  ? $_REQUEST["pfx"]  : "a";
  nearestNeighbor($lat,$lon,$time,$res,$pfx);
} // END: function main();

main();

// EOF -- nn.php
?>
