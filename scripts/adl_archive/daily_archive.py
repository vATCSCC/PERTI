#!/usr/bin/env python3
"""
ADL Raw Data Lake - Daily Archive Job

Archives the previous day's trajectory data from VATSIM_ADL to Parquet in Azure Blob.
Designed to run as an Azure Function with Timer trigger at 04:00 UTC daily.

Can also be run standalone:
    python daily_archive.py                    # Archive yesterday's data
    python daily_archive.py --date 2025-01-15  # Archive specific date
    python daily_archive.py --local ./output   # Write to local directory

Azure Function deployment:
    See function_app/ directory for the Azure Function wrapper.

Author: Claude (AI-assisted implementation)
Date: 2026-02-02
"""

import io
import logging
import os
import sys
from datetime import datetime, timedelta
from pathlib import Path
from typing import Optional

import pyarrow as pa
import pyarrow.parquet as pq
import pyodbc
from azure.storage.blob import BlobServiceClient

# Configure logging for Azure Functions compatibility
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

# Azure Storage connection (set via environment variable)
STORAGE_CONN_STRING = os.environ.get('ADL_ARCHIVE_STORAGE_CONN', '')

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

# Batch sizes
QUERY_BATCH_SIZE = 100_000
PARQUET_ROW_GROUP_SIZE = 100_000


class DailyArchiver:
    """Archives a single day's trajectory data to Parquet."""

    def __init__(
        self,
        db_conn_string: str = ADL_CONNECTION_STRING,
        storage_conn_string: str = None,
        local_output_dir: Optional[Path] = None
    ):
        self.db_conn_string = db_conn_string
        self.storage_conn_string = storage_conn_string or STORAGE_CONN_STRING
        self.local_output_dir = local_output_dir
        self.conn: Optional[pyodbc.Connection] = None
        self.blob_service: Optional[BlobServiceClient] = None

    def connect(self):
        """Establish database and storage connections."""
        self.conn = pyodbc.connect(self.db_conn_string)

        if self.local_output_dir:
            self.local_output_dir.mkdir(parents=True, exist_ok=True)
            logger.info(f"Writing to local directory: {self.local_output_dir}")
        elif self.storage_conn_string:
            self.blob_service = BlobServiceClient.from_connection_string(
                self.storage_conn_string
            )
            logger.info(f"Writing to Azure Blob: {CONTAINER_NAME}")
        else:
            raise ValueError(
                "No output destination. Set ADL_ARCHIVE_STORAGE_CONN or use --local"
            )

    def close(self):
        """Close connections."""
        if self.conn:
            self.conn.close()
            self.conn = None

    def get_row_count(self, date: datetime.date) -> int:
        """Get count of trajectory rows for a specific date."""
        cursor = self.conn.cursor()
        cursor.execute("""
            SELECT COUNT(*)
            FROM adl_trajectory_archive t
            WHERE t.timestamp_utc >= ? AND t.timestamp_utc < DATEADD(day, 1, ?)
        """, (date, date))
        return cursor.fetchone()[0]

    def check_already_archived(self, date: datetime.date) -> bool:
        """Check if data for this date has already been archived."""
        if self.local_output_dir:
            dir_path = (
                self.local_output_dir /
                f"trajectory/year={date.year}/month={date.month:02d}/day={date.day:02d}"
            )
            return dir_path.exists() and any(dir_path.glob("*.parquet"))

        if self.blob_service:
            container = self.blob_service.get_container_client(CONTAINER_NAME)
            prefix = (
                f"trajectory/year={date.year}/month={date.month:02d}/"
                f"day={date.day:02d}/"
            )
            blobs = list(container.list_blobs(name_starts_with=prefix))
            return len(blobs) > 0

        return False

    def stream_trajectory_data(self, date: datetime.date):
        """Stream trajectory data for a single date with denormalized fields."""
        cursor = self.conn.cursor()

        start_date = datetime.combine(date, datetime.min.time())
        end_date = start_date + timedelta(days=1)

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

            if len(batch) >= QUERY_BATCH_SIZE:
                yield batch
                batch = []

        if batch:
            yield batch

    def write_parquet_blob(self, data: list[dict], date: datetime.date, part_num: int) -> str:
        """Write data batch to Parquet file in Azure Blob Storage."""
        table = pa.Table.from_pylist(data, schema=TRAJECTORY_SCHEMA)

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

        blob_path = (
            f"trajectory/year={date.year}/month={date.month:02d}/"
            f"day={date.day:02d}/part-{part_num:05d}.parquet"
        )

        buffer.seek(0)
        container = self.blob_service.get_container_client(CONTAINER_NAME)
        blob = container.get_blob_client(blob_path)
        blob.upload_blob(buffer.getvalue(), overwrite=True)

        return blob_path

    def write_parquet_local(self, data: list[dict], date: datetime.date, part_num: int) -> str:
        """Write data batch to local Parquet file."""
        table = pa.Table.from_pylist(data, schema=TRAJECTORY_SCHEMA)

        dir_path = (
            self.local_output_dir /
            f"trajectory/year={date.year}/month={date.month:02d}/day={date.day:02d}"
        )
        dir_path.mkdir(parents=True, exist_ok=True)

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

    def archive_date(self, date: datetime.date, force: bool = False) -> dict:
        """
        Archive a single date's trajectory data.

        Returns dict with keys: date, rows, files, skipped, error
        """
        result = {
            'date': date.isoformat(),
            'rows': 0,
            'files': 0,
            'skipped': False,
            'error': None
        }

        try:
            # Check if already archived
            if not force and self.check_already_archived(date):
                logger.info(f"Date {date} already archived, skipping")
                result['skipped'] = True
                return result

            # Get row count
            row_count = self.get_row_count(date)
            if row_count == 0:
                logger.info(f"No data for {date}")
                return result

            logger.info(f"Archiving {date}: {row_count:,} rows")

            # Stream and write data
            total_rows = 0
            part_num = 0

            for batch in self.stream_trajectory_data(date):
                if self.blob_service:
                    path = self.write_parquet_blob(batch, date, part_num)
                else:
                    path = self.write_parquet_local(batch, date, part_num)

                total_rows += len(batch)
                part_num += 1
                logger.debug(f"  Wrote {path}: {len(batch):,} rows")

            result['rows'] = total_rows
            result['files'] = part_num
            logger.info(f"Completed {date}: {total_rows:,} rows in {part_num} files")

        except Exception as e:
            logger.error(f"Error archiving {date}: {e}")
            result['error'] = str(e)

        return result


def archive_yesterday(local_output: Optional[str] = None) -> dict:
    """
    Archive yesterday's data - main entry point for Azure Function.

    Returns dict with archive results.
    """
    yesterday = datetime.utcnow().date() - timedelta(days=1)

    archiver = DailyArchiver(
        local_output_dir=Path(local_output) if local_output else None
    )

    try:
        archiver.connect()
        result = archiver.archive_date(yesterday)
        return result
    finally:
        archiver.close()


def main():
    """CLI entry point."""
    import argparse

    parser = argparse.ArgumentParser(
        description='Archive trajectory data to Parquet'
    )

    parser.add_argument(
        '--date',
        type=str,
        help='Date to archive (YYYY-MM-DD, default: yesterday)'
    )

    parser.add_argument(
        '--local',
        type=str,
        help='Write to local directory instead of Azure Blob'
    )

    parser.add_argument(
        '--force',
        action='store_true',
        help='Force archive even if data already exists'
    )

    parser.add_argument(
        '-v', '--verbose',
        action='store_true',
        help='Verbose output'
    )

    args = parser.parse_args()

    # Configure logging
    logging.basicConfig(
        level=logging.DEBUG if args.verbose else logging.INFO,
        format='%(asctime)s - %(levelname)s - %(message)s'
    )

    # Determine date
    if args.date:
        date = datetime.strptime(args.date, '%Y-%m-%d').date()
    else:
        date = datetime.utcnow().date() - timedelta(days=1)

    logger.info(f"Archiving date: {date}")

    # Create archiver
    archiver = DailyArchiver(
        local_output_dir=Path(args.local) if args.local else None
    )

    try:
        archiver.connect()
        result = archiver.archive_date(date, force=args.force)

        print(f"\nArchive Result for {date}:")
        print(f"  Rows:    {result['rows']:,}")
        print(f"  Files:   {result['files']}")
        print(f"  Skipped: {result['skipped']}")

        if result['error']:
            print(f"  Error:   {result['error']}")
            return 1

    except Exception as e:
        logger.error(f"Archive failed: {e}")
        return 1
    finally:
        archiver.close()

    return 0


if __name__ == '__main__':
    sys.exit(main())
