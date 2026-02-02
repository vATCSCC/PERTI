#!/usr/bin/env python3
"""
ADL Raw Data Lake - Archive Query Utility

Query archived trajectory data from Parquet files in Azure Blob Storage.
Supports filtering by date range, callsign, airport pairs.

For Cool-tier data (< 365 days old):
    Direct Parquet queries via PyArrow

For Archive-tier data (> 365 days old):
    Must rehydrate first using rehydrate.py

Usage:
    # Test connectivity
    python query_archive.py --test

    # Query by date range
    python query_archive.py --start 2025-01-01 --end 2025-01-07

    # Query specific flight
    python query_archive.py --callsign DAL123 --date 2025-01-15

    # Query airport pair
    python query_archive.py --dept KJFK --dest KLAX --start 2025-01-01

    # Export to CSV
    python query_archive.py --callsign UAL456 --date 2025-01-15 -o flight.csv

Author: Claude (AI-assisted implementation)
Date: 2026-02-02
"""

import argparse
import logging
import os
import sys
from datetime import datetime, timedelta
from pathlib import Path
from typing import Optional

import pyarrow.parquet as pq
from azure.storage.blob import BlobServiceClient

# Configure logging
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s'
)
logger = logging.getLogger(__name__)

# Azure Storage connection
STORAGE_CONN_STRING = os.environ.get('ADL_ARCHIVE_STORAGE_CONN', '')
CONTAINER_NAME = 'adl-raw-archive'


class ArchiveQuery:
    """Query interface for archived Parquet data."""

    def __init__(
        self,
        storage_conn_string: str = None,
        local_path: Optional[Path] = None
    ):
        self.storage_conn_string = storage_conn_string or STORAGE_CONN_STRING
        self.local_path = local_path
        self.blob_service: Optional[BlobServiceClient] = None

    def connect(self):
        """Establish connection to storage."""
        if self.local_path:
            if not self.local_path.exists():
                raise FileNotFoundError(f"Local path not found: {self.local_path}")
            logger.info(f"Using local archive: {self.local_path}")
        elif self.storage_conn_string:
            self.blob_service = BlobServiceClient.from_connection_string(
                self.storage_conn_string
            )
            logger.info(f"Connected to Azure Blob: {CONTAINER_NAME}")
        else:
            raise ValueError(
                "No storage connection. Set ADL_ARCHIVE_STORAGE_CONN or use --local"
            )

    def test_connection(self) -> bool:
        """Test connectivity and list available date ranges."""
        try:
            if self.local_path:
                trajectory_dir = self.local_path / "trajectory"
                if not trajectory_dir.exists():
                    logger.warning("No trajectory data found locally")
                    return False

                years = list(trajectory_dir.glob("year=*"))
                if years:
                    logger.info(f"Found {len(years)} years of data")
                    for year_dir in sorted(years):
                        year = year_dir.name.replace("year=", "")
                        months = list(year_dir.glob("month=*"))
                        logger.info(f"  {year}: {len(months)} months")
                    return True
                return False

            elif self.blob_service:
                container = self.blob_service.get_container_client(CONTAINER_NAME)

                # List top-level prefixes
                blobs = list(container.walk_blobs(name_starts_with="trajectory/year="))

                if not blobs:
                    logger.warning("No trajectory data found in blob storage")
                    return False

                # Parse available years
                years = set()
                for blob in blobs:
                    parts = blob.name.split("/")
                    if len(parts) >= 2:
                        year_part = parts[1]
                        if year_part.startswith("year="):
                            years.add(year_part.replace("year=", ""))

                logger.info(f"Found data for years: {sorted(years)}")
                return True

        except Exception as e:
            logger.error(f"Connection test failed: {e}")
            return False

    def list_dates_in_range(
        self,
        start_date: datetime.date,
        end_date: datetime.date
    ) -> list[datetime.date]:
        """List dates with available data in the given range."""
        dates = []
        current = start_date

        while current <= end_date:
            prefix = (
                f"trajectory/year={current.year}/"
                f"month={current.month:02d}/day={current.day:02d}/"
            )

            if self.local_path:
                dir_path = self.local_path / prefix.rstrip("/")
                if dir_path.exists() and any(dir_path.glob("*.parquet")):
                    dates.append(current)
            elif self.blob_service:
                container = self.blob_service.get_container_client(CONTAINER_NAME)
                blobs = list(container.list_blobs(name_starts_with=prefix))
                if blobs:
                    dates.append(current)

            current += timedelta(days=1)

        return dates

    def get_parquet_files(self, date: datetime.date) -> list[str]:
        """Get list of Parquet files for a specific date."""
        prefix = (
            f"trajectory/year={date.year}/"
            f"month={date.month:02d}/day={date.day:02d}/"
        )

        if self.local_path:
            dir_path = self.local_path / prefix.rstrip("/")
            return [str(f) for f in dir_path.glob("*.parquet")]
        elif self.blob_service:
            container = self.blob_service.get_container_client(CONTAINER_NAME)
            return [blob.name for blob in container.list_blobs(name_starts_with=prefix)]

        return []

    def read_parquet_file(self, file_path: str):
        """Read a Parquet file and return as PyArrow Table."""
        if self.local_path:
            return pq.read_table(file_path)
        elif self.blob_service:
            # Download to memory and read
            import io
            container = self.blob_service.get_container_client(CONTAINER_NAME)
            blob = container.get_blob_client(file_path)
            data = blob.download_blob().readall()
            return pq.read_table(io.BytesIO(data))

    def query_date(
        self,
        date: datetime.date,
        callsign: Optional[str] = None,
        dept_icao: Optional[str] = None,
        dest_icao: Optional[str] = None,
        flight_uid: Optional[int] = None
    ):
        """
        Query trajectory data for a specific date with optional filters.

        Returns PyArrow Table with matching rows.
        """
        import pyarrow as pa
        import pyarrow.compute as pc

        files = self.get_parquet_files(date)
        if not files:
            logger.warning(f"No data found for {date}")
            return None

        logger.info(f"Reading {len(files)} file(s) for {date}")

        tables = []
        for file_path in files:
            table = self.read_parquet_file(file_path)

            # Apply filters
            mask = None

            if callsign:
                callsign_mask = pc.equal(table['callsign'], callsign.upper())
                mask = callsign_mask if mask is None else pc.and_(mask, callsign_mask)

            if dept_icao:
                dept_mask = pc.equal(table['dept_icao'], dept_icao.upper())
                mask = dept_mask if mask is None else pc.and_(mask, dept_mask)

            if dest_icao:
                dest_mask = pc.equal(table['dest_icao'], dest_icao.upper())
                mask = dest_mask if mask is None else pc.and_(mask, dest_mask)

            if flight_uid:
                uid_mask = pc.equal(table['flight_uid'], flight_uid)
                mask = uid_mask if mask is None else pc.and_(mask, uid_mask)

            if mask is not None:
                table = table.filter(mask)

            if table.num_rows > 0:
                tables.append(table)

        if not tables:
            return None

        return pa.concat_tables(tables)

    def query_range(
        self,
        start_date: datetime.date,
        end_date: datetime.date,
        callsign: Optional[str] = None,
        dept_icao: Optional[str] = None,
        dest_icao: Optional[str] = None
    ):
        """Query trajectory data across a date range."""
        import pyarrow as pa

        tables = []
        current = start_date

        while current <= end_date:
            result = self.query_date(
                current,
                callsign=callsign,
                dept_icao=dept_icao,
                dest_icao=dest_icao
            )
            if result is not None:
                tables.append(result)
            current += timedelta(days=1)

        if not tables:
            return None

        return pa.concat_tables(tables)


def main():
    parser = argparse.ArgumentParser(
        description='Query ADL archive trajectory data'
    )

    # Connection options
    parser.add_argument(
        '--local',
        type=str,
        help='Path to local archive directory'
    )

    # Actions
    parser.add_argument(
        '--test',
        action='store_true',
        help='Test connection and show available data'
    )

    # Query filters
    parser.add_argument(
        '--date',
        type=str,
        help='Query specific date (YYYY-MM-DD)'
    )

    parser.add_argument(
        '--start',
        type=str,
        help='Start date for range query'
    )

    parser.add_argument(
        '--end',
        type=str,
        help='End date for range query (default: same as start)'
    )

    parser.add_argument(
        '--callsign',
        type=str,
        help='Filter by callsign'
    )

    parser.add_argument(
        '--dept',
        type=str,
        help='Filter by departure airport (ICAO)'
    )

    parser.add_argument(
        '--dest',
        type=str,
        help='Filter by destination airport (ICAO)'
    )

    parser.add_argument(
        '--flight-uid',
        type=int,
        help='Filter by flight UID'
    )

    # Output options
    parser.add_argument(
        '-o', '--output',
        type=str,
        help='Output file (CSV or Parquet based on extension)'
    )

    parser.add_argument(
        '--limit',
        type=int,
        default=100,
        help='Limit rows displayed (default: 100, 0 = no limit)'
    )

    parser.add_argument(
        '-v', '--verbose',
        action='store_true',
        help='Verbose output'
    )

    args = parser.parse_args()

    if args.verbose:
        logging.getLogger().setLevel(logging.DEBUG)

    # Create query interface
    query = ArchiveQuery(
        local_path=Path(args.local) if args.local else None
    )

    try:
        query.connect()
    except Exception as e:
        logger.error(f"Failed to connect: {e}")
        return 1

    # Test mode
    if args.test:
        success = query.test_connection()
        return 0 if success else 1

    # Determine date range
    if args.date:
        start_date = datetime.strptime(args.date, '%Y-%m-%d').date()
        end_date = start_date
    elif args.start:
        start_date = datetime.strptime(args.start, '%Y-%m-%d').date()
        end_date = (
            datetime.strptime(args.end, '%Y-%m-%d').date()
            if args.end else start_date
        )
    else:
        logger.error("Specify --date or --start/--end for queries")
        return 1

    # Execute query
    logger.info(f"Querying {start_date} to {end_date}")

    if start_date == end_date:
        result = query.query_date(
            start_date,
            callsign=args.callsign,
            dept_icao=args.dept,
            dest_icao=args.dest,
            flight_uid=args.flight_uid
        )
    else:
        result = query.query_range(
            start_date,
            end_date,
            callsign=args.callsign,
            dept_icao=args.dept,
            dest_icao=args.dest
        )

    if result is None or result.num_rows == 0:
        print("No matching rows found")
        return 0

    print(f"\nFound {result.num_rows:,} rows")

    # Output results
    if args.output:
        output_path = Path(args.output)
        if output_path.suffix.lower() == '.csv':
            result.to_pandas().to_csv(output_path, index=False)
        elif output_path.suffix.lower() == '.parquet':
            pq.write_table(result, str(output_path))
        else:
            logger.error(f"Unknown output format: {output_path.suffix}")
            return 1

        print(f"Wrote {result.num_rows:,} rows to {output_path}")

    else:
        # Display sample
        df = result.to_pandas()

        if args.limit > 0:
            df = df.head(args.limit)

        print(f"\n{df.to_string()}")

        if result.num_rows > args.limit:
            print(f"\n... showing {args.limit} of {result.num_rows:,} rows")
            print("Use -o file.csv to export all data")

    return 0


if __name__ == '__main__':
    sys.exit(main())
