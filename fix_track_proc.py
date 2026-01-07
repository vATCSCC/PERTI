#!/usr/bin/env python3
"""Fix sp_Get_Flight_Track type error"""

import pymssql

SERVER = 'vatsim.database.windows.net'
DATABASE = 'VATSIM_ADL'
USERNAME = 'jpeterson'
PASSWORD = 'Jhp21012'

FIX_TRACK_PROC = """
CREATE OR ALTER PROCEDURE dbo.sp_Get_Flight_Track
    @flight_uid BIGINT = NULL,
    @callsign NVARCHAR(16) = NULL,
    @simplify BIT = 0,
    @max_points INT = 1000
AS
BEGIN
    SET NOCOUNT ON;

    IF @flight_uid IS NULL AND @callsign IS NOT NULL
    BEGIN
        SELECT TOP 1 @flight_uid = flight_uid
        FROM dbo.adl_flight_core
        WHERE callsign = @callsign
        ORDER BY last_seen_utc DESC;

        IF @flight_uid IS NULL
        BEGIN
            SELECT TOP 1 @flight_uid = flight_uid
            FROM dbo.adl_flight_archive
            WHERE callsign = @callsign
            ORDER BY last_seen_utc DESC;
        END
    END

    IF @flight_uid IS NULL
    BEGIN
        SELECT 'ERROR' AS status, 'Flight not found' AS message;
        RETURN;
    END

    ;WITH AllTrajectory AS (
        SELECT
            'HOT' AS tier,
            recorded_utc AS timestamp_utc,
            lat,
            lon,
            altitude_ft,
            groundspeed_kts,
            heading_deg,
            vertical_rate_fpm,
            ROW_NUMBER() OVER (ORDER BY recorded_utc) AS rn,
            COUNT(*) OVER () AS total_count
        FROM dbo.adl_flight_trajectory
        WHERE flight_uid = @flight_uid

        UNION ALL

        SELECT
            source_tier,
            timestamp_utc,
            lat,
            lon,
            altitude_ft,
            groundspeed_kts,
            heading_deg,
            vertical_rate_fpm,
            ROW_NUMBER() OVER (ORDER BY timestamp_utc) AS rn,
            COUNT(*) OVER () AS total_count
        FROM dbo.adl_trajectory_archive
        WHERE flight_uid = @flight_uid
    )
    SELECT
        tier,
        timestamp_utc,
        lat,
        lon,
        altitude_ft,
        groundspeed_kts,
        heading_deg,
        vertical_rate_fpm
    FROM AllTrajectory
    WHERE @simplify = 0
       OR rn % CAST(CEILING(CAST(total_count AS FLOAT) / @max_points) AS BIGINT) = 1
       OR rn = 1
       OR rn = total_count
    ORDER BY timestamp_utc ASC;
END;
"""

def main():
    print(f"Connecting to {SERVER}/{DATABASE}...")
    conn = pymssql.connect(server=SERVER, user=USERNAME, password=PASSWORD, database=DATABASE, tds_version='7.3')
    cursor = conn.cursor()
    print("Connected!")

    try:
        cursor.execute(FIX_TRACK_PROC)
        conn.commit()
        print("  OK: Fixed sp_Get_Flight_Track")
    except Exception as e:
        print(f"  ERROR: {e}")

    cursor.close()
    conn.close()

if __name__ == '__main__':
    main()
