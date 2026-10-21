<?php
 /**
  * Utility functions for lookup.
  *
  * @file lu.inc.php
  * @date 2019-04-03 11:48 PDT
  * @author Paul Reuter/Joseph Chen
  * @version 1.0.3
  * 
  * @modifications:
  * 1.0.0 - 2009-12-14 - Created from nn.php
  * 1.0.1 - 2010-02-04 - Add PRVI from sandbar's latLonToRegion.inc.php
  * 1.0.2 - 2014-10-02 - Add: getRelNCFilePath($reg,$pfx,$res,$ts)
  * 1.0.3 - 2019-04-03 - Bug: added long term averages to lookupFilePartFromPrefix and getRelFilePath
  */
 
function getRegionFromLatLon($lat,$lon) { 
  if( $lon >= -130.36 && $lon <= -115.75 && $lat >= 30.25 && $lat <= 50.0 ) { 
    return "USWC";
  }
  if( $lon >= -98.0 && $lon <= -54.5 && $lat >= 21.7 && $lat <= 46.5 ) { 
    return "USEGC";
  }
  if( $lon >= -164.0 && $lon <= -151 && $lat >= 15.0 && $lat <= 26.0 ) { 
    return "USHI";
  }
  if( $lon >= -70.5 && $lon <= -61.0 && $lat >= 14.5 && $lat <= 22.0 ) { 
    return "PRVI";
  }
  if( $lon >= -167.0 && $lon <= -123.0 && $lat >= 50.0 && $lat <= 62.0 ) { 
    return "GAK";
  }
  if( $lon >= -174.5 && $lon <= -128.5 && $lat >= 50.0 && $lat <= 72.25 ) { 
    return "AKNS";
  }
  if( $lon >= 123.0 && $lon <= 132.0 && $lat >= 32.0 && $lat <= 40.0 ) { 
    return "SKOR";
  }
  return "UNKNOWN";
} // END: function getRegionFromLatLon($lat,$lon)


function lookupFilePartFromResolution($res) { 
  if( in_array((string)$res,array("500m","1km","2km","6km")) ) { 
    return $res;
  }
  if( $res >= 1000 ) { 
    return sprintf("%dkm",$res/1000);
  }
  return sprintf("%dm",$res);
} // END: function lookupFilePartFromResolution($res)


function lookupFilePartFromPrefix($pfx) { 
  if( $pfx==="a" ) { 
    return "-25hrAvg";
  }
  if( $pfx==="am" ){
    return "-monthAvg";
  }
  if( $pfx==="ay" ){
    return "-annualAvg";
  }
  return "";
} // END: function lookupFilePartFromPrefix($pfx)


function getRelFilePath($reg,$pfx,$res,$ts) { 
  if( $pfx=="-monthAvg" ){
    // Create a file path
    // USWC/long_term_averages/2019/02/rtv-monthAvg_uswc_1km_uwls_201902.asc 
    return sprintf(
       "%s/long_term_averages/%s/rtv%s_%s_%s_uwls_%s.asc",
       $reg,gmdate("Y/m",$ts),$pfx,
       strtolower($reg),$res,gmdate("Ym",$ts) 
    );
  }
  if( $pfx=="-annualAvg" ){
    // Create a file path
    // USWC/long_term_averages/2019/rtv-annualAvg_uswc_1km_uwls_2019.asc 
    return sprintf(
       "%s/long_term_averages/%s/rtv%s_%s_%s_uwls_%s.asc",
       $reg,gmdate("Y",$ts),$pfx,
       strtolower($reg),$res,gmdate("Y",$ts) 
    );
  }
  // Create a file path
  return sprintf(
     "%s/%s/ascii/rtv%s_%s_%s_uwls_%s.asc",
     $reg,gmdate("Y_m",$ts),$pfx,
     strtolower($reg),$res,gmdate("Y_m_d_H",$ts)."00" 
  );
} // END: function getRelFilePath($reg,$pfx_txt,$res_txt,$ts)


# TODO: not sure if this function gets used anywhere.
# If it does, will need to edit so it can return long term averages
function getRelNCFilePath($reg,$pfx,$res,$ts) { 
  // Create a file path
  // Q: Where is pfx?
  return sprintf(
     "%s/%s/NetCDF/%s_hfr_%s_%s_rtv_uwls_SIO.nc",
     $reg, gmdate("Y_m",$ts),
     gmdate("YmdH", $ts)."00", strtolower($reg), $res
  );
} // END: function getRelFilePath($reg,$pfx_txt,$res_txt,$ts)

// EOF -- lu.inc.php
?>
