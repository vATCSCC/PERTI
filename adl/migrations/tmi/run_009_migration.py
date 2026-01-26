#!/usr/bin/env python3
"""
Run migration 009_add_gs_flag_eligibility.sql against VATSIM_ADL

Usage:
    python run_009_migration.py

Requires: pip install pymssql
"""

import pymssql
import sys
from pathlib import Path

# Connection settings - Update these if different
SERVER = 'vatsim.database.windows.net'
DATABASE = 'VATSIM_ADL'
USER = 'jpeterson'
PASSWORD = '***REMOVED***'

def main():
    print("=" * 60)
    print("Migration 009: Add gs_flag to vw_adl_flights")
    print("=" * 60)
    
    # Read migration SQL
    sql_file = Path(__file__).parent / '009_add_gs_flag_eligibility.sql'
    if not sql_file.exists():
        print(f"ERROR: Migration file not found: {sql_file}")
        sys.exit(1)
    
    sql_content = sql_file.read_text(encoding='utf-8')
    print(f"✓ Read migration file ({len(sql_content)} bytes)")
    
    # Connect to database
    print(f"\nConnecting to {SERVER}/{DATABASE}...")
    try:
        conn = pymssql.connect(
            server=SERVER,
            user=USER,
            password=PASSWORD,
            database=DATABASE,
            login_timeout=30
        )
        cursor = conn.cursor()
        print("✓ Connected successfully")
    except Exception as e:
        print(f"✗ Connection failed: {e}")
        sys.exit(1)
    
    # Split SQL by GO statements and execute
    print("\nExecuting migration...")
    batches = []
    current_batch = []
    
    for line in sql_content.split('\n'):
        if line.strip().upper() == 'GO':
            if current_batch:
                batches.append('\n'.join(current_batch))
                current_batch = []
        else:
            current_batch.append(line)
    
    if current_batch:
        batches.append('\n'.join(current_batch))
    
    for i, batch in enumerate(batches, 1):
        # Skip empty batches
        clean = '\n'.join(l for l in batch.split('\n') 
                         if not l.strip().startswith('--') and l.strip())
        if not clean.strip():
            continue
        
        try:
            cursor.execute(batch)
            conn.commit()
            
            # Try to fetch results (for SELECT statements)
            try:
                rows = cursor.fetchall()
                if rows:
                    # Get column names
                    cols = [desc[0] for desc in cursor.description] if cursor.description else []
                    if cols:
                        print(f"\n  Batch {i}: Results")
                        print(f"    {' | '.join(cols)}")
                        print(f"    {'-' * 50}")
                        for row in rows:
                            print(f"    {' | '.join(str(v) for v in row)}")
            except:
                pass  # No results to fetch
            
            print(f"  ✓ Batch {i}/{len(batches)} executed")
        except Exception as e:
            err_str = str(e)
            if 'PRINT' in err_str or 'output' in err_str.lower():
                print(f"  ✓ Batch {i}/{len(batches)} executed (info message)")
            else:
                print(f"  ⚠ Batch {i}: {err_str[:100]}")
    
    # Verify gs_flag column
    print("\n" + "=" * 60)
    print("Verification")
    print("=" * 60)
    
    cursor.execute("""
        SELECT TOP 1 gs_flag 
        FROM dbo.vw_adl_flights
    """)
    if cursor.fetchone() is not None:
        print("✓ gs_flag column exists in view")
    
    # Show summary
    cursor.execute("""
        SELECT 
            CASE WHEN gs_flag = 1 THEN 'Eligible' ELSE 'Ineligible' END AS status,
            COUNT(*) AS cnt
        FROM dbo.vw_adl_flights
        WHERE is_active = 1
        GROUP BY gs_flag
        ORDER BY gs_flag DESC
    """)
    print("\nGS Eligibility Summary (active flights):")
    for row in cursor.fetchall():
        print(f"  {row[0]}: {row[1]} flights")
    
    conn.close()
    print("\n✓ Migration 009 complete!")
    print("=" * 60)

if __name__ == '__main__':
    main()
