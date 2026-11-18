"""Bounded NetCDF processing shared by requests in one API worker."""

import asyncio
import multiprocessing
from concurrent.futures import ProcessPoolExecutor
from os import getenv


class _ProcessingPool:
    def __init__(self, max_workers):
        # Spawn avoids inheriting active S3/HDF5 library threads and handles.
        self._executor = ProcessPoolExecutor(
            max_workers=max_workers,
            mp_context=multiprocessing.get_context("spawn"),
        )
        # Bound running and queued submissions together across all requests.
        self._slots = asyncio.Semaphore(2 * max_workers)
        self._closed = False

    async def run(self, processor, *args):
        await self._slots.acquire()
        if self._closed:
            self._slots.release()
            raise RuntimeError("Processing pool is shutting down")

        loop = asyncio.get_running_loop()
        try:
            future = self._executor.submit(processor, *args)
        except BaseException:
            self._slots.release()
            raise

        def release_slot(_):
            # Request cancellation does not stop an already running process.
            # Keep its slot until the underlying job actually finishes.
            loop.call_soon_threadsafe(self._slots.release)

        future.add_done_callback(release_slot)
        return await asyncio.wrap_future(future)

    async def shutdown(self):
        self._closed = True
        await asyncio.to_thread(
            self._executor.shutdown, wait=True, cancel_futures=True
        )


_processing_pool = None


def _get_processing_pool():
    global _processing_pool
    if _processing_pool is None:
        try:
            max_workers = int(getenv("NUM_PROCESS", "1"))
            if max_workers < 1:
                raise ValueError
        except ValueError:
            max_workers = 1
        _processing_pool = _ProcessingPool(max_workers)
    return _processing_pool


async def process_files(processor, data_files, *args):
    pool = _get_processing_pool()
    tasks = [asyncio.create_task(pool.run(processor, data_file, *args))
             for data_file in data_files]
    try:
        return await asyncio.gather(*tasks)
    except BaseException:
        # Stop waiting submissions and cancel executor jobs where still possible.
        # Running jobs finish in the shared pool without blocking this request.
        for task in tasks:
            task.cancel()
        await asyncio.gather(*tasks, return_exceptions=True)
        raise


async def shutdown_processing_pool():
    global _processing_pool
    if _processing_pool is not None:
        await _processing_pool.shutdown()
        _processing_pool = None
