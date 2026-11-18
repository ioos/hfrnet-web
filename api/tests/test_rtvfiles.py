import asyncio
from datetime import datetime, timedelta, timezone
from unittest.mock import AsyncMock

import obstore as obs
import pytest
from dateutil.relativedelta import relativedelta
from obstore.store import MemoryStore

import rtvfiles


def get_files(*args):
    return asyncio.run(rtvfiles.get_files(*args))


def get_hist_files(*args):
    return asyncio.run(rtvfiles.get_hist_files(*args))


@pytest.fixture(autouse=True)
def clear_cache():
    rtvfiles._listing_cache.clear()
    yield
    rtvfiles._listing_cache.clear()


def dt(year, month, day, hour=0, minute=0):
    return datetime(year, month, day, hour, minute, tzinfo=timezone.utc)


def key(time, region="uswc", prod="a_6km", revision="202610300000000"):
    pfx, res = prod.split("_")
    if pfx == "h":
        directory, product = f"{time:%Y/%m/%d}", "uwls"
        start = f"{time:%Y%m%d%H}00000"
    elif pfx == "a":
        directory, product = f"{time:%Y/%m/%d}/averages", "25h-avg"
        start = f"{time:%Y%m%d%H}00000"
    elif pfx in ("am", "ma"):
        directory, product = f"{time:%Y/%m}/averages", "mon-avg"
        start = f"{time:%Y%m}010000000"
    else:
        directory, product = f"{time:%Y}/averages", "ann-avg"
        start = f"{time:%Y}01010000000"
    return (f"test-bucket/hfrtv/{directory}/rtv-{region}-{res}-{product}"
            f"_v1r0_hfr_s{start}_e{start}_c{revision}.nc")


@pytest.fixture
def s3(monkeypatch):
    objects = []
    store = AsyncMock()
    # Deliberately unsorted; listing order must not determine the revision.
    store.side_effect = lambda _, prefix: {"objects": [
        {"path": path.split("/", 1)[1]} for path in reversed(objects)
        if path.rsplit("/", 1)[0] == f"test-bucket/{prefix}"
    ], "common_prefixes": []}
    monkeypatch.setattr(rtvfiles, "bucket", "test-bucket")
    monkeypatch.setattr(rtvfiles.obs, "list_with_delimiter_async", store)
    return store, objects


@pytest.mark.parametrize("prod", ["h_2km", "a_6km", "am_6km", "ma_6km", "ay_6km", "ya_6km"])
def test_latest_revision_and_exact_product(s3, prod):
    store, objects = s3
    time = dt(2026, 10, 29, 5)
    latest = key(time, prod=prod)
    objects.extend([latest, key(time, prod=prod, revision="202610290000000"),
                    key(time, region="ushi", prod=prod), key(time, prod="h_1km"),
                    latest + ".bak", latest + "/nested.nc"])
    assert get_files(["USWC", "USWC"], prod, time.timestamp()) == [latest]
    assert store.await_count == 1


def test_hourly_history_limits_listings_and_preserves_boundaries(s3):
    store, objects = s3
    start, end = dt(2026, 9, 30, 23, 30), dt(2026, 10, 1, 2, 30)
    expected = []
    for time in (start, start + timedelta(hours=2), end):
        for region in ("uswc", "gak"):
            latest = key(time, region=region)
            expected.append(latest)
            objects.extend([latest, key(time, region=region, revision="202610290000000")])
    # The missing hour must stay missing; ignore data outside the request and
    # unrelated products/regions, sidecars, and nested objects.
    objects.extend([key(start - timedelta(hours=1)), key(end + timedelta(hours=1)),
                    key(start, prod="a_1km"), key(start, region="ushi"),
                    expected[0] + ".bak", expected[0] + "/nested.nc"])
    result = get_hist_files(start.timestamp(), end.timestamp(), ["USWC", "GAK"], "a_6km")
    assert result == expected
    assert store.await_count == 2  # one directory per day, shared by both regions


def test_six_day_history_lists_each_day_once(s3):
    store, objects = s3
    start = dt(2026, 10, 23)
    objects.extend(key(start + timedelta(hours=hour)) for hour in range(145))
    assert get_hist_files(start.timestamp(), (start + timedelta(days=6)).timestamp(),
                          ["USWC"], "a_6km") == objects
    assert store.await_count == 7


@pytest.mark.parametrize("prod,step", [
    ("am_6km", relativedelta(months=1)), ("ma_6km", relativedelta(months=1)),
    ("ay_6km", relativedelta(years=1)), ("ya_6km", relativedelta(years=1)),
])
def test_long_averages_across_year_boundary(s3, prod, step):
    store, objects = s3
    start = dt(2024, 12, 31)
    middle, end = start + step, start + step + step
    objects.extend(key(time, prod=prod) for time in (start, middle, end))
    assert get_hist_files(start.timestamp(), end.timestamp(), ["USWC"], prod) == objects
    assert store.await_count == 3


def test_missing_data_and_reversed_range(s3):
    store, _ = s3
    start = dt(2026, 10, 23)
    end = start + timedelta(days=6)
    assert get_hist_files(start.timestamp(), end.timestamp(), ["USWC"], "h_6km") == []
    assert store.await_count == 7  # each empty day is listed only once per request
    store.reset_mock()
    assert get_hist_files(end.timestamp(), start.timestamp(), ["USWC"], "h_6km") == []
    store.assert_not_awaited()


def test_listing_cache_expires_and_is_bounded(s3, monkeypatch):
    store, objects = s3
    time = dt(2026, 10, 29)
    objects.append(key(time))
    clock = [0]
    monkeypatch.setattr(rtvfiles, "monotonic", lambda: clock[0])
    monkeypatch.setattr(rtvfiles, "_LISTING_CACHE_SIZE", 1)
    assert get_files(["USWC"], "a_6km", time.timestamp()) == objects
    assert get_files(["USWC"], "a_6km", time.timestamp()) == objects
    assert store.await_count == 1
    newer = key(time, revision="202610310000000")
    objects.append(newer)
    clock[0] = rtvfiles._LISTING_TTL + 1
    assert get_files(["USWC"], "a_6km", time.timestamp()) == [newer]
    assert store.await_count == 2
    tomorrow = time + timedelta(days=1)
    objects.append(key(tomorrow))
    assert get_files(["USWC"], "a_6km", tomorrow.timestamp()) == [objects[-1]]
    assert len(rtvfiles._listing_cache) == 1
    assert list(rtvfiles._listing_cache) == ["hfrtv/2026/10/30/averages"]


def test_missing_listing_is_rechecked(s3):
    store, objects = s3
    time = dt(2026, 10, 29)
    assert get_files(["USWC"], "a_6km", time.timestamp()) == []
    objects.append(key(time))
    assert get_files(["USWC"], "a_6km", time.timestamp()) == objects
    assert store.await_count == 2


def test_native_obstore_listing_excludes_neighboring_directories(monkeypatch):
    store = MemoryStore()
    time = dt(2026, 10, 29)
    wanted = key(time)
    for path in [wanted, wanted + "/nested.nc", key(time, prod="a_1km"),
                 key(time + timedelta(days=1)), key(time, prod="am_6km")]:
        obs.put(store, path.split("/", 1)[1], b"data")
    monkeypatch.setattr(rtvfiles, "bucket", "test-bucket")
    monkeypatch.setattr(rtvfiles, "s3_store", store)
    assert get_files(["USWC"], "a_6km", time.timestamp()) == [wanted]


def test_history_listing_concurrency_is_bounded(s3):
    store, _ = s3
    active, peak = 0, 0

    async def list_directory(*args, **kwargs):
        nonlocal active, peak
        active += 1
        peak = max(peak, active)
        await asyncio.sleep(0)
        active -= 1
        return {"objects": [], "common_prefixes": []}

    store.side_effect = list_directory
    start = dt(2026, 10, 23)
    assert get_hist_files(start.timestamp(), (start + timedelta(days=6)).timestamp(),
                          ["USWC"], "a_6km") == []
    assert peak == rtvfiles._LISTING_CONCURRENCY
