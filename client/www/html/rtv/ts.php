<?php
 /**
  * Return JSON tile status.
  *
  * @file ts.php
  * @date 2020-06-25 10:26 PST
  * @author Paul Reuter/Joseph Chen
  * @version 2.1.3
  *
  * @modifications
  * 1.0.0 - Original XML version
  * 2.0.0 - Original JSON version
  * 2.1.0 - 2009-12-11 - Updated for hfrnet, serving from db.
  * 2.1.1 - 2009-12-14 - Stopped using titles, using actual resolution,prefix.
  * 2.1.2 - 2010-04-14 - BugFix: expected, missing SQL bug.
  * 2.1.3 - 2020-06-25 - Changed getCachePath to use directory cache
  */

define("LIB_DIR","/var/www/lib");
define("CONFIG_FILE",LIB_DIR."/diagnostics/hfrnet_db.ini");

require_once(LIB_DIR."/rtv/Db.php");
require_once(LIB_DIR."/rtv/Filesystem.php");
require_once(LIB_DIR."/rtv/Services_JSON.php");

function getSql() { 
  return "SELECT abc.*,
  ROUND((latest-earliest)/3600)+1 AS expected,
  ROUND((latest-earliest)/3600)+1-count AS missing
FROM (

SELECT DISTINCT 
  p.prefix AS prefix, 
  r.resolution AS resolution, 
  UNIX_TIMESTAMP(MIN(s.timestamp)) AS earliest,
  UNIX_TIMESTAMP(MAX(s.timestamp)) AS latest,
  COUNT(s.timestamp) AS count
FROM status AS s, prefix AS p, resolve AS r 
WHERE s.resId=r.id AND s.pfxId=p.id 
GROUP BY pfxId, resId

) AS abc";
} // END: function getSql()


function isValidCache($fpath=null,$dt=60) { 
  $fpath = ($fpath===null) ? getCachePath() : $fpath;
  return ( file_exists($fpath) && filemtime($fpath) > time()-$dt );
}
function getCachePath() { 
  return dirname(__FILE__)."/cache/".basename(__FILE__,".php").".json";
  //return substr(__FILE__,0,-4).".json";
}
function getCacheContents($fpath=null) { 
  $fpath = ($fpath===null) ? getCachePath() : $fpath;
  return Filesystem::file_get_contents($fpath);
}
function setCacheContents(&$dat,$fpath=null) { 
  $fpath = ($fpath===null) ? getCachePath() : $fpath;
  return Filesystem::file_put_contents($fpath,$dat);
}

function main() { 
  if( isValidCache() ) { 
    $json = getCacheContents();
  } else { 
    $config = parse_ini_file(CONFIG_FILE,true);
    $dbinfo = isset($config['hfradar']) ? $config['hfradar'] : $config;

    $db = new Db();
    $db->set_host($dbinfo["server"],$dbinfo["port"]);
    $db->set_user($dbinfo["user"],$dbinfo["password"]);
    $db->set_database($dbinfo["db"]);
    $db->set_associated();
    if( !$db->query(getSql()) ) { 
      return false;
    }
    $res = $db->get_results();
    $svc = new Services_JSON();
    $json = $svc->encode($res);
    setCacheContents($json);
  }
  header("Content-Type: text/javascript");
  if( isset($_REQUEST["callback"]) ) { 
    echo($_REQUEST["callback"]."(".$json.");");
  } else { 
    echo($json);
  }
  exit(0);
} // END: function main()


main();

// EOF -- ts.php
?>
