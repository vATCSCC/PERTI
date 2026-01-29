#!/usr/bin/env python3
"""Verify play names are not truncated."""
import pyodbc

SERVER = "vatsim.database.windows.net"
USERNAME = "jpeterson"
PASSWORD = "Jhp21012"

conn_str = (
    f"DRIVER={{ODBC Driver 18 for SQL Server}};"
    f"SERVER={SERVER};DATABASE=VATSIM_REF;"
    f"UID={USERNAME};PWD={PASSWORD};"
    f"Encrypt=yes;TrustServerCertificate=no;"
)
conn = pyodbc.connect(conn_str)
cursor = conn.cursor()

# Find longest play names
cursor.execute("""
    SELECT TOP 5 play_name, LEN(play_name) as len
    FROM dbo.playbook_routes
    ORDER BY LEN(play_name) DESC
""")

print("Longest play names in VATSIM_REF:")
for row in cursor.fetchall():
    print(f"  {row.len} chars: {row.play_name}")

# Check for any that look truncated (end with _old_2 without 601)
cursor.execute("""
    SELECT COUNT(*) FROM dbo.playbook_routes
    WHERE play_name LIKE '%[_]old[_]2'
""")
truncated = cursor.fetchone()[0]
print(f"\nPotentially truncated (ending with _old_2): {truncated}")

conn.close()
