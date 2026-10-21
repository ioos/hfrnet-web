<?php

ob_start();
require_once("/var/www/lib/diagnostics/HFRNetwork.php");
ob_end_clean();

if ($_GET["output"]) {
  $output=$_GET["output"];
  $datenow=new DateTime();
  $timestamp=$datenow->format('Y_m_d_H_i');
  $csvfilename="HFRnet-station-list-".$timestamp.".csv";
  $f=fopen('php://output','w');
  header('Content-Type: application/csv');
  header('Content-Disposition: attachment; filename="'.$csvfilename.'";');
  $columns=array("Station","Station Name","Network","Frequency","Bandwidth","SweepRate","Date Added","Last Radial File","Latitude","Longitude","Manufacturer");
  fputcsv($f,$columns);
} else {
  print "<html>
      <head>
      <title>HFRnet National Network Station List</title>
      <script type=\"text/javascript\" src=\"sortable_us.js\"></script>
    <link rel=\"stylesheet\" type=\"text/css\" href=\"stations.css\"/>
    </head>
    <body>";
}


$hfr = new HFRNetwork();
// Get all the current stations
$query = "select
  n.net,s.sta,s.staname,rf.lat,rf.lon,rf.cfreq,rf.manufacturer,rf.xmit_bandwidth,rf.xmit_sweep_rate
from
   network n left join
   site s on n.network_id = s.network_id left join
   ( select distinct rf.site_id,rf.network_id,rf.lat,rf.lon,rf.cfreq,rf.manufacturer,rf.xmit_bandwidth,rf.xmit_sweep_rate
    FROM (select site_id,network_id,max(time) time from latest_radialfiles group by site_id,network_id ) lrf left join radialfiles rf on
          lrf.site_id = rf.site_id and lrf.network_id = rf.network_id and lrf.time = rf.time) rf on
   s.site_id = rf.site_id and s.network_id = rf.network_id
WHERE
  s.decommissioned=false";
$query = "select
  n.net,s.sta,s.staname,rf.lat,rf.lon,rf.cfreq,rf.manufacturer,rf.xmit_bandwidth,rf.xmit_sweep_rate,firstfile.time
from
   network n left join
   site s on n.network_id = s.network_id left join
   ( select distinct rf.site_id,rf.network_id,rf.lat,rf.lon,rf.cfreq,rf.manufacturer,rf.xmit_bandwidth,rf.xmit_sweep_rate
    FROM (select site_id,network_id,max(time) time from latest_radialfiles group by site_id,network_id ) lrf left join radialfiles rf on
          lrf.site_id = rf.site_id and lrf.network_id = rf.network_id and lrf.time = rf.time) rf on
   s.site_id = rf.site_id and s.network_id = rf.network_id left join
   (SELECT site_id,min(time) as time FROM hfradar.radialfiles group by site_id) firstfile ON
   s.site_id = firstfile.site_id
WHERE
  s.decommissioned=false";
$query = "select
  n.net,s.sta,s.staname,rf.lat,rf.lon,rf.cfreq,rf.manufacturer,rf.xmit_bandwidth,rf.xmit_sweep_rate,firstfile.time,rf.time as 'lasttime'
from
   network n left join
   site s on n.network_id = s.network_id left join
   ( select distinct rf.site_id,rf.network_id,rf.lat,rf.lon,rf.cfreq,rf.manufacturer,rf.xmit_bandwidth,rf.xmit_sweep_rate,rf.time
    FROM (select site_id,max(time) time from latest_radialfiles group by site_id ) lrf left join radialfiles rf on
          lrf.site_id = rf.site_id and lrf.time = rf.time) rf on
   s.site_id = rf.site_id left join
   (SELECT site_id,min(time) as time FROM hfradar.radialfiles group by site_id) firstfile ON
   s.site_id = firstfile.site_id
WHERE
  s.decommissioned=false";
$rows = $hfr->customQuery($query);

// Separate the current stations into west, east, hawaii, and alaska
$west_sites = array();
$east_sites = array();
$hawaii_sites = array();
$alaska_sites = array();
foreach( $rows as $row ){
  if( $row['lon'] < -110 AND $row['lon'] > -135 ){
    $west_sites[] = $row;
  }
  else if( $row['lon'] > -110 ){
    $east_sites[] = $row;
  }
  else if( $row['lon'] < -135 AND $row['lat'] > 50 ){
    $alaska_sites[] = $row;
  }
  else if( $row['lon'] < -150 AND $row['lat'] < 25 ){
    $hawaii_sites[] = $row;
  }
}

# current west coast stations
foreach( $west_sites as $row ){
  $sites=array();
  $sites['sta']=$row['sta'];
  $sites['staname']=$row['staname'];
  $sites['net']=$row['net'];
  $sites['lat']=$row['lat'];
  $sites['lon']=$row['lon'];
  $sites['lasttime']=gmdate('Y-m-d',$row['lasttime']);
  $sites['time']=gmdate('Y-m-d',$row['time']);
  $sites['cfreq']=sprintf('%2.5f',$row['cfreq']);
  $sites['manu']=$row['manufacturer'];
  $sites['bwidth']=$row['xmit_bandwidth'];
  $sites['sweep']=$row['xmit_sweep_rate'];
  $site[]=$sites;
}
asort($site);

if ($output!="CSV") {
  print "
  <a href=#wc>West Coast Stations</a> <a href=#ec>East Coast & GOM Stations</a> <a href=#ak>Alaska</a> <a href=#hi>Hawaii</a><br>
  Click on column to sort<br>
  <a href=".$_SELF."?output=CSV>CLICK HERE FOR CSV</a>
  <table class=\"sortable\" id=\"westcoast\">
  <caption><a name=\"wc\">West Coast</a></caption>
  <tr><th>Station</th><th>Network</th><th>Frequency</th><th>Bandwidth</th><th>Sweep Rate</th><th>Date Added</th><th>Last Radial File</th><th>Latitude</th><th>Longitude</th><th>Manufacturer</th></tr>\n";
  foreach ($site as $line){
    print "<tr><td>".$line['sta']."</td><td>".$line['net']."</td><td>".$line['cfreq']."</td><td>".$line['bwidth']."</td><td>".$line['sweep']."</td><td>".$line['time']."</td><td>".$line['lasttime']."</td><td>".$line['lat']."</td><td>".$line['lon']."</td><td>".$line['manu']."</td></tr>\n";
  }
  print "</table>\n";
} else {
    foreach ($site as $data){
      $line=array($data['sta'],$data['staname'],$data['net'],$data['cfreq'],$data['bwidth'],$data['sweep'],$data['time'],$data['lasttime'],$data['lat'],$data['lon'],$data['manu']);
      fputcsv($f,$line);
    }
}

$site=array();
# current east coast stations
foreach( $east_sites as $row ){
  $sites=array();
  $sites['sta']=$row['sta'];
  $sites['staname']=$row['staname'];
  $sites['net']=$row['net'];
  $sites['lat']=$row['lat'];
  $sites['lon']=$row['lon'];
  $sites['lasttime']=gmdate('Y-m-d',$row['lasttime']);
  $sites['time']=gmdate('Y-m-d',$row['time']);
  $sites['cfreq']=sprintf('%2.5f',$row['cfreq']);
  $sites['manu']=$row['manufacturer'];
  $sites['bwidth']=$row['xmit_bandwidth'];
  $sites['sweep']=$row['xmit_sweep_rate'];
  $site[]=$sites;
}
asort($site);
if ($output!="CSV"){
  print "<p></p>
  <table class=\"sortable\" id=\"eastcoast\">
  <caption><a name=\"ec\">East Coast and Gulf of Mexico</a></caption>
  <tr><th>Station</th><th>Network</th><th>Frequency</th><th>Bandwidth</th><th>Sweep Rate</th><th>Date Added</th><th>Last Radial File</th><th>Latitude</th><th>Longitude</th><th>Manufacturer</th></tr>\n";
  foreach ($site as $line){
    print "<tr><td>".$line['sta']."</td><td>".$line['net']."</td><td>".$line['cfreq']."</td><td>".$line['bwidth']."</td><td>".$line['sweep']."</td><td>".$line['time']."</td><td>".$line['lasttime']."</td><td>".$line['lat']."</td><td>".$line['lon']."</td><td>".$line['manu']."</td></tr>\n";
  }
  print "</table>\n";
} else {
  foreach ($site as $data){
    $line=array($data['sta'],$data['staname'],$data['net'],$data['cfreq'],$data['bwidth'],$data['sweep'],$data['time'],$data['lasttime'],$data['lat'],$data['lon'],$data['manu']);
    fputcsv($f,$line);
  }
}

$site=array();
# current alaska stations
foreach( $alaska_sites as $row ){
  $sites=array();
  $sites['sta']=$row['sta'];
  $sites['staname']=$row['staname'];
  $sites['net']=$row['net'];
  $sites['lat']=$row['lat'];
  $sites['lon']=$row['lon'];
  $sites['lasttime']=gmdate('Y-m-d',$row['lasttime']);
  $sites['time']=gmdate('Y-m-d',$row['time']);
  $sites['cfreq']=sprintf('%2.5f',$row['cfreq']);
  $sites['manu']=$row['manufacturer'];
  $sites['bwidth']=$row['xmit_bandwidth'];
  $sites['sweep']=$row['xmit_sweep_rate'];
  $site[]=$sites;
}
asort($site);
if ($output!="CSV"){
  print "<p></p>
  <table class=\"sortable\" id=\"alaska\">
  <caption><a name=\"ak\">Alaska</a></caption>
  <tr><th>Station</th><th>Network</th><th>Frequency</th><th>Bandwidth</th><th>Sweep Rate</th><th>Date Added</th><th>Last Radial File</th><th>Latitude</th><th>Longitude</th><th>Manufacturer</th></tr>\n";
  foreach ($site as $line){
    print "<tr><td>".$line['sta']."</td><td>".$line['net']."</td><td>".$line['cfreq']."</td><td>".$line['bwidth']."</td><td>".$line['sweep']."</td><td>".$line['time']."</td><td>".$line['lasttime']."</td><td>".$line['lat']."</td><td>".$line['lon']."</td><td>".$line['manu']."</td></tr>\n";
  }
  print "</table>\n";
} else {
  foreach ($site as $data){
    $line=array($data['sta'],$data['staname'],$data['net'],$data['cfreq'],$data['bwidth'],$data['sweep'],$data['time'],$data['lasttime'],$data['lat'],$data['lon'],$data['manu']);
    fputcsv($f,$line);
  }
}

$site=array();
# current hawaii stations
foreach( $hawaii_sites as $row ){
  $sites=array();
  $sites['sta']=$row['sta'];
  $sites['staname']=$row['staname'];
  $sites['net']=$row['net'];
  $sites['lat']=$row['lat'];
  $sites['lon']=$row['lon'];
  $sites['lasttime']=gmdate('Y-m-d',$row['lasttime']);
  $sites['time']=gmdate('Y-m-d',$row['time']);
  $sites['cfreq']=sprintf('%2.5f',$row['cfreq']);
  $sites['manu']=$row['manufacturer'];
  $sites['bwidth']=$row['xmit_bandwidth'];
  $sites['sweep']=$row['xmit_sweep_rate'];
  $site[]=$sites;
}
asort($site);
if ($output!="CSV"){
  print "<p></p>
  <table class=\"sortable\" id=\"hawaii\">
  <caption><a name=\"hi\">Hawaii</a></caption>
  <tr><th>Station</th><th>Network</th><th>Frequency</th><th>Bandwidth</th><th>Sweep Rate</th><th>Date Added</th><th>Last Radial File</th><th>Latitude</th><th>Longitude</th><th>Manufacturer</th></tr>\n";
  foreach ($site as $line){
    print "<tr><td>".$line['sta']."</td><td>".$line['net']."</td><td>".$line['cfreq']."</td><td>".$line['bwidth']."</td><td>".$line['sweep']."</td><td>".$line['time']."</td><td>".$line['lasttime']."</td><td>".$line['lat']."</td><td>".$line['lon']."</td><td>".$line['manu']."</td></tr>\n";
  }
  print "</table>\n";
} else {
  foreach ($site as $data){
    $line=array($data['sta'],$data['staname'],$data['net'],$data['cfreq'],$data['bwidth'],$data['sweep'],$data['time'],$data['lasttime'],$data['lat'],$data['lon'],$data['manu']);
    fputcsv($f,$line);
  }
}
# retired stations
$sql="select * from site where endtime is not null";

?>
