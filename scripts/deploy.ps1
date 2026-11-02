# Deployt einen Git-Stand der Web-App per SSH auf einen Server (z. B. Testsystem bei cyon).
# Verbindung steht in deploy.local.json (nicht im Git, Vorlage: deploy.example.json), Anmeldung nur per SSH-Schlüssel.
# Ausgeliefert wird ein eingecheckter Stand (Standard: origin/main), nicht die Arbeitskopie.
# api/config.php und die Datenbank in api/data/ auf dem Server bleiben unangetastet.
#
#   powershell -File scripts\deploy.ps1                      # origin/main
#   powershell -File scripts\deploy.ps1 -Ref origin/feature/x
param(
  [string]$Ref = 'origin/main',
  [string]$Config = (Join-Path $PSScriptRoot '..\deploy.local.json')
)
$ErrorActionPreference = 'Stop'
function Check($what) { if ($LASTEXITCODE -ne 0) { throw "$what fehlgeschlagen (Exit-Code $LASTEXITCODE)" } }

$c = Get-Content $Config -Raw | ConvertFrom-Json
$files = 'index.html', 'admin.html', 'common.js', 'common.css', '.htaccess', 'api/index.php', 'api/data/.htaccess'
$opt = @('-i', $c.key, '-o', 'IdentitiesOnly=yes', '-o', 'BatchMode=yes')
$dest = "$($c.user)@$($c.host)"

git fetch -q origin; Check 'git fetch'
$sha = (git rev-parse --short $Ref); Check "Stand $Ref"
$tar = Join-Path $env:TEMP "swiftwater-deploy-$sha.tar"
git archive --format=tar -o $tar $Ref @files; Check 'git archive'

# Auf dem Server zuerst in einen Zwischenordner entpacken und die PHP-Syntax prüfen, erst dann übernehmen.
# common.js/common.css werden in den HTML-Seiten mit ?v=<Stand> eingebunden, damit Browser nach jedem Deployment die neuen Dateien laden.
$stage = ".swiftwater-deploy-$sha"
Write-Host "Deploye $Ref ($sha) nach ${dest}:$($c.path)"
scp @opt $tar "${dest}:$stage.tar"; Check 'Upload'
ssh @opt $dest "set -e; rm -rf $stage; mkdir $stage; tar -xf $stage.tar -C $stage; rm $stage.tar; php -l $stage/api/index.php; sed -i 's/?v=[0-9a-z]*/?v=$sha/g' $stage/*.html; cp -r $stage/. $($c.path)/; rm -rf $stage; echo '$Ref $sha' > $($c.path)/api/data/deployed.txt; echo Fertig."
Check 'Installation auf dem Server'
Remove-Item $tar
