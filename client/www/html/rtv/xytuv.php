<?php
/**
 * Generate JSON object of x,y,t + u, v hfradar data.
 */

ini_set("memory_limit","1024M");
define("DATA_DIR","/exports/hfradar/hfrnet/hfrtv");

function listRegions() {
  return array('AKNS','GAK','PRVI','USEGC','USHI','USWC');
  //return array('AKNS','CA-OSPR','GAK','PRVI','SKOR','USEGC','USHI','USWC');
}

function timeRegionResolutionToFile($ts,$region,$resolution,$pfx='') {
  return sprintf(
    "%s/%s/%s/ascii/rtv%s_%s_%s_uwls_%s.asc",
    DATA_DIR, strtoupper($region), gmdate("Y_m",$ts), $pfx,
    strtolower($region), strtolower($resolution), gmdate("Y_m_d_Hi",$ts)
  );
}

function getPrefix() {
  return '-25hrAvg';
}

function getResolution() {
  return '6km';
}

function getPointsFromFile($fpath) {
  $mat = array();
  foreach(explode("\n",rtrim(file_get_contents($fpath),"\n")) as $line) {
    $row = preg_split('/\s+/',trim($line),-1,PREG_SPLIT_NO_EMPTY);
    if( !empty($row) ){
      $mat[] = array_map('floatVal',$row);
    }
  }
  return $mat;
}

function main() {
  $ts = time() + 3600;
  $ts -= $ts%3600;
  $tries = 168;
  $resolution = getResolution();
  $prefix = getPrefix();

  // Find most recent time where both East and West coast have data.
  do {
    $ts -= 3600;
    $uswc = timeRegionResolutionToFile($ts, 'USWC', $resolution, $prefix);
    $usegc = timeRegionResolutionToFile($ts, 'USEGC', $resolution, $prefix);
  } while( --$tries > 0 && !(is_readable($usegc) && is_readable($uswc)) );

  // build time[]
  $times = array();
  $ti = $ts;
  for($i=0; $i<25; $i++) {
    $times[] = $ti;
    $ti -= 3600;
  }
    
  //$tmpl = array_fill(0,count($times),null);
  $tmpl = array_fill(0,count($times),0);
  $ipoints = array();
  $points = array();

  for($i=0; $i<25; $i++) {
    foreach( listRegions() as $region ) {
      $fpath = timeRegionResolutionToFile($ts, $region, $resolution, $prefix);
      if( !is_readable($fpath) ) {
        continue;
      }
      //array_splice($obj['points'], -1, 0, getPointsFromFile($fpath));
      foreach( getPointsFromFile($fpath) as $row ) {
        list($y, $x, $u, $v) = $row;
        $k = sprintf("%f|%f",$y,$x);
        if( !isset($ipoints[$k]) ) {
          $ix = count($ipoints);
          $points[$ix] = array('x'=>$x,'y'=>$y,'u'=>$tmpl,'v'=>$tmpl);
          $ipoints[$k] = $ix;
        }
        $points[$ipoints[$k]]['u'][$i] = $u;
        $points[$ipoints[$k]]['v'][$i] = $v;
      }
    }
    $ts -= 3600;
  }

  $obj = array(
    'resolution' => $resolution, 
    'hourly' => ($prefix==''),
    'averaged' => ($prefix!=''),
    'n_times' => count($times),
    'n_points' => count($points),
    'times' => $times,
    'points' => $points
  );
  echo(json_encode($obj));
}

main();
?>
