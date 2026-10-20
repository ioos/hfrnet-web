// Get my URL parameters
const queryString = window.location.search;
const urlParams = new URLSearchParams(queryString);

const lat1 = urlParams.get('lat1')
const lon1 = urlParams.get('lon1')
const time = urlParams.get('time')
const prod = urlParams.get('prod')
const site = urlParams.get('site')
const tz = urlParams.get('tz') || 'UTC'

// Configure Highcharts to use the specified timezone
Highcharts.setOptions({
  time: {
    timezone: tz
  },
  tooltip: {
    headerFormat: `<span style="font-size: 10px">{point.key} ${tz}</span><br/>`
  }
});

// Add CSS for loading spinner
const spinnerCSS = `
<style>
.plot-loading-overlay {
  position: fixed;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  background-color: rgba(255, 255, 255, 0.8);
  display: flex;
  justify-content: center;
  align-items: center;
  z-index: 9999;
}

.plot-spinner {
  border: 4px solid #f3f3f3;
  border-top: 4px solid #3498db;
  border-radius: 50%;
  width: 50px;
  height: 50px;
  animation: spin 1s linear infinite;
  margin: 0 auto;
}

@keyframes spin {
  0% { transform: rotate(0deg); }
  100% { transform: rotate(360deg); }
}

</style>
`;

// Inject CSS into the document head
document.head.insertAdjacentHTML('beforeend', spinnerCSS);

// Function to show loading spinner
function showLoadingSpinner() {
  const overlay = document.createElement('div');
  overlay.className = 'plot-loading-overlay';
  overlay.id = 'plot-loading-overlay';
  overlay.innerHTML = `
    <div style="text-align: center;">
      <div class="plot-spinner"></div>
    </div>
  `;
  document.body.appendChild(overlay);
}

// Function to hide loading spinner
function hideLoadingSpinner() {
  const overlay = document.getElementById('plot-loading-overlay');
  if (overlay) {
    overlay.remove();
  }
}

function sortByTime(points) {
  return points.sort((a, b) => a[0] - b[0]);
}

function wavePlots(){
  const url = `${process.env.API_URL}/waves/hist`;

  showLoadingSpinner();

  $.ajax({
    url:url,
    async: true,
    data: { site: site, time: time },
    dataType: "json"
  })
  .done( function( data ){
    var mwht_data = [];
    var wavb_data = [];
    var wndb_data = [];
    var mwpd_data = [];

    if( data != null ){

      $.each(data, function(index, element) {
        timestamp = element.time*1000;

        lat = element.lat;
        lon = element.lon;
        mwht = element.MWHT;
        wavb = element.WAVB;
        wndb = element.WNDB;
        mwpd = element.MWPD;
  
        mwht_data.unshift( [timestamp,mwht] );
        wavb_data.unshift( [timestamp,wavb] ); 
        wndb_data.unshift( [timestamp,wndb] );
        mwpd_data.unshift( [timestamp,mwpd] );

      });
      sortByTime(mwht_data);
      sortByTime(wavb_data);
      sortByTime(wndb_data);
      sortByTime(mwpd_data);
    }
    // MWHT Plot
    var options = {
      chart:{
        renderTo: 'magni-plot', 
        zoomType: 'x'                                                                   
      },
      tooltip: {
        xDateFormat: '%b %e, %Y %H:%M'
      },
      title:{ text: 'Wave Model Height' },
      subtitle:{ text: lat + ", " + lon },
      xAxis:{ 
        type: 'datetime',
        events:{
          afterSetExtremes(e){
            updateExtremes(e)
          }
        }
      },
      yAxis:[{ 
        title:{text: 'meters',  }, tickAmount: 4, alternateGridColor: '#FDFFD5',
        },{
        title: { text: 'feet' },
        labels: {
          formatter: function () {
            k = this.axis.defaultLabelFormatter.call(this) * 3.281;
            return k.toFixed(2);
          }            
        },
        linkedTo: 0,
        opposite: true},
      ],   
      series:[
        {name: 'Wave Model Height', data: mwht_data},                                        
      ] 
    };  
    var chart = new Highcharts.Chart(options);           
   
    // MWPD Plot
    var options = {
      chart:{
        renderTo: 'direction-plot', 
        zoomType: 'x'                                                                   
      },
      tooltip: {
        xDateFormat: '%b %e, %Y %H:%M'
      },
      title:{ text: 'Wave Spectra Period' },
      subtitle:{ text: lat + ", " + lon },
      xAxis:{ 
        type: 'datetime',
        events:{
          afterSetExtremes(e){
            updateExtremes(e)
          }
        }
      },
      yAxis:[{ 
        title:{text: 'seconds',  }, tickAmount: 4, alternateGridColor: '#FDFFD5',
        },{
        title: { text: 'seconds' },
        linkedTo: 0,
        opposite: true},
      ],   
      series:[
        {name: 'Wave Spectra Period', data: mwpd_data},                                        
      ] 
    };  
    var chart = new Highcharts.Chart(options);           
    
    // WAVB Plot
    var options = {
      chart:{
        renderTo: 'ns-plot', 
        zoomType: 'x'                                                                   
      },
      tooltip: {
        xDateFormat: '%b %e, %Y %H:%M'
      },
      title:{ text: 'Wave From Direction' },
      subtitle:{ text: lat + ", " + lon },
      xAxis:{ 
        type: 'datetime',
        events:{
          afterSetExtremes(e){
            updateExtremes(e)
          }
        }
      },
      yAxis:[{ 
        title: {text: '&deg; from N' },
        max: 360,
        tickInterval: 45,
        alternateGridColor: '#FDFFD5',
        },{
        title: {text: '&deg; from N' },
        linkedTo: 0,
        opposite: true},
      ],   
      series:[
        {name: 'Wave From Direction', data: wavb_data},                                        
      ] 
    };  
    var chart = new Highcharts.Chart(options);           
   
    // WNDB Plot
    var options = {
      chart:{
        renderTo: 'ew-plot', 
        zoomType: 'x'                                                                   
      },
      tooltip: {
        xDateFormat: '%b %e, %Y %H:%M'
      },
      title:{ text: 'Wind From Direction' },
      subtitle:{ text: lat + ", " + lon },
      xAxis:{ 
        type: 'datetime',
        events:{
          afterSetExtremes(e){
            updateExtremes(e)
          }
        }
      },
      yAxis:[{ 
        title: {text: '&deg; from N' },
        max: 360,
        tickInterval: 45,
        alternateGridColor: '#FDFFD5',
        },{
        title: {text: '&deg; from N' },
        linkedTo: 0,
        opposite: true},
      ],   
      series:[
        {name: 'Wind From Direction', data: wndb_data},                                        
      ] 
    };  
    var chart = new Highcharts.Chart(options);           
    
    // Hide loading spinner
    hideLoadingSpinner();
  })
  .fail(function(jqXHR, textStatus, errorThrown) {
    console.log('Error loading wave plot data:', textStatus, errorThrown);
    // Hide loading spinner on error
    hideLoadingSpinner();
  });
}

function radialPlots(){
  const url = `${process.env.API_URL}/hist`;
  showLoadingSpinner();

  $.ajax({
    url:url,
    async: true,
    data: { lat1: lat1, lon1: lon1, time: time, prod: prod },
    dataType: "json"
  })
  .done( function( data ){
    var magni_data = [];
    var direction_data = [];
    var ns_data = [];
    var ew_data = [];

    var patterntypes = {};
    var elements_i = [];
    var elements_m = [];
    patterntypes["i"] = elements_i;                                                             
    patterntypes["m"] = elements_m;
    
    if( data != null ){

      $.each(data, function(index, element) {
        // index: (39.10464096069336, -75.2345199584961, Timestamp('2021-07-24 17:00:00'))
        timestamp_el = index.split('\'')[1]
        // TODO get epoch from string, unfortunately the commented out parts don't work on FF
        //var timestamp = new Date(timestamp_el+" +0000");
        //timestamp = timestamp.getTime();
        var parts = timestamp_el.match(/(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2}):\d{2}/);
        var timestamp = Date.UTC(+parts[1], parts[2]-1, +parts[3], +parts[4], +parts[5]);        

        u = element.u;
        v = element.v;
        magni = element.magni;
        head = element.head;
  
        magni_data.unshift( [timestamp,magni*100] );
        direction_data.unshift( [timestamp,head] ); 
        ns_data.unshift( [timestamp,v*100] );
        ew_data.unshift( [timestamp,u*100] );

      });
      sortByTime(magni_data);
      sortByTime(direction_data);
      sortByTime(ns_data);
      sortByTime(ew_data);
    }

    // Magnitude Plot
    var options = {
      chart:{
        renderTo: 'magni-plot', 
        zoomType: 'x'                                                                   
      },
      tooltip: {
        xDateFormat: '%b %e, %Y %H:%M'
      },
      title:{ text: 'Surface Current Magnitude' },
      subtitle:{ text: lat1 + ", " + lon1 },
      xAxis:{ 
        type: 'datetime',
        events:{
          afterSetExtremes(e){
            updateExtremes(e)
          }
        }
      },
      yAxis:[{ 
        title:{text: 'cm/s',  }, tickAmount: 4, alternateGridColor: '#FDFFD5',
        },{
        title: { text: 'knots' },
        labels: {
          formatter: function () {
            k = this.axis.defaultLabelFormatter.call(this) / 51.44;
            return k.toFixed(2);
          }            
        },
        linkedTo: 0,
        opposite: true},
      ],   
      series:[
        {name: 'Magnitude', data: magni_data},                                        
      ] 
    };  
    var chart = new Highcharts.Chart(options);           

    // Direction Plot
    var options = {
      chart:{
        renderTo: 'direction-plot', 
        zoomType: 'x'                                                                   
      },
      tooltip: {
        xDateFormat: '%b %e, %Y %H:%M'
      },
      title:{ text: 'Surface Current Direction' },
      subtitle:{ text: lat1 + ", " + lon1 },
      xAxis:{ 
        type: 'datetime',
        events:{
          afterSetExtremes(e){
            updateExtremes(e)
          }
        }
      },
      yAxis:[{ 
        title: {text: '&deg; from N' },
        max: 360,
        tickInterval: 45,
        alternateGridColor: '#FDFFD5',
        },{
        title: {text: '&deg; from N' },
        linkedTo: 0,
        opposite: true},
      ],   
      series:[
        {name: 'Direction', data: direction_data},                                        
      ] 
    };  
    var chart = new Highcharts.Chart(options);           

    // NS Plot
    options = {
      chart:{
        renderTo: 'ns-plot', zoomType: 'x'
      },
      tooltip: {
        xDateFormat: '%b %e, %Y %H:%M'
      },
      title:{ text: 'North-South Surface Currents' },
      subtitle:{ text: lat1 + ", " + lon1 },
      xAxis:{
        type: 'datetime',
        events:{
          afterSetExtremes(e){
            updateExtremes(e)
          }
        }
      },
      yAxis:[{ 
        title:{text: 's - n<br />cm/s' },tickAmount: 5, alternateGridColor: '#FDFFD5',
        },{
        title: { text: 'knots' },
        tickAmount: 5,
        labels: {
          formatter: function () {
            k = this.axis.defaultLabelFormatter.call(this) / 51.44;
            return k.toFixed(2);
          }            
        },
        linkedTo: 0,
        opposite: true},
      ],   
      series:[
        {name: 'North South', data: ns_data}
      ] 
    };  
    chart = new Highcharts.Chart(options);           

    // EW Plot
    options = {
      chart:{
        renderTo: 'ew-plot', zoomType: 'x'
      },
      tooltip: {
        xDateFormat: '%b %e, %Y %H:%M'
      },
      title:{ text: 'East-West Surface Currents' },
      subtitle:{ text: lat1 + ", " + lon1 },
      xAxis:{
        type: 'datetime',
        events:{
          afterSetExtremes(e){
            updateExtremes(e)
          }
        }
      },
      yAxis:[{ 
        title:{text: 'w - e<br />cm/s' },tickAmount: 5, alternateGridColor: '#FDFFD5',
        },{
        title: { text: 'knots' },
        tickAmount: 5,
        labels: {
          formatter: function () {
            k = this.axis.defaultLabelFormatter.call(this) / 51.44;
            return k.toFixed(2);
          }            
        },
        linkedTo: 0,
        opposite: true},
      ],   
      series:[
        {name: 'East West', data: ew_data},
      ] 
    };  
    chart = new Highcharts.Chart(options);           
    
    hideLoadingSpinner();
  })
  .fail(function(jqXHR, textStatus, errorThrown) {
    console.log('Error loading radial plot data:', textStatus, errorThrown);
    hideLoadingSpinner();
  });
}

function updateExtremes ({ min, max }) {
  Highcharts.charts.forEach((chart) => chart.xAxis[0].setExtremes(min, max))
}
$(document).ready(function() {
  if( prod=="waves" ){
    wavePlots();
  }
  else {
    radialPlots();
  }

});
