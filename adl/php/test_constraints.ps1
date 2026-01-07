$conn = New-Object System.Data.SqlClient.SqlConnection("Server=tcp:vatsim.database.windows.net,1433;Database=VATSIM_ADL;User ID=jpeterson;Password=***REMOVED***;Encrypt=True;TrustServerCertificate=False;")
$conn.Open()

# Check which WITTI5 variant will be matched
Write-Host "Checking STAR procedure matching order..." -ForegroundColor Yellow
$cmd = $conn.CreateCommand()
$cmd.CommandText = @"
SELECT TOP 5 computer_code, has_leg_detail,
       (SELECT COUNT(*) FROM nav_procedure_legs WHERE procedure_id = np.procedure_id) as leg_count,
       CASE WHEN computer_code LIKE 'WITTI5.RW%' THEN 0 ELSE 1 END as is_rw
FROM nav_procedures np
WHERE computer_code LIKE 'WITTI5.%' AND procedure_type = 'STAR'
ORDER BY has_leg_detail DESC,
         CASE WHEN computer_code LIKE 'WITTI5.RW%' THEN 0 ELSE 1 END,
         (SELECT COUNT(*) FROM nav_procedure_legs WHERE procedure_id = np.procedure_id) DESC
"@
$reader = $cmd.ExecuteReader()
Write-Host "computer_code       has_legs  leg_count  is_rw"
while ($reader.Read()) {
    $code = $reader["computer_code"].ToString().PadRight(20)
    $has = $reader["has_leg_detail"].ToString().PadRight(10)
    $cnt = $reader["leg_count"].ToString().PadRight(11)
    $rw = $reader["is_rw"].ToString()
    Write-Host "$code$has$cnt$rw"
}
$reader.Close()

# Check what the flight route actually is
Write-Host ""
Write-Host "Flight 4465 route:" -ForegroundColor Yellow
$cmd2 = $conn.CreateCommand()
$cmd2.CommandText = "SELECT fp.route FROM adl_flight_core fc JOIN adl_flight_plan fp ON fc.flight_uid = fp.flight_uid WHERE fc.flight_uid = 4465"
$route = $cmd2.ExecuteScalar()
Write-Host $route

# Re-parse and check waypoints
Write-Host ""
Write-Host "Re-parsing and checking waypoints..." -ForegroundColor Yellow
$cmd3 = $conn.CreateCommand()
$cmd3.CommandTimeout = 120
$cmd3.CommandText = "EXEC sp_ParseRoute @flight_uid = 4465, @debug = 0"
$cmd3.ExecuteNonQuery() | Out-Null

$cmd4 = $conn.CreateCommand()
$cmd4.CommandText = "SELECT fix_name, source, on_dp, on_star, leg_type, alt_restriction, altitude_1_ft, altitude_2_ft FROM adl_flight_waypoints WHERE flight_uid = 4465 AND (on_dp IS NOT NULL OR on_star IS NOT NULL) ORDER BY sequence_num"
$reader2 = $cmd4.ExecuteReader()
Write-Host "fix_name    source  dp      star    leg  alt  alt1    alt2"
Write-Host "----------  ------  ------  ------  ---  ---  ------  ------"
while ($reader2.Read()) {
    $fix = $reader2["fix_name"].ToString().PadRight(12)
    $src = $reader2["source"].ToString().PadRight(8)
    $dp = if ($reader2["on_dp"] -eq [DBNull]::Value) { "      " } else { $reader2["on_dp"].ToString().PadRight(8) }
    $star = if ($reader2["on_star"] -eq [DBNull]::Value) { "      " } else { $reader2["on_star"].ToString().PadRight(8) }
    $leg = if ($reader2["leg_type"] -eq [DBNull]::Value) { "   " } else { $reader2["leg_type"].ToString().PadRight(5) }
    $alt = if ($reader2["alt_restriction"] -eq [DBNull]::Value) { "   " } else { $reader2["alt_restriction"].ToString().PadRight(5) }
    $a1 = if ($reader2["altitude_1_ft"] -eq [DBNull]::Value) { "      " } else { $reader2["altitude_1_ft"].ToString().PadRight(8) }
    $a2 = if ($reader2["altitude_2_ft"] -eq [DBNull]::Value) { "      " } else { $reader2["altitude_2_ft"].ToString() }
    Write-Host "$fix$src$dp$star$leg$alt$a1$a2"
}
$reader2.Close()

$conn.Close()
