<?php
 /**
  * Generate a station status kml document.
  *
  * @file stasta.php
  * @date 2020-10-15 12:12 PDT
  * @author Paul Reuter/Joseph Chen
  * @version 1.2.5
  *
  * @modifications
  * 1.0.0 - 2010-02-06 - Created with skeleton classes.
  * 1.0.1 - 2010-02-07 - Completed, functional.
  * 1.0.2 - 2010-02-12 - Add: Caching
  * 1.0.3 - 2010-02-12 - Add: http header, changed paths.
  * 1.1.0 - 2014-10-01 - Utilize Antelope's MySQL database.
  * 1.1.1 - 2015-07-14 - Changed href in function getStationKml.
  * 1.1.2 - 2015-10-09 - Age was incorrect for older stations
  * 1.1.3 - 2016-01-21 - Added white icon for really old data.
  *                      Changed sql to include stations with no current radial files 
  * 1.1.4 - 2016-07-06 - Add url option to specify which rtv.ini file
  * 1.1.5 - 2016-09-09 - Removed Beam Pattern from the popup
  * 1.2.0 - 2016-11-09 - Changed db to use cs2
  *                      Moved kml files to kml dir
  * 1.2.1 - 2017-01-12 - Changed url for palau diagnostics
  * 1.2.2 - 2018-01-19 - added vietnam diagnostics
  * 1.2.3 - 2020-01-13 - Changed query, adding any_value
  * 1.2.4 - 2020-06-26 - Removed next_records()
  * 1.2.5 - 2020-10-15 - Changes to use rtv.cordc.ini instead of rtv.pw and rtv.vn
  */


// parse url
parse_str($_SERVER['QUERY_STRING'],$url_comp);

// Load ini file:
ini_set("memory_limit","400M");
$config_all = parse_ini_file('/var/www/lib/diagnostics/hfrnet_db.ini',true);
$config = isset($config_all['hfradar']) ? $config_all['hfradar'] : $config_all;

// Required includes:
require_once('/var/www/lib/rtv/Filesystem.php');
require_once('/var/www/lib/rtv/Db.php');


function getStationKml($hdr,&$mat) { 
  global $url_comp;
  $ihdr = array_flip($hdr);

  ob_start();
  echo('<'.'?xml version="1.0" encoding="UTF-8"?'.">\n");
  echo('<kml xmlns="http://www.opengis.net/kml/2.2" xmlns:gx="http://www.google.com/kml/ext/2.2">');
  echo('<Document>');
  echo('
   <Style id="hfg">
    <IconStyle>
     <color>ffffffff</color>
     <scale>0.5</scale>
     <Icon>
      <href>http://maps.google.com/mapfiles/kml/paddle/grn-blank.png</href>
     </Icon>
     <hotSpot x="32" y="1" xunits="pixels" yunits="pixels"/>
    </IconStyle>
    <ListStyle>
     <color>ff00c000</color>
     <ItemIcon>
      <href>http://maps.google.com/mapfiles/kml/paddle/grn-blank-lv.png</href>
     </ItemIcon>
    </ListStyle>
   </Style>
  ');
  echo('
   <Style id="hfy">
    <IconStyle>
     <color>ffffffff</color>
     <scale>0.6</scale>
     <Icon>
      <href>http://maps.google.com/mapfiles/kml/paddle/ylw-blank.png</href>
     </Icon>
     <hotSpot x="32" y="1" xunits="pixels" yunits="pixels"/>
    </IconStyle>
    <ListStyle>
     <color>ff00ffff</color>
     <ItemIcon>
      <href>http://maps.google.com/mapfiles/kml/paddle/ylw-blank-lv.png</href>
     </ItemIcon>
    </ListStyle>
   </Style>
  ');
  echo('
   <Style id="hfr">
    <IconStyle>
     <color>ffffffff</color>
     <scale>0.7</scale>
     <Icon>
      <href>http://maps.google.com/mapfiles/kml/paddle/red-blank.png</href>
     </Icon>
     <hotSpot x="32" y="1" xunits="pixels" yunits="pixels"/>
    </IconStyle>
    <ListStyle>
     <color>ff0000c0</color>
     <ItemIcon>
      <href>http://maps.google.com/mapfiles/kml/paddle/red-circle-lv.png</href>
     </ItemIcon>
    </ListStyle>
   </Style>
  ');
  echo('
   <Style id="hfw">
    <IconStyle>
     <color>ffffffff</color>
     <scale>0.5</scale>
     <Icon>
      <href>http://maps.google.com/mapfiles/kml/paddle/wht-blank.png</href>
     </Icon>
     <hotSpot x="32" y="1" xunits="pixels" yunits="pixels"/>
    </IconStyle>
    <ListStyle>
     <color>ff0000c0</color>
     <ItemIcon>
      <href>http://maps.google.com/mapfiles/kml/paddle/wht-circle-lv.png</href>
     </ItemIcon>
    </ListStyle>
   </Style>
  ');
  echo('
   <Style id="hfd">
    <IconStyle>
     <color>ffffffff</color>
     <scale>0.5</scale>
     <Icon>
      <href>http://maps.google.com/mapfiles/kml/paddle/red-diamond.png</href>
     </Icon>
     <hotSpot x="32" y="1" xunits="pixels" yunits="pixels"/>
    </IconStyle>
    <ListStyle>
     <color>ffa6a6a6</color>
     <ItemIcon>
      <href>http://maps.google.com/mapfiles/kml/paddle/red-diamond-lv.png</href>
     </ItemIcon>
    </ListStyle>
   </Style>
  ');

  foreach($mat as $row) { 
    $age = "";

    $datediff = time() - floatVal($row[$ihdr['mtime']]);
    if( $datediff < 18000 ) { 
      $styleUrl = 'hfg';
    } else if( $datediff < 36000 ) { 
      $styleUrl = 'hfy';
    } else if( $datediff < 2592000 ) { 
      $styleUrl = 'hfr';
    } else { 
      $styleUrl = 'hfw';
    }
    if( $diffdays = floor($datediff/86400) ){
      $datediff = $datediff % 86400;
      $age = $diffdays . " days ";
    }
    if( $diffhours = floor( $datediff/3600) ){
      $datediff = $datediff % 3600;
    }
    if( $diffminutes = floor( $datediff/60) ){
      $datediff = $datediff % 60;
      $diffseconds = $datediff;
    }
    $age .= $diffhours.":".$diffminutes;

    $lat = $row[$ihdr['lat']];
    $lon = $row[$ihdr['lon']];
    $latdm = sprintf("%s %d %.4f",($lat<0) ? 'S' : 'N', abs($lat), (abs($lat) - (int)abs($lat))*60);
    $londm = sprintf("%s %d %.4f",($lon<0) ? 'W' : 'E', abs($lon), (abs($lon) - (int)abs($lon))*60);
    $pattern = ($row[$ihdr['beam']]=='i') ? 'Idealized' : 'Measured';
    $name = sprintf("%s (%s)",$row[$ihdr['name']],$row[$ihdr['sta']]);

    $stats = '';
    if( isset( $url_comp["p"] ) ){
      switch ($url_comp["p"]){
        case "pw":
          $stats = '.palau';
          break;
        case "vn":
          $stats = '.vietnam';
          break;
        default:
          $stats = '';
          break;
      }
    }
    if( $styleUrl == 'hfw' ){
      // There's no radial file for this site
      $desc = sprintf(
        "<div>Station ID: %s<br/>Affiliation: %s<br/>".
        "Coords: %0.4f, %0.4f<br/>%s, %s<br/>".
        "<img src=\"http://cordc.ucsd.edu/imgs/spacer.gif\" width=\"250\"".
        " height=\"16\" alt=\"\"/><br/>".
        "No radial files within 10 days<br />".
        "<a href=\"https://hfradar.ioos.us/hfrnet/diagnostics%s/?p=sta&sta=%s&net=%s&t=0\">".
        "Station Diagnostics</a></div>",
        $row[$ihdr['sta']], $row[$ihdr['net']],
        $lat, $lon, $latdm, $londm,$stats,
        $row[$ihdr['sta']], $row[$ihdr['net']]
      );

    }
    else {
      $desc = sprintf(
        "<div>Station ID: %s<br/>Affiliation: %s<br/>".
        "Coords: %0.4f, %0.4f<br/>%s, %s<br/>".
        "<img src=\"http://cordc.ucsd.edu/imgs/spacer.gif\" width=\"250\"".
        " height=\"16\" alt=\"\"/><br/>".
        "Ctr Freq: %.3f MHz<br/>Time: %s<br/>".
        "Age: %s (HH:MM)<br/>Format: %s<br/><a ".
        "href=\"https://hfradar.ioos.us/hfrnet/diagnostics%s/?p=sta&sta=%s&net=%s&t=0\">".
        "Station Diagnostics</a></div>",
        $row[$ihdr['sta']], $row[$ihdr['net']],
        $lat, $lon, $latdm, $londm, $row[$ihdr['cfreq']],
        gmdate("Y-m-d H:i:s",floatVal($row[$ihdr['mtime']]))." UTC",
        $age, $row[$ihdr['format']],$stats,
        $row[$ihdr['sta']], $row[$ihdr['net']]
      );
    }
    echo('
<Placemark>
  <name>'.htmlentities($name).'</name>
  <description><![CDATA['.$desc.']]></description>
  <styleUrl>#'.$styleUrl.'</styleUrl>
  <Point>
    <altitudeMode>clampToGround</altitudeMode> 
    <coordinates>'.$row[$ihdr['lon']].','.$row[$ihdr['lat']].',0</coordinates>
  </Point>
</Placemark>
    ');
  }
  echo('</Document>');
  echo('</kml>');

  $dat = ob_get_contents();
  ob_end_clean();
  return $dat;
} // END: function getStationKml($hdr,&$mat)


function isFloat($str) { 
  $pat = '/^\s*[+-]?(\d+\.?\d*|\d*\.\d+)(?:[eE][+-]?\d+)?\s*$/';
  return (is_float($str) || is_int($str) || preg_match($pat,$str));
} // END: function isFloat($str)


function getStationJSON($hdr,&$mat) { 
  $parts = array();
  foreach ($mat as $row) { 
    $txts = array();
    foreach (array_keys($hdr) as $i) { 
      if (isFloat($row[$i])) { 
        $txts[] = '  '.$hdr[$i].': '.$row[$i];
      } else { 
        $txts[] = '  '.$hdr[$i].': "'.$row[$i].'"';
      }
    }
    $parts[] = " {\n".implode(",\n",$txts)."\n }";
  }
  return "[\n".join(",\n",$parts)."\n]";
} // END: function getStationJSON($hdr,&$mat)


function makeHash(&$a,$kz,$vz,$cmpcb=null,$j='-') { 
  if( !is_array($a) ) { 
    return $a;
  }
  if( !is_array($kz) ) { 
    $kz = array($kz);
  }
  $retarr = true;
  if( !is_array($vz) ) { 
    $retarr = false;
    $vz = array($vz);
  }
  $h = array();
  foreach($a as $r) { 
    $k = array();
    foreach($kz as $i) { 
      $k[] = $r[$i];
    }
    $v = array();
    foreach($vz as $i) { 
      $v[] = $r[$i];
    }
    $k = implode($j,$k);
    $v = ($retarr) ? $v : implode($j,$v);
    if($cmpcb==null || !isset($h[$k]) || call_user_func($cmpcb,$h[$k],$v)<=0) {
      $h[$k] = $v;
    }
  }
  return $h;
} // END: function makeHash($a,$kz,$vz,$cmpcb=null,$j='-')



function cmp_cb($prev,$next) { 
  foreach( array_keys($prev) as $i ) { 
    if( $prev[$i] < $next[$i] ) { 
      return -1;
    }
    if( $prev[$i] > $next[$i] ) { 
      return +1;
    }
  }
  return 0;
} // END: function cmp_cb($prev,$next)

function cachePath() { 
  global $url_comp;
  $dir= __DIR__;
  $file=substr(basename(__FILE__),0,-4);

  if( isset( $url_comp["p"] ) ){
    switch ($url_comp["p"]){
      case "pw":
        $file .= '.pw';
        break;
      case "vn":
        $file .= '.vn';
        break;
      default:
        $file .= '';
        break;
    }
  }
  return $dir."/kml/".$file.".kml";
}

function cacheIsFresh($timeout=300) { 
  $cpath = cachePath();
  return ( file_exists($cpath) && (filemtime($cpath)+$timeout) > time() );
} // END: function cacheIsFresh($timeout=600)

function  cacheEmit() { 
  $cpath = cachePath();
  $mtime = filemtime($cpath);
  header("Content-Type: application/vnd.google-earth.kml+xml; charset=utf-8");
  $fname = basename($cpath);
  header("Content-Disposition: inline; filename={$fname}");
  $altloc = substr($cpath,strlen('/var/www/html'));
  header("Content-Location: {$altloc}");
  $lastmod = date("r",$mtime);
  header("Last-Modified: {$lastmod}");
  $expires = date("r",$mtime+600);
  //header("Expires: {$expires}");
  header("Expires: 0");

  readfile($cpath);
} // END: function cacheEmit();

function cacheStore(&$dat) { 
  return Filesystem::file_put_contents(cachePath(),$dat);
}

function getRequest($k,$dft=null) { 
  if (isset($_REQUEST[$k])) {
    $val = trim($_REQUEST[$k]);
    if (strlen($val) > 0) { 
      return ((string)floatVal($val)===trim($val)) ? floatVal($val) : $val;
    }
  }
  return $dft;
} // END: function getRequest($k,$dft=null)


function main() { 
  if( cacheIsFresh() && !isset($_REQUEST["force"]) ) { 
    cacheEmit();
    exit(0);
  }

  global $config;
  global $url_comp;

  $db = new Db();
  $db->set_host($config["server"],$config["port"]);
  $db->set_user($config["user"],$config["password"]);
  $db->set_database($config["db"]);

  $sql = "SELECT 
  distinct s.sta,s.staname,n.net,n.netname,rf.lat,rf.lon,rf.cfreq,rf.time,rf.mtime,rf.patterntype,rf.format
FROM
  site s JOIN network n ON s.network_id=n.network_id JOIN
  (SELECT rf.site_id,rf.network_id,rf.lat,rf.lon,rf.time,rf.cfreq,rf.format, any_value(rf.patterntype) as patterntype ,max(rf.mtime) as mtime
   FROM 
   (select site_id,network_id,max(time) as time from latest_radialfiles group by site_id,network_id ) lrf left join 
   radialfiles rf on 
  lrf.site_id = rf.site_id and 
  lrf.network_id = rf.network_id and 
  lrf.time = rf.time 
   GROUP BY rf.site_id,rf.network_id,rf.lat,rf.lon,rf.time,rf.cfreq,rf.format) rf on
   s.site_id = rf.site_id and s.network_id = rf.network_id
WHERE 
  s.decommissioned=0"; 

  if( isset($url_comp["p"])){
    switch ($url_comp["p"]){
      case "pw":
        $sql .= " AND net='SIO'";
        break;
      case "vn":
        $sql .= " AND net='VASI'";
        break;
      default:
        break;
    }
  }
  $sql .= " ORDER BY n.net,s.sta";

  if( !$db->query($sql) ) { 
    error_log("Couldn't query database.");
    exit(1);
  }

  $mat = array();
  $hdr = array('sta','name','net','aff','lat','lon',
    'cfreq','time','mtime','beam','format');

  $mat = $db->get_results();

  if( strtolower(getRequest("fmt",null)) === "json" ) { 
    $fname = basename(__FILE__,'.php').'.json';
    header("Content-Type: text/plain; filename=$fname");
    echo(getStationJSON($hdr,$mat));
    exit;
  }

  $dat = getStationKml($hdr,$mat);
  cacheStore($dat);
  cacheEmit();
} // END: function main()


main();

// EOF -- stasta.php
?>
