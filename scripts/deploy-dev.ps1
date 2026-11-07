# Deployt offene PRs ins lokale DEV-Environment (IIS): je PR ein Unterordner mit der PR-Nummer.
# Ausgeliefert wird der gepushte Stand des PR, nicht die Arbeitskopie. Webroot ($web, seit Issue #109 web/) und Dateiliste kommen aus scripts/deploy.ps1 dieses Stands.
# api/config.php und api/data/ im Unterordner bleiben erhalten (eigene Datenbank je PR).
# IIS wertet .htaccess nicht aus: api/data/web.config sperrt darum die Datenbank.
# Der Ordner testbase zeigt den Stand von origin/main. Seine Datenbank ist die Basis fuer neue PR-Ordner:
# Ein PR-Ordner ohne Datenbank erhaelt beim ersten Deploy eine Kopie davon (ohne Push-Abonnements), bestehende bleiben unberuehrt.
#
#   powershell -File scripts\deploy-dev.ps1 -Pr 100
#   powershell -File scripts\deploy-dev.ps1 -All        # alle offenen PRs und testbase
#   powershell -File scripts\deploy-dev.ps1 -Testbase   # nur testbase (origin/main)
param(
  [int[]]$Pr,
  [switch]$All,
  [switch]$Testbase,
  [string]$Root = 'C:\inetpub\swiftwaterdev',
  [string]$Base = 'C:\inetpub\swiftwaterdev\testbase',
  [string]$Php = 'C:\inetpub\tools\php\php.exe'
)
$ErrorActionPreference = 'Stop'
function Check($what) { if ($LASTEXITCODE -ne 0) { throw "$what fehlgeschlagen (Exit-Code $LASTEXITCODE)" } }

$deny = @'
<?xml version="1.0" encoding="UTF-8"?>
<configuration>
    <system.webServer>
        <security>
            <requestFiltering>
                <fileExtensions allowUnlisted="false" />
            </requestFiltering>
        </security>
    </system.webServer>
</configuration>
'@

if ($All) { $Pr = (gh pr list --state open --limit 100 --json number | ConvertFrom-Json).number; Check 'gh pr list' }
if (-not $Pr -and -not $Testbase -and -not $All) { throw 'Bitte -Pr <Nummer>, -All oder -Testbase angeben.' }

# Kopie ueber SQLite statt Dateikopie: enthaelt auch, was noch in der WAL-Datei liegt
$seedPhp = @'
<?php
[, $src, $dst] = $argv;
$db = new PDO('sqlite:' . $src, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec('VACUUM INTO ' . $db->quote($dst));
$db = new PDO('sqlite:' . $dst, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
try { $db->exec('DELETE FROM push_subs'); } catch (PDOException $e) {} // Test-Alarme sollen keine echten Geraete erreichen
'@

$targets = @($Pr | Where-Object { $_ })
if ($All -or $Testbase) { $targets += 'testbase' }
foreach ($n in $targets) {
  $isBase = "$n" -eq 'testbase'
  $label = if ($isBase) { '[testbase] ' } else { "[PR $n] " }
  if ($isBase) { git fetch -q origin main; Check 'git fetch main' } else { git fetch -q origin "pull/$n/head"; Check "git fetch PR $n" }
  $full = (git rev-parse FETCH_HEAD); Check 'git rev-parse'
  $sha = $full.Substring(0, 7)
  $script = git show "${full}:scripts/deploy.ps1" | Out-String
  $m = [regex]::Match($script, '\$files\s*=\s*([^\r\n]+)')
  if (-not $m.Success) { throw "PR ${n}: Dateiliste nicht gefunden" }
  $files = [regex]::Matches($m.Groups[1].Value, "'([^']+)'") | ForEach-Object { $_.Groups[1].Value }
  # Webroot im Repo (Issue #109: web/), aeltere Staende liegen im Hauptordner
  $w = [regex]::Match($script, "(?m)^\`$web\s*=\s*'([^']+)'")
  $tree = if ($w.Success) { "${full}:$($w.Groups[1].Value)" } else { $full }
  $cfgSrc = if ($w.Success) { "${full}:$($w.Groups[1].Value)/api/config.php" } else { "${full}:api/config.php" }

  $stage = Join-Path $env:TEMP "swiftwater-dev-$n-$sha"
  if (Test-Path $stage) { Remove-Item -Recurse -Force $stage }
  New-Item -ItemType Directory $stage | Out-Null
  $tar = "$stage.tar"
  git archive --format=tar -o $tar $tree @files; Check 'git archive'
  tar -xf $tar -C $stage; Check 'tar'
  Remove-Item $tar

  if (Test-Path $Php) { & $Php -l (Join-Path $stage 'api\index.php') | Out-Null; Check "PHP-Syntax PR $n" }
  # DEV behaelt die gruen umrandeten Icons aus dem Repo
  'img\test', 'img\prod' | ForEach-Object { $p = Join-Path $stage $_; if (Test-Path $p) { Remove-Item -Recurse -Force $p } }
  $enc = New-Object System.Text.UTF8Encoding($false)
  Get-ChildItem $stage -File | Where-Object { $_.Extension -eq '.html' -or $_.Name -eq 'sw.js' } | ForEach-Object {
    $t = [IO.File]::ReadAllText($_.FullName, $enc) -replace '\?v=[0-9a-z]*', "?v=$sha"
    # Kennung im Seitentitel, auch dort, wo die Seite den Titel zur Laufzeit neu setzt
    $t = $t -replace '<title>', "<title>$label" -replace 'document\.title=(?!=)', "document.title='$label'+"
    [IO.File]::WriteAllText($_.FullName, $t, $enc)
  }

  $dest = if ($isBase) { $Base } else { Join-Path $Root $n }
  # /XD braucht den Pfad in Quelle UND Ziel: mit dem Ziel allein leert /MIR den Datenordner samt Datenbank
  $data = Join-Path $dest 'api\data'
  robocopy $stage $dest /MIR /XD (Join-Path $stage 'api\data') $data /XF config.php /NFL /NDL /NJH /NJS /NP | Out-Null
  if ($LASTEXITCODE -ge 8) { throw "robocopy PR $n fehlgeschlagen (Exit-Code $LASTEXITCODE)" }
  if (-not (Test-Path $data)) { New-Item -ItemType Directory $data | Out-Null }
  $ht = Join-Path $stage 'api\data\.htaccess'
  if (Test-Path $ht) { Copy-Item $ht (Join-Path $data '.htaccess') -Force }
  [IO.File]::WriteAllText((Join-Path $data 'web.config'), $deny, $enc)
  $cfg = Join-Path $dest 'api\config.php'
  if (-not (Test-Path $cfg)) { git show $cfgSrc | Set-Content -Encoding Ascii $cfg }
  [IO.File]::WriteAllText((Join-Path $data 'deployed.txt'), "$($label.Trim()) $sha`n", $enc)
  $db = Join-Path $data 'swiftwater.sqlite'
  $baseDb = Join-Path $Base 'api\data\swiftwater.sqlite'
  $seeded = ''
  if (-not $isBase -and -not (Test-Path $db) -and (Test-Path $baseDb)) {
    $tmp = Join-Path $stage 'seed.php'
    [IO.File]::WriteAllText($tmp, $seedPhp, $enc)
    & $Php $tmp $baseDb $db; Check "Datenbankbasis PR $n"
    $seeded = ', Datenbank aus testbase'
  }
  Remove-Item -Recurse -Force $stage
  Write-Host "$($label.Trim()) ($sha) -> $dest$seeded"
}
$global:LASTEXITCODE = 0
