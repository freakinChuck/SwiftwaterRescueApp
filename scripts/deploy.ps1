# Deployt einen Git-Stand der Web-App per SSH auf einen Server (z. B. Testsystem bei cyon).
# Verbindung steht in scripts/deploy.local.json (nicht im Git, Vorlage: scripts/deploy.example.json), Anmeldung nur per SSH-Schlüssel.
# Konfigurationen im Repo-Hauptordner (Ablage vor Issue #109) werden weiterhin gefunden.
# Ausgeliefert wird ein eingecheckter Stand (Standard: origin/main), nicht die Arbeitskopie.
# Ausgeliefert wird der Inhalt des Ordners web/ (Issue #109): Er liegt auf dem Server direkt im Zielordner, die URLs bleiben gleich.
# App-Icons je System: im Repo die grün umrandeten (DEV), Test erhält img/test/ (orange), Prod img/prod/ (ohne Rand).
# Das System steht in der Konfiguration unter "env" (dev|test|prod), sonst prod, wenn der Dateiname "prod" enthält, sonst test.
# api/config.php und die Datenbank in api/data/ auf dem Server bleiben unangetastet.
#
#   powershell -File scripts\deploy.ps1                      # origin/main
#   powershell -File scripts\deploy.ps1 -Ref origin/feature/x
param(
  [string]$Ref = 'origin/main',
  [string]$Config = (Join-Path $PSScriptRoot 'deploy.local.json')
)
$ErrorActionPreference = 'Stop'
function Check($what) { if ($LASTEXITCODE -ne 0) { throw "$what fehlgeschlagen (Exit-Code $LASTEXITCODE)" } }
# Version eines Stands (Issue #175): letzter Tag vX.Y.Z davor, ohne Tag die Datei VERSION. Release = genau auf dem Tag (0.1.0), sonst mit Abstand und Commit (0.1.0+12.abc1234).
# deploy-dev.ps1 hat dieselbe Funktion.
function Get-SwVersion($ref, $sha) {
  $ErrorActionPreference = 'Continue'
  $d = git describe --tags --match 'v[0-9]*' --long $ref 2>$null
  if ($LASTEXITCODE -eq 0 -and "$d" -match '^v(\d+\.\d+\.\d+)-(\d+)-g[0-9a-f]+$') { $v = $Matches[1]; $n = [int]$Matches[2] }
  else { $v = ((git show "${ref}:VERSION" 2>$null) | Out-String).Trim(); $n = -1; if ($v -notmatch '^\d+\.\d+\.\d+$') { $v = '0.0.0' } }
  $global:LASTEXITCODE = 0
  $full = if ($n -eq 0) { $v } elseif ($n -gt 0) { "$v+$n.$sha" } else { "$v+$sha" }
  return @{ version = $v; full = $full }
}

# Rückfall: Konfiguration im Repo-Hauptordner (vor Issue #109) bzw. im Ordner scripts/
if (-not (Test-Path $Config)) {
  $leaf = Split-Path $Config -Leaf
  $alt = @((Join-Path $PSScriptRoot "..\$leaf"), (Join-Path $PSScriptRoot $leaf)) | Where-Object { Test-Path $_ } | Select-Object -First 1
  if ($alt) { $Config = $alt } else { throw "Konfiguration $Config nicht gefunden (Vorlage: scripts\deploy.example.json)" }
}
$c = Get-Content $Config -Raw | ConvertFrom-Json
# Webroot im Repo, die Dateiliste ist relativ dazu. deploy-dev.ps1 und deploy.ps1 selbst lesen beides aus dem deploy.ps1 des ausgelieferten Stands;
# diese Werte gelten nur als Rückfall, wenn der Stand kein solches Skript hat. Format beibehalten (eine Zeile je Variable, Einträge in '…').
$web = 'web'
$files ='index.html', 'admin.html', 'public.html', 'funk.html', 'dok.html', 'legal.html', 'hilfe.html', 'hilfe', 'common.js', 'demo.js', 'common.css', 'voice.js', 'sos.js', 'cc.js', 'docs.js', 'i18n.js', 'i18n', '.htaccess', 'manifest.webmanifest', 'sw.js', 'changes.json', 'img', 'api/index.php', 'api/fed.php', 'api/docs.php', 'api/concepts.php', 'api/data/.htaccess'
$opt = @('-i', $c.key, '-o', 'IdentitiesOnly=yes', '-o', 'BatchMode=yes')
$dest = "$($c.user)@$($c.host)"
$envName = if ($c.env) { $c.env } elseif ((Split-Path $Config -Leaf) -match "prod") { "prod" } else { "test" }
if ($envName -notin "dev", "test", "prod") { throw "Unbekanntes System in env: $envName" }

git fetch -q origin; Check 'git fetch'
$sha = (git rev-parse --short $Ref); Check "Stand $Ref"

# Webroot und Dateiliste aus dem deploy.ps1 des ausgelieferten Stands, nicht aus der Arbeitskopie (wie deploy-dev.ps1).
# Vorfall vom 22.11.2026: Mit einer älteren Arbeitskopie fehlten in v1.4.0 docs.js, dok.html und api/docs.php, alle Routen lieferten 500.
# Ältere Stände: deploy.ps1 im Hauptordner (vor Issue #109), dort ohne $web (Webroot = Hauptordner). Ohne Skript im Stand gilt die Liste oben.
function Get-RefText($spec) {
  $ErrorActionPreference = 'Continue'
  $t = git show $spec 2>$null | Out-String
  $ok = $LASTEXITCODE -eq 0
  $global:LASTEXITCODE = 0
  if ($ok) { return $t } else { return $null }
}
$refScript = Get-RefText "${Ref}:scripts/deploy.ps1"
if (-not $refScript) { $refScript = Get-RefText "${Ref}:deploy.ps1" }
$m = if ($refScript) { [regex]::Match($refScript, '\$files\s*=\s*([^\r\n]+)') } else { $null }
if ($m -and $m.Success) {
  $files = @([regex]::Matches($m.Groups[1].Value, "'([^']+)'") | ForEach-Object { $_.Groups[1].Value })
  $w = [regex]::Match($refScript, "(?m)^\`$web\s*=\s*'([^']+)'")
  $web = if ($w.Success) { $w.Groups[1].Value } else { '' }
  Write-Host "Dateiliste aus deploy.ps1 von $Ref ($($files.Count) Eintraege)"
} else {
  Write-Warning "Kein deploy.ps1 mit Dateiliste in $Ref, verwende die Liste der Arbeitskopie"
}
$tree = if ($web) { "${Ref}:$web" } else { $Ref }
$src = if ($web) { "${Ref}:$web/" } else { "${Ref}:" }

$tar = Join-Path $env:TEMP "swiftwater-deploy-$sha.tar"
git archive --format=tar -o $tar $tree @files; Check 'git archive'

# Sicherheitsprüfung: Jede per require/require_once eingebundene PHP-Datei der API muss im Archiv sein, sonst bricht die API ab.
# Geprüft wird ab api/index.php des Stands, auch Einbindungen in eingebundenen Dateien. config.php bleibt auf dem Server und fehlt absichtlich.
$inTar = @{}
tar -tf $tar | ForEach-Object { $inTar[($_ -replace '^\./', '')] = $true }; Check 'tar -tf'
$todo = New-Object System.Collections.Queue
$todo.Enqueue('api/index.php')
$seen = @{}
$missing = @()
while ($todo.Count) {
  $p = $todo.Dequeue()
  if ($seen[$p]) { continue }
  $seen[$p] = $true
  if (-not $inTar[$p]) { $missing += $p; continue }
  $code = Get-RefText "$src$p"
  if (-not $code) { continue }
  $dir = ($p -replace '[^/]*$', '')
  foreach ($r in [regex]::Matches($code, "(?m)^[^/#\r\n]*?\b(?:require|include)(?:_once)?\s*\(?\s*__DIR__\s*\.\s*'/([^']+)'")) {
    $t = "$dir$($r.Groups[1].Value)"
    if ($t -ne 'api/config.php') { $todo.Enqueue($t) }
  }
}
if ($missing.Count) {
  Remove-Item $tar
  throw "Abbruch: Im Archiv fehlen eingebundene Dateien: $($missing -join ', '). Dateiliste `$files in scripts/deploy.ps1 von $Ref ergaenzen."
}
# api/data/version.json (Issue #175): angezeigte Version, Commit (= ?v= der Seiten) und System; liest die API (verInfo)
$ver = Get-SwVersion $Ref $sha
$vj = Join-Path $env:TEMP "swiftwater-version-$sha.json"
[IO.File]::WriteAllText($vj, (@{ version = $ver.version; full = $ver.full; commit = "$sha"; env = $envName; ref = $Ref; at = [DateTimeOffset]::UtcNow.ToUnixTimeSeconds() } | ConvertTo-Json -Compress), (New-Object System.Text.UTF8Encoding($false)))

# Auf dem Server zuerst in einen Zwischenordner entpacken und die PHP-Syntax prüfen, erst dann übernehmen.
# common.js/common.css werden in den HTML-Seiten mit ?v=<Stand> eingebunden, damit Browser nach jedem Deployment die neuen Dateien laden. In sw.js ebenso, damit der Service Worker (Issue #67) sich mit jedem Deployment erneuert.
$stage = ".swiftwater-deploy-$sha"
Write-Host "Deploye $Ref ($sha, Version $($ver.full)) nach $envName, ${dest}:$($c.path)"
scp @opt $tar "${dest}:$stage.tar"; Check 'Upload'
scp @opt $vj "${dest}:$stage.json"; Check 'Upload Version'
ssh @opt $dest "set -e; rm -rf $stage; mkdir $stage; tar -xf $stage.tar -C $stage; rm $stage.tar; php -l $stage/api/index.php; if [ $envName != dev ]; then cp $stage/img/$envName/* $stage/img/; fi; rm -rf $stage/img/test $stage/img/prod; sed -i 's/?v=[0-9a-z]*/?v=$sha/g' $stage/*.html $stage/sw.js; cp -r $stage/. $($c.path)/; rm -rf $stage; mv $stage.json $($c.path)/api/data/version.json; echo '$Ref $sha' > $($c.path)/api/data/deployed.txt; echo Fertig."
Check 'Installation auf dem Server'
Remove-Item $tar, $vj
