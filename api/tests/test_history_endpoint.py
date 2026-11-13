import asyncio
from datetime import datetime, timedelta, timezone
from unittest.mock import AsyncMock

import pandas as pd
import pytest
from fastapi import HTTPException

import radialdata


@pytest.mark.parametrize("params,expected_end", [
    ({"time": 1775339357}, 1775339357),
    ({"time": 0}, 0),
    ({}, 1793232000 - 54000),
])
def test_history_end_time(monkeypatch, params, expected_end):
    discover = AsyncMock(return_value=[])
    monkeypatch.setattr(radialdata.time_module, "time", lambda: 1793232000)
    monkeypatch.setitem(radialdata.config["urls"], "default_time_offset", "54000")
    monkeypatch.setattr(radialdata, "get_hist_files", discover)
    args = {"time": None, **params}

    with pytest.raises(HTTPException) as error:
        asyncio.run(radialdata.get_history(
            None, lat1=39.967682, lon1=-73.434189, prod="a_6km", **args))

    assert error.value.status_code == 204
    discover.assert_awaited_once_with(
        expected_end - 6 * 86400, expected_end, ["USEGC"], "a_6km")


@pytest.mark.parametrize("out,media_type", [("json", "application/json"), ("csv", "text/csv")])
def test_history_awaits_discovery_and_processing(monkeypatch, out, media_type):
    end = datetime(2026, 10, 29, tzinfo=timezone.utc)
    files = ["bucket/hfrtv/history.nc"]
    discover = AsyncMock(return_value=files)
    frame = pd.DataFrame({"u": [1.0], "v": [2.0]}, index=pd.MultiIndex.from_tuples(
        [(pd.Timestamp(end), 36.0, -123.0, 0)], names=["time", "lat", "lon", "index"]))
    process = AsyncMock(return_value=frame)
    monkeypatch.setattr(radialdata, "get_regions", lambda *args: ["USWC"])
    monkeypatch.setattr(radialdata, "get_hist_files", discover)
    monkeypatch.setattr(radialdata, "get_hist_data", process)
    response = asyncio.run(radialdata.get_history(
        None, lon1=-123.0, lat1=36.0, time=end.timestamp(), prod="a_6km", out=out))
    discover.assert_awaited_once_with((end - timedelta(days=6)).timestamp(), end.timestamp(),
                                      ["USWC"], "a_6km")
    process.assert_awaited_once_with(files, 36.0, -123.0, None)
    assert response.media_type == media_type
    assert response.body


def test_points_awaits_file_discovery(monkeypatch):
    files = ["bucket/history.nc"]
    discover = AsyncMock(return_value=files)
    process = AsyncMock(return_value="point-data")
    monkeypatch.setattr(radialdata, "get_regions", lambda *args: ["USWC"])
    monkeypatch.setattr(radialdata, "get_files", discover)
    monkeypatch.setattr(radialdata, "get_data", process)
    monkeypatch.setattr(radialdata, "df_to_geojson", lambda *args: {"type": "FeatureCollection"})
    result = asyncio.run(radialdata.get_points(
        lon1=-124, lat1=37, lon2=-122, lat2=35, prod="a_6km", time=1793232000))
    discover.assert_awaited_once_with(["USWC"], "a_6km", 1793232000)
    process.assert_awaited_once_with(files, 37, -124, 35, -122)
    assert result == {"type": "FeatureCollection"}


@pytest.mark.parametrize("method", ["any", "all"])
def test_latest_awaits_file_discovery(monkeypatch, method):
    discover = AsyncMock(side_effect=[[], ["bucket/history.nc"]])
    monkeypatch.setattr(radialdata, "get_regions", lambda *args: ["USWC"])
    monkeypatch.setattr(radialdata, "get_files", discover)
    result = asyncio.run(radialdata.get_latest(prod="a_6km", method=method))
    assert discover.await_count == 2
    first, second = discover.await_args_list
    assert first.args[2] - second.args[2] == 3600
    assert result["timestamp"] == second.args[2]
