<?php
 /**
  * Computes the target lat/lon coordinate when provided a
  * starting lat/lon and a bearing (in true degrees from the north pole).
  *   Units is a defined constant of:
  *   KILOMETERS, METERS, YARDS, FEET, MILES, KNOTS
  *
  * @usage computeEarthDistance(lat1,lon1,lat2,lon2,units);
  * 
  * @file computeEarthPosition.php
  * @date 2007-01-16 23:40 PST
  * @author Paul Reuter
  * @version 1.0
  */


  /**#@+*/
  if(!defined("EARTH_RADIUS")) { 
    // Avg great-circle radius (km), wikipedia
    define("EARTH_RADIUS"   , 6372.795);

    define("EARTH_RADIUS_KILOMETERS", EARTH_RADIUS             );
    define("EARTH_RADIUS_MILES"     , EARTH_RADIUS*0.621371192 );
    define("EARTH_RADIUS_KNOTS"     , EARTH_RADIUS*0.539956803 );
    define("EARTH_RADIUS_METERS"    , EARTH_RADIUS*1000        );
    define("EARTH_RADIUS_YARDS"     , EARTH_RADIUS*1093.6133   );
    define("EARTH_RADIUS_FEET"      , EARTH_RADIUS*3280.8399   );
  }
  /**#@-*/


 /**
  * Computes the target coordinate referenced by lat/lon + distance
  *   in some bearing angle.
  * @param float $lat1 Latitude of point 
  * @param float $lon1 Longitude of point 
  * @param float $dist Distance to travel from point
  * @param float $bear Bearing in decimal degrees, from North, 90=East.
  * @param float $R Assumed earth radius, default=6372.795km
  * @return array of decimal degrees (lat,lon)
  */
 function computeEarthPosition($lat1,$lon1,$dist,$bear,$R=EARTH_RADIUS_KILOMETERS) {
   $lat1 = deg2rad($lat1);
   $lon1 = deg2rad($lon1);
   $Az   = deg2rad($bear);
   $c    = $dist/$R;

   /* Lightly tested */
   $lat2 = asin( sin($lat1)*cos($c) + cos($lat1)*sin($c)*cos($Az) );
   $t1 = sin($c)*sin($Az);
   $t2 =  cos($lat1)*cos($c) - sin($lat1)*sin($c)*cos($Az);
   $lon2 = $lon1 + atan2( $t1 , $t2 );

   return array(rad2deg($lat2),rad2deg($lon2));
 }

?>
