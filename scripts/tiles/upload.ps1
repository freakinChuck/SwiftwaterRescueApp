# Lädt osm.pmtiles nach <Webroot>/tiles/osm.pmtiles (Issue #106), mit der Verbindung aus der Deploy-Konfiguration (scripts/deploy.*.json: host, user, key, path).
# Erst unter neuem Namen, dann umbenannt: Geräte sehen nie eine halbe Datei. Lokales DEV (IIS): Datei nach <Ordner>\tiles\ kopieren, siehe README.
#   powershell -File scripts\tiles\upload.ps1 -File osm.pmtiles [-Config scripts\deploy.local.json]
param(
  [string]$File = 'osm.pmtiles',
  [string]$Config = (Join-Path $PSScriptRoot '..\deploy.local.json')
)
$ErrorActionPreference = 'Stop'
function Check($what) { if ($LASTEXITCODE -ne 0) { throw "$what fehlgeschlagen (Exit-Code $LASTEXITCODE)" } }
if (-not (Test-Path $File)) { throw "$File fehlt (zuerst build.sh)" }
$c = Get-Content $Config -Raw | ConvertFrom-Json
$opt = @('-i', $c.key, '-o', 'IdentitiesOnly=yes', '-o', 'BatchMode=yes')
$dest = "$($c.user)@$($c.host)"
ssh @opt $dest "mkdir -p $($c.path)/tiles"; Check 'Ordner anlegen'
scp @opt $File "${dest}:$($c.path)/tiles/.osm.pmtiles.neu"; Check 'Upload'
ssh @opt $dest "mv -f $($c.path)/tiles/.osm.pmtiles.neu $($c.path)/tiles/osm.pmtiles && ls -l $($c.path)/tiles/osm.pmtiles"; Check 'Umbenennen'
