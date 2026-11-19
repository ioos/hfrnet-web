import asyncio
import json
from datetime import datetime, timezone
from unittest.mock import AsyncMock, Mock

import httpx
import numpy as np
import obstore as obs
import pytest
import xarray as xr
from obstore.store import MemoryStore

import config as settings
import datasetapi
import datasetcatalog
import datasetmetadata
import radialdata
import rtvfiles
import rtvprocessing


def file_key(when="202610290500000", *, region="uswc", prod="h_6km",
             revision="202610300000000", end=None):
    prefix, resolution = prod.split("_")
    if prefix == "h":
        directory, kind = f"{when[:4]}/{when[4:6]}/{when[6:8]}", "uwls"
    elif prefix == "a":
        directory, kind = f"{when[:4]}/{when[4:6]}/{when[6:8]}/averages", "25h-avg"
    elif prefix in ("am", "ma"):
        directory, kind = f"{when[:4]}/{when[4:6]}/averages", "mon-avg"
    else:
        directory, kind = f"{when[:4]}/averages", "ann-avg"
    return (f"hfrtv/{directory}/rtv-{region}-{resolution}-{kind}_v1r0_hfr_"
            f"s{when}_e{end or when}_c{revision}.nc")


@pytest.fixture
def storage(monkeypatch):
    store = MemoryStore()
    monkeypatch.setattr(settings, "bucket", "test-bucket")
    monkeypatch.setattr(settings, "s3_store", store)
    monkeypatch.setattr(rtvfiles, "bucket", "test-bucket")
    monkeypatch.setattr(rtvfiles, "s3_store", store)
    monkeypatch.setattr(rtvprocessing, "s3_store", store)
    monkeypatch.delenv("HFRNET_DATA_HTTPS_BASE_URL", raising=False)
    rtvfiles._listing_cache.clear()
    yield store
    rtvfiles._listing_cache.clear()


def get(path, params=None):
    async def request():
        async with httpx.AsyncClient(transport=httpx.ASGITransport(app=radialdata.app),
                                    base_url="http://test") as client:
            return await client.get(path, params=params)
    return asyncio.run(request())


def test_options_distinguish_supported_selectors_from_availability():
    response = get("/datasets/options")
    assert response.status_code == 200
    options = response.json()
    assert options["aliases"] == {"am": "ma", "ay": "ya"}
    assert {region["code"] for region in options["regions"]} == set(datasetcatalog.region_bounds())
    assert "Supported selectors only" in options["availability"]
    assert options["bbox_order"] == ["west", "south", "east", "north"]


def test_discovery_returns_latest_revision_and_exact_access_identity(storage, monkeypatch):
    older = file_key(revision="202610290000000")
    latest = file_key()
    for key in (older, latest, file_key(region="usegc"), file_key(prod="h_1km")):
        obs.put(storage, key, b"not downloaded for discovery")
    downloader = Mock(side_effect=AssertionError("Discovery must not read data"))
    monkeypatch.setattr(datasetmetadata.obs, "get_range", downloader)
    response = get("/datasets", {"prod": "h_6km", "regions": "USWC", "time": "2026-10-29T05:59:00Z"})
    assert response.status_code == 200, response.text
    result = response.json()
    assert result["returned"] == 1
    record = result["datasets"][0]
    assert record["object_key"] == latest
    assert datasetcatalog.decode_dataset_id(record["dataset_id"]) == latest
    assert record["nominal_time"] == "2026-10-29T05:00:00Z"
    assert record["time_source"] == "filename"
    assert record["spatial_coverage_source"] == "configured_region"
    assert record["candidate_bbox"] == [-130, 30, -115, 50]
    assert record["access"] == [{"url": f"s3://test-bucket/{latest}", "protocol": "s3",
                                  "media_type": "application/x-netcdf", "authentication": "deployment_dependent"}]
    downloader.assert_not_called()


def test_https_access_is_only_returned_when_configured(storage, monkeypatch):
    key = file_key()
    obs.put(storage, key, b"data")
    monkeypatch.setenv("HFRNET_DATA_HTTPS_BASE_URL", "https://data.example.test/bucket/")
    record = get("/datasets", {"prod": "h_6km", "time": "2026-10-29T05:00:00Z"}).json()["datasets"][0]
    assert record["access"][1]["url"] == f"https://data.example.test/bucket/{key}"


@pytest.mark.parametrize("prod,when,requested", [
    ("h_500m", "202610290500000", "2026-10-29T07:30:00+02:00"),
    ("a_2km", "202610290500000", "2026-10-29T05:30:00Z"),
    ("am_6km", "202610010000000", "2026-10-29T05:30:00Z"),
    ("ma_1km", "202610010000000", "2026-10-29T05:30:00Z"),
    ("ay_6km", "202601010000000", "2026-10-29T05:30:00Z"),
    ("ya_6km", "202601010000000", "2026-10-29T05:30:00Z"),
])
def test_product_periods_aliases_and_utc_offsets(storage, prod, when, requested):
    obs.put(storage, file_key(when, prod=prod), b"data")
    response = get("/datasets", {"prod": prod, "regions": "uswc", "time": requested})
    assert response.status_code == 200, response.text
    assert response.json()["returned"] == 1
    assert response.json()["product"].startswith({"am": "ma", "ay": "ya"}.get(prod.split("_")[0], prod.split("_")[0]))


def test_range_pagination_and_missing_periods(storage):
    keys = [file_key(stamp) for stamp in ("202610292300000", "202610300000000", "202610300200000")]
    for key in keys:
        obs.put(storage, key, b"data")
    params = {"prod": "h_6km", "regions": "USWC", "start_time": "2026-10-29T23:30:00Z",
              "end_time": "2026-10-30T02:30:00Z", "limit": 2}
    first = get("/datasets", params).json()
    assert [item["object_key"] for item in first["datasets"]] == keys[:2]
    assert first["next_cursor"] is not None
    second = get("/datasets", {**params, "cursor": first["next_cursor"]}).json()
    assert [item["object_key"] for item in second["datasets"]] == keys[2:]
    assert second["next_cursor"] is None


def test_monthly_range_uses_calendar_boundaries(storage):
    for stamp in ("202601010000000", "202602010000000", "202603010000000"):
        obs.put(storage, file_key(stamp, prod="ma_6km"), b"data")
    response = get("/datasets", {"prod": "ma_6km", "regions": "USWC",
                                "start_time": "2026-01-31T12:00:00Z", "end_time": "2026-03-01T00:00:00Z"})
    assert response.status_code == 200, response.text
    assert response.json()["returned"] == 3


def test_bbox_uses_candidate_regions_and_empty_search_is_json(storage):
    obs.put(storage, file_key(), b"data")
    params = [("prod", "h_6km"), ("time", "2026-10-29T05:00:00Z")]
    response = get("/datasets", params + [("bbox", value) for value in (-125, 35, -120, 40)])
    assert response.status_code == 200, response.text
    assert response.json()["regions"] == ["USWC"]
    assert response.json()["returned"] == 1
    empty = get("/datasets", params + [("bbox", value) for value in (10, -10, 20, 0)])
    assert empty.status_code == 200
    assert empty.json()["datasets"] == []


def test_latest_search_uses_requested_lookback(storage, monkeypatch):
    latest = AsyncMock(return_value=(1793250000, [f"test-bucket/{file_key()}"]))
    monkeypatch.setattr(rtvfiles, "get_latest_files", latest)
    response = get("/datasets", {"prod": "h_6km", "regions": "USWC", "latest": "true", "lookback_periods": 3})
    assert response.status_code == 200, response.text
    assert response.json()["returned"] == 1
    assert latest.await_args.args[0:2] == (["USWC"], "h_6km")
    assert latest.await_args.args[3] == 3


@pytest.mark.parametrize("params", [
    {}, {"latest": True, "time": "2026-10-29T05:00:00Z"},
    {"time": "2026-10-29T05:00:00"}, {"start_time": "2026-10-29T05:00:00Z"},
    {"start_time": "2026-10-30T00:00:00Z", "end_time": "2026-10-29T00:00:00Z"},
    {"start_time": "2020-01-01T00:00:00Z", "end_time": "2026-01-01T00:00:00Z"},
    {"latest": True, "prod": "bogus_6km"}, {"latest": True, "prod": "h_9km"},
    {"latest": True, "regions": "ATLANTIS"}, {"latest": True, "limit": 501},
    {"latest": True, "limit": 0}, {"latest": True, "lookback_periods": 745},
    {"latest": True, "extra": "unexpected"},
    [("latest", "true"), ("bbox", 0), ("bbox", 20), ("bbox", 10), ("bbox", 10)],
    [("latest", "true"), ("bbox", "nan"), ("bbox", 0), ("bbox", 10), ("bbox", 10)],
    [("latest", "true"), ("regions", "USWC"), *(('bbox', v) for v in (-125, 35, -120, 40))],
])
def test_invalid_search_fails_before_storage(params, monkeypatch):
    listing = AsyncMock(side_effect=AssertionError("Invalid input must not reach storage"))
    monkeypatch.setattr(rtvfiles, "_list_files", listing)
    response = get("/datasets", params)
    assert response.status_code == 422, response.text
    listing.assert_not_awaited()


@pytest.fixture
def netcdf_contents(tmp_path):
    source = xr.Dataset(
        {"u_mean": (("TIME", "LATITUDE", "LONGITUDE"), np.ones((1, 3, 4)),
                    {"units": "m s-1", "standard_name": "eastward_sea_water_velocity", "test_no_load": 1}),
         "v_mean": (("TIME", "LATITUDE", "LONGITUDE"), np.ones((1, 3, 4)),
                    {"units": "m s-1", "test_no_load": 1}),
         "u_var": (("LATITUDE", "LONGITUDE"), np.ones((3, 4)),
                   {"units": "m2 s-2", "long_name": "Eastward velocity variance", "test_no_load": 1}),
         "station_count": ((), 4)},
        coords={"TIME": ("TIME", [1.0], {"units": "hours since 2026-10-29 00:00:00", "calendar": "standard"}),
                "LATITUDE": [35.0, 36.0, 37.0], "LONGITUDE": [-125.0, -124.0, -123.0, -122.0]},
        attrs={"title": "Totals fixture", "numbers": np.array([1, 2], dtype=np.int16), "missing": float("nan")},
    )
    path = tmp_path / "totals.nc"
    source.to_netcdf(path, engine="h5netcdf")
    return path.read_bytes()


def test_metadata_preserves_all_native_variables_and_never_loads_scientific_arrays(storage, netcdf_contents, monkeypatch):
    key = file_key("202610010000000", prod="ma_6km", end="202610312359590")
    obs.put(storage, key, netcdf_contents)
    original = xr.Variable.values.fget

    def values(variable):
        assert not variable.attrs.get("test_no_load"), "Scientific array was loaded"
        return original(variable)

    monkeypatch.setattr(xr.Variable, "values", property(values))
    response = get(f"/datasets/{datasetcatalog.encode_dataset_id(key)}/metadata", {"coordinate_limit": 2})
    assert response.status_code == 200, response.text
    result = response.json()
    assert result["dimensions"] == {"TIME": 1, "LATITUDE": 3, "LONGITUDE": 4}
    assert "u_mean" in result["variables"] and "u" not in result["variables"]
    assert result["variables"]["u_var"]["units"] == "m2 s-2"
    assert result["variables"]["station_count"]["units"] is None
    assert result["attributes"]["numbers"] == [1, 2]
    assert result["attributes"]["missing"] is None
    assert result["velocity_components"] == {"eastward": ["u_mean"], "northward": ["v_mean"]}
    assert result["variables"]["u_mean"]["role_identified_by"] == "standard_name"
    assert result["variables"]["v_mean"]["role_identified_by"] == "variable_name"
    assert result["coordinates"]["LATITUDE"]["values"] == [35, 36]
    assert result["coordinates"]["LATITUDE"]["truncated"] is True
    assert result["coordinates"]["TIME"]["values"] == [1]
    assert result["variables"]["TIME"]["attributes"]["units"].startswith("hours since")
    json.dumps(result, allow_nan=False)


def test_schema_only_metadata_reads_no_variable_values(storage, netcdf_contents, monkeypatch):
    key = file_key()
    obs.put(storage, key, netcdf_contents)

    def no_values(variable):
        raise AssertionError("Schema-only inspection loaded variable values")

    monkeypatch.setattr(xr.Variable, "values", property(no_values))
    response = get(f"/datasets/{datasetcatalog.encode_dataset_id(key)}/metadata", {"coordinate_limit": 0})
    assert response.status_code == 200, response.text
    assert all(not coordinate["values"] for coordinate in response.json()["coordinates"].values())


@pytest.mark.parametrize("coordinate_limit", [0, 2])
def test_metadata_uses_small_ranges_instead_of_downloading_file(storage, tmp_path, monkeypatch, coordinate_limit):
    source = xr.Dataset(
        {"u": (("lat", "lon"), np.ones((1024, 1024), dtype=np.float32)),
         "v": (("lat", "lon"), np.ones((1024, 1024), dtype=np.float32))},
        coords={"lat": np.arange(1024), "lon": np.arange(1024)},
    )
    path = tmp_path / "large.nc"
    source.to_netcdf(path, engine="h5netcdf")
    contents = path.read_bytes()
    expected = datasetmetadata.describe_netcdf(contents, coordinate_limit)
    key = file_key()
    obs.put(storage, key, contents)
    ranges = Mock(wraps=obs.get_range)
    monkeypatch.setattr(obs, "get_range", ranges)
    monkeypatch.setattr(obs, "get", Mock(side_effect=AssertionError("Full download")))
    monkeypatch.setattr(obs, "get_async", AsyncMock(side_effect=AssertionError("Full download")))

    response = get(f"/datasets/{datasetcatalog.encode_dataset_id(key)}/metadata",
                   {"coordinate_limit": coordinate_limit})
    assert response.status_code == 200, response.text
    result = response.json()
    result.pop("dataset")
    assert result == expected
    transferred = sum(call.kwargs["end"] - call.kwargs["start"] for call in ranges.call_args_list)
    assert 0 < transferred < len(contents) // 10
    # HDF5 revisits headers; each cached block should be fetched only once here.
    starts = [call.kwargs["start"] for call in ranges.call_args_list]
    assert len(starts) == len(set(starts))


@pytest.mark.parametrize("key", ["../../private.nc", "other/data.nc", "s3://other-bucket/private.nc",
                                   file_key().replace("hfrtv/", "private/"),
                                   file_key().replace("uswc", "unknown")])
def test_metadata_rejects_identifiers_outside_totals_namespace(key, monkeypatch):
    downloader = AsyncMock(side_effect=AssertionError("Invalid ID reached storage"))
    monkeypatch.setattr(datasetapi.obs, "head_async", downloader)
    response = get(f"/datasets/{datasetcatalog.encode_dataset_id(key)}/metadata")
    assert response.status_code == 422
    downloader.assert_not_awaited()


def test_missing_and_unreadable_datasets_have_explicit_errors(storage):
    key = file_key()
    path = f"/datasets/{datasetcatalog.encode_dataset_id(key)}/metadata"
    assert get(path).status_code == 404
    obs.put(storage, key, b"not a NetCDF file")
    assert get(path).status_code == 422
    assert get(path, {"coordinate_limit": 1001}).status_code == 422


def test_metadata_rejects_large_objects_before_reading_body(storage, monkeypatch):
    body = Mock(side_effect=AssertionError("Oversized body was downloaded"))
    monkeypatch.setattr(datasetapi.obs, "head_async", AsyncMock(return_value={"size": datasetapi.MAX_METADATA_BYTES + 1}))
    monkeypatch.setattr(datasetmetadata.obs, "get_range", body)
    path = f"/datasets/{datasetcatalog.encode_dataset_id(file_key())}/metadata"
    assert get(path).status_code == 413
    body.assert_not_called()


def test_metadata_range_storage_errors_remain_gateway_errors(storage, netcdf_contents, monkeypatch):
    key = file_key()
    obs.put(storage, key, netcdf_contents)
    monkeypatch.setattr(obs, "get_range", Mock(side_effect=obs.exceptions.GenericError("Storage unavailable")))
    assert get(f"/datasets/{datasetcatalog.encode_dataset_id(key)}/metadata").status_code == 502


def test_latest_endpoint_returns_no_content_when_bounded_search_is_empty(storage):
    response = get("/latest", {"prod": "h_6km", "lookback_periods": 1})
    assert response.status_code == 204
    assert get("/latest", {"prod": "bogus_6km"}).status_code == 422


def test_multidimensional_auxiliary_coordinates_have_bounded_samples(storage, tmp_path):
    source = xr.Dataset(
        {"east_velocity": (("y", "x"), np.ones((3, 4)),
                           {"standard_name": "eastward_sea_water_velocity", "units": "cm s-1"}),
         "north_velocity": (("y", "x"), np.ones((3, 4)),
                            {"standard_name": "northward_sea_water_velocity", "units": "cm s-1"})},
        coords={"latitude_grid": (("y", "x"), np.arange(12).reshape(3, 4)),
                "longitude_grid": (("y", "x"), np.arange(12).reshape(3, 4) - 125),
                "reference_depth": 1.0},
    )
    path = tmp_path / "aux.nc"
    source.to_netcdf(path, engine="h5netcdf")
    key = file_key()
    obs.put(storage, key, path.read_bytes())
    response = get(f"/datasets/{datasetcatalog.encode_dataset_id(key)}/metadata", {"coordinate_limit": 5})
    assert response.status_code == 200, response.text
    result = response.json()
    assert result["velocity_components"] == {"eastward": ["east_velocity"], "northward": ["north_velocity"]}
    for name in ("latitude_grid", "longitude_grid"):
        sample = result["coordinates"][name]
        assert len(sample["values"]) <= 5
        assert np.prod(sample["sample_shape"]) == len(sample["values"])
        assert sample["truncated"] is True
        assert result["variables"][name]["is_coordinate"] is True
    assert result["coordinates"]["reference_depth"]["values"] == [1.0]


def test_unix_time_and_maximum_datetime_range(storage):
    key = file_key()
    obs.put(storage, key, b"data")
    instant = datetime(2026, 10, 29, 5, tzinfo=timezone.utc).timestamp()
    assert get("/datasets", {"prod": "h_6km", "time": instant}).json()["returned"] == 1
    response = get("/datasets", {"prod": "ma_6km", "start_time": "9999-12-01T00:00:00Z",
                                "end_time": "9999-12-31T23:59:59Z"})
    assert response.status_code == 200, response.text


def test_bbox_can_cross_date_line_and_cursor_must_match_product(storage):
    params = [("prod", "h_6km"), ("time", "2026-10-29T05:00:00Z")]
    response = get("/datasets", params + [("bbox", v) for v in (170, 60, -160, 74)])
    assert response.status_code == 200, response.text
    assert "AKNS" in response.json()["regions"]
    mismatch = get("/datasets", params + [("cursor", datasetcatalog.encode_dataset_id(file_key(prod="a_6km")))])
    assert mismatch.status_code == 422


def test_storage_errors_and_missing_configuration_have_stable_http_errors(storage, monkeypatch):
    listing = AsyncMock(side_effect=OSError("backend error"))
    monkeypatch.setattr(rtvfiles, "_list_files", listing)
    assert get("/datasets", {"latest": True, "lookback_periods": 1}).status_code == 502
    download = AsyncMock(side_effect=OSError("backend error"))
    monkeypatch.setattr(datasetapi.obs, "head_async", download)
    assert get(f"/datasets/{datasetcatalog.encode_dataset_id(file_key())}/metadata").status_code == 502
    monkeypatch.setattr(settings, "bucket", None)
    assert get("/datasets", {"latest": True}).status_code == 503
    assert get("/datasets/options").status_code == 200


def test_invalid_https_configuration_is_explicit(storage, monkeypatch):
    obs.put(storage, file_key(), b"data")
    monkeypatch.setenv("HFRNET_DATA_HTTPS_BASE_URL", "http://insecure.example.test")
    response = get("/datasets", {"prod": "h_6km", "time": "2026-10-29T05:00:00Z"})
    assert response.status_code == 503


def test_openapi_exposes_search_and_metadata_schemas():
    response = get("/openapi.json")
    assert response.status_code == 200, response.text
    spec = response.json()
    params = {param["name"]: param for param in spec["paths"]["/datasets"]["get"]["parameters"]}
    assert params["regions"]["in"] == "query"
    assert params["limit"]["schema"]["maximum"] == 500
    assert "DatasetMetadata" in spec["components"]["schemas"]
