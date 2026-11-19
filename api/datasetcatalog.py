"""Dataset records built from the existing RTV file discovery infrastructure."""

import base64
import binascii
import re
from datetime import datetime, timezone
from os import getenv
from pathlib import PurePosixPath
from urllib.parse import quote, urlsplit

from fastapi import HTTPException
from pydantic import AwareDatetime, BaseModel, ConfigDict, Field, field_validator, model_validator

import config as settings
import rtvfiles
from utils import get_regions


MAX_PERIODS = 744
MAX_RESULTS = 500
PRODUCTS = {
    "h": ("Hourly totals", "uwls", "hour"),
    "a": ("25-hour average totals", "25h-avg", "hour"),
    "ma": ("Monthly average totals", "mon-avg", "month"),
    "ya": ("Annual average totals", "ann-avg", "year"),
}
ALIASES = {"am": "ma", "ay": "ya"}
FILENAME = re.compile(
    r"rtv-(?P<region>[a-z]+)-(?P<resolution>500m|1km|2km|6km)-"
    r"(?P<kind>uwls|25h-avg|mon-avg|ann-avg)_v1r0_hfr_"
    r"s(?P<start>\d{15})_e(?P<end>\d{15})_c(?P<created>\d{15})\.nc"
)


def normalize_product(product):
    parts = product.split("_")
    if len(parts) != 2:
        raise ValueError("Expected product_type_resolution, for example h_6km")
    prefix, resolution = parts
    if prefix not in settings.config["urls"]["valid_pfx"].split(","):
        raise ValueError("Unknown product type")
    if resolution not in settings.config["urls"]["valid_res"].split(","):
        raise ValueError("Unknown resolution")
    return f"{ALIASES.get(prefix, prefix)}_{resolution}"


def region_bounds():
    bounds = {}
    for region, value in settings.config.items("usregions"):
        south, west, north, east = map(float, value.split(","))
        bounds[region.upper()] = [west, south, east, north]
    return bounds


class DatasetSearch(BaseModel):
    model_config = ConfigDict(extra="forbid", allow_inf_nan=False)

    prod: str = Field(default="a_6km", description="Product type and resolution, e.g. h_6km or ma_1km")
    regions: list[str] | None = Field(default=None, min_length=1, max_length=7)
    bbox: list[float] | None = Field(default=None, min_length=4, max_length=4,
                                   description="west,south,east,north; west > east crosses the date line")
    time: AwareDatetime | None = Field(default=None, description="ISO 8601 with timezone, or Unix UTC seconds")
    start_time: AwareDatetime | None = None
    end_time: AwareDatetime | None = None
    latest: bool = False
    lookback_periods: int = Field(default=168, ge=1, le=MAX_PERIODS)
    limit: int = Field(default=100, ge=1, le=MAX_RESULTS)
    cursor: str | None = Field(default=None, min_length=1, max_length=2048)

    @field_validator("prod")
    @classmethod
    def valid_product(cls, value):
        return normalize_product(value)

    @field_validator("regions")
    @classmethod
    def valid_regions(cls, values):
        if values is None:
            return None
        values = list(dict.fromkeys(value.upper() for value in values))
        if set(values) - region_bounds().keys():
            raise ValueError("Unknown region code")
        return values

    @field_validator("bbox")
    @classmethod
    def valid_bbox(cls, values):
        if values is not None:
            west, south, east, north = values
            if not (-180 <= west <= 180 and -180 <= east <= 180 and -90 <= south <= north <= 90):
                raise ValueError("Invalid west,south,east,north bounding box")
        return values

    @model_validator(mode="after")
    def valid_selection(self):
        if self.regions is not None and self.bbox is not None:
            raise ValueError("Choose regions or bbox, not both")
        has_range = self.start_time is not None or self.end_time is not None
        if sum((self.time is not None, has_range, self.latest)) != 1:
            raise ValueError("Choose time, start_time/end_time, or latest=true")
        if has_range:
            if self.start_time is None or self.end_time is None or self.start_time > self.end_time:
                raise ValueError("A range requires start_time <= end_time")
            start, step = rtvfiles.product_period(self.prod, self.start_time)
            end, _ = rtvfiles.product_period(self.prod, self.end_time)
            count = 1
            while start < end and count <= MAX_PERIODS:
                start += step
                count += 1
            if count > MAX_PERIODS:
                raise ValueError(f"A search may cover at most {MAX_PERIODS} product periods")
        return self

    def selected_regions(self):
        if self.regions is not None:
            return self.regions
        if self.bbox is not None:
            west, south, east, north = self.bbox
            return get_regions(south, west, north, east)
        return list(region_bounds())


class AccessLink(BaseModel):
    url: str
    protocol: str
    media_type: str = "application/x-netcdf"
    authentication: str = "deployment_dependent"


class DatasetRecord(BaseModel):
    dataset_id: str
    product: str
    resolution: str
    region: str
    filename: str
    object_key: str
    nominal_time: datetime
    observation_start: datetime
    observation_end: datetime
    creation_time: datetime
    time_source: str = "filename"
    candidate_bbox: list[float]
    spatial_coverage_source: str = "configured_region"
    access: list[AccessLink]


class DatasetResults(BaseModel):
    product: str
    regions: list[str]
    datasets: list[DatasetRecord]
    returned: int
    next_cursor: str | None


def discovery_options():
    return {
        "products": [{"prefix": prefix, "description": details[0], "period": details[2]}
                     for prefix, details in PRODUCTS.items()],
        "aliases": ALIASES,
        "resolutions": settings.config["urls"]["valid_res"].split(","),
        "regions": [{"code": code, "candidate_bbox": bbox} for code, bbox in region_bounds().items()],
        "bbox_order": ["west", "south", "east", "north"],
        "availability": "Supported selectors only; search /datasets to establish file availability.",
        "spatial_selection": "Configured regional boxes identify candidate files, not valid observation coverage.",
        "time_selection": "Choose time, an inclusive start_time/end_time range, or latest=true. "
                          "Times select UTC product periods: hour, calendar month, or calendar year. "
                          "Ranges select every period touched, rather than averaging-window overlap. "
                          "Filename start/end values are not verified NetCDF time coverage.",
        "limits": {"max_periods": MAX_PERIODS, "max_results": MAX_RESULTS,
                   "default_latest_lookback_periods": 168, "max_coordinate_values": 1000},
    }


def encode_dataset_id(key):
    return base64.urlsafe_b64encode(key.encode()).decode().rstrip("=")


def decode_dataset_id(dataset_id):
    try:
        if not re.fullmatch(r"[A-Za-z0-9_-]{1,2048}", dataset_id):
            raise ValueError
        key = base64.b64decode(dataset_id + "=" * (-len(dataset_id) % 4), altchars=b"-_", validate=True).decode()
        if encode_dataset_id(key) != dataset_id:
            raise ValueError
        parse_key(key)
        return key
    except (ValueError, UnicodeError, binascii.Error):
        raise HTTPException(status_code=422, detail="Invalid dataset identifier") from None


def parse_stamp(stamp):
    return datetime.strptime(stamp[:14], "%Y%m%d%H%M%S").replace(
        tzinfo=timezone.utc, microsecond=int(stamp[14]) * 100000
    )


def parse_key(key):
    path = PurePosixPath(key)
    if str(path) != key or ".." in path.parts:
        raise ValueError("Invalid object path")
    match = FILENAME.fullmatch(path.name)
    if match is None:
        raise ValueError("Unsupported totals filename")
    values = match.groupdict()
    region = values["region"].upper()
    if region not in region_bounds():
        raise ValueError("Unknown region")
    prefix = next(prefix for prefix, details in PRODUCTS.items() if details[1] == values["kind"])
    product = normalize_product(f"{prefix}_{values['resolution']}")
    start, end, created = [parse_stamp(values[name]) for name in ("start", "end", "created")]
    if end < start:
        raise ValueError("Reversed observation period")
    directory, stem = rtvfiles._file_prefix(region, product, start)
    if str(path.parent) != directory or not path.name.startswith(stem):
        raise ValueError("Object is outside the configured totals layout")
    return region, product, start, end, created


def dataset_record(key):
    region, product, start, end, created = parse_key(key)
    if not settings.bucket:
        raise HTTPException(status_code=503, detail="Dataset storage is not configured")
    links = [AccessLink(url=f"s3://{settings.bucket}/{quote(key, safe='/')}", protocol="s3")]
    base_url = getenv("HFRNET_DATA_HTTPS_BASE_URL", "").rstrip("/")
    if base_url:
        parsed = urlsplit(base_url)
        if parsed.scheme != "https" or not parsed.hostname or parsed.username or parsed.password or parsed.query or parsed.fragment:
            raise HTTPException(status_code=503, detail="Dataset HTTPS access base URL is misconfigured")
        links.append(AccessLink(url=f"{base_url}/{quote(key, safe='/')}", protocol="https"))
    nominal, _ = rtvfiles.product_period(product, start)
    return DatasetRecord(
        dataset_id=encode_dataset_id(key), product=product, resolution=product.split("_")[1],
        region=region, filename=PurePosixPath(key).name, object_key=key, nominal_time=nominal,
        observation_start=start, observation_end=end, creation_time=created,
        candidate_bbox=region_bounds()[region], access=links,
    )


async def search_datasets(query):
    regions = query.selected_regions()
    after = decode_dataset_id(query.cursor) if query.cursor else None
    if after:
        region, product, *_ = parse_key(after)
        if product != query.prod or region not in regions:
            raise HTTPException(status_code=422, detail="Cursor does not match this product/region selection")
    if not settings.bucket:
        raise HTTPException(status_code=503, detail="Dataset storage is not configured")
    if not regions:
        files = []
    elif query.latest:
        _, files = await rtvfiles.get_latest_files(
            regions, query.prod, datetime.now(timezone.utc).timestamp(), query.lookback_periods
        )
    elif query.time is not None:
        files = await rtvfiles.get_files(regions, query.prod, query.time.timestamp())
    else:
        start, _ = rtvfiles.product_period(query.prod, query.start_time)
        end, _ = rtvfiles.product_period(query.prod, query.end_time)
        files = await rtvfiles.get_hist_files(start.timestamp(), end.timestamp(), regions, query.prod)
    # File discovery already chooses the newest filename revision per period.
    keys = sorted({file.split("/", 1)[1] for file in files})
    records = []
    for key in keys:
        if after is not None and key <= after:
            continue
        try:
            records.append(dataset_record(key))
        except ValueError:
            continue  # Ignore malformed objects sharing a supported filename prefix.
        if len(records) > query.limit:
            break
    more = len(records) > query.limit
    records = records[:query.limit]
    return DatasetResults(
        product=query.prod, regions=regions, datasets=records, returned=len(records),
        next_cursor=records[-1].dataset_id if more else None,
    )
