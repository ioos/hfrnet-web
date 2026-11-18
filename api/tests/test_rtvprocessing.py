import asyncio
from concurrent.futures import ThreadPoolExecutor
from threading import Event

import numpy as np
import pytest
import xarray as xr

import rtvprocessing


@pytest.mark.parametrize("averaged", [False, True])
def test_history_loads_only_the_requested_point(tmp_path, monkeypatch, averaged):
    source = xr.Dataset(
        {"u": (("time", "lat", "lon"), np.arange(9).reshape(1, 3, 3) + 1.0),
         "v": (("time", "lat", "lon"), np.ones((1, 3, 3))),
         "unused": (("lat", "lon"), np.ones((3, 3)))},
        coords={"time": [np.datetime64("2026-10-29")],
                "lat": [35.0, 36.0, 37.0], "lon": [-124.0, -123.0, -122.0]},
    )
    if averaged:
        source = source.rename({"u": "u_mean", "v": "v_mean", "time": "TIME",
                                "lat": "LATITUDE", "lon": "LONGITUDE"})
    path = tmp_path / "history.nc"
    source.to_netcdf(path, engine="h5netcdf")
    loaded = []
    original_load = xr.Dataset.load

    def record_load(dataset, **kwargs):
        if "u" in dataset and "v" in dataset:
            loaded.append((dict(dataset.sizes), set(dataset.data_vars)))
        return original_load(dataset, **kwargs)

    monkeypatch.setattr(xr.Dataset, "load", record_load)
    result = rtvprocessing.process_hist_file({"file": path.name, "data": path.read_bytes()}, 36.1, -122.9)
    assert loaded == [({"time": 1, "lat": 1, "lon": 1}, {"u", "v"})]
    assert result.u.item() == 5.0
    assert result.v.item() == 1.0
    assert result.lat.item() == 36.0
    assert result.lon.item() == -123.0
    assert result.magni.item() == 5.1


def test_invalid_history_file():
    assert rtvprocessing.process_hist_file({"file": "bad.nc", "data": b"invalid"}, 36, -123) is None


def test_history_processing_keeps_event_loop_responsive(monkeypatch):
    started, released = Event(), Event()

    async def download(file):
        return {"file": file, "data": b"unused"}

    def process(*args):
        started.set()
        if not released.wait(timeout=2):
            raise RuntimeError("Event loop blocked while waiting for executor")
        return xr.Dataset(
            {"u": (("time", "lat", "lon"), [[[1.0]]]),
             "v": (("time", "lat", "lon"), [[[2.0]]])},
            coords={"time": [np.datetime64("2026-10-29")], "lat": [36.0], "lon": [-123.0]},
        )

    monkeypatch.setattr(rtvprocessing, "dl_file", download)
    monkeypatch.setattr(rtvprocessing, "process_hist_file", process)
    monkeypatch.setattr(rtvprocessing, "ProcessPoolExecutor", ThreadPoolExecutor)
    monkeypatch.setenv("NUM_PROCESS", "1")

    async def run():
        async def release_from_event_loop():
            while not started.is_set():
                await asyncio.sleep(0.001)
            released.set()

        data, _ = await asyncio.gather(
            rtvprocessing.get_hist_data(["bucket/history.nc"], 36.0, -123.0, None),
            release_from_event_loop(),
        )
        return data

    try:
        result = asyncio.run(run())
    finally:
        released.set()
    assert len(result) == 1
    assert result.index.names == ["time", "lat", "lon", "index"]
