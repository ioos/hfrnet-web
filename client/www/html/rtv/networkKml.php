<?php
 /**
  * @file networkKml2.php
  * @date 2022-07-29 01:45 PDT
  * @author Paul Reuter/Joseph Chen
  * @version 2.0.1
  *
  * @modifications
  * 1.0.1 - 2020-06-26 - Removed next_records()
  * 1.1.0 - 2021-11-16 - Changed getLatestTimestamp to use filesystem instead of db (db going away probably)
  * 2.0.0 - 2021-12-14 - Used netcdf service on hfrnet instead of asc files and db
  * 2.0.1 - 2022-07-29 - Remove references to projects/mapping
  */

$regions = array('AKNS','GAK','PRVI','USEGC','USHI','USWC');
$ROOT_DIR = "/exports/hfradar/hfrnet/hfrtv";

function the_scheme() { 
  $cs = (isset($_REQUEST["cs"])) ? $_REQUEST["cs"] : "ROGB";
  switch($cs) { 
    case "JET": return 0;
    case "HEAT": return 1;
    case "BLUE_BIAS": return 2;
    case "COLD": return 3;
    case "ROGB": return 4;
    case "JUICE": return 5;
    case "BURR": return 6;
    case "BLUE_RED": return 7;
    case "OCEAN": return 8;
    case "GREY": return 9;
    case "THERMAL": return 10;
    case "HEATMAP": return 11;
    case "STOPLIGHT": return 12;
    case "WHEEL": return 13;
    case "ALT_WHEEL": return 14;
  }
  return 4;
}

// Go through the file system and find the latest timestamp
// $dir = "/exports/hfradar/hfrnet/hfrtv/USWC/2021_10/NetCDF/*_6km_*uwls_SIO.nc";
// 202110211800_hfr_uswc_6km_rtv_uwls_SIO.nc
function getLatestTimestamp($res,$pfx) {
  $time = gmdate("Y_m");
  $lastfiles = array();
  global $regions;
  global $ROOT_DIR;

  foreach( $regions as $region ){
    if ($pfx == "h"){
      $file = sprintf("*_%s_*uwls_SIO.nc", $res);
    }
    else {
      $file = sprintf("*_%s_*uwls_25hr_average_SIO.nc", $res);
    }

    $dir = sprintf("%s/%s/%s/NetCDF/%s", $ROOT_DIR, $region, $time, $file);
    $foundfiles = glob($dir);

    array_push($lastfiles, basename(array_pop( $foundfiles )));
  }

  sort($lastfiles);
  $lastfile = array_pop($lastfiles);
  // Get datetime from lastfile
  $regex = "/(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})/";
  preg_match($regex,$lastfile,$dt);

  $latest = gmmktime($dt[4],$dt[5],0,$dt[2],$dt[3],$dt[1]);
  return $latest;
} // END: function getLatestTimestamp($res,$pfx)


function main() { 
  header("Content-Type: application/vnd.google-earth.kml+xml");
  header("Content-Disposition: inline; filename=network.kml");
  echo('<'.'?xml version="1.0" encoding="UTF-8"?'.">\n");

  $cs = (isset($_REQUEST["cs"])) ? $_REQUEST["cs"] : "ROGB";
  $pfx = (isset($_REQUEST["pfx"])) ? $_REQUEST["pfx"] : "a";
  $res = (isset($_REQUEST["res"])) ? $_REQUEST["res"] : "6km";
  $rng = (isset($_REQUEST["rng"])) ? $_REQUEST["rng"] : "0,50";
  $bbox = (isset($_REQUEST["bbox"])) ? $_REQUEST["bbox"] : null;

  $t1 = getLatestTimestamp($res,$pfx);
  if( isset($_REQUEST["t1"]) ) { 
    $t1 = $_REQUEST["t1"];
  }

  $t0 = $t1 - 7*86400;
  // strtotime("2010-05-01 06:00 UTC");
  // '30.5,-81,25,-90';
  if( isset($_REQUEST["t0"]) ) { 
    $t0 = $_REQUEST["t0"];
    if( isset($_REQUEST["dt"]) ) { 
      $t1 = $t0 + $_REQUEST["dt"];
    }
  } else if( isset($_REQUEST["dt"]) ) { 
    $t0 = $t1 - $_REQUEST["dt"];
  }

?>
<kml xmlns="http://www.opengis.net/kml/2.2" xmlns:gx="http://www.google.com/kml/ext/2.2" xmlns:kml="http://www.opengis.net/kml/2.2" xmlns:atom="http://www.w3.org/2005/Atom">
<Folder>
 <name>HF RADAR Derived Surface Currents</name>
 <ScreenOverlay>
  <name>Colorbar</name>
  <visibility>1</visibility>
  <Icon><href>http://cordc.ucsd.edu/projects/hfrnet/img/php/cb.php?range_min=0&amp;range_max=50&amp;width=204&amp;height=15&amp;padding=15,8&amp;font_size=10&amp;title=Current%20Strength%20(cm/s)&amp;scheme=<?php echo the_scheme(); ?>&amp;bg=0x00ffffff&amp;ticks=6</href></Icon>
  <overlayXY x="0" y="135" xunits="pixels" yunits="pixels"/>
  <screenXY x="5" y="1" xunits="pixels" yunits="fraction"/>
  <rotationXY x="0" y="0" xunits="pixels" yunits="pixels"/>
  <size x="0" y="0" xunits="pixels" yunits="pixels"/>
 </ScreenOverlay>

<?php

  for($ts=$t1; $ts>=$t0; $ts-=3600) { 
  echo("
<NetworkLink>
  <name>".gmdate("Y-m-d H:i:s",$ts)." UTC</name>
  <visibility>1</visibility>
  <TimeSpan>
    <begin>".gmdate("Y-m-d\TH:i:s\Z",$ts-1800)."</begin>
    <end>".gmdate("Y-m-d\TH:i:s\Z",$ts+1799)."</end>
  </TimeSpan>
  <Style>
    <ListStyle>
      <listItemType>checkHideChildren</listItemType>
      <bgColor>00ffffff</bgColor>
      <maxSnippetLines>2</maxSnippetLines>
    </ListStyle>
  </Style>
  <Link>
    <href>http://hfrnet.ucsd.edu/rtv/vectorKml.php?res={$res}&amp;pfx={$pfx}&amp;ts={$ts}&amp;bbox={$bbox}&amp;rng={$rng}&amp;cs={$cs}</href>
  </Link>
</NetworkLink>
  ");

  } // end for(ts=t1..t0)

  echo("
</Folder>
</kml>");

} // END: function main()


main();

// EOF -- networkKml.php
?>
