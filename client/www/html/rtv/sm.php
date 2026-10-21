<?php
 /**
  * Generate a site metadata as a json document.
  *
  * @file sitemeta.php
  * @date 2020-06-25 15:30 PDT
  * @author Paul Reuter / Joseph Chen
  * @version 1.3.1
  *
  * @modifications
  * 1.0.0 - 2010-02-12 - Created from stasta.php
  * 1.1.0 - 2010-04-14 - Changed structure for practicality.
  * 1.2.0 - 2014-10-01 - Changed to use Antelope+MySQL
  * 1.3.0 - 2016-11-04 - Changed to use new db on cs2
  * 1.3.1 - 2020-06-25 - Changed getCachePath to use directory cache.  Removed next_records()
  */


// Load ini file:
ini_set("memory_limit","400M");
$config_all = parse_ini_file('/var/www/lib/diagnostics/hfrnet_db.ini',true);
$config = isset($config_all['hfradar']) ? $config_all['hfradar'] : $config_all;

// Required includes:
require_once('/var/www/lib/rtv/Db.php');
require_once('/var/www/lib/rtv/Filesystem.php');
require_once('/var/www/lib/rtv/Services_JSON.php');


function makeHash(&$a,$kz,$vz=null,$cmpcb=null,$j='-') { 
  if( !is_array($a) ) { 
    return $a;
  }
  if( !is_array($kz) ) { 
    $kz = array($kz);
  }
  $retarr = true;
  if( !is_array($vz) && $vz!==null ) { 
    $retarr = false;
    $vz = array($vz);
  }
  $h = array();
  foreach($a as $r) { 
    $k = array();
    foreach($kz as $i) { 
      $k[] = $r[$i];
    }
    if( $vz===null ) { 
      $v = $r;
    } else { 
      $v = array();
      foreach($vz as $i) { 
        $v[] = $r[$i];
      }
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
  return dirname(__FILE__)."/cache/".basename(__FILE__,".php").".json";
}


function cacheIsFresh($timeout=600) { 
  $cpath = cachePath();
  return ( file_exists($cpath) && (filemtime($cpath)+$timeout) > time() );
} // END: function cacheIsFresh($timeout=600)


function  cacheEmit() { 
  $cpath = cachePath();
  $mtime = filemtime($cpath);
  header("Content-Type: text/javascript charset=utf-8");
  $fname = basename($cpath);
  header("Content-Disposition: inline; filename={$fname}");
  $altloc = substr($cpath,strlen('/var/www/html'));
  header("Content-Location: {$altloc}");
  $lastmod = date("r",$mtime);
  header("Last-Modified: {$lastmod}");
  $expires = date("r",$mtime+600);
  header("Expires: {$expires}");

  header("Content-Type: text/javascript");
  if( isset($_REQUEST["callback"]) ) {
    echo($_REQUEST["callback"]."(".file_get_contents($cpath).");");
  } else {
    readfile($cpath);
  }
  exit(0);
} // END: function cacheEmit();


function cacheStore(&$dat) { 
  return Filesystem::file_put_contents(cachePath(),$dat);
} // END: function cacaheStore(&$dat)


function main() { 
  if( cacheIsFresh() && !isset($_REQUEST["force"]) ) { 
    cacheEmit();
    exit(0);
  }

  global $config;

  $db = new Db();
  $db->set_host($config["server"],$config["port"]);
  $db->set_user($config["user"],$config["password"]);
  $db->set_database($config["db"]);


  // Initialize returned object.
  $json_obj = array(
    "meta" => array(
      "keys" => array(
        "stations" => array(
          "net" => "unique network identifier",
          "sta" => "character-code name for a radar installation or ".
                     "processing center",
          "lat" => "estimated latitude",
          "lon" => "estimated longitude",
          "staname" => "full name for a radar installation",
          "time" => "time",
          "endtime" => "end of a time range",
          "mtime" => "latest radfile mod time."
        ),
        "networks" => array(
          "net" => "unique network identifier",
          "netname" => "network name"
        ),
        "status"   => array(
          "nSites" => "Number of Sites",
          "nNets"  => "Number of Networks",
          "latest" => "Time Latest File Received"
        )
      ),
      "cached" => time()
    ),
    "data" => array(
      "stations" => array(),
      "networks" => array(),
      "status" => array(
        "latest" => null
      ),
    )
  );

  if( $db->query('SELECT net, netname FROM network ORDER BY net') ) { 
    $json_obj["data"]["networks"] = $db->get_results();
  }

  $sql = "SELECT 
  distinct n.net,s.sta,rf.lat,rf.lon,s.staname,rf.time
FROM
  site s JOIN network n ON s.network_id=n.network_id JOIN
  (SELECT distinct rf.site_id,rf.network_id,rf.lat,rf.lon,rf.time
   FROM 
   (select site_id,network_id,max(time) as time from latest_radialfiles group by site_id,network_id ) lrf left join 
   radialfiles rf on 
  lrf.site_id = rf.site_id and 
  lrf.network_id = rf.network_id and 
  lrf.time = rf.time ) rf on
   s.site_id = rf.site_id and s.network_id = rf.network_id
WHERE 
  s.decommissioned=0
ORDER BY n.net,s.sta";
  if( $db->query($sql) ) { 
    $json_obj["data"]["stations"] = $db->get_results();
  }

  $sql = 'SELECT MAX(time) FROM latest_radialfiles';
  if( $db->query($sql) ) { 
    $mtime = intVal($db->get_results()[0][0]);
    $json_obj["data"]["status"]["latest"] = $mtime;
  }
  $sql = 'SELECT COUNT(DISTINCT sta) FROM site';
  if( $db->query($sql) ) { 
    $nsta = intVal($db->get_results()[0][0]);
    $json_obj["data"]["status"]["nSites"] = $nsta;
  }
  $sql = 'SELECT COUNT(DISTINCT net) FROM network';
  if( $db->query($sql) ) { 
    $nnet = intVal($db->get_results()[0][0]);
    $json_obj["data"]["status"]["nNets"] = $nnet;
  }

  $json = new Services_JSON();
  $dat = $json->encode($json_obj);
  unset($json_obj);
  unset($json);

  cacheStore($dat);
  unset($dat);
  cacheEmit();
} // END: function main()


main();

// EOF -- sitemeta.php
?>
