import asyncio
from collections import OrderedDict
from datetime import datetime, timezone
from time import monotonic

import obstore as obs
from dateutil.relativedelta import relativedelta
from config import config, bucket, s3_store


_LISTING_TTL = 300
_LISTING_CACHE_SIZE = 128
_LISTING_CONCURRENCY = 4
_listing_cache = OrderedDict()


def _file_prefix(region, prod, dt):
    """Return the directory and literal S3 key prefix for a product/time."""
    rootdir = str(config["directories"]["rootdir"]).strip("/")
    pfx, res = prod.split("_")
    stem = f"rtv-{region.lower()}-{res}-"
    if pfx == "h":
        directory = f"{rootdir}/{dt:%Y/%m/%d}"
        stem += "uwls_v1r0_hfr_s"
    elif pfx == "a":
        directory = f"{rootdir}/{dt:%Y/%m/%d}/averages"
        stem += "25h-avg_v1r0_hfr_s"
    elif pfx in ("am", "ma"):
        return f"{rootdir}/{dt:%Y/%m}/averages", stem + "mon-avg_v1r0_hfr_s"
    else:
        return f"{rootdir}/{dt:%Y}/averages", stem + "ann-avg_v1r0_hfr_s"
    return directory, stem + dt.strftime("%Y%m%d%H") + "00"


async def _list_files(directory):
    cached = _listing_cache.get(directory)
    if cached is not None:
        expires, paths = cached
        if monotonic() < expires:
            _listing_cache.move_to_end(directory)
            return paths
        del _listing_cache[directory]

    # Obstore prefixes match path segments, not partial filenames. A delimiter
    # listing stays within the day/month/year averages directory, even when
    # the archive contains millions of files under neighboring directories.
    result = await obs.list_with_delimiter_async(s3_store, prefix=directory)
    paths = tuple(
        obj["path"] for obj in result["objects"]
        if obj["path"].endswith(".nc") and obj["path"].rsplit("/", 1)[0] == directory
    )
    # Match the previous five-minute listings cache without retaining an
    # unbounded number of archive directories. Missing data is not cached.
    if paths:
        _listing_cache[directory] = (monotonic() + _LISTING_TTL, paths)
        _listing_cache.move_to_end(directory)
        while len(_listing_cache) > _LISTING_CACHE_SIZE:
            _listing_cache.popitem(last=False)
    return paths


async def _get_matching_files(lookups):
    semaphore = asyncio.Semaphore(_LISTING_CONCURRENCY)

    async def list_directory(directory):
        async with semaphore:
            return directory, await _list_files(directory)

    # Deduplicate directories across all hours and overlapping regions.
    directories = dict.fromkeys(directory for directory, _ in lookups)
    listings = dict(await asyncio.gather(*(list_directory(d) for d in directories)))
    files = []
    seen = set()
    for directory, prefix in lookups:
        latest = max(
            (path for path in listings[directory] if path.startswith(f"{directory}/{prefix}")),
            default=None,
        )
        if latest is not None and latest not in seen:
            seen.add(latest)
            # Keep the bucket/key format expected by the processing layer.
            files.append(f"{bucket}/{latest}")
    return files


async def get_files(regions, prod, req_time):
    """Gets the file paths that satisfy the given input regions, time, and product

    :param regions: list of abbreviated regions
    :param prod: product string in the request url format e.g. 'a_6km'
    :param req_time: epoch time
    :return: list of file paths
    """
    # utc datetime object for epoch time
    dt = datetime.fromtimestamp(req_time, tz=timezone.utc)
    return await _get_matching_files([_file_prefix(region, prod, dt) for region in regions])


async def get_hist_files(t0, t1, regions, prod):
    """Gets a list of file paths needed for a time series request

    :param t0: epoch start time
    :param t1: epoch end time
    :param regions: list of regions
    :param prod: product string in the request url format e.g. 'a_6km'
    :return: array containing the file paths for the time series
    """
    lookups = []

    pfx, _ = prod.split("_")
    t0 = datetime.fromtimestamp(t0, timezone.utc)
    t1 = datetime.fromtimestamp(t1, timezone.utc)
    ts = t0

    while ts <= t1:
        lookups.extend(_file_prefix(region, prod, ts) for region in regions)

        # increment ts by 1 year/month depending on pfx type to minimize no. of get_files calls
        if pfx == "am" or pfx == "ma":
            ts = ts + relativedelta(months=1)
        elif pfx == "ay" or pfx == "ya":
            ts = ts + relativedelta(years=1)
        else:
            ts = ts + relativedelta(hours=1)

    return await _get_matching_files(lookups)
