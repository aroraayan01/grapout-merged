<#
  GrapOut, merged — one address, locally.

      powershell -ExecutionPolicy Bypass -File start.ps1

  Opens one window per service so a crash is visible in its own log rather
  than swallowed, then the gateway that puts them all on one port. Give it
  about half a minute and open http://localhost:8080.

  Nothing here touches production: no remote database is contacted, and the
  legacy site runs against a stand-in that answers every query with nothing
  (see legacy-nodb.php).
#>

$ErrorActionPreference = 'Stop'
$here = Split-Path -Parent $MyInvocation.MyCommand.Path
# One folder holds the whole site: this script lives in _run inside it.
$SITE = Split-Path -Parent $here

$GRAPME = Join-Path $SITE 'crm'
$TRADE_API = Join-Path $SITE 'trade/apibase'
$PHP = 'C:\php84\php.exe'

function Free-Port($p) {
  $c = Get-NetTCPConnection -LocalPort $p -State Listen -ErrorAction SilentlyContinue
  if ($c) { Stop-Process -Id $c[0].OwningProcess -Force -ErrorAction SilentlyContinue }
}

function Wait-Port($p, $name, $seconds = 120) {
  $deadline = (Get-Date).AddSeconds($seconds)
  while ((Get-Date) -lt $deadline) {
    if (Get-NetTCPConnection -LocalPort $p -State Listen -ErrorAction SilentlyContinue) {
      Write-Host "    $name ready on $p" -ForegroundColor Green
      return $true
    }
    Start-Sleep -Milliseconds 1000
  }
  Write-Host "    $name did NOT come up on $p" -ForegroundColor Red
  return $false
}

# Out of sight, with its output in _run/logs. Seven console windows for one
# website is not a preview, it is a mess; when something misbehaves the log is
# a better place to look than a window that has scrolled past anyway.
$LOGS = Join-Path $here 'logs'
New-Item -ItemType Directory -Force -Path $LOGS | Out-Null

function Launch($title, $command, $workdir) {
  $log = Join-Path $LOGS (($title -replace '[^A-Za-z0-9]+', '-').Trim('-') + '.log')
  Start-Process powershell -WindowStyle Hidden -ArgumentList @(
    '-NoProfile', '-Command',
    "Set-Location '$workdir'; $command *> '$log'"
  ) | Out-Null
}

Write-Host "`n  GrapOut, merged — starting`n" -ForegroundColor Cyan

Write-Host "  [0/5] clearing the ports"
foreach ($p in 8080, 8081, 8000, 8001, 8002, 3000, 4000) { Free-Port $p }
# Postgres and Redis are left alone if they are already up: they hold the CRM's
# data, and restarting them costs a minute for no reason.

Write-Host "  [1/5] the legacy site        (:8081)"
Launch 'GrapOut — legacy site' `
  "& '$PHP' -d auto_prepend_file='$here\legacy-nodb.php' -S 127.0.0.1:8081 -t '$SITE' '$here\legacy-router.php'" $here

Write-Host "  [2/5] GrapOut 2.0 backend    (:8000-8002)"
# Three copies, because PHP's built-in server answers one request at a time and
# Windows has no worker setting for it. The gateway sends work round them in
# turn; one slow query then stops holding up the whole screen.
foreach ($port in 8000, 8001, 8002) {
  Launch "GrapOut — Trade API :$port" `
    "`$env:OPENSSL_CONF='C:\php84\extras\ssl\openssl.cnf'; `$env:APP_URL_PREFIX='/trade'; & '$PHP' artisan serve --host=127.0.0.1 --port=$port" $TRADE_API
}

Write-Host "  [3/5] the CRM's database     (:5432, :6379)"
if (-not (Get-NetTCPConnection -LocalPort 5432 -State Listen -ErrorAction SilentlyContinue)) {
  Launch 'GrapOut — CRM database' 'npm run db:embedded' $GRAPME
}
if (-not (Get-NetTCPConnection -LocalPort 6379 -State Listen -ErrorAction SilentlyContinue)) {
  Launch 'GrapOut — CRM redis' '.\redis-server.exe' (Join-Path $GRAPME '.redis')
}
Wait-Port 5432 'database' | Out-Null
Wait-Port 6379 'redis' | Out-Null

# Postgres opens its port before it will answer a query — a hard stop leaves it
# replaying the write-ahead log for a few seconds. Starting the API into that
# window kills it on boot, so wait for Postgres to say it is ready.
$dblog = Join-Path $LOGS 'GrapOut-CRM-database.log'
$deadline = (Get-Date).AddSeconds(90)
while ((Get-Date) -lt $deadline) {
  if ((Test-Path $dblog) -and (Select-String -Path $dblog -Pattern 'ready to accept connections' -Quiet)) {
    Write-Host '    database accepting queries' -ForegroundColor Green
    break
  }
  Start-Sleep -Milliseconds 1000
}

Write-Host "  [4/5] the CRM                (:4000 api, :3000 web)"
Launch 'GrapOut — CRM API' `
  "`$env:QUEUE_ENABLED='true'; `$env:WEB_PUBLIC_URL='http://localhost:8080/crm'; npm run dev:api" $GRAPME
Launch 'GrapOut — CRM web' `
  "`$env:NEXT_PUBLIC_BASE_PATH='/crm'; `$env:NEXT_PUBLIC_API_URL='http://localhost:8080/crm/api/v1'; npm run dev:web" $GRAPME

Wait-Port 8081 'legacy site' | Out-Null
Wait-Port 8000 'Trade API' | Out-Null
Wait-Port 4000 'CRM API' | Out-Null
Wait-Port 3000 'CRM web' | Out-Null

Write-Host "  [5/5] the gateway            (:8080)"
Launch 'GrapOut — gateway' 'node gateway.mjs' $here
Wait-Port 8080 'gateway' | Out-Null

Write-Host ""
Write-Host "  Open  http://localhost:8080" -ForegroundColor Cyan
Write-Host ""
Write-Host "    /         the site"
Write-Host "    /trade    GrapOut 2.0 — sign in with your local account"
Write-Host "    /crm      the CRM      — admin@grapme.local"
Write-Host ""
Write-Host "  Logs:  _run\logs"
Write-Host "  Stop everything with  _run\stop.ps1"
Write-Host ""
