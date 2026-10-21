<?php
 /**
  * Common functions used frequently.
  *
  * @file common.inc.php
  * @date 2009-12-11 11:02 HST
  * @author Paul Reuter
  * @version 1.0.4
  *
  * @modifications
  * 1.0.0 - 2009-10-28 - Created as common utility functions (include)
  * 1.0.1 - 2009-10-28 - Added $_SERVER["SCRIPT_FILENAME"] constant.
  * 1.0.2 - 2009-12-08 - Added getHostname($fqdn=true)
  * 1.0.3 - 2009-12-11 - Added debug($msg) method; getHostname fallback.
  * 1.0.4 - 2009-12-11 - Added getModifiedFiles(dpath,t0,t1) support.
  */


// Required includes: 
// DirectoryListing.php -- for getModifiedFiles($dpath,$t0=null)
// Filesystem.php -- for file_put_contents


if( !isset($_SERVER["SCRIPT_FILENAME"]) ) {
  // SCRIPT_FILENAME is created in an Apache environment.
  $_SERVER["SCRIPT_FILENAME"] = getcwd().DIRECTORY_SEPARATOR.$argv[0];
}


if( !defined("PHP_EOL" ) ) { 
  // For backwards compatability
  define("PHP_EOL","\n");
}


function setLastExecutionTime($ts) {
  $fpath = substr($_SERVER["SCRIPT_FILENAME"],0,-4).'.status';
  return Filesystem::file_put_contents($fpath,$ts);
} // END: function setLastExecutionTime($ts)



function getLastExecutionTime() {
  $fpath = substr($_SERVER["SCRIPT_FILENAME"],0,-4).'.status';
  if( !file_exists($fpath) ) {
    return false;
  }
  return intVal(file_get_contents($fpath));
} // END: function getLastExecutionTime()


function getHostname($fqdn=true) { 
  if( $fqdn ) { 
    return gethostbyaddr('127.0.0.1');
  }
  if( isset($_ENV["HOSTNAME"]) ) { 
    return $_ENV["HOSTNAME"];
  }
  return false;
} // END: function getHostname($fqdn=true)


function debug($msg) { 
  if( defined("DEBUG") && DEBUG ) { 
    return enotice("DEBUG: $msg");
  }
} // END: function debug($msg)


function enotice($msg) {
  return notice($msg,true);
} // END: function enotice($msg)


function notice($msg,$isError=false) {
  if( $isError ) {
    error_log(
      sprintf("%s - %s",gmdate("Y-m-d H:i:s"),rtrim($msg))
    );
  } else {
    printf("%s - %s%s",gmdate("Y-m-d H:i:s"),rtrim($msg),PHP_EOL);
 }
 return true;
} // END: function notice($msg,$isError=false)



function getModifiedFiles($dpath,$t0=null,$t1=null) {
  $dl = new DirectoryListing($dpath);
  $dl->setRecursive(true);
  $dl->setIncludeFiles(true);
  $dl->setIncludeDirectories(false);
  $dl->setUseFullPath(true);

  $files = $dl->getListing();
  if( $t0===null && $t1===null ) {
    return $files;
  }

  $keepers = array();
  foreach($files as $fpath) {
    $mtime = filemtime($fpath);
    if( ($t0===null || $t0 <= $mtime)
    &&  ($t1===null || $mtime <= $t1) ) {
      $keepers[] = $fpath;
    }
  }
  return $keepers;
} // END: function getModifiedFiles($dpath,$t0=null)


// EOF -- common.inc.php
?>
