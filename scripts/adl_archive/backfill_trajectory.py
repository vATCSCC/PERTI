#!/usr/bin/env python3
"""
ADL Raw Data Lake - Trajectory Backfill Script

Migrates historical trajectory data from VATSIM_ADL to Parquet archive.
Includes denormalized callsign, dept_icao, dest_icao for efficient queries.

Usage:
    python backfill_trajectory.py --days 30          # Backfill last 30 days
    python backfill_trajectory.py --start 2025-01-01 # Backfill from date
    python backfill_trajectory.py --all              # Backfill all historical data
    python backfill_trajectory.py --dry-run          # Show what would be migrated

Author: Claude (AI-assisted implementation)
Date: 2026-02-02
"""

import argparse
import io
import logging
import os
import sys
from datetime import datetime, timedelta
from pathlib import Path
from typing import Generator, Optional

import pyarrow as pa
import pyarrow.parquet as pq
import pyodbc
from azure.storage.blob import BlobServiceClient
from tqdm import tqdm

# Configure logging
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s',
    handlers=[
        logging.StreamHandler(),
        logging.FileHandler(Path(__file__).parent / 'backfill.log')
    ]
)
logger = logging.getLogger(__name__)

# Database connection string
ADL_CONNECTION_STRING = (
    "Driver={ODBC Driver 18 for SQL Server};"
    "Server=vatsim.database.windows.net;"
    "Database=VATSIM_ADL;"
    "Uid=adl_api_user;"
    f"Pwd={os.environ.get('ADL_SQL_PASSWORD', '')};"
    "TrustServerCertificate=yes;"
)

# Azure Storage connection (override with environment variable)
STORAGE_CONN_STRING = os.environ.get(
    'ADL_ARCHIVE_STORAGE_CONN',
    ''  # Set via setup_infrastructure.ps1 output
)

CONTAINER_NAME = 'adl-raw-archive'

# Parquet schema with denormalized fields
TRAJECTORY_SCHEMA = pa.schema([
    ('flight_uid', pa.int64()),
    ('callsign', pa.string()),
    ('dept_icao', pa.string()),
    ('dest_icao', pa.string()),
    ('timestamp_utc', pa.timestamp('ms', tz='UTC')),
    ('lat', pa.float64()),
    ('lon', pa.float64()),
    ('altitude_ft', pa.int32()),
    ('groundspeed_kts', pa.int32()),
    ('heading_deg', pa.int32()),
    ('vertical_rate_fpm', pa.int32()),
])

# Parquet write options for optimal compression
PARQUET_OPTIONS = {
    'compression': 'zstd',
    'compression_level': 9,
    'use_dictionary': True,
    'write_statistics': True,
}

# Batch sizes
QUERY_BATCH_SIZE = 100_000  # Rows per DB fetch
PARQUET_ROW_GROUP_SIZE = 100_000  # Rows per Parquet row group


def get_db_connection() -> pyodbc.Connection:
    """Create database connection."""
    return pyodbc.connect(ADL_CONNECTION_STRING)


def get_blob_service() -> Optional[BlobServiceClient]:
    """Create Azure Blob service client."""
    if not STORAGE_CONN_STRING:
        logger.warning("No storage connection string configured")
        return None
    return BlobServiceClient.from_connection_string(STORAGE_CONN_STRING)


def get_date_range(conn: pyodbc.Connection) -> tuple[datetime, datetime]:
    """Get the date range of trajectory data in the database."""
    cursor = conn.cursor()
    cursor.execute("""
        SELECT MIN(timestamp_utc), MAX(timestamp_utc)
        FROM adl_trajectory_archive
    """)
    row = cursor.fetchone()
    if row and row[0] and row[1]:
        return row[0], row[1]
    raise ValueError("No trajectory data found in database")


def get_row_count_for_date(conn: pyodbc.Connection, date: datetime.date) -> int:
    """Get count of trajectory rows for a specific date."""
    cursor = conn.cursor()
    cursor.execute("""
        SELECT COUNT(*)
        FROM adl_trajectory_archive t
        WHERE t.timestamp_utc >= ? AND t.timestamp_utc < DATEADD(day, 1, ?)
    """, (date, date))
    return cursor.fetchone()[0]


def stream_trajectory_data(
    conn: pyodbc.Connection,
    start_date: datetime.date,
    end_date: datetime.date,
    batch_size: int = QUERY_BATCH_SIZE
) -> Generator[list[dict], None, None]:
    """
    Stream trajectory data with denormalized flight info.

    Yields batches of rows as dictionaries.
    """
    cursor = conn.cursor()

    # Query with denormalized callsign and airport codes
    # callsign from adl_flight_core, airports from adl_flight_plan
    query = """
        SELECT
            t.flight_uid,
            COALESCE(c.callsign, '') as callsign,
            COALESCE(p.fp_dept_icao, '') as dept_icao,
            COALESCE(p.fp_dest_icao, '') as dest_icao,
            t.timestamp_utc,
            t.lat,
            t.lon,
            t.altitude_ft,
            t.groundspeed_kts,
            COALESCE(t.heading_deg, 0) as heading_deg,
            COALESCE(t.vertical_rate_fpm, 0) as vertical_rate_fpm
        FROM adl_trajectory_archive t
        LEFT JOIN adl_flight_core c ON t.flight_uid = c.flight_uid
        LEFT JOIN adl_flight_plan p ON t.flight_uid = p.flight_uid
        WHERE t.timestamp_utc >= ? AND t.timestamp_utc < ?
        ORDER BY t.timestamp_utc
    """

    cursor.execute(query, (start_date, end_date))

    columns = [
        'flight_uid', 'callsign', 'dept_icao', 'dest_icao',
        'timestamp_utc', 'lat', 'lon', 'altitude_ft',
        'groundspeed_kts', 'heading_deg', 'vertical_rate_fpm'
    ]

    batch = []
    for row in cursor:
        record = dict(zip(columns, row))
        # Convert Decimal to float for lat/lon (SQL Server returns Decimal)
        if record['lat'] is not None:
            record['lat'] = float(record['lat'])
        if record['lon'] is not None:
            record['lon'] = float(record['lon'])
        batch.append(record)

        if len(batch) >= batch_size:
            yield batch
            batch = []

    if batch:
        yield batch


def write_parquet_to_blob(
    blob_service: BlobServiceClient,
    data: list[dict],
    date: datetime.date,
    part_num: int
) -> str:
    """Write data batch to Parquet file in Azure Blob Storage."""
    # Create PyArrow table
    table = pa.Table.from_pylist(data, schema=TRAJECTORY_SCHEMA)

    # Write to in-memory buffer
    buffer = io.BytesIO()
    pq.write_table(
        table,
        buffer,
        compression='zstd',
        compression_level=9,
        use_dictionary=True,
        write_statistics=True,
        row_group_size=PARQUET_ROW_GROUP_SIZE,
    )

    # Generate blob path
    blob_path = (
        f"trajectory/year={date.year}/month={date.month:02d}/"
        f"day={date.day:02d}/part-{part_num:05d}.parquet"
    )

    # Upload to blob
    buffer.seek(0)
    container = blob_service.get_container_client(CONTAINER_NAME)
    blob = container.get_blob_client(blob_path)
    blob.upload_blob(buffer.getvalue(), overwrite=True)

    return blob_path


def write_parquet_local(
    output_dir: Path,
    data: list[dict],
    date: datetime.date,
    part_num: int
) -> str:
    """Write data batch to local Parquet file (for testing)."""
    # Create PyArrow table
    table = pa.Table.from_pylist(data, schema=TRAJECTORY_SCHEMA)

    # Create directory structure
    dir_path = output_dir / f"trajectory/year={date.year}/month={date.month:02d}/day={date.day:02d}"
    dir_path.mkdir(parents=True, exist_ok=True)

    # Write Parquet file
    file_path = dir_path / f"part-{part_num:05d}.parquet"
    pq.write_table(
        table,
        str(file_path),
        compression='zstd',
        compression_level=9,
        use_dictionary=True,
        write_statistics=True,
        row_group_size=PARQUET_ROW_GROUP_SIZE,
    )

    return str(file_path)


def backfill_date(
    conn: pyodbc.Connection,
    date: datetime.date,
    blob_service: Optional[BlobServiceClient],
    output_dir: Optional[Path],
    dry_run: bool = False
) -> tuple[int, int]:
    """
    Backfill trajectory data for a single date.

    Returns (rows_processed, files_written).
    """
    row_count = get_row_count_for_date(conn, date)

    if row_count == 0:
        logger.debug(f"No data for {date}")
        return 0, 0

    if dry_run:
        logger.info(f"[DRY RUN] Would backfill {date}: {row_count:,} rows")
        return row_count, 0

    logger.info(f"Backfilling {date}: {row_count:,} rows")

    start_date = datetime.combine(date, datetime.min.time())
    end_date = start_date + timedelta(days=1)

    total_rows = 0
    part_num = 0

    for batch in stream_trajectory_data(conn, start_date, end_date):
        if blob_service:
            path = write_parquet_to_blob(blob_service, batch, date, part_num)
        elif output_dir:
            path = write_parquet_local(output_dir, batch, date, part_num)
        else:
            logger.warning("No output destination configured")
            return 0, 0

        total_rows += len(batch)
        part_num += 1
        logger.debug(f"  Wrote {path}: {len(batch):,} rows")

    logger.info(f"  Completed {date}: {total_rows:,} rows in {part_num} files")
    return total_rows, part_num


def main():
    parser = argparse.ArgumentParser(
        description='Backfill ADL trajectory data to Parquet archive'
    )

    group = parser.add_mutually_exclusive_group(required=True)
    group.add_argument(
        '--days',
        type=int,
        help='Number of days to backfill (from today)'
    )
    group.add_argument(
        '--start',
        type=str,
        help='Start date for backfill (YYYY-MM-DD)'
    )
    group.add_argument(
        '--all',
        action='store_true',
        help='Backfill all historical data'
    )

    parser.add_argument(
        '--end',
        type=str,
        help='End date for backfill (YYYY-MM-DD, default: yesterday)'
    )

    parser.add_argument(
        '--dry-run',
        action='store_true',
        help='Show what would be migrated without writing'
    )

    parser.add_argument(
        '--local',
        type=str,
        help='Write to local directory instead of Azure Blob'
    )

    parser.add_argument(
        '-v', '--verbose',
        action='store_true',
        help='Verbose output'
    )

    args = parser.parse_args()

    if args.verbose:
        logging.getLogger().setLevel(logging.DEBUG)

    # Determine date range
    yesterday = datetime.utcnow().date() - timedelta(days=1)

    if args.days:
        end_date = yesterday
        start_date = end_date - timedelta(days=args.days - 1)
    elif args.start:
        start_date = datetime.strptime(args.start, '%Y-%m-%d').date()
        end_date = datetime.strptime(args.end, '%Y-%m-%d').date() if args.end else yesterday
    else:  # --all
        conn = get_db_connection()
        try:
            min_date, max_date = get_date_range(conn)
            start_date = min_date.date()
            end_date = yesterday
        finally:
            conn.close()

    logger.info(f"Backfill range: {start_date} to {end_date}")

    # Set up output destination
    blob_service = None
    output_dir = None

    if args.local:
        output_dir = Path(args.local)
        output_dir.mkdir(parents=True, exist_ok=True)
        logger.info(f"Writing to local directory: {output_dir}")
    elif not args.dry_run:
        if not STORAGE_CONN_STRING:
            logger.error(
                "No storage connection string. Set ADL_ARCHIVE_STORAGE_CONN "
                "environment variable or use --local for local testing."
            )
            sys.exit(1)
        blob_service = get_blob_service()
        logger.info(f"Writing to Azure Blob Storage: {CONTAINER_NAME}")

    # Connect to database
    conn = get_db_connection()

    try:
        # Calculate total days
        total_days = (end_date - start_date).days + 1

        # Process each date
        total_rows = 0
        total_files = 0

        current_date = start_date
        with tqdm(total=total_days, desc="Backfilling", unit="day") as pbar:
            while current_date <= end_date:
                rows, files = backfill_date(
                    conn,
                    current_date,
                    blob_service,
                    output_dir,
                    args.dry_run
                )
                total_rows += rows
                total_files += files
                current_date += timedelta(days=1)
                pbar.update(1)

        # Summary
        logger.info("")
        logger.info("=" * 50)
        logger.info("Backfill Summary")
        logger.info("=" * 50)
        logger.info(f"Date range:   {start_date} to {end_date}")
        logger.info(f"Days:         {total_days}")
        logger.info(f"Total rows:   {total_rows:,}")
        logger.info(f"Files:        {total_files}")

        if not args.dry_run:
            # Estimate storage size (approximate)
            compressed_size_gb = (total_rows * 25) / (1024 ** 3)  # ~25 bytes/row compressed
            logger.info(f"Est. size:    {compressed_size_gb:.2f} GB")

    finally:
        conn.close()

    return 0


if __name__ == '__main__':
    sys.exit(main())
