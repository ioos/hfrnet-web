<?php
/**
 * Extends the mysqli class. 
 *
 * @file    mySQL_DB.php
 * @package Core
 * @date    2019-10-09 PDT
 * @author  Joseph Chen
 * @version 1.5
 *
 * @modifications<pre>
 * 1.0 - 2013-04-01 Initial
 * 1.1 - Added selectQuery function
 * 1.2 - Added updatingQuery function
 * 1.3 - Remove ini stuff.  Added neocodata_DB and neocodev_DB class
 *       Added die if unable to connect
 * 1.4 - Added sccoos-obs0 class
 * 1.5 - Added port, removed sccoos_obs0 and neoco
 *</pre>
 *
 */
class mySQL_DB extends mysqli{

  public function __construct($server, $user, $pass, $db,$port=3306){
    // PHP 8.1+ throws on connect failure unless this is off.
    mysqli_report(MYSQLI_REPORT_OFF);
    parent::__construct($server, $user, $pass, $db,$port);

    if( mysqli_connect_error() ){
      die( "*ERROR* Unable to connect to database " . $server . ": " . mysqli_connect_errno() . " " .mysqli_connect_error() );
    }
  }

 /**
  * Run a query that updates (UPDATE, INSERT, DELETE) a database and return the number of rows affected
  * @param string $query
  * @param string optional multiple param - each param is used to replace an instance of ? in $query
  *        "IMPORT FROM userse where id=? ORDER BY?", 5, "username"
  * @return integer number of rows affected
  */
  function updatingQuery($query){
    $args = func_get_args();
    if( count($args) == 1 ){
      if( $this->query($query) ){
        return $this->affected_rows;
      }
      else {
        trigger_error("There was an unexpected error: {$query}: " . $this->error);
        return false;
      }
    }
    else {
      if( !$stmt = $this->prepare($query) ) {
        trigger_error("Unable to prepare statement: {$query}: " . $this->error);
        return false;
      }
      
      array_shift($args);
      $a = array();
      foreach( $args as $k=>&$v )
        $a[$k] = &$v;
      $types = str_repeat("s", count($args)); // all params are strings
      array_unshift($a, $types);
      call_user_func_array(array($stmt, 'bind_param'), $a );
      $stmt->execute();
      $rows = $stmt->affected_rows;
      $stmt->free_result();
      return $rows;
    }
  }

  /**
   * Run a SELECT query and return the results as an array
   * @param string $query 
   * @param string optional multiple param - each param is used to replace an instance of ? in $query
   *        "SELECT * FROM users WHERE id=? ORDER BY ?", 5, "username"
   * @return array results returned
   */
  function selectQuery($query){
    $args = func_get_args();
    if( count($args) == 1 ){
      $result = $this->query($query);
      if( !$result ) {
        trigger_error("There was an error running the query: $query " . $this->error);
        return false;
      }
      if( $result->num_rows ){
        $out = array();
        while (null != ($r = $result->fetch_array(MYSQLI_ASSOC)))
          $out[] = $r;
        return $out;
      }
      return null;
    }
    else {
      if( !$stmt = $this->prepare($query) ) {
        trigger_error("Unable to prepare statement: {$query}: " . $this->error);
        return false;
      }
      array_shift($args); // remove $query from args
      $a = array();
      foreach( $args as $k=>&$v)
        $a[$k] = &$v;
      $types = str_repeat("s", count($args)); // all params are strings
      array_unshift($a, $types);
      call_user_func_array(array($stmt, 'bind_param'), $a);
      $stmt->execute();

      // Fetch all results into array
      $metadata = $stmt->result_metadata();
      $out = array();
      $fields = array();
      if( !$metadata )
        return null;
      $length = 0;
      while (null != ($field = mysqli_fetch_field($metadata))) {
        $fields [] = &$out[$field->name];
        $length += $field->length;
      }
      call_user_func_array(array($stmt, "bind_result"), $fields);
      $output = array();
      $count = 0;
      while ($stmt->fetch()) {
        foreach( $out as $k=>$v)
          $output[$count][$k] = $v;
        $count++;
      }
      $stmt->free_result();
      return( $count == 0 ) ? null : $output;
    }
  }

}
?>
