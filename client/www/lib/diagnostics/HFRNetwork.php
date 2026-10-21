<?php
/**
 * Utility used to return queries used for the hfradar db
 *
 * @file HFRNetwork.php
 * @date 2023-06-20
 * @author Joseph Chen
 * @version 1.0.9
 * 
 * @modifications
 * 1.0.0   - 2016-10-25 - Initial creation
 * 1.0.1   - 2016-11-22 - Added patterntype sort for latency,range,numbersolutions
 * 1.0.2   - 2017-03-28 - Added custom query function, and getDiskUsage
 * 1.0.3   - 2018-03-15 - Added RTVProc
 * 1.0.4   - 2020-01-09 - Moved db server info into ini files, added manufacturer to getLastData
 * 1.0.5   - 2020-10-02 - Added loadIniFile() to HFRNetwork
 * 1.0.6   - 2022-07-19 - Added getHistory to RTVProc
 * 1.0.7   - 2022-11-08 - Added site config functions to RTVProc class
 * 1.0.8   - 2022-11-16 - Changed getRadialRange to use rd.rad_range instead of rf.range_bin_end and rf.range_res
 * 1.0.9   - 2023-06-20 - getSiteIdDomainResolution - Added network to query
 */

require_once("/var/www/lib/diagnostics/mySQL_DB.php");

class hfrnetDB extends mySQL_DB{
  function __construct($server,$user, $pass, $db, $port){
    parent::__construct($server,$user, $pass, $db, $port);
  }
}

class hfrnetMetricsDB extends mySQL_DB{
  function __construct($server,$user,$pass,$db,$port=3306){
    parent::__construct($server,$user,$pass,$db,$port);
  }
}

class rtvprocDB extends mySQL_DB{
  function __construct($server,$user,$pass,$db,$port){
    parent::__construct($server,$user,$pass,$db,$port);
  }
}

class RTVProc{
  public $site = "";
  public $network = "";

  private $db;
  const DB_INI = "/var/www/lib/diagnostics/hfrnet_db.ini";

  function __construct(){
    $this->initializeDB();
    $a = func_get_args();
    $i = func_num_args();
    if( method_exists( $this, $f='__construct'.$i )){
      call_user_func_array(array($this,$f),$a);
    }
  }
  
  function __destruct(){
    $this->db->close();
  }
  
  private function initializeDB(){
    $all_ini = parse_ini_file(self::DB_INI, true);
    $db_ini = isset($all_ini['hfradar']) ? $all_ini['hfradar'] : $all_ini;
    $this->db = new rtvprocDB($db_ini["server"],$db_ini["user"],$db_ini["password"],$db_ini["db"],$db_ini["port"]);
  }
 
  function setNetwork($network){
    $this->network = $network;
  }
  
  function setSite($site){
    $this->site = $site;
  }

  //TODO delete this D
  function getBeamPatternPreference(){
    $query = "SELECT beampattern FROM site WHERE name=?";
    $rows = $this->db->selectQuery($query,$this->site);
    return $rows;
  }
  
  //TODO delete this D
  function updateBeamPattern($pattern){
    $query = "UPDATE site SET beampattern=? WHERE name=? and network=?";
    $rows = $this->db->updatingQuery($query,$pattern,$this->site,$this->network);
    return $rows;
  }

  function getAllSiteConfig(){
    $query = "SELECT s.network,s.name,c.beampattern,c.use_radial_minute,d.description,r.name as 'res'
 from site_config c     join site s on s.id=c.site_id     join domain d
on d.id = c.domain_id     join resolution r on r.id = c.resolution_id
where c.end_time IS NULL order by s.network,s.name,d.name,r.name; ";
    $rows = $this->db->selectQuery($query);
    return $rows;
  }
  
  function getHistory(){
    $query = "SELECT s.name 'site', r.name 'resolution', sc.start_time, sc.end_time, sc.beampattern, sc.use_radial_minute FROM site_config sc right join site s on s.id = sc.site_id right join resolution r on sc.resolution_id=r.id where s.name=? order by sc.start_time desc";
    $rows = $this->db->selectQuery($query, $this->site);
    return $rows;
  }
  
  // Get the site's siteid, domain ids and resolution ids
  // returned as an array of dictionary items (site_id, domain_id, resolution_id)
  private function getSiteIdDomainResolution(){
    $query = "SELECT sc.site_id,sc.domain_id,sc.resolution_id, MAX(sc.start_time) FROM site_config sc right join site s on s.id = sc.site_id where s.name=? AND s.network=? GROUP BY sc.site_id,sc.domain_id,sc.resolution_id";
    $rows = $this->db->selectQuery($query, $this->site, $this->network);
    return $rows;
  }
  
  // Add an endtime to a site's config
  private function addEndTimeSiteConfig($siteid,$endtime){
    $query = "UPDATE site_config SET end_time=? where site_id=? and end_time is null";
    $rows = $this->db->updatingQuery($query, $endtime, $siteid);
    return $rows;
  }

  // Add new entries to the site_config table
  // start - starttime in epoch
  // pattern - ideal or measured
  // minute - minute to use
  // addendtime - boolean, true to add an end time
  function addNewSiteConfig($start,$pattern,$minute,$addendtime){
    $rows = $this->getSiteIdDomainResolution();
    if (count($rows) == 0){
      error_log("addNewSiteConfig(): Unable to find site config for " . $this->site);
      return false;
    }

    if( $addendtime ){
      $rs = $this->addEndTimeSiteConfig($rows[0]["site_id"],$start);
      if ($rs == 0){
        error_log("addNewSiteConfig(): No end times updated with site_id: " . $rows[0]['site_id']);
        return false;
      }
    }

    $values = array();
    foreach ($rows as $row){
      $values[] = sprintf("(%s,%s,%s,'%s','%s',%s)", $row["site_id"],$row["domain_id"],$row["resolution_id"],$start,$pattern, $minute);
    }

    $query = sprintf("INSERT into site_config (site_id,domain_id,resolution_id,start_time,beampattern,use_radial_minute) values %s", implode(",",$values));
    $rows = $this->db->updatingQuery($query);
    if( count( $rows ) == 0 ){
      error_log("addNewSiteConfig(): Error inserting new site_config data: " . $query);
      return false;
    }
    return true;
  }
 
  // Stop the site from contributing to totals (add end time)
  function stopSiteConfig($enddate){
    $rows = $this->getSiteIdDomainResolution();
    if (count($rows) == 0){
      error_log("stopSiteConfig(): Unable to find site config for " . $this->site);
      return false;
    }  
    $rs = $this->addEndTimeSiteConfig($rows[0]["site_id"],$enddate);
    if ($rs == 0){
      error_log("stopSiteConfig(): No end times updated with site_id: " . $rows[0]['site_id']);
      return false;
    }
    return true;
  }
  
}

class HFRMetrics{
  private $db;
  const DB_INI = "/var/www/lib/diagnostics/hfrnet_db.ini";

  function __construct(){
    $this->initializeDB();
    $a = func_get_args();
    $i = func_num_args();
    if( method_exists( $this, $f='__construct'.$i )){
      call_user_func_array(array($this,$f),$a);
    }
  }

  private function initializeDB(){
    $all_ini = parse_ini_file(self::DB_INI, true);
    $db_ini = isset($all_ini['metrics']) ? $all_ini['metrics'] : $all_ini;
    $this->db = new hfrnetMetricsDB($db_ini["server"],$db_ini["user"],$db_ini["password"],$db_ini["db"],$db_ini["port"]);
  }

  /**
   * Get the metric uptime by quarter by association
   *
   * Will return using fiscal quarters, thus for 2016, quarter 1 will be
   * 2015-10 thru 2015-12.  
   *
   * @param int year
   * @return array data 
   */
  function getMetricUptime($year){
    $query = "select
  ra.ra,if(b.FQ=4,1,b.FQ+1) FQ,sum(b.numObs) numObs,count(distinct b.site) count
from (
select 
ifnull(a.affiliation,a.affiliation2) affiliation,ifnull(a.site,a.site2) site,quarter(ifnull(a.date,a.date2)) FQ, 
if(numObs>numObs2,numObs,numObs2) numObs
from
(
select i.site,i.affiliation,i.date,i.type,i.numObs,m.site2,m.affiliation2,m.date2,m.type2,ifnull(m.numObs2,0) numObs2
from
(SELECT site,affiliation,date,type,if(theoObs>744,numObs/2,numObs) numObs FROM metricUptime where type ='RDLi' and (year(date)=? or (year(date)=? and quarter(date)=4))) as i left join
(SELECT site site2,affiliation affiliation2, date date2,type type2,if(theoObs>744,numObs/2,numObs) numObs2 FROM metricUptime where type ='RDLm' and (year(date)=? or (year(date)=? and quarter(date)=4))) as m on
i.site=m.site2 and i.date=m.date2
union
select i.site,i.affiliation,i.date,i.type,ifnull(i.numObs,0) numObs,m.site2,m.affiliation2,m.date2,m.type2,m.numObs2
from
(SELECT site,affiliation,date,type,if(theoObs>744,numObs/2,numObs) numObs FROM metricUptime where type ='RDLi' and (year(date)=? or (year(date)=? and quarter(date)=4))) as i right join
(SELECT site site2,affiliation affiliation2,date date2,type type2,if(theoObs > 744, numObs/2,numObs) numObs2 FROM metricUptime where type ='RDLm' and (year(date)=? or (year(date)=? and quarter(date)=4))) as m on
i.site=m.site2 and i.date=m.date2
) as a ) as b left join
regionalAssociations ra on
b.affiliation = ra.affiliation
group by
  ra.ra,b.FQ";
    $rows = $this->db->selectQuery($query,$year,$year-1,$year,$year-1,$year,$year-1,$year,$year-1);

    if( date("z", mktime(0,0,0,12,31,$year)) + 1 == 366 ){
      $leapyear=1;
    }
    else {
      $leapyear=0;
    }
    $thismonth = gmdate( "n" ); 
    $thisquarter = ceil($thismonth/3);
    // Fiscal quarter is quarter+1.  FQ4=Q1
    if( $thisquarter == 4 ) {
      $thisquarter = 1;
    }
    else {
      $thisquarter++;
    }
    // Figure out how many days are in the quarter
    foreach( $rows as $index=>$row ){
      // If this is fiscal quarter 2, add 1 day
      switch( $row["FQ"] ){
        case 2:
          $days = 31+28+31+$leapyear;
          break;
        case 3:
          $days = 30+31+30;
          break;
        // Q1 - oct-dec, q4 - jul-sep
        default:
          $days = 31+30+31;
      }
      $hours = $days*24;
 
      // If the current quarter is the same as the fiscal quarter
      // Calculate the number of days based on what day today is 
      if( $thisquarter == $row["FQ"] ){
        switch( $thisquarter ){
          case 1:
            $day1=gmmktime(0,0,0,10,1,$year);
            break;
          case 2:
            $day1=gmmktime(0,0,0,1,1,$year);
            break;
          case 3:
            $day1=gmmktime(0,0,0,4,1,$year);
            break;
          case 4:
            $day1=gmmktime(0,0,0,7,1,$year);
            break;
        }
        $hours = floor((mktime() - $day1)/3600);
      }
      $rows[$index]["theoObs"] = $hours * $rows[$index]["count"];
    }
    // Transpose the columns so instead of # ra,quarter,numobs...
    // it becomes ra, q1,q2,q3,q4
    $ra = array();
    foreach ($rows as $row){
      $ra[$row["ra"]]["fq".$row["FQ"]] = $row["numObs"]/$row["theoObs"];
    }
    return $rows;
  }
}

class HFRNetwork {
  
  public $network = "";
  public $site = "";
  public $patternType = "m";
  public $startTime = "";
  public $endTime = "";
  
  private $db;
  private $db_ini = array();

  const DB_INI = "/var/www/lib/diagnostics/hfrnet_db.ini";

  function __construct(){
    $all_ini = parse_ini_file(self::DB_INI, true);
    // Prefer 'hfradar' section if present, otherwise support legacy flat INI
    $this->db_ini = isset($all_ini['hfradar']) ? $all_ini['hfradar'] : $all_ini;
    $this->initializeDB();
    $a = func_get_args();
    $i = func_num_args();
    if( method_exists( $this, $f='__construct'.$i )){
      call_user_func_array(array($this,$f),$a);
    }
  }
  function __construct1($network){
    $this->setNetwork($network);
  }
  function __construct2($network,$site){
    $this->setNetwork($network);
    $this->setSite($site);
  }
  function __destruct(){
    $this->db->close();
  }

  private function initializeDB(){
    $this->db = new hfrnetDB($this->db_ini["server"], $this->db_ini["user"],$this->db_ini["password"],$this->db_ini["db"],$this->db_ini["port"]);
  }

  function loadIniFile($file){
    $this->db_ini = parse_ini_file($file);
    if (isset($this->db)){
      $this->db->close();
    }
    $this->initializeDB();
  }  

  function setNetwork($network){
    $this->network = $network;
  }

  function setSite($site){
    $this->site = $site;
  }

  function setPatternType($type){
    $this->patternType = $type;
  }

  function setStartTime($time){
    $this->startTime = $time;
  }

  function setEndtime($time){
    $this->endTime = $time;
  }

  function clearNetwork(){
    $this->network = "";
  }

  function clearSite(){
    $this->site = "";
  }

  function clearStartTime(){
    $this->startTime = "";
  }
  function clearEndTime(){
    $this->endTime = "";
  }

  function clearPatternType(){
    $this->patternType = "m";
  }

  function clearAll(){
    $this->clearNetwork();
    $this->clearSite();
    $this->clearPatternType();
    $this->clearStartTime();
    $this->clearEndTime();
  }

  /**
   * Get network for the station
   */
  function getStationNetwork(){
    //$query = "SELECT DISTINCT net from site where sta=?";
    $query = "SELECT distinct net FROM site left join network on site.network_id = network.network_id where sta=?";
    $rows = $this->db->selectQuery($query,$this->site); 
    return $rows;
  }

  /** 
   * Get station info.  This will return data from the site table 
   * To get only one site, set the site variable.
   * To retrieve all stations within an affiliation, set the network variable
   * @return array data 
   */
  function getSiteInfo(){
    $whereClause = array();
    $params = array();
    $whereString = "";

    $query = "SELECT site.*,network.net,network.netname FROM site left join network on site.network_id = network.network_id";
    
    $whereClause[] = "site.decommissioned=false";
    //$whereClause[] = "site.endtime is null"; 
    if( ! $this->network == "" ){
       $whereClause[] = "network.net=?";
       $params[] = $this->network;
    }
    if( ! $this->site == "" ){
       $whereClause[] = "site.sta=?";
       $params[] = $this->site;
    }
    if( count($whereClause) > 1 ){
      $whereString = implode(" AND ", $whereClause);
    }
    else {
      $whereString = implode("", $whereClause);
    }
    if( !$whereString == "" ) $query .= " WHERE $whereString";
    $query .= " ORDER BY network.net, site.sta";

    array_unshift($params, $query);
    $rows = call_user_func_array( array( $this->db, 'selectQuery' ), $params );
    return $rows;
  }

  /**
   * Get the data used for the tree view
   * @return array data retrieved based on jstree structure
   */
  function getTreeViewData(){
    $rows = $this->getSiteInfo(); 

    $networks = array();
    $treedata = array();
    
    $line = array();
    $line["id"] = "all";
    $line["parent"] = "#";
    $line["text"] = "All Stations";
    $line["state"]["opened"] = "true";
    $treedata[] = $line;

    foreach( $rows as $row ){
      $line = array();

      // If this network hasn't been added yet
      if( ! array_key_exists($row["net"],$networks) ){
        $networks[$row["net"]] = 1;
        $line["id"] = $row["net"];
        $line["parent"] = "all";
        $line["text"] = $row["net"] ." - " .$row["netname"];
        $treedata[] = $line;
      }

      // We have the network now add the site
      $line["id"] = $row["net"]."/".$row["sta"];
      $line["parent"] = $row["net"];
      $line["text"] = $row["sta"]." - ".$row["staname"];
      $treedata[] = $line;

    }
    return $treedata;
  }

  /*
   * Get data from the hardware diagnostics table
   */
  private function getHardwareDiagnostics($limit=""){
    $whereString = "";

    $query = "SELECT hd.* from hardwarediag as hd left join network as n on hd.network_id = n.network_id left join site as s on hd.site_id = s.site_id";

    if( ! $this->network == "" ){
      $whereClause[] = "n.net=?";
      $params[] = $this->network;
    }
    if( ! $this->site == "" ){
      $whereClause[] = "s.sta=?";
      $params[] = $this->site;
    }
    if( ! $this->startTime == "" ){
      $whereClause[] = "hd.time>=?";
      $params[] = $this->startTime;
    }
    if( ! $this->endTime == "" ) {
      $whereClause[] = "hd.time<=?";
      $params[] = $this->endTime;
    }

    $whereString = implode(" AND ",$whereClause );
    if (! $whereString == "" ) $query .= " WHERE $whereString";
    $query .= " ORDER BY time DESC";
    if( ! $limit == "" ) $query .= " LIMIT $limit";

    array_unshift($params, $query);

    $rows= call_user_func_array( array($this->db,'selectQuery'), $params );
    return $rows;
  }
  
  /* 
   * Get the latest data from the hardware diagnotics table
   */
  function getLatestHardwareDiagnostics(){
    return $this->getHardwareDiagnostics(1);
  }
 
  /*
   * Get data from the radial diagnostics table
   */ 
  private function getRadialDiagnostics($limit=""){
    $whereString = "";

    $query = "SELECT rd.* from radialdiag as rd left join network as n on rd.network_id = n.network_id left join site as s on rd.site_id = s.site_id ";

    if( ! $this->network == "" ){
      $whereClause[] = "n.net=?";
      $params[] = $this->network;
    }
    if( ! $this->site == "" ){
      $whereClause[] = "s.sta=?";
      $params[] = $this->site;
    }
    if( ! $this->startTime == "" ){
      $whereClause[] = "rd.time>=?";
      $params[] = $this->startTime;
    }
    if( ! $this->endTime == "" ) {
      $whereClause[] = "rd.time<=?";
      $params[] = $this->endTime;
    }

    $whereString = implode(" AND ",$whereClause );
    if (! $whereString == "" ) $query .= " WHERE $whereString";
    $query .= " ORDER BY time DESC";
    if( ! $limit == "" ) $query .= " LIMIT $limit";

    array_unshift($params, $query);

    $rows= call_user_func_array( array($this->db,'selectQuery'), $params );
    return $rows;
  }
  
  /* 
   * Get the latest data from the radial diagnostics table
   */
  function getLatestRadialDiagnostics(){
    return $this->getRadialDiagnostics(1);
  }

  /*
   * Get the latest data from the radial file table
   */
  function getLatestRadialFiles(){
    $query = "SELECT n.net,s.sta,s.staname,rf.*
              FROM network n 
              LEFT JOIN site s on n.network_id = s.network_id 
              LEFT JOIN (SELECT distinct rf.* FROM (select site_id,network_id,patterntype,time from latest_radialfiles) lrf
              LEFT JOIN radialfiles rf on lrf.site_id = rf.site_id AND lrf.network_id = rf.network_id AND lrf.time = rf.time AND
              lrf.patterntype = rf.patterntype) rf on
              s.site_id = rf.site_id AND s.network_id = rf.network_id
              WHERE %s";
    $whereclause[] = "s.decommissioned=false";
    if( $this->network <> "" ){
      $whereclause[] = "n.net='$this->network'";
    }
    if( $this->site <> ""){
      $whereclause[] = "s.sta='$this->site'";
    }
    $wc = implode(" AND ",$whereclause);
    $rows = $this->db->selectQuery(sprintf($query, $wc));
    return $rows;

  }

  /*
   * S3 object path for a radialfiles row.
   * Ingest stores the full key in dfile (/radials/SITE/YYYY-MM/file.ruv).
   * Older rows may store only the basename, with the directory in dir.
   */
  static function radialObjectPath($row){
    $dfile = trim((string)(isset($row['dfile']) ? $row['dfile'] : ''));
    if ($dfile === '') {
      return '';
    }
    if (strpos($dfile, '/') === false) {
      $dir = rtrim(trim((string)(isset($row['dir']) ? $row['dir'] : '')), '/');
      if ($dir !== '') {
        $dfile = $dir . '/' . $dfile;
      }
    }
    return '/' . ltrim($dfile, '/');
  }

  /*
   * True when this network and station exist.
   */
  function hasStation(){
    if ($this->site === '' || $this->network === '') {
      return false;
    }
    $rows = $this->db->selectQuery(
      "SELECT s.site_id
         FROM site s
         JOIN network n ON n.network_id = s.network_id
        WHERE s.sta=? AND n.net=?
        LIMIT 1",
      $this->site,
      $this->network
    );
    return is_array($rows) && count($rows) > 0;
  }

  /*
   * Latest radial file for the station, from latest_radialfiles.
   * Patterns m and i are filtered. wera and lera are not an ideal/measured
   * pair, so every pattern is eligible and the newest row is used.
   * When several formats share that timestamp, prefer format ruv.
   * Returns one row (dfile, dir, format, patterntype, time) or null.
   */
  function getLatestRadialCoverageFile($pattern){
    if ($this->site === '' || $this->network === '') {
      return null;
    }
    $pattern = strtolower((string)$pattern);
    $broad = ($pattern === 'wera' || $pattern === 'lera');
    if (!$broad && $pattern !== 'm' && $pattern !== 'i') {
      return null;
    }

    $query = "SELECT rf.dfile, rf.dir, rf.format, rf.patterntype, rf.time
                FROM latest_radialfiles lrf
                JOIN radialfiles rf
                  ON rf.site_id = lrf.site_id
                 AND rf.network_id = lrf.network_id
                 AND rf.time = lrf.time
                 AND rf.patterntype = lrf.patterntype
                JOIN site s
                  ON s.site_id = lrf.site_id AND s.network_id = lrf.network_id
                JOIN network n
                  ON n.network_id = lrf.network_id
               WHERE s.sta=? AND n.net=?";
    if (!$broad) {
      $query .= " AND rf.patterntype=?";
    }
    $query .= " ORDER BY lrf.time DESC, FIELD(rf.format, 'ruv') DESC, rf.mtime DESC LIMIT 1";

    if ($broad) {
      $rows = $this->db->selectQuery($query, $this->site, $this->network);
    } else {
      $rows = $this->db->selectQuery($query, $this->site, $this->network, $pattern);
    }
    if (!is_array($rows) || count($rows) === 0) {
      return null;
    }
    return $rows[0];
  }

  /*
   * Radial files whose observation hour overlaps [startTime, endTime].
   * A file at `time` is included when time <= endTime and time > startTime - 3599,
   * matching the previous filename-hour filter (file hour ends at time + 3599).
   * One row per time and pattern; format ruv wins when several formats share a timestamp.
   */
  function getRadialCoverageFiles($pattern){
    if ($this->site === '' || $this->network === '') {
      return array();
    }
    $pattern = strtolower((string)$pattern);
    $broad = ($pattern === 'wera' || $pattern === 'lera');
    if (!$broad && $pattern !== 'm' && $pattern !== 'i') {
      return array();
    }

    $end = (int)$this->endTime;
    $start = (int)$this->startTime;
    $query = "SELECT rf.dfile, rf.dir, rf.format, rf.patterntype, rf.time
                FROM radialfiles rf
                JOIN site s
                  ON s.site_id = rf.site_id AND s.network_id = rf.network_id
                JOIN network n
                  ON n.network_id = rf.network_id
               WHERE s.sta=? AND n.net=?";
    if (!$broad) {
      $query .= " AND rf.patterntype=?";
    }
    $query .= " AND rf.time<=? AND rf.time>?
                ORDER BY rf.time ASC, FIELD(rf.format, 'ruv') DESC, rf.mtime DESC";

    $overlapStart = $start - 3599;
    if ($broad) {
      $rows = $this->db->selectQuery($query, $this->site, $this->network, $end, $overlapStart);
    } else {
      $rows = $this->db->selectQuery($query, $this->site, $this->network, $pattern, $end, $overlapStart);
    }
    return $this->preferOneRadialFilePerTime($rows);
  }

  /*
   * Keep the first row for each observation time and pattern.
   * Callers order format ruv ahead of other formats.
   */
  private function preferOneRadialFilePerTime($rows){
    if (!is_array($rows)) {
      return array();
    }
    $kept = array();
    foreach ($rows as $row) {
      $key = sprintf('%.0f|%s', (float)$row['time'], $row['patterntype']);
      if (!isset($kept[$key])) {
        $kept[$key] = $row;
      }
    }
    return array_values($kept);
  }

  /*
   * Get the last data for a network or site
   * Must select either network or station.  Otherwise it'll take forever to return everything
   */
  function getLastData(){
    
    // Make sure either the network or station is specified
    if( $this->network == "" && $this->site == "" ) {
      print "getLastData() requires a network or station to be specified\n";
      exit;
    }

    $query = "select 
  n.net,s.sta,s.staname,rf.lat,rf.lon,rf.time,rf.dfile,rf.nrads,rf.mtime,rf.patterntype,rf.cfreq, rf.range_res,rf.manufacturer,rf.spec_range_cells,
  rd.rad_range,
  hd.xmit_fwd_pwr,hd.xmit_ref_pwr,hd.receiver_chassis_tmp,hd.awg_tmp
from
   network n left join
   site s on n.network_id = s.network_id left join

   ( select distinct rf.site_id,rf.network_id,rf.lat,rf.lon,rf.time,rf.cfreq,rf.dfile,rf.nrads,rf.mtime,rf.patterntype,rf.spec_range_cells,rf.range_res,rf.manufacturer
    FROM (select site_id,network_id,patterntype,time from latest_radialfiles ) lrf left join radialfiles rf on 
          lrf.site_id = rf.site_id and lrf.network_id = rf.network_id and lrf.time = rf.time and lrf.patterntype = rf.patterntype) rf on
   s.site_id = rf.site_id and s.network_id = rf.network_id left join

   ( select distinct rd.site_id,rd.network_id,rd.rad_range,rd.patterntype
    FROM (select site_id,network_id,patterntype, time from latest_radialdiag ) lrd left join radialdiag rd on 
          lrd.site_id = rd.site_id and lrd.network_id = rd.network_id and lrd.time = rd.time and lrd.patterntype = rd.patterntype ) rd on
   s.site_id = rd.site_id and s.network_id = rd.network_id and rf.patterntype = rd.patterntype left join

   ( select distinct hd.site_id,hd.network_id,hd.xmit_fwd_pwr,hd.xmit_ref_pwr,hd.receiver_chassis_tmp,hd.awg_tmp
    FROM (select site_id,network_id,time from latest_hardwarediag ) lhd left join hardwarediag hd on 
          lhd.site_id = hd.site_id and lhd.network_id = hd.network_id and lhd.time = hd.time ) hd on
   s.site_id = hd.site_id and s.network_id = hd.network_id
WHERE %s";
    $whereclause[] = "s.decommissioned=false";
    if( $this->network <> "" ){
      $whereclause[] = "n.net='$this->network'";
    }
    if( $this->site <> ""){
      $whereclause[] = "s.sta='$this->site'";
    }
    $wc = implode(" AND ",$whereclause);
    $rows = $this->db->selectQuery(sprintf($query, $wc));

    return $rows;
  }

  /**
   * Returns the number of sites by affiliation/network
   * @param boolean $active true will return only active stations.  false is default
   * @return array affliation and the number of sites
   */
  function getNumberOfSitesByAffiliation($active=false){

    $query = "select n.net, count(s.site_id) as sites
              from network n join site s on n.network_id = s.network_id %s 
              group by n.net";

    if( $active===true ){
      $where = "WHERE s.decommissioned = false";
    }
    else {
      $where = "";
    }

    $query = sprintf($query, $where);

    $rows = $this->db->selectQuery($query);
    return $rows;
  }

  function getReceiverTemperature(){
    $net = (empty($this->network)) ? $net="" : $net="AND net='$this->network'"; 
    $query = "select hd.time,hd.receiver_chassis_tmp
              from
                network n join site s
                on n.network_id = s.network_id 
                left join hardwarediag hd
                on hd.site_id = s.site_id and hd.network_id = n.network_id
              where
                s.sta=? $net AND hd.time<=? and hd.time>=?
              order by hd.time ASC";

    $rows = $this->db->selectQuery($query,$this->site,$this->endTime,$this->startTime);
    return $rows;
  }
  function getTXReflectedPower(){
    $net = (empty($this->network)) ? $net="" : $net="AND net='$this->network'"; 
    $query = "select hd.time,hd.xmit_ref_pwr
              from
                network n join site s
                on n.network_id = s.network_id 
                left join hardwarediag hd
                on hd.site_id = s.site_id and hd.network_id = n.network_id
              where
                s.sta=? $net AND hd.time<=? and hd.time>=?
              order by hd.time ASC";

    $rows = $this->db->selectQuery($query,$this->site,$this->endTime,$this->startTime);
    return $rows;
  }
 
  function getTXForwardPower(){
    $net = (empty($this->network)) ? $net="" : $net="AND net='$this->network'"; 
    $query = "select hd.time,hd.xmit_fwd_pwr
              from
                network n join site s
                on n.network_id = s.network_id 
                left join hardwarediag hd
                on hd.site_id = s.site_id and hd.network_id = n.network_id
              where
                s.sta=? $net AND hd.time<=? and hd.time>=?
              order by hd.time ASC";

    $rows = $this->db->selectQuery($query,$this->site,$this->endTime,$this->startTime);
    return $rows;
  }
 
  function getAWGTemperature(){
    $net = (empty($this->network)) ? $net="" : $net="AND net='$this->network'"; 
    $query = "select hd.time,hd.awg_tmp
              from
                network n join site s
                on n.network_id = s.network_id 
                left join hardwarediag hd
                on hd.site_id = s.site_id and hd.network_id = n.network_id
              where
                s.sta=? $net AND hd.time<=? and hd.time>=?
              order by hd.time ASC";

    $rows = $this->db->selectQuery($query,$this->site,$this->endTime,$this->startTime);
    return $rows;
  }
  function getDatabaseLatency(){
    $net = (empty($this->network)) ? $net="" : $net="AND net='$this->network'"; 
    $query = "select rf.patterntype, rf.time,rf.mtime-rf.time as latency
              from
                network n join site s
                on n.network_id = s.network_id 
                left join radialfiles rf
                on rf.site_id = s.site_id and rf.network_id = n.network_id
              where
                s.sta=? $net AND rf.time<=? and rf.time>=? AND (minute(from_unixtime(rf.time))>=45 or minute(from_unixtime(rf.time))<=5)
              order by rf.patterntype,rf.time ASC";

    $rows = $this->db->selectQuery($query,$this->site,$this->endTime,$this->startTime);
    return $rows;
  }
  function getRadialRange(){
    $net = (empty($this->network)) ? $net="" : $net="AND n.net='$this->network'"; 
    $query = "select rd.patterntype, rd.time,rd.rad_range
              from
                network n join site s
                on n.network_id = s.network_id 
                left join radialdiag rd
                on rd.site_id = s.site_id and rd.network_id = n.network_id
              where
                s.sta=? $net AND rd.time<=? and rd.time>=? and rd.rad_range is not null
              order by rd.patterntype,rd.time ASC";

    $rows = $this->db->selectQuery($query,$this->site,$this->endTime, $this->startTime);
    return $rows;
  }
  function getNumberSolutions(){
    $net = (empty($this->network)) ? $net="" : $net="AND net='$this->network'"; 
    $query = "select rf.patterntype, rf.time,rf.nrads
              from
                network n join site s
                on n.network_id = s.network_id 
                left join radialfiles rf
                on rf.site_id = s.site_id and rf.network_id = n.network_id
              where
                s.sta=? $net AND rf.time<=? and rf.time>=? 
              order by rf.patterntype,rf.time ASC";


    $rows = $this->db->selectQuery($query,$this->site,$this->endTime, $this->startTime);
    return $rows;
  }
  
  /*
   * Get station, network,frequency, bandwidth, date added, lat, lon, and manufacturer 
   */
  function getStationListSummary(){
    $query = "SELECT
  n.regional_association,n.net, s.site_id,s.sta,s.staname,rf.time latest_radial_file,rf.cfreq
FROM
  site s left join
  network n on s.network_id = n.network_id left join
  ( select distinct rf.site_id,rf.network_id,rf.lat,rf.lon,rf.time,rf.cfreq
    FROM (select site_id,network_id,max(time) time from latest_radialfiles group by site_id,network_id) lrf left join radialfiles rf on 
	  lrf.site_id = rf.site_id and lrf.network_id = rf.network_id and lrf.time = rf.time ) rf on
  s.site_id = rf.site_id and s.network_id = rf.network_id
WHERE
  s.decommissioned = false";


    $rows = $this->db->selectQuery($query);
    return $rows;
  }

  function getNetworkSummary(){
    $query = "select 
  n.net,s.sta,s.site_id,rf.lat,rf.lon,rf.time,s.staname, 
  hd.receiver_chassis_tmp,hd.xmit_fwd_pwr,hd.xmit_ref_pwr,hd.awg_tmp, xmit_chassis_tmp,xmit_amp_tmp,xmit_supply_p28vdc,xmit_supply_p5vdc,transmit_trip, awg_run_time,receiver_supply_p24vdc,mono_css_snr
from
   network n join
   site s on n.network_id = s.network_id join
( select distinct rf.site_id,rf.network_id,rf.lat,rf.lon,rf.time,rf.cfreq,rf.xmit_bandwidth,rf.range_res,rf.spec_range_cells
    FROM (select site_id,network_id,max(time) time from latest_radialfiles group by site_id,network_id) lrf left join radialfiles rf on 
          lrf.site_id = rf.site_id and lrf.network_id = rf.network_id and lrf.time = rf.time ) rf on
   s.site_id = rf.site_id and s.network_id = rf.network_id left join
   latest_hardwarediag lhd on s.network_id = lhd.network_id and s.site_id = lhd.site_id left join
   hardwarediag hd on lhd.network_id = hd.network_id and lhd.site_id = hd.site_id and lhd.time = hd.time LEFT JOIN
   ( select distinct rd.site_id,rd.network_id,rd.mono_css_snr
     FROM (select site_id,network_id,max(time) time from latest_radialdiag group by site_id,network_id) lrd left join radialdiag rd on 
     lrd.site_id = rd.site_id and lrd.network_id = rd.network_id and lrd.time = rd.time ) rd ON
   s.site_id = rd.site_id and s.network_id = rd.network_id
WHERE
   n.net = ? AND
   s.decommissioned = false";
    $rows = $this->db->selectQuery($query,$this->network);
    // Add rad_range to the array return above 
    $query = "select 
  n.net,s.sta,s.site_id,cast(group_concat(concat(rd.rad_range,' (',patterntype,')') separator ',') as char) rad_range
from
   network n join
   site s on n.network_id = s.network_id join
   ( select distinct rd.site_id,rd.network_id,rd.patterntype,rd.rad_range
     FROM (select site_id,network_id,max(time) time from latest_radialdiag group by site_id,network_id) lrd left join 
	 radialdiag rd on lrd.site_id = rd.site_id and lrd.network_id = rd.network_id and lrd.time = rd.time ) rd ON
   s.site_id = rd.site_id and s.network_id = rd.network_id
WHERE n.net = ? AND s.decommissioned = false
group by n.net,s.sta,s.site_id";
    $rd_rows = $this->db->selectQuery($query,$this->network);
    foreach( $rows as $r_index=>$row ){
      $rows[$r_index]["rad_range"] = null;
      if( $rd_rows ){
        foreach( $rd_rows as $rd_index=>$rd_row ){
          if( $row["site_id"] == $rd_row["site_id"] ){
            $rows[$r_index]["rad_range"] = $rd_row["rad_range"];
            break;
          }
        }   
      }
    }

    return $rows;
  }

  function getStationFrequencyList(){
    $query = "select 
  n.net,s.sta,s.staname,rf.cfreq
from
   network n left join
   site s on n.network_id = s.network_id left join
   ( select distinct rf.site_id,rf.network_id,rf.cfreq
    FROM (select site_id,network_id,max(time) time from latest_radialfiles group by site_id,network_id ) lrf left join radialfiles rf on 
          lrf.site_id = rf.site_id and lrf.network_id = rf.network_id and lrf.time = rf.time) rf on
   s.site_id = rf.site_id and s.network_id = rf.network_id
WHERE
  s.decommissioned=false"; 
    $rows = $this->db->selectQuery($query);
    return $rows;
  }
 
  function getNetworkList(){
    $query = "select distinct n.net,s.sta
from
   network n left join
   site s on n.network_id = s.network_id
WHERE
  s.decommissioned=false ORDER BY n.net,s.sta";
    $rows = $this->db->selectQuery($query);
    return $rows;

  }
 
  function getNumberofActiveSites(){
    $query = "select count(*) number from site where decommissioned=0";
    return $this->db->selectQuery($query);

  }

  function getNumberofActiveNetworks(){
    $query = "select count(distinct n.net) number from network n left join site s on n.network_id = s.network_id
WHERE s.decommissioned=false";
    $rows = $this->db->selectQuery($query);
    return $rows;
  }
  
  function getStationLatLonList(){
    $query = "select 
  n.net,s.sta,s.staname,rf.lat,rf.lon
from
   network n left join
   site s on n.network_id = s.network_id left join
   ( select distinct rf.site_id,rf.network_id,rf.lat,rf.lon
    FROM (select site_id,network_id,max(time) time from latest_radialfiles group by site_id,network_id ) lrf left join radialfiles rf on 
          lrf.site_id = rf.site_id and lrf.network_id = rf.network_id and lrf.time = rf.time) rf on
   s.site_id = rf.site_id and s.network_id = rf.network_id
WHERE
  s.decommissioned=false"; 
    $rows = $this->db->selectQuery($query);
    return $rows;
  }
  
  function getStationCountByYear(){
    $query = "select d.date,count(*) total FROM
      (SELECT site_id,FROM_UNIXTIME(min(time),'%Y-%m') as date FROM hfradar.radialfiles group by site_id ) as d
      group by d.date";
    $rows = $this->db->selectQuery($query);
    return $rows;
  }
  
  function getNetworkSiteGrowth(){
    $query = "SELECT date,sum(CASE WHEN region='US' THEN total else 0 END) US,  sum(CASE WHEN region='Inter' THEN total else 0 END) Inter
FROM
(
select d.date,d.region,count(*) as total
FROM
  (SELECT a.site_id, 
       case n.net when 'MEOPAR' then 'Inter' when 'UVIC' then 'Inter' when 'UABC' then 'Inter' when 'KHOA' then 'Inter' else 'US' end as region,
	   min(a.date) as date
   FROM
      (SELECT DISTINCT rf.site_id, rf.network_id, FROM_UNIXTIME(rf.time,'%Y-%m') as date 
       FROM hfradar.radialfiles rf) a LEFT JOIN
      network n ON a.network_id = n.network_id   
   GROUP BY a.site_id, case n.net when 'MEOPAR' then 'Inter' when 'UVIC' then 'Inter' when 'UABC' then 'Inter' when 'KHOA' then 'Inter' else 'US' end
  ) as d
group by d.date,d.region
union
select 
  FROM_UNIXTIME(lrf.latest,'%Y-%m') as date, case n.net when 'MEOPAR' then 'Inter' when 'UVIC' then 'Inter' when 'UABC' then 'Inter' when 'KHOA' then 'Inter' else 'US' end as region, count(*)*-1 as total
FROM
  site s join
  (SELECT site_id, max(time) latest FROM latest_radialfiles group by site_id ) lrf ON
  s.site_id = lrf.site_id left join
  network n on
  s.network_id = n.network_id
WHERE
  decommissioned=true
GROUP BY
FROM_UNIXTIME(lrf.latest,'%Y-%m'), case n.net when 'MEOPAR' then 'Inter' when 'UVIC' then 'Inter' when 'UABC' then 'Inter' when 'KHOA' then 'Inter' else 'US' end
) as alldata
GROUP BY date order by date";
    $rows = $this->db->selectQuery($query);
    return $rows;
  }

  /**
   * Get network site growth data after a specific date
   * Used to query only new data when merging with precalculated baseline
   * @param string $afterDate Date in 'YYYY-MM' format
   * @return array data
   */
  function getNetworkSiteGrowthAfterDate($afterDate){
    $query = "SELECT date,sum(CASE WHEN region='US' THEN total else 0 END) US,  sum(CASE WHEN region='Inter' THEN total else 0 END) Inter
FROM
(
select d.date,d.region,count(*) as total
FROM
  (SELECT a.site_id, 
       case n.net when 'MEOPAR' then 'Inter' when 'UVIC' then 'Inter' when 'UABC' then 'Inter' when 'KHOA' then 'Inter' else 'US' end as region,
	   min(a.date) as date
   FROM
      (SELECT DISTINCT rf.site_id, rf.network_id, FROM_UNIXTIME(rf.time,'%Y-%m') as date 
       FROM hfradar.radialfiles rf) a LEFT JOIN
      network n ON a.network_id = n.network_id   
   GROUP BY a.site_id, case n.net when 'MEOPAR' then 'Inter' when 'UVIC' then 'Inter' when 'UABC' then 'Inter' when 'KHOA' then 'Inter' else 'US' end
  ) as d
group by d.date,d.region
union
select 
  FROM_UNIXTIME(lrf.latest,'%Y-%m') as date, case n.net when 'MEOPAR' then 'Inter' when 'UVIC' then 'Inter' when 'UABC' then 'Inter' when 'KHOA' then 'Inter' else 'US' end as region, count(*)*-1 as total
FROM
  site s join
  (SELECT site_id, max(time) latest FROM latest_radialfiles group by site_id ) lrf ON
  s.site_id = lrf.site_id left join
  network n on
  s.network_id = n.network_id
WHERE
  decommissioned=true
GROUP BY
FROM_UNIXTIME(lrf.latest,'%Y-%m'), case n.net when 'MEOPAR' then 'Inter' when 'UVIC' then 'Inter' when 'UABC' then 'Inter' when 'KHOA' then 'Inter' else 'US' end
) as alldata
GROUP BY date HAVING date > ? order by date";
    $rows = $this->db->selectQuery($query, $afterDate);
    return $rows;
  }

  function getNetworkSiteGrowth2(){
    $query = "SELECT date,sum(CASE WHEN region='US' THEN total else 0 END) US,  sum(CASE WHEN region='Inter' THEN total else 0 END) Inter
FROM
(
select d.date,d.region,count(*) as total
FROM
  (SELECT rf.site_id,case n.net when 'MEOPAR' then 'Inter' when 'UVIC' then 'Inter' when 'UABC' then 'Inter' when 'KHOA' then 'Inter' else 'US' end as region,FROM_UNIXTIME(min(rf.time),'%Y-%m') as date 
FROM hfradar.radialfiles rf LEFT JOIN
network n ON rf.network_id = n.network_id
GROUP BY rf.site_id,case n.net when 'MEOPAR' then 'Inter' when 'UVIC' then 'Inter' when 'UABC' then 'Inter' when 'KHOA' then 'Inter' else 'US' end
 ) as d
group by d.date,d.region
union
select 
  FROM_UNIXTIME(lrf.latest,'%Y-%m') as date, case n.net when 'MEOPAR' then 'Inter' when 'UVIC' then 'Inter' when 'UABC' then 'Inter' when 'KHOA' then 'Inter' else 'US' end as region, count(*)*-1 as total
FROM
  site s left join
  (SELECT site_id, max(time) latest FROM latest_radialfiles group by site_id ) lrf ON
  s.site_id = lrf.site_id left join
  network n on
  s.network_id = n.network_id
WHERE
  decommissioned=true
GROUP BY
FROM_UNIXTIME(lrf.latest,'%Y-%m'), case n.net when 'MEOPAR' then 'Inter' when 'UVIC' then 'Inter' when 'UABC' then 'Inter' when 'KHOA' then 'Inter' else 'US' end
) as alldata
GROUP BY date order by date";
    $rows = $this->db->selectQuery($query);

    return $rows;
  }
  
  function getDiskUsage(){
    $query = "SELECT d.* FROM hfradar.disks d LEFT JOIN hfradar.site s on s.site_id=d.site_id";
    if( $this->site <> null ){
      $query .= " WHERE s.sta=?";
      $rows = $this->db->selectQuery($query,$this->site);
    }
    else {
      $rows = $this->db->selectQuery($query);
    }
    return $rows;
  }

  function customQuery($query){
    $rows = $this->db->selectQuery($query);
    return $rows;
  }
}
