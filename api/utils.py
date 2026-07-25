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


def read_waves_row(row):
    """Reads/parses a row from the waves database

    :param row: row from waves database
    :return: tuple containing needed data from the row in the waves database, None if wave height is missing
    """
    if row["LATD"] is not None:
        if float(row["LATD"]) == 0:
            lat = float(row["lat"])
        else:
            lat = float(row["LATD"])
    else:
        lat = float(row["lat"])

    if row["LOND"] is not None:
        if float(row["LOND"]) == 0:
            lon = float(row["lon"])
        else:
            lon = float(row["LOND"])
    else:
        lon = float(row["lon"])

    # if MWHT is missing, skip the entire row cause that's the wave height
    if row["MWHT"] is None:
        return None

    # Fill missing values if needed. row is a read-only RowMapping, so apply
    # defaults here rather than mutating it (params can be NULL when CODAR
    # reports them as not calculable).
    return {
        "lat": lat,
        "lon": lon,
        "time": row["time"],
        "sta": row["sta"],
        "net": row["net"],
        "MWHT": row["MWHT"],
        "MWPD": row["MWPD"] if row["MWPD"] is not None else 0,
        "WAVB": row["WAVB"] if row["WAVB"] is not None else 0,
        "WNDB": row["WNDB"] if row["WNDB"] is not None else 0,
    }
