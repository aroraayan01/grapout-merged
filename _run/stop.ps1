<#
  Stop everything start.ps1 started.

      powershell -ExecutionPolicy Bypass -File stop.ps1

  The database and Redis are stopped too, but their files stay where they are —
  the CRM's local data is in grapme\apps\api\.pgdata and survives this.
#>

$ports = @{
  8080 = 'gateway'
  8081 = 'legacy site'
  8000 = 'Trade API'
  8001 = 'Trade API 2'
  8002 = 'Trade API 3'
  3000 = 'CRM web'
  4000 = 'CRM API'
  6379 = 'redis'
  5432 = 'database'
}

foreach ($p in $ports.Keys | Sort-Object) {
  $c = Get-NetTCPConnection -LocalPort $p -State Listen -ErrorAction SilentlyContinue
  if ($c) {
    Stop-Process -Id $c[0].OwningProcess -Force -ErrorAction SilentlyContinue
    Write-Host ("  stopped {0,-12} (:{1})" -f $ports[$p], $p)
  } else {
    Write-Host ("  {0,-12} was not running" -f $ports[$p])
  }
}

# An embedded Postgres that was killed mid-flight leaves a lock behind that
# stops the next start; clearing it here saves a confusing failure tomorrow.
$pid_file = Join-Path (Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)) 'crm/apps/api/.pgdata/postmaster.pid'
if (Test-Path $pid_file) { Remove-Item $pid_file -Force -ErrorAction SilentlyContinue }

Write-Host ""
