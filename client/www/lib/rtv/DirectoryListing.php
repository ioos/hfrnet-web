<?php
 /**
  * Object for listing the contents of directories.
  *
  * @file    DirectoryListing.php
  * @date    2009-12-11 10:52 HST
  * @author  Paul Reuter
  * @version 1.1.0
  *
  * Modifications:
  * 1.0.0 - 2008-12-19 - Created
  * 1.0.1 - 2009-01-06 - Added setFilter/clearFilter methods.
  * 1.0.2 - 2009-02-05 - Logic bug (is_link/is_dir/is_file) :: 2 out of 3.
  * 1.0.3 - 2009-03-02 - Removed clone() function for compatibility with Win.
  * 1.0.4 - 2009-03-30 - Implemented a 3-part versioning system.
  * 1.0.5 - 2009-04-08 - Renamed protected property match to filter.
  * 1.0.5 - 2009-04-08 - Assigned values in class definition, not constructor.
  * 1.0.6 - 2009-04-08 - Added a file return limit to stop-out early.
  * 1.0.7 - 2009-04-11 - BugFix: When comparing limit, returned 1 too many.
  * 1.0.8 - 2009-04-21 - BugFix: Now doing str_replace([/\:],'/',dpath)
  * 1.0.9 - 2009-06-26 - BugFix: Removed the colon from str_replace (Win32).
  * 1.1.0 - 2009-12-11 - Added start offset for setLimit(len,start=0)
  */


/**
 * Object for listing the contents of a directory with options and filters.
 * Not as robust as a `find`, but quite handy.
 */
class DirectoryListing {
  var $dpath  = null;       // Directory path to search
  var $filter = null;       // Preg expression to test against
  var $limit  = array(0,0); // int: number of files to stop and return at.
  var $bFullpath   = true;  // Bool: Return the full path of the file
  var $bIncldirs   = true;  // Bool: Include directories in $dpath
  var $bInclfiles  = true;  // Bool: Include files in $dpath
  var $bIncllinks  = false; // Bool: Include links in list.
  var $bInclhidden = false; // Bool: Include hidden files (dirs) in $dpath
  var $bRecursive  = false; // Bool: Return a recursive listing
  var $bRealpath   = false; // Bool: Convert relative paths to absolute paths.

  // ----------------------------------------------------------------------//
  // --- CONSTRUCTOR BELOW ------------------------------------------------//
  // ----------------------------------------------------------------------//

  /**
   * Create a new instance.
   *
   * @public
   * @param {string} $dpath [default=CWD] Create's a new instance, assignes
   *  working directory if specified.
   * @param {string} $filter [default=null] Uses this preg_match pattern to
   *  test for which files will be included.
   * @return {object} New DirectoryListing object.
   */
  function __construct($dpath=null,$filter=null) { 
    $this->dpath = (is_null($dpath)) ? getcwd() : $dpath;
    $this->filter = $filter;
    return $this;
  } // END: constructor __construct($dpath=null,$filter=null)


  // ----------------------------------------------------------------------//
  // --- PUBLIC METHODS BELOW ---------------------------------------------//
  // ----------------------------------------------------------------------//


  /**
   * Sets the number of files to return.  When $limit > 0, the searching will
   *  stop as soon as $limit results are found.  When $limit <= 0, there is
   *  no limit.
   *
   * @public
   * @param int $length Number of results to return.
   * @param int $start Number of matches to skip before adding.
   * @return bool true for success, false if input is invalid.
   */
  function setLimit($length=1,$start=0) {
    if( !is_numeric($length) || $length < 0 ) { 
      return false;
    }
    if( !is_numeric($start) || $start < 0 ) { 
      return false;
    }
    $this->limit = array( intVal($length), intVal($start) );
    return true;
  } // END: function setLimit($length=1,$start=0)


 /**
   * Set active directory path.
   *
   * @public
   * @param {string} $dpath Directory path from which we shall list contents.
   * @return {boolean} Always true.
   */
  function setDirectory($dpath) { 
    $this->dpath = (is_null($dpath)) ? getcwd() : $dpath;
    return true;
  }

  /**
   * Set the preg_match filter to use when searching.
   *
   * @public
   * @param {string} $filter A preg_match compatible regular expression.
   * @return {boolean} Always true.
   */
  function setFilter($filter=null) { 
    $this->filter = $filter;
    return true;
  }

  /**
   * Clears the preg_match filter so no regular expression is used.
   *
   * @public
   * @return {boolean} Always true.
   */
  function clearFilter() { 
    $this->filter = null;
    return true;
  }

  /**
   * Set flag to return full path of files and directories in listing.
   *
   * @public
   * @param {boolean} $b [default=true] Yes or no.
   * @return {boolean} Always true.
   */
  function setUseFullPath($b=true) { 
    $this->bFullpath = ($b) ? true : false;
    return true;
  }

  /**
   * Set flag to include directories in the return results.
   *
   * @public
   * @param {boolean} $b [default=true] Yes or no.
   * @return {boolean} Always true.
   */
  function setIncludeDirectories($b=true) { 
    $this->bIncldirs = ($b) ? true : false;
    return true;
  }

  /**
   * Set flag to include files in the return results.
   *
   * @public
   * @param {boolean} $b [default=true] Yes or no.
   * @return {boolean} Always true.
   */
  function setIncludeFiles($b=true) { 
    $this->bInclfiles = ($b) ? true : false;
    return true;
  }

  /**
   * Set a flag to include hidden files in the return results.  
   * Not including hidden directories will prevent recursive traversal of
   *  hidden directories.
   *
   * @public
   * @param {boolean} $b [default=true] Yes or no.
   * @return {boolean} Always true.
   */
  function setIncludeHidden($b=true) { 
    $this->bInclhidden = ($b) ? true : false;
    return true;
  }

  /**
   * Set flag to include symbolic links in the return results.
   * Symbolic links are never traversed when pointing to a directory.
   *
   * @public
   * @param {boolean} $b [default=true] Yes or no.
   * @return {boolean} Always true.
   */
  function setIncludeLinks($b=true) { 
    $this->bIncllinks = ($b) ? true : false;
    return true;
  }

  /**
   * Set flag to recursively scan sub-directories for more matches.
   *
   * @public
   * @param {boolean} $b [default=true] Yes or no.
   * @return {boolean} Always true.
   */
  function setRecursive($b=true) { 
    $this->bRecursive = ($b) ? true : false;
    return true;
  }

  /**
   * Set flag to convert relative paths to real paths.
   *
   * @public
   * @param {boolean} $b [default=true] Yes or no.
   * @return {boolean} Always true.
   */
  function setRealPath($b=true) { 
    $this->bRealpath = ($b) ? true : false;
    return true;
  }


  /**
   * Performs a directory listing based on currently set flags.
   *
   * @public
   * @param {string} $src Optionally assign a different directory to list.
   * @return {mixed} False if error, array of files and/or directories.
   */
  function getListing($src=null) { 
    $src = (is_null($src)) ? $this->dpath : $src;
    $src = str_replace( array("\\","/"), DIRECTORY_SEPARATOR, $src);
    list($limit,$start) = $this->limit;

    if( $this->bRealpath ) { 
      $src = realpath($src);
    }

    if( is_null($src) ) { 
      error_log("Directory path is null: $src");
      return false;
    }

    if( !is_dir($src) ) { 
      error_log("Directory not found: $src");
      return false;
    }

    if( !is_readable($src) ) { 
      error_log("Directory not readable: $src");
      return false;
    }

    // remove possible trailing slash
    $src = rtrim($src,DIRECTORY_SEPARATOR);
    // Initialize list of directories needed for searching.
    $dirs = array( $src ); 
    // Initialize list of files and directories found.
    $list = array();

    // Continue reading directories until all directories have been read.
    $listCounter = 0;
    while( count($dirs) > 0 ) { 
      // Get the next directory to read.
      $dpath = array_shift($dirs);

      $dp = opendir($dpath);
      if( !$dp ) {
        continue;
      }

      // loop through directory contents
      while( ($fname = readdir($dp)) !== false ) {
        if( $fname == "." || $fname == ".." ) {
          continue;
        }

        // Only include hidden files if requested.
        if( !$this->bInclhidden && $fname[0]=='.' ) { 
          continue;
        }

        // build the full path
        $fpath = $dpath.DIRECTORY_SEPARATOR.$fname;
        // build the stored version (based on settings)
        $sfile = ($this->bFullpath) ? $fpath : $fname;

        if( is_link($fpath) ) { 
        // Only include links if requested.
          if( $this->bIncllinks ) { 
            // include only links that match the pattern.
            if( $this->m_matches($this->filter,$fname) ) { 
              if( $start > 0 ) { 
                $start -= 1;
              } else { 
                $list[] = $sfile;
                $listCounter += 1;
              }
            }
          }

        } else if( is_file($fpath) ) { 
        // Only include files if requested.
          if( $this->bInclfiles ) {
            // include only files that match the pattern.
            if( $this->m_matches($this->filter,$fname) ) { 
              if( $start > 0 ) { 
                $start -= 1;
              } else { 
                $list[] = $sfile;
                $listCounter += 1;
              }
            }
          }

        } else if ( is_dir($fpath) ) { 
        // Directories are a particular case, since they can be traversed.

          // When recursive: add dirs to the list of dirs to search.
          if( $this->bRecursive ) { 
            array_push($dirs,$fpath);
          }
          // Only include directories if requested.
          if( $this->bIncldirs ) {
            if( $this->m_matches($this->filter,$fname) ) { 
              if( $start > 0 ) { 
                $start -= 1;
              } else { 
                $list[] = $sfile;
                $listCounter += 1;
              }
            }
          }

        } // end if(link)/elseif(file)/elseif(dir)

        // Stop if limit reached
        if( $limit > 0 && $listCounter >= $limit ) {
          break;
        }
      } // end: loop through directory contents

      closedir($dp);
       // Stop if limit reached.
      if( $limit > 0 && $listCounter >= $limit ) {
        break;
      }
    } // end while ( cdirs not empty )

    return $list;
  } // END: function list($dpath=null)


  /**
   * Copies all attributes of another object to the current object.
   *
   * @public
   * @param {object} $that The object being copied from.
   * @return {boolean} success or failure.
   */
  function copy(&$that) { 
    if( strcasecmp(get_class($this),get_class($that)) !== 0 ) { 
      error_log("Parameter must be an instance of ".get_class($this));
      return false;
    }

    $properties = get_object_vars($that);
    foreach( $properties as $k=>$v ) { 
      $this->$k = $v;
    }
    return true;
  } // END: function copy($that)


  /**
   * Tests for similarity between elements.
   *
   * @public
   * @param {object} $that Object to compare this to.
   * @return {boolean} yes or no.
   */
  function equals(&$that) { 
    if( strcasecmp(get_class($this),get_class($that)) !== 0 ) { 
      return false;
    }

    $properties = get_object_vars($that);
    foreach( $properties as $k=>$v ) { 
      if( $this->$k !== $v ) { 
        return false;
      }
    }
    return true;
  } // END: function equals($that)


  // ----------------------------------------------------------------------//
  // --- PRIVATE METHODS BELOW --------------------------------------------//
  // ----------------------------------------------------------------------//

  /**
   * Tests input string against input pattern.
   *
   * @private
   * @param {string} $pattern A preg_match pattern.
   * @param {string} $string Text to match against pattern.
   * @return {boolean} true if matches or pattern is null; false otherwise.
   */
  function m_matches($pattern,$string) { 
    return (is_null($pattern) || preg_match($pattern,$string));
  }

} // END: class DirectoryListing

?>
