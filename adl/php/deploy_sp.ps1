$conn = New-Object System.Data.SqlClient.SqlConnection("Server=tcp:vatsim.database.windows.net,1433;Database=VATSIM_ADL;User ID=jpeterson;Password=Jhp21012;Encrypt=True;TrustServerCertificate=False;")
$conn.Open()
$sql = Get-Content "c:\Users\jerem.DESKTOP-T926IG8\OneDrive - Virtual Air Traffic Control System Command Center\Documents - Virtual Air Traffic Control System Command Center\VATSIM PERTI\PERTI\adl\procedures\sp_ParseRoute.sql" -Raw
$batches = $sql -split '\nGO\s*\n|\nGO\s*$|^GO\s*\n'
$count = 0
foreach ($batch in $batches) {
    $batch = $batch.Trim()
    if ($batch.Length -gt 0) {
        $cmd = $conn.CreateCommand()
        $cmd.CommandTimeout = 120
        $cmd.CommandText = $batch
        try {
            $cmd.ExecuteNonQuery() | Out-Null
            $count++
        } catch {
            Write-Host "Error in batch $count`: $_" -ForegroundColor Red
        }
    }
}
$conn.Close()
Write-Host "Deployed $count batches successfully" -ForegroundColor Green
