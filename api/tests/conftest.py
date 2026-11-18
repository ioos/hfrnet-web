from concurrent.futures import ThreadPoolExecutor

import pytest

import processingpool


@pytest.fixture
def processing_executors(monkeypatch):
    """Exercise scheduling with real futures and controllable thread jobs."""
    executors = []

    class RecordingExecutor(ThreadPoolExecutor):
        def __init__(self, *, max_workers, mp_context):
            super().__init__(max_workers=max_workers)
            self.worker_count = max_workers
            self.start_method = mp_context.get_start_method()
            self.submissions = []
            self.shutdown_calls = 0
            executors.append(self)

        def submit(self, *args, **kwargs):
            future = super().submit(*args, **kwargs)
            self.submissions.append(future)
            return future

        def shutdown(self, **kwargs):
            self.shutdown_calls += 1
            return super().shutdown(**kwargs)

    monkeypatch.setattr(processingpool, "_processing_pool", None)
    monkeypatch.setattr(processingpool, "ProcessPoolExecutor", RecordingExecutor)
    monkeypatch.setenv("NUM_PROCESS", "1")
    yield executors
    for executor in executors:
        executor.shutdown(wait=True, cancel_futures=True)
