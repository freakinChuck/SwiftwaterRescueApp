# Deployt offene PRs ins lokale DEV-Environment (IIS): je PR ein Unterordner mit der PR-Nummer.
# Ausgeliefert wird der gepushte Stand des PR, nicht die Arbeitskopie. Die Dateiliste kommt aus scripts/deploy.ps1 dieses Stands.
# api/config.php und api/data/ im Unterordner bleiben erhalten (eigene Datenbank je PR).
# IIS wertet .htaccess nicht aus: api/data/web.config sperrt darum die Datenbank.
#
#   powershell -File scripts\deploy-dev.ps1 -Pr 100
#   powershell -File scripts\deploy-dev.ps1 -All        # alle offenen PRs
param(
  [int[]]$Pr,
  [switch]$All,
  [string]$Root = 'C:\inetpub\swiftwaterdev',
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
if (-not $Pr) { throw 'Bitte -Pr <Nummer> oder -All angeben.' }

foreach ($n in $Pr) {
  git fetch -q origin "pull/$n/head"; Check "git fetch PR $n"
  $full = (git rev-parse FETCH_HEAD); Check 'git rev-parse'
  $sha = $full.Substring(0, 7)
  $m = [regex]::Match((git show "${full}:scripts/deploy.ps1" | Out-String), '\$files\s*=\s*([^\r\n]+)')
  if (-not $m.Success) { throw "PR ${n}: Dateiliste nicht gefunden" }
  $files = [regex]::Matches($m.Groups[1].Value, "'([^']+)'") | ForEach-Object { $_.Groups[1].Value }

  $stage = Join-Path $env:TEMP "swiftwater-dev-$n-$sha"
  if (Test-Path $stage) { Remove-Item -Recurse -Force $stage }
  New-Item -ItemType Directory $stage | Out-Null
  $tar = "$stage.tar"
  git archive --format=tar -o $tar $full @files; Check 'git archive'
  tar -xf $tar -C $stage; Check 'tar'
  Remove-Item $tar

  if (Test-Path $Php) { & $Php -l (Join-Path $stage 'api\index.php') | Out-Null; Check "PHP-Syntax PR $n" }
  # DEV behaelt die gruen umrandeten Icons aus dem Repo
  'img\test', 'img\prod' | ForEach-Object { $p = Join-Path $stage $_; if (Test-Path $p) { Remove-Item -Recurse -Force $p } }
  $enc = New-Object System.Text.UTF8Encoding($false)
  Get-ChildItem $stage -File | Where-Object { $_.Extension -eq '.html' -or $_.Name -eq 'sw.js' } | ForEach-Object {
    $t = [IO.File]::ReadAllText($_.FullName, $enc) -replace '\?v=[0-9a-z]*', "?v=$sha"
    # PR-Nummer im Seitentitel, auch dort, wo die Seite den Titel zur Laufzeit neu setzt
    $t = $t -replace '<title>', "<title>[PR $n] " -replace 'document\.title=(?!=)', "document.title='[PR $n] '+"
    [IO.File]::WriteAllText($_.FullName, $t, $enc)
  }

  $dest = Join-Path $Root $n
  robocopy $stage $dest /MIR /XD (Join-Path $dest 'api\data') /XF config.php /NFL /NDL /NJH /NJS /NP | Out-Null
  if ($LASTEXITCODE -ge 8) { throw "robocopy PR $n fehlgeschlagen (Exit-Code $LASTEXITCODE)" }
  $data = Join-Path $dest 'api\data'
  if (-not (Test-Path $data)) { New-Item -ItemType Directory $data | Out-Null }
  [IO.File]::WriteAllText((Join-Path $data 'web.config'), $deny, $enc)
  $cfg = Join-Path $dest 'api\config.php'
  if (-not (Test-Path $cfg)) { git show "${full}:api/config.php" | Set-Content -Encoding Ascii $cfg }
  [IO.File]::WriteAllText((Join-Path $data 'deployed.txt'), "PR $n $sha`n", $enc)
  Remove-Item -Recurse -Force $stage
  Write-Host "PR $n ($sha) -> $dest"
}
$global:LASTEXITCODE = 0
