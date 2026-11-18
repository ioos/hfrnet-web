import asyncio
from threading import Event

import pytest

import processingpool


async def wait_until(predicate):
    async with asyncio.timeout(4):
        while not predicate():
            await asyncio.sleep(0.001)


def test_overlapping_requests_share_pool_and_submission_limit(processing_executors):
    released = Event()

    def process(value):
        if not released.wait(timeout=4):
            raise RuntimeError("Event loop did not release the worker")
        return value * 2

    async def run():
        first = asyncio.create_task(processingpool.process_files(process, list(range(8))))
        second = asyncio.create_task(processingpool.process_files(process, [8, 9]))
        try:
            await wait_until(lambda: processing_executors and
                             len(processing_executors[0].submissions) == 2)
            # Give all other submissions a chance to reach the shared limit.
            await asyncio.sleep(0)
            assert len(processing_executors) == 1
            executor = processing_executors[0]
            assert len(executor.submissions) == 2
            assert executor.start_method == "spawn"
            released.set()
            assert await first == list(range(0, 16, 2))
            assert await second == [16, 18]
            assert await processingpool.process_files(process, [10]) == [20]
            assert len(processing_executors) == 1
            assert executor.shutdown_calls == 0
        finally:
            released.set()
            await asyncio.gather(first, second, return_exceptions=True)
            await processingpool.shutdown_processing_pool()
        assert executor.shutdown_calls == 1

    asyncio.run(run())


def test_cancellation_keeps_running_job_counted_and_pool_available(processing_executors):
    started, released = Event(), Event()

    def process(value):
        started.set()
        if not released.wait(timeout=4):
            raise RuntimeError("Event loop did not release the worker")
        return value

    async def run():
        request = asyncio.create_task(processingpool.process_files(process, [1]))
        remaining = []
        try:
            await wait_until(started.is_set)
            request.cancel()
            with pytest.raises(asyncio.CancelledError):
                await asyncio.wait_for(request, timeout=1)

            pool = processingpool._get_processing_pool()
            remaining = [asyncio.create_task(pool.run(process, value)) for value in (2, 3)]
            await wait_until(lambda: len(processing_executors[0].submissions) >= 2)
            await asyncio.sleep(0)
            executor = processing_executors[0]
            # One cancelled-but-running job plus one queued job fill the limit.
            assert len(executor.submissions) == 2
            assert executor.shutdown_calls == 0
            released.set()
            assert await asyncio.gather(*remaining) == [2, 3]
        finally:
            released.set()
            await asyncio.gather(request, *remaining, return_exceptions=True)
            await processingpool.shutdown_processing_pool()

    asyncio.run(run())


def test_shutdown_waits_without_blocking_event_loop(processing_executors):
    started, released = Event(), Event()

    def process(value):
        started.set()
        if not released.wait(timeout=4):
            raise RuntimeError("Shutdown blocked the event loop")
        return value

    async def run():
        request = asyncio.create_task(processingpool.process_files(process, [1]))
        shutdown = None
        try:
            await wait_until(started.is_set)
            pool = processingpool._get_processing_pool()
            shutdown = asyncio.create_task(processingpool.shutdown_processing_pool())
            await asyncio.sleep(0)
            assert not shutdown.done()
            with pytest.raises(RuntimeError, match="shutting down"):
                await pool.run(process, 2)
            released.set()
            assert await request == [1]
            await shutdown
            assert processingpool._processing_pool is None
            assert processing_executors[0].shutdown_calls == 1
            await processingpool.shutdown_processing_pool()
        finally:
            released.set()
            await asyncio.gather(request, return_exceptions=True)
            if shutdown is not None:
                await shutdown

    asyncio.run(run())


@pytest.mark.parametrize("configured,expected", [
    (None, 1), ("", 1), ("invalid", 1), ("0", 1), ("-2", 1), ("2", 2),
])
def test_worker_configuration(processing_executors, monkeypatch, configured, expected):
    if configured is None:
        monkeypatch.delenv("NUM_PROCESS", raising=False)
    else:
        monkeypatch.setenv("NUM_PROCESS", configured)

    async def run():
        try:
            assert await processingpool.process_files(abs, [-1]) == [1]
            assert processing_executors[0].worker_count == expected
        finally:
            await processingpool.shutdown_processing_pool()

    asyncio.run(run())


def test_spawned_process_pool_executes_pickled_jobs(monkeypatch):
    monkeypatch.setattr(processingpool, "_processing_pool", None)
    monkeypatch.setenv("NUM_PROCESS", "1")

    async def run():
        try:
            assert await processingpool.process_files(pow, [2, 3], 3) == [8, 27]
            assert await processingpool.process_files(pow, [4], 2) == [16]
        finally:
            await processingpool.shutdown_processing_pool()

    asyncio.run(run())
