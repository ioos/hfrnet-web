# HFRNET API

This repository contains the HFRNET API application for processing and retrieving data. The application is built using Python and Flask and supports running with 
Compose or directly in a Python environment.

## Features
- Serve data endpoints for radial and wave data.
- Flask-based API with several data endpoints.
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
   Update the `HFRNET_DIR` variable to point to your local data directory.

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
   Update the variables in `.env` as needed (e.g., `HFRNET_DIR`).

2. Start the application:
   ```bash
   flask run --host=0.0.0.0
   ```
   The application will be accessible at `http://localhost:5000`.

---


## File Descriptions
- `radialdata.py`: Main application code containing endpoints for the API.
- `requirements.txt`: Python dependencies.
- `Dockerfile`: Docker image definition.
- `docker-compose.yml`: Compose file for containerized deployment.
- `.env.example`: Example environment variables configuration file.
- `radialdata.wsgi`: WSGI entry point for the application.

---

## Endpoints
- `/`: Fetch processed data based on provided parameters.
- `/hist`: Retrieve historical data ending at the `time` Unix timestamp.
  If omitted, the end time defaults to now minus 15 hours. Hourly
  products return the preceding six days.
- `/waves`: Retrieve wave-related data.
- `/waves/hist`: Retrieve historical wave data.

For details on the API parameters, refer to the comments in `radialdata.py`.

---

## Environment Variables
- `FLASK_ENV`: Flask environment (e.g., `development`, `production`).
- `FLASK_APP`: Entry point script (default: `radialdata.py`).
- `HFRNET_DIR`: Directory path for HFRNET data.

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

Run the API tests from the project root after installing `pytest`:

```bash
PYTHONPATH=api AWS_BUCKET_NAME=test AWS_REGION=us-east-1 .venv/bin/python -m pytest api/tests -q
```

---
