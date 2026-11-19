# HFRNET API

This repository contains the HFRNET API application for processing and retrieving data. The application is built using Python and FastAPI and supports running with
Compose or directly in a Python environment.

## Features
- Serve data endpoints for radial and wave data.
- Read-only totals dataset discovery and complete NetCDF metadata descriptions.
- FastAPI application with OpenAPI documentation at `/docs` and `/openapi.json`.
- Configurable using environment variables.

---

## Running the Project
### 1. Using Docker Compose
#### Prerequisites:
- Docker and Docker Compose installed on your system.

#### Setup:
1. Create a `.env` file based on `.env.example`:
   ```bash
   cp .env.example .env
   ```
   Set `AWS_BUCKET_NAME`, `AWS_REGION`, and the credentials needed for your data
   bucket. For a public bucket, `AWS_SKIP_SIGNATURE=true` enables anonymous reads.

2. Build and run the container:
   ```bash
   docker-compose up --build
   ```
   The application will be accessible at `http://localhost:5000`.

---

### 2. Local Python Environment
#### Prerequisites:
- Ensure Python 3.11 or newer is installed (the Docker image uses Python 3.12).
- Install dependencies:
  ```bash
  pip install -r requirements.txt
  ```
#### Setup:
1. Create a `.env` file based on the `.env.example` file:
   ```bash
   cp .env.example .env
   ```
   Configure the bucket, region, and AWS authentication variables in `.env`.

2. Start the application:
   ```bash
   uvicorn radialdata:app --host 0.0.0.0 --port 5000
   ```
   Run from the `api/` directory after loading your AWS environment variables
   into the shell (`.env` files are loaded by Compose, not automatically by this
   Uvicorn command). The application will be accessible at `http://localhost:5000`.

---


## File Descriptions
- `radialdata.py`: Main application code containing endpoints for the API.
- `requirements.txt`: Python dependencies.
- `Dockerfile`: Docker image definition.
- `docker-compose.yml`: Compose file for containerized deployment.
- `.env.example`: Example environment variables configuration file.
- `datasetapi.py`: Dataset discovery and metadata HTTP routes.
- `datasetcatalog.py`: Search validation, dataset identifiers, and access records.
- `datasetmetadata.py`: Original NetCDF schema, attributes, and coordinate samples.

---

## Endpoints
- `/`: Fetch processed data based on provided parameters.
- `/hist`: Retrieve historical data ending at the `time` Unix timestamp.
  If omitted, the end time defaults to now minus 15 hours. Hourly
  products return the preceding six days.
- `/waves`: Retrieve wave-related data.
- `/waves/hist`: Retrieve historical wave data.
- `/latest`: Latest available timestep within a bounded search window. The
  optional `lookback_periods` defaults to 168, with a maximum of 744 product
  periods, including the current period. Returns 204 when none match.
- `/datasets/options`: Supported products, resolutions, regions, and search rules.
- `/datasets`: Available totals files by product, geography, and time selection.
- `/datasets/{dataset_id}/metadata`: Original NetCDF contents and bounded
  coordinate samples. Use `coordinate_limit=0` for schema-only inspection.

See [the dataset API contract and examples](DATASETS.md) for integration from an
MCP server or another client. This repository does not implement an MCP server.

For details on the API parameters, refer to the comments in `radialdata.py`.

---

## Environment Variables
- `AWS_BUCKET_NAME`, `AWS_REGION`, and standard AWS credential variables:
  configure the existing S3 client. `AWS_SKIP_SIGNATURE=true` permits anonymous
  access when the configured bucket allows it.
- `HFRNET_DATA_HTTPS_BASE_URL`: Optional HTTPS root for access links. Object keys
  are appended to it; omit this variable to return only S3 links. Authentication
  is deployment dependent, and no public HTTPS access is assumed.

## S3 history lookup

RTV discovery and downloads share an obstore S3 client configured through the
standard AWS environment variables, including `AWS_BUCKET_NAME` and `AWS_REGION`.
Production requests continue to use the configured AWS credentials. For local
read-only testing against a public bucket, set `AWS_SKIP_SIGNATURE=true`.

`/hist` lists each required day directory once, sharing listings across hours and
regions. Monthly and annual products use their respective averages directories.
Listings are nonrecursive, run with at most four concurrent requests per lookup,
and cache up to 128 nonempty directories for five minutes. The latest matching
filename revision is selected for each requested time. Processing loads only the
nearest point's velocity variables before closing each NetCDF dataset.

Install `api/requirements-dev.txt` for the HTTP tests (`pytest` and `httpx`), then
run the API tests from the project root:

```bash
PYTHONPATH=api AWS_BUCKET_NAME=test AWS_REGION=us-east-1 .venv/bin/python -m pytest api/tests -q
```

---
