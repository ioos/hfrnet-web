"""Describe original NetCDF contents without preprocessing scientific variables."""

import io
import math
from datetime import date, datetime

import numpy as np
import xarray as xr


VELOCITY_NAMES = {"u": "eastward", "u_mean": "eastward", "v": "northward", "v_mean": "northward"}
VELOCITY_STANDARD_NAMES = {
    "eastward_sea_water_velocity": "eastward",
    "northward_sea_water_velocity": "northward",
    "surface_eastward_sea_water_velocity": "eastward",
    "surface_northward_sea_water_velocity": "northward",
}
COORDINATE_NAMES = {
    "lat": "latitude", "latitude": "latitude", "lon": "longitude",
    "longitude": "longitude", "time": "time", "depth": "depth",
}
ENCODING_FIELDS = ("_FillValue", "missing_value", "scale_factor", "add_offset", "units", "calendar",
                   "chunksizes", "contiguous", "zlib", "complevel", "shuffle", "fletcher32", "endian")


def json_value(value):
    """Normalize NetCDF attribute types, including strict-JSON missing values."""
    if isinstance(value, dict):
        return {str(key): json_value(item) for key, item in value.items()}
    if isinstance(value, np.ndarray):
        return json_value(value.tolist())
    if isinstance(value, np.generic):
        return json_value(value.item())
    if isinstance(value, (list, tuple)):
        return [json_value(item) for item in value]
    if isinstance(value, bytes):
        return value.decode("utf-8", errors="replace")
    if isinstance(value, (datetime, date)):
        return value.isoformat()
    if isinstance(value, float) and not math.isfinite(value):
        return None
    if value is None or isinstance(value, (str, int, float, bool)):
        return value
    return str(value)


def coordinate_sample(variable, limit):
    if not limit or not variable.size:
        return {"values": [], "selection": {}, "sample_shape": [], "truncated": bool(variable.size)}
    # Read a bounded rectangular prefix rather than flattening a full coordinate.
    selection = {}
    budget = limit
    for dim, length in reversed(list(variable.sizes.items())):
        count = min(length, budget)
        selection[dim] = slice(0, count)
        budget = max(1, budget // count)
    sample = variable.isel(selection)
    values = json_value(sample.values.reshape(-1))
    return {
        "values": values,
        "selection": {dim: [0, index.stop] for dim, index in selection.items()},
        "sample_shape": list(sample.shape),
        "truncated": sample.size < variable.size,
    }


def describe_netcdf(data, coordinate_limit=10):
    # Preserve stored types, dimensions, fill/scale attributes, and time units.
    # Do not create coordinate indexes, which would eagerly load entire axes.
    with xr.open_dataset(io.BytesIO(data), engine="h5netcdf", decode_cf=False,
                         create_default_indexes=False) as dataset:
        coordinates = set(dataset.coords)
        coordinates.update(str(dataset.attrs.get("coordinates", "")).split())
        for variable in dataset.variables.values():
            coordinates.update(str(variable.attrs.get("coordinates", "")).split())
        roles = {"eastward": [], "northward": []}
        coordinate_roles = {"latitude": [], "longitude": [], "time": [], "depth": []}
        variables = {}
        for name, variable in dataset.variables.items():
            attributes = json_value(variable.attrs)
            description = {
                "dimensions": list(variable.dims), "shape": list(variable.shape),
                "dtype": str(variable.dtype), "units": attributes.get("units"),
                "attributes": attributes,
                "encoding": json_value({key: variable.encoding[key] for key in ENCODING_FIELDS
                                        if key in variable.encoding}),
            }
            standard_name = variable.attrs.get("standard_name")
            role = VELOCITY_STANDARD_NAMES.get(standard_name)
            identified_by = "standard_name"
            if role is None:
                role = VELOCITY_NAMES.get(name)
                identified_by = "variable_name"
            if role is not None:
                roles[role].append(name)
                description["velocity_role"] = role
                description["role_identified_by"] = identified_by
            coordinate_role = standard_name if standard_name in coordinate_roles else COORDINATE_NAMES.get(name.lower())
            if coordinate_role is not None:
                coordinates.add(name)
                coordinate_roles[coordinate_role].append(name)
            description["is_coordinate"] = name in coordinates
            variables[name] = description
        samples = {name: coordinate_sample(dataset[name], coordinate_limit)
                   for name in sorted(coordinates) if name in dataset.variables}
        return {
            "dimensions": dict(dataset.sizes), "attributes": json_value(dataset.attrs),
            "variables": variables, "coordinates": samples,
            "coordinate_roles": coordinate_roles, "velocity_components": roles,
            "coordinate_values": "Stored values; interpret using each variable's units, calendar, "
                                 "scale_factor, add_offset, and missing-value attributes.",
        }
