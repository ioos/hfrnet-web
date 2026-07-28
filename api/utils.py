from datetime import datetime, timezone
from calendar import monthrange
import geopandas as gpd
from config import config
from shapely.geometry import Point


def find_closest(gdf, lat, lon):
    """Finds the closest available lat/lon in the input dataframe to a given lat/lon.

    :param gdf: dataframe containing lat/lon geometries
    :param lat: latitude to search for
    :param lon: longitude to search for
    :return: pandas series
    """
    input_point_df = gpd.GeoDataFrame([], geometry=[Point(lon, lat)], crs="EPSG:4326")
    joined = gpd.sjoin_nearest(input_point_df, gdf, how="left").iloc[0]
    joined.drop(labels=["index_right"], inplace=True)
    return joined


# TODO: just use shapely here?
def bounding_boxes_intersect(box1, box2):
    """Find if the two input bounding boxes intersect.

    :param box1: lat/lon bounding box in format [lat1, lon1, lat2, lon2]
    :param box2: lat/lon bounding box in format [lat1, lon1, lat2, lon2]
    :return: boolean
    """
    lat_ok = False
    lon_ok = False

    # Convert to lists of floats
    box1_float = [float(value) for value in box1]
    box2_float = [float(value) for value in box2]

    # For some reason, lat1/lat2 in the original code is expected to go from north to south instead of south to north
    # This code flips the lat1/lat2 if this is the case, so technically both north/south and south/north are supported
    if box1_float[0] > box1_float[2]:
        box1_float[0], box1_float[2] = box1_float[2], box1_float[0]
    if box2_float[0] > box2_float[2]:
        box2_float[0], box2_float[2] = box2_float[2], box2_float[0]

    # Check lats
    if box1_float[0] <= box2_float[2] and box1_float[2] >= box2_float[0]:
        lat_ok = True

    # Check for lon that cross date line (lon = 180)
    box1_crosses = box1_float[1] > box1_float[3]
    box2_crosses = box2_float[1] > box2_float[3]
    if box1_crosses and box2_crosses:
        # If both boxes cross date line, they must intersect in lon by definition
        lon_ok = True
    elif box1_crosses or box2_crosses:
        if box1_float[1] <= box2_float[3] or box1_float[3] >= box2_float[1]:
            lon_ok = True
    else:
        if box1_float[1] <= box2_float[3] and box1_float[3] >= box2_float[1]:
            lon_ok = True

    return lat_ok and lon_ok


def get_regions(lat1, lon1, lat2, lon2):
    """Finds all the regions in a given bounding box.

    :param lat1: start latitude (either north or south edge)
    :param lon1: start longitude (west edge)
    :param lat2: end latitude (opposite edge of lat1)
    :param lon2: end longitude (east edge)
    :return: list of regions
    """
    regions = []
    regions.extend(get_us_regions(lat1, lon1, lat2, lon2))
    return regions


def get_us_regions(lat1, lon1, lat2, lon2):
    """Finds all the US regions in a given bounding box.

    :param lat1: start latitude (either north or south edge)
    :param lon1: start longitude (west edge)
    :param lat2: end latitude (opposite edge of lat1)
    :param lon2: end longitude (east edge)
    :return: list of regions
    """
    valid_regions = []
    # Load regions and bounding boxes from config into a dict
    us_regions = dict(
        [(key, value.split(",")) for key, value in config.items("usregions")]
    )
    for region, region_bounds in us_regions.items():
        if bounding_boxes_intersect([lat1, lon1, lat2, lon2], region_bounds):
            # convert region string to uppercase to match s3 filesystem structure
            valid_regions.append(region.upper())

    return valid_regions


def last_day_of_month(epoch):
    """Finds the last day of the month that the given epoch time is in.

    :param epoch: epoch time
    :return: date object
    """
    date_value = datetime.fromtimestamp(epoch, timezone.utc)
    return date_value.replace(day=monthrange(date_value.year, date_value.month)[1])


def df_to_geojson(geo_df, properties, product, time):
    """Convert a dataframe to geojson

    :param geo_df: GeoPandas dataframe
    :param properties: list of properties to include in the geojson
    :param product: product name
    :param time: time value
    :return: geojson-serializable dictionary
    """
    geo_dict = geo_df[["geometry"] + properties].to_geo_dict(drop_id=True)
    geo_dict["product"] = product
    geo_dict["time"] = time
    return geo_dict


def float_or_none(value):
    """Converts a value to float, passing None through unchanged."""
    return float(value) if value is not None else None


def _read_waves_coord(preferred, fallback):
    """Picks a coordinate value, preferring the data-row value (LATD/LOND) over
    the file-header value (lat/lon) unless it is missing or zero.

    :return: float coordinate, or None if both values are missing
    """
    if preferred is not None and float(preferred) != 0:
        return float(preferred)
    if fallback is not None:
        return float(fallback)
    return None


def read_waves_row(row):
    """Reads/parses a row from the waves database

    Wave values can be NULL either because CODAR reported them as not
    calculable (999.00 sentinels are stored as NULL) or because the row is a
    station placeholder with no data sentence near the requested time. NULLs
    are passed through so callers can render them as missing (NaN).

    :param row: row from waves database
    :return: dict containing needed data from the row, None if coordinates are missing
    """
    lat = _read_waves_coord(row["LATD"], row["lat"])
    lon = _read_waves_coord(row["LOND"], row["lon"])

    # without coordinates there is nothing to place on the map
    if lat is None or lon is None:
        return None

    return {
        "lat": lat,
        "lon": lon,
        "time": row["time"],
        "sta": row["sta"],
        "net": row["net"],
        "MWHT": row["MWHT"],
        "MWPD": row["MWPD"],
        "WAVB": row["WAVB"],
        "WNDB": row["WNDB"],
    }
