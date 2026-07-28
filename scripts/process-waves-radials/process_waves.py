#!/usr/bin/env python3
"""
This script processes wave files (.wls) from S3, tracking processing
state in MySQL to ensure idempotency and prevent duplicate processing.
"""
import os
import sys
import logging
import argparse
from dotenv import load_dotenv

import boto3
import s3fs
from botocore.exceptions import ClientError

from acquisition import process_acquisition_file, AcquisitionError, InvalidFileError
from database import DataBase
from process_utils import (
    DEFAULT_RECENT_HOURS,
    DEFAULT_FILE_TIMEOUT_SECONDS,
    FileProcessingTracker,
    FileProcessingTimeoutError,
    ProcessingStats,
    run_with_timeout,
    setup_logging,
    validate_environment,
    health_check_s3,
    generate_month_prefixes,
    get_recent_month_prefixes,
    parse_month_arg,
    filter_files_by_time,
    should_process_month,
    extract_site_code,
)

# Load environment variables
load_dotenv()


def get_most_recent_files(files, count=2):
    """Get the most recent wave files based on S3 LastModified timestamp.
    
    :param files: List of file objects from S3
    :param count: Number of most recent files to return (default: 2)
    :return: List of most recent file objects (sorted newest first)
    """
    from datetime import datetime
    if not files:
        return []
    
    sorted_files = sorted(files, key=lambda f: f.get("LastModified", datetime.min), reverse=True)
    return sorted_files[:count]


def process_file(s3_key, s3_obj, bucket, tracker, stats, s3_filesystem, acq_db, dry_run=False,
                 retry_skipped=False):
    """Process a single file through the acquisition pipeline (in-process).
    
    :param s3_key: S3 object key
    :param s3_obj: S3 object metadata
    :param bucket: S3 bucket name
    :param tracker: FileProcessingTracker instance
    :param stats: ProcessingStats instance
    :param s3_filesystem: Shared s3fs.S3FileSystem instance
    :param acq_db: Shared DataBase instance for acquisition inserts
    :param dry_run: If True, don't actually process
    :param retry_skipped: If True, reprocess files previously marked as skipped
    :return: True if successful, False otherwise
    """
    filename = os.path.basename(s3_key)
    s3_etag = s3_obj.get("ETag", "").strip('"')
    s3_last_modified = s3_obj.get("LastModified")
    site_code = extract_site_code(s3_key)
    
    stats.total_files += 1
    
    # Check if already processed or skipped
    if tracker.check_file_processed(s3_key, s3_etag, retry_skipped=retry_skipped):
        try:
            with tracker.connection.cursor() as cursor:
                sql = f"""
                    SELECT status FROM {tracker.table_name}
                    WHERE s3_key = %s AND s3_etag = %s
                """
                cursor.execute(sql, (s3_key, s3_etag))
                result = cursor.fetchone()
                if result:
                    status = result['status']
                    if status == 'success':
                        logging.debug(f"- Already successfully processed: {filename}")
                    elif status == 'skipped':
                        logging.debug(f"- Previously marked as invalid (skipping): {filename}")
        except Exception:
            logging.debug(f"Already processed: {filename}")
        
        stats.already_processed += 1
        return True
    
    if dry_run:
        logging.info(f"- [DRY RUN] Would process: s3://{bucket}/{s3_key}")
        stats.processed_success += 1
        return True
    
    # Mark as processing
    if not tracker.mark_file_processing(
        bucket, s3_key, s3_etag, s3_last_modified,
        site_code, filename
    ):
        logging.error(f"Failed to mark file as processing: {filename}")
        stats.skipped += 1
        return False
    
    logging.info(f"- Processing s3://{bucket}/{s3_key}")
    
    try:
        run_with_timeout(
            process_acquisition_file,
            DEFAULT_FILE_TIMEOUT_SECONDS,
            filename,
            s3_filesystem,
            acq_db,
        )
        tracker.mark_file_success(s3_key, s3_etag)
        stats.processed_success += 1
        logging.info(f"- Successfully processed {filename}")
        return True
        
    except FileProcessingTimeoutError as e:
        logging.error(f"TIMEOUT processing {filename}: {e}")
        tracker.mark_file_failed(s3_key, s3_etag, str(e), "TimeoutExpired")
        stats.processed_failed += 1
        return False

    except InvalidFileError as e:
        logging.info(f"- SKIPPING invalid file {filename}: {e}")
        tracker.mark_file_skipped(s3_key, s3_etag, str(e), "InvalidFile")
        stats.skipped += 1
        return False
        
    except AcquisitionError as e:
        logging.error(f"- ERROR processing {filename}: {e}")
        tracker.mark_file_failed(s3_key, s3_etag, str(e), "AcquisitionError")
        stats.processed_failed += 1
        return False
        
    except Exception as e:
        error_msg = str(e)
        error_type = type(e).__name__
        logging.error(f"EXCEPTION processing {filename}: {error_type} - {error_msg}")
        tracker.mark_file_failed(s3_key, s3_etag, error_msg, error_type)
        stats.processed_failed += 1
        return False


def main():
    """Main execution function."""
    parser = argparse.ArgumentParser(
        description='Process wave files from S3 with database tracking',
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog="""
Examples:
  %(prog)s                          Process recent files for all sites
  %(prog)s --site RAGG              Process recent files for RAGG only
  %(prog)s --site RAGG --month 2025-06
                                    Process all RAGG files from June 2025
  %(prog)s --month 2025-06          Process all sites for June 2025
  %(prog)s --site CORE --month 2025-05:2025-12
                                    Process CORE from May through Dec 2025
  %(prog)s --months 3               Process last 3 months for all sites
  %(prog)s --site RAGG --months 3   Process last 3 months for RAGG only
  %(prog)s --all --dry-run          Preview processing all files
  %(prog)s --site WOOD --month 2026-07 --retry-skipped
                                    Reprocess WOOD files previously marked as skipped

Environment Variables Required:
  AWS_ACCESS_KEY_ID              AWS access key
  AWS_SECRET_ACCESS_KEY          AWS secret key
  AWS_SESSION_TOKEN              AWS session token (optional)
  BUCKET_NAME                    S3 bucket name
  DB_USER                        Database username
  DB_PASSWD                      Database password
  DB_HOST                        Database host
  DB_PORT                        Database port
  DB_DATABASE                    HFRadar database name
  FILEPROCESSING_DB_DATABASE     File processing database name (default: fileprocessing)
  LOG_FILE_PATH                  Log file path (optional)
        """
    )
    
    parser.add_argument('--site', type=str, metavar='CODE',
                        help='Process only this site code (e.g. RAGG)')
    parser.add_argument('--month', type=str, metavar='YYYY-MM[:YYYY-MM]',
                        help='Single month or inclusive range (e.g. 2025-06 or 2025-05:2025-12)')
    parser.add_argument('--months', type=int, metavar='N',
                        help='Number of months to process including current month (1=current only, 3=current+2 previous)')
    parser.add_argument('--all', action='store_true',
                        help='Process all available months and all files for each site')
    parser.add_argument('--dry-run', action='store_true',
                        help='Show what would be processed without actually processing')
    parser.add_argument('--retry-skipped', action='store_true',
                        help='Reprocess files previously marked as skipped/invalid')
    
    args = parser.parse_args()
    
    # Validate mutually exclusive month options
    if args.month and (args.months is not None or args.all):
        parser.error("--month cannot be combined with --months or --all")
    
    # Validate --month format (single or range)
    if args.month:
        try:
            args.parsed_months = parse_month_arg(args.month)
        except ValueError as e:
            parser.error(str(e))
    
    # Normalize --site to uppercase
    if args.site:
        args.site = args.site.upper()
    
    # Validate environment and get configuration
    try:
        config = validate_environment('process_waves.log')
    except RuntimeError as e:
        print(f"Configuration error: {e}", file=sys.stderr)
        return 1
    
    # Setup logging
    setup_logging(config['log_file_path'])
    
    logging.info("=" * 70)
    logging.info("WAVE FILE PROCESSOR")
    logging.info("=" * 70)
    
    if args.dry_run:
        logging.info("DRY RUN MODE - No files will be processed")
    
    if args.retry_skipped:
        logging.info("Retrying previously skipped files")
    
    if args.site:
        logging.info(f"Filtering to site: {args.site}")
    
    # Determine which months to process
    if args.month:
        target_months = args.parsed_months
        process_all_files = True
        logging.info(f"Processing month(s): {', '.join(target_months)}")
    elif args.all:
        target_months = "all"
        process_all_files = True
        logging.info("Processing all available months and all files")
    elif args.months is not None:
        target_months = generate_month_prefixes(args.months)
        process_all_files = True
        logging.info(f"Processing {len(target_months)} months: {', '.join(target_months)}")
    else:
        target_months = get_recent_month_prefixes(DEFAULT_RECENT_HOURS)
        process_all_files = False
        logging.info(f"Processing files from the last {DEFAULT_RECENT_HOURS} hours (months: {', '.join(target_months)})")
    
    # Initialize database tracker (for file processing state)
    tracker = FileProcessingTracker(config['db_config'], 'wave_file_processing')
    if not tracker.connect():
        logging.error("Failed to connect to file processing database")
        return 1
    
    if not tracker.health_check():
        logging.error("File processing database health check failed")
        tracker.close()
        return 1
    
    logging.info("File processing database connection established and healthy")
    
    # Initialize shared acquisition database connection (for hfradar inserts)
    acq_db = DataBase()
    acq_db.connection = acq_db.init_db()
    if not acq_db.connection:
        logging.error("Failed to connect to hfradar database")
        tracker.close()
        return 1
    
    logging.info("HFRadar database connection established")
    
    # Initialize S3 clients
    try:
        s3 = boto3.client(
            "s3",
            aws_access_key_id=config['aws_key'],
            aws_secret_access_key=config['aws_secret'],
            aws_session_token=config['aws_token'],
        )
    except Exception as e:
        logging.error(f"Failed to create S3 client: {e}")
        acq_db.connection.close()
        tracker.close()
        return 1
    
    # S3 health check
    if not health_check_s3(s3, config['bucket']):
        logging.error("S3 health check failed")
        acq_db.connection.close()
        tracker.close()
        return 1
    
    # Shared s3fs connection for acquisition file reads
    try:
        s3_filesystem = s3fs.S3FileSystem(anon=False)
    except Exception as e:
        logging.error(f"Failed to create s3fs filesystem: {e}")
        acq_db.connection.close()
        tracker.close()
        return 1
    
    # Initialize statistics
    stats = ProcessingStats()
    
    # Process files
    prefix_root = "waves/"
    paginator = s3.get_paginator("list_objects_v2")
    
    try:
        pages = paginator.paginate(Bucket=config['bucket'], Prefix=prefix_root, Delimiter="/")
    except ClientError as e:
        logging.error(f"Error listing S3 bucket {config['bucket']}/{prefix_root}: {e}")
        tracker.close()
        return 1
    
    logging.info(f"Starting file discovery in s3://{config['bucket']}/{prefix_root}")
    
    try:
        for page in pages:
            for site in page.get("CommonPrefixes", []):
                site_prefix = site["Prefix"]
                site_code = extract_site_code(site_prefix)
                
                if args.site and site_code != args.site:
                    continue
                
                logging.debug(f"Processing site: {site_code}")
                
                # List year-month under this site
                try:
                    mp = s3.get_paginator("list_objects_v2").paginate(
                        Bucket=config['bucket'], Prefix=site_prefix, Delimiter="/"
                    )
                except ClientError as e:
                    logging.error(f"Error listing months for {site_prefix}: {e}")
                    continue
                
                for mpage in mp:
                    for month in mpage.get("CommonPrefixes", []):
                        month_prefix = month["Prefix"]
                        
                        # Check if this month should be processed
                        if not should_process_month(month_prefix, target_months):
                            logging.debug(f"Skipping month: {month_prefix}")
                            continue
                        
                        logging.info(f"Processing month: {month_prefix}")
                        
                        # List all files under this month
                        try:
                            fp = s3.get_paginator("list_objects_v2").paginate(
                                Bucket=config['bucket'], Prefix=month_prefix
                            )
                        except ClientError as e:
                            logging.error(f"Error listing files for {month_prefix}: {e}")
                            continue
                        
                        # Collect all files for this site/month
                        all_files = []
                        for fpage in fp:
                            for obj in fpage.get("Contents", []):
                                key = obj["Key"]
                                filename = os.path.basename(key)
                                if filename.endswith(".wls"):
                                    all_files.append(obj)
                        
                        # Determine which files to process
                        if process_all_files:
                            files_to_process = all_files
                            logging.info(f"Processing {len(files_to_process)}")
                        else:
                            # Filter files to those modified within the last N hours
                            files_to_process = filter_files_by_time(all_files, hours=DEFAULT_RECENT_HOURS)
                            logging.info(f"- Found {len(files_to_process)} files from {DEFAULT_RECENT_HOURS} hours ago.")
                        
                        # Process the selected files
                        for obj in files_to_process:
                            key = obj["Key"]
                            process_file(
                                key, obj,
                                config['bucket'],
                                tracker,
                                stats,
                                s3_filesystem,
                                acq_db,
                                dry_run=args.dry_run,
                                retry_skipped=args.retry_skipped,
                            )
    
    except KeyboardInterrupt:
        logging.warning("Processing interrupted by user")
        stats.print_summary()
        acq_db.connection.close()
        tracker.close()
        return 130
    
    except Exception as e:
        logging.error(f"Unexpected error during processing: {e}", exc_info=True)
        stats.print_summary()
        acq_db.connection.close()
        tracker.close()
        return 1
    
    # Print summary
    stats.print_summary()
    
    # Cleanup
    acq_db.connection.close()
    tracker.close()
    
    # Return non-zero exit code if there were failures
    if stats.processed_failed > 0:
        logging.warning(f"Completed with {stats.processed_failed} failures")
        return 1
    
    logging.info("Processing completed successfully")
    return 0


if __name__ == "__main__":
    sys.exit(main())

