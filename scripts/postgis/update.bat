@echo off
setlocal EnableDelayedExpansion

REM ============================
REM Swiftwater OSM Import Update
REM ============================

REM PostgreSQL
set "PGDATABASE=swiftwater_osm"
set "PGHOST=localhost"
set "PGPORT=5432"
set "PGUSER=postgres"

REM Falls nötig Passwort setzen:
REM set "PGPASSWORD=deinPasswort"

REM Programme
set "PSQL=C:\Program Files\PostgreSQL\18\bin\psql.exe"
set "OSM2PGSQL=C:\Users\Silvio\Downloads\osm2pgsql-latest-x64\osm2pgsql-bin\osm2pgsql.exe"

REM PostgreSQL DLLs für osm2pgsql verfügbar machen
set "PATH=C:\Program Files\PostgreSQL\18\bin;%PATH%"

REM Import-Konfiguration
set "URL=https://download.geofabrik.de/europe/switzerland-latest.osm.pbf"
set "WORKDIR=C:\Swiftwater\data"
set "TABLE=sw_waterways"
set "READER=swiftwater_app"
set "STAGE=sw_import"

echo.
echo ======================================
echo Swiftwater OSM Update
echo ======================================
echo.

echo PostgreSQL:
"%PSQL%" --version

echo.
echo osm2pgsql:
"%OSM2PGSQL%" --version

echo.

if not exist "%WORKDIR%" mkdir "%WORKDIR%"

echo [%date% %time%] Lade Schweiz-Daten...

curl.exe -L ^
-o "%WORKDIR%\switzerland-latest.osm.pbf" ^
"%URL%"

if errorlevel 1 (
    echo Fehler beim Download.
    exit /b 1
)

echo [%date% %time%] Initialisiere Datenbankschema...

"%PSQL%" ^
-h %PGHOST% ^
-p %PGPORT% ^
-U %PGUSER% ^
-d %PGDATABASE% ^
-c "CREATE EXTENSION IF NOT EXISTS postgis; DROP SCHEMA IF EXISTS %STAGE% CASCADE; CREATE SCHEMA %STAGE%;"

if errorlevel 1 (
    echo Fehler beim Erstellen des Schemas.
    exit /b 1
)

echo [%date% %time%] Starte osm2pgsql Import...

"%OSM2PGSQL%" ^
--create ^
--slim ^
--output=flex ^
--style="%~dp0waterways.lua" ^
--schema=%STAGE% ^
--cache=2000 ^
"%WORKDIR%\switzerland-latest.osm.pbf"

if errorlevel 1 (
    echo Fehler beim osm2pgsql Import.
    exit /b 1
)

echo [%date% %time%] Import abgeschlossen.

for /f %%i in ('
    "%PSQL%" -h %PGHOST% -p %PGPORT% -U %PGUSER% -d %PGDATABASE% -t -A -c "SELECT count(*) FROM %STAGE%.sw_waterways;"
') do set COUNT=%%i

echo.
echo Importierte Gewaesser: %COUNT%
echo.

if not defined COUNT (
    echo Konnte Anzahl Datensaetze nicht bestimmen.
    exit /b 1
)

if %COUNT% LSS 1000 (
    echo Zu wenige Gewaesser importiert.
    exit /b 1
)

echo Ersetze Produktivtabelle...

"%PSQL%" ^
-h %PGHOST% ^
-p %PGPORT% ^
-U %PGUSER% ^
-d %PGDATABASE% ^
-c "BEGIN; DROP TABLE IF EXISTS public.%TABLE%; ALTER TABLE %STAGE%.sw_waterways SET SCHEMA public; GRANT USAGE ON SCHEMA public TO %READER%; GRANT SELECT ON public.%TABLE% TO %READER%; COMMIT;"

if errorlevel 1 (
    echo Fehler beim Austausch der Tabelle.
    exit /b 1
)

echo ANALYZE ausfuehren...

"%PSQL%" ^
-h %PGHOST% ^
-p %PGPORT% ^
-U %PGUSER% ^
-d %PGDATABASE% ^
-c "ANALYZE public.%TABLE%;"

echo.
echo ======================================
echo Import erfolgreich abgeschlossen
echo ======================================
echo.
pause
