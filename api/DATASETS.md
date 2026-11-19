# Totals dataset discovery and NetCDF metadata

These read-only endpoints reuse the API's configured S3 client and totals file
lookup. They require no catalog database or MCP runtime. Use `/docs` or
`/openapi.json` for request and response schemas.

## Supported selectors

`GET /datasets/options` returns supported product prefixes, resolutions, region
codes and candidate bounding boxes, aliases, and request limits. It does not
claim that every supported combination has files.

Product strings combine a prefix and resolution, for example `h_6km`:

| Prefix | Product | Selection period |
| --- | --- | --- |
| `h` | Hourly totals | UTC hour |
| `a` | 25-hour average totals | UTC hour |
| `ma` (`am` alias) | Monthly average totals | UTC calendar month |
| `ya` (`ay` alias) | Annual average totals | UTC calendar year |

Resolutions are `500m`, `1km`, `2km`, and `6km`. Responses canonicalize `am` to
`ma` and `ay` to `ya`.

## Search available files

`GET /datasets` requires `prod` (default `a_6km`) and exactly one time selection:

- `time`: Select the product period containing this instant.
- `start_time` and `end_time`: Select every product period touched by the
  inclusive range. A range can cover at most 744 periods.
- `latest=true`: Find the newest period with at least one matching regional
  file. `lookback_periods` includes the current period and defaults to 168; its
  maximum is 744. An exhausted search returns an empty JSON result.

Times accept ISO 8601 strings with an explicit timezone or Unix seconds. They
are converted to UTC. Naive datetimes are rejected. A monthly range from
January 31 to March 1 selects January, February, and March. Time selection uses
product periods, not intersection with a 25-hour averaging window.

For geography, choose either repeated `regions` codes or a repeated four-value
`bbox` in `[west,south,east,north]` order. Region codes are case insensitive. A
box with `west > east` crosses the date line. Omitting geography searches all
configured regions. Geographic selection uses configured regional boxes, not
per-file footprints or a guarantee of nonmissing observations.

```bash
curl --get 'http://localhost:5000/datasets' \
  --data-urlencode 'prod=h_6km' \
  --data-urlencode 'regions=USWC' \
  --data-urlencode 'time=2026-10-29T05:00:00Z'

curl --get 'http://localhost:5000/datasets' \
  --data-urlencode 'prod=ma_6km' \
  --data-urlencode 'regions=USWC' \
  --data-urlencode 'start_time=2026-01-31T00:00:00Z' \
  --data-urlencode 'end_time=2026-03-01T00:00:00Z'

curl --get 'http://localhost:5000/datasets' \
  --data-urlencode 'prod=h_6km' \
  --data-urlencode 'latest=true' \
  --data-urlencode 'lookback_periods=48' \
  --data-urlencode 'bbox=-125' --data-urlencode 'bbox=35' \
  --data-urlencode 'bbox=-120' --data-urlencode 'bbox=40'
```

The response contains `product`, selected `regions`, `datasets`, `returned`, and
`next_cursor`. `limit` defaults to 100 and is at most 500. If `next_cursor` is
nonnull, repeat the same search with `cursor` set to its value. Results are
ordered by object key. Pagination is not an archive snapshot: newly arriving
revisions can change later results.

Each dataset record includes:

- `dataset_id`: URL-safe identifier for the exact object key in this deployment.
  Retain it verbatim for metadata requests. It does not resolve to a later file
  revision automatically.
- `product`, `resolution`, `region`, `filename`, and `object_key`.
- `nominal_time`: UTC hour/month/year used for selection.
- `observation_start`, `observation_end`, and `creation_time`: Parsed from the
  filename's `s`, `e`, and `c` fields. `time_source=filename` explicitly marks
  these as filename declarations, not verified NetCDF time coverage.
- `candidate_bbox` and `spatial_coverage_source=configured_region`.
- `access`: S3 link plus an optional HTTPS link, each with protocol,
  `application/x-netcdf` media type, and `authentication=deployment_dependent`.
  Links do not contain credentials or signatures. No anonymous access is
  promised. Set `HFRNET_DATA_HTTPS_BASE_URL` only when object keys are actually
  served under that HTTPS root.

The existing filename revision policy selects the lexicographically greatest
matching filename per product, region, and period. Missing periods are omitted;
an empty search returns 200 with `datasets=[]`. File discovery does not download
NetCDF objects. Listings retain their existing five-minute cache policy, while
latest lookup also retains empty listings for the duration of one request.

## Describe a NetCDF file

`GET /datasets/{dataset_id}/metadata?coordinate_limit=10` describes the original
file, preserving native names such as `u_mean`, `TIME`, and `u_var`. It returns:

- `dataset`: The exact dataset record and access links.
- `dimensions`: Native dimension names and lengths.
- `attributes`: Global attributes, normalized for JSON.
- `variables`: Every variable, including coordinates, with dimensions, shape,
  stored dtype, units (or null), attributes, selected encoding information, and
  an `is_coordinate` flag.
- `coordinates`: Bounded samples from coordinate variables. Each sample has
  flattened `values`, dimension `selection` as `[start,stop)` indices,
  `sample_shape`, and a `truncated` flag. Multidimensional samples are rectangular
  prefixes containing no more than `coordinate_limit` values per variable.
- `coordinate_roles`: Identified latitude, longitude, time, and depth variables.
- `velocity_components`: Candidate eastward and northward variable names.
  Identification first uses recognized `standard_name` attributes, then the
  existing `u`/`v` and `u_mean`/`v_mean` aliases. Multiple matches are preserved;
  each identified variable reports its role and identification source.

Coordinate values are **stored values**. Interpret their units, calendar,
`scale_factor`, `add_offset`, and fill/missing-value attributes as applicable.
The endpoint does not derive, mask, or normalize scientific variables. Missing
units remain null; nonfinite JSON numbers become null.

`coordinate_limit` is between 0 and 1000, inclusive. With zero, no variable
values are loaded by the NetCDF inspector. Xarray opens a seekable S3 range
reader, fetching only the blocks needed for NetCDF headers and requested
coordinate samples. Reads use 64 KiB blocks with a cache of up to 2 MiB per
inspection; a block can also contain nearby scientific data. The complete file
is not downloaded first. Files larger than 256 MiB are rejected from the
object's reported size before any range reads. NetCDF inspection runs off
the event loop and closes the file before returning. Metadata is returned
directly as JSON; the response model documents the schema without validating
the NetCDF-derived response through Pydantic again.

Only valid totals keys under the configured root can be described. Arbitrary
URLs, other buckets, and unrelated object paths are not accepted.

## Errors and limits

| HTTP status | Meaning |
| --- | --- |
| 413 | File exceeds the metadata inspection size limit |
| 422 | Invalid selector, cursor, dataset ID, or unreadable NetCDF file |
| 404 | A valid dataset ID references a missing file |
| 502 | Object storage is unavailable |
| 503 | Dataset storage or HTTPS-link configuration is missing/invalid |
| 504 | Discovery or metadata inspection exceeded 30 seconds |

The existing `/latest` route also uses bounded lookup with `lookback_periods`
between 1 and 744 (default 168). Its existing response shape and `any`/`all`
behavior are retained; `all` still omits the configured excluded regions. It
returns 204 when no files are found in the search window. New `/datasets`
searches use explicit configured regions without that exclusion.
