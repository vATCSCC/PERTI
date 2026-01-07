# Deploy ADL Archive System

## Task
Deploy the ADL Archive System to Azure SQL by running the SQL migration file in this folder.

## Database Connection
```
Server: vatsim.database.windows.net
Database: VATSIM_ADL
Username: adl_api_user
Password: ***REMOVED***
```

## Steps

1. Install pymssql: `pip install pymssql`

2. Create and run this Python script in this folder:

```python
import pymssql
import re

server = 'vatsim.database.windows.net'
database = 'VATSIM_ADL'
username = 'adl_api_user'
password = '***REMOVED***'

with open('ADL_Archive_FULL_DEPLOYMENT.sql', 'r', encoding='utf-8') as f:
    sql = f.read()

batches = re.split(r'\nGO\s*\n|\nGO\s*$', sql, flags=re.IGNORECASE)
batches = [b.strip() for b in batches if b.strip()]

print(f"Connecting to {database}...")
conn = pymssql.connect(server=server, user=username, password=password, database=database)
cursor = conn.cursor()
print("Connected!\n")

for i, batch in enumerate(batches):
    if not batch: continue
    try:
        cursor.execute(batch)
        conn.commit()
        print(f"✓ Batch {i+1}/{len(batches)}")
    except Exception as e:
        print(f"✗ Batch {i+1}: {e}")

# Verify
cursor.execute("SELECT COUNT(*) FROM dbo.adl_archive_config")
print(f"\nConfig entries: {cursor.fetchone()[0]}")
cursor.execute("EXEC dbo.sp_Log_Trajectory @force_log = 1")
print(f"Trajectory test: {cursor.fetchone()[0]} positions logged")
conn.close()
print("\nDone!")
```

3. After deployment, add this line to the END of `sp_Adl_RefreshFromVatsim_Normalized` (before final END):
```sql
EXEC dbo.sp_Log_Trajectory;
```

## Verify
```sql
SELECT * FROM dbo.v_archive_stats;
SELECT * FROM dbo.adl_archive_config;
EXEC dbo.sp_Trajectory_Stats;
```
