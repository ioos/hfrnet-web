<?php
 /**
  * Abstraction of MySQL database interface.
  * 
  * @file    Db.php
  * @date    2020-06-24 11:44 PST
  * @author  Paul Reuter/Joseph Chen
  * @version 3.2.0
  *
  * Original Source: http://www.modwest.com/help/kb6-60.html
  * Minor spanish->english translations by Paul Reuter
  *
  * @modifications
  *  1.0.0 - Initial release
  *  2.0.0 - Expansion of defined constants, next_record(assoc?)
  *  2.1.0 - Added a few mysql_list functions and a get_results()
  *  2.2.0 - Added num_records() -> num_rows() because I kept doing it.
  *  2.3.0 - escape() mysql_real_escape_string shortcut.
  *  2.4.0 - Minor changes to the defaults
  *  2.4.1 - SANDBAR_TILEGENERATION added
  *  2.4.2 - Modified a optimize(), added analyze(), added last_insert_id()
  *  2.4.3 - Added SANDBAR_CHARTS
  *  3.0.0 - 2009-04-27 - Stripped down to minimum.
  *  3.0.1 - 2009-05-13 - normalized comments, changed Query_String to sql
  *  3.0.2 - 2009-10-18 - Attempt to reconnect if idle too long (mysql_ping)
  *  3.0.3 - 2009-12-01 - Set host=localhost as default in constructor.
  *  3.0.4 - 2010-04-06 - Add: quote($str,$ch='"') method to escape values.
  *  3.0.5 - 2010-04-07 - Add: get_results_header()
  *  3.1.0 - 2010-07-07 - upon mysql_connect, set time_zone variable.
  *  3.1.1 - 2010-07-12 - BugFix: data_seek sought OOB.
  *  3.1.2 - 2010-09-05 - Add: quote(str,dft=null,ch='"'); dft replaces null
  *  3.1.3 - 2010-09-14 - Add: copy(&$other) method
  *  3.2.0 - 2020-06-24 - Changed mysql to mysqli
  */

define( "DB_USE_MULTI_CONNECT" , true , true );
define( "DB_DEFAULT_PORT"      , 3306 , true );

/**
 * Database abstraction and utility class.
 *
 * @package Utilities
 * @subpackage Db
 */
class Db {

  /**
   * @access public
   */
  var $host;      //!< MySQL server hostname
  var $port;      //!< MySQL port number
  var $database;  //!< The default database name to use
  var $user;      //!< User from the database
  var $password;  //!< Password to use

  /**
   * @access protected
   */
  var $linkID;          //!< Result from mysql_connect()
  var $queryID;         //!< Result from the last mysql_query()
  var $record = array();    //!< The mysql_fetch_array record
  var $row;                 //!< Current row number
  var $errno = 0;           //!< Query error number.
  var $error = "";          //!< Query error message.
  var $timezone = "+00:00"; //!< Default timezone established on connect.

  /**
   * @private
   */
  var $m_bAssoc = false;  //!< Fetch arrays associated

  function __construct($host='localhost',$port=DB_DEFAULT_PORT,$user=null,$pass=null) { 
    $this->set_host($host,$port);
    $this->set_user($user,$pass);
    register_shutdown_function(array($this,'close'));
    return $this;
  }

  function set_host($host,$port=DB_DEFAULT_PORT) { 
    $this->host = $host;
    return $this->set_port($port);
  }

  function set_port($port) { 
    if(!is_numeric($port)) { 
      return false;
    }
    $this->port = $port;
    return true;
  }

  function set_user($usr,$pass=null) { 
    $this->user = $usr;
    if( !is_null($pass) ) { 
      $this->set_password($pass);
    }
    return true;
  }

  function set_password($pass) { 
    $this->password = $pass;
    return true;
  }

  function set_associated($b=true) { 
    $this->m_bAssoc = ($b) ? true : false;
    return true;
  }

  function get_associated() { 
    return ($this->m_bAssoc) ? true : false;
  }

  function set_database($dbname) { 
    $this->database = $dbname;
    return true;
  }

  function get_database() { 
    return $this->database;
  }

  function set_timezone($tz) {
    // If tz doesn't look like a clock ([+-]##:##)
    if( !preg_match('/^[+-][012][0-9]\:[0-5][0-9]$/',$tz) ) { 
      // Treat it like a timezone "UTC", "EDT","America/Los_Angeles", etc..
      if( function_exists('date_default_timezone_get') ) { 
        $tzold = date_default_timezone_get();
        date_default_timezone_set($tz);
        $tz = date("P");
        date_default_timezone_set($tzold);
      } else { 
        $tzold = getenv('TZ');
        putenv("TZ=$tz");
        $tz = date("P");
        putenv("TZ=$tzold");
      }

    } else if( (string)intVal($tz)===(string)$tz ) { 
    // Else if $tz is a timezone offset (seconds)
      $tz = sprintf("%s%02d:%02d",($tz<0)?'-':'+',abs($tz)/60,abs($tz)%60);
    }

    // If after all that, it's still not a clock, fail.
    if( !preg_match('/^[+-][012][0-9]\:[0-5][0-9]$/',$tz) ) { 
      return false;
    }
    $this->timezone = $tz;
    return true;
  } // END: function set_timezone($tz)


  function copy(&$that) {
    foreach( get_object_vars($that) as $k => $v ) {
      $this->$k = $v;
    }
    $this->linkID = 0;
    $this->queryID = 0;
    return true;
  } // END: function copy(&$that)


  function halt($msg) {
    echo("Error: $msg\n");
    echo("Error in MySQL: $this->errno ($this->error)\n");
    exit("Session Aborted.");
  }

  function connect() {
    if(!$this->linkID) {
      // PHP 8.1+ throws on connect/query failure unless this is off.
      mysqli_report(MYSQLI_REPORT_OFF);
      $this->linkID = mysqli_connect(
        $this->host,
        $this->user,
        $this->password,
        $this->database,
        $this->port
      );
      if( !$this->linkID ) {
        $this->halt("linkID == false, connect failed");
      }
      $this->query('SET TIME_ZONE='.$this->quote($this->timezone));
    } else if( function_exists('mysqli_ping') ) {
      // reestablish a connection if disconnected.
      if( !mysqli_ping($this->linkID) ) {
        $this->linkID = 0;
        return $this->connect();
      }
    }
  }

  function query($sql) {
    // echo($sql."\n");
    $this->connect();
    if( $this->queryID = mysqli_query($this->linkID,$sql) ){
      $this->row = 0;
      $this->errno = mysqli_errno($this->linkID);
      $this->error = mysqli_error($this->linkID);
    }
    else {
     #if (!$this->queryID) {
      $this->halt("SQL Invalid: ".$sql);
    }
    return $this->queryID;
  }


  function data_seek($row_number) { 
    if( !is_resource($this->queryID) ) { 
      return false;
    }
    $this->row = $row_number;
    if( $row_number >= $this->num_rows() ) { 
      return false;
    }
    $success = mysqli_data_seek($this->queryID,$row_number);
    $this->errno = mysqli_errno($this->linkID);
    $this->error = mysqli_error($this->linkID);
    return $success;
  }


  function get_results_header() { 
    if( !is_resource($this->queryID) ) { 
      return false;
    }
    $old_row = $this->row;
    if( !$this->data_seek(0) ) { 
      return false;
    }
    $row = mysqli_fetch_assoc($this->queryID);
    $this->errno = mysqli_errno($this->linkID);
    $this->error = mysqli_error($this->linkID);
    $this->data_seek($old_row);
    return array_keys($row);
  } // END: function get_results_header()


  function get_results() { 
    //$res = array();
    $flag = ($this->m_bAssoc) ? MYSQLI_ASSOC : MYSQLI_NUM;
    $res =  mysqli_fetch_all($this->queryID, $flag);
    mysqli_free_result($this->queryID);
/*
    while( ($row=$this->next_record()) !== false ) { 
      array_push($res,$row);
    }
*/
    return $res;
  }


  function get_structure() { 
    $old_assoc = $this->get_associated();

    $this->set_associated(false);
    $tabs = $this->list_tables();
    if(!$tabs) { 
      $this->set_associated($old_assoc);
      return false;
    }

    $data = array();
    $this->set_associated(true);
    foreach($tabs as $tab) { 
      $tab = $tab[0];
      $fields = $this->list_fields($tab);
      if(!$fields) { 
        continue;
      }
      $data[$tab] = $fields;
    }

    $this->set_associated($old_assoc);
    return $data;
  }

  function list_fields($tab) { 
    $this->query("SHOW COLUMNS FROM ".$tab);
    return ($this->errno) ? false : $this->get_results();
  }

  function list_tables($db=null) { 
    if( is_null($db) ) { 
      $db = $this->database;
    }
    $this->query("SHOW TABLES FROM ".$db);
    return ($this->errno) ? false : $this->get_results();
  }

  function esc($str) { 
    return $this->escape($str);
  }

  function escape($str) { 
    $this->connect();
    return mysqli_real_escape_string($this->linkID,$str);
  }

  function quote($str,$dft=null,$ch='"') {
    if( is_null($str) ) { 
      $str = $dft;
    }
    if( is_null($str) ) { 
      return 'NULL';
    }
    if( is_int($str) ) { 
      return $str;
    }
    return $ch.$this->escape($str).$ch;
  }

  function last_insert_id() { 
    $this->connect();
    return mysqli_insert_id($this->linkID);
  }

  function num_records() { 
    return $this->num_rows();
  }

  function num_rows() {
    return mysqli_num_rows($this->queryID);
  }

  function affected_rows() {
    return mysqli_affected_rows($this->linkID);
  }

  function analyze($tbl_name) { 
    return $this->query("ANALYZE TABLE $tbl_name");
  }

  function optimize($tbl_name) {
    return $this->query("OPTIMIZE TABLE $tbl_name");
  }

  function clean_results() {
    if($this->queryID) {
      mysqli_free_result($this->queryID);
    }
    return true;
  }

  function disconnect() { 
    return $this->close();
  }

  function close() {
    if($this->linkID) { 
      mysqli_close($this->linkID);
    }
    return true;
  }

} // END: class Db.php

?>
