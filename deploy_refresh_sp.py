#!/usr/bin/env python3
"""Deploy updated sp_Adl_RefreshFromVatsim_Normalized to Azure SQL"""

import pymssql
import re

SERVER = 'vatsim.database.windows.net'
DATABASE = 'VATSIM_ADL'
USERNAME = 'jpeterson'
PASSWORD = 'Jhp21012'

SQL_FILE = r'adl\procedures\sp_Adl_RefreshFromVatsim_Normalized.sql'

def split_sql_batches(sql_content):
    """Split SQL content on GO statements"""
    batches = re.split(r'^\s*GO\s*$', sql_content, flags=re.MULTILINE | re.IGNORECASE)
    return [b.strip() for b in batches if b.strip()]

def main():
    print(f"Connecting to {SERVER}/{DATABASE}...")
    conn = pymssql.connect(server=SERVER, user=USERNAME, password=PASSWORD, database=DATABASE, tds_version='7.3')
    cursor = conn.cursor()
    print("Connected!")

    print(f"\nReading SQL file: {SQL_FILE}")
    with open(SQL_FILE, 'r', encoding='utf-8') as f:
        sql_content = f.read()

    batches = split_sql_batches(sql_content)
    print(f"Found {len(batches)} SQL batches to execute")

    for i, batch in enumerate(batches, 1):
        try:
            if not batch or (batch.startswith('--') and '\n' not in batch):
                continue
            cursor.execute(batch)
            conn.commit()
            if 'DROP PROCEDURE' in batch.upper():
                print(f"  [{i}] Dropped existing procedure")
            elif 'CREATE PROCEDURE' in batch.upper():
                print(f"  [{i}] Created procedure sp_Adl_RefreshFromVatsim_Normalized")
            elif 'PRINT' in batch.upper():
                print(f"  [{i}] Executed PRINT statement")
        except Exception as e:
            print(f"  [{i}] ERROR: {str(e)[:100]}")

    cursor.close()
    conn.close()
    print("\nDeployment complete!")

if __name__ == '__main__':
    main()
