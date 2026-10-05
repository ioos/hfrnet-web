<?php
$subdirectory = getenv('SUBDIRECTORY');
if ($subdirectory === false || $subdirectory === '') {
    $subdirectory_path = '';
} else {
    $subdirectory_path = "/{$subdirectory}";
}
?>

<!DOCTYPE html>
<html lang='en'>
<head>
  <title>HFRNet</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta charset="utf-8">
  <link rel="stylesheet" href="https://ajax.googleapis.com/ajax/libs/jqueryui/1.12.1/themes/smoothness/jquery-ui.css">
  <link rel="stylesheet" type="text/css" href="styles/jquery.datetimepicker.min.css"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-EVSTQN3/azprG1Anm3QDgpJLIm9Nao0Yz1ztcQTwFspd3yD65VohhpuuCOmLASjC" crossorigin="anonymous">
  <link rel="stylesheet" type="text/css" href="styles/index.css"/>
  <script>
    (g=>{var h,a,k,p="The Google Maps JavaScript API",c="google",l="importLibrary",q="__ib__",m=document,b=window;b=b[c]||(b[c]={});var d=b.maps||(b.maps={}),r=new Set,e=new URLSearchParams,u=()=>h||(h=new Promise(async(f,n)=>{await (a=m.createElement("script"));e.set("libraries",[...r]+"");for(k in g)e.set(k.replace(/[A-Z]/g,t=>"_"+t[0].toLowerCase()),g[k]);e.set("callback",c+".maps."+q);a.src=`https://maps.${c}apis.com/maps/api/js?`+e;d[q]=f;a.onerror=()=>h=n(Error(p+" could not load."));a.nonce=m.querySelector("script[nonce]")?.nonce||"";m.head.append(a)}));d[l]?console.warn(p+" only loads once. Ignoring:",g):d[l]=(f,...n)=>r.add(f)&&u().then(()=>d[l](f,...n))})({
      key: "<?php echo getenv('GOOGLE_MAPS_API_KEY'); ?>",
      v: "weekly",
      // Use the 'v' parameter to indicate the version to use (weekly, beta, alpha, etc.).
      // Add other bootstrap parameters as needed, using camel case.
    });
  </script>
  <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.4.1/jquery.min.js"></script>
  <script src="https://ajax.googleapis.com/ajax/libs/jqueryui/1.12.1/jquery-ui.min.js"></script>
  <script src="js/jquery.ddslick.min.js"></script>
  <script src="js/rainbowvis.js"></script>
  <script src="js/index.min.js?v=<?= filemtime(__DIR__ . '/js/index.min.js') ?>"></script>
  <script src="js/gmapsDistanceTool-v0.1.3.js"></script>
</head>
<body>
<!-- main navigation header -->
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
  <div class="container-fluid">
  <button id="openCloseSidebar" type="button" aria-label="Toggle navigation" class="rounded border border-dark bg-secondary">
    <span class="navbar-toggler-icon"></span>
  </button>
  <a href="https://www.noaa.gov/" target="_blank">
    <img src="<?= $subdirectory_path ?>/img/noaa-logo.png" alt="NOAA Logo" style="height: 40px; margin-left: 10px;" />
  </a>
  
  <a href="https://ioos.noaa.gov/" target="_blank">
    <img src="<?= $subdirectory_path ?>/img/ioos-logo.png" alt="IOOS Logo" style="height: 40px; margin-left: 10px;" />
  </a>
  <a class="navbar-brand px-3"  href="#">HF-Radar Network</a>
  <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarSupportedContent" aria-controls="navbarSupportedContent" aria-expanded="false" aria-label="Toggle navigation">
    <span class="navbar-toggler-icon"></span>
  </button>
  <div class="collapse navbar-collapse" id="navbarSupportedContent">
    <ul class="navbar-nav me-auto mb-2 mb-lg-0">
      <li class="nav-item dropdown">
        <a class="nav-link dropdown-toggle" href="#" id="navbarDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">Data</a>
        <ul class="dropdown-menu" aria-labelledby="navbarDropdown">
          <li><a target="_blank" href="assets/rtv7day.kml" class="dropdown-item">Google Earth KML (7 day)</a></li>
          <li><a target="_blank" href="https://dods.ndbc.noaa.gov/thredds/catalog/hfradar.html" class="dropdown-item">Data Access via NDBC THREDDS Server</a></li>
          <li><a target="_blank" href="https://www.ncei.noaa.gov/access/metadata/landing-page/bin/iso?id=gov.noaa.nodc:IOOS-HFRadarRadial" class="dropdown-item">NCEI Radial Archive</a></li>
          <li><a target="_blank" href="https://www.ncei.noaa.gov/access/metadata/landing-page/bin/iso?id=gov.noaa.nodc:IOOS-HFRadarRTVector" class="dropdown-item">NCEI RTV (Total) Archive</a></li>
          <li><a target="_blank" href="https://hfradar.ioos.us/radials-erddap/erddap/index.html" class="dropdown-item">IOOS Radial & Waves ERDDAP Server</a></li>
        </ul>
      </li>
      <li class="nav-item dropdown">
        <a class="nav-link dropdown-toggle" href="#" id="navbarDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">Diagnostics</a>
        <ul class="dropdown-menu" aria-labelledby="navbarDropdown">
          <li><a target="_blank" href="sitediag/stationList.php" class="dropdown-item">Site List</a></li>
          <li><a target="_blank" href="diagnostics" class="dropdown-item">Site Diagnostics</a></li>
          <li><a target="_blank" href="diagnostics/help.php" class="dropdown-item">Site Diagnostics Help</a></li>
          <li><a target="_blank" href="sitediag/metric-FY.php" class="dropdown-item">IOOS Metric</a></li>
          <li><a target="_blank" href="diagnostics/networkstats.php" class="dropdown-item">HFRNet Growth</a></li>
        </ul>
      </li>
      <li class="nav-item dropdown">
        <a class="nav-link dropdown-toggle" href="#" id="navbarDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">Documents</a>
        <ul class="dropdown-menu" aria-labelledby="navbarDropdown">
          <li><a class="dropdown-item" target="_blank" href="documents/HFRNet_RTV-NetCDF-2026.pdf"> HFRNet RTV NetCDF Description</a></li>
          <li><a class="dropdown-item" target="_blank" href="documents/HFRNet_QC-RTVproc.pdf">HFRNet RTV QC</a></li>
          <li><a class="dropdown-item" target="_blank" href="documents/HFRNet_RTVgrids_20060127.pdf"> HFRNet RTV Grid Development</a></li>
          <li><a class="dropdown-item" target="_blank" href="documents/HFRNet_Radial_NetCDF.pdf"> HFRNet Radial NetCDF Description</a></li>
          <li><a class="dropdown-item" target="_blank" href="documents/HFRNet_Long_Term_Averages_and_Statistics.pdf"> HFRNet Averages and Statistics</a></li>
          <li><a class="dropdown-item" target="_blank" href="documents/SCCOOS-BestPractices.pdf"> HF-Radar Best Practices</a></li>
          <li><a class="dropdown-item" target="_blank" href="documents/principles.pdf"> Principles of Operation</a></li>
          <li><a class="dropdown-item" target="_blank" href="documents/specifications.pdf"> Radiated Signal Specifications</a></li>
          <li><a class="dropdown-item" target="_blank" href="documents/radFileFormats_20050408.pdf"> CODAR File Formats</a></li>
          <li><a class="dropdown-item" target="_blank" href="documents/GNOME_data_formats.pdf"> GNOME Data Formats</a></li>
          <li><a class="dropdown-item" target="_blank" href="documents/HFRNet_WERA_LonLatUV_RDL.pdf"> WERA File Formats</a></li>
        </ul>
      </li>
    </ul>
    <span class="navbar-text pe-3" id="bookmark">Bookmark this page</span>
    <span class="navbar-text ">
      <div class="nav-item me-1" data-bs-toggle="modal" data-bs-target="#helpModal">
        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="grey" class="bi bi-question-circle" viewBox="0 0 16 16">
          <path d="M8 15A7 7 0 1 1 8 1a7 7 0 0 1 0 14zm0 1A8 8 0 1 0 8 0a8 8 0 0 0 0 16z"/>
          <path d="M5.255 5.786a.237.237 0 0 0 .241.247h.825c.138 0 .248-.113.266-.25.09-.656.54-1.134 1.342-1.134.686 0 1.314.343 1.314 1.168 0 .635-.374.927-.965 1.371-.673.489-1.206 1.06-1.168 1.987l.003.217a.25.25 0 0 0 .25.246h.811a.25.25 0 0 0 .25-.25v-.105c0-.718.273-.927 1.01-1.486.609-.463 1.244-.977 1.244-2.056 0-1.511-1.276-2.241-2.673-2.241-1.267 0-2.655.59-2.75 2.286zm1.557 5.763c0 .533.425.927 1.01.927.609 0 1.028-.394 1.028-.927 0-.552-.42-.94-1.029-.94-.584 0-1.009.388-1.009.94z"/>
        </svg>
      </div>
    </span>
    <span class="navbar-text">
      <div class="nav-item me-1" data-bs-toggle="modal" data-bs-target="#infoModal">
        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="grey" class="bi bi-info-circle" viewBox="0 0 16 16">
          <path d="M8 15A7 7 0 1 1 8 1a7 7 0 0 1 0 14zm0 1A8 8 0 1 0 8 0a8 8 0 0 0 0 16z"/>
          <path d="m8.93 6.588-2.29.287-.082.38.45.083c.294.07.352.176.288.469l-.738 3.468c-.194.897.105 1.319.808 1.319.545 0 1.178-.252 1.465-.598l.088-.416c-.2.176-.492.246-.686.246-.275 0-.375-.193-.304-.533L8.93 6.588zM9 4.5a1 1 0 1 1-2 0 1 1 0 0 1 2 0z"/>
        </svg>
      </div>

    </span>
  </div>
  </div>
</nav>

<!-- Info Modal -->
<div class="modal fade" id="infoModal" tabindex="-1" aria-labelledby="infoModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div id="notice_modal_header" class="modal-header text-white bg-primary">
        <h5 class="modal-title" id="notice_modal_title">Info</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div id="notice_modal_content_message">
          <div id="card" class="card" style="margin-bottom: 10px;">
 	    <div class="card-body">
	      <h5 id="card_title" class="card-title">Disclaimer</h5>
	      <div id="card_content">
          Timely delivery of data and products from this server through the Internet is not guaranteed.
          Before using information obtained from the HFRNet web page, special attention should be given to the date and time of the data and products being displayed.
          The information on the High Frequency Radar Network (HFRNet) web page is supported by the U.S. Integrated Ocean Observing System (IOOS) and is in the public domain, unless specifically noted otherwise, and may be used without charge for any lawful purpose so long as you do not: 
          <ol>
              <li>Claim it as your own (e.g., by asserting copyright).</li>
              <li>Use it in a way that implies endorsement or affiliation with IOOS or HFRNet.</li>
              <li>Modify its content and present it as official government material.</li>
            </ol>
          The user assumes the entire risk related to its use of information on the HFRNet web pages. IOOS provides such information "as is," and IOOS disclaims any and all warranties, whether express or implied, including (without limitation) any implied warranties of merchantability or fitness for a particular purpose. In no event will IOOS be liable to you or to any third party for any direct, indirect, incidental, consequential, special or exemplary damages or lost profit resulting from any use or misuse of this data.
          Third parties producing copyrighted works consisting predominantly of the material appearing in the HFRNet web page must provide notice with such work(s) identifying the IOOS material incorporated and stating that such material is not subject to copyright protection.
        </div>
    </div>
    <div class="card-body">
      <h5 id="card_title" class="card-title">Credit reference</h5>
      <div id="card_content">
        Data provided by the U.S. Integrated Ocean Observing System (IOOS) High Frequency Radar Network (HFRNet).
      </div>
    </div>

    <div class="card-body">
      <h5 id="card_title" class="card-title">Contact Us</h5>
      <div id="card_content">
        For questions or support regarding the HFRNet portal, please <a href="mailto:data.ioos@noaa.gov,hfrnetsupport@tetratech.com?subject=HFRNet%20Portal%20Inquiry">contact us</a>.
      </div>
    </div>

	  </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Help Modal -->
<div class="modal fade" id="helpModal" tabindex="-1" aria-labelledby="helpModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div id="notice_modal_header" class="modal-header text-white bg-primary">
        <h5 class="modal-title" id="notice_modal_title">Help</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div id="notice_modal_content_message">
          <div id="card" class="card" style="margin-bottom: 10px;">
	    <img id="card_img" class="card-img-top" src="img/distance-tool.png" alt="Card image cap">
	    <div class="card-body">
	      <h5 id="card_title" class="card-title">Distance Tool</h5>
	      <div id="card_content"><ul class="list-group list-group-flush"><li class="list-group-item d-flex align-items-left">Right click on the map to start tool and set first point.</li><li class="list-group-item d-flex align-items-center">Continue right clicking to add more points.</li><li class="list-group-item d-flex align-items-center">Click on any of the segments to get the distance for that segment.</li><li class="list-group-item d-flex align-items-center">Total distance is shown above.</li><li class="list-group-item d-flex align-items-center">Click on any of the dropdowns to change units or hide/clear the distance tool.</li></ul></div>
	    </div>
	  </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- body -->
<div class="app-container container-fluid flex-grow-1 px-0">
  <div class="row h-100 g-0" >
    <!-- Sidebar settings -->
    <div id="sidebarMenu" class="col-md-3 col-12 ps-4 pt-3 order-2 order-md-1">
      <p class="text-start"><small id="clock"></small><br /><small id="clock_gmt"></small></p>
      <div id="accordionExample" class="accordion">
        <div class="accordion-item">
          <h5 class="accordion-header"><button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#collapseOne" aria-expanded="true" aria-controls="collapseOne">RTV Products</button></h5>
          <div id="collapseOne" class="accordion-collapse collapse show" aria-labelledby="headingOne">
            <div class="accordion-body">
              <table id="productsResolutions" class="table"><thead><tr>
          <th scope="col"></th>
          <th scope="col">500m</th>
          <th scope="col">1km</th>
          <th scope="col">2km</th>
          <th scope="col">6km</th></tr></thead>
          <tbody>
            <tr>
              <td>Hourly</td>
              <td><input class="form-check-input" type="checkbox" id="h_500m" value="h_500m"></td>
              <td><input class="form-check-input" type="checkbox" id="h_1km" value="h_1km"></td>
              <td><input class="form-check-input" type="checkbox" id="h_2km" value="h_2km"></td>
              <td><input class="form-check-input" type="checkbox" id="h_6km" value="h_6km"></td>
            </tr>
            <tr>
              <td>25hr Average</td>
              <td><input class="form-check-input" type="checkbox" id="a_500m" value="a_500m"></td>
              <td><input class="form-check-input" type="checkbox" id="a_1km" value="a_1km"></td>
              <td><input class="form-check-input" type="checkbox" id="a_2km" value="a_2km"></td>
              <td><input class="form-check-input" type="checkbox" id="a_6km" value="a_6km"></td>
            </tr>
            <tr>
              <td>Month Average</td>
              <td><input class="form-check-input" type="checkbox" id="ma_500m" value="ma_500m"></td>
              <td><input class="form-check-input" type="checkbox" id="ma_1km" value="ma_1km"></td>
              <td><input class="form-check-input" type="checkbox" id="ma_2km" value="ma_2km"></td>
              <td><input class="form-check-input" type="checkbox" id="ma_6km" value="ma_6km"></td>
            </tr>
            <tr>
              <td>Year Average</td>
              <td><input class="form-check-input" type="checkbox" id="ya_500m" value="ya_500m"></td>
              <td><input class="form-check-input" type="checkbox" id="ya_1km" value="ya_1km"></td>
              <td><input class="form-check-input" type="checkbox" id="ya_2km" value="ya_2km"></td>
              <td><input class="form-check-input" type="checkbox" id="ya_6km" value="ya_6km"></td>
            </tr>
            <tr>
              <td colspan="5" class="text-muted" style="font-size: 0.7rem;">Note: Historical RTV products measured prior to July 2025 are available <a href="https://www.ncei.noaa.gov/access/metadata/landing-page/bin/iso?id=gov.noaa.nodc:IOOS-HFRadarRTVector" target="_blank" rel="noopener">from NCEI</a>.</td>
            </tr>
          </tbody>
        </table>
            </div>
          </div>
        </div>
        <!-- Overlays menu -->
        <div class="accordion-item">
          <h5 class="accordion-header"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseTwo" aria-expanded="true" aria-controls="collapseTwo">Overlays</button></h5>
          <div id="collapseTwo" class="accordion-collapse collapse" aria-labelledby="headingTwo">
            <div class="accordion-body">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" value="" id="check_stations">
                <label class="form-check-label" for="check_stations">Station Placemarks</label>
              </div>
              <div class="form-check">
                <input class="form-check-input" type="checkbox" value="" id="check_waves">
                <label class="form-check-label" for="check_waves">Waves</label>
              </div>
              <div class="form-check">
                <input class="form-check-input" type="checkbox" value="" id="check_oilplatforms">
                <label class="form-check-label" for="check_oilplatforms">So-Cal Oil Platforms </label>
              </div>
              <div class="form-check">
                <input class="form-check-input" type="checkbox" value="" id="check_shippinglanes">
                <label class="form-check-label" for="check_shippinglanes">Shipping Lanes</label>
              </div>
            </div>
          </div>
        </div>
        <!-- Legend menu -->
        <div class="accordion-item">
          <h5 class="accordion-header"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseThree" aria-expanded="true" aria-controls="collapseThree">Legend</button></h5>
          <div id="collapseThree" class="accordion-collapse collapse" aria-labelledby="headingThree">
            <div class="accordion-body">
              <div class="mb-4">
                <h6>Vectors</h6>
                <div id="div_colorbar"> 
                  <img class="ms-2" src="img/php/cb.php?range_min=0&amp;range_max=50&amp;width=154&amp;height=15&amp;padding=15,8&amp;font_size=10&amp;title=Current%20Strength (cm/s)&amp;scheme=4&amp;bg=0x7fffffff&amp;ticks=6" alt="Colorbar for RTV's" height="45" width="170" name="img_colorbar" id="img_colorbar"/> 
                </div>
                <button type="button" class="btn btn-primary ms-3" data-bs-toggle="modal" data-bs-target="#colorbarEditor">Edit Vectors</button>
              </div>

              <div class="mb-4" style="display:none" id="wavesLegend">
                <h6>Waves</h6>
                <div id="div_colorbarWaves"> 
                  <img class="ms-2" src="img/php/cb.php?range_min=0&amp;range_max=16&amp;width=154&amp;height=15&amp;padding=15,8&amp;font_size=10&amp;title=Wave%20Height (m)&amp;scheme=4&amp;bg=0x7fffffff&amp;ticks=6" alt="Colorbar for waves" height="45" width="170" name="img_colorbarWaves" id="img_colorbarWaves"/> 
                </div>
                <button type="button" class="btn btn-primary ms-3" data-bs-toggle="modal" data-bs-target="#colorbarWaveEditor">Edit Waves</button>
              </div>
              <div class="mb-4" style="display:none" id="stationLegend">
                <h6 class="mt-2">Station Placemarks</h6> 
                <div class="ms-3">
                <img src="img/hfg.png" /> Less than 5 hours old<br />
                <img src="img/hfy.png" /> Over 5 hours old<br />
                <img src="img/hfr.png" /> Over 10 hours old <br />
                <img src="img/hfd.png" /> Over 30 days old <br />
                </div>
              </div>
              <div class="mb-4" style="display:none" id="shippingLanesLegend">
     <h6 class="mt-2">Shipping Lanes Legend</h6>
<svg xmlns="http://www.w3.org/2000/svg" style="color: green; fill:green; background-color: green;" width="16" height="16" class="bi bi-square" viewBox="0 0 16 16">
  <path d="M14 1a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H2a1 1 0 0 1-1-1V2a1 1 0 0 1 1-1h12zM2 0a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V2a2 2 0 0 0-2-2H2z"/>
</svg>
         Traffic Separation Schemes/Traffic Lanes<br /> 
<svg xmlns="http://www.w3.org/2000/svg" style="color: cornflowerblue; fill: cornflowerblue; background-color: cornflowerblue" width="16" height="16" class="bi bi-square" viewBox="0 0 16 16">
  <path d="M14 1a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H2a1 1 0 0 1-1-1V2a1 1 0 0 1 1-1h12zM2 0a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V2a2 2 0 0 0-2-2H2z"/>
</svg>
         Speed Restrictions/Right Whales<br />
<svg xmlns="http://www.w3.org/2000/svg" style="color: hotpink; fill: hotpink; background-color: hotpink" width="16" height="16" class="bi bi-square" viewBox="0 0 16 16">
  <path d="M14 1a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H2a1 1 0 0 1-1-1V2a1 1 0 0 1 1-1h12zM2 0a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V2a2 2 0 0 0-2-2H2z"/>
</svg>
         Area to be Avoided<br />
<svg xmlns="http://www.w3.org/2000/svg" style="color: cyan; fill: cyan; background-color: cyan" width="16" height="16" class="bi bi-square" viewBox="0 0 16 16">
  <path d="M14 1a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H2a1 1 0 0 1-1-1V2a1 1 0 0 1 1-1h12zM2 0a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V2a2 2 0 0 0-2-2H2z"/>
</svg>
         Particularly Sensitive Sea Area<br />
<svg xmlns="http://www.w3.org/2000/svg" style="color: darkgrey; fill: darkgrey; background-color: darkgrey" width="16" height="16" class="bi bi-square" viewBox="0 0 16 16">
  <path d="M14 1a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H2a1 1 0 0 1-1-1V2a1 1 0 0 1 1-1h12zM2 0a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V2a2 2 0 0 0-2-2H2z"/>
</svg>
         Precautionary Areas<br />
<svg xmlns="http://www.w3.org/2000/svg" style="color: greenyellow; fill: greenyellow; background-color: greenyellow" width="16" height="16" class="bi bi-square" viewBox="0 0 16 16">
  <path d="M14 1a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H2a1 1 0 0 1-1-1V2a1 1 0 0 1 1-1h12zM2 0a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V2a2 2 0 0 0-2-2H2z"/>
</svg>
         Recommended Routes<br />
<svg xmlns="http://www.w3.org/2000/svg" style="color: #ff70f7; fill: #ff70f7;" width="16" height="16" class="bi bi-square" viewBox="0 0 16 16">
  <path d="M14 1a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H2a1 1 0 0 1-1-1V2a1 1 0 0 1 1-1h12zM2 0a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V2a2 2 0 0 0-2-2H2z"/>
</svg>
         Shipping Fairways Lanes and Zones<br />
<svg xmlns="http://www.w3.org/2000/svg" style="color: #f2b5ef; fill: #f2b5ef; background-color: #f2b5ef" width="16" height="16" class="bi bi-square" viewBox="0 0 16 16">
  <path d="M14 1a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H2a1 1 0 0 1-1-1V2a1 1 0 0 1 1-1h12zM2 0a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V2a2 2 0 0 0-2-2H2z"/>
</svg>
         Traffic Separation Schemes
              </div>
            </div>
          </div>
        </div>
        <!-- Coordinate locator menu -->
        <div class="accordion-item">
          <h5 class="accordion-header"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseFour" aria-expanded="true" aria-controls="collapseFour">Coordinate Locator</button></h5>
          <div id="collapseFour" class="accordion-collapse collapse" aria-labelledby="headingFour">
            <div class="accordion-body">
              <form id="coordinatelocator" >
                <div class="input-group mb-3">
                  <span class="input-group-text" id="basic-addon1">DDD.dddd</span>
                  <input required type="number" step="0.01" id="lat" class="form-control" placeholder="Lat (deg. N)" aria-label="Latitude" aria-describedby="basic-addon1" min="0" max="90" />
                </div>
                <div class="input-group mb-3">
                  <span class="input-group-text" id="basic-addon1">DDD.dddd</span>
                  <input required type="number" step="0.01" id="lon" class="form-control" placeholder="Lon (deg. E)" aria-label="Longitude" aria-describedby="basic-addon1" min="-180.0" max="-30" />
                </div>
                <button type="button" id="latlonlocator" class="btn btn-primary">Find</button>
                <button type="button" id="latlonlocatorremover" class="btn btn-primary">Remove</button>
              </form>
            </div>
          </div>
        </div>
      </div> <!-- End div class=accordion -->
    </div> <!-- End div sidebarMenu -->

    <!-- main body -->
    <main id="mainbody" class="col-md-9 col-12 order-1 order-md-2" >

      <div class="card h-100">
        <div class="card-header p-0">
          <!-- Time chooser -->
          <nav class="navbar navbar-expand-lg navbar-light bg-light d-flex justify-content-center">
            <div id="onelessday" title="-1 Day" class="px-2">
              <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-chevron-double-left" viewBox="0 0 16 16">
                <path fill-rule="evenodd" d="M8.354 1.646a.5.5 0 0 1 0 .708L2.707 8l5.647 5.646a.5.5 0 0 1-.708.708l-6-6a.5.5 0 0 1 0-.708l6-6a.5.5 0 0 1 .708 0z"/>
                <path fill-rule="evenodd" d="M12.354 1.646a.5.5 0 0 1 0 .708L6.707 8l5.647 5.646a.5.5 0 0 1-.708.708l-6-6a.5.5 0 0 1 0-.708l6-6a.5.5 0 0 1 .708 0z"/>
              </svg>
            </div>
            <div id="onelesshour" title="-1 Hour" class="px-2">
              <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-chevron-left" viewBox="0 0 16 16">
                <path fill-rule="evenodd" d="M11.354 1.646a.5.5 0 0 1 0 .708L5.707 8l5.647 5.646a.5.5 0 0 1-.708.708l-6-6a.5.5 0 0 1 0-.708l6-6a.5.5 0 0 1 .708 0z"/>
              </svg>
            </div>
            <input type="text" id="datepicker" class="rounded border-1 px-2"/>
            <select id="localOrUtc" class="selectpicker rounded mx-2" style="height: 28px;">
              <!-- Populated dynamically by JavaScript with timezone options -->
            </select>
            <div id="onemorehour" title="+1 Hour" class="px-2">
              <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-chevron-right" viewBox="0 0 16 16">
                <path fill-rule="evenodd" d="M4.646 1.646a.5.5 0 0 1 .708 0l6 6a.5.5 0 0 1 0 .708l-6 6a.5.5 0 0 1-.708-.708L10.293 8 4.646 2.354a.5.5 0 0 1 0-.708z"/>
              </svg>
            </div>
            <div id="onemoreday" title="+1 Day" class="px-2">
              <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-chevron-double-right" viewBox="0 0 16 16">
                <path fill-rule="evenodd" d="M3.646 1.646a.5.5 0 0 1 .708 0l6 6a.5.5 0 0 1 0 .708l-6 6a.5.5 0 0 1-.708-.708L9.293 8 3.646 2.354a.5.5 0 0 1 0-.708z"/>
                <path fill-rule="evenodd" d="M7.646 1.646a.5.5 0 0 1 .708 0l6 6a.5.5 0 0 1 0 .708l-6 6a.5.5 0 0 1-.708-.708L13.293 8 7.646 2.354a.5.5 0 0 1 0-.708z"/>
              </svg> 
            </div>
          </nav>
        </div><!-- end div class card-header -->
        <div class="card-body p-0">
          <!-- Map --> 
          <div id="map" style="height:100%"></div>
        </div><!-- end div card-body -->
      </div> <!-- End div class card -->
    </main>
  </div>
</div>
<!-- Distance tool -->
<div id="distanceTool"></div>
<!-- Plots using toast-->
<div class="position-fixed bottom-0 end-0 p-3" style="z-index: 11">
  <div id="liveToast" class="toast hide" style="width:400px" data-bs-autohide="false">
    <div class="toast-header">
      <strong class="me-auto">Plots</strong>
      <small id="latlonplots">lat lon</small>
      <button type="button" class="btn-close" data-bs-dismiss="toast" aria-label="Close"></button>
    </div>
    <div class="toast-body">
        <a id="newWindowPlot" target="_blank"  href="">Open in new window 
          <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-box-arrow-up-right" viewBox="0 0 16 16">
            <path fill-rule="evenodd" d="M8.636 3.5a.5.5 0 0 0-.5-.5H1.5A1.5 1.5 0 0 0 0 4.5v10A1.5 1.5 0 0 0 1.5 16h10a1.5 1.5 0 0 0 1.5-1.5V7.864a.5.5 0 0 0-1 0V14.5a.5.5 0 0 1-.5.5h-10a.5.5 0 0 1-.5-.5v-10a.5.5 0 0 1 .5-.5h6.636a.5.5 0 0 0 .5-.5z"/>
            <path fill-rule="evenodd" d="M16 .5a.5.5 0 0 0-.5-.5h-5a.5.5 0 0 0 0 1h3.793L6.146 9.146a.5.5 0 1 0 .708.708L15 1.707V5.5a.5.5 0 0 0 1 0v-5z"/>
          </svg></a>
        <iframe id="iframe-plots" style='height: 600px' src="/plots.php?lat1=39.805859&lon1=-72.388840&prod=a_6km&time=1630299600"></iframe>
    </div>
  </div>
</div>

<!-- Alert toast notification positioned in top left of map -->
<div id="alertToastContainer" class="position-absolute" style="top: 10px; left: 10px; z-index: 1000; pointer-events: none;">
  <div id="alertToast" class="toast hide" role="alert" aria-live="assertive" aria-atomic="true" data-bs-autohide="true" data-bs-delay="10000" style="pointer-events: auto;">
    <div class="toast-header" style="background-color: #212529; color: white;">
      <strong class="me-auto">Notice</strong>
      <button type="button" class="btn-close btn-close-white" data-bs-dismiss="toast" aria-label="Close"></button>
    </div>
    <div class="toast-body" id="alertToastBody">
      <!-- Alert message will be inserted here -->
    </div>
  </div>
</div>

<!-- colorbar Modal -->
<div class="modal fade" id="colorbarEditor" tabindex="-1" aria-labelledby="colorbarEditor" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h1 class="modal-title fs-5" id="exampleModalLabel">Vectors color bar editor</h1>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <select id="colorbar_ddslick">
            <option data-imagesrc="img/php/cb.php?width=40&amp;height=20&amp;scheme=0&amp;bg=0x7fffffff&amp;ticks=0" value="0">Jet</option>
            <option data-imagesrc="img/php/cb.php?width=40&amp;height=20&amp;scheme=1&amp;bg=0x7fffffff&amp;ticks=0" value="1" >Heat</option>
            <option data-imagesrc="img/php/cb.php?width=40&amp;height=20&amp;scheme=2&amp;bg=0x7fffffff&amp;ticks=0" value="2" >More Blue</option>
            <option data-imagesrc="img/php/cb.php?width=40&amp;height=20&amp;scheme=3&amp;bg=0x7fffffff&amp;ticks=0" value="3">Cold</option>
            <option data-imagesrc="img/php/cb.php?width=40&amp;height=20&amp;scheme=4&amp;bg=0x7fffffff&amp;ticks=0" value="4">ROGB</option>
          </select>
       </div>

        <div class="input-group mb-3">
          <span class="input-group-text" id="basic-addon1">Min Range</span>
          <input type="text" value="0" id="colorbarmin" class="form-control" aria-label="minrange" aria-describedby="basic-addon1"> 
          <span class="input-group-text" id="basic-addon1">Max Range</span>
          <input type="text" value="50" id="colorbarmax" class="form-control" aria-label="maxrange" aria-describedby="basic-addon1"> 
        </div>
<!--
        <select id="vectorUnits" class="form-select form-select-sm" aria-label=".form-select-sm example">
          <option value="100" selected>cm/s</option>
          <option value="1">m/s</option>
          <option value="3.6">kph</option>
          <option value="1.944">kts</option>
          <option value="2.237">mph</option>
          <option value="3.281">ft/s</option>
        </select>
-->
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
        <button type="button" class="btn btn-primary" onclick="changeVectorColors()" data-bs-dismiss="modal">Save changes</button>
      </div>
    </div>
  </div>
</div>
<!-- wave colorbar Modal -->
<div class="modal fade" id="colorbarWaveEditor" tabindex="-1" aria-labelledby="colorbarWaveEditor" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h1 class="modal-title fs-5" id="exampleModalLabel">Waves color bar editor</h1>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <select id="colorbarWave_ddslick">
            <option data-imagesrc="img/php/cb.php?width=40&amp;height=20&amp;scheme=0&amp;bg=0x7fffffff&amp;ticks=0" value="0">Jet</option>
            <option data-imagesrc="img/php/cb.php?width=40&amp;height=20&amp;scheme=1&amp;bg=0x7fffffff&amp;ticks=0" value="1" >Heat</option>
            <option data-imagesrc="img/php/cb.php?width=40&amp;height=20&amp;scheme=2&amp;bg=0x7fffffff&amp;ticks=0" value="2" >More Blue</option>
            <option data-imagesrc="img/php/cb.php?width=40&amp;height=20&amp;scheme=3&amp;bg=0x7fffffff&amp;ticks=0" value="3">Cold</option>
            <option data-imagesrc="img/php/cb.php?width=40&amp;height=20&amp;scheme=4&amp;bg=0x7fffffff&amp;ticks=0" value="4">ROGB</option>
          </select>
       </div>

        <div class="input-group mb-3">
          <span class="input-group-text" id="basic-addon1">Min Range</span>
          <input type="text" value="0" id="colorbarwavemin" class="form-control" aria-label="minrange" aria-describedby="basic-addon1"> 
          <span class="input-group-text" id="basic-addon1">Max Range</span>
          <input type="text" value="16" id="colorbarwavemax" class="form-control" aria-label="maxrange" aria-describedby="basic-addon1"> 
        </div>
<!--
        <select id="vectorUnits" class="form-select form-select-sm" aria-label=".form-select-sm example">
          <option value="100" selected>cm/s</option>
          <option value="1">m/s</option>
          <option value="3.6">kph</option>
          <option value="1.944">kts</option>
          <option value="2.237">mph</option>
          <option value="3.281">ft/s</option>
        </select>
-->
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
        <button type="button" class="btn btn-primary" onclick="changeWaveColors()" data-bs-dismiss="modal">Save changes</button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-MrcW6ZMFYlzcLA8Nl+NtUVF0sA7MsXsP1UyJoMp4YLEuNSfAP+JcXn/tWtIaxVXM" crossorigin="anonymous"></script>
<script src="js/jquery.datetimepicker.full.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/ms-dropdown@4.0.3/dist/js/dd.min.js"></script>
<script>
// Hide the duplicate KML station name inside placemark popups.
// The large Google Maps title still comes from the placemark <name>.
(function hideDuplicateStationPlacemarkTitle() {
  function hideTitle() {
    document.querySelectorAll('.gm-style-iw-d').forEach(function (element) {
      var diagnosticsLink = element.querySelector('a[href*="/diagnostics"]');

      if (!diagnosticsLink || !element.textContent.includes('Station ID:')) {
        return;
      }

      element.querySelectorAll('b').forEach(function (title) {
        if (title.textContent.includes(':')) {
          return;
        }

        title.style.display = 'none';
        if (title.nextSibling && title.nextSibling.nodeName === 'BR') {
          title.nextSibling.remove();
        }
      });
    });
  }

  new MutationObserver(hideTitle).observe(document.body, {
    childList: true,
    subtree: true
  });
  hideTitle();
})();
</script>

</body>
</html>
