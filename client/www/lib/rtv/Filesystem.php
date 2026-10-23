<?php
 /**
  * Filename: Filesystem.php
  * Description: Contains an object that can perform basic
  *  Linux filesystem functions such as mkdir and rmdir,
  *  directory listings, etc.
  * Last Modified: 2009-12-01 15:41 HST
  * Author: Paul Reuter
  * Version: 1.1.5
  * 
  * Modifications: 
  *  0.0.9 - 2007-05-17 - Created: File was created, but not completed
  *  1.0.0 - 2007-05-18 - Release: Initial version
  *  1.0.1 - 2008-11-13 - Remove: some features
  *  1.0.2 - 2008-12-19 - Add: Dependency on DirectoryListing object.
  *  1.0.3 - 2009-02-13 - Add: file_put_contents($fpath,&$dat)
  *  1.0.4 - 2009-02-13 - Remove: dependency on DirectoryListing.
  *  1.0.5 - 2009-03-02 - Add: static append method; forced binary.
  *  1.0.6 - 2009-03-26 - BugFix: DIRECTORY_SEPARATOR, file_put_contents
  *  1.0.7 - 2009-04-03 - Optimize: Order of magnitude speed-up of mkdir.
  *  1.0.8 - 2009-04-15 - Add: getPathSafe(), getFileSafe() functions.
  *  1.0.9 - 2009-04-21 - Clean the path before mkdir, rmdir.
  *  1.1.0 - 2009-04-22 - BugFix: str_replace on a directory with a valid ':'
  *  1.1.1 - 2009-05-05 - Add: directory mode on file_put_contents, append
  *  1.1.2 - 2009-06-17 - Add: copy($src,$dst), unlink for files and dirs.
  *  1.1.3 - 2009-10-14 - Add: rename($src,$dst).
  *  1.1.4 - 2009-10-21 - BugFix: Fixed race condition (!is_dir && !mkdir).
  *  1.1.5 - 2009-12-01 - BugFix: copy(sfile,dpath) if dpath is dir, do cp.
  */


// Required includes:
// [none]


if( !defined("FILESYSTEM") ) { 
  DEFINE("FILESYSTEM",true);

  DEFINE("SORT_ASC" ,   1 );
  DEFINE("SORT_DESC" , -1 );
  DEFINE("SORT_NONE" ,  0 );
}


if( !defined("DIRECTORY_SEPARATOR") ) { 
  define("DIRECTORY_SEPARATOR","/");
}



/**
 * An object that extends basic file-level operations, making recursive
 *  operations more intuitive.
 */
class Filesystem {

  function Filesystem() { 
    return $this;
  }

  /*
   * Function: parentDir(path)
   * Returns the path of the parent directory
   *   to the parameter supplied.
   */
  static function parentDir($dpath) {
    $dpath = str_replace(DIRECTORY_SEPARATOR,'/',$dpath);
    $dpath = rtrim($dpath,'/');
    $pts = explode('/',$dpath);
    array_pop($pts);
    $dpath = implode(DIRECTORY_SEPARATOR,$pts);
    return $dpath;
  }


  /*
   * Tests whether a directory is empty or not.
   */
  static function isEmpty($dpath) {
    if(!file_exists($dpath) || !is_dir($dpath) || !is_readable($dpath) ) {
      return false;
    }

    $dp = opendir($dpath);
    if(!$dp) {
      return false;
    }

    while( ($fname = readdir($dp)) !== false ) {
      if($fname == '.' || $fname == '..') {
        continue;
      }
      // not self or parent; therefore, directory isn't empty.
      closedir($dp);
      return false;
    }

    closedir($dp);
    return true;
  }


  /*
   * Function: mkdir(dir,permissions)
   * Purpose: Iteratively create the full
   *   directory path specified by dir.
   */
  static function mkdir($dpath,$mode=0755,$prune=0) {
    // Clean up the string, normalize path separator.
    $dpath = trim($dpath);
    $dpath = str_replace(array("\\","/",DIRECTORY_SEPARATOR),'/',$dpath);

    // Remove any dangling path components
    $dparts = null;
    if( $prune > 0 ) {
      $dparts = explode('/',$dpath);
      if( !is_array($dparts) ) {
        error_log("ERROR: mkdir failed because explode failed.");
        return false;
      }

      // skip bottom N directories specified by $prune.
      $np = count($dparts);
      if( $np < $prune ) { 
        error_log("ERROR: mkdir path supplied is invalid ($dpath)");
        return false;
      }
      array_splice($dparts,$np-$prune,$prune);

      // update intended path, skipping pruned directories.
      $dpath = implode(DIRECTORY_SEPARATOR,$dparts);
    }
    $dpath = (strlen($dpath)==0) ? '.' : $dpath;

    // Make the directory as requested
    if( !is_dir($dpath) ) {
      if( is_null($dparts) ) { 
        $dparts = explode(DIRECTORY_SEPARATOR,$dpath);
      }
      $mpath = '';
      foreach($dparts as $part) {
        $mpath .= $part.DIRECTORY_SEPARATOR;
        /**
         * Prior to change, statement went:
         *   if( !is_dir($mpath) && !mkdir($mpath,$mode) ) { ... }
         *
         * This setup allowed a race condition between the two calls.
         *   Since file system operations are notoriously slow, there was
         *   a high likelihood of conflict.  By ignoring mkdir warnings and
         *   subsequently testing for directory existence, we ensure
         *   that the directory exists after the calls without warnings if
         *   the directory existed before.
         *
         * @since 2009-10-21
         */
        if( !@mkdir($mpath,$mode) && !is_dir($mpath) ) { 
          return false;
        }
      }
    }

    return true;
  } // END: function mkdir($dpath,$mode=0775,$prune=0);


  /*
   * Function: rmdir(dpath)
   * Purpose: Recursivly delete directories from base-dir
   */
  static function rmdir($dpath) {
    // Clean up the string, normalize path separator.
    $dpath = trim($dpath);
    $dpath = str_replace(array("\\","/",DIRECTORY_SEPARATOR),'/',$dpath);

    if( !is_dir($dpath) ) { 
      return true;
    }

    // Recursively remove sub-directories
    $dirs = Filesystem::getDirectoryListing($dpath,true,true,false);
    foreach($dirs as $spath) { 
      if( !Filesystem::rmdir($spath) ) { 
        error_log("Couldn't rmdir: ".$spath);
        return false;
      }
    }
    // Remove files in given directory.
    $files = Filesystem::getDirectoryListing($dpath,true,false,true);
    foreach($files as $fpath) { 
      if( !unlink($fpath) ) { 
        // error_log("Couldn't remove file: $fpath");
        return false;
      }
    }
    // Remove given directory
    return rmdir($dpath);
  }


  /**
   * Ensure that $dst directory exists, or is capable of existing.
   */
  static function copy($srcpath,$dstpath) {
    if( is_dir($srcpath) ) { 
      // Clean up the string, normalize path separator.
      $srcpath = trim($srcpath);
      $srcpath = str_replace(array("\\","/",DIRECTORY_SEPARATOR),'/',$srcpath);

      // Clean up the string, normalize path separator.
      $dstpath = trim($dstpath);
      $dstpath = str_replace(array("\\","/",DIRECTORY_SEPARATOR),'/',$dstpath);

      // store the full path to files (not dirs) stored at dpath.
      $dp = opendir($srcpath);
      if( !$dp ) {
        // error_Log("couldn't open directory: ".$dpath);
        return false;
      }
      while( ($fname = readdir($dp)) !== false ) {
        if( $fname == "." || $fname == ".." ) {
          continue;
        }
        if( !Filesystem::copy($srcpath.'/'.$fname,$dstpath.'/'.$fname) ) { 
          return false;
        }
      }
      // NB: closedir returns void
      closedir($dp);
      return true;
    }
    if( is_dir($dstpath) ) { 
      $dstpath = $dstpath.DIRECTORY_SEPARATOR.basename($srcpath);
    } else  if( !Filesystem::mkdir($dstpath,0775,1) ) { 
      return false;
    }
    return copy($srcpath,$dstpath);
  } // END: function copy($srcpath,$dstpath)


  /**
   * Performs a Filesystem::copy($srcpath,$dstpath) followed by 
   * a Filesystem::unlink($srcpath).
   *
   * Reasoning: rename does not work across disk partitions in some cases.
   *
   * @access public
   * @static
   * @param string $srcpath A file or directory source.
   * @param string $dstpath A file or directory destination.
   * @return bool success or failure.
   */
  static function rename($srcpath,$dstpath) { 
    return (
         Filesystem::copy($srcpath,$dstpath) 
      && Filesystem::unlink($srcpath)
    );
  } // END: function rename($srcpath,$dstpath)


  /**
   * Remove the file or directory from the file system.
   */
  static function unlink($src) { 
    if( is_file($src) ) { 
      return unlink($src);
    }
    if( is_dir($src) ) { 
      return Filesystem::rmdir($src);
    }
    return false;
  } // END: function unlink($src)



  /**
   * Reads an existing file, returns the contents.
   *
   * @public
   * @param {string} $fpath A file to read from the local file system.
   * @return {mixed} file contents or false if file not found.
   */
  static function file_get_contents($fpath) {
    if( !file_exists($fpath) ) { 
      return false;
    }
    return file_get_contents($fpath);
  } // END: function file_get_contents($fpath)


  /**
   * Writes data to a file with directory creation test.
   *
   * @public
   * @param {string} $fpath Path to file where we'll write to.
   * @param {string} $dat Data to be written, we don't care.
   * @param {string} $mode [default=wb], used internally for appending.
   * @param {int} $dmode [default=0775], Permissions on the directory.
   * @return {boolean} success or failure.
   */
  static function file_put_contents($fpath,$dat,$mode="wb",$dmode=0775) {
    $fpath = str_replace(array("\\","/"),DIRECTORY_SEPARATOR,$fpath);

    if( !Filesystem::mkdir($fpath,$dmode,1) ) {
      error_log("couldn't make directory: ".dirname($fpath));
      return false;
    }

    $fp = fopen($fpath,$mode);
    if( !$fp ) {
      error_log("couldn't open file for writing ($mode): ".$fpath);
      return false;
    }
    $len = strlen($dat);
    if( $len > fwrite($fp,$dat,$len) ) {
      error_log("couldn't write $len bytes");
      fclose($fp);
      return false;
    }
    return fclose($fp);
  } // END: function file_put_contents($fpath,$dat,$mode="wb",$dmode=0775)



  /**
   * Writes data to the end of a file, if file not found, creates.
   *
   * @public
   * @param {string} $fpath Path to file where we'll write to.
   * @param {string} $dat Data to be written, we don't care.
   * @param {int} $dmode Permissions on rhe directory.
   * @return {boolean} success or failure.
   */
  static function append($fpath,&$dat,$dmode=0775) {
    return Filesystem::file_put_contents($fpath,$dat,"ab",$dmode);
  } // END: function append($fpath,&$dat)


  /**
   * Return a list of files and/or directories contained in $dpath.
   *
   * @public
   * @param {string} $dpath Directory to serarch.
   * @param {boolean} $fullpath Return the full path of the file.
   * @param {boolean} $incldirs Include directories contained in $dpath.
   * @param {boolean} $inclfiles Include files contained in $dpath.
   * @param {string} $match Preg expression, including slashes, to match
   *  the file name against.
   * @return {array} List of files and directories contained within $dpath,
   *  matching $match.
   */
  static function getDirectoryListing(
  $dpath, $fullpath=true, $incldirs=true, $inclfiles=true, $match=null ) {

    if( !is_dir($dpath) || !is_readable($dpath) ) {
      return false;
    }

    $dpath = str_replace(DIRECTORY_SEPARATOR,'/',$dpath);
    $dpath = rtrim(rtrim($dpath),'/');
    $files = array();

    // store the full path to files (not dirs) stored at dpath.
    $dp = opendir($dpath);
    if( !$dp ) {
      // error_Log("couldn't open directory: ".$dpath);
      return false;
    }
    while( ($fname = readdir($dp)) !== false ) {
      if( $fname == "." || $fname == ".." ) {
        continue;
      }
      $fpath = $dpath.'/'.$fname;
      $sfile = ($fullpath) ? $fpath : $fname;
      if( is_file($fpath) && $inclfiles ) {
        array_push($files,$sfile);
      } else if (is_dir($fpath) && $incldirs ) {
        array_push($files,$sfile);
      }
    }
    closedir($dp);

    return $files;
  } // END: function m_getDirectoryListing($dpath)



  static function microtime_float() {
    list($usec, $sec) = explode(" ", microtime());
    return ((float)$usec + (float)$sec);
  }

  /**
   * Return a string safe to use in a file path.
   *
   * @public
   * @static
   * @param {string} $str A portion of text that will be used in a file path.
   * @return {string} A safe for storing & addressing file path part.
   */
  static function getPathSafe($str) { 
    return preg_replace("/(?:^\.*|[^a-zA-Z0-9\-\.\,_\ \'\&\"])/","",$str);
  } // END: function getPathSafe($str)

  /**
   * Return a string safe to use in a file name.
   *
   * @public
   * @static
   * @param {string} $str A file name component, does not have directories.
   * @return {string} A file-name safe string.
   */
  static function getFileSafe($str) { 
    return preg_replace("/(?:^\.*|[\/\\\r\n\t])/","",$str);
  } // END: function getFileSafe($str)

} // END: class Filesystem
?>
