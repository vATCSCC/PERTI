#!/usr/bin/env python3
"""
ADL Raw Data Lake - Blob Rehydration Utility

Rehydrates Archive-tier blobs to Cool or Hot tier for querying.
Archive tier blobs cannot be read directly - they must be rehydrated first.

Rehydration times:
    Standard priority: 1-15 hours
    High priority: < 1 hour (higher cost)

Usage:
    # Check status of blobs for a date range
    python rehydrate.py --status --start 2024-01-01 --end 2024-01-07

    # Rehydrate a date range (standard priority)
    python rehydrate.py --start 2024-01-01 --end 2024-01-07

    # High priority rehydration (faster, more expensive)
    python rehydrate.py --start 2024-01-01 --end 2024-01-07 --high-priority

    # Rehydrate specific callsign's data (must know dates)
    python rehydrate.py --start 2024-01-01 --end 2024-01-31 --prefix trajectory/

Author: Claude (AI-assisted implementation)
Date: 2026-02-02
"""

import argparse
import logging
import os
import sys
from datetime import datetime, timedelta
from enum import Enum
from typing import Optional

from azure.storage.blob import BlobServiceClient, RehydratePriority

# Configure logging
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s'
)
logger = logging.getLogger(__name__)

# Azure Storage connection
STORAGE_CONN_STRING = os.environ.get('ADL_ARCHIVE_STORAGE_CONN', '')
CONTAINER_NAME = 'adl-raw-archive'


class AccessTier(Enum):
    """Azure Blob access tiers."""
    HOT = "Hot"
    COOL = "Cool"
    ARCHIVE = "Archive"


class BlobRehydrator:
    """Manages rehydration of Archive-tier blobs."""

    def __init__(self, storage_conn_string: str = None):
        self.storage_conn_string = storage_conn_string or STORAGE_CONN_STRING
        self.blob_service: Optional[BlobServiceClient] = None

    def connect(self):
        """Establish connection to storage."""
        if not self.storage_conn_string:
            raise ValueError(
                "No storage connection. Set ADL_ARCHIVE_STORAGE_CONN env var"
            )
        self.blob_service = BlobServiceClient.from_connection_string(
            self.storage_conn_string
        )
        logger.info(f"Connected to Azure Blob: {CONTAINER_NAME}")

    def get_blobs_for_date_range(
        self,
        start_date: datetime.date,
        end_date: datetime.date,
        prefix: str = "trajectory/"
    ) -> list[dict]:
        """
        List blobs in the date range with their access tier.

        Returns list of dicts with: name, tier, size, rehydrate_status
        """
        container = self.blob_service.get_container_client(CONTAINER_NAME)
        blobs = []

        current = start_date
        while current <= end_date:
            date_prefix = (
                f"{prefix}year={current.year}/"
                f"month={current.month:02d}/day={current.day:02d}/"
            )

            for blob in container.list_blobs(
                name_starts_with=date_prefix,
                include=['metadata']
            ):
                blob_client = container.get_blob_client(blob.name)
                properties = blob_client.get_blob_properties()

                blobs.append({
                    'name': blob.name,
                    'tier': properties.blob_tier,
                    'size': blob.size,
                    'rehydrate_status': properties.archive_status,
                    'last_modified': blob.last_modified
                })

            current += timedelta(days=1)

        return blobs

    def get_tier_summary(self, blobs: list[dict]) -> dict:
        """Summarize blobs by access tier."""
        summary = {
            'hot': {'count': 0, 'size': 0},
            'cool': {'count': 0, 'size': 0},
            'archive': {'count': 0, 'size': 0},
            'rehydrating': {'count': 0, 'size': 0}
        }

        for blob in blobs:
            tier = blob['tier'].lower() if blob['tier'] else 'unknown'
            size = blob['size'] or 0

            if blob['rehydrate_status']:
                summary['rehydrating']['count'] += 1
                summary['rehydrating']['size'] += size
            elif tier in summary:
                summary[tier]['count'] += 1
                summary[tier]['size'] += size

        return summary

    def rehydrate_blob(
        self,
        blob_name: str,
        target_tier: AccessTier = AccessTier.COOL,
        priority: RehydratePriority = RehydratePriority.STANDARD
    ) -> bool:
        """
        Start rehydration of a single blob.

        Returns True if rehydration was started, False if already accessible.
        """
        container = self.blob_service.get_container_client(CONTAINER_NAME)
        blob_client = container.get_blob_client(blob_name)

        try:
            properties = blob_client.get_blob_properties()

            # Already accessible
            if properties.blob_tier in ['Hot', 'Cool']:
                return False

            # Already rehydrating
            if properties.archive_status:
                logger.debug(f"Already rehydrating: {blob_name}")
                return False

            # Start rehydration
            blob_client.set_standard_blob_tier(
                target_tier.value,
                rehydrate_priority=priority
            )
            return True

        except Exception as e:
            logger.error(f"Failed to rehydrate {blob_name}: {e}")
            return False

    def rehydrate_date_range(
        self,
        start_date: datetime.date,
        end_date: datetime.date,
        target_tier: AccessTier = AccessTier.COOL,
        priority: RehydratePriority = RehydratePriority.STANDARD,
        prefix: str = "trajectory/",
        dry_run: bool = False
    ) -> dict:
        """
        Rehydrate all Archive-tier blobs in a date range.

        Returns dict with: total, archive, started, skipped, errors
        """
        blobs = self.get_blobs_for_date_range(start_date, end_date, prefix)

        result = {
            'total': len(blobs),
            'archive': 0,
            'started': 0,
            'skipped': 0,
            'errors': 0
        }

        for blob in blobs:
            if blob['tier'] != 'Archive':
                result['skipped'] += 1
                continue

            if blob['rehydrate_status']:
                result['skipped'] += 1
                continue

            result['archive'] += 1

            if dry_run:
                logger.info(f"[DRY RUN] Would rehydrate: {blob['name']}")
                continue

            try:
                success = self.rehydrate_blob(
                    blob['name'],
                    target_tier=target_tier,
                    priority=priority
                )
                if success:
                    result['started'] += 1
                    logger.debug(f"Started: {blob['name']}")
                else:
                    result['skipped'] += 1
            except Exception as e:
                result['errors'] += 1
                logger.error(f"Error: {blob['name']}: {e}")

        return result


def format_size(size_bytes: int) -> str:
    """Format bytes as human-readable size."""
    for unit in ['B', 'KB', 'MB', 'GB', 'TB']:
        if size_bytes < 1024:
            return f"{size_bytes:.1f} {unit}"
        size_bytes /= 1024
    return f"{size_bytes:.1f} PB"


def main():
    parser = argparse.ArgumentParser(
        description='Rehydrate Archive-tier blobs for querying'
    )

    # Date range
    parser.add_argument(
        '--start',
        type=str,
        required=True,
        help='Start date (YYYY-MM-DD)'
    )

    parser.add_argument(
        '--end',
        type=str,
        help='End date (YYYY-MM-DD, default: same as start)'
    )

    # Options
    parser.add_argument(
        '--status',
        action='store_true',
        help='Show status only, do not rehydrate'
    )

    parser.add_argument(
        '--high-priority',
        action='store_true',
        help='Use high priority rehydration (faster, costs more)'
    )

    parser.add_argument(
        '--target-tier',
        choices=['hot', 'cool'],
        default='cool',
        help='Target tier after rehydration (default: cool)'
    )

    parser.add_argument(
        '--prefix',
        default='trajectory/',
        help='Blob prefix to filter (default: trajectory/)'
    )

    parser.add_argument(
        '--dry-run',
        action='store_true',
        help='Show what would be rehydrated without making changes'
    )

    parser.add_argument(
        '-v', '--verbose',
        action='store_true',
        help='Verbose output'
    )

    args = parser.parse_args()

    if args.verbose:
        logging.getLogger().setLevel(logging.DEBUG)

    # Parse dates
    start_date = datetime.strptime(args.start, '%Y-%m-%d').date()
    end_date = (
        datetime.strptime(args.end, '%Y-%m-%d').date()
        if args.end else start_date
    )

    # Connect
    rehydrator = BlobRehydrator()
    try:
        rehydrator.connect()
    except Exception as e:
        logger.error(f"Failed to connect: {e}")
        return 1

    # Get blobs
    logger.info(f"Scanning {start_date} to {end_date}...")
    blobs = rehydrator.get_blobs_for_date_range(start_date, end_date, args.prefix)

    if not blobs:
        print("No blobs found in date range")
        return 0

    # Show summary
    summary = rehydrator.get_tier_summary(blobs)

    print(f"\nBlob Summary ({start_date} to {end_date}):")
    print(f"  Total:       {len(blobs)} blobs")
    print(f"  Hot:         {summary['hot']['count']} ({format_size(summary['hot']['size'])})")
    print(f"  Cool:        {summary['cool']['count']} ({format_size(summary['cool']['size'])})")
    print(f"  Archive:     {summary['archive']['count']} ({format_size(summary['archive']['size'])})")
    print(f"  Rehydrating: {summary['rehydrating']['count']} ({format_size(summary['rehydrating']['size'])})")

    if args.status:
        return 0

    # Check if rehydration needed
    if summary['archive']['count'] == 0:
        print("\nNo Archive-tier blobs to rehydrate")
        return 0

    # Confirm rehydration
    target_tier = AccessTier.HOT if args.target_tier == 'hot' else AccessTier.COOL
    priority = (
        RehydratePriority.HIGH if args.high_priority
        else RehydratePriority.STANDARD
    )

    priority_str = "HIGH (< 1 hour)" if args.high_priority else "Standard (1-15 hours)"

    print(f"\nRehydration Plan:")
    print(f"  Blobs to rehydrate: {summary['archive']['count']}")
    print(f"  Target tier:        {target_tier.value}")
    print(f"  Priority:           {priority_str}")

    if args.dry_run:
        print("\n[DRY RUN] No changes will be made")

    # Execute rehydration
    print("\nStarting rehydration...")
    result = rehydrator.rehydrate_date_range(
        start_date,
        end_date,
        target_tier=target_tier,
        priority=priority,
        prefix=args.prefix,
        dry_run=args.dry_run
    )

    print(f"\nRehydration Results:")
    print(f"  Total blobs:  {result['total']}")
    print(f"  Archive tier: {result['archive']}")
    print(f"  Started:      {result['started']}")
    print(f"  Skipped:      {result['skipped']}")
    print(f"  Errors:       {result['errors']}")

    if result['started'] > 0 and not args.dry_run:
        print(f"\nRehydration started. Expected completion: {priority_str}")
        print("Run with --status to check progress")

    return 0 if result['errors'] == 0 else 1


if __name__ == '__main__':
    sys.exit(main())
