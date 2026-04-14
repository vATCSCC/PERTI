# Power BI Dataflow Update: VATSIM_Data → VATSIM_STATS

## Overview

Update the Power BI dataflow to write network statistics to VATSIM_STATS instead of VATSIM_Data. This enables enhanced analytics with automatic traffic tagging, time binning, and pattern detection.

---

## Connection Details

### OLD Connection (VATSIM_Data)
| Property | Value |
|----------|-------|
| Server | vatsim.database.windows.net |
| Database | VATSIM_Data |
| Table | Running_VATSIM_Data_2 |

### NEW Connection (VATSIM_STATS)
| Property | Value |
|----------|-------|
| Server | vatsim.database.windows.net |
| Database | **VATSIM_STATS** |
| Username | adl_api_user |
| Password | (same as ADL) |

---

## Option A: Call Stored Procedure (Recommended)

The stored procedure `sp_TagNetworkSnapshot` automatically calculates all time tags and traffic levels.

### Dataflow Query (M/Power Query)
```powerquery
let
    // Get current VATSIM data
    Source = Json.Document(Web.Contents("https://data.vatsim.net/v3/vatsim-data.json")),

    // Extract counts
    pilots = List.Count(Source[pilots]),
    controllers = List.Count(Source[controllers]),
    snapshot_time = DateTimeZone.UtcNow(),

    // Call stored procedure
    Result = Sql.Database("vatsim.database.windows.net", "VATSIM_STATS", [
        Query = "EXEC sp_TagNetworkSnapshot @snapshot_time='" & DateTime.ToText(snapshot_time, "yyyy-MM-dd HH:mm:ss") & "', @total_pilots=" & Number.ToText(pilots) & ", @total_controllers=" & Number.ToText(controllers)
    ])
in
    Result
```

---

## Option B: Direct Table Insert

If stored procedure calls aren't supported in your dataflow, insert directly with minimal columns:

### Target Table
`dbo.fact_network_5min`

### Required Columns (others have defaults or are computed)
| Column | Type | Source |
|--------|------|--------|
| snapshot_time | datetime2 | Current UTC timestamp |
| time_id | int | YYYYMMDDHHMM format |
| total_pilots | int | Count from VATSIM API |
| total_controllers | int | Count from VATSIM API |
| hour_of_day | tinyint | DATEPART(HOUR, snapshot_time) |
| minute_bin_15 | tinyint | (minute / 15) * 15 |
| minute_bin_30 | tinyint | (minute / 30) * 30 |
| time_of_day | varchar(10) | 'night'/'morning'/'afternoon'/'evening' |
| day_of_week | tinyint | 1=Sun...7=Sat |
| day_of_week_name | varchar(3) | 'Sun', 'Mon', etc. |
| is_weekend | bit | 1 if Sat/Sun |
| week_of_year | tinyint | DATEPART(WEEK, ...) |
| month_num | tinyint | 1-12 |
| season_code | varchar(3) | 'DJF', 'MAM', 'JJA', 'SON' |
| year_num | smallint | 2026 |

### Dataflow Query (Direct Insert)
```powerquery
let
    Source = Json.Document(Web.Contents("https://data.vatsim.net/v3/vatsim-data.json")),

    pilots = List.Count(Source[pilots]),
    controllers = List.Count(Source[controllers]),
    now = DateTimeZone.UtcNow(),
    hour = Time.Hour(DateTime.Time(now)),
    minute = Time.Minute(DateTime.Time(now)),
    month = Date.Month(DateTime.Date(now)),
    dow = Date.DayOfWeek(DateTime.Date(now), Day.Sunday) + 1,

    // Build record
    Record = [
        snapshot_time = now,
        time_id = Date.Year(now) * 100000000 + month * 1000000 + Date.Day(now) * 10000 + hour * 100 + minute,
        total_pilots = pilots,
        total_controllers = controllers,
        hour_of_day = hour,
        minute_bin_15 = Number.RoundDown(minute / 15) * 15,
        minute_bin_30 = Number.RoundDown(minute / 30) * 30,
        time_of_day = if hour < 6 then "night" else if hour < 12 then "morning" else if hour < 18 then "afternoon" else "evening",
        day_of_week = dow,
        day_of_week_name = {"Sun","Mon","Tue","Wed","Thu","Fri","Sat"}{dow-1},
        is_weekend = if dow = 1 or dow = 7 then true else false,
        week_of_year = Date.WeekOfYear(DateTime.Date(now)),
        month_num = month,
        season_code = if List.Contains({12,1,2}, month) then "DJF" else if List.Contains({3,4,5}, month) then "MAM" else if List.Contains({6,7,8}, month) then "JJA" else "SON",
        year_num = Date.Year(now),
        retention_tier = 0
    ],

    Output = Table.FromRecords({Record})
in
    Output
```

---

## Steps to Update in Power BI Service

1. **Navigate to Dataflow**
   - Go to https://app.powerbi.com
   - Open the workspace containing the VATSIM dataflow
   - Click on the dataflow (likely named "VATSIM Network Stats" or similar)

2. **Edit Data Source**
   - Click "Edit tables"
   - Find the query that writes to `Running_VATSIM_Data_2`
   - Update the database from `VATSIM_Data` to `VATSIM_STATS`

3. **Update Query**
   - Replace the existing query with Option A or B above
   - Ensure the output columns match the target table

4. **Test**
   - Run a manual refresh
   - Verify data appears in `fact_network_5min`:
   ```sql
   SELECT TOP 5 * FROM fact_network_5min ORDER BY snapshot_time DESC;
   ```

5. **Schedule**
   - Keep the 5-minute refresh schedule
   - Monitor for the first day to ensure consistency

---

## Verification Queries

### Check latest data
```sql
SELECT TOP 10
    snapshot_time,
    total_pilots,
    total_controllers,
    traffic_level,
    time_of_day
FROM fact_network_5min
ORDER BY snapshot_time DESC;
```

### Compare with old table (during parallel run)
```sql
-- Old: VATSIM_Data
SELECT TOP 1 File_Time, [#_of_Pilots], [#_of_Controllers]
FROM VATSIM_Data.dbo.Running_VATSIM_Data_2
ORDER BY File_Time DESC;

-- New: VATSIM_STATS
SELECT TOP 1 snapshot_time, total_pilots, total_controllers
FROM VATSIM_STATS.dbo.fact_network_5min
ORDER BY snapshot_time DESC;
```

---

## Rollback Plan

If issues occur, revert the dataflow query to write to `VATSIM_Data.dbo.Running_VATSIM_Data_2` temporarily while debugging.

---

## After Verification (7 days)

Once data is flowing correctly to VATSIM_STATS for 7 days:

1. Disable the old dataflow query (if separate)
2. Delete VATSIM_Data database:
   ```bash
   az sql db delete --resource-group VATSIM_RG --server vatsim --name VATSIM_Data
   ```
3. Expected savings: **$559/month**
