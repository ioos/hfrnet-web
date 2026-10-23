<?php
// Load helper for geographic calculations
require_once __DIR__ . '/computeEarthPosition.php';
require_once '/var/www/lib/diagnostics/HFRNetwork.php';

// Configuration for S3 bucket and URL root
$S3_BUCKET    = 'rps-nccf-hfrnet-dissemination-prod';
$LLUV_S3_ROOT = "https://{$S3_BUCKET}.s3.amazonaws.com";

// Default values for network, station code, and radial pattern
$DEFAULT_NET     = 'SIO';
$DEFAULT_STA     = 'SDBP';
$DEFAULT_PATTERN = 'm';

// Parse query string into an associative array
parse_str($_SERVER['QUERY_STRING'], $urlargs);

// Apply defaults if parameters are missing
$net     = $urlargs['net']     ?? $DEFAULT_NET;
$sta     = strtoupper($urlargs['sta']     ?? $DEFAULT_STA);
$pattern = strtolower($urlargs['pattern'] ?? $DEFAULT_PATTERN);

// Validate that the requested pattern is supported
if (!in_array($pattern, ['m', 'i', 'wera', 'lera'], true)) {
    header('HTTP/1.1 400 Bad Request');
    exit('Invalid pattern');
}

// The filename comes from latest_radialfiles, the same row the station page
// labels "Most Recent File". Listing the month in S3 stops at 1000 keys, and
// RDLi names sort before RDLm, so that listing drops the real latest measured file.
$hfr = new HFRNetwork($net, $sta);
if (!$hfr->hasStation()) {
    header('HTTP/1.1 404 Not Found');
    exit("No data for station {$sta}");
}
$row = $hfr->getLatestRadialCoverageFile($pattern);
$path = is_array($row) ? HFRNetwork::radialObjectPath($row) : '';
if ($path === '') {
    header('HTTP/1.1 404 Not Found');
    exit("No files matching pattern '{$pattern}'");
}
$fileUrl = "{$LLUV_S3_ROOT}{$path}";

// Read the radial file, skipping empty lines
$lines = @file($fileUrl, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
if (!is_array($lines)) {
    header('HTTP/1.1 502 Bad Gateway');
    exit("Failed to fetch {$fileUrl}");
}

// Default resolution value until header is parsed
$rangeResolution = 0.5;
foreach ($lines as $ln) {
    if (strpos($ln, 'RangeResolutionKMeters') !== false
        && preg_match('/(\d+\.\d+)/', $ln, $m)
    ) {
        $rangeResolution = (float)$m[1];
        break;
    }
}

// Identify column positions by parsing header line
$heads = [];
foreach ($lines as $ln) {
    if (strpos($ln, 'TableColumnTypes') !== false && strpos($ln, 'LOND') !== false) {
        [, $cols] = explode(':', $ln, 2);
        $cols      = preg_split('/\s+/', trim($cols));
        $heads     = array_flip($cols);
        break;
    }
}

// Filter out comment/metadata lines and initialize data structures
$dataRows       = array_filter($lines, fn($ln) => isset($ln[0]) && $ln[0] !== '%');
$coordsMap      = [];
$coverage       = [];
$totalFileCount = 0;

// Process each data row to compute line segments and coverage
foreach ($dataRows as $line) {
    $parts    = preg_split('/\s+/', trim($line));
    $lat1     = (float)$parts[$heads['LATD']];
    $lon1     = (float)$parts[$heads['LOND']];
    $velocity = isset($heads['VELO']) ? (float)$parts[$heads['VELO']] : 0.0;

    // Determine heading: use provided value or calculate from velocity components
    if (isset($heads['HEAD'])) {
        $head = (float)$parts[$heads['HEAD']];
    } else {
        $velu = (float)($parts[$heads['VELU']] ?? 0);
        $velv = (float)($parts[$heads['VELV']] ?? 0);
        $th   = atan2($velv, $velu) * 180 / pi();
        $head = fmod(90 - $th + ($velocity < 0 ? 180 : 0), 360);
    }

    // Calculate radial end point and arrow tips for visualization
    $range = ($velocity / 100 / 0.9) * $rangeResolution;
    list($lat2, $lon2) = computeEarthPosition($lat1, $lon1, $range, $head);
    $arrow1 = computeEarthPosition($lat2, $lon2, 0.25 * $range, $head + 135);
    $arrow2 = computeEarthPosition($lat2, $lon2, 0.25 * $range, $head - 135);

    // Prepare coordinate arrays for GeoJSON MultiLineString
    $from      = [$lon1, $lat1];
    $end       = [$lon2, $lat2];
    $arrowTip1 = [$arrow1[1], $arrow1[0]];
    $arrowTip2 = [$arrow2[1], $arrow2[0]];

    $key = "{$lat1}/{$lon1}";
    $coordsMap[$key][] = [$end, $from];
    $coordsMap[$key][] = [$arrowTip1, $end, $arrowTip2];

    // Count occurrences for weighting if needed
    $coverage[$key] = ($coverage[$key] ?? 0) + 1;
    $totalFileCount++;
}

$json = [
    'type'     => 'FeatureCollection',
    'features' => []
];
foreach ($coordsMap as $segments) {
    $json['features'][] = [
        'type'     => 'Feature',
        'geometry' => [
            'type'        => 'MultiLineString',
            'coordinates' => $segments
        ]
    ];
}

header('Content-Type: application/json');
echo json_encode($json, JSON_PRETTY_PRINT);
