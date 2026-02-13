# ADL Archive System - Deployment Instructions for Claude in VS Code

## TASK
Deploy the ADL Archive System to the VATSIM_ADL Azure SQL database.

## CONNECTION DETAILS
```
Server: vatsim.database.windows.net
Database: VATSIM_ADL
Username: adl_api_user
Password: <PASSWORD>
Port: 1433
```

## FILES IN THIS FOLDER
- `ADL_Archive_FULL_DEPLOYMENT.sql` - The complete deployment script (run this)
- `DEPLOYMENT_INSTRUCTIONS.md` - This file

## DEPLOYMENT STEPS

### Step 1: Install pymssql
```bash
pip install pymssql
```

### Step 2: Create and run deployment script
Create a Python file called `deploy.py` with this content:

```python
import pymssql
import re

server = 'vatsim.database.windows.net'
database = 'VATSIM_ADL'
username = 'adl_api_user'
password = '<PASSWORD>'

# Read the SQL file
with open('ADL_Archive_FULL_DEPLOYMENT.sql', 'r', encoding='utf-8') as f:
    sql_content = f.read()

# Split on GO statements
batches = re.split(r'\nGO\s*\n|\nGO\s*$', sql_content, flags=re.IGNORECASE)
batches = [b.strip() for b in batches if b.strip() and not b.strip().startswith('--')]

print(f"Connecting to {server}/{database}...")
conn = pymssql.connect(server=server, user=username, password=password, database=database)
cursor = conn.cursor()
print("Connected!\n")

success_count = 0
error_count = 0

for i, batch in enumerate(batches):
    if not batch or batch.isspace():
        continue
    try:
        cursor.execute(batch)
        conn.commit()
        # Look for PRINT statements to show progress
        if 'PRINT' in batch:
            for line in batch.split('\n'):
                if line.strip().startswith('PRINT'):
                    msg = line.strip().replace('PRINT ', '').replace("'", "").replace(";", "")
                    if msg and not msg.startswith('--'):
                        print(f"  {msg}")
        success_count += 1
    except Exception as e:
        error_count += 1
        print(f"Batch {i+1} ERROR: {e}")
        print(f"  SQL: {batch[:100]}...")

print(f"\n{'='*50}")
print(f"Deployment complete: {success_count} batches succeeded, {error_count} errors")

# Verification
print("\n" + "="*50)
print("VERIFICATION")
print("="*50)

cursor.execute("SELECT name FROM sys.tables WHERE name LIKE 'adl_%archive%' OR name = 'adl_archive_config' OR name = 'adl_archive_log' ORDER BY name")
tables = [row[0] for row in cursor.fetchall()]
print(f"\nTables created: {tables}")

cursor.execute("SELECT name FROM sys.procedures WHERE name LIKE 'sp_%Archive%' OR name LIKE 'sp_%Trajectory%' OR name = 'sp_Get_Flight_History' OR name = 'sp_Get_Flight_Track' ORDER BY name")
procs = [row[0] for row in cursor.fetchall()]
print(f"Procedures created: {procs}")

cursor.execute("SELECT COUNT(*) FROM dbo.adl_archive_config")
config_count = cursor.fetchone()[0]
print(f"Config entries: {config_count}")

# Test trajectory logging
print("\nTesting trajectory logging...")
cursor.execute("EXEC dbo.sp_Log_Trajectory @force_log = 1")
result = cursor.fetchone()
print(f"Trajectory test: {result[0]} positions logged")

conn.close()
print("\nDone!")
```

Then run:
```bash
python deploy.py
```

### Step 3: Integrate trajectory logging into refresh SP

After deployment succeeds, modify `sp_Adl_RefreshFromVatsim_Normalized` to add trajectory logging.

Find the procedure in the database and add this line at the END (before the final `END;`):

```sql
    -- Log trajectory positions (handles its own 60-sec interval checking)
    EXEC dbo.sp_Log_Trajectory;
```

You can do this by running:
```sql
-- First get the current procedure definition, then ALTER it to add the line above
```

Or find the procedure in `PERTI/adl/procedures/sp_Adl_RefreshFromVatsim_Normalized.sql` and add the line there, then re-deploy that procedure.

## EXPECTED RESULTS

After successful deployment you should see:
- **4 tables**: `adl_archive_config`, `adl_archive_log`, `adl_flight_archive`, `adl_trajectory_archive`
- **9+ procedures**: `sp_Archive_CompletedFlights`, `sp_Archive_Trajectory_ToWarm`, etc.
- **10 config entries** with retention settings
- **Trajectory test** should log positions for all active flights

## VERIFICATION QUERIES

Run these to confirm everything works:
```sql
-- Check archive stats
SELECT * FROM dbo.v_archive_stats;

-- Check config
SELECT * FROM dbo.adl_archive_config;

-- Test trajectory logging
EXEC dbo.sp_Log_Trajectory @force_log = 1;

-- Check trajectory stats
EXEC dbo.sp_Trajectory_Stats;
```

## TROUBLESHOOTING

- **Connection refused**: Check that your IP is allowed in Azure SQL firewall rules
- **Login failed**: Verify credentials are correct
- **Object already exists**: The script uses IF NOT EXISTS, so re-running is safe
- **Trajectory logs 0 rows**: Make sure there are active flights with positions in `adl_flight_core` and `adl_flight_position`
