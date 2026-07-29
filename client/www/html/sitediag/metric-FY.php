<html>
<head>
<title>HFRnet National Network Metric</title>

<script type="text/javascript" src="https://ajax.googleapis.com/ajax/libs/jquery/1.11.1/jquery.min.js"></script>
<script type="text/javascript" src="https://ajax.googleapis.com/ajax/libs/jqueryui/1.11.4/jquery-ui.min.js"></script>
<script type="text/javascript" src="https://cdn.datatables.net/1.10.12/js/jquery.dataTables.js"></script>

<script type="text/javascript" src="sorttable.js"></script>
<script type="text/javascript" src="ztable.js"></script>

<link rel="stylesheet" href="https://ajax.googleapis.com/ajax/libs/jqueryui/1.11.4/themes/smoothness/jquery-ui.css" />
<link rel="stylesheet" href="https://cdn.datatables.net/1.10.12/css/jquery.dataTables.css" type="text/css"/>

<link rel="stylesheet" type="text/css" href="stations.css"/>
<script type="text/javascript">
  $(document).ready(function() {
    var table = $('#ra_metric_fy').DataTable({
      "paging": false,
      "searching": false,
      "info"  : false});

    // highlight the current quarter
    var d = new Date(), n=d.getMonth();
    var q;
    if( n >= 0 && n <= 2 ){
      q=2;
    }
    else if (n >= 3 && n <= 5){
      q=3;
    }
    else if (n >= 6 && n <= 8){
      q=4;
    }
    else {
      q=1;
    }
    $( table.column( q ).nodes() ).addClass('highlight');
    //$( table.column( q+1 ).nodes() ).addClass('highlight');
    
  } );
</script>
<style type="text/css">
   .highlight{ background-color: orange; }
   #ra_metric_fy_wrapper{ width: 500px; margin: 10px 0 50px 0;}
   #color10 {background: #f00;}
   #color20 {background: #f40;}
   #color30 {background: #f80;}
   #color40 {background: #fa0;}
   #color50 {background: #fc0;}
   #color60 {background: #cf0;}
   #color70 {background: #af0;}
   #color80 {background: #8f0;}
   #color90 {background: #4f0;}
   #color100 {background: #0f0;}
</style>
</head>
<body>
<?php
function FY($year,$mon){
  if ($mon < 10){
     $fy = $year;
  } else {
     $fy = $year+1;
  }
  return $fy;
}
require_once("/var/www/lib/diagnostics/mySQL_DB.php");
const DB_INI = "/var/www/lib/diagnostics/hfrnet_db.ini";
$all_ini = parse_ini_file(DB_INI, true);
$db_ini = isset($all_ini['metrics']) ? $all_ini['metrics'] : $all_ini;
$db = new mySQL_DB($db_ini["server"],$db_ini["user"],$db_ini["password"],$db_ini["db"],$db_ini["port"]);
$self=$_SERVER['PHP_SELF'];

# DO regions, regional associations

# coasts vs regionalAssociation
if (! isset($_GET['type']) || $_GET['type']=='ra' ) {  
  $sql="SELECT DISTINCT ra FROM regionalAssociations";
  $type='ra';
  $rows=$db->selectQuery($sql);
  foreach ($rows as $row){
   $regions[]=$row[$type];
   $alias[]=$row[$type];
  }
}
elseif ($_GET['type']=='coasts'){
  $type='coasts';
  $regions=array('westcoast','eastcoast');
  $alias=array('West Coast','East and Gulf Coasts');
}
 
/*
  if ($_GET['type']=='coasts'){
    $type='coasts';
    $regions=array('westcoast','eastcoast');
    $alias=array('West Coast','East and Gulf Coasts');
  } elseif ($_GET['type']=='ra' || $_GET['type']=='') {  
    $sql="SELECT DISTINCT ra FROM regionalAssociations";
    $res=mysql_query($sql);
    $type='ra';
    while ($row=mysql_fetch_array($res)){
     $regions[]=$row[0];
     $alias[]=$row[0];
    }
  }
*/

# Handle years
# select year
$sql="SELECT DISTINCT YEAR(date) FROM metricUptime";
$sql="select case when month(date)>=10 then year(date)+1  else year(date) end as fy From metricUptime group by fy";
$rows=$db->selectQuery($sql);
foreach ($rows as $row){
  $years[]=$row['fy'];
}
arsort($years);
print "Pick a year: ";
foreach ($years as $year){
  print "<a href=$self?year=$year&type=$type>$year</a> ";
}
print "<br>";

# use current year unless given
$mon=date("n");
if (isset($_GET['year'])){
   $year=$_GET['year'];
} else {
   $year=date("Y");
}

# Print all stations or hide (default)
if( isset($_GET['stations']) ){
  if ($_GET['stations']=="show"){
    $stations=1;
  } else {
     $stations=0;
  }
}
else {
  $stations=0;
}



##Print out page
$num=0;
$allsum=0;
$q1sum=0;
$q2sum=0;
$q3sum=0;
$q4sum=0;
for ($i=0;$i<count($regions);$i++){
  list($filesgood,$filesposs,$sites,$html[$i])=printTable($regions[$i],$alias[$i],$year,$db);
  //$perc[$i]=sprintf("%01d%%",$filesgood[0]/$filesposs[0]*100);
  //$q1[$i]=sprintf("%01d%%",$filesgood[1]/$filesposs[1]*100);
  //$q2[$i]=sprintf("%01d%%",$filesgood[2]/$filesposs[2]*100);
  //$q3[$i]=sprintf("%01d%%",$filesgood[3]/$filesposs[3]*100);
  //$q4[$i]=sprintf("%01d%%",$filesgood[4]/$filesposs[4]*100);
  $perc[$i]=($filesposs[0]==0) ? 0 : sprintf("%01d%%",$filesgood[0]/$filesposs[0]*100);
  $q1[$i]=($filesposs[1]==0) ? 0 : sprintf("%01d%%",$filesgood[1]/$filesposs[1]*100);
  $q2[$i]=($filesposs[2]==0) ? 0 : sprintf("%01d%%",$filesgood[2]/$filesposs[2]*100);
  $q3[$i]=($filesposs[3]==0) ? 0 : sprintf("%01d%%",$filesgood[3]/$filesposs[3]*100);
  $q4[$i]=($filesposs[4]==0) ? 0 : sprintf("%01d%%",$filesgood[4]/$filesposs[4]*100);
  if ($perc[$i]>0){
   //$allsum+=$filesgood[0]/$filesposs[0]*100;
   $allsum+=( $filesposs[0]==0 ) ? 0 : $filesgood[0]/$filesposs[0]*100;
   //$q1sum+=$filesgood[1]/$filesposs[1]*100;
   $q1sum+=( $filesposs[1]==0 ) ? 0 : $filesgood[1]/$filesposs[1]*100;
   //$q2sum+=$filesgood[2]/$filesposs[2]*100;
   $q2sum+=( $filesposs[2]==0 ) ? 0 : $filesgood[2]/$filesposs[2]*100;
   //$q3sum+=$filesgood[3]/$filesposs[3]*100;
   $q3sum+=( $filesposs[3]==0 ) ? 0 : $filesgood[3]/$filesposs[3]*100;
   //$q4sum+=$filesgood[4]/$filesposs[4]*100;
   $q4sum+=( $filesposs[4]==0 ) ? 0 : $filesgood[4]/$filesposs[4]*100;
   $num++;
  }
}

//print "</div>";


$colq1="<col>";
$colq2="<col>";
$colq3="<col>";
$colq4="<col>";
 

switch (true)
{
case($mon>=10 && $year==date('Y')):
 $colq1="<col style=\"background-color: orange;\">";
case($mon<=3 && $year==date('Y')):
 $colq2="<col style=\"background-color: orange;\">";
case($mon>=4 && $mon<=6 && $year==date('Y')):
 $colq3="<col style=\"background-color: orange;\">";
case($mon>=7 && $mon<=9 && $year==date('Y')):
 $colq4="<col style=\"background-color: orange;\">";
}

#<table style=\"position:absolute;top:-150px;\" border=1 cellpadding=4>
print "
<table id='ra_metric_fy' style='width:400px'>
<!--
<col>
$colq1
$colq2
$colq3
$colq4
<col>
-->
<thead><tr><th>Location</th><th>Q1</th><th>Q2</th><th>Q3</th><th>Q4</th><th>FY</th></tr></thead>
<tbody>
";

for ($i=0;$i<count($regions);$i++){
  if ($perc[$i]>0){
    if ($_SERVER['QUERY_STRING']){
      print "<tr><td><a href=".$_SERVER['REQUEST_URI']."&stations=show#$regions[$i]>$regions[$i]</a></td><td>$q1[$i]</td><td>$q2[$i]</td><td>$q3[$i]</td><td>$q4[$i]</td><td>$perc[$i]</td></tr>";
    } else {
      print "<tr><td><a href=".$_SERVER['REQUEST_URI']."?stations=show#$regions[$i]>$regions[$i]</a></td><td>$q1[$i]</td><td>$q2[$i]</td><td>$q3[$i]</td><td>$q4[$i]</td><td>$perc[$i]</td></tr>";
    }
  }
}

$allQ1=sprintf("%01d%%",$q1sum/$num);
$allQ2=sprintf("%01d%%",$q2sum/$num);
$allQ3=sprintf("%01d%%",$q3sum/$num);
$allQ4=sprintf("%01d%%",$q4sum/$num);
$allperc=sprintf("%01d%%",$allsum/$num);

print
"</tbody><tfoot><tr><th>All</th><th>$allQ1</th><th>$allQ2</th><th>$allQ3</th><th>$allQ4</th><th>$allperc</th></tr></tfoot>
</table>
";


print
"
<h4>High Frequency (HF) Radar National Network (HFRNet) Performance Metric</h4>
<p>
The performance metric is based on uptime of the U.S. array over a 12 month fiscal cycle (October through September) or otherwise defined reporting cycles (e.g. quarterly cycle).  Currently, uptime is reported quarterly, but updated daily for the current quarter.  Uptime is defined as the percentage of time NOAA IOOS funded radars meet a pre-defined threshold of data availability for the reported time period.
</p>

<p>
A radar site is considered “Up” if its hourly radial file arrives at the national server within 25 hours of its time stamp and if the number of radial velocity data points (aka “solutions”) is 300 or more.  Then, the number of “up” hours during the month is divided by the total number of possible hours for that month (e.g., a 30-day month has 720 possible hours).  For example, if a site sends 631 hourly files on-time with at least 300 data points for November, that site’s uptime would be 87.6%.  Within each radar site’s diagnostic page, accessible from the main diagnostic web page at https://hfradar.ioos.us/hfrnet/diagnostics/, is an indication of “ % available”.  This number will usually be larger than the uptime for any time period because the file does not need to meet the thresholds, mentioned above, for this designation.</p>

<p>
Above, all radars’ uptime for each Regional Association are averaged together to give the values in the table.</p>
<p>
Calculation: % Uptime = # of Operational Radial Files/# of Hours in reporting period </p> 
<p>
The percent uptime is calculated by dividing the number of operational radial files reported to the HFRNet by the total number of hours in a given reporting period.  An operational radial file is defined as an HF radar data file where the number of observed radial solutions meets or exceeds a nominal number of radial solutions (300) and the file was reported within (25) hours of the observation. </p> 
<p>
Note:  there are a small number of sites (about 6) with topographic barriers to the transmitted radar signal cause a diminished coverage area such that the site would never be able to reach 300 points per hour.  These sites must only meet a 200 point threshold.  There is an even smaller set of sites (currently only 4) having severe limitations whose threshold is only 50 points.
</p>

<p>
For more details see: <a href='https://hfradar.ioos.us/hfrnet/documents/IOOS_metrics_HFR_201801.pdf'>High Frequency (HF) Radar National Network (HFRNet) Performance Metric</a>
</p>

";
if ($stations==1){
  print "Click on column to sort<br>";
  for ($i=0;$i<count($regions);$i++){
    print $html[$i];
  }
}

function printTable($coast,$proper,$year,$db){
$sites=array();  
# current coast stations
if ($coast=='eastcoast'){
  $sql="SELECT DISTINCT site,affiliation,lat from metricUptime WHERE lon>=-110 AND lat>0 AND DATE(date) BETWEEN DATE( CONCAT( -1+$year, '-10-01' )) AND DATE( CONCAT( $year, '-09-30' )) ORDER BY lat ASC";
} elseif ($coast=='westcoast') {
  $sql="SELECT DISTINCT site,affiliation,lat from metricUptime WHERE lon<-110 AND DATE(date) BETWEEN DATE( CONCAT( -1+$year, '-10-01' )) AND DATE( CONCAT( $year, '-09-30' )) ORDER BY lat ASC";
} else {
  $sql="SELECT DISTINCT site,metricUptime.affiliation,regionalAssociations.ra,lat from metricUptime LEFT JOIN regionalAssociations ON metricUptime.affiliation=regionalAssociations.affiliation WHERE regionalAssociations.ra='$coast' AND DATE(date) BETWEEN DATE( CONCAT( -1+$year, '-10-01' )) AND DATE( CONCAT( $year, '-09-30' )) ORDER BY lat ASC";
}
$rows=$db->selectQuery($sql);

if (!is_array($rows)) {
  $rows = [];
}

foreach ($rows as $row){
   $sites[]=array('site'=>$row{'site'},'affiliation'=>$row{'affiliation'});
}

if (count($sites)==0){
  return;
}
$html= "<table class=\"sortable\" id=\"$coast\">
<caption><a name=\"$coast\">$proper</a></caption>
<tr><th width=50px>Station</th><th>Network</th><th>Latitude</th><th>Longitude</th><th>Frequency</th>";
$sql="SELECT DISTINCT date from metricUptime WHERE DATE(date) BETWEEN DATE( CONCAT( -1+$year
, '-10-01' )) AND DATE( CONCAT( $year, '-09-30' )) ORDER BY date";
$rows=$db->selectQuery($sql);
foreach ($rows as $row){
   $date=strftime('%Y-%m',strtotime($row['date']));
   $times[]=$row['date'];
   $html.= "<th class=\"sorttable_numeric\">$date</th>";
}
$html.= "<th class=\"sorttable_numeric\">TOTAL</th>";
$html.= "</tr>";
$ii=0;
$allTotalFiles=array();
$allTotalFiles[0] = 0;
$allTotalFiles[1] = 0;
$allTotalFiles[2] = 0;
$allTotalFiles[3] = 0;
$allTotalFiles[4] = 0;
$allTotalPoss=array();
$allTotalPoss[0] = 0;
$allTotalPoss[1] = 0;
$allTotalPoss[2] = 0;
$allTotalPoss[3] = 0;
$allTotalPoss[4] = 0;

foreach ($sites as $site){
  $sql="SELECT lat,lon,freq FROM metricUptime WHERE site='".$site{'site'}."' AND affiliation='".$site{'affiliation'}. "' and DATE(date) BETWEEN DATE( CONCAT( -1+$year, '-10-01' )) AND DATE( CONCAT( $year, '-09-30' )) LIMIT 0,1";
  $row=$db->selectQuery($sql);
  $lon=$row{'lon'};
  $lat=$row{'lat'};
  $freq=$row{'freq'};
#find out if there are more than one beam pattern
  $sql="SELECT DISTINCT type FROM metricUptime where site='".$site{'site'}."' AND affiliation='".$site{'affiliation'}. "' and DATE(date) BETWEEN DATE( CONCAT( -1+$year, '-10-01' )) AND DATE( CONCAT( $year, '-09-30' ))";
  $rows=$db->selectQuery($sql);
  $types=array();
  $numPatt=0;
  foreach($rows as $row){
     $ii=$ii+1;
     $imod=$ii%2;
     if ($imod==0){
       $html.= "<tr class=\"odd\"><td class=\"site\">".$site{'site'}." <br>". $row['type']."</td><td>".$site{'affiliation'}."</td><td>$lat</td><td>$lon</td><td>$freq</td>";
     } else {
       $html.= "<tr class=\"even\"><td class=\"site\">".$site{'site'}." <br>". $row['type']."</td><td>".$site{'affiliation'}."</td><td>$lat</td><td>$lon</td><td>$freq</td>";
     }
     $totFiles[$numPatt]=array();
     $totFiles[$numPatt][0] = 0;
     $totFiles[$numPatt][1] = 0;
     $totFiles[$numPatt][2] = 0;
     $totFiles[$numPatt][3] = 0;
     $totFiles[$numPatt][4] = 0;
     $totPoss[$numPatt]=array();
     $totPoss[$numPatt][0] = 0;
     $totPoss[$numPatt][1] = 0;
     $totPoss[$numPatt][2] = 0;
     $totPoss[$numPatt][3] = 0;
     $totPoss[$numPatt][4] = 0;

     $filesperday=0;
     $theoObs=0;
     foreach ($times as $time){
# loop through month by month and count the number of good files
        $sql="SELECT date,numObs,theoObs FROM metricUptime WHERE site='".$site{'site'}."' AND affiliation='".$site{'affiliation'}. "' and YEAR(date)=YEAR('$time') AND MONTH(date)=MONTH('$time') AND type='".$row['type']."'";
        $mm=intval(substr($time,5,2));
        $yy=intval(substr($time,0,4));
        $rrow=$db->selectQuery($sql);
        if ($mm==date('n') && $yy==date('Y')){
          $daysinmonth=date('j');
        } else {
          $daysinmonth=date('t', mktime(0, 0, 0, $mm, 1, $year));
        }
        if ($rrow[0]['date']){
# There is data available for this month
          if ($filesperday==0){
            $filesperday=$rrow[0]{'theoObs'}/date('t', mktime(0, 0, 0, $mm, 1, $year));
          } else {
            $filesperday=min($filesperday,$rrow[0]{'theoObs'}/$daysinmonth);
          }
          $theoObs=$filesperday*$daysinmonth;
          $perc=sprintf("%01.2f",$rrow[0]{'numObs'}/$theoObs*100);
          $fperc=ceil($perc/10)*10;
          $fcolor='color'.$fperc;
          if ($mm>=10){
             $totFiles[$numPatt][1]=$totFiles[$numPatt][1]+$rrow[0]{'numObs'};
             $totPoss[$numPatt][1]=$totPoss[$numPatt][1]+$theoObs;
          } elseif ($mm<=3){
             $totFiles[$numPatt][2]=$totFiles[$numPatt][2]+$rrow[0]{'numObs'};
             $totPoss[$numPatt][2]=$totPoss[$numPatt][2]+$theoObs;
          } elseif ($mm>=4 && $mm<=6){
             $totFiles[$numPatt][3]=$totFiles[$numPatt][3]+$rrow[0]{'numObs'};
             $totPoss[$numPatt][3]=$totPoss[$numPatt][3]+$theoObs;
          } elseif ($mm>=7 && $mm<9){
             $totFiles[$numPatt][4]=$totFiles[$numPatt][4]+$rrow[0]{'numObs'};
             $totPoss[$numPatt][4]=$totPoss[$numPatt][4]+$theoObs;
          }
          $totFiles[$numPatt][0]=$totFiles[$numPatt][0]+$rrow[0]{'numObs'};
          $totPoss[$numPatt][0]=$totPoss[$numPatt][0]+$theoObs;
          $html.= "<td id=\"$fcolor\" sorttable_customkey=\"$perc\">$perc %<br>".$rrow[0]{'numObs'}." / ".$theoObs."</td>"; 
        } elseif ($yy==2017 && $mm>=1 && $site{'affiliation'}=="SFSU") {
/* Special condition to handle SFSU to CODAR switchover
*/ 
          $html.= "<td>&nbsp</td>";
         
        } else {
# There is no data available for this month
# still calculate total possible files
          $html.= "<td>&nbsp</td>";

          $totPoss[$numPatt][0]=$totPoss[$numPatt][0]+($filesperday*$daysinmonth);
          if ($mm>=10){
             $totPoss[$numPatt][1]=$totPoss[$numPatt][1]+($filesperday*$daysinmonth);
          } elseif ($mm<=3){
             $totPoss[$numPatt][2]=$totPoss[$numPatt][2]+($filesperday*$daysinmonth);
          } elseif ($mm>=4 && $mm<=6){
             $totPoss[$numPatt][3]=$totPoss[$numPatt][3]+($filesperday*$daysinmonth);
          } elseif ($mm>=7 && $mm<9){
             $totPoss[$numPatt][4]=$totPoss[$numPatt][4]+($filesperday*$daysinmonth);
          }
        }
     }
     $totPerc=sprintf("%01.2f",$totFiles[$numPatt][0]/$totPoss[$numPatt][0]*100);
     $tcolor='color'.ceil($totPerc/10)*10;
     $html.= "<td id=\"$tcolor\" sorttable_customkey=\"$totPerc\">$totPerc %<br>".$totFiles[$numPatt][0]." / ".$totPoss[$numPatt][0]."</td>";
     $html.= "</tr>";
     $numPatt+=1;
  }
  if ($numPatt>1){
     $Q1Files=($totFiles[0][1]>$totFiles[1][1]) ? $totFiles[0][1]:$totFiles[1][1];
     $Q1Poss=($totFiles[0][1]>$totFiles[1][1]) ? $totPoss[0][1]:$totPoss[1][1];
     $Q2Files=($totFiles[0][2]>$totFiles[1][2]) ? $totFiles[0][2]:$totFiles[1][2];
     $Q2Poss=($totFiles[0][2]>$totFiles[1][2]) ? $totPoss[0][2]:$totPoss[1][2];
     $Q3Files=($totFiles[0][3]>$totFiles[1][3]) ? $totFiles[0][3]:$totFiles[1][3];
     $Q3Poss=($totFiles[0][3]>$totFiles[1][3]) ? $totPoss[0][3]:$totPoss[1][3];
     $Q4Files=($totFiles[0][4]>$totFiles[1][4]) ? $totFiles[0][4]:$totFiles[1][4];
     $Q4Poss=($totFiles[0][4]>$totFiles[1][4]) ? $totPoss[0][4]:$totPoss[1][4];
     $siteFiles=($totFiles[0][0]>$totFiles[1][0]) ? $totFiles[0][0]:$totFiles[1][0];
     $sitePoss=($totFiles[0][0]>$totFiles[1][0]) ? $totPoss[0][0]:$totPoss[1][0];
  } else {
     $siteFiles=$totFiles[0][0];
     $sitePoss=$totPoss[0][0];
     $Q1Files=$totFiles[0][1];
     $Q1Poss=$totPoss[0][1];
     $Q2Files=$totFiles[0][2];
     $Q2Poss=$totPoss[0][2];
     $Q3Files=$totFiles[0][3];
     $Q3Poss=$totPoss[0][3];
     $Q4Files=$totFiles[0][4];
     $Q4Poss=$totPoss[0][4];
  }
  $allTotalFiles[0]=$allTotalFiles[0]+$siteFiles;
  $allTotalPoss[0]=$allTotalPoss[0]+$sitePoss;
  $allTotalFiles[1]=$allTotalFiles[1]+$Q1Files;
  $allTotalPoss[1]=$allTotalPoss[1]+$Q1Poss;
  $allTotalFiles[2]=$allTotalFiles[2]+$Q2Files;
  $allTotalPoss[2]=$allTotalPoss[2]+$Q2Poss;
  $allTotalFiles[3]=$allTotalFiles[3]+$Q3Files;
  $allTotalPoss[3]=$allTotalPoss[3]+$Q3Poss;
  $allTotalFiles[4]=$allTotalFiles[4]+$Q4Files;
  $allTotalPoss[4]=$allTotalPoss[4]+$Q4Poss;
}
return array($allTotalFiles,$allTotalPoss,count($sites),$html);
}
?>
