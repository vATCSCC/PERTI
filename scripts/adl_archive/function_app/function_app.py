"""
ADL Raw Data Lake - Azure Function App

Timer-triggered function that archives yesterday's trajectory data to Parquet.
Runs daily at 04:00 UTC.

Deployment:
    func azure functionapp publish <app-name> --python

Required App Settings:
    - ADL_ARCHIVE_STORAGE_CONN: Azure Blob Storage connection string
    - ADL_DB_CONNECTION: SQL Server connection string (optional override)
"""

import logging
import azure.functions as func
import sys
from pathlib import Path

# Add parent directory to path for imports
sys.path.insert(0, str(Path(__file__).parent.parent))

from daily_archive import DailyArchiver
from datetime import datetime, timedelta

app = func.FunctionApp()


@app.timer_trigger(
    schedule="0 0 4 * * *",  # 04:00 UTC daily
    arg_name="timer",
    run_on_startup=False,
    use_monitor=True
)
def archive_trajectory_daily(timer: func.TimerRequest) -> None:
    """
    Archive previous day's trajectory data to Parquet.

    Runs at 04:00 UTC to ensure all data from the previous UTC day is available.
    Uses idempotent writes - will skip if data already exists for the date.
    """
    utc_timestamp = datetime.utcnow().isoformat()

    if timer.past_due:
        logging.warning(f"Timer trigger is past due: {utc_timestamp}")

    yesterday = datetime.utcnow().date() - timedelta(days=1)
    logging.info(f"Starting daily archive for {yesterday}")

    try:
        archiver = DailyArchiver()
        archiver.connect()

        result = archiver.archive_date(yesterday)

        if result['error']:
            logging.error(f"Archive failed: {result['error']}")
            raise Exception(result['error'])

        if result['skipped']:
            logging.info(f"Date {yesterday} already archived, skipped")
        else:
            logging.info(
                f"Archive complete: {result['rows']:,} rows "
                f"in {result['files']} files"
            )

    except Exception as e:
        logging.exception(f"Daily archive failed: {e}")
        raise

    finally:
        archiver.close()

    logging.info(f"Daily archive completed at {datetime.utcnow().isoformat()}")


@app.timer_trigger(
    schedule="0 0 5 * * 0",  # 05:00 UTC every Sunday
    arg_name="timer",
    run_on_startup=False,
    use_monitor=True
)
def archive_catchup_weekly(timer: func.TimerRequest) -> None:
    """
    Weekly catch-up job to fill any gaps from the past 7 days.

    Handles edge cases like:
    - Function was down for maintenance
    - Transient errors on a specific day
    - Manual testing/redeployment gaps
    """
    logging.info("Starting weekly catch-up archive")

    archiver = DailyArchiver()
    archiver.connect()

    try:
        today = datetime.utcnow().date()
        archived = 0
        skipped = 0
        errors = 0

        # Check last 7 days
        for days_ago in range(1, 8):
            date = today - timedelta(days=days_ago)

            result = archiver.archive_date(date)

            if result['error']:
                errors += 1
                logging.error(f"Error archiving {date}: {result['error']}")
            elif result['skipped']:
                skipped += 1
            else:
                archived += 1
                logging.info(f"Catch-up archived {date}: {result['rows']:,} rows")

        logging.info(
            f"Weekly catch-up complete: {archived} archived, "
            f"{skipped} skipped, {errors} errors"
        )

    finally:
        archiver.close()
