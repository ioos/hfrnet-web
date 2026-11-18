"""Read-only dataset discovery and metadata routes for API and MCP clients."""

import asyncio
import logging
from typing import Annotated, Any

from fastapi import APIRouter, HTTPException, Query
from obstore.exceptions import BaseError as ObjectStoreError
from pydantic import BaseModel
from starlette.concurrency import run_in_threadpool

from datasetcatalog import (DatasetRecord, DatasetResults, DatasetSearch, dataset_record,
                            decode_dataset_id, discovery_options, search_datasets)
from datasetmetadata import describe_netcdf
from rtvprocessing import dl_file


router = APIRouter(prefix="/datasets", tags=["datasets"])
logger = logging.getLogger("uvicorn")
MAX_METADATA_BYTES = 256 * 1024 * 1024


class VariableMetadata(BaseModel):
    dimensions: list[str]
    shape: list[int]
    dtype: str
    units: Any = None
    attributes: dict[str, Any]
    encoding: dict[str, Any]
    is_coordinate: bool
    velocity_role: str | None = None
    role_identified_by: str | None = None


class CoordinateSample(BaseModel):
    values: list[Any]
    selection: dict[str, list[int]]
    sample_shape: list[int]
    truncated: bool


class DatasetMetadata(BaseModel):
    dataset: DatasetRecord
    dimensions: dict[str, int]
    attributes: dict[str, Any]
    variables: dict[str, VariableMetadata]
    coordinates: dict[str, CoordinateSample]
    coordinate_roles: dict[str, list[str]]
    velocity_components: dict[str, list[str]]
    coordinate_values: str


@router.get("/options")
async def get_discovery_options():
    """List supported selectors and search semantics, independently of availability."""
    return discovery_options()


@router.get("", response_model=DatasetResults)
async def get_datasets(query: Annotated[DatasetSearch, Query()]):
    """Find exact totals file revisions without downloading scientific arrays."""
    try:
        async with asyncio.timeout(30):
            return await search_datasets(query)
    except TimeoutError:
        raise HTTPException(status_code=504, detail="Dataset discovery timed out") from None
    except (ObjectStoreError, OSError):
        logger.exception("Dataset discovery storage error")
        raise HTTPException(status_code=502, detail="Dataset storage is unavailable") from None


@router.get("/{dataset_id}/metadata", response_model=DatasetMetadata)
async def get_dataset_metadata(
    dataset_id: str,
    coordinate_limit: Annotated[int, Query(ge=0, le=1000)] = 10,
):
    """Describe native variables and bounded samples of stored coordinate values."""
    key = decode_dataset_id(dataset_id)
    record = dataset_record(key)
    try:
        async with asyncio.timeout(30):
            downloaded = await dl_file(key, max_bytes=MAX_METADATA_BYTES)
        if downloaded is None:
            raise FileNotFoundError
    except FileNotFoundError:
        raise HTTPException(status_code=404, detail="Dataset not found") from None
    except TimeoutError:
        raise HTTPException(status_code=504, detail="Dataset download timed out") from None
    except (ObjectStoreError, OSError):
        logger.exception("Dataset metadata storage error")
        raise HTTPException(status_code=502, detail="Dataset storage is unavailable") from None
    try:
        metadata = await run_in_threadpool(describe_netcdf, downloaded["data"], coordinate_limit)
    except (ValueError, OSError, TypeError):
        logger.exception("Unreadable NetCDF dataset")
        raise HTTPException(status_code=422, detail="Dataset is not a readable totals NetCDF file") from None
    return {"dataset": record.model_dump(mode="json"), **metadata}
