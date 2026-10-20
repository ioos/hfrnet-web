import io
import asyncio
import logging
from concurrent.futures import ProcessPoolExecutor
from os import getenv
import xarray as xr
import obstore as obs
import numpy as np
import pandas as pd
import geopandas as gpd
from config import s3_store
from fastapi import HTTPException
import multiprocessing

logger = logging.getLogger("uvicorn")


async def dl_file(file):
    """Asynchronously download a file using obstore. Return the filename and the data itself"""
    data = await obs.get_async(s3_store, file)
    if not data:
        return None
    data_bytes = await data.bytes_async()
    return {"file": file, "data": data_bytes}


def preprocess_file(ds):
    """Preprocesses a RTV file dataset by renaming variables/dims as needed and subsetting variables

    :param ds: opened xarray dataset of the RTV file
    :return: preprocessed dataset
    """
    # Rename u_mean and v_mean variables if needed
    if "u_mean" in ds:
        ds = ds.rename({"u_mean": "u", "v_mean": "v"})

    # Rename lat and lon dims if needed
    if "LATITUDE" in ds.dims:
        ds = ds.rename({"LATITUDE": "lat", "LONGITUDE": "lon"})
    if "TIME" in ds.dims:
        ds = ds.rename({"TIME": "time"})

    # ensure u and v are in ds variables
    if not {"u", "v"}.issubset(ds.data_vars):
        return None

    # subset to u, v
    return ds[["u", "v"]]


def derive_assign_vars(ds_uv):
    """
    Derive and assign angle, head, and magni vars (representing vector) for a RTV dataset containing u and v vars
    as vector components.

    :param ds_uv: opened xarray dataset of the RTV file containing u and v vars
    :return: xarray dataset with new angle, head, and magni vars assigned
    """
    # skip if empty vars
    if ds_uv.u.size == 0 or ds_uv.v.size == 0:
        return None

    # build mask of points where both vector components are zero
    both_zero = (ds_uv.u == 0) & (ds_uv.v == 0)

    # Calculate angle, head and magni vars
    angle = np.degrees(np.arctan2(ds_uv.v, ds_uv.u))
    head = ((90 - angle) % 360).round(2)
    magni = np.sqrt(ds_uv.u**2 + ds_uv.v**2).round(2)

    # use both_zero to mask angle and head
    angle = angle.where(~both_zero)
    head = head.where(~both_zero)

    # assign derived vars
    ds_uv = ds_uv.assign(angle=angle, head=head, magni=magni)

    return ds_uv


def process_file(data_file, lat1, lon1, lat2, lon2):
    """Process a data_file into a dataframe, filtering by the request lat/lon bbox

    :param data_file: data file to process
    :param lat1: latitude of first point
    :param lon1: longitude of first point
    :param lat2: latitude of second point
    :param lon2: longitude of second point
    """
    data = io.BytesIO(data_file["data"])
    try:
        ds = xr.open_dataset(data, engine="h5netcdf")
    except:
        # logging apparently not safe in a multiprocessing pool
        # logging.error('There was an error opening %s' % data_file['file'])
        return None

    # preprocess data
    ds_uv = preprocess_file(ds)
    if ds_uv is None:
        return None

    # Filter by lat/lon of bbox
    # latitude mask
    lat_mask = (ds_uv.lat >= lat2) & (ds_uv.lat <= lat1)
    # longitude mask (account for possible lon wrapping)
    if lon1 > lon2:
        lon_mask = (ds_uv.lon >= lon1) | (ds_uv.lon <= lon2)
    else:
        lon_mask = (ds_uv.lon >= lon1) & (ds_uv.lon <= lon2)

    ds_uv = ds_uv.where(lat_mask & lon_mask, drop=True)

    # drop along each dim where u,v are n/a
    ds_uv = ds_uv.dropna(dim="time", how="all", subset=["u", "v"])
    ds_uv = ds_uv.dropna(dim="lat", how="all", subset=["u", "v"])
    ds_uv = ds_uv.dropna(dim="lon", how="all", subset=["u", "v"])

    # derive angle/head/magni and assign
    ds_uv = derive_assign_vars(ds_uv)
    if ds_uv is None:
        return None

    # subset to cols we want
    keep_cols = ["lat", "lon", "time", "u", "v", "angle", "head", "magni"]

    # convert to dataframe
    uv = ds_uv[keep_cols].to_dataframe().dropna().reset_index()
    return uv


def process_hist_file(data_file, lat, lon):
    """Process a data_file into a dataframe, filtering by the nearest requested point

    :param data_file: data file to process
    :param lat: latitude of request point
    :param lon: longitude of request point
    """

    data = io.BytesIO(data_file["data"])
    try:
        logger.info(f"Loading file {data_file['file']}")
        ds = xr.load_dataset(data, engine="h5netcdf")
    except:
        # logger not safe in multiprocessing pool apparently
        logger.error("There was an error opening %s" % data_file["file"])
        return None

    # preprocess data
    ds_uv = preprocess_file(ds)
    if ds_uv is None:
        return None

    # sel nearest lat/lon to the point we want
    ds_uv = ds_uv.sel(lat=[lat], lon=[lon], method="nearest")
    if ds_uv.u.size == 0 or ds_uv.v.size == 0:
        return None

    # drop along each dim where u,v are n/a
    ds_uv = ds_uv.dropna(dim="time", how="all", subset=["u", "v"])
    ds_uv = ds_uv.dropna(dim="lat", how="all", subset=["u", "v"])
    ds_uv = ds_uv.dropna(dim="lon", how="all", subset=["u", "v"])

    # derive angle/head/magni and assign
    ds_uv = derive_assign_vars(ds_uv)
    if ds_uv is None:
        return None

    # subset to cols we want
    ds_uv = ds_uv[["lat", "lon", "time", "u", "v", "angle", "head", "magni"]]

    logger.info(f"Done loading file {data_file['file']}")
    return ds_uv


async def get_data(files, lat1, lon1, lat2, lon2):
    """Get the data from a given list of files to satisfy a regular rtv request

    :param files: list of file paths
    :param lat1: start latitude (north edge)
    :param lon1: start longitude (west edge)
    :param lat2: end latitude (south edge)
    :param lon2: end longitude (east edge)
    :return: dataframe
    """

    # If lat1-lat2 is in min/max, switch to max-min
    if lat1 < lat2:
        lat1, lat2 = lat2, lat1

    # download and process files into list of xarray datasets
    tasks = []
    for file in files:
        # strip bucket name from file path
        file = file.split("/", 1)[1]
        tasks.append(asyncio.ensure_future(dl_file(file)))

    data_files = await asyncio.gather(*tasks)

    # drop empty data_files (files that fail to download)
    data_files = [data_file for data_file in data_files if data_file is not None]

    if not data_files:
        raise HTTPException(
            status_code=500, detail="Error downloading RTV files needed for request."
        )

    # process data_files into dataframes in a process pool
    num_process = int(getenv("NUM_PROCESS"))
    if not num_process:
        num_process = 1

    with ProcessPoolExecutor(max_workers=num_process) as executor:
        loop = asyncio.get_running_loop()
        tasks = []
        for data_file in data_files:
            tasks.append(
                loop.run_in_executor(
                    executor, process_file, data_file, lat1, lon1, lat2, lon2
                )
            )

    results = await asyncio.gather(*tasks)

    # drop empty results
    dataframes = [result for result in results if result is not None]

    # Check to see if there is anything in dataset list
    if not dataframes:
        abort(500, description="Error processing RTV data from files.")

    # concat and reindex datasets
    df = pd.concat(dataframes)  # .set_index("time")
    return gpd.GeoDataFrame(
        df, geometry=gpd.points_from_xy(df.lon, df.lat), crs="EPSG:4326"
    )


async def get_hist_data(files, lat, lon, request):
    """Get the data from a given list of files to satisfy a /hist rtv request

    :param files: list of file paths
    :param lat: request latitude
    :param lon: request longitude
    :param request: FastAPI request object
    :return: dataframe
    """

    # download and process files into list of xarray datasets
    logger.info("Starting download of RTV files")
    tasks = [dl_file(file.split("/", 1)[1]) for file in files]
    data_files = []
    for coro in asyncio.as_completed(tasks):
        data_file = await coro
        if request is not None:
            disconnected = await request.is_disconnected()
            # profiling middleware is enabled
            if disconnected:
                from fastapi import HTTPException

                raise HTTPException(status_code=499, detail="Client disconnected")
        if data_file is not None:
            data_files.append(data_file)

    if not data_files:
        raise HTTPException(
            status_code=500, detail="Error downloading RTV files needed for request."
        )

    # process data_files into dataframes in a process pool
    num_process_env = getenv("NUM_PROCESS")
    try:
        num_process = int(num_process_env)
        if num_process < 1:
            raise ValueError
    except (TypeError, ValueError):
        num_process = multiprocessing.cpu_count()

    with ProcessPoolExecutor(max_workers=num_process) as executor:
        loop = asyncio.get_running_loop()
        tasks = []
        for data_file in data_files:
            tasks.append(
                loop.run_in_executor(executor, process_hist_file, data_file, lat, lon)
            )

    group = asyncio.gather(*tasks)

    try:
        results = await group
    except asyncio.CancelledError:
        for task in tasks:
            task.cancel()
        raise

    # drop empty results
    datasets = [result for result in results if result is not None]

    # Check to see if there is anything in dataset list
    if not datasets:
        raise HTTPException(
            status_code=500, detail="Error processing RTV data from files."
        )

    # concat datasets and select nearest lat/lon
    dataset = xr.concat(datasets, dim="index", join="outer")
    dataset = dataset.sel(lat=[lat], lon=[lon], method="nearest")

    # convert to dataframe and set index
    dataframe = dataset.to_dataframe().dropna()

    # Remove index column
    if "index" in dataframe:
        dataframe = dataframe.drop(columns=["index"])

    # Reordering index levels does not reorder rows. Downloaded files can arrive
    # in any order, so sort by time before serializing the response.
    return dataframe.reorder_levels(["time", "lat", "lon", "index"]).sort_index()
