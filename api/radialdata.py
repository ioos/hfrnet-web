from sqlalchemy.ext.asyncio import create_async_engine
from sqlmodel.ext.asyncio.session import AsyncSession
from sqlalchemy.orm import sessionmaker
from datetime import datetime, timezone
from dateutil.relativedelta import relativedelta
from rtvprocessing import get_data, get_hist_data
from rtvfiles import get_files, get_hist_files
from config import config

# TODO: several variable names conflict, consider better naming conventions
#       or alias FastAPI parameter names for web requests
import time as time_module
from utils import get_regions, last_day_of_month, df_to_geojson, read_waves_row
from fastapi import FastAPI, APIRouter, HTTPException, Query, Request as FastAPIRequest
from fastapi.middleware.cors import CORSMiddleware

# from pydantic import model_validator
import logging
from pyinstrument import Profiler
from starlette.requests import Request
from sqlalchemy import text
from starlette.responses import Response
from fastapi.responses import HTMLResponse
#
# radialdata.py
#
# Changes
# 2022-01-01 - Initial version
# 2022-08-01 - Added GLNA US region
# 2023-04-27 - Added waves
#
# Arguments
# lat1
# lat2
# lon1
# lon2
# prod (default: a_6km)
# time (epoch utc time.  default: 15 hours ago)
# t1 (epoch utc time.  default: 15 hours ago)
# t0 (epoch utc time)
# out (default: json)
# debug (default: False)
#

app = FastAPI()

app.add_middleware(
    CORSMiddleware,
    allow_origins=[
        o.strip() for o in config["cors"]["allowed_origins"].split(",") if o.strip()
    ],
    allow_credentials=False,
    allow_methods=["GET"],
    allow_headers=["*"],
)

radials_router = APIRouter()

logger = logging.getLogger("uvicorn")
dbc = config["data_database"]

try:
    request_profiling = config.getboolean("debug", "profiling")
except ValueError:
    request_profiling = False

if request_profiling:

    @app.middleware("http")
    async def profile_request(request: Request, call_next):
        """Middleware to profile requests when ?profile=true is added to query string."""
        profiling = request.query_params.get("profile", False)
        if profiling:
            profiler = Profiler()
            profiler.start()
            response = await call_next(request)
            profiler.stop()
            HTMLResponse(profiler.write_html(show_all=True, path="profile.html"))
            return response
        else:
            return await call_next(request)


async_engine = create_async_engine(
    f"mysql+asyncmy://{dbc['user']}:"
    f"{dbc['passwd']}@{dbc['host']}:"
    f"{dbc['port']}/{dbc['db']}",
    echo=True,
    future=True,
    pool_pre_ping=True,
    # pool_size=10, max_overflow=20, pool_recycle=3600  # set if desired
)

# Session factory bound to module-level engine
async_session = sessionmaker(async_engine, class_=AsyncSession, expire_on_commit=False)

# @app.on_event("startup")
# def startup_event():
#    # load config file once at startup and attach to app.state
#    app.state.config = config
#
#    # s3 resources
#    app.state.bucket = getenv('AWS_BUCKET_NAME')
#    app.state.s3_store = S3Store()
#    # obstore store may use fsspec internally but get_files uses s3fs; keep both.
#    app.state.s3 = s3fs.S3FileSystem(anon=False, use_listings_cache=True, listings_expiry_time=300)


@app.on_event("shutdown")
async def shutdown_event():
    # remove/cleanup database
    global async_engine
    if async_engine is not None:
        await async_engine.dispose()
        del async_engine


# TODO: use model like this or use some kind of Pydantic geospatial package
#       for bbox validation
# class BBox(BaseModel):
#    lat1: float
#    lon1: float
#    lat2: float
#    lon2: float
#
#    @model_validator(mode="after"):
#    def check_order(cls, values):
#        lat_min, lat_max = -90.0, 90.0
#        lon_min, lon_max = -180.0, 180.0
#
#        lat1, lon1, lat2, lon2 = values["lat1"], values["lon1"], values["lat2"], values["lon2"]
#        if not (lat1 < lat2 and lon1 < lon2):
#            raise ValueError("Expected lat1 < lat2 and lon1 < lon2 (min < max).")
#        return values
#
#        if not (lon_min <= lon1 <= lon_max and lon_min <= lon2 <= lon_max):
#            raise ValueError(f"Longitude values must be between {lon_min} and {lon_max} degrees")
#        if not (lat_min <= lat1 <= lat_max and lat_min <= lat2 <= lat_max):
#            raise ValueError(f"Latitude values must be between {lat_min} and {lat_max} degrees")


@radials_router.get("/")
async def get_points(
    lon1: float = Query(float(config["urls"]["default_lon1"])),
    lat1: float = Query(float(config["urls"]["default_lat1"])),
    lon2: float = Query(float(config["urls"]["default_lon2"])),
    lat2: float = Query(float(config["urls"]["default_lat2"])),
    prod: str = Query(config["urls"]["default_prod"]),
    time: float = Query(None),
):
    # Set default time if not provided
    if time is None:
        time = time_module.time() - float(config["urls"]["default_time_offset"])
    regions = get_regions(lat1, lon1, lat2, lon2)
    if len(regions) == 0:
        raise HTTPException(
            status_code=204,
            detail="Request bounding box contains no valid RTV regions.",
        )

    files = get_files(regions, prod, time)
    if len(files) == 0:
        raise HTTPException(
            status_code=204,
            detail="No RTV files currently available for given request params.",
        )

    # Get data from file(s), convert to geojson, return
    data = await get_data(files, lat1, lon1, lat2, lon2)
    geo_df = df_to_geojson(data, ["head", "magni", "u", "v"], prod, time)
    return geo_df


@radials_router.get("/hist")
async def get_history(
    request: FastAPIRequest,
    lon1: float = Query(float(config["urls"]["default_lon1"])),
    lat1: float = Query(float(config["urls"]["default_lat1"])),
    lon2: float = Query(float(config["urls"]["default_lon2"])),
    lat2: float = Query(float(config["urls"]["default_lat2"])),
    t1: float = Query(None),
    prod: str = Query(config["urls"]["default_prod"]),
    out: str = Query(config["urls"]["default_out"], enum=["csv", "json"]),
):
    # Set default t1 if not provided
    if t1 is None:
        t1 = time_module.time() - float(config["urls"]["default_time_offset"])
    pfx, res = prod.split("_")

    # If prod is am or ay (long averages), adjust t1 and t0
    if pfx == "am" or pfx == "ma":
        t1 = last_day_of_month(t1)
        t0 = t1 + relativedelta(months=-12)
    elif pfx == "ay" or pfx == "ya":
        t1 = last_day_of_month(t1)
        t0 = t1 + relativedelta(years=-4)
    else:
        t1 = datetime.fromtimestamp(t1, timezone.utc)
        t0 = t1 + relativedelta(days=-6)
    # convert t0/t1 back to timestamp for get_timeseries_files
    t0 = t0.timestamp()
    t1 = t1.timestamp()

    # Hist requests do not have a lat2/lon2, so we need the regions for just a single point
    logger.info("getting regions")
    regions = get_regions(lat1, lon1, lat1, lon1)
    if len(regions) == 0:
        raise HTTPException(
            status_code=204,
            detail="Request bounding box contains no valid RTV regions.",
        )

    logger.info("getting hist files")
    files = get_hist_files(t0, t1, regions, prod)
    if len(files) == 0:
        raise HTTPException(
            status_code=204,
            detail="No RTV files currently available for given request params.",
        )

    logger.info("fetching historical data")
    try:
        data = await get_hist_data(files, lat1, lon1, request)
    except HTTPException:
        logger.info("hist request disconnected")
        raise

    # If you want to get the result from get_hist_data, you need to use a variable or a queue.
    # Here's a simple pattern using a nonlocal variable:
    # (Assume get_hist_data is modified to accept a callback or a result container.)

    logger.info("outputting data")
    if out == "csv":
        return Response(data.to_csv(date_format="%s"), media_type="text/csv")
    else:
        return Response(
            data.to_json(orient="index", date_format="epoch"),
            media_type="application/json",
        )


@radials_router.get("/waves")
async def get_waves(time: int = Query(None)):
    # TODO: Consider porting to SQLModel?
    sql = text(
        "SELECT s.sta, n.net, wf.time,wf.lat,wf.lon, wf.MWHT, wf.MWPD, wf.WAVB, wf.WNDB, wf.LOND, wf.LATD "
        "FROM hfradar.wavefiles wf "
        "LEFT JOIN hfradar.site s on wf.site_id=s.site_id "
        "LEFT JOIN hfradar.network n on wf.network_id = n.network_id "
        "WHERE wf.time=:time"
    )

    async with async_session() as session:
        result = await session.execute(sql, {"time": time})
        rows = result.mappings().all()
    if rows is None:
        raise HTTPException(
            status_code=500, detail="Internal error reading waves data."
        )

    if len(rows) == 0:
        raise HTTPException(
            status_code=204, detail="No waves data found for given request params."
        )

    geojson = {"type": "FeatureCollection", "time": time, "features": []}

    # Go through each entry returned and create my json
    for row in rows:
        row_data = read_waves_row(row)

        # if no row_data (likely because MWHT is missing), skip the row
        if row_data is None:
            continue

        feature = {
            "type": "Feature",
            "properties": {
                "site": row_data["sta"],
                "net": row_data["net"],
                "time": float(row_data["time"]),
                "MWHT": float(row_data["MWHT"]),
                "MWPD": float(row_data["MWPD"]),
                "WAVB": float(row_data["WAVB"]),
                "WNDB": float(row_data["WNDB"]),
            },
            "geometry": {
                "type": "Point",
                "coordinates": [row_data["lon"], row_data["lat"]],
            },
        }

        geojson["features"].append(feature)

    return geojson


@radials_router.get("/waves/hist")
async def get_waves_history(
    site: str = Query(""),
    t0: float = Query(None),
    t1: float = Query(None),
):
    """
    Query historical wave data for a given site and time range.

    Returns:
        JSON array of wave data.

    """
    # Set default t1 if not provided
    if t1 is None:
        t1 = time_module.time()
    # Set default t0 if not provided: default 6 days before t1
    if t0 is None:
        t0 = t1 - float(config["waves"]["default_history_t0"])
    sql = text(
        "SELECT s.sta, n.net, wf.time, wf.lat, wf.lon, wf.MWHT, wf.MWPD, wf.WAVB, wf.WNDB, wf.LOND, wf.LATD "
        "FROM hfradar.wavefiles wf "
        "LEFT JOIN hfradar.site s on wf.site_id=s.site_id "
        "LEFT JOIN hfradar.network n on wf.network_id = n.network_id "
        "WHERE s.sta=:site AND wf.time>=:t0 AND wf.time<=:t1 "
        "ORDER BY wf.time DESC"
    )

    async with async_session() as session:
        result = await session.execute(sql, {"site": site, "t0": t0, "t1": t1})
        rows = result.mappings().all()

    if rows is None:
        raise HTTPException(
            status_code=500, detail="Internal error reading waves data."
        )
    if len(rows) == 0:
        raise HTTPException(
            status_code=204, detail="No waves data found for given request params."
        )

    json_data = []
    for row in rows:
        row_data = read_waves_row(row)
        if row_data is None:
            continue
        data = {
            "time": float(row_data["time"]),
            "lat": row_data["lat"],
            "lon": row_data["lon"],
            "MWHT": float(row_data["MWHT"]),
            "WAVB": float(row_data["WAVB"]),
            "WNDB": float(row_data["WNDB"]),
            "MWPD": float(row_data["MWPD"]),
        }
        json_data.append(data)

    return json_data


@radials_router.get("/latest")
async def get_latest(
    lat1: float = Query(float(config["urls"]["default_lat1"])),
    lon1: float = Query(float(config["urls"]["default_lon1"])),
    lat2: float = Query(float(config["urls"]["default_lat2"])),
    lon2: float = Query(float(config["urls"]["default_lon2"])),
    prod: str = Query(config["urls"]["default_prod"]),
    method: str = Query("any", enum=["any", "all"]),
):
    """
    Gets the latest available timestep for a given product and bounding box.

    Returns:
        JSON object with latest time and product info.

    Notes:
        Uses get_regions and get_files to determine latest available data.
        No database query is performed here.
    """
    pfx, res = prod.split("_")

    # Default lat/lon includes all regions; this just gives us the option of limiting regions if needed
    regions = get_regions(lat1, lon1, lat2, lon2)

    # Remove ignored regions (if applicable)
    ignored_regions = config["latest"]["exclude_regions"].split(",")
    if method != "any":
        regions = [region for region in regions if region not in ignored_regions]

    if len(regions) == 0:
        raise HTTPException(
            status_code=204,
            detail="Request bounding box contains no valid RTV regions.",
        )

    # get current time rounded to the nearest whole hour (latest possible file)
    now = datetime.now(tz=timezone.utc)

    if pfx == "am" or pfx == "ma":
        ts = datetime(now.year, now.month, 1, tzinfo=now.tzinfo)
    elif pfx == "ay" or pfx == "ya":
        ts = datetime(now.year, 1, 1, tzinfo=now.tzinfo)
    else:
        ts = datetime(now.year, now.month, now.day, now.hour, tzinfo=now.tzinfo)

    # work backwards until request files are found
    latest_files = []
    while len(latest_files) == 0:
        newfiles = get_files(regions, prod, ts.timestamp())

        # if we are using 'any' method, we are looking for any files for a given timestep. Otherwise we want
        # len(newfiles) to be equal to len(regions) representing successful data for each region
        if method == "any":
            if len(newfiles) > 0:
                # add each unique new file to files, ignoring potential duplicates
                for file in newfiles:
                    if file not in latest_files:
                        latest_files.append(file)
        else:
            if len(newfiles) == len(regions):
                # add each unique new file to files, ignoring potential duplicates
                for file in newfiles:
                    if file not in latest_files:
                        latest_files.append(file)

        if not latest_files:
            # increment ts backwards depending on pfx type to minimize no. of get_files calls
            if pfx == "am" or pfx == "ma":
                ts += relativedelta(months=-1)
            elif pfx == "ay" or pfx == "ya":
                ts += relativedelta(years=-1)
            else:
                ts += relativedelta(hours=-1)

    # return the datetime and timestamp of the latest file found
    json = {
        "utc_time": ts.strftime("%Y%m%d%H%M%S"),
        "timestamp": ts.timestamp(),
        "prod": prod,
        "method": method,
    }

    return json


app.include_router(radials_router)
