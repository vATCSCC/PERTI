#!/usr/bin/env python3
"""Deploy ADL Archive System to Azure SQL"""

import pymssql
import re
import sys

# Connection parameters
SERVER = 'vatsim.database.windows.net'
DATABASE = 'VATSIM_ADL'
USERNAME = 'jpeterson'
PASSWORD = 'Jhp21012'

SQL_FILE = r'database\migrations\archive\ADL_Archive_FULL_DEPLOYMENT.sql'

def split_sql_batches(sql_content):
    """Split SQL content on GO statements"""
    # Match GO on its own line (case insensitive)
    batches = re.split(r'^\s*GO\s*$', sql_content, flags=re.MULTILINE | re.IGNORECASE)
    # Filter out empty batches
    return [b.strip() for b in batches if b.strip()]

def main():
    print(f"Connecting to {SERVER}/{DATABASE}...")

    try:
        conn = pymssql.connect(
            server=SERVER,
            user=USERNAME,
            password=PASSWORD,
            database=DATABASE,
            tds_version='7.3'
        )
        cursor = conn.cursor()
        print("Connected successfully!")

        # Read SQL file
        print(f"\nReading SQL file: {SQL_FILE}")
        with open(SQL_FILE, 'r', encoding='utf-8') as f:
            sql_content = f.read()

        # Split into batches
        batches = split_sql_batches(sql_content)
        print(f"Found {len(batches)} SQL batches to execute")

        # Execute each batch
        success_count = 0
        error_count = 0

        for i, batch in enumerate(batches, 1):
            try:
                # Skip empty or comment-only batches
                if not batch or batch.startswith('--') and '\n' not in batch:
                    continue

                cursor.execute(batch)
                conn.commit()
                success_count += 1

                # Print progress for key operations
                if 'CREATE TABLE' in batch.upper():
                    print(f"  [{i}/{len(batches)}] Created table")
                elif 'CREATE OR ALTER PROCEDURE' in batch.upper():
                    print(f"  [{i}/{len(batches)}] Created/altered procedure")
                elif 'CREATE VIEW' in batch.upper():
                    print(f"  [{i}/{len(batches)}] Created view")
                elif 'CREATE FUNCTION' in batch.upper():
                    print(f"  [{i}/{len(batches)}] Created function")
                elif 'CREATE.*INDEX' in batch.upper() or 'CREATE NONCLUSTERED INDEX' in batch.upper():
                    print(f"  [{i}/{len(batches)}] Created index")
                elif i % 10 == 0:
                    print(f"  [{i}/{len(batches)}] Executed batch")

            except pymssql.Error as e:
                error_count += 1
                error_msg = str(e)
                # Some errors are expected (like "already exists" for idempotent scripts)
                if 'already exists' in error_msg.lower():
                    print(f"  [{i}/{len(batches)}] Skipped (already exists)")
                else:
                    print(f"  [{i}/{len(batches)}] ERROR: {error_msg[:100]}")

        print(f"\n{'='*50}")
        print(f"Deployment complete!")
        print(f"  Successful batches: {success_count}")
        print(f"  Errors/skipped: {error_count}")

        # Close connection
        cursor.close()
        conn.close()

        return 0

    except pymssql.Error as e:
        print(f"Connection error: {e}")
        return 1

if __name__ == '__main__':
    sys.exit(main())
