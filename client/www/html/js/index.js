"use strict";

// Global variables
let map;
let infowindow;
let currentInfoWindowType = null; // Track what type of data the infowindow is showing
let lineSymbol;
let colorbar;
let colorbarWaves;
const geojson_radials = {}; // Contains the geojson of the radials keyed by product
let jqxhr = { abort: () => {} };
const currentSettings = {}; // Holds the current settings (prod, time, zm, ll, etc.)
const myPolylines = {}; // Polylines organized by product
const myTempPolylines = {};
const mylayers = {}; // Overlays on the map
let mywaves = []; // Array containing the waves markers
let changetm = null;
let skipcounter;
let dateClickTimeout;
let mapTimeout;
let maxTime;
let distanceTool;
let latlonlocator; // Marker used for coordinate locator

// Constants
const STATION_KML = "https://hfradar.ioos.us/hfrnet/rtv/ss.php";
const OIL_KML = "https://hfradar.ioos.us/hfrnet/assets/oil-platforms.kml";
const API_URL = process.env.API_URL;
const SUBDIRECTORY = process.env.SUBDIRECTORY;

// Start with 16 hours back (16 * 60 * 60 * 1000 milliseconds)
const hoursback = 60 * 60 * 16 * 1000;
const now = new Date();
const utcMillisecondsSinceEpoch = now.getTime();
const utcSecondsSinceEpoch = Math.round(utcMillisecondsSinceEpoch / 1000);

/**
 * Get the value of a URL parameter by name.
 * If not found, returns a default value for certain keys.
 * @param {string} name - The name of the URL parameter.
 * @returns {string|number|null}
 */
$.urlParam = (name) => {
  const results = new RegExp("[\\?&]" + name + "=([^&#]*)").exec(
    window.location.href
  );
  if (results === null) {
    switch (name) {
      case "prod":
        return "a_6km";
      case "time":
        return Math.round((utcMillisecondsSinceEpoch - hoursback) / 1000);
      case "zm":
        return 3;
      case "ll":
        return "51.18,-110";
      case "rng":
        return "0,50"; // colorbar min,max range
      case "cb":
        return 4; // colorbar scheme index
      case "wrng":
        return "0,4"; // waves colorbar min,max range
      case "wcb":
        return 4; // waves colorbar scheme index
      case "us":
        return 100; // vector units
      case "o_sp":
        return 0; // station placemarks
      case "o_o":
        return 0; // oil platforms
      case "o_sl":
        return 0; // shipping lanes
      case "o_wa":
        return 0; // waves
      default:
        return null;
    }
  } else {
    return results[1] || 0;
  }
};

// Get URL parameters and assign to currentSettings
let mytime = $.urlParam("time");
let myRoundedDate = new Date(mytime * 1000);
myRoundedDate.setMinutes(0, 0, 0);
mytime = myRoundedDate.getTime() / 1000;
currentSettings["time"] = mytime;
currentSettings["prod"] = $.urlParam("prod").split(",");
currentSettings["zm"] = parseInt($.urlParam("zm"), 10);
currentSettings["rng"] = $.urlParam("rng").toString();
currentSettings["cb"] = parseInt($.urlParam("cb"), 10);
currentSettings["wrng"] = $.urlParam("wrng").toString();
currentSettings["wcb"] = parseInt($.urlParam("wcb"), 10);
currentSettings["us"] = parseFloat($.urlParam("us"));
currentSettings["o_sp"] = parseInt($.urlParam("o_sp"));
currentSettings["o_o"] = parseInt($.urlParam("o_o"));
currentSettings["o_sl"] = parseInt($.urlParam("o_sl"));
currentSettings["o_wa"] = parseInt($.urlParam("o_wa"));

// Set initial lat/lon from URL
currentSettings["ll"] = $.urlParam("ll");

/**
 * Callback for geolocation. Updates currentSettings with the found position.
 * @param {Object} position - Geolocation position object.
 */
function getposition(position) {
  currentSettings["zm"] = 5;
  currentSettings[
    "ll"
  ] = `${position.coords.latitude},${position.coords.longitude}`;
}

// Calculate maxTime based on product type and current time.
maxTime = new Date();
maxTime.setMinutes(0, 0, 0);
if ($.urlParam("prod").includes("h")) {
  maxTime = maxTime.getTime() - 60 * 60 * 1000;
} else {
  maxTime = maxTime.getTime() - 60 * 60 * 14 * 1000;
}
// Ensure currentSettings time does not exceed maxTime
if (currentSettings["time"] > maxTime) {
  currentSettings["time"] = maxTime;
}

/**
 * Delete all wave markers from the map.
 */
function deleteWavesData() {
  if (mywaves.length) {
    for (let i = 0; i < mywaves.length; i++) {
      mywaves[i].position = null;
    }
  }
  mywaves = [];
}

/**
 * Asynchronously fetch waves data from the API and display markers on the map.
 * @returns {Promise<array>} Array of wave markers.
 */
async function getWavesData() {
  deleteWavesData();

  const { AdvancedMarkerElement } = await google.maps.importLibrary("marker");
  jqxhr = $.ajax({
    url: `${API_URL}/waves`,
    data: { time: currentSettings["time"] },
    dataType: "jsonp",
    type: "GET",
    contentType: "application/json; charset=utf-8",
    success: (results, status, xhr) => {
      results.features.forEach((feature) => {
        const coords = feature.geometry.coordinates;
        const latLng = new google.maps.LatLng(coords[1], coords[0]);
        const { site, net, MWHT, MWPD, WAVB, WNDB } = feature.properties;
        const iconcolor = "#" + colorbarWaves.colourAt(MWHT);
        const iconSvgString = `<svg version="1.1" width="30" height="30" xmlns="http://www.w3.org/2000/svg">
          <circle cx="15" cy="15" r="15" fill="${iconcolor}" fill-opacity=".7" stroke="white"/>
          <text x="15" y="18" font-size="10" text-anchor="middle" fill="black">${MWHT}</text>
        </svg>`;
        const parser = new DOMParser();
        const iconSvg = parser.parseFromString(
          iconSvgString,
          "image/svg+xml"
        ).documentElement;
        const wave = new AdvancedMarkerElement({
          position: latLng,
          map: map,
          content: iconSvg,
        });

        const content = `<div class='iw'>
          Station ID: ${site}<br />
          Affiliation: ${net}<br />
          Coords: ${coords[1].toFixed(4)}, ${coords[0].toFixed(4)}<br>
          <div style="display: flex; align-items: flex-start;">
            <div style="width: 40px;"><b>Time:</b></div>
            <div id="iw-time-display" style="text-align: left;">
              ${getDateTimeFromEpoch(currentSettings["time"] * 1000, "UTC", 0)}<br>
              ${getDateTimeFromEpoch(currentSettings["time"] * 1000, getSelectedTimezone(), 0)}
            </div>
          </div>
          <fieldset><legend>Wave Data</legend>
            Height: ${MWHT} m<br />
            Period: ${MWPD} seconds<br />
            Wave Direction: ${WAVB}°<br />
            Wind Direction: ${WNDB}°
          </fieldset><br />
          <button type='button' class='btn btn-primary' id='liveToastBtn'>Time History</button>
        </div>`;

        wave.addListener("click", () => {
          infowindow.close();
          infowindow.setContent(content);
          infowindow.open(wave.map, wave);
          currentInfoWindowType = 'waves';

          const myurl = `plots.php?site=${site}&prod=waves&time=${currentSettings["time"]}&tz=${encodeURIComponent(resolveTimezone(getSelectedTimezone()))}`;
          window.lastPlotUrl = myurl;
          window.lastPlotLatLon = `${coords[1].toFixed(4)}, ${coords[0].toFixed(4)}`;

          // If the plot toast is already visible, update the iframe and link immediately
          if (document.getElementById('liveToast').classList.contains('show')) {
            $("#newWindowPlot").attr("href", window.lastPlotUrl);
            $("#iframe-plots").attr("src", '');
            setTimeout(function() {
              $("#iframe-plots").attr("src", window.lastPlotUrl);
            }, 10);
            $("#latlonplots").text(window.lastPlotLatLon);
          }
        });

        mywaves.push(wave);
      });
      return mywaves;
    },
    error: (xhr, status, error) => {
      alertMissingWaves();
      console.log(
        `ERROR getWavesData(): ${error}: ${xhr.status}: ${xhr.statusText}`
      );
    },
  });
}

/**
 * Loads shipping lane data and returns a Google Maps Data layer.
 * @returns {google.maps.Data} The data layer containing shipping lanes.
 */
function getShippingData() {
  const layer = new google.maps.Data();
  layer.loadGeoJson(`/${SUBDIRECTORY}/assets/shipping_lanes.json`);
  layer.setStyle((feature) => {
    const THEMELAYER = feature.getProperty("THEMELAYER");
    let fillcolor,
      strokecolor,
      fillopacity = 0.1,
      strokeweight = 1;
    switch (THEMELAYER) {
      case "Speed Restrictions/Right Whales":
        fillcolor = "#53bbfc";
        strokecolor = "#53bbfc";
        break;
      case "Traffic Separation Schemes/Traffic Lanes":
        fillcolor = "green";
        fillopacity = 0;
        strokecolor = "green";
        break;
      case "Area to be Avoided":
        fillcolor = "#ff70f7";
        strokecolor = "#ff70f7";
        break;
      case "Particularly Sensitive Sea Area":
        fillcolor = "#30ffd2";
        strokecolor = "#30ffd2";
        break;
      case "Precautionary Areas":
        fillcolor = "#8798d1";
        strokecolor = "#8798d1";
        break;
      case "Recommended Routes":
        fillcolor = "#cafc6c";
        strokecolor = "#cafc6c";
        break;
      case "Traffic Separation Schemes":
        fillcolor = "#f2b5ef";
        strokecolor = "#f2b5ef";
        break;
      case "Shipping Fairways Lanes and Zones":
        fillcolor = "white";
        strokecolor = "#ff70f7";
        fillopacity = 0;
        break;
      default:
        fillcolor = "black";
        strokecolor = "black";
    }
    return {
      clickable: false,
      fillColor: fillcolor,
      fillOpacity: fillopacity,
      strokeColor: strokecolor,
      strokeWeight: strokeweight,
    };
  });
  layer.setMap(map);
  return layer;
}

/**
 * Convert a lat/lon string ("lat,lon") to a google.maps.LatLng object.
 * @param {string} latlng - A string in the format "lat,lon".
 * @returns {google.maps.LatLng}
 */
function getLatLngObject(latlng) {
  const arr = latlng.split(",");
  return new google.maps.LatLng({
    lat: parseFloat(arr[0]),
    lng: parseFloat(arr[1]),
  });
}

/**
 * Popular timezones and their IANA identifiers
 * The "local" entry represents the user's browser timezone.
 */
const TIMEZONES = [
  { id: "local" },
  { id: "UTC" },
  { id: "America/Halifax" },      // Atlantic (UTC-4)
  { id: "America/New_York" },     // Eastern (UTC-5)
  { id: "America/Chicago" },      // Central (UTC-6)
  { id: "America/Denver" },       // Mountain (UTC-7)
  { id: "America/Los_Angeles" },  // Pacific (UTC-8)
  { id: "America/Anchorage" },    // Alaska (UTC-9)
  { id: "Pacific/Honolulu" },     // Hawaii (UTC-10)
];

/**
 * Fallback abbreviations for timezones that may not be detected correctly by the browser.
 */
const TIMEZONE_ABBREVIATIONS = {
  "America/Halifax": { standard: "AST", daylight: "ADT" },
  "America/New_York": { standard: "EST", daylight: "EDT" },
  "America/Chicago": { standard: "CST", daylight: "CDT" },
  "America/Denver": { standard: "MST", daylight: "MDT" },
  "America/Los_Angeles": { standard: "PST", daylight: "PDT" },
  "America/Anchorage": { standard: "AKST", daylight: "AKDT" },
  "Pacific/Honolulu": { standard: "HST", daylight: "HST" }, // Hawaii doesn't observe DST
};

/**
 * Gets the user's local IANA timezone identifier.
 * @returns {string} IANA timezone identifier (e.g., "America/Los_Angeles").
 */
function getLocalTimezone() {
  return Intl.DateTimeFormat().resolvedOptions().timeZone;
}

/**
 * Resolves a timezone identifier, handling the "local" alias.
 * @param {string} timezone - IANA timezone identifier, "local", or "UTC".
 * @returns {string} IANA timezone identifier or "UTC".
 */
function resolveTimezone(timezone = "local") {
  return timezone === "local" ? getLocalTimezone() : timezone;
}

/**
 * Gets the offset in milliseconds between UTC and the target timezone.
 * @param {Date} date - Date object representing an absolute time.
 * @param {string} timezone - IANA timezone identifier.
 * @returns {number} Offset in milliseconds (positive for zones ahead of UTC).
 */
function getTimeZoneOffsetMs(date, timezone) {
  const formatter = new Intl.DateTimeFormat("en-US", {
    timeZone: timezone,
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
    hour: "2-digit",
    minute: "2-digit",
    second: "2-digit",
    hour12: false
  });
  const parts = formatter.formatToParts(date);
  const year = parseInt(parts.find(p => p.type === "year").value);
  const month = parseInt(parts.find(p => p.type === "month").value);
  const day = parseInt(parts.find(p => p.type === "day").value);
  const hour = parseInt(parts.find(p => p.type === "hour").value);
  const minute = parseInt(parts.find(p => p.type === "minute").value);
  const second = parseInt(parts.find(p => p.type === "second").value);

  const asUtc = Date.UTC(year, month - 1, day, hour, minute, second);
  return asUtc - date.getTime();
}


/**
 * Gets the timezone abbreviation for a given IANA timezone identifier.
 * @param {string} timezone - IANA timezone identifier or "local" or "UTC".
 * @returns {string} Timezone abbreviation (e.g., "PST", "EST", "NST").
 */
function getTimezoneAbbreviation(timezone = "local") {
  if (timezone === "UTC") return "UTC";

  const tz = resolveTimezone(timezone);
  const d = new Date();
  
  // Try to extract abbreviation from toLocaleTimeString
  const tzString = d.toLocaleTimeString("en-US", { timeZone: tz, timeZoneName: "short" });
  const match = tzString.match(/\s([A-Z]{2,5})$/);
  if (match) {
    return match[1];
  }
  
  // Fallback to known abbreviations
  if (TIMEZONE_ABBREVIATIONS[tz]) {
    // Determine if we're in daylight saving time
    const jan = new Date(d.getFullYear(), 0, 1);
    const jul = new Date(d.getFullYear(), 6, 1);
    const janOffset = new Date(jan.toLocaleString("en-US", { timeZone: tz })).getTime() - jan.getTime();
    const julOffset = new Date(jul.toLocaleString("en-US", { timeZone: tz })).getTime() - jul.getTime();
    const nowOffset = new Date(d.toLocaleString("en-US", { timeZone: tz })).getTime() - d.getTime();
    
    // If current offset matches the smaller offset (more negative = further from UTC), it's daylight time
    const isDST = Math.max(janOffset, julOffset) === nowOffset;
    return isDST ? TIMEZONE_ABBREVIATIONS[tz].daylight : TIMEZONE_ABBREVIATIONS[tz].standard;
  }
  
  // Last resort: return a shortened version of the IANA name
  const parts = tz.split("/");
  return parts[parts.length - 1].replace(/_/g, " ");
}

/**
 * Gets the UTC offset in hours for a given timezone.
 * @param {string} timezone - IANA timezone identifier or "local" or "UTC".
 * @returns {number} Offset in hours (e.g., -5 for EST, -8 for PST).
 */
function getTimezoneOffsetHours(timezone = "local") {
  if (timezone === "UTC") return 0;
  const tz = resolveTimezone(timezone);
  const now = new Date();
  const offsetMs = getTimeZoneOffsetMs(now, tz);
  return offsetMs / 3600000;
}

/**
 * Formats a timezone offset as a string (e.g., "(UTC -05:00)", "(UTC +00:00)").
 * @param {number} offsetHours - Offset in hours.
 * @returns {string} Formatted offset string.
 */
function formatTimezoneOffset(offsetHours) {
  const sign = offsetHours >= 0 ? "+" : "-";
  // Round to nearest minute to avoid floating-point precision issues
  const totalMinutes = Math.round(Math.abs(offsetHours) * 60);
  const hours = String(Math.floor(totalMinutes / 60)).padStart(2, "0");
  const minutes = String(totalMinutes % 60).padStart(2, "0");
  return `(UTC ${sign}${hours}:${minutes})`;
}

/**
 * Returns the time portion ("hh:00" or "hh:mm") of an epoch timestamp in a specific timezone.
 * @param {number} timestamp - Epoch timestamp in milliseconds.
 * @param {string} [timezone="local"] - IANA timezone identifier, "local", or "UTC".
 * @param {number} [minutes=0] - 0 to force minutes as "00", 1 to include actual minutes.
 * @returns {string} Time string.
 */
function getTimeFromEpoch(timestamp, timezone = "local", minutes = 0) {
  const tz = resolveTimezone(timezone);
  const date = new Date(timestamp);
  const formatter = new Intl.DateTimeFormat("en-US", {
    timeZone: tz,
    hour: "2-digit",
    minute: "2-digit",
    hour12: false
  });
  const parts = formatter.formatToParts(date);
  const hours = parts.find(p => p.type === "hour").value;
  const min = parts.find(p => p.type === "minute").value;
  if (minutes === 0) {
    return hours + ":00";
  } else {
    return hours + ":" + min;
  }
}

/**
 * Returns the date portion ("MM/DD/YYYY") of an epoch timestamp in a specific timezone.
 * @param {number} timestamp - Epoch timestamp in milliseconds.
 * @param {string} [timezone="local"] - IANA timezone identifier, "local", or "UTC".
 * @returns {string} Date string.
 */
function getDateFromEpoch(timestamp, timezone = "local") {
  const tz = resolveTimezone(timezone);
  const date = new Date(timestamp);
  const formatter = new Intl.DateTimeFormat("en-US", {
    timeZone: tz,
    month: "2-digit",
    day: "2-digit",
    year: "numeric"
  });
  return formatter.format(date);
}

/**
 * Returns the combined date and time string from an epoch timestamp.
 * @param {number} timestamp - Epoch timestamp in milliseconds.
 * @param {string} [timezone="local"] - IANA timezone identifier, "local", or "UTC".
 * @param {number} [minutes=0] - 0 to force minutes as "00", 1 to include actual minutes.
 * @returns {string} Date and time string.
 */
function getDateTimeFromEpoch(timestamp, timezone = "local", minutes = 0) {
  const mydate = getDateFromEpoch(timestamp, timezone);
  const mytime = getTimeFromEpoch(timestamp, timezone, minutes);
  const mytz = getTimezoneAbbreviation(timezone);
  return `${mydate} ${mytime} ${mytz}`;
}

/**
 * Gets the currently selected timezone from the #localOrUtc dropdown.
 * @returns {string} IANA timezone identifier, "local", or "UTC".
 */
function getSelectedTimezone() {
  const e = document.getElementById("localOrUtc");
  if (!e || e.options.length === 0) {
    return "local"; // Default to local if dropdown not yet initialized
  }
  return e.options[e.selectedIndex].value;
}

/**
 * Initializes the timezone dropdown with popular timezones.
 * Filters out duplicates, sorts by UTC offset (descending), and formats as "(UTC -05:00) EST".
 * Defaults to the user's local timezone.
 */
function initTimezoneDropdown() {
  const select = document.getElementById("localOrUtc");
  if (!select) return;
  
  // Clear existing options
  select.innerHTML = "";
  
  // Track which display strings we've added to avoid visual duplicates
  // (different IANA IDs can have the same offset and abbreviation)
  const addedDisplayStrings = new Set();
  
  // Build list of timezone options with their metadata
  const tzOptions = [];
  let localOptionIndex = -1;
  
  TIMEZONES.forEach((tz) => {
    const abbrev = getTimezoneAbbreviation(tz.id);
    const offset = getTimezoneOffsetHours(tz.id);
    const offsetStr = formatTimezoneOffset(offset);
    const displayText = `${offsetStr} ${abbrev}`;
    
    // Skip if we've already added a timezone with the same display text
    // (e.g., user's local EST matches America/New_York EST)
    if (tz.id !== "local" && addedDisplayStrings.has(displayText)) {
      return;
    }
    
    // Mark this display string as added
    addedDisplayStrings.add(displayText);
    
    tzOptions.push({
      id: tz.id,
      abbrev: abbrev,
      offset: offset,
      text: displayText,
      isLocal: tz.id === "local"
    });
  });
  
  // Sort by UTC offset (descending - highest/easternmost first)
  tzOptions.sort((a, b) => b.offset - a.offset);
  
  // Add sorted options to the select and track local option index
  tzOptions.forEach((tz, index) => {
    const option = document.createElement("option");
    option.value = tz.id;
    option.text = tz.text;
    select.appendChild(option);
    
    if (tz.isLocal) {
      localOptionIndex = index;
    }
  });
  
  // Default to the user's local timezone
  if (localOptionIndex >= 0) {
    select.selectedIndex = localOptionIndex;
  }
  
  // Add change handler to update the datepicker display when timezone changes
  select.addEventListener("change", onTimezoneChange);
}

/**
 * Handles timezone dropdown change.
 * Converts the current time to the new timezone display.
 */
function onTimezoneChange() {
  // Get the current epoch time from settings (this is always UTC)
  const epochMs = currentSettings["time"] * 1000;
  const newTz = getSelectedTimezone();
  
  // Update the datepicker to show the time in the new timezone
  const newdate = getDateFromEpoch(epochMs, newTz);
  const newtime = getTimeFromEpoch(epochMs, newTz, 0);
  $("#datepicker").val(`${newdate} ${newtime}`);
  
  // Update datetimepicker constraints
  setMaxTime();
  
  // Update info window time display if it's open
  const iwTimeDisplay = document.getElementById("iw-time-display");
  if (iwTimeDisplay) {
    iwTimeDisplay.innerHTML = `${getDateTimeFromEpoch(epochMs, "UTC", 0)}<br>${getDateTimeFromEpoch(epochMs, newTz, 0)}`;
  }
  
  // If plot panel is open, refresh with new timezone
  if (window.lastPlotUrl && document.getElementById('liveToast').classList.contains('show')) {
    // Update the URL with new timezone
    const url = new URL(window.lastPlotUrl, window.location.origin);
    url.searchParams.set('tz', resolveTimezone(newTz));
    window.lastPlotUrl = url.pathname + url.search;
    
    $("#newWindowPlot").attr("href", window.lastPlotUrl);
    $("#iframe-plots").attr("src", '');
    setTimeout(function() {
      $("#iframe-plots").attr("src", window.lastPlotUrl);
    }, 10);
  }
}

/**
 * Converts the date/time in the #datepicker input box to epoch milliseconds.
 * Handles conversion from the currently selected timezone to UTC.
 * @returns {number} Epoch time in milliseconds.
 */
function convertDateTimeTextToEpoch() {
  const dateTimeStr = $("#datepicker").val();
  const timezone = getSelectedTimezone();
  const tz = resolveTimezone(timezone);
  
  // Parse the date string (MM/DD/YYYY HH:mm format)
  const [datePart, timePart] = dateTimeStr.split(" ");
  const [month, day, year] = datePart.split("/").map(Number);
  const [hours, minutes] = timePart.split(":").map(Number);
  
  if (tz === "UTC") {
    return Date.UTC(year, month - 1, day, hours, minutes);
  }

  // Convert wall-clock time in target timezone to UTC epoch.
  // Use the timezone offset at that instant (iterate to handle DST transitions).
  let utcGuess = Date.UTC(year, month - 1, day, hours, minutes, 0);
  for (let i = 0; i < 2; i++) {
    const offsetMs = getTimeZoneOffsetMs(new Date(utcGuess), tz);
    const corrected = Date.UTC(year, month - 1, day, hours, minutes, 0) - offsetMs;
    if (corrected === utcGuess) {
      break;
    }
    utcGuess = corrected;
  }
  return utcGuess;
}

/**
 * Sets the maximum selectable time based on the product type.
 * For hourly products, maxTime is one hour before current time;
 * for others, maxTime is 14 hours before.
 */
function setMaxTime() {
  maxTime = new Date();
  maxTime.setMinutes(0, 0, 0);
  if (currentSettings["prod"].toString().includes("h")) {
    maxTime = maxTime - 60 * 60 * 1000;
  } else {
    maxTime = maxTime - 60 * 60 * 14 * 1000;
  }
  const tz = getSelectedTimezone();
  $("#datepicker").datetimepicker({
    maxDate: getDateFromEpoch(maxTime, tz),
    maxTime: getTimeFromEpoch(maxTime, tz, 0),
  });
  updateTime(0);
}

/**
 * Enables or disables the "one more" options based on the datepicker value.
 */
function enableDisableOneMoreOptions() {
  const tb = convertDateTimeTextToEpoch();
  if (tb === maxTime) {
    $("#onemoreday, #onemorehour").css("pointer-event", "none");
  } else {
    $("#onemoreday, #onemorehour").css("pointer-event", "auto");
  }
}

/**
 * Computes a new geographic position given a starting lat/lon, a distance, and a bearing.
 * @param {number} lat1 - Starting latitude.
 * @param {number} lon1 - Starting longitude.
 * @param {number} dist - Distance to travel.
 * @param {number} bear - Bearing in decimal degrees (from North, 90 = East).
 * @param {number} [R=6372.795] - Earth's radius in kilometers.
 * @returns {Array<number>} Array containing [latitude, longitude] in decimal degrees.
 */
function computeEarthPosition(lat1, lon1, dist, bear, R = 6372.795) {
  const lat1Rad = deg2rad(lat1);
  const lon1Rad = deg2rad(lon1);
  const Az = deg2rad(bear);
  const c = dist / R;

  const lat2 = Math.asin(
    Math.sin(lat1Rad) * Math.cos(c) +
      Math.cos(lat1Rad) * Math.sin(c) * Math.cos(Az)
  );
  const t1 = Math.sin(c) * Math.sin(Az);
  const t2 =
    Math.cos(lat1Rad) * Math.cos(c) -
    Math.sin(lat1Rad) * Math.sin(c) * Math.cos(Az);
  const lon2 = lon1Rad + Math.atan2(t1, t2);

  return [rad2deg(lat2), rad2deg(lon2)];
}

/**
 * Converts degrees to radians.
 * @param {number} degrees
 * @returns {number} Radians.
 */
function deg2rad(degrees) {
  return degrees * (Math.PI / 180);
}

/**
 * Converts radians to degrees.
 * @param {number} radians
 * @returns {number} Degrees.
 */
function rad2deg(radians) {
  return radians * (180 / Math.PI);
}

/**
 * Finds the closest radial (geojson feature) to a click event and displays an info window.
 * @param {google.maps.MouseEvent} e - The click event.
 */
function findClosestRadial(e) {
  let closestDistance = Infinity; // Closest distance between where we click and a radial
  let closestRadial; // Geojson element with the closest distance
  let closestProduct; // Product associated with the closest radial

  // Exit if no geojson radials are loaded
  if ($.isEmptyObject(geojson_radials)) return;

  // Loop over all products and their associated geojson features
  for (const prod in geojson_radials) {
    geojson_radials[prod].features.forEach((feature) => {
      const radial = feature.geometry.coordinates;
      const latlon = new google.maps.LatLng({ lat: radial[1], lng: radial[0] });
      const d = google.maps.geometry.spherical.computeDistanceBetween(
        latlon,
        e.latLng
      );
      if (d < closestDistance) {
        closestDistance = d;
        closestRadial = feature; // Save the feature (radial) that is closest
        closestProduct = prod; // Save the product associated with this radial
      }
    });
  }

  createInfoWindow(closestRadial, closestProduct); // Pass the closest radial and product to the info window function
}


/**
 * Creates and displays an info window for the selected radial.
 * @param {Object} closestRadial - The geojson feature for the radial.
 * @param {string} prod - The product identifier.
 */
function createInfoWindow(closestRadial, prod) {
  const radial = closestRadial;
  const latlon = radial.geometry.coordinates;  
  const head = radial.properties.head;
  const magni = radial.properties.magni * 100;
  const u = radial.properties.u * 100;
  const v = radial.properties.v * 100;

  // Define timeseries based on product (prod)
  let timeseries = '';
  switch (prod.slice(0, prod.indexOf("_"))) {
    case "a":
      timeseries = "25 hr Averaged";
      break;
    case "h":
      timeseries = "Hourly";
      break;
    case "ma":
      timeseries = "Month Averaged";
      break;
    case "ya":
      timeseries = "Year Averaged";
      break;
    default:
      timeseries = "Unknown"; // In case prod doesn't match any of the cases
  }

  const content = `
    <div class='iw'>
      <b>Coords:</b> ${latlon[1].toFixed(4)}, ${latlon[0].toFixed(4)}<br>
      <b>Resolution:</b> ${prod}, ${timeseries}<br>
      <div style="display: flex; align-items: flex-start;">
        <div style="width: 40px;"><b>Time:</b></div>
        <div id="iw-time-display" style="text-align: left;">
          ${getDateTimeFromEpoch(currentSettings['time'] * 1000, "UTC", 0)}<br>
          ${getDateTimeFromEpoch(currentSettings['time'] * 1000, getSelectedTimezone(), 0)}
        </div>
      </div>
      <br>
      <fieldset><legend>Current Vector</legend>
      <img src='img/php/vi.php?u=${+u.toFixed(1)}&v=${v.toFixed(1)}&range=0,50&scheme=${currentSettings['cb']}&heading=${head.toFixed(2)}' alt='Direction' width='65' height='65' align='right' style="margin-right: 5px;">
      <b>U:</b> ${u.toFixed(1)}, <b>V:</b> ${v.toFixed(1)}<br>
      <b>Mag:</b> ${magni.toFixed(2)} cm/s<br>
      <b>Dir:</b> ${head.toFixed(2)}° from N</fieldset>
      <button type='button' class='btn btn-primary' id='liveToastBtn'>Time History</button>
    </div>
  `;

  // Set the info window content
  infowindow.setContent(content);
  infowindow.setPosition(new google.maps.LatLng({ lat: latlon[1], lng: latlon[0] }));
  infowindow.open(map);
  currentInfoWindowType = 'radials';

  // Store the plot URL and lat/lon globally for use when the plot window is shown
  const myurl = `plots.php?lat1=${latlon[1].toFixed(6)}&lon1=${latlon[0].toFixed(6)}&prod=${prod}&time=${currentSettings['time']}&tz=${encodeURIComponent(resolveTimezone(getSelectedTimezone()))}`;
  window.lastPlotUrl = myurl;
  window.lastPlotLatLon = latlon[1].toFixed(4) + ", " + latlon[0].toFixed(4);

  // If the plot toast is already visible, update the iframe and link immediately
  if (document.getElementById('liveToast').classList.contains('show')) {
    $("#newWindowPlot").attr("href", window.lastPlotUrl);
    $("#iframe-plots").attr("src", '');
    setTimeout(function() {
      $("#iframe-plots").attr("src", window.lastPlotUrl);
    }, 10);
    $("#latlonplots").text(window.lastPlotLatLon);
  }
}


/**
 * Initializes the Google Map, sets up overlays, event listeners, and loads initial data.
 */
async function initMap() {
  const { Map } = await google.maps.importLibrary("maps");
  
  // Import geometry library to ensure google.maps.geometry.spherical is available
  await google.maps.importLibrary("geometry");

  setColorbar();
  setColorbarWaves();

  map = new Map(document.getElementById("map"), {
    zoom: currentSettings["zm"],
    mapId: "DEMO_MAP_ID",
    mapTypeId: "terrain",
    clickableIcons: false,
    mapTypeControl: true,
    mapTypeControlOptions: {
      mapTypeIds: ["terrain", "satellite", "roadmap"],
      position: google.maps.ControlPosition.LEFT_BOTTOM,
    },
    center: getLatLngObject(currentSettings["ll"]),
    restriction: {
      latLngBounds: {
        north: 85,
        south: -85,
        east: 180,
        west: -180
      },
      strictBounds: true
    }
  });

  infowindow = new google.maps.InfoWindow();
  lineSymbol = { path: google.maps.SymbolPath.FORWARD_OPEN_ARROW };

  // Clear the infowindow type and hide plot window when it's closed
  infowindow.addListener('closeclick', () => {
    $("#liveToast").toast("hide");
    currentInfoWindowType = null;
  });

  // Click listener: show closest radial info
  map.addListener("click", findClosestRadial);

  map.addListener("idle", () => {
    // If zooming in further, update bounds
    if (map.getZoom() > currentSettings["zm"]) {
      if (
        map.getZoom() >= 7 &&
        currentSettings["zm"] < 7 &&
        skipcounter === 4
      ) {
        mapTimeout = setTimeout(refreshRadials, 1000);
      }
      updateMapBoundsZoom();
      return;
    }
    // If panning only slightly, don’t redraw
    const d = google.maps.geometry.spherical.computeDistanceBetween(
      map.getCenter(),
      getLatLngObject(currentSettings["ll"])
    );
    if (d < 10000 && map.getZoom() === currentSettings["zm"]) return;

    jqxhr.abort();
    clearTimeout(mapTimeout);
    mapTimeout = setTimeout(refreshRadials, 1000);
  });

  // Initial loading once the map is idle
  google.maps.event.addListenerOnce(map, "idle", () => {
    jqxhr.abort();
    redrawRadials();

    if (currentSettings["o_sp"] === 1) {
      mylayers["check_stations"] = new google.maps.KmlLayer({
        url: `${STATION_KML}?timestamp=${utcMillisecondsSinceEpoch}`,
        map: map,
        preserveViewport: true,
      });
      $("#stationLegend").show();
    }
    if (currentSettings["o_wa"] === 1) {
      mylayers["check_waves"] = getWavesData();
      map.data.add(mylayers["check_waves"]);
      $("#wavesLegend").show();
    }
    if (currentSettings["o_o"] === 1) {
      mylayers["check_oilplatforms"] = new google.maps.KmlLayer({
        url: OIL_KML,
        map: map,
        preserveViewport: true,
      });
    }
    if (currentSettings["o_sl"] === 1) {
      mylayers["check_shippinglanes"] = getShippingData();
      map.data.add(mylayers["check_shippinglanes"]);
      $("#shippingLanesLegend").show();
    }

    const clockElement = document.getElementById("clock");
    const gmtClockElement = document.getElementById("clock_gmt");
    function clock() {
      clockElement.textContent = getDateTimeFromEpoch(Date.now(), getSelectedTimezone(), 1);
      gmtClockElement.textContent = getDateTimeFromEpoch(Date.now(), "UTC", 1);
    }
    setInterval(clock, 1000);
  });

  distanceTool = new gmapsDistanceTool(map, "distanceTool", { top_offset: 15 });
}

/**
 * Refreshes radials (polylines) on the map based on the current product.
 * Uses last API response saved in geojson_radials.
 */
function refreshRadials() {
  currentSettings["prod"].forEach((prod) =>
      drawRadials(geojson_radials[prod])
  );
}

/**
 * Draws radials (polylines) on the map based on geojson data.
 * Adjusts vector length and skip rate based on the zoom level.
 * @param {Object} rs - Geojson response containing features and product info.
 */
function drawRadials(rs) {
  if (!rs) return;

  const polylines = [];
  let vectorLength = 1;
  const prod = rs.product;

  if (map.getZoom() >= 7) {
    skipcounter = 1;
    if (prod.includes("6km")) {
      vectorLength = 2;
    } else if (prod.includes("2km")) {
      vectorLength = 1;
    } else if (prod.includes("1km")) {
      vectorLength = 0.5;
    } else {
      vectorLength = 0.25;
    }
  } else {
    skipcounter = 4;
    if (prod.includes("6km")) {
      vectorLength = 5;
    } else if (prod.includes("2km")) {
      vectorLength = 3;
    } else if (prod.includes("1km")) {
      vectorLength = 2;
    } else {
      vectorLength = 1;
    }
  }

  updateMapBoundsZoom();
  if (!verifyProductsResolutions(prod)) return;

  for (let i = 0; i < rs.features.length; i += skipcounter) {
    const radial = rs.features[i].geometry.coordinates;
    const latlon2 = computeEarthPosition(
      radial[1],
      radial[0],
      vectorLength,
      rs.features[i].properties.head
    );
    const line = new google.maps.Polyline({
      path: [
        { lat: radial[1], lng: radial[0] },
        { lat: latlon2[0], lng: latlon2[1] },
      ],
      strokeWeight: 0.7,
      strokeColor:
        "#" +
        colorbar.colourAt(changeVectorUnits(rs.features[i].properties.magni)),
      icons: [{ icon: lineSymbol, offset: "100%" }],
      map: map,
    });
    polylines.push(line);
  }

  document.body.style.cursor = "default";

  if (myPolylines[prod] && myPolylines[prod].length) {
    myPolylines[prod].forEach((polyline) => polyline.setMap(null));
    delete myPolylines[prod];
    delete geojson_radials[prod];
  }

  myPolylines[prod] = polylines;
  geojson_radials[prod] = rs;
}

/**
 * converts product identifier into human-readable product for missing data alert.
 * @param prod
 */
function makeProdReadable(prod) {
  let res = prod.split('_')[1]

  let interval;
  switch (prod.split('_')[0]) {
    case "a":
      interval = "25hr Averaged";
      break;
    case "h":
      interval = "Hourly";
      break;
    case "ma":
      interval = "Month Averaged";
      break;
    case "ya":
      interval = "Year Averaged";
      break;
    default:
      interval = "Unknown"; // In case prod doesn't match any of the cases
  }

  return `${res} ${interval}`;
}

/**
 * Shows a toast notification with the specified message
 * @param {string} message - The message to display in the toast
 */
function showToastNotification(message) {
  const toastContainer = document.getElementById('alertToastContainer');
  const toastElement = document.getElementById('alertToast');
  const toastBodyElement = document.getElementById('alertToastBody');
  const mapElement = document.getElementById('map');
  
  // Move the toast container to be a child of the map element for proper positioning
  if (toastContainer && mapElement && !mapElement.contains(toastContainer)) {
    mapElement.appendChild(toastContainer);
  }
  
  toastBodyElement.textContent = message;
  
  const toast = new bootstrap.Toast(toastElement);
  toast.show();
}

/**
 * Alerts the user that there are no radials found for a given prod/time combo (due to error or simply no data)
 */
function alertMissingRadials(prod) {
  showToastNotification(`No ${makeProdReadable(prod)} data found at the selected time.`);
}

/**
 * Alerts the user that there are no waves found for a given time (due to database error or simply no data)
 */
function alertMissingWaves() {
  showToastNotification(`No waves data found at the selected time.`);
}

/**
 * Loads all radial data for a given prod/time from the API and draws it on the map.
 * API response is saved in a global variable so that pan/zoom events do not have to make additional API calls.
 * @param {string} prod - Product identifier.
 * @param {number} time - Epoch time in seconds.
 */
function loadRadials(prod, time) {
  document.body.style.cursor = "wait";

  jqxhr = $.ajax({
    url: `${API_URL}/`,
    data: {
      time: time,
      prod: prod,
    },
    dataType: "jsonp",
    type: "GET",
    contentType: "application/json; charset=utf-8",
    success: (results, status, xhr) => {
      verifyProductsResolutions(prod);
      drawRadials(results);
    },
    error: (xhr, status, error) => {
      console.log(
        `ERROR loadRadials(): ${prod} ${time} ${status}: ${error}: ${xhr.status}: ${xhr.statusText}`
      );
      if (status === "error") {
        alertMissingRadials(prod);
      }
      removeRadials(prod);
    },
  });
}

/**
 * Loads radial data from the API, constrained by the map bounds, and draws them on the map.
 * @param {google.maps.LatLngBounds} bounds - Current map bounds.
 * @param {string} prod - Product identifier.
 * @param {number} time - Epoch time in seconds.
 */
function loadRadialsBbox(bounds, prod, time) {
  document.body.style.cursor = "wait";
  const ne = bounds.getNorthEast();
  let sw = bounds.getSouthWest();
  if (ne.lng() <= sw.lng()) {
    sw = new google.maps.LatLng({ lat: sw.lat(), lng: -170 });
  }

  jqxhr = $.ajax({
    url: `${API_URL}/`,
    data: {
      lat1: ne.lat(),
      lat2: sw.lat(),
      lon1: sw.lng(),
      lon2: ne.lng(),
      time: time,
      prod: prod,
    },
    dataType: "jsonp",
    type: "GET",
    contentType: "application/json; charset=utf-8",
    success: (results, status, xhr) => {
      verifyProductsResolutions(prod);
      drawRadials(results);
    },
    error: (xhr, status, error) => {
      console.log(
          `ERROR loadRadials(): ${prod} ${time} ${status}: ${error}: ${xhr.status}: ${xhr.statusText}`
      );
      removeRadials(prod);
    },
  });
}

/**
 * Updates the currentSettings zoom level and center based on the map state.
 */
function updateMapBoundsZoom() {
  currentSettings["zm"] = map.getZoom();
  currentSettings["ll"] = map.getCenter().toUrlValue();
}

$(document).ready(() => {
  // Initialize timezone dropdown first (needed before datepicker setup)
  initTimezoneDropdown();

  // Setup colorbar ddslick
  $("#colorbar_ddslick").ddslick({});
  $("#colorbar_ddslick").on("click", () => {
    $(".dd-option-text").removeAttr("style");
  });

  // Setup colorbar editor values
  const rng = currentSettings["rng"].split(",");
  $("#colorbarmin").val(rng[0]);
  $("#colorbarmax").val(rng[1]);

  // Setup wave colorbar ddslick
  $("#colorbarWave_ddslick").ddslick({});
  $("#colorbarWave_ddslick").on("click", () => {
    $(".dd-option-text").removeAttr("style");
  });
  const wrng = currentSettings["wrng"].split(",");
  $("#colorbarwavemin").val(wrng[0]);
  $("#colorbarwavemax").val(wrng[1]);

  updateColorbarIcons();

  const initTz = getSelectedTimezone();
  const mydate = getDateFromEpoch(currentSettings["time"] * 1000, initTz);
  const mytime = getTimeFromEpoch(currentSettings["time"] * 1000, initTz, 0);
  $("#datepicker").val(`${mydate} ${mytime}`);

  $("#datepicker").datetimepicker({
    format: "m/d/Y H:i",
    defaultDate: mydate,
    defaultTime: mytime,
    formatDate: "m/d/Y",
    minDate: "06/01/2006",
    maxDate: "0",
    scrollTime: false,
    onSelectDate: function (ct, input) {
      const tz = getSelectedTimezone();
      const dt = input.val().split(" ");
      if (dt[0] === getDateFromEpoch(Date.now(), tz)) {
        this.setOptions({ maxTime: getTimeFromEpoch(maxTime, tz, 0) });
        this.setOptions({
          defaultTime: getTimeFromEpoch(
            utcMillisecondsSinceEpoch - 60 * 60 * 2000,
            tz,
            0
          ),
        });
      } else {
        this.setOptions({ maxTime: false });
      }
      enableDisableOneMoreOptions();
    },
  });

  // Check default product checkboxes
  currentSettings["prod"].forEach((prod) => {
    document.getElementById(prod).checked = true;
  });

  $(document).on("click",'#liveToastBtn',function(){
    // When the Time History button is clicked, show the toast and set the iframe and link
    $("#liveToast").toast("show");
    if (window.lastPlotUrl && window.lastPlotLatLon) {
      $("#newWindowPlot").attr("href", window.lastPlotUrl);
      $("#iframe-plots").attr("src", window.lastPlotUrl);
      $("#latlonplots").text(window.lastPlotLatLon);
    }
  });

  // When we click on an RTV product checkbox, load or remove its radials
  $("#productsResolutions input:checkbox").click(function () {
    if (this.checked) {
      if (!currentSettings["prod"].includes(this.value)) {
        loadRadials(this.value, currentSettings["time"]);
        currentSettings["prod"].push(this.value);
      }
    } else {
      // Always remove the product regardless of myPolylines status
      removeRadials(this.value);
      currentSettings["prod"] = currentSettings["prod"].filter(
        (e) => e !== this.value
      );
      // Only close infowindow and plot window if it's showing radial data
      if (currentInfoWindowType === 'radials') {
        infowindow.close();
        $("#liveToast").toast("hide");
        currentInfoWindowType = null;
      }
    }
    setMaxTime();
    enableDisableOneMoreOptions();
  });

  // Toggle station placemarks
  $("#check_stations").click(function () {
    if (this.checked) {
      mylayers[this.id] = new google.maps.KmlLayer({
        url: `${STATION_KML}?timestamp=${utcMillisecondsSinceEpoch}`,
        map: map,
        preserveViewport: true,
      });
      $("#stationLegend").show();
      currentSettings["o_sp"] = 1;
    } else {
      mylayers[this.id].setMap(null);
      $("#stationLegend").hide();
      currentSettings["o_sp"] = 0;
      // Only close infowindow and plot window if it's showing station data
      if (currentInfoWindowType === 'stations') {
        infowindow.close();
        $("#liveToast").toast("hide");
        currentInfoWindowType = null;
      }
    }
  });

  // Toggle waves
  $("#check_waves").click(function () {
    if (this.checked) {
      mylayers[this.id] = getWavesData();
      map.data.add(mylayers[this.id]);
      $("#wavesLegend").show();
      currentSettings["o_wa"] = 1;
    } else {
      deleteWavesData();
      $("#wavesLegend").hide();
      currentSettings["o_wa"] = 0;
      // Only close infowindow and plot window if it's showing wave data
      if (currentInfoWindowType === 'waves') {
        infowindow.close();
        $("#liveToast").toast("hide");
        currentInfoWindowType = null;
      }
    }
  });

  // Toggle oil platforms
  $("#check_oilplatforms").click(function () {
    if (this.checked) {
      mylayers[this.id] = new google.maps.KmlLayer({
        url: OIL_KML,
        map: map,
        preserveViewport: true,
      });
      currentSettings["o_o"] = 1;
    } else {
      mylayers[this.id].setMap(null);
      currentSettings["o_o"] = 0;
    }
  });

  // Toggle shipping lanes
  $("#check_shippinglanes").click(function () {
    if (this.checked) {
      mylayers[this.id] = getShippingData();
      map.data.add(mylayers[this.id]);
      $("#shippingLanesLegend").show();
      currentSettings["o_sl"] = 1;
    } else {
      mylayers[this.id].setMap(null);
      $("#shippingLanesLegend").hide();
      currentSettings["o_sl"] = 0;
    }
  });

  // Coordinate locator: place marker based on input lat/lon
  $("#latlonlocator").click(function () {
    $("#lat, #lon").removeClass("is-invalid");
    if ($("#lat").val() <= 0 || $("#lat").val() >= 90) {
      $("#lat").removeClass("is-valid").addClass("is-invalid");
      return;
    }
    if ($("#lon").val() <= -181 || $("#lon").val() >= -30) {
      $("#lon").removeClass("is-valid").addClass("is-invalid");
      return;
    }
    if (latlonlocator) {
      latlonlocator.setMap(null);
    }
    latlonlocator = new google.maps.Marker({
      position: new google.maps.LatLng($("#lat").val(), $("#lon").val()),
    });
    latlonlocator.setMap(map);
    map.setCenter(new google.maps.LatLng($("#lat").val(), $("#lon").val()));
  });

  // Remove coordinate locator marker
  $("#latlonlocatorremover").click(function () {
    if (latlonlocator) {
      latlonlocator.setMap(null);
      $("#lat").val("");
      $("#lon").val("");
    }
  });

  // Bookmark current map settings in URL
  $("#bookmark").click(function () {
    const url = `?zm=${currentSettings["zm"]}&ll=${currentSettings["ll"]}&prod=${currentSettings["prod"]}&time=${currentSettings["time"]}&rng=${currentSettings["rng"]}&cb=${currentSettings["cb"]}&us=${currentSettings["us"]}&o_sp=${currentSettings["o_sp"]}&o_o=${currentSettings["o_o"]}&o_sl=${currentSettings["o_sl"]}&wcb=${currentSettings["wcb"]}&wrng=${currentSettings["wrng"]}&o_wa=${currentSettings["o_wa"]}`;
    history.pushState(null, "", url);
  });

  // Toggle sidebar menu
  $("#openCloseSidebar").click(function () {
    if ($("#sidebarMenu").is(":visible")) {
      $("#sidebarMenu").hide();
      $("#mainbody").removeClass("col-md-9").addClass("col-md-12");
    } else {
      $("#sidebarMenu").show();
      $("#mainbody").removeClass("col-md-12").addClass("col-md-9");
    }
  });

  // Datepicker change event: update time and redraw radials if changed
  $("#datepicker").change(function () {
    if (
      Math.round(convertDateTimeTextToEpoch() / 1000) !==
      currentSettings["time"]
    ) {
      clearTimeout(dateClickTimeout);
      updateTime(0);
      dateClickTimeout = setTimeout(redrawRadials, 1000);
      if ($("#check_waves").is(":checked")) {
        setTimeout(getWavesData, 1000);
      }
    }
  });

  // Update time buttons (day/hour adjustments)
  $("#onelessday").click(function () {
    clearTimeout(dateClickTimeout);
    updateTime(-60 * 60 * 24);
    dateClickTimeout = setTimeout(redrawRadials, 1000);
    if ($("#check_waves").is(":checked")) getWavesData();
  });

  $("#onelesshour").click(function () {
    clearTimeout(dateClickTimeout);
    updateTime(-60 * 60);
    dateClickTimeout = setTimeout(redrawRadials, 1000);
    if ($("#check_waves").is(":checked")) getWavesData();
  });
  $("#onemorehour").click(function () {
    clearTimeout(dateClickTimeout);
    updateTime(60 * 60);
    dateClickTimeout = setTimeout(redrawRadials, 1000);
    if ($("#check_waves").is(":checked")) getWavesData();
  });
  $("#onemoreday").click(function () {
    clearTimeout(dateClickTimeout);
    updateTime(60 * 60 * 24);
    dateClickTimeout = setTimeout(redrawRadials, 1000);
    if ($("#check_waves").is(":checked")) getWavesData();
  });
});

/**
 * Updates the datetime selector by adjusting the current epoch time.
 * @param {number} epochseconds - Seconds to advance (or subtract if negative).
 */
function updateTime(epochseconds) {
  const tz = getSelectedTimezone();
  let epochtime = convertDateTimeTextToEpoch() + epochseconds * 1000;

  if (epochtime > maxTime) {
    epochtime = maxTime;
    $("#onemoreday, #onemorehour").css("pointer-event", "none");
  } else {
    $("#onemoreday, #onemorehour").css("pointer-event", "auto");
  }

  const newdate = getDateFromEpoch(epochtime, tz);
  const newtime = getTimeFromEpoch(epochtime, tz, 0);
  $("#datepicker").val(`${newdate} ${newtime}`);
  currentSettings["time"] = epochtime / 1000;
}

/**
 * Redraws all radials currently displayed on the map.
 */
function redrawRadials() {
  currentSettings["prod"].forEach((prod) =>
    loadRadials(prod, currentSettings["time"])
  );
}

/**
 * Verifies that the product checkbox is checked.
 * @param {string} prod - Product identifier.
 * @returns {boolean} True if checked; false otherwise.
 */
function verifyProductsResolutions(prod) {
  return $("#" + prod).prop("checked") === true;
}

/**
 * Removes radials (polylines) for the specified product from the map and global arrays.
 * @param {string} prod - Product identifier.
 */
function removeRadials(prod) {
  if (prod in myPolylines) {
    myPolylines[prod].forEach((polyline) => polyline.setMap(null));
    delete myPolylines[prod];
    delete geojson_radials[prod];
  }
}

/**
 * Sets up the colorbar based on current settings.
 */
function setColorbar() {
  colorbar = new Rainbow();
  switch (parseInt(currentSettings["cb"])) {
    case 0:
      colorbar.setSpectrum(
        "#000080",
        "#0080ff",
        "#80ff80",
        "#ffff00",
        "#ff0000",
        "#800000"
      );
      break;
    case 1:
      colorbar.setSpectrum("#ffffff", "#ffff00", "#ff8000", "#800000");
      break;
    case 2:
      colorbar.setSpectrum(
        "#002874",
        "#0050e9",
        "#44c297",
        "#4fc222",
        "#ff8000",
        "#ff0000",
        "#800000"
      );
      break;
    case 3:
      colorbar.setSpectrum("#30c0c0", "#e010a2");
      break;
    default: // case 4
      colorbar.setSpectrum(
        "#2347bd",
        "#3760e3",
        "#00c914",
        "#ffa500",
        "#b30600",
        "#8e0500"
      );
      break;
  }
  const mymin = parseFloat($("#colorbarmin").val());
  const mymax = parseFloat($("#colorbarmax").val());
  colorbar.setNumberRange(mymin, mymax);
}

/**
 * Sets up the wave colorbar based on current settings.
 */
function setColorbarWaves() {
  colorbarWaves = new Rainbow();
  switch (parseInt(currentSettings["wcb"])) {
    case 0:
      colorbarWaves.setSpectrum(
        "#000080",
        "#0080ff",
        "#80ff80",
        "#ffff00",
        "#ff0000",
        "#800000"
      );
      break;
    case 1:
      colorbarWaves.setSpectrum("#ffffff", "#ffff00", "#ff8000", "#800000");
      break;
    case 2:
      colorbarWaves.setSpectrum(
        "#002874",
        "#0050e9",
        "#44c297",
        "#4fc222",
        "#ff8000",
        "#ff0000",
        "#800000"
      );
      break;
    case 3:
      colorbarWaves.setSpectrum("#30c0c0", "#e010a2");
      break;
    default: // case 4
      colorbarWaves.setSpectrum(
        "#2347bd",
        "#3760e3",
        "#00c914",
        "#ffa500",
        "#b30600",
        "#8e0500"
      );
      break;
  }
  const wrng = currentSettings["wrng"].split(",");
  const mymin = parseFloat(wrng[0]);
  const mymax = parseFloat(wrng[1]);
  colorbarWaves.setNumberRange(mymin, mymax);
}

/**
 * Converts the vector magnitude from meters to the units specified in currentSettings.
 * @param {number} inputvalue - Value in meters.
 * @returns {number} Converted value.
 */
function changeVectorUnits(inputvalue) {
  return inputvalue * currentSettings["us"];
}

/**
 * Returns the units text (e.g., "cm/s", "m/s") based on the current units setting.
 * @returns {string} Units text.
 */
function getUnitsText() {
  switch (currentSettings["us"]) {
    case 100:
      return "cm/s";
    case 1:
      return "m/s";
    case 3.6:
      return "kph";
    case 1.944:
      return "kts";
    case 2.237:
      return "mph";
    case 3.281:
      return "ft/s";
    default:
      return "m/s";
  }
}

/**
 * Updates the colorbar icons based on the current settings.
 */
function updateColorbarIcons() {
  const rng = currentSettings["rng"].split(",");
  let imgsrc = `img/php/cb.php?range_min=${rng[0]}&range_max=${
    rng[1]
  }&width=154&height=15&padding=15,8&font_size=10&title=Current%20Strength (${getUnitsText()})&scheme=${
    currentSettings["cb"]
  }&bg=0x7fffffff&ticks=6`;
  $("#img_colorbar").attr("src", imgsrc);
  $("#colorbar_ddslick").ddslick("select", { index: currentSettings["cb"] });

  const wrng = currentSettings["wrng"].split(",");
  imgsrc = `img/php/cb.php?range_min=${wrng[0]}&range_max=${wrng[1]}&width=154&height=15&padding=15,8&font_size=10&title=Wave%20Height (m)&scheme=${currentSettings["wcb"]}&bg=0x7fffffff&ticks=6`;
  $("#img_colorbarWaves").attr("src", imgsrc);
  $("#colorbarWave_ddslick").ddslick("select", {
    index: currentSettings["wcb"],
  });
}

/**
 * Changes the vector color settings and redraws the radials.
 */
function changeVectorColors() {
  const ddData = $("#colorbar_ddslick").data("ddslick");
  currentSettings["cb"] = ddData["selectedData"]["value"];
  currentSettings["rng"] =
    $("#colorbarmin").val() + "," + $("#colorbarmax").val();
  updateColorbarIcons();
  setColorbar();
  redrawRadials();
}

/**
 * Changes the wave color settings and refreshes the waves data.
 */
function changeWaveColors() {
  const ddData = $("#colorbarWave_ddslick").data("ddslick");
  currentSettings["wcb"] = ddData["selectedData"]["value"];
  currentSettings["wrng"] =
    $("#colorbarwavemin").val() + "," + $("#colorbarwavemax").val();
  updateColorbarIcons();
  setColorbarWaves();
  getWavesData();
}

initMap();

window.changeVectorColors = changeVectorColors;
window.changeWaveColors = changeWaveColors;
