<?php
// Retrieves radial summaries for a given station and time range, outputs GeoJSON points.

require_once '/var/www/lib/diagnostics/lluvfile_s3.php'; // lluvFile class for reading radial files
require_once '/var/www/lib/diagnostics/HFRNetwork.php';

// S3 bucket configuration
$S3_BUCKET    = 'rps-nccf-hfrnet-dissemination-prod';
$LLUV_S3_ROOT = "https://{$S3_BUCKET}.s3.amazonaws.com";

// Default network and station code
$DEFAULT_NET   = 'SIO';
$DEFAULT_STA   = 'SDBP';

// Initialize coverage counts and GeoJSON template
$coverage       = [];
$jsonarray      = ['type' => 'FeatureCollection', 'features' => []];
$totalFileCount = 0;

// Parse HTTP query parameters
parse_str($_SERVER['QUERY_STRING'], $urlargs);
$net       = $urlargs['net']       ?? $DEFAULT_NET;
$sta       = strtoupper($urlargs['sta'] ?? $DEFAULT_STA);
$starttime = isset($urlargs['starttime']) ? (int)$urlargs['starttime'] : time() - 86400; // default: last 24 hours
$endtime   = isset($urlargs['endtime'])   ? (int)$urlargs['endtime']   : time();
$pattern   = strtolower($urlargs['pattern'] ?? 'm');

if (!in_array($pattern, ['m', 'i', 'wera', 'lera'], true)) {
    header('HTTP/1.1 400 Bad Request');
    exit('Invalid pattern');
}

// Filenames come from radialfiles. Listing the month in S3 stops at 1000 keys,
// which drops late-month RDLm files because RDLi names sort first.
$hfr = new HFRNetwork($net, $sta);
if (!$hfr->hasStation()) {
    header('HTTP/1.1 404 Not Found');
    exit("Invalid network/station: {$net}/{$sta}");
}
$hfr->setStartTime($starttime);
$hfr->setEndtime($endtime);
$rows = $hfr->getRadialCoverageFiles($pattern);

foreach ($rows as $row) {
    $path = HFRNetwork::radialObjectPath($row);
    if ($path === '') {
        continue;
    }

    $url = "{$LLUV_S3_ROOT}{$path}";
    try {
        $lluv = new lluvFile($url);
    } catch (NotFoundException $e) {
        continue;
    }
    $datas = $lluv->getLLUVData();

    $totalFileCount++;
    foreach ($datas as $data) {
        $latlon = $data['LATD'] . '/' . $data['LOND'];
        $coverage[$latlon] = ($coverage[$latlon] ?? 0) + 1;
    }
}

foreach ($coverage as $latlon => $count) {
    list($lat, $lon) = explode('/', $latlon);
    $jsonarray['features'][] = [
        'type'       => 'Feature',
        'geometry'   => [
            'type'        => 'Point',
            'coordinates' => [(float)$lon, (float)$lat],
        ],
        'properties' => [
            'weight' => $count / max(1, $totalFileCount),
            'name'   => 'Radials',
        ],
    ];
}

header('Content-Type: application/json');
echo json_encode($jsonarray, JSON_PRETTY_PRINT);
