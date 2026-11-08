<?php
// Swiftwater Rescue API – Aufruf: api/index.php?r=<route>
$cfg = require __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
ini_set('serialize_precision', '-1'); // Zahlen im JSON kurz ausgeben (47.481 statt 47.48100000000000164…), Hosting-Einstellung übersteuern
// HTTPS-Abrufe unter Windows (lokales DEV mit IIS): Zertifikate aus dem Windows-Speicher, PHP bringt dort keine Liste mit (sonst schlägt jeder Abruf fehl, Feedback zu #125)
define('CURL_CA', PHP_OS_FAMILY === 'Windows' && defined('CURLSSLOPT_NATIVE_CA') ? [CURLOPT_SSL_OPTIONS => CURLSSLOPT_NATIVE_CA] : []);
function out($d, $c = 200) { if ($c < 400 && isset(APPLOG_ROUTES[$GLOBALS['route'] ?? ''])) alogRoute($d); // Ereignis fürs Applikationslog (Issue #139)
  http_response_code($c); echo json_encode($d, JSON_UNESCAPED_UNICODE);
  if (!empty($GLOBALS['pushQ']) || !empty($GLOBALS['AFTER'])) { // Web-Push erst nach der Antwort senden (Issue #67), damit z. B. «Alarm auslösen» nicht auf die Push-Dienste wartet
    ignore_user_abort(true); if (function_exists('fastcgi_finish_request')) fastcgi_finish_request(); elseif (function_exists('litespeed_finish_request')) litespeed_finish_request(); else flush(); // cyon läuft mit LiteSpeed
    try { if (!empty($GLOBALS['pushQ'])) pushFlush(); } catch (Throwable $e) { alog('err', 'push', 'Senden fehlgeschlagen: ' . $e->getMessage(), ['file' => basename($e->getFile()), 'line' => $e->getLine()]); }
    // Nachlauf (after): Arbeiten, auf die niemand warten soll, z. B. Pegel und Abfluss beim BAFU abfragen (Issue #125)
    foreach ($GLOBALS['AFTER'] ?? [] as $f) { try { set_time_limit(60); $f(); } catch (Throwable $e) { alog('err', 'api', 'Nachlauf fehlgeschlagen: ' . $e->getMessage(), ['file' => basename($e->getFile()), 'line' => $e->getLine()]); } } }
  exit; }
function after($f) { $GLOBALS['AFTER'][] = $f; }
function fail($m, $c = 400) { out(['error' => $m], $c); }
// Unerwartete Fehler (z. B. Datenbank gesperrt) als JSON melden statt leerer 500-Antwort; offene Transaktion zurückrollen, Details ins Fehlerprotokoll
set_exception_handler(function ($e) { global $db;
  try { if ($db && $db->inTransaction()) $db->rollBack(); } catch (Throwable $x) {} // zuerst zurückrollen, sonst ginge der Log-Eintrag mit verloren
  alog('err', 'api', 'Serverfehler: ' . $e->getMessage(), ['type' => get_class($e), 'file' => basename($e->getFile()), 'line' => $e->getLine()]);
  fail('Serverfehler, bitte nochmals versuchen', 500); });

/* ---------- Technisches Applikationslog (Issue #139) ---------- */
// Ereignisse, Warnungen und Fehler in der Tabelle app_log (Verwaltung → System → «Applikationslog», Recht applog), gelöscht nach applog_days Tagen.
// alog schreibt nie einen Fehler weiter (der Log-Eintrag darf eine Anfrage nicht scheitern lassen); Warnungen und Fehler gehen zusätzlich ins error_log des Servers.
// Nie Passwörter, Schlüssel oder Inhalte von Einsätzen ins Log, nur Kennungen und technische Angaben.
const APPLOG_LVL = ['info' => 'Ereignis', 'warn' => 'Warnung', 'err' => 'Fehler'];
const APPLOG_CATS = ['api' => 'Server', 'php' => 'PHP', 'auth' => 'Anmeldung', 'user' => 'Benutzer & Rollen', 'cfg' => 'Einstellungen', 'inc' => 'Einsatz', 'anon' => 'Anonymisierung',
  'push' => 'Push', 'overpass' => 'Gewässerdaten', 'air' => 'Helikopter', 'hydro' => 'Pegel & Abfluss', 'ai' => 'AI-Assistent', 'client' => 'Browser & App'];
const APPLOG_MAX = 50; // höchstens so viele Einträge je Anfrage (z. B. Warnungen in einer Schleife)
const APPLOG_DAYS = 30; // Standard der Aufbewahrung in Tagen (Einstellung applog_days, 1–365)
// Erfolgreiche Anfragen dieser Routen sind Ereignisse (Eintrag in out(), also erst, wenn die Route durchgelaufen ist): Route => [Bereich, Text]
const APPLOG_ROUTES = ['logout' => ['auth', 'Abgemeldet'], 'me/password' => ['auth', 'Eigenes Passwort geändert'], 'me/session/remove' => ['auth', 'App-Sitzung abgemeldet'],
  'user/save' => ['user', 'Benutzer gespeichert'], 'user/password' => ['user', 'Passwort zurückgesetzt'], 'user/active' => ['user', 'Benutzer aktiviert bzw. deaktiviert'], 'user/delete' => ['user', 'Benutzer gelöscht'],
  'urole/save' => ['user', 'Benutzerrolle gespeichert'], 'urole/remove' => ['user', 'Benutzerrolle entfernt'], 'group/save' => ['user', 'Einsatzgruppe gespeichert'], 'group/delete' => ['user', 'Einsatzgruppe gelöscht'],
  'settings/save' => ['cfg', 'Einstellungen gespeichert'], 'ai/cfg/save' => ['cfg', 'AI-Assistent eingestellt'], 'anon/cfg/save' => ['cfg', 'Anonymisierung eingestellt'], 'aar/cfg/save' => ['cfg', 'After Action Review eingerichtet'],
  'kind/save' => ['cfg', 'Einsatztyp gespeichert'], 'kind/delete' => ['cfg', 'Einsatztyp gelöscht'], 'mode/save' => ['cfg', 'Einrückoption gespeichert'], 'role/save' => ['cfg', 'Spezialrolle gespeichert'],
  'incident/start' => ['inc', 'Einsatz gestartet'], 'ex/start' => ['inc', 'Vorbereitete Übung gestartet'], 'incident/end' => ['inc', 'Einsatz beendet'],
  'incident/replay/delete' => ['anon', 'Aufzeichnung gelöscht'], 'anon/apply' => ['anon', 'Anonymisierung angewendet'], 'anon/undo' => ['anon', 'Anonymisierung zurückgenommen'],
  'overpass/stats/reset' => ['overpass', 'Statistik der Gewässerdaten zurückgesetzt']];
$ALOG_N = 0;
function alog($lvl, $cat, $msg, $ctx = null) { global $db, $ALOG_N;
  $msg = mb_substr(trim((string)$msg), 0, 1000);
  if ($lvl !== 'info') error_log("[$lvl] $cat: $msg");
  if (!($db instanceof PDO) || ++$ALOG_N > APPLOG_MAX) return;
  $c = $ctx === null ? null : mb_substr((string)json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR), 0, 4000);
  try { $db->prepare("INSERT INTO app_log(at,lvl,cat,msg,ctx,user_id,route) VALUES(?,?,?,?,?,?,?)")->execute([time(), $lvl, $cat, $msg, $c, $GLOBALS['uid'] ?? null, mb_substr((string)($_GET['r'] ?? ''), 0, 60)]); }
  catch (Throwable $e) {} }
function applogDays() { $d = (int)setting('applog_days', APPLOG_DAYS); return $d >= 1 && $d <= 365 ? $d : APPLOG_DAYS; }
// Ereignis einer Route aus APPLOG_ROUTES: Kennung (id) und die Namen der übergebenen Felder, nie ihre Werte
function alogRoute($d) { global $in, $route; [$cat, $t] = APPLOG_ROUTES[$route];
  $x = []; if (isset($in['id']) && is_scalar($in['id'])) $x['id'] = (int)$in['id']; elseif (is_array($d) && isset($d['id']) && is_scalar($d['id'])) $x['id'] = (int)$d['id'];
  if ($route === 'user/active') $t = empty($in['active']) ? 'Benutzer deaktiviert' : 'Benutzer aktiviert';
  $k = array_values(array_diff(array_keys((array)$in), ['id', 'password', 'pass', 'old', 'new', 'key', 'token'])); if ($k) $x['fields'] = array_slice($k, 0, 30);
  alog('info', $cat, $t, $x ?: null); }
// PHP-Warnungen und Hinweise (mit @ unterdrückte nicht), danach wie bisher; fatale Fehler beim Beenden
set_error_handler(function ($no, $str, $file, $line) { if (!(error_reporting() & $no)) return false;
  alog(in_array($no, [E_WARNING, E_USER_WARNING, E_CORE_WARNING, E_COMPILE_WARNING, E_RECOVERABLE_ERROR, E_USER_ERROR], true) ? 'warn' : 'info', 'php', $str, ['file' => basename($file), 'line' => $line]);
  return false; });
register_shutdown_function(function () { $e = error_get_last();
  if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) alog('err', 'php', 'Abbruch: ' . $e['message'], ['file' => basename($e['file']), 'line' => $e['line']]); });

$db = new PDO($cfg['dsn'], $cfg['db_user'], $cfg['db_pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$my = str_starts_with($cfg['dsn'], 'mysql');
// SQLite im WAL-Modus: Lesende blockieren Schreibende nicht (und umgekehrt). Sonst sperrt ein langer Request (z. B. Overpass) mit offenem
// Lesezugriff die Datenbank, sobald jemand schreiben will, und alle weiteren Requests laufen nach dem Timeout in einen Serverfehler.
if (!$my) { $db->exec('PRAGMA journal_mode=WAL'); $db->exec('PRAGMA busy_timeout=10000'); }
$ai = $my ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
$tx = $my ? 'VARCHAR(190)' : 'TEXT';
foreach ([
 "CREATE TABLE IF NOT EXISTS users(id $ai, username $tx UNIQUE, name $tx, pass_hash $tx, is_admin INT DEFAULT 0, active INT DEFAULT 1, photo MEDIUMTEXT, token $tx, exercise_mode INT DEFAULT 0, must_change INT DEFAULT 0)",
 "CREATE TABLE IF NOT EXISTS roles(id $ai, name $tx, color $tx, is_lead INT DEFAULT 0, active INT DEFAULT 1, is_post INT DEFAULT 0, can_message INT DEFAULT 1)",
 // Aufzeichnung fürs Replay in der Verwaltung: record = aktueller Zustand (0 aus/beendet, 1 läuft, 2 pausiert), recorded=1 sobald Daten vorhanden sind
 // Öffentliche Ansicht ohne Login (public.html?k=<public_token>): public_token NULL = aus; public_anon=1 blendet Namen aus
 "CREATE TABLE IF NOT EXISTS incidents(id $ai, message TEXT, lat DOUBLE, lon DOUBLE, alarm_at BIGINT, ended_at BIGINT NULL, flow_ms DOUBLE NULL, created_by INT, is_exercise INT DEFAULT 0, record INT DEFAULT 0, recorded INT DEFAULT 0, public_token $tx NULL, public_anon INT DEFAULT 1, pls_at BIGINT NULL)",
 // Aufzeichnung: Standortverlauf je Einsatzkraft (user_id) bzw. markierte Standorte je Rettling (rescuee_id)
 "CREATE TABLE IF NOT EXISTS track(id $ai, incident_id INT, user_id INT NULL, rescuee_id INT NULL, ts BIGINT, lat DOUBLE, lon DOUBLE)",
 // Aufzeichnung der Luftfahrzeuge in der Relevanzzone (Issue #73): hex = Transponder-Adresse, alt in ft (barometrisch, 0 = am Boden), trk = Kurs
 "CREATE TABLE IF NOT EXISTS air_track(id $ai, incident_id INT, hex $tx, ts BIGINT, lat DOUBLE, lon DOUBLE, alt INT NULL, trk INT NULL, cs $tx NULL, reg $tx NULL, type $tx NULL, cat $tx NULL)",
 // Zeiträume, in denen aufgezeichnet wurde (stop NULL = läuft); Pausen liegen dazwischen
 "CREATE TABLE IF NOT EXISTS rec_periods(id $ai, incident_id INT, start BIGINT, stop BIGINT NULL)",
 "CREATE TABLE IF NOT EXISTS participants(id $ai, incident_id INT, user_id INT, mode $tx, role_id INT NULL, lat DOUBLE NULL, lon DOUBLE NULL, loc_at BIGINT NULL, left_at BIGINT NULL, log_lat DOUBLE NULL, log_lon DOUBLE NULL, log_at BIGINT NULL, fast_at BIGINT NULL, UNIQUE(incident_id,user_id))",
 // lat/lon: Standort der sendenden Person beim Senden; mark=1: als Markierung auf der Karte (mark_lat/mark_lon)
 "CREATE TABLE IF NOT EXISTS messages(id $ai, incident_id INT, from_user INT, text TEXT, recipients TEXT NULL, created BIGINT, lat DOUBLE NULL, lon DOUBLE NULL, mark INT DEFAULT 0, mark_lat DOUBLE NULL, mark_lon DOUBLE NULL, unmarked BIGINT NULL)",
 "CREATE TABLE IF NOT EXISTS incident_log(id $ai, incident_id INT, ts BIGINT, user_id INT NULL, kind $tx, text TEXT)",
 "CREATE TABLE IF NOT EXISTS rescuee_marks(id $ai, incident_id INT, user_id INT, lat DOUBLE, lon DOUBLE, ts BIGINT, manual INT DEFAULT 0)",
 // Einrückoptionen beim Annehmen eines Einsatzes; participants.mode verweist auf code
 // lead_rights: Option mit den Rechten der Einsatzleitung, aber ohne Spezialrolle (z. B. Koordinator KP Rück)
 // counts: zählt im Zähler am Kartenknopf «Einsatzkräfte» (Standard ja, Koordinator KP Rück nein)
 "CREATE TABLE IF NOT EXISTS modes(id $ai, code $tx UNIQUE, name $tx, share_location INT DEFAULT 1, active INT DEFAULT 1, lead_rights INT DEFAULT 0, counts INT DEFAULT 1)",
 // Einstellungen der Verwaltung als Schlüssel/Wert, z. B. msg_no_role
 "CREATE TABLE IF NOT EXISTS settings(k $tx PRIMARY KEY, v TEXT)",
 // Positionen und Bereiche der Einsatzleitung: kind point|area, pts = JSON [[lat,lon],…]; removed = Zeitpunkt des Entfernens
 "CREATE TABLE IF NOT EXISTS pois(id $ai, incident_id INT, kind $tx, name $tx, color $tx, pts TEXT, created_by INT, created BIGINT, removed BIGINT NULL)",
 // Rettlinge (vermisste Personen) eines Einsatzes: letzter markierter Standort (lat/lon, loc_at, loc_by), gerettet (rescued_at, rescued_by); removed = entfernt
// pls_lat/pls_lon/pls_at: eigener PLS mit Zeitpunkt (Issue #24), NULL = PLS des Einsatzes
 "CREATE TABLE IF NOT EXISTS rescuees(id $ai, incident_id INT, name $tx, descr TEXT, lat DOUBLE NULL, lon DOUBLE NULL, loc_at BIGINT NULL, loc_by INT NULL, rescued_at BIGINT NULL, rescued_by INT NULL, created_by INT, created BIGINT, removed BIGINT NULL, pls_lat DOUBLE NULL, pls_lon DOUBLE NULL, pls_at BIGINT NULL)",
 // Externe Einsatzkräfte ohne App-Konto mit festem Standort (Issue #39), z. B. Sicherungs- und Beobachtungsposten der Feuerwehr; moved = zuletzt verschoben, removed = entfernt
 "CREATE TABLE IF NOT EXISTS externals(id $ai, incident_id INT, name $tx, descr TEXT, role_id INT NULL, lat DOUBLE, lon DOUBLE, created_by INT, created BIGINT, moved BIGINT NULL, removed BIGINT NULL)",
 // Vorlagen dafür aus der Verwaltung; lat/lon NULL = Standort beim Erfassen auf der Karte wählen; deaktiviert statt gelöscht
 // Web-Push-Abonnements je Gerät (Issue #67): endpoint des Push-Dienstes, Schlüssel des Browsers (p256dh, auth, base64url)
 "CREATE TABLE IF NOT EXISTS push_subs(id $ai, user_id INT, endpoint TEXT, p256dh $tx, auth $tx, created BIGINT)",
 // Gerätesitzungen der nativen Apps (Issue #108): je Anmeldung mit device ein eigener Token, damit eine Anmeldung im Browser die App nicht abmeldet (users.token bleibt für den Browser);
 // platform android|ios|'', last_seen höchstens alle 10 min nachgeführt
 "CREATE TABLE IF NOT EXISTS sessions(id $ai, user_id INT, token $tx UNIQUE, device $tx, platform $tx, created BIGINT, last_seen BIGINT)",
 // Nativer Push über Firebase Cloud Messaging (Issue #108): FCM-Token je App-Installation, session_id = Gerätesitzung (NULL = Anmeldung ohne device)
 "CREATE TABLE IF NOT EXISTS push_native(id $ai, user_id INT, session_id INT NULL, token TEXT, platform $tx, created BIGINT)",
 // Benutzerrollen (Issue #63): perms = JSON-Liste der Rechte (PERMS), sys 'admin' = Administrator (alle Rechte, auch künftige, nicht bearbeitbar), def = bei neuen Benutzern vorausgewählt.
 // Eine Person kann mehrere Rollen haben (user_uroles) und erhält alle Rechte all ihrer Rollen
 "CREATE TABLE IF NOT EXISTS uroles(id $ai, name $tx, perms TEXT, sys $tx NULL, def INT DEFAULT 0)",
 "CREATE TABLE IF NOT EXISTS user_uroles(user_id INT, urole_id INT, PRIMARY KEY(user_id, urole_id))",
 "CREATE TABLE IF NOT EXISTS ext_templates(id $ai, name $tx, descr TEXT, role_id INT NULL, lat DOUBLE NULL, lon DOUBLE NULL, active INT DEFAULT 1)",
 // Feste Standorte (Issue #96): plan_id NULL = permanent (Verwaltung), sonst nur für diese vorbereitete Übung
 "CREATE TABLE IF NOT EXISTS places(id $ai, plan_id INT NULL, name $tx, descr TEXT, abbr $tx NULL, color $tx NULL, lat DOUBLE, lon DOUBLE, active INT DEFAULT 1)",
 "CREATE TABLE IF NOT EXISTS place_cats(id $ai, name $tx, def_on INT DEFAULT 1)",
 // Verwendung je Overpass-Server (Issue #45): src server = Abfrage vom Hosting, device = direkte Abfrage des Geräts (Rückfall); ok_ms = Summe der Antwortzeiten der erfolgreichen
 "CREATE TABLE IF NOT EXISTS overpass_stats(url $tx, src $tx, uses INT DEFAULT 0, ok INT DEFAULT 0, fails INT DEFAULT 0, ok_ms BIGINT DEFAULT 0, last_ok BIGINT NULL, last_fail BIGINT NULL, last_err $tx NULL, PRIMARY KEY(url, src))",
 // Vorbereitete Übungen (Issue #29): Titel (nur für Verwaltung, Übungsleitung und Warteseite), Aufgebotsmeldung (message), Übungsleitung (leads = JSON-Liste von User-IDs,
 // Rechte der Einsatzleitung, nicht Teil der Übung), invite (aufgebotene Personen, JSON-Liste von User-IDs, NULL = alle; nur sie werden alarmiert, teilnehmen dürfen alle), Aufzeichnung, vorbereiteter öffentlicher Link (public_token, schon vor dem Start gültig); incident_id = gestartete Übung; active 0 = entfernt
 "CREATE TABLE IF NOT EXISTS ex_plans(id $ai, title $tx, message TEXT, leads TEXT, record INT DEFAULT 1, public_token $tx NULL, public_anon INT DEFAULT 1, created_by INT, created BIGINT, incident_id INT NULL, active INT DEFAULT 1, invite TEXT NULL)",
 // Vorbereitete Elemente einer Übung, erst nach der Freigabe durch die Übungsleitung sichtbar: kind pls (PLS des Einsatzes setzen/verschieben), res (neuer Rettling mit PLS: name, descr),
 // seen (Rettling gesichtet: neuer PLS für den Rettling aus dem Element ref_id; ref_id NULL = neuer Rettling mit name bzw. unbekannter Rettling ohne name, erfasst bei der Freigabe); ago = Zeitpunkt des PLS in Minuten vor der Freigabe (NULL = Übungsleitung gibt ihn bei der Freigabe ein);
 // ext (neue externe Einsatzkraft: name, descr, role_id, abbr, color; ext_id nach der Freigabe), extdel (entfernt die externe Einsatzkraft aus dem Element ref_id, Standort wie dieses)
 // auto = automatisch freigeben so viele Minuten nach dem Übungsstart (NULL = nur von Hand; released_by NULL = automatisch freigegeben); msg = Meldung, geht bei der Freigabe als Nachricht an alle
 // Einsatzgruppen (Issue #90): pos = Reihenfolge, active 0 = deaktiviert; Mitglieder in inc_group_members; aufbietbar und vorausgewählt je Einsatztyp (inc_kinds)
 // (Tabellennamen nicht «groups»: in MySQL 8 reserviert)
 "CREATE TABLE IF NOT EXISTS inc_groups(id $ai, name $tx, color $tx, pos INT DEFAULT 0, active INT DEFAULT 1)",
 "CREATE TABLE IF NOT EXISTS inc_group_members(group_id INT, user_id INT, PRIMARY KEY(group_id, user_id))",
 // Abwesenheiten und ausgesetzte Einsatzfähigkeit (Issue #91): kind abs = abwesend, sus = Einsatzfähigkeit ausgesetzt (gleiche Wirkung: nicht aufgeboten, kein Alarm,
 // laufende Einsätze bleiben sichtbar, freiwillig beitreten erlaubt); until_at NULL = bis auf Weiteres; removed 1 = entfernt
 "CREATE TABLE IF NOT EXISTS absences(id $ai, user_id INT, from_at BIGINT, until_at BIGINT NULL, kind $tx, note TEXT NULL, created_by INT, created_at BIGINT, removed INT DEFAULT 0)",
 // Einsatztypen (Issue #90, ersetzen die feste Unterscheidung scharf/Übung): perm = Recht zum Starten (inc_start, ex_start, start_<id>), alarm = Alarmstufe (ALARM_LEVELS),
 // mode_up = Personen mit Übungsmodus kritisch alarmieren, rec/air = Vorauswahl Aufzeichnung/Helikopter, feat_off = ausgeschaltete Funktionen, grp_off = nicht aufbietbare Gruppen (Neues ist also aufbietbar),
 // grp_def = vorausgewählte Gruppen, persons = Einzelpersonen aufbietbar, open_all = auch Nicht-Aufgebotene sehen den Einsatz, plans = vorbereitete Übungen erlaubt;
 // deleted 1 = gelöscht: Zeile bleibt, damit bisherige Einsätze Name, Farbe, Funktionen und Fristen behalten
 "CREATE TABLE IF NOT EXISTS inc_kinds(id $ai, name $tx, color $tx, pos INT DEFAULT 0, active INT DEFAULT 1, deleted INT DEFAULT 0, perm $tx, alarm $tx, mode_up INT DEFAULT 0, rec INT DEFAULT 0, air INT DEFAULT 0, feat_off TEXT NULL, grp_off TEXT NULL, grp_def TEXT NULL, persons INT DEFAULT 0, open_all INT DEFAULT 0, plans INT DEFAULT 0)",
 "CREATE TABLE IF NOT EXISTS ex_injects(id $ai, plan_id INT, kind $tx, label $tx, name $tx NULL, descr TEXT NULL, lat DOUBLE, lon DOUBLE, ago INT DEFAULT 0, msg TEXT NULL, ref_id INT NULL, released BIGINT NULL, released_by INT NULL, rescuee_id INT NULL, removed INT DEFAULT 0, auto INT NULL, role_id INT NULL, abbr $tx NULL, color $tx NULL, ext_id INT NULL)",
  "CREATE TABLE IF NOT EXISTS aar(incident_id INT PRIMARY KEY, data MEDIUMTEXT, updated BIGINT, updated_by INT NULL)", // After Action Review (Issue #55)
  "CREATE TABLE IF NOT EXISTS aar_img(id $ai, incident_id INT, field_id $tx, caption TEXT, data MEDIUMTEXT, w INT, h INT, created BIGINT, created_by INT NULL)", // Bilder im After Action Review (Feldart img)
  // Vor dem Anonymisieren gesichertes Review (aarArchive): snap/aar_arch_img bis das PDF steht, das PDF in Stücken (aar_arch_part, unabhängig von Upload- und Paketgrenzen), unveränderlich ab pdf_at
  "CREATE TABLE IF NOT EXISTS aar_arch(incident_id INT PRIMARY KEY, created BIGINT, snap LONGTEXT NULL, pdf_at BIGINT NULL, pdf_parts INT NULL, pdf_name TEXT NULL, pdf_sha TEXT NULL)",
  "CREATE TABLE IF NOT EXISTS aar_arch_img(incident_id INT, img_id INT, field_id $tx, caption TEXT, data MEDIUMTEXT, w INT, h INT, PRIMARY KEY(incident_id,img_id))",
  "CREATE TABLE IF NOT EXISTS aar_arch_part(incident_id INT, n INT, data MEDIUMTEXT, PRIMARY KEY(incident_id,n))",
  // AI-Assistent (Issue #111): jede Abfrage beim Anbieter (Kostenbremse, Statistik) und die letzte Antwort je Funktion und Objekt (Companion, keine doppelten Kosten)
  // Technisches Applikationslog (Issue #139): lvl info|warn|err, cat = Bereich (APPLOG_CATS), ctx = JSON mit technischen Angaben, user_id/route der Anfrage
 "CREATE TABLE IF NOT EXISTS app_log(id $ai, at BIGINT, lvl $tx, cat $tx, msg TEXT, ctx TEXT NULL, user_id INT NULL, route $tx NULL)",
 "CREATE TABLE IF NOT EXISTS ai_usage(id $ai, ts BIGINT, user_id INT, fn $tx, incident_id INT NULL, tokens INT DEFAULT 0, ok INT DEFAULT 1, err TEXT NULL)",
  "CREATE TABLE IF NOT EXISTS ai_cache(k VARCHAR(64) PRIMARY KEY, ts BIGINT, ctx_hash VARCHAR(64), text MEDIUMTEXT, tokens INT DEFAULT 0, model $tx NULL)",
  // Pegel und Abfluss (Issue #125): Werte der Messstationen des BAFU zu Beginn (phase start, sobald ein PLS bekannt ist) und am Ende (end) des Einsatzes;
  // dist = Distanz zum nächsten PLS in Metern, at = Zeitpunkt der Messung. incidents.hydro_s: NULL = Beginn noch nicht erfasst, > 0 erfasst, < 0 = -Zeitpunkt des letzten Fehlschlags
  "CREATE TABLE IF NOT EXISTS inc_hydro(id $ai, incident_id INT, phase $tx, ts BIGINT, station $tx, name $tx, water $tx, lat DOUBLE, lon DOUBLE, dist INT NULL, flow DOUBLE NULL, height DOUBLE NULL, level DOUBLE NULL, temp DOUBLE NULL, danger INT NULL, at BIGINT NULL)",
] as $s) $db->exec($s);
// Migrationen für ältere Installationen (schlagen bei vorhandenen Spalten still fehl)
// Aufgebot (Issue #90): incidents.invite = beim Start aufgelöste User-IDs (NULL = alle), inv_groups = aufgebotene Gruppen (JSON, gn = Namen gelöschter Gruppen), kind_id = Einsatztyp (is_exercise wird nicht mehr gelesen),
// open_join = Einsatzleitung erlaubt allen den Beitritt;
// ex_plans.grp_ids = aufgebotene Gruppen einer vorbereiteten Übung (zusätzlich zu invite = Einzelpersonen); uroles.grp_ids = Gruppen, die diese Rolle aufbieten darf (NULL = alle)
try { $db->exec("ALTER TABLE roles ADD COLUMN is_post INT DEFAULT 0"); $db->exec("UPDATE roles SET is_post=1 WHERE name LIKE 'Sicherungs%'"); } catch (Throwable $e) {}
try { $db->exec("ALTER TABLE modes ADD COLUMN lead_rights INT DEFAULT 0"); $db->exec("UPDATE modes SET lead_rights=1 WHERE code='coordinator'"); } catch (Throwable $e) {}
try { $db->exec("ALTER TABLE modes ADD COLUMN counts INT DEFAULT 1"); $db->exec("UPDATE modes SET counts=0 WHERE code='coordinator'"); } catch (Throwable $e) {}
foreach (['aar' => ['status INT DEFAULT 0', 'rel_at BIGINT NULL', 'rel_by INT NULL'], 'roles' => ['can_message INT DEFAULT 1'], 'messages' => ['lat DOUBLE NULL', 'lon DOUBLE NULL', 'mark INT DEFAULT 0', 'mark_lat DOUBLE NULL', 'mark_lon DOUBLE NULL', 'unmarked BIGINT NULL', "from_label $tx NULL", 'to_label TEXT NULL'],'participants' => ["label $tx NULL", 'log_lat DOUBLE NULL', 'log_lon DOUBLE NULL', 'log_at BIGINT NULL', 'fast_at BIGINT NULL'], 'users' => ['is_planner INT DEFAULT 0', 'exercise_mode INT DEFAULT 0', 'must_change INT DEFAULT 0', "coord_sys $tx NULL", "nav_app $tx NULL", 'place_view TEXT NULL', 'ai_modes TEXT NULL', 'hydro TEXT NULL'], 'places' => ['cat_id INT NULL', 'pts TEXT NULL', 'sort INT NULL', 'line INT DEFAULT 0'], 'place_cats' => ['sort INT NULL'], 'externals' => ['manual INT DEFAULT 0', "abbr $tx NULL", "color $tx NULL"], 'ext_templates' => ["abbr $tx NULL", "color $tx NULL"], 'incidents' => ['is_exercise INT DEFAULT 0', 'record INT DEFAULT 0', 'recorded INT DEFAULT 0', "public_token $tx NULL", 'public_anon INT DEFAULT 1', 'pls_at BIGINT NULL', 'plan_id INT NULL', 'public_log INT DEFAULT 1', 'rp_anon TEXT NULL', 'log_anon INT DEFAULT 0', 'air INT NULL', 'view_anon INT DEFAULT 0', 'txt_anon INT DEFAULT 0', 'part_anon INT DEFAULT 0', 'kind_id INT NULL', 'invite TEXT NULL', 'inv_groups TEXT NULL', 'open_join INT DEFAULT 0', 'inv_absent TEXT NULL', 'hydro_s BIGINT NULL'], 'incident_log' => ["actor $tx NULL"], 'rescuees' => ['pls_lat DOUBLE NULL', 'pls_lon DOUBLE NULL', 'pls_at BIGINT NULL'], 'ex_plans' => ['invite TEXT NULL', 'grp_ids TEXT NULL', 'kind_id INT NULL', 'places TEXT NULL'], 'uroles' => ['grp_ids TEXT NULL'], 'inc_groups' => ['show_name INT DEFAULT 1'], 'inc_hydro' => ['danger INT NULL'], 'ex_injects' => ['auto INT NULL', 'role_id INT NULL', "abbr $tx NULL", "color $tx NULL", 'ext_id INT NULL']] as $t => $cs)
  foreach ($cs as $c) { try { $db->exec("ALTER TABLE $t ADD COLUMN $c"); } catch (Throwable $e) {} }
// Letzte Anmeldung (Löschen nur für Personen, die sich nie angemeldet haben); bei der Migration gelten Konten mit eigenem Passwort oder Token als angemeldet (0 = Zeitpunkt unbekannt)
try { $db->exec("ALTER TABLE users ADD COLUMN last_login BIGINT NULL"); $db->exec("UPDATE users SET last_login=0 WHERE must_change=0 OR token IS NOT NULL"); } catch (Throwable $e) {}
try { $db->exec("CREATE INDEX track_inc ON track(incident_id,ts)"); } catch (Throwable $e) {} // schlägt bei vorhandenem Index still fehl
// Bilder im After Action Review: einmalig ein Feld «Bilder» an bereits gespeicherte Einstellungen anhängen (der Standard hat es schon); entfernbar
if (setting('aar_img_mig', '') === '') { $c = json_decode((string)setting('aar_cfg', ''), true);
  if (is_array($c) && !array_filter((array)($c['fields'] ?? []), fn($f) => ($f['type'] ?? '') === 'img')) {
    $c['seq'] = (int)($c['seq'] ?? 12) + 1; $c['fields'][] = ['id' => 'f' . $c['seq'], 'type' => 'img', 'label' => 'Bilder', 'hint' => 'Fotos, Skizzen, Kartenausschnitte'];
    setSetting('aar_cfg', json_encode($c, JSON_UNESCAPED_UNICODE)); }
  setSetting('aar_img_mig', '1'); }
try { $db->exec("CREATE INDEX app_log_at ON app_log(at)"); } catch (Throwable $e) {}
try { $db->exec("CREATE INDEX air_track_inc ON air_track(incident_id,ts)"); } catch (Throwable $e) {}
if (!$db->query("SELECT COUNT(*) c FROM roles")->fetch()['c']) {
  $st = $db->prepare("INSERT INTO roles(name,color,is_lead,is_post) VALUES(?,?,?,?)");
  foreach ([['Einsatzleitung','#d62828',1,0],['Rettungsboot','#1d7fd1',0,0],['Rettungsschlitten','#e6a100',0,0],['Sicherungs- und Beobachtungsposten','#2a9d5c',0,1]] as $r) $st->execute($r);
}
if (!$db->query("SELECT COUNT(*) c FROM modes")->fetch()['c']) { // bisherige feste Optionen, Codes bleiben für bestehende Einsätze gültig
  $st = $db->prepare("INSERT INTO modes(code,name,share_location,lead_rights,counts) VALUES(?,?,?,?,?)");
  foreach ([['base','Anfahrt via Stützpunkt',1,0,1],['direct','Anfahrt direkt',1,0,1],['logistics','Logistik',1,0,1],['coordinator','Koordinator KP Rück',0,1,0]] as $r) $st->execute($r);
}
if (!$db->query("SELECT COUNT(*) c FROM users")->fetch()['c'])
  $db->prepare("INSERT INTO users(username,name,pass_hash,is_admin,must_change) VALUES(?,?,?,1,1)")->execute([$cfg['admin_user'], 'Administrator', password_hash($cfg['admin_pass'], PASSWORD_DEFAULT)]);
// Einsatztypen (Issue #90): beim ersten Aufruf «Einsatz» und «Übung» mit den bisherigen Einstellungen anlegen. Die Sperrzeile in settings sorgt dafür, dass gleichzeitige Anfragen das nur einmal tun
// (eine zweite wartet, bis die erste fertig ist, und schlägt dann am Primärschlüssel fehl).
if (!$db->query("SELECT COUNT(*) c FROM inc_kinds")->fetch()['c']) {
  $db->beginTransaction();
  try {
    $db->exec("INSERT INTO settings(k,v) VALUES('kinds_mig','1')");
    $sv = function ($k) use ($db) { $s = $db->prepare("SELECT v FROM settings WHERE k=?"); $s->execute([$k]); $v = $s->fetchColumn(); $s->closeCursor(); return $v === false ? null : $v; };
    $fo = json_decode($sv('features') ?? '', true) ?: []; $ac = json_decode($sv('air') ?? '', true) ?: []; $go = []; $gr = []; $ge = [];
    try { foreach ($db->query("SELECT id,for_real,def_real,def_ex FROM inc_groups")->fetchAll() as $g) { if (!(int)$g['for_real']) $go[] = (int)$g['id']; if ((int)$g['def_real']) $gr[] = (int)$g['id']; if ((int)$g['def_ex']) $ge[] = (int)$g['id']; } } catch (Throwable $e) {} // Spalten gab es nur im Entwurf von #90
    $st = $db->prepare("INSERT INTO inc_kinds(name,color,pos,perm,alarm,mode_up,rec,air,feat_off,grp_off,grp_def,persons,open_all,plans) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $st->execute(['Einsatz', '#ff4d2e', 1, 'inc_start', 'crit', 0, (int)($sv('rec_real') ?? 0), (int)($ac['def_real'] ?? 1), json_encode(array_values((array)($fo['real'] ?? []))), json_encode($go), json_encode($gr), 0, 0, 0]); $kr = (int)$db->lastInsertId();
    $st->execute(['Übung', '#1d7fd1', 2, 'ex_start', 'normal', 1, (int)($sv('rec_ex') ?? 1), (int)($ac['def_ex'] ?? 0), json_encode(array_values((array)($fo['ex'] ?? []))), '[]', json_encode($ge), 1, 1, 1]); $ke = (int)$db->lastInsertId();
    $db->prepare("UPDATE incidents SET kind_id=CASE WHEN is_exercise=1 THEN ? ELSE ? END WHERE kind_id IS NULL")->execute([$ke, $kr]);
    $db->prepare("UPDATE ex_plans SET kind_id=? WHERE kind_id IS NULL")->execute([$ke]);
    $ao = json_decode($sv('anon_cfg') ?? '', true);
    if (is_array($ao)) { foreach ($ao as $k => $x) if (is_array($x)) $ao[$k] = ['on' => $x['on'] ?? 1, 'days' => [$kr => $x['real'] ?? null, $ke => $x['ex'] ?? null]]; $db->prepare("UPDATE settings SET v=? WHERE k='anon_cfg'")->execute([json_encode($ao)]); }
    $db->commit();
    try { $db->exec("DROP TABLE IF EXISTS inc_types"); } catch (Throwable $e) {} // Vorauswahl-Typen aus dem Entwurf von #90 sind entfallen
  } catch (Throwable $e) { $db->rollBack(); if (!$db->query("SELECT COUNT(*) c FROM inc_kinds")->fetch()['c']) throw $e; }
}
// Berechtigungssystem (Issue #63): beim ersten Aufruf die bisherigen festen Benutzerrollen als Rollen anlegen und zuweisen
// (alle = Einsatzkraft, is_planner = zusätzlich Übungsleiter, is_admin = zusätzlich Systemadministrator). Die Spalten is_admin/is_planner gelten danach nicht mehr.
if (!$db->query("SELECT COUNT(*) c FROM uroles")->fetch()['c']) {
  $st = $db->prepare("INSERT INTO uroles(name,perms,sys,def) VALUES(?,?,?,?)"); $ids = [];
  foreach ([['Systemadministrator', [], 'admin', 0], ['Übungsleiter', ['ex_plan'], null, 0], ['Einsatzkraft', ['inc_start', 'ex_start'], null, 1]] as [$n, $p, $sy, $d]) { $st->execute([$n, json_encode($p), $sy, $d]); $ids[] = (int)$db->lastInsertId(); }
  $st = $db->prepare("INSERT INTO user_uroles(user_id,urole_id) VALUES(?,?)");
  foreach ($db->query("SELECT id,is_admin,is_planner FROM users")->fetchAll() as $u) {
    $st->execute([(int)$u['id'], $ids[2]]);
    if ((int)$u['is_admin']) $st->execute([(int)$u['id'], $ids[0]]); elseif ((int)$u['is_planner']) $st->execute([(int)$u['id'], $ids[1]]);
  }
}
// Die feste Rolle heisst Systemadministrator (früher «Administrator»)
if ($db->query("SELECT COUNT(*) c FROM uroles WHERE sys='admin' AND name='Administrator'")->fetch()['c'])
  $db->exec("UPDATE uroles SET name='Systemadministrator' WHERE sys='admin' AND name='Administrator'");

$route = $_GET['r'] ?? '';
$in = json_decode(file_get_contents('php://input'), true) ?: [];
$now = time();

$MODES = ['base' => 'Anfahrt via Stützpunkt', 'direct' => 'Anfahrt direkt', 'logistics' => 'Logistik', 'coordinator' => 'Koordinator KP Rück'];
// Einsatzlog: nur Fahrten mit Fahrzeug protokollieren (nicht zu Fuss, nicht auf dem Gewässer)
const VEHICLE_MS = 7;    // ab ca. 25 km/h zwischen zwei Standortmeldungen gilt eine Bewegung als Fahrt (Gehen/Laufen liegt deutlich darunter)
const TRIP_MIN_M = 500;  // kürzere Fahrten und GPS-Sprünge nicht protokollieren
const STOP_S = 90;       // so lange ohne schnelle Meldung = Fahrt beendet
const TRIP_LOG_S = 600;  // lange Fahrten zusätzlich alle 10 Minuten protokollieren
const COORDS = ['lv95', 'lv03', 'wgs84', 'wgs84dms']; // Koordinatensysteme für die Anzeige (Umrechnung im Frontend, COORD_SYS in common.js)
// Gewässernetz (OSM via Overpass) über den Server mit Datei-Cache in api/data/overpass/ (overpassServe)
const OVERPASS = ['https://overpass-api.de/api/interpreter', 'https://maps.mail.ru/osm/tools/overpass/api/interpreter', 'https://overpass.kumi.systems/api/interpreter'];
// Reihenfolge der Abfrage: Einstellung overpass_urls (JSON-Liste, in der Verwaltung unter «Gewässerdaten»), leer = OVERPASS
function overpassUrls() { $v = json_decode(setting('overpass_urls', ''), true); return is_array($v) && $v ? $v : OVERPASS; }
// Issue #75: ausgeschaltete Server (Einstellung overpass_off, JSON-Liste) und Zugriff ganz aus (overpass_on = 0); abgefragt werden nur die übrigen
function overpassOff() { $v = json_decode(setting('overpass_off', ''), true); return array_values(array_intersect(overpassUrls(), is_array($v) ? $v : [])); }
function overpassActive() { return setting('overpass_on', '1') === '1' ? array_values(array_diff(overpassUrls(), overpassOff())) : []; }
// Abschaltbare Funktionen (Issue #35), je für scharfe Einsätze und Übungen getrennt (Abschnitt «Funktionen» in der Verwaltung).
// Neue Funktionen hier aufnehmen (Schlüssel => Bezeichnung) und ihre Routen in FEATURE_ROUTES; das Frontend liest features aus incident/state bzw. me.
// Rechte für Benutzerrollen (Issue #63): Schlüssel => [Gruppe, Bezeichnung, Erklärung]. Neue Rechte hier aufnehmen; Administratoren haben sie automatisch.
// Rechte im Einsatz selbst (Nachrichten, Rettlinge, Markierungen …) regeln weiterhin Einsatzleitung, Spezialrollen und Einrückoptionen.
// Das Recht zum Starten gibt es je Einsatztyp, siehe permsAll()
const PERMS = [
  'inc_lead' => ['Einsatz-App', 'Jeden Einsatz leiten', 'Rechte der Einsatzleitung in jedem Einsatz, an dem die Person teilnimmt'],
  'ex_plan' => ['Übungen', 'Übungen vorbereiten', 'Verwaltung → Übungen; im Einsatz die vorbereiteten Elemente sehen und freigeben'],
  'groups' => ['Verwaltung', 'Einsatzgruppen verwalten', 'Gruppen anlegen, ändern, deaktivieren und ihre Mitglieder bestimmen'],
  'avail' => ['Verwaltung', 'Abwesenheiten verwalten', 'Abwesenheiten und ausgesetzte Einsatzfähigkeit aller Personen erfassen und entfernen (die eigenen darf jede Person selbst setzen)'],
  'users' => ['Verwaltung', 'Benutzer verwalten', 'Anlegen, bearbeiten, Passwort zurücksetzen, deaktivieren, nie angemeldete löschen, Benutzerrollen zuweisen'],
  'uroles' => ['Verwaltung', 'Benutzerrollen verwalten', 'Rollen anlegen, ändern und entfernen'],
  'cfg_inc' => ['Verwaltung', 'Einsatz einstellen', 'Einsatztypen (Alarmstufe, aufbietbare Einsatzgruppen), Einrückoptionen, Spezialrollen, Nachrichten, Vorlagen für externe Einsatzkräfte'],
  'cfg_func' => ['Verwaltung', 'Funktionen einstellen', 'Funktionen und Aufzeichnung je Einsatztyp, Flussdistanz, Navigation'],
  'cfg_map' => ['Verwaltung', 'Karte & Daten einstellen', 'Koordinaten, Gewässerdaten (PostGIS, Overpass), Helikopter, Pegel & Abfluss, feste Standorte'],
  'cfg_ai' => ['Verwaltung', 'AI-Assistent einstellen', 'Anbieter, API-Schlüssel, erlaubte Modi je Benutzerrolle, Datenschutz und Limits des AI-Assistenten; Nutzung einsehen'],
  'stats' => ['Auswertung', 'Alle Einsätze auswerten', 'Einsatzstatistik, Log und Replay aller Einsätze'],
  'anon' => ['Auswertung', 'Anonymisieren und Aufzeichnungen löschen', 'Anonymisierung einstellen (Stufen, automatisch nach Tagen) und anwenden, auch für mehrere Einsätze; endgültige Stufen sind nicht umkehrbar'],
  'cfg_my' => ['Auswertung', 'Einsicht für Einsatzkräfte einstellen', 'Was Teilnehmende von ihren eigenen Einsätzen sehen (Meine Einsätze)'],
  'aar' => ['Auswertung', 'After Action Reviews erstellen', 'In der Einsatzstatistik je beendetem Einsatz die Angaben erfassen, als nicht relevant markieren, ansehen und herunterladen (ohne «Alle Einsätze auswerten» nur Liste und Kennzahlen, ohne Log und Replay)'],
  'aar_rel' => ['Auswertung', 'After Action Reviews freigeben', 'Als abgeschlossen freigeben (danach gesperrt und für die Teilnehmenden unter «Meine Einsätze» sichtbar) und Freigaben zurücknehmen'],
  'aar_cfg' => ['Auswertung', 'After Action Review einrichten', 'Felder, Dokumenttitel, Organisation, Logo, mitgelieferte Abschnitte und erlaubte Formate'],
  'legal' => ['Verwaltung', 'Impressum & Datenschutz einstellen', 'Angaben zur betreibenden Organisation, Kontakt und Zusatztexte der Seite «Impressum, Lizenzen & Datenschutz»'],
  'aar_arch' => ['Auswertung', 'Gesicherte After Action Reviews abrufen', 'Das vor dem Anonymisieren gesicherte, vollständige PDF eines freigegebenen Reviews abrufen, mit allen Namen'],
  'applog' => ['System', 'Applikationslog einsehen', 'Technisches Applikationslog mit Ereignissen, Warnungen und Fehlern des Servers und der Geräte; Aufbewahrung einstellen'],
];
// Alarmstufen der Einsatztypen (Issue #90), von stark nach schwach: Schlüssel => [Bezeichnung, Erklärung]; Einsatz-App und Service Worker setzen sie um
const ALARM_LEVELS = ['crit' => ['Kritisch', 'Vollbild-Alarm mit Dauerton und Vibration, Push bleibt stehen, bis man ihn antippt'], 'high' => ['Hoch', 'Vollbild-Alarm mit einmaligem Ton, Push bleibt stehen, bis man ihn antippt'], 'normal' => ['Normal', 'Hinweis mit kurzem Ton, normaler Push'], 'low' => ['Leise', 'Hinweis ohne Ton und Vibration, stiller Push'], 'none' => ['Keine', 'Kein Alarm und kein Push, der Einsatz erscheint nur in der App']];
// Einstellungen (settings/save) und das Recht, das sie ändern darf
const SETTING_PERMS = ['msg_no_role' => 'cfg_inc', 'msg_read_all' => 'cfg_inc', 'features' => 'cfg_func', 'air_kinds' => 'cfg_map', 'rd_speeds' => 'cfg_func', 'nav_off' => 'cfg_func', 'nav_default' => 'cfg_func',
  'coord_sys' => 'cfg_map', 'overpass_ttl' => 'cfg_map', 'overpass_urls' => 'cfg_map', 'overpass_off' => 'cfg_map', 'overpass_on' => 'cfg_map', 'air' => 'cfg_map', 'my_runs' => 'cfg_my', 'my_btns' => 'cfg_my', 'legal' => 'legal', 'ai' => 'cfg_ai', 'hydro' => 'cfg_map', 'postgis' => 'cfg_map', 'applog_days' => 'applog']; // ai: eigene Route ai/cfg/save (Prüfung aiIn), settings/save lehnt es ab
/* ---------- After Action Review (Issue #55) ---------- */
// Felder frei definierbar (Einstellung aar_cfg: Titel, Organisation, mitgelieferte Abschnitte, Felder), Werte je Einsatz in der Tabelle aar (JSON je Feld-ID).
// Das Dokument (Word/PDF) entsteht im Browser (admin.html, aarModel); der Server liefert nur die Daten. Logo als PNG-/JPEG-Data-URL in der Einstellung aar_logo.
const AAR_TYPES = ['head' => 'Zwischentitel', 'text' => 'Text einzeilig', 'area' => 'Text mehrzeilig', 'date' => 'Datum', 'time' => 'Uhrzeit', 'number' => 'Zahl', 'select' => 'Auswahl', 'check' => 'Ja/Nein', 'img' => 'Bilder'];
const AAR_IMG_MAX = 50; // Bilder je Review, je höchstens 3 MB (der Browser verkleinert auf 1600 px JPEG)
const AAR_AUTO = ['inc' => 'Einsatzdaten (Art, Zeiten, Kennzahlen)', 'people' => 'Einsatzkräfte', 'res' => 'Rettlinge', 'ext' => 'Externe Einsatzkräfte', 'log' => 'Einsatzlog'];
// Status je Review: 0 = in Arbeit, 1 = freigegeben (abgeschlossen, gesperrt, für Teilnehmende sichtbar), 2 = nicht relevant
const AAR_STATUS = [0 => 'in Arbeit', 1 => 'freigegeben', 2 => 'nicht relevant'];
const AAR_ARCH_PARTS = 400; const AAR_ARCH_CHUNK = 2200000; // gesichertes PDF: höchstens so viele Stücke zu je so vielen Zeichen Base64 (reicht für 50 Bilder zu 3 MB)
const AAR_DL = ['docx' => 'Word (.docx)', 'pdf' => 'PDF']; // Download je Format und Zustand erlaubt (Einstellung dl); Ansehen im Browser ist davon unabhängig
const AAR_DL_ST = ['wip' => 'In Arbeit', 'rel' => 'Freigegeben', 'nr' => 'Nicht relevant']; // «noch kein AAR» zählt als in Arbeit
// dl je Format und Zustand; die frühere Einstellung (nur Format ein/aus) gilt für «Freigegeben»
function aarDlNorm($d) { $r = []; foreach (AAR_DL as $k => $_) { $v = $d[$k] ?? null; foreach (AAR_DL_ST as $s => $__) $r[$k][$s] = is_array($v) ? (int)!empty($v[$s]) : ($s === 'rel' ? (int)($v ?? 1) : 0); } return $r; }
const AAR_DEFAULT = ['title' => 'After Action Review', 'org' => '', 'seq' => 13, 'auto' => ['inc' => 1, 'people' => 1, 'res' => 1, 'ext' => 1, 'log' => 0], 'dl' => ['docx' => ['wip' => 0, 'rel' => 1, 'nr' => 0], 'pdf' => ['wip' => 0, 'rel' => 1, 'nr' => 0]], 'fields' => [
  ['id' => 'f1', 'type' => 'date', 'label' => 'Datum des Reviews', 'hint' => ''],
  ['id' => 'f2', 'type' => 'text', 'label' => 'Moderation', 'hint' => ''],
  ['id' => 'f3', 'type' => 'area', 'label' => 'Teilnehmende am Review', 'hint' => ''],
  ['id' => 'f4', 'type' => 'text', 'label' => 'Wetter und Wasserstand', 'hint' => ''],
  ['id' => 'f5', 'type' => 'head', 'label' => 'Ablauf', 'hint' => ''],
  ['id' => 'f6', 'type' => 'area', 'label' => 'Was war geplant?', 'hint' => 'Auftrag, Absicht, erwarteter Ablauf'],
  ['id' => 'f7', 'type' => 'area', 'label' => 'Was ist tatsächlich passiert?', 'hint' => 'Ablauf aus Sicht der Beteiligten'],
  ['id' => 'f8', 'type' => 'area', 'label' => 'Warum gab es Abweichungen?', 'hint' => ''],
  ['id' => 'f9', 'type' => 'head', 'label' => 'Erkenntnisse', 'hint' => ''],
  ['id' => 'f10', 'type' => 'area', 'label' => 'Was lief gut?', 'hint' => 'Beibehalten'],
  ['id' => 'f11', 'type' => 'area', 'label' => 'Was können wir verbessern?', 'hint' => ''],
  ['id' => 'f12', 'type' => 'area', 'label' => 'Massnahmen', 'hint' => 'Was, wer, bis wann'],
  ['id' => 'f13', 'type' => 'img', 'label' => 'Bilder', 'hint' => 'Fotos, Skizzen, Kartenausschnitte'],
]];
// Zugriff auf ein Review: Verwaltung (aar, aar_rel) oder my=1 (Meine Einsätze: Teilnahme, freigegeben, my_runs nicht off)
// Statistik pro Person (Issue #87): eine Zeile je Teilnahme; $only = nur diese Person (Meine Statistik)
function psRows($only = null) { global $db, $now; $only = $only === null ? null : (int)$only;
  // Einsatzzeit = Summe der Abschnitte von «angenommen»/«wieder eingestiegen» bis «verlassen», der letzte bis Austritt (left_at) bzw. Einsatzende
  // Fahrten = Log-Einträge «move» (Luftlinie gemäss Log), Weg = Summe der aufgezeichneten Standorte (nur mit Aufzeichnung, Lücken über 120 s nicht, wie GAP_S im Replay)
  // Endgültig anonymisierte Einsätze bleiben personenbezogen: Log-Einträge tragen dann die Bezeichnung «Einsatzkraft N» (actor), die Spuren eine negative ID (rp_anon);
  // beide folgen der Reihenfolge der Teilnahmen (anonCtx, participants nach id), daraus ergibt sich wieder die Person
  $inc = []; foreach ($db->query("SELECT id,alarm_at,ended_at,is_exercise,kind_id,log_anon,rp_anon FROM incidents") as $r) $inc[(int)$r['id']] = kindAdd(ints($r, ['id', 'alarm_at', 'ended_at', 'is_exercise', 'log_anon']));
  $lab = []; $n = []; foreach ($db->query("SELECT incident_id,user_id FROM participants ORDER BY incident_id,id") as $r) { $i = (int)$r['incident_id']; $n[$i] = ($n[$i] ?? 0) + 1; $lab[$i]['Einsatzkraft ' . $n[$i]] = (int)$r['user_id']; }
  $neg = []; foreach ($inc as $i => $x) if ($x['rp_anon'] !== null) foreach (json_decode($x['rp_anon'], true) ?: [] as $p) if (isset($p['user_id'], $p['name'], $lab[$i][$p['name']])) $neg[$i][(int)$p['user_id']] = $lab[$i][$p['name']];
  $ev = []; foreach ($db->query("SELECT incident_id,user_id,actor,ts,kind,text FROM incident_log WHERE kind IN ('accept','leave','move','area') AND (user_id IS NOT NULL OR actor IS NOT NULL) ORDER BY ts, id") as $r) {
   $i = (int)$r['incident_id']; $u = $r['user_id'] !== null ? (int)$r['user_id'] : ($lab[$i][(string)$r['actor']] ?? null);
   if ($u !== null && ($only === null || $u === $only)) $ev[$i . ':' . $u][] = $r; }
  $tr = []; $last = []; foreach ($db->query("SELECT incident_id,user_id,ts,lat,lon FROM track WHERE user_id IS NOT NULL ORDER BY incident_id,user_id,ts,id") as $r) {
   $i = (int)$r['incident_id']; $u = (int)$r['user_id']; if ($u < 0) $u = $neg[$i][$u] ?? null;
   if ($u === null || ($only !== null && $u !== $only)) continue;
   $k = $i . ':' . $u; $l = $last[$k] ?? null;
   if ($l && (int)$r['ts'] - (int)$l['ts'] <= 120) $tr[$k] = ($tr[$k] ?? 0) + hav((float)$l['lat'], (float)$l['lon'], (float)$r['lat'], (float)$r['lon']);
   $last[$k] = $r; }
  $rows = [];
  foreach ($db->query("SELECT p.incident_id,p.user_id,p.mode,p.left_at,u.name,u.username,u.active,m.name mode_name FROM participants p JOIN users u ON u.id=p.user_id LEFT JOIN modes m ON m.code=p.mode" . ($only === null ? '' : ' WHERE p.user_id=' . (int)$only)) as $p) {
   $i = $inc[(int)$p['incident_id']] ?? null; if (!$i) continue;
   $k = $p['incident_id'] . ':' . $p['user_id']; $e = $ev[$k] ?? []; $dec = $p['mode'] === 'declined';
   $dur = 0; $open = null; $first = null; $trips = 0; $km = 0.0; $area = null;
   foreach ($e as $x) { $t = (int)$x['ts'];
    if ($x['kind'] === 'accept') { if ($first === null) $first = $t; if ($open === null) $open = $t; }
    elseif ($x['kind'] === 'leave') { if ($open !== null) { $dur += $t - $open; $open = null; } }
    elseif ($x['kind'] === 'area') { if ($area === null) $area = $t; }
    elseif (preg_match('~ca\. ([0-9.]+) km~', $x['text'], $mm)) { $trips++; $km += (float)$mm[1]; } }
   $stop = $p['left_at'] !== null ? (int)$p['left_at'] : ($i['ended_at'] ?? $now);
   if ($open !== null && !$dec) $dur += max(0, $stop - $open);
   $row = ['incident_id' => $i['id'], 'kind_id' => $i['kind_id'], 'kind_name' => $i['kind_name'], 'kind_color' => $i['kind_color'], 'alarm_at' => $i['alarm_at'], 'declined' => $dec ? 1 : 0,
    'dur' => $dec ? 0 : $dur, 'react' => $first === null ? null : max(0, $first - $i['alarm_at']), 'area' => $area === null ? null : max(0, $area - $i['alarm_at']),
    'trips' => $trips, 'km' => round($km, 1), 'track_km' => isset($tr[$k]) ? round($tr[$k] / 1000, 1) : null, 'mode_name' => $dec ? null : $p['mode_name']];
   $row += ['user_id' => (int)$p['user_id'], 'name' => $p['name'], 'username' => $p['username'], 'active' => (int)$p['active']];
   $rows[] = $row; }

  return $rows; }
// Ab der Sicherung (aarArchive) gibt es das Review nur noch als PDF (Recht aar_arch), auch wenn die Anzeige wieder aufgehoben wird: nichts ansehen, nichts ändern
// Anonymisiert (jede Stufe ausser track, solange sie gilt): kein Review mehr. Ein freigegebenes wäre gesichert (aarSealed); bleibt eines ohne Sicherung, stammt es von vor der Sicherung und bleibt lesbar
const AAR_LOCK = "(i.rp_anon IS NOT NULL OR COALESCE(i.log_anon,0)<>0 OR COALESCE(i.view_anon,0)<>0 OR COALESCE(i.txt_anon,0)<>0 OR COALESCE(i.part_anon,0)<>0) AND NOT EXISTS (SELECT 1 FROM aar a WHERE a.incident_id=i.id AND a.status=1)";
function aarSealed($id) { global $db; $s = $db->prepare("SELECT 1 FROM aar_arch WHERE incident_id=?"); $s->execute([(int)$id]); $v = $s->fetchColumn(); $s->closeCursor(); if ($v !== false) fail('Dieses After Action Review ist gesichert und nur noch als PDF verfügbar.', 409);
  $s = $db->prepare("SELECT 1 FROM incidents i WHERE i.id=? AND " . AAR_LOCK); $s->execute([(int)$id]); $v = $s->fetchColumn(); $s->closeCursor(); if ($v !== false) fail('Dieser Einsatz ist anonymisiert, ein After Action Review ist nicht mehr möglich.', 409); }
function aarView($id) { global $db; if (empty($_GET['my'])) { need('aar', 'aar_rel'); aarSealed($id); return false; }
  if (myRuns() === 'off' || !myPart($id) || !myAar()) fail('Kein Zugriff auf diesen Einsatz', 403);
  $s = $db->prepare("SELECT status FROM aar WHERE incident_id=?"); $s->execute([$id]); $st = $s->fetchColumn(); $s->closeCursor();
  if ($st === false || (int)$st !== 1) fail('Dieses After Action Review ist nicht freigegeben', 403);
  aarSealed($id); return true; }
// Bearbeiten (Angaben, Bilder): Recht aar, Einsatz beendet, nicht freigegeben; danach gilt das Review als in Arbeit
function aarEditable($inc) { global $db; need('aar'); aarSealed($inc['id']);
  if ($inc['ended_at'] === null) fail('Ein After Action Review gibt es erst nach dem Einsatzende');
  $s = $db->prepare("SELECT status FROM aar WHERE incident_id=?"); $s->execute([(int)$inc['id']]); $st = $s->fetchColumn(); $s->closeCursor();
  if ($st !== false && (int)$st === 1) fail('Das After Action Review ist freigegeben. Zum Bearbeiten zuerst die Freigabe zurücknehmen.');
  return $st !== false; }
function aarTouch($id, $exists) { global $db, $now, $uid;
  if ($exists) $db->prepare("UPDATE aar SET updated=?,updated_by=?,status=0 WHERE incident_id=?")->execute([$now, $uid, $id]);
  else $db->prepare("INSERT INTO aar(incident_id,data,updated,updated_by,status) VALUES(?,'{}',?,?,0)")->execute([$id, $now, $uid]); }
function aarImgRow($img) { global $db; $s = $db->prepare("SELECT id,incident_id FROM aar_img WHERE id=?"); $s->execute([(int)$img]); $r = $s->fetch(); $s->closeCursor(); return $r ?: fail('Bild nicht gefunden', 404); }
// Inhalt des Dokuments (Route aar/get, Sicherung aarArchive); c = anonCtx oder null
function aarDoc($inc, $c, $anon, $forced, $mine, $self) { global $db; $id = (int)$inc['id']; $q = function ($sql) use ($db, $id) { $s = $db->prepare($sql); $s->execute([$id]); return $s->fetchAll(); };
  $a = $q("SELECT data,updated,updated_by,status,rel_at,rel_by FROM aar WHERE incident_id=?")[0] ?? null;
  $nm = fn($u) => $u === null ? null : ($c ? ($c['u'][(int)$u] ?? 'Person') : ($db->query('SELECT name FROM users WHERE id=' . (int)$u)->fetchColumn() ?: '?'));
  $rpa = $inc['rp_anon'] !== null;
  $parts = array_map(fn($p) => ['name' => $c && !$rpa ? ($c['u'][$p['user_id']] ?? 'Einsatzkraft') : $p['name'], 'mode' => $p['mode_name'] ?? null, 'role' => $p['role'] ?? null, 'joined' => $p['joined'] ?? null, 'left_at' => $p['left_at'] ?? null],
    $rpa ? (json_decode($inc['rp_anon'], true) ?: []) : replayParts($id));
  $res = array_map(function ($r) use ($c) { $r = ints($r, ['id', 'loc_at', 'rescued_at', 'created', 'removed']); if ($c) { $r['name'] = $c['r'][$r['id']] ?? 'Rettling'; $r['descr'] = null; } return $r; },
    $q("SELECT id,name,descr,lat,lon,loc_at,rescued_at,created,removed FROM rescuees WHERE incident_id=? ORDER BY id"));
  $ext = array_map(function ($r) use ($c) { $r = ints($r, ['id', 'created', 'removed']); if ($c) { $r['name'] = $c['e'][$r['id']] ?? 'Externe Einsatzkraft'; $r['descr'] = null; } return $r; },
    $q("SELECT e.id,e.name,e.descr,e.created,e.removed,r.name role FROM externals e LEFT JOIN roles r ON r.id=e.role_id WHERE e.incident_id=? ORDER BY e.id"));
  $first = function ($k) use ($q) { $v = $q("SELECT MIN(ts) v FROM incident_log WHERE incident_id=? AND kind='$k'")[0]['v'] ?? null; return $v === null ? null : (int)$v; };
  $plan = $inc['plan_id'] ? ($db->query('SELECT title FROM ex_plans WHERE id=' . (int)$inc['plan_id'])->fetchColumn() ?: null) : null;
  return ['inc' => ['id' => $id, 'message' => $c ? anonText($inc['message'], $c) : $inc['message'], 'alarm_at' => (int)$inc['alarm_at'], 'ended_at' => $inc['ended_at'] === null ? null : (int)$inc['ended_at'],
      'kind_name' => kindOf($inc)['name'], 'kind_color' => kindOf($inc)['color'], 'creator' => $nm($inc['created_by']), 'plan' => $plan, 'flow_ms' => $inc['flow_ms'] === null ? null : (float)$inc['flow_ms'],
      'lat' => $inc['lat'] === null ? null : (float)$inc['lat'], 'lon' => $inc['lon'] === null ? null : (float)$inc['lon'], 'first_accept' => $first('accept'), 'first_area' => $first('area')],
    'anon' => $anon, 'forced' => $forced, 'people' => $parts, 'rescuees' => $res, 'externals' => $ext, 'log' => $mine && !myBtns()['log'] ? [] : logRows($id, true, $anon, $self), 'my_anon' => $mine && myRuns() === 'anon',
    'data' => $a ? (json_decode($a['data'], true) ?: (object)[]) : (object)[], 'updated' => $a && $a['updated'] !== null ? (int)$a['updated'] : null, 'updated_by' => $a ? $nm($a['updated_by']) : null,
    'status' => $a ? (int)$a['status'] : 0, 'rel_at' => $a && $a['rel_at'] !== null ? (int)$a['rel_at'] : null, 'rel_by' => $a ? $nm($a['rel_by']) : null,
    'imgs' => array_map(fn($r) => ints($r, ['id', 'w', 'h']), $q("SELECT id,field_id field,caption,w,h FROM aar_img WHERE incident_id=? ORDER BY id"))]; } // Bilder ohne Inhalt, der kommt einzeln über aar/img
// Sicherung vor dem Anonymisieren: Ein freigegebenes Review bleibt als PDF vollständig erhalten (Tabelle aar_arch, Recht aar_arch). Das PDF entsteht im Browser (jsPDF), die automatische Anonymisierung läuft aber ohne Browser:
// Deshalb hält anonDo in derselben Transaktion zuerst den Inhalt fest (snap, Bilder in aar_arch_img); der nächste Browser mit dem Recht erzeugt daraus das PDF. Danach ist es unveränderlich (pdf_at), und snap und Bilder entfallen.
function aarArchive($inc) { global $db, $now, $my; $id = (int)$inc['id'];
  $s = $db->prepare("SELECT status FROM aar WHERE incident_id=?"); $s->execute([$id]); $st = $s->fetchColumn(); $s->closeCursor(); if ($st === false || (int)$st !== 1) return;
  $s = $db->prepare(($my ? 'INSERT IGNORE' : 'INSERT OR IGNORE') . " INTO aar_arch(incident_id,created) VALUES(?,?)"); $s->execute([$id, $now]); if (!$s->rowCount()) return; // die erste Sicherung bleibt
  $fin = (int)$inc['log_anon'] || $inc['rp_anon'] !== null; $doc = aarDoc($inc, null, false, $fin, false, null);
  $doc['anon'] = $fin; $doc['cfg'] = aarCfg(); $doc['logo'] = setting('aar_logo', null); // Einrichtung mit sichern: spätere Änderungen betreffen das Archiv nicht
  $db->prepare("UPDATE aar_arch SET snap=? WHERE incident_id=?")->execute([json_encode($doc, JSON_UNESCAPED_UNICODE), $id]);
  $db->prepare("INSERT INTO aar_arch_img(incident_id,img_id,field_id,caption,data,w,h) SELECT incident_id,id,field_id,caption,data,w,h FROM aar_img WHERE incident_id=?")->execute([$id]); }
function aarArchRow($id) { global $db; $s = $db->prepare("SELECT incident_id,created,pdf_at,pdf_parts,pdf_name,pdf_sha FROM aar_arch WHERE incident_id=?"); $s->execute([(int)$id]); $r = $s->fetch(); $s->closeCursor(); return $r ?: fail('Keine Sicherung für diesen Einsatz', 404); }
function aarCfg() { $c = json_decode((string)setting('aar_cfg', ''), true);
  if (!is_array($c)) return AAR_DEFAULT;
  $c += AAR_DEFAULT; $a = []; foreach (AAR_AUTO as $k => $_) $a[$k] = (int)($c['auto'][$k] ?? AAR_DEFAULT['auto'][$k]); $c['auto'] = $a;
  $c['dl'] = aarDlNorm((array)$c['dl']);
  return $c; }
// Einstellungen prüfen; neue Felder erhalten eine nie zuvor vergebene ID (seq), damit Werte entfernter Felder nie einem neuen Feld zufallen
function aarIn($in) { $old = aarCfg(); $s = fn($v, $n) => mb_substr(trim(preg_replace('/\s+/u', ' ', (string)$v)), 0, $n);
  $seq = (int)$old['seq']; $known = array_column($old['fields'], 'id'); $f = []; $ids = [];
  $fs = (array)($in['fields'] ?? []);
  if (count($fs) > 80) fail('Höchstens 80 Felder');
  foreach ($fs as $x) {
    if (!is_array($x)) fail('Ungültiges Feld');
    $t = (string)($x['type'] ?? ''); if (!isset(AAR_TYPES[$t])) fail('Ungültige Feldart');
    $l = $s($x['label'] ?? '', 150); if ($l === '') fail('Jedes Feld braucht eine Bezeichnung');
    $id = (string)($x['id'] ?? '');
    if (!in_array($id, $known, true) || isset($ids[$id])) $id = 'f' . ++$seq;
    $ids[$id] = 1; $r = ['id' => $id, 'type' => $t, 'label' => $l, 'hint' => $s($x['hint'] ?? '', 300)];
    if ($t === 'select') {
      $o = array_values(array_unique(array_filter(array_map(fn($v) => $s($v, 80), (array)($x['opts'] ?? [])), 'strlen')));
      if (!$o || count($o) > 30) fail("Auswahl «{$l}»: 1 bis 30 Werte angeben");
      $r['opts'] = $o;
    }
    $f[] = $r;
  }
  $a = []; foreach (AAR_AUTO as $k => $_) $a[$k] = (int)!empty(($in['auto'] ?? [])[$k]);
  $d = isset($in['dl']) ? aarDlNorm((array)$in['dl']) : $old['dl'];
  return ['title' => $s($in['title'] ?? '', 120) ?: AAR_DEFAULT['title'], 'org' => $s($in['org'] ?? '', 120), 'seq' => $seq, 'auto' => $a, 'dl' => $d, 'fields' => $f]; }
const FEATURES = ['area' => 'Suchgebiet', 'res' => 'Rettlinge', 'msg' => 'Nachrichten (mit Kartenmarkierungen)', 'poi' => 'Markierungen & Bereiche', 'ext' => 'Externe Einsatzkräfte',
                  'rd' => 'Werkzeug «Flussdistanz»', 'pub' => 'Öffentliche Ansicht', 'rec' => 'Aufzeichnung (Replay)', 'nav' => 'Navigation (Ziele an eine Navigations-App übergeben)', 'place' => 'Feste Standorte', 'hydro' => 'Pegel & Abfluss (BAFU)', 'pinfo' => 'Personeninfo (Infofenster beim Antippen einer Einsatzkraft)']; // Helikopter: eigener Hauptschalter und Schalter je Einsatz (airInc), nicht hier
// Navigations-Apps (Funktion «nav»): in der Verwaltung einzeln ausschaltbar (nav_off), Standard nav_default, pro Person users.nav_app
const NAV_APPS = ['google' => 'Google Maps', 'apple' => 'Apple Karten', 'waze' => 'Waze', 'geo' => 'Andere App (nur Android: Auswahl der Karten-Apps)'];
function navApps() { $off = json_decode(setting('nav_off', '[]'), true) ?: []; $a = array_values(array_diff(array_keys(NAV_APPS), $off)); return $a ?: ['google']; } // eingeschaltete, mindestens eine
function navDefault() { $a = navApps(); $d = setting('nav_default', 'google'); return in_array($d, $a, true) ? $d : $a[0]; }
const FEATURE_ROUTES = ['incident/mark' => 'res', 'incident/mark/remove' => 'res', 'incident/rescuee/save' => 'res', 'incident/rescuee/locate' => 'res', 'incident/rescuee/rescued' => 'res', 'incident/rescuee/remove' => 'res',
                        'incident/message' => 'msg', 'incident/message/unmark' => 'msg', 'incident/poi' => 'poi', 'incident/poi/remove' => 'poi',
                        'incident/external/save' => 'ext', 'incident/external/remove' => 'ext', 'incident/public' => 'pub', 'incident/record' => 'rec', 'incident/event' => 'area'];
const OVERPASS_KEEP_S = 7 * 86400; // abgelaufene Einträge so lange als Rückfall behalten, falls Overpass nicht erreichbar ist
/* ---------- Anonymisierung in Stufen, einstellbar und automatisch (Issue #87) ---------- */
// Stufen in dieser Reihenfolge: [Bezeichnung, Beschreibung, endgültig]. Einstellung anon_cfg je Stufe: on = angeboten (aus: weder von Hand noch automatisch),
// real/ex = automatisch so viele Tage nach dem Einsatzende (null = nie). Spalten: view_anon, rp_anon, log_anon, txt_anon, part_anon; «Aufzeichnung löschen» setzt recorded 0.
// Ohne gespeicherte Einstellung gelten die Fristen aus ANON_DEFAULT (Tage nach dem Einsatzende, [scharf, Übung]).
const ANON_STEPS = [
  'view' => ['Anzeige anonymisieren', 'Log, Replay und After Action Review zeigen für alle nur noch Bezeichnungen wie «Einsatzkraft 3», «Rettling 1» (in «Meine Einsätze» die Person selbst mit Namen). Die Daten bleiben unverändert, die Stufe lässt sich aufheben.', 0],
  'replay' => ['Replay pseudonymisieren', 'Die Standortverläufe verlieren die Verbindung zu den Benutzerkonten, das Replay zeigt nur noch Bezeichnungen (mit Einrückoption und Spezialrolle). Nur bei aufgezeichneten Einsätzen.', 1],
  'log' => ['Einsatzprotokoll pseudonymisieren', 'Log-Einträge und Nachrichten verlieren die Verbindung zu den Benutzerkonten, Namen im Text werden ersetzt, Beschreibungen der Rettlinge entfallen. Vornamen allein oder Spitznamen erkennt die Ersetzung nicht.', 1],
  'texts' => ['Personenangaben und Freitexte entfernen', 'Namen und Beschreibungen von Rettlingen und externen Einsatzkräften werden auch in den Einsatzdaten ersetzt, Bezeichnungen von Markierungen und Bereichen anonymisiert und Nachrichtentexte gelöscht. Pseudonymisiert vorher das Einsatzprotokoll.', 1],
  'parts' => ['Teilnahmen vom Konto lösen', 'Die Teilnahmen verlieren die Verbindung zu den Benutzerkonten: Der Einsatz fehlt danach in der Statistik pro Person und in «Meine Einsätze» der Teilnehmenden, Log, Replay und After Action Review zeigen nur noch Bezeichnungen. Pseudonymisiert vorher Replay und Einsatzprotokoll. Der Inhalt des After Action Reviews bleibt unverändert.', 1],
  'track' => ['Aufzeichnung löschen', 'Standortverläufe, aufgezeichnete Zeiträume und Helikopterspuren werden gelöscht, ein Replay gibt es danach nicht mehr.', 1],
];
const ANON_DEFAULT = ['view' => [90, 30], 'replay' => [180, 90], 'log' => [365, 180], 'texts' => [730, 365], 'parts' => [730, 365], 'track' => [365, 180]]; // steht vor den öffentlichen Routen (legal, Issue #102)
const EX_KINDS = ['pls' => 'PLS des Einsatzes', 'res' => 'Neuer Rettling', 'seen' => 'Rettling gesichtet', 'ext' => 'Neue externe Einsatzkraft', 'extdel' => 'Externe Einsatzkraft entfernen']; // Elemente vorbereiteter Übungen (Issue #29), steht vor public/state
/* ---------- Helikopter / Luftlage (Issue #73) ---------- */
// Positionen aus offenen ADS-B-Daten, immer über den Server (die Dienste erlauben keine Abfrage aus dem Browser, kein CORS), mit Datei-Cache je Einsatz
// in api/data/air/ (airServe). Alle Quellen sind gratis und ohne Schlüssel, aber nur für nicht-kommerzielle Nutzung gedacht (Vermerk in der Verwaltung).
// Steht vor public/state (public/air). Einstellung air = JSON, fehlende Werte aus AIR_DEFAULT (airCfg).
const AIR_SOURCES = [
  'adsbfi' => ['name' => 'adsb.fi (Open Data)', 'site' => 'https://adsb.fi', 'url' => 'https://opendata.adsb.fi/api/v3/lat/%s/lon/%s/dist/%d',
               'note' => 'Gratis, ohne Schlüssel, höchstens 1 Abfrage pro Sekunde. Nur für nicht-kommerzielle Nutzung.'],
  'adsblol' => ['name' => 'adsb.lol', 'site' => 'https://adsb.lol', 'url' => 'https://api.adsb.lol/v2/point/%s/%s/%d',
               'note' => 'Gratis, ohne Schlüssel. Daten unter der Open Database License (ODbL), Quellenangabe auf der Karte. Für nicht-kommerzielle Nutzung gedacht, Limits kann der Betreiber jederzeit ändern.'],
  'opensky' => ['name' => 'OpenSky Network', 'site' => 'https://opensky-network.org', 'url' => 'https://opensky-network.org/api/states/all?extended=1&lamin=%s&lomin=%s&lamax=%s&lomax=%s',
               'note' => 'Gratis nur für nicht-kommerzielle Nutzung (Forschung, gemeinnützige Zwecke). Ohne Konto nur rund 400 Abfragen pro Tag, das reicht für einen Einsatz nicht lange. Deshalb standardmässig aus, als letzter Rückfall gedacht.'],
];
const AIR_FILTERS = ['rescue' => 'Nur Rettungshelikopter (Liste unten)', 'heli' => 'Alle Helikopter, Rettungshelikopter hervorgehoben', 'all' => 'Alle Luftfahrzeuge (auch Flugzeuge, z. B. Suchflüge)'];
const AIR_APP = ['all' => 'Alle angemeldeten Personen', 'part' => 'Teilnehmende und Einsatzleitung', 'lead' => 'Nur Einsatzleitung', 'off' => 'Niemand'];
// Zahlen: [Standard, Minimum, Maximum]
const AIR_NUM = ['radius_km' => [15, 1, 500], 'max_alt_ft' => [10000, 500, 60000], 'max_age_s' => [60, 10, 600], 'trail_min' => [5, 0, 60], 'cache_s' => [5, 2, 120], 'home_km' => [0, 0, 500]]; // home_km: ohne Einsatz um die festen Standorte (Issue #130), 0 = aus
const AIR_DEFAULT = ['on' => 1, 'src' => ['adsbfi', 'adsblol'], 'order' => ['adsbfi', 'adsblol', 'opensky'], 'filter' => 'heli', 'app' => 'all', 'public' => 0, 'replay' => 1, 'rescue' => [['k' => 'RGA', 'n' => 'Rega'], ['k' => 'AAA', 'n' => 'Alpine Air Ambulance']]];
const AIR_MAX_M = 463000; // grösster Abfrageradius der Dienste (250 Seemeilen); ein grösserer Umkreis wird nur bis dahin abgefragt
const AIR_BACKOFF_S = 20; // nach einem Fehlschlag aller Quellen so lange nicht erneut abfragen
const AIR_REPLAY_S = 5;   // Aufzeichnung fürs Replay: höchstens ein Punkt je Luftfahrzeug in diesem Abstand
function airCfg() { $v = json_decode(setting('air', ''), true); $v = is_array($v) ? $v : []; $c = AIR_DEFAULT; $ks = array_keys(AIR_SOURCES);
  $c['order'] = array_values(array_unique(array_merge(array_values(array_intersect(array_filter((array)($v['order'] ?? []), 'is_string'), $ks)), AIR_DEFAULT['order'], $ks)));
  $c['src'] = array_values(array_intersect($c['order'], is_array($v['src'] ?? null) ? $v['src'] : AIR_DEFAULT['src'])); // eingeschaltete in der Reihenfolge
  foreach (['filter' => AIR_FILTERS, 'app' => AIR_APP] as $k => $o) if (is_string($v[$k] ?? null) && isset($o[$v[$k]])) $c[$k] = $v[$k];
  foreach (AIR_NUM as $k => [$d, $lo, $hi]) $c[$k] = is_numeric($v[$k] ?? null) ? (int)min(max((float)$v[$k], $lo), $hi) : $d;
  foreach (['on', 'public', 'replay'] as $k) if (isset($v[$k])) $c[$k] = $v[$k] ? 1 : 0;
  if (is_array($v['rescue'] ?? null)) $c['rescue'] = airRescueIn($v['rescue'], false);
  return $c; }
// Liste der Rettungshelikopter: k = Anfang des Rufzeichens oder Kennzeichens (z. B. RGA, HB-Z) bzw. Transponder-Adresse (6 Hex-Zeichen), n = Bezeichnung
function airRescueIn($a, $strict) { $o = [];
  foreach (array_slice((array)$a, 0, 60) as $x) {
    $k = strtoupper(trim((string)($x['k'] ?? ''))); $n = mb_substr(trim((string)($x['n'] ?? '')), 0, 40);
    if (!preg_match('/^[A-Z0-9-]{2,8}$/', $k) || $n === '') { if ($strict) fail('Ungültiger Eintrag in der Liste der Rettungshelikopter: «' . mb_substr($k ?: $n, 0, 20) . '» (Kennung 2–8 Zeichen A–Z, 0–9, «-», Bezeichnung nötig)'); continue; }
    $o[] = ['k' => $k, 'n' => $n]; }
  return $o; }
// Einstellung aus der Verwaltung prüfen (streng, Fehler statt stillem Korrigieren)
function airIn($v) { if (!is_array($v)) fail('Ungültige Einstellungen für Helikopter'); $ks = array_keys(AIR_SOURCES);
  $order = array_values(array_filter((array)($v['order'] ?? []), 'is_string')); $src = array_values(array_filter((array)($v['src'] ?? []), 'is_string'));
  if (array_diff($order, $ks) || array_diff($src, $ks)) fail('Unbekannte Datenquelle');
  if (!isset(AIR_FILTERS[$v['filter'] ?? '']) || !isset(AIR_APP[$v['app'] ?? ''])) fail('Ungültige Auswahl');
  $o = ['order' => array_values(array_unique(array_merge($order, $ks))), 'src' => array_values(array_unique($src)), 'filter' => $v['filter'], 'app' => $v['app'], 'public' => empty($v['public']) ? 0 : 1, 'replay' => empty($v['replay']) ? 0 : 1];
  $o['on'] = empty($v['on']) ? 0 : 1;
  foreach (AIR_NUM as $k => [$d, $lo, $hi]) { if (!is_numeric($v[$k] ?? null) || $v[$k] < $lo || $v[$k] > $hi) fail("Wert ausserhalb des Bereichs ($lo–$hi): $k"); $o[$k] = (int)$v[$k]; }
  $o['rescue'] = airRescueIn($v['rescue'] ?? [], true);
  return $o; }
// Rettungshelikopter? Bezeichnung aus der Liste oder null
function airRescue($a, $c) { $cs = strtoupper($a['cs'] ?? ''); $rg = strtoupper((string)($a['reg'] ?? ''));
  foreach ($c['rescue'] as $x) if (($cs !== '' && str_starts_with($cs, $x['k'])) || ($rg !== '' && str_starts_with($rg, $x['k'])) || strtolower($x['k']) === $a['hex']) return $x['n'];
  return null; }
// passt zum Filter der Verwaltung (Kategorie A7 = Rotorcraft nach ADS-B)
function airKind($a, $c) { return $c['filter'] === 'all' || airRescue($a, $c) !== null || ($c['filter'] === 'heli' && $a['cat'] === 'A7'); }
// Antworten der Quellen auf ein gemeinsames Format: hex, cs (Rufzeichen), reg, type, desc, cat, lat, lon, alt (ft, barometrisch), gnd, gs (kt), trk (Grad), vr (ft/min), at (Zeit der Position)
function airNorm($src, $j) { $out = []; $str = fn($x, $n = 40) => is_string($x) && trim($x) !== '' ? mb_substr(trim($x), 0, $n) : null; $num = fn($x) => is_int($x) || is_float($x);
  if ($src === 'opensky') { if (!array_key_exists('states', $j)) return null; $t0 = (int)($j['time'] ?? time());
    foreach ((array)$j['states'] as $s) { if (!is_array($s) || !$num($s[5] ?? null) || !$num($s[6] ?? null) || !is_string($s[0] ?? null)) continue;
      $out[] = ['hex' => strtolower($s[0]), 'cs' => $str($s[1] ?? null, 12), 'reg' => null, 'type' => null, 'desc' => null, 'cat' => ($s[17] ?? null) === 8 ? 'A7' : null, 'lat' => (float)$s[6], 'lon' => (float)$s[5],
                'alt' => $num($s[7] ?? null) ? (int)round($s[7] / 0.3048) : null, 'gnd' => !empty($s[8]), 'gs' => $num($s[9] ?? null) ? round($s[9] * 1.943844, 1) : null, 'trk' => $num($s[10] ?? null) ? (int)round($s[10]) : null,
                'vr' => $num($s[11] ?? null) ? (int)round($s[11] * 196.85) : null, 'at' => $num($s[3] ?? null) ? (int)$s[3] : $t0]; }
    return $out; }
  $ac = $j['ac'] ?? $j['aircraft'] ?? null; if (!is_array($ac)) return null;
  $now = $num($j['now'] ?? null) ? (float)$j['now'] : time(); if ($now > 1e11) $now /= 1000; // adsb.lol und v3: Millisekunden
  foreach ($ac as $a) { if (!is_array($a) || !$num($a['lat'] ?? null) || !$num($a['lon'] ?? null) || !is_string($a['hex'] ?? null)) continue;
    $alt = $a['alt_baro'] ?? null; $gnd = $alt === 'ground';
    $out[] = ['hex' => strtolower(ltrim($a['hex'], '~')), 'cs' => $str($a['flight'] ?? null, 12), 'reg' => $str($a['r'] ?? null, 12), 'type' => $str($a['t'] ?? null, 8), 'desc' => $str($a['desc'] ?? null), 'cat' => $str($a['category'] ?? null, 4),
              'lat' => (float)$a['lat'], 'lon' => (float)$a['lon'], 'alt' => $gnd ? 0 : ($num($alt) ? (int)$alt : ($num($a['alt_geom'] ?? null) ? (int)$a['alt_geom'] : null)), 'gnd' => $gnd,
              'gs' => $num($a['gs'] ?? null) ? round($a['gs'], 1) : null, 'trk' => $num($a['track'] ?? null) ? (int)round($a['track']) : ($num($a['true_heading'] ?? null) ? (int)round($a['true_heading']) : null),
              'vr' => $num($a['baro_rate'] ?? null) ? (int)$a['baro_rate'] : ($num($a['geom_rate'] ?? null) ? (int)$a['geom_rate'] : null), 'at' => (int)round($now - ($num($a['seen_pos'] ?? null) ? $a['seen_pos'] : ($num($a['seen'] ?? null) ? $a['seen'] : 0)))]; }
  return $out; }
function airGet($url) {
  if (!function_exists('curl_init')) { $r = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 6, 'ignore_errors' => true, 'header' => "User-Agent: SwiftwaterRescue/0.1\r\n"]]));
    return [preg_match('/^HTTP\/\S+ (\d{3})/', $http_response_header[0] ?? '', $m) ? (int)$m[1] : 0, $r === false ? null : $r, $r === false ? 'Keine Antwort' : '']; }
  $c = curl_init($url); curl_setopt_array($c, CURL_CA + [CURLOPT_RETURNTRANSFER => 1, CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_ENCODING => '', CURLOPT_USERAGENT => 'SwiftwaterRescue/0.1']);
  $r = curl_exec($c); $code = curl_getinfo($c, CURLINFO_HTTP_CODE); $err = $r === false ? (curl_errno($c) === CURLE_OPERATION_TIMEDOUT ? 'Zeitüberschreitung' : curl_error($c)) : ''; 
  return [$code, $r === false ? null : $r, $err]; }
// Abruf je Quelle für die Anzeige in der Verwaltung (api/data/air/status.json); darf die Abfrage nie stören
function airStat($k, $ok, $ms, $err, $n) { if (!$ok) alog('warn', 'air', 'Quelle ohne Antwort: ' . (AIR_SOURCES[$k]['name'] ?? $k), ['err' => $err === null ? null : mb_substr((string)$err, 0, 200), 'ms' => (int)$ms]); try { $f = __DIR__ . '/data/air/status.json'; if (!is_dir(dirname($f))) @mkdir(dirname($f), 0775, true); $s = json_decode((string)@file_get_contents($f), true) ?: []; $x = $s[$k] ?? ['uses' => 0, 'fails' => 0];
  $x['uses']++; if ($ok) { $x['last_ok'] = time(); $x['ms'] = (int)$ms; $x['n'] = $n; } else { $x['fails']++; $x['last_fail'] = time(); $x['err'] = mb_substr((string)$err, 0, 150); }
  $s[$k] = $x; @file_put_contents("$f.tmp", json_encode($s)); @rename("$f.tmp", $f); } catch (Throwable $e) {} }
// erste Quelle mit gültiger Antwort für den Kreis (la, lo, rm Meter): [Quelle, Liste, abgedeckter Radius] oder null
function airFetch($srcs, $la, $lo, $rm) { $f = fn($x) => sprintf('%.4F', $x);
  $nm = (int)min(AIR_MAX_M / 1852, max(5, ceil($rm / 1852 / 5) * 5)); // Radius in Seemeilen, auf 5 aufgerundet (höchstens 250, Grenze der Dienste)
  foreach ($srcs as $k) { $S = AIR_SOURCES[$k];
    if ($k === 'opensky') { $d = $nm * 1852; $dla = $d / 111320; $dlo = $d / (111320 * max(.1, cos(deg2rad($la)))); $url = sprintf($S['url'], $f($la - $dla), $f($lo - $dlo), $f($la + $dla), $f($lo + $dlo)); }
    else $url = sprintf($S['url'], $f($la), $f($lo), $nm);
    $t = microtime(true); [$code, $body, $err] = airGet($url);
    $j = $code === 200 && $body !== null ? json_decode($body, true) : null; $list = is_array($j) ? airNorm($k, $j) : null;
    airStat($k, $list !== null, (microtime(true) - $t) * 1000, $err ?: ($code && $code !== 200 ? "HTTP $code" : ($code ? 'Ungültige Antwort' : 'Keine Antwort')), $list === null ? null : count($list));
    if ($list !== null) return [$k, $list, $nm * 1852]; }
  return null; }
// Relevanzzone: Kreise [lat, lon, Radius m, Art] um alle aktiven Positionen des Einsatzes. Um den PLS zusätzlich die Driftdistanz
// (Fliessgeschwindigkeit × Zeit seit dem PLS, höchstens 30 km wie das Gewässernetz), damit das ganze Suchgebiet abgedeckt ist.
function airZone($inc) { global $db, $now; $c = airCfg(); $R = $c['radius_km'] * 1000; $id = (int)$inc['id']; $z = []; $flow = $inc['flow_ms'] === null ? 0.0 : (float)$inc['flow_ms'];
  $q = function ($sql) use ($db, $id) { $s = $db->prepare($sql); $s->execute([$id]); return $s->fetchAll(); };
  foreach (plsSources($inc, incRes($inc)) as $s) $z[] = [$s['lat'], $s['lon'], $R + min(30000, max(0, $flow * ($now - $s['at']))), 'pls'];
  foreach ($q("SELECT lat,lon FROM participants WHERE incident_id=? AND mode<>'declined' AND left_at IS NULL AND lat IS NOT NULL") as $p) $z[] = [(float)$p['lat'], (float)$p['lon'], $R, 'pos'];
  if (feature($inc, 'ext')) foreach (incExternals($id) as $e) $z[] = [$e['lat'], $e['lon'], $R, 'pos'];
  if (feature($inc, 'res')) foreach (incRescuees($id) as $r) if ($r['lat'] !== null && $r['rescued_at'] === null) $z[] = [$r['lat'], $r['lon'], $R, 'pos'];
  if (feature($inc, 'poi')) foreach ($q("SELECT pts FROM pois WHERE incident_id=? AND removed IS NULL") as $p) foreach (json_decode($p['pts'], true) ?: [] as $pt) if (is_array($pt) && count($pt) >= 2) $z[] = [(float)$pt[0], (float)$pt[1], $R, 'pos'];
  return $z; }
// Abfragekreise (Issue #107): Die Dienste liefern höchstens AIR_MAX_M um einen Punkt. Nahe Kreise der Zone werden zu einem zusammengefasst
// (umschliessender Kreis nicht grösser als beide zusammen), weit auseinanderliegende Gebiete einzeln abgefragt, höchstens AIR_MAX_Q Abfragen.
const AIR_MAX_Q = 6;
function airGroups($z) { $g = [];
  $join = function ($a, $b) { $d = hav($a[0], $a[1], $b[0], $b[1]); if ($d + $b[2] <= $a[2]) return $a; if ($d + $a[2] <= $b[2]) return $b;
    $t = (($d + $a[2] + $b[2]) / 2 - $a[2]) / $d; $la = $a[0] + ($b[0] - $a[0]) * $t; $lo = $a[1] + ($b[1] - $a[1]) * $t; // Mitte auf der Verbindungslinie
    return [$la, $lo, max(hav($la, $lo, $a[0], $a[1]) + $a[2], hav($la, $lo, $b[0], $b[1]) + $b[2])]; };
  foreach ($z as [$la, $lo, $r]) { foreach ($g as $x) if (hav($x[0], $x[1], $la, $lo) + $r <= $x[2]) continue 2; $g[] = [$la, $lo, $r]; } // gleiche bzw. enthaltene weglassen
  while (count($g) > 1) { $bi = $bj = $bm = null; $fi = $fj = $fm = null; $n = count($g);
    for ($i = 0; $i < $n; $i++) for ($j = $i + 1; $j < $n; $j++) { $m = $join($g[$i], $g[$j]);
      if ($fm === null || $m[2] < $fm[2]) [$fi, $fj, $fm] = [$i, $j, $m]; // kleinster gemeinsamer Kreis, falls es zu viele Abfragen wären
      if ($m[2] <= min(AIR_MAX_M, $g[$i][2] + $g[$j][2]) && ($bm === null || $m[2] < $bm[2])) [$bi, $bj, $bm] = [$i, $j, $m]; }
    if ($bm === null) { if ($n <= AIR_MAX_Q) break; [$bi, $bj, $bm] = [$fi, $fj, $fm]; }
    $g[$bi] = $bm; array_splice($g, $bj, 1); }
  return $g; }
function airInZone($a, $z) { foreach ($z as [$la, $lo, $r]) if (hav($la, $lo, $a['lat'], $a['lon']) <= $r) return true; return false; }
// sichtbar: Filter, Höhe, Alter und Relevanzzone
function airShow($a, $c, $z) { global $now; return airKind($a, $c) && ($a['gnd'] || $a['alt'] === null || $a['alt'] <= $c['max_alt_ft']) && $now - $a['at'] <= $c['max_age_s'] && airInZone($a, $z); }
// Lage für einen laufenden Einsatz: aus dem Cache (Datei je Einsatz, gültig cache_s und solange er die Zone abdeckt), sonst neu bei der ersten erreichbaren Quelle.
// Holt gerade ein anderer Request (Sperre belegt), gibt es ohne Warten den bisherigen Stand. Der Cache hält auch die Spuren (trail_min) und zeichnet fürs Replay auf.
function airServe($inc) { return airServeZ(airZone($inc), 'inc' . (int)$inc['id'], $inc, 'Noch kein Bezugspunkt: Helikopter erscheinen, sobald ein PLS oder Standorte bekannt sind.'); }
// Ohne Einsatz (Issue #130): Umkreis home_km um die aktiven permanenten festen Standorte (Bereiche: Schwerpunkt und Eckpunkte), 0 = aus; Cache api/data/air/home.json, keine Aufzeichnung
function airHomeZone() { $c = airCfg(); if (!$c['on'] || !$c['home_km']) return []; $R = $c['home_km'] * 1000; $z = [];
  foreach (placesOf(null) as $p) { if (!$p['active']) continue; foreach (array_merge([[$p['lat'], $p['lon']]], $p['pts'] ?: []) as $pt) $z[] = [(float)$pt[0], (float)$pt[1], $R, 'place', $p['name']]; }
  return $z; }
// Helikopter ohne Einsatz für alle Angemeldeten, solange die Einsatz-App sie überhaupt zeigt (app nicht off), eine Quelle an ist und es feste Standorte gibt
function airHomeOn() { $c = airCfg(); return $c['on'] && $c['home_km'] > 0 && $c['app'] !== 'off' && $c['src'] && airHomeZone(); }
// Lage für eine Zone: key = Name der Cache-Datei, inc = laufender Einsatz (Aufzeichnung fürs Replay) oder null; Distanz zum nächsten PLS (pls_m) bzw. festen Standort (near)
function airServeZ($z, $key, $inc, $info) { global $now, $db; $c = airCfg(); $id = $inc ? (int)$inc['id'] : 0;
  $base = ['air' => [], 'radius_km' => $inc ? $c['radius_km'] : $c['home_km'], 'now' => $now];
  if (!$c['src']) return $base + ['err' => 'Keine Datenquelle eingeschaltet (Verwaltung → Helikopter)'];
  if (!$z) return $base + ['info' => $info];
  $qs = airGroups($z);
  $dir = __DIR__ . '/data/air'; if (!is_dir($dir)) @mkdir($dir, 0775, true); $file = "$dir/$key.json";
  $read = function () use ($file) { clearstatcache(true, $file); $d = is_file($file) ? json_decode((string)@file_get_contents($file), true) : null; return is_array($d) ? $d : null; };
  // gültig, solange jeder Abfragekreis in einem abgefragten liegt (cov); grösser als die Grenze der Dienste: deckt der Cache das Abfragbare ab, gilt er
  $ok = fn($d) => $d && isset($d['at'], $d['cov']) && $now - (int)$d['at'] < $c['cache_s'] && !array_filter($qs, function ($q) use ($d) { foreach ($d['cov'] as $v) if (hav($v[0], $v[1], $q[0], $q[1]) + min($q[2], AIR_MAX_M * .9) <= $v[2]) return false; return true; });
  $wait = fn($d) => $d && !empty($d['fail_at']) && $now - (int)$d['fail_at'] < AIR_BACKOFF_S;
  $d = $read();
  if (!$ok($d) && !$wait($d) && ($lk = @fopen("$file.lock", 'c')) && flock($lk, LOCK_EX | LOCK_NB)) {
    $d = $read();
    if (!$ok($d) && !$wait($d)) { set_time_limit(60);
      // je Abfragekreis etwas grösser, damit kleine Verschiebungen der Zone den Cache nicht ungültig machen; Abstand ≥ 1 s (Limit von adsb.fi)
      $all = []; $cov = []; $k = null; $miss = 0; $t0 = 0;
      foreach ($qs as [$la, $lo, $rm]) { if ($t0 && ($w = 1.05 - (microtime(true) - $t0)) > 0) usleep((int)($w * 1e6)); $t0 = microtime(true);
        $r = airFetch($c['src'], $la, $lo, $rm * 1.1); if (!$r) { $miss++; continue; }
        $k = $k ?? $r[0]; $cov[] = [$la, $lo, $r[2]]; foreach ($r[1] as $a) if (!isset($all[$a['hex']]) || $a['at'] > $all[$a['hex']]['at']) $all[$a['hex']] = $a; }
      if ($k !== null) { $list = array_values($all); $hist = $d['hist'] ?? []; $keep = max(120, $c['trail_min'] * 60); $ac = [];
        $rec = $inc && (int)$inc['record'] === 1 && $c['replay'] && feature($inc, 'rec');
        $ins = $rec ? $db->prepare("INSERT INTO air_track(incident_id,hex,ts,lat,lon,alt,trk,cs,reg,type,cat) VALUES(?,?,?,?,?,?,?,?,?,?,?)") : null;
        foreach ($list as $a) { if (!airKind($a, $c) || !preg_match('/^[0-9a-f]{6}$/', $a['hex'])) continue; $ac[$a['hex']] = $a;
          $h = $hist[$a['hex']] ?? []; $last = $h ? end($h) : null;
          if (!$last || $a['at'] > $last[0]) { $h[] = [$a['at'], round($a['lat'], 6), round($a['lon'], 6), $a['alt']]; $hist[$a['hex']] = $h;
            if ($ins && airShow($a, $c, $z) && (!$last || $a['at'] - ($d['rec'][$a['hex']] ?? 0) >= AIR_REPLAY_S)) { $ins->execute([$id, $a['hex'], $a['at'], $a['lat'], $a['lon'], $a['gnd'] ? 0 : $a['alt'], $a['trk'], $a['cs'], $a['reg'], $a['type'], $a['cat']]); $d['rec'][$a['hex']] = $a['at']; } } }
        foreach ($hist as $hx => $h) { $h = array_values(array_filter($h, fn($p) => $now - $p[0] <= $keep)); if ($h) $hist[$hx] = $h; else unset($hist[$hx], $d['rec'][$hx]); }
        $d = ['at' => $now, 'cov' => $cov, 'src' => $k, 'ac' => array_values($ac), 'hist' => $hist, 'rec' => $d['rec'] ?? []];
        if ($miss) { $d['fail_at'] = $now; $d['part'] = 1; } // nicht alle Gebiete erhalten: Pause wie bei einem Fehlschlag, Daten der übrigen bleiben
      } else { $d = $d ?: ['ac' => [], 'hist' => []]; $d['fail_at'] = $now; unset($d['part']); } // alle Quellen fehlgeschlagen: bisherige Daten behalten, Pause AIR_BACKOFF_S
      if (file_put_contents("$file.tmp", json_encode($d)) !== false) rename("$file.tmp", $file); }
    flock($lk, LOCK_UN); }
  if (!$d) return $base + ['info' => 'Luftlage wird geladen …'];
  $pls = array_values(array_filter($z, fn($x) => $x[3] === 'pls')); $pl = array_values(array_filter($z, fn($x) => $x[3] === 'place')); $out = [];
  foreach ($d['ac'] ?? [] as $a) { if (!airShow($a, $c, $z)) continue;
    $dist = null; foreach ($pls as [$pa, $po]) { $x = hav($pa, $po, $a['lat'], $a['lon']); $dist = $dist === null ? $x : min($dist, $x); }
    $near = null; foreach ($pl as $p) { $x = hav($p[0], $p[1], $a['lat'], $a['lon']); if (!$near || $x < $near['m']) $near = ['n' => $p[4], 'm' => (int)round($x)]; }
    $tr = $c['trail_min'] ? array_values(array_filter($d['hist'][$a['hex']] ?? [], fn($p) => $now - $p[0] <= $c['trail_min'] * 60)) : [];
    $out[] = $a + ['rescue' => airRescue($a, $c), 'pls_m' => $dist === null ? null : (int)round($dist), 'near' => $near, 'trail' => $tr]; }
  usort($out, fn($x, $y) => [$x['rescue'] === null, $x['pls_m'] ?? $x['near']['m'] ?? PHP_INT_MAX] <=> [$y['rescue'] === null, $y['pls_m'] ?? $y['near']['m'] ?? PHP_INT_MAX]); // Rettungshelikopter zuerst, dann nach Distanz
  $S = AIR_SOURCES[$d['src'] ?? ''] ?? null;
  return ['air' => $out, 'at' => isset($d['at']) ? (int)$d['at'] : null, 'src' => $S ? ['name' => $S['name'], 'site' => $S['site']] : null, 'err' => !empty($d['fail_at']) && $now - (int)$d['fail_at'] < 60 ? (empty($d['part']) ? 'Keine Datenquelle erreichbar, neuer Versuch folgt' : 'Nicht für alle Einsatzgebiete erreichbar, neuer Versuch folgt') : null, 'radius_km' => $base['radius_km'], 'now' => $now]; }
// Helikopter für diesen Einsatz an? Hauptschalter (on) und Schalter je Einsatz (incidents.air, beim Start gesetzt; NULL = Vorauswahl des Einsatztyps)
function airDef($kid) { $c = airCfg(); return $c['on'] ? (int)(kinds()[(int)$kid]['air'] ?? 0) : 0; }
function airInc($inc) { $c = airCfg(); if (!$c['on']) return false; $v = $inc['air'] ?? null; return $v === null || $v === '' ? (bool)airDef($inc['kind_id'] ?? 0) : (bool)(int)$v; }
// Wer sieht die Helikopter in der Einsatz-App (Einstellung app)
function airAllowed($inc) { $a = airCfg()['app']; if (!airInc($inc) || $a === 'off') return false; if ($a === 'all') return true;
  if (canManage($inc)) return true; return $a === 'part' && isActivePart((int)$inc['id']); }
// Replay: aufgezeichnete Luftfahrzeuge je Transponder-Adresse, Punkte [0, ts, lat, lon, alt, trk] (Zeit an Stelle 1 wie bei track)
function airReplay($id) { global $db; $c = airCfg(); if (!$c['replay']) return [];
  $s = $db->prepare("SELECT hex,ts,lat,lon,alt,trk,cs,reg,type,cat FROM air_track WHERE incident_id=? ORDER BY hex,ts,id"); $s->execute([$id]); $o = [];
  foreach ($s->fetchAll() as $r) { $h = $r['hex'];
    if (!isset($o[$h])) { $a = ['hex' => $h, 'cs' => $r['cs'], 'reg' => $r['reg'], 'type' => $r['type'], 'cat' => $r['cat']]; $o[$h] = $a + ['rescue' => airRescue($a, $c), 'pts' => []]; }
    foreach (['cs', 'reg', 'type'] as $k) if ($r[$k] !== null) $o[$h][$k] = $r[$k];
    $o[$h]['pts'][] = [0, (int)$r['ts'], (float)$r['lat'], (float)$r['lon'], $r['alt'] === null ? null : (int)$r['alt'], $r['trk'] === null ? null : (int)$r['trk']]; }
  return array_values($o); }

/* ---------- Pegel und Abfluss (Issue #125) ---------- */
// Messstationen und aktuelle Werte der Hydrologie des BAFU direkt von hydrodaten.admin.ch (offene Daten, Quellenangabe «BAFU»), immer über den Server mit Datei-Cache
// in api/data/hydro/bafu.json: Stationen und Messwerte aller Stationen in einem Abruf alle HYDRO_TTL (hydroBafu, hydroLocs, hydroLatest). Funktion «hydro» je Einsatztyp.
// Einsatz: zu Beginn (sobald ein PLS bekannt ist, Auslöser im Polling) und am Ende die Werte der nächsten Stationen um die PLS ins Log und in inc_hydro (hydroLog).
// Persönliche Stationen: users.hydro (JSON-Liste der Stations-IDs), im Menü der Einsatz-App und auf der Karte. Steht vor legal.
const HYDRO_API = 'https://api.existenz.ch/apiv1/hydro/'; // Schnittstelle (Quelle «api»)
const HYDRO_Q = 'app=swiftwater.rescue&version=0.1';
const HYDRO_WEB = 'https://www.hydrodaten.admin.ch/web-hydro-maps/'; // GeoJSON der Karte auf hydrodaten.admin.ch (Quelle «web»)
// Quellen in der Reihenfolge des Vorrangs, Angaben für Verwaltung und legal.html
const HYDRO_SOURCES = [
  'api' => ['name' => 'api.existenz.ch (Schnittstelle)', 'site' => 'https://api.existenz.ch', 'lic' => 'Offene Daten des BAFU, Quellenangabe «BAFU»',
            'note' => 'Gratis, ohne Schlüssel, privat betrieben (Existenz.ch), stellt die Daten des BAFU als Schnittstelle bereit: Abfluss, Pegel, Wassertemperatur, ohne Gefahrenstufe. Kein Anspruch auf Verfügbarkeit. Hat Vorrang.'],
  'web' => ['name' => 'hydrodaten.admin.ch (Webseite des BAFU)', 'site' => 'https://www.hydrodaten.admin.ch', 'lic' => 'Offene Daten des BAFU, Quellenangabe «BAFU»',
            'note' => 'Daten der Karte auf der Webseite des BAFU (keine dokumentierte Schnittstelle, der Aufbau kann sich ohne Ankündigung ändern), mit Gefahrenstufe und Einordnung. Ergänzt die Schnittstelle und springt ein, wenn sie nicht antwortet.'],
];
const HYDRO_OLD = 86400; // ältere Messwerte gelten als nicht aktuell
const HYDRO_TTL = 300; // Messwerte zwischenspeichern (BAFU misst alle 10 Minuten)
const HYDRO_MAX = 20;  // persönliche Messstationen je Person
const HYDRO_NUM = ['radius_km' => [15, 1, 100], 'n' => [3, 1, 10]]; // [Standard, Minimum, Maximum]: Umkreis um jeden PLS, Stationen je PLS
const HYDRO_DEFAULT = ['radius_km' => 15, 'n' => 3, 'lakes' => 0, 'src' => ['api' => 1, 'web' => 1]];
const HYDRO_ID = '/^[A-Za-z0-9_-]{1,12}$/';
function hydroCfg() { $v = json_decode(setting('hydro', ''), true); $v = is_array($v) ? $v : []; $c = HYDRO_DEFAULT;
  foreach (HYDRO_NUM as $k => [$d, $lo, $hi]) if (is_numeric($v[$k] ?? null)) $c[$k] = (int)min(max((float)$v[$k], $lo), $hi);
  if (isset($v['lakes'])) $c['lakes'] = $v['lakes'] ? 1 : 0;
  foreach (HYDRO_SOURCES as $k => $x) if (isset($v['src'][$k])) $c['src'][$k] = $v['src'][$k] ? 1 : 0;
  return $c; }
function hydroIn($v) { if (!is_array($v)) fail('Ungültige Einstellung'); $o = [];
  foreach (HYDRO_NUM as $k => [$d, $lo, $hi]) { if (!is_numeric($v[$k] ?? null) || $v[$k] < $lo || $v[$k] > $hi) fail("Wert ausserhalb des Bereichs ($lo–$hi)"); $o[$k] = (int)$v[$k]; }
  $o['lakes'] = empty($v['lakes']) ? 0 : 1; $o['src'] = [];
  foreach (HYDRO_SOURCES as $k => $x) $o['src'][$k] = isset($v['src'][$k]) ? (empty($v['src'][$k]) ? 0 : 1) : hydroCfg()['src'][$k];
  return $o; }
// Datei-Cache mit Sperre: gültig ttl Sekunden, sonst neu holen (fetch liefert Daten oder null); bei Fehlschlag die bisherigen Daten und eine Minute Pause
function hydroFile($name, $ttl, $fetch) { $dir = __DIR__ . '/data/hydro'; if (!is_dir($dir)) @mkdir($dir, 0775, true); $f = "$dir/$name.json";
  $rd = function () use ($f) { clearstatcache(true, $f); $d = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null; return is_array($d) ? $d : null; };
  $fresh = fn($d) => $d && (time() - (int)($d['at'] ?? 0) < $ttl || time() - (int)($d['fail_at'] ?? 0) < 60);
  $d = $rd(); if ($fresh($d)) return $d;
  $lk = @fopen("$f.lock", 'c'); if ($lk) flock($lk, LOCK_EX);
  $d = $rd();
  if (!$fresh($d)) { $t = microtime(true); $n = $fetch();
    $d = $n !== null ? ['at' => time(), 'data' => $n, 'ms' => (int)((microtime(true) - $t) * 1000)] : ($d ?: ['at' => 0, 'data' => null]) + ['fail_at' => time()];
    if ($n === null) $d['fail_at'] = time();
    if (file_put_contents("$f.tmp", json_encode($d, JSON_UNESCAPED_UNICODE)) !== false) rename("$f.tmp", $f); }
  if ($lk) flock($lk, LOCK_UN);
  return $d; }
// Quellen (Feedback zu #125), je in der Verwaltung schaltbar: «api» = Schnittstelle api.existenz.ch (Vorrang), «web» = Daten der Karte auf hydrodaten.admin.ch.
// Ein Abruf alle HYDRO_TTL: zuerst die API; die Webseite ergänzt, was der API fehlt (Gefahrenstufe, Einordnung, Stationen), und springt ganz ein, wenn die API nicht antwortet.
// Werte der API gehen immer vor. Bei Fehlschlag aller Quellen gelten die bisherigen Daten. Stand je Quelle in api/data/hydro/status.json (hydroStat).
function hydroBafu() { return hydroFile('bafu', HYDRO_TTL, function () { $on = hydroCfg()['src']; $locs = []; $vals = []; $ok = false;
    foreach (array_keys(HYDRO_SOURCES) as $k) { if (empty($on[$k])) continue; $r = hydroSrc($k); if (!$r) continue; $ok = true;
      foreach ($r['locs'] as $i => $s) $locs[$i] ??= $s;
      foreach ($r['vals'] as $i => $v) $vals[$i] = ($vals[$i] ?? []) + $v; } // vorhandene Werte (aus der API) bleiben
    return $ok && $locs ? ['locs' => $locs, 'vals' => $vals] : null; }); }
// eine Quelle abfragen und den Stand festhalten: ['locs' => …, 'vals' => …] oder null
function hydroSrc($k) { $t = microtime(true); $err = null;
  try { $r = $k === 'api' ? hydroApi() : hydroWeb(); } catch (Throwable $e) { $r = null; $err = $e->getMessage(); }
  if (!$r && !$err) $err = 'keine gültige Antwort';
  if (!$r) alog('warn', 'hydro', 'Quelle ohne Antwort: ' . (HYDRO_SOURCES[$k]['name'] ?? $k), ['err' => mb_substr((string)$err, 0, 200)]); // Applikationslog (Issue #139)
  hydroStat($k, ['ok' => $r ? 1 : 0, 'ms' => (int)((microtime(true) - $t) * 1000), 'err' => $err, 'stations' => $r ? count($r['locs']) : 0, 'values' => $r ? count($r['vals']) : 0]);
  return $r; }
function hydroStat($k = null, $v = null) { $f = __DIR__ . '/data/hydro/status.json'; $d = json_decode((string)@file_get_contents($f), true) ?: [];
  if ($k === null) return $d; $v['at'] = time(); $v['ok_at'] = $v['ok'] ? time() : ($d[$k]['ok_at'] ?? null); $d[$k] = $v;
  @file_put_contents($f, json_encode($d, JSON_UNESCAPED_UNICODE), LOCK_EX); return $d; }
// API: Stationsliste 7 Tage zwischengespeichert, Messwerte aller Stationen in einer Abfrage
function hydroApi() {
  $d = hydroFile('api_locs', 7 * 86400, function () {
    $j = json_decode((string)http_get(HYDRO_API . 'locations?' . HYDRO_Q), true); if (!is_array($j['payload'] ?? null)) return null; $o = [];
    foreach ($j['payload'] as $k => $e) { $x = $e['details'] ?? null; if (!is_array($x) || !is_numeric($x['lat'] ?? null) || !is_numeric($x['lon'] ?? null)) continue;
      $id = (string)($x['id'] ?? $k); if (!preg_match(HYDRO_ID, $id)) continue;
      $o[$id] = ['id' => $id, 'name' => mb_substr((string)($x['name'] ?? $id), 0, 80), 'water' => mb_substr((string)($x['water-body-name'] ?? ''), 0, 80), 'type' => ($x['water-body-type'] ?? '') === 'lake' ? 'lake' : 'river', 'lat' => (float)$x['lat'], 'lon' => (float)$x['lon']]; }
    return $o ?: null; });
  $locs = is_array($d['data'] ?? null) ? $d['data'] : []; if (!$locs) return null;
  $j = json_decode((string)http_get(HYDRO_API . 'latest?' . HYDRO_Q), true); if (!is_array($j['payload'] ?? null)) return null; $o = []; $old = time() - HYDRO_OLD;
  foreach ($j['payload'] as $r) { $l = (string)($r['loc'] ?? ''); $p = (string)($r['par'] ?? ''); if ($l === '' || !is_numeric($r['val'] ?? null) || !is_numeric($r['timestamp'] ?? null) || $r['timestamp'] < $old) continue;
    $k = ['flow' => 'flow', 'flow_ls' => 'flow', 'height' => 'height', 'height_abs' => 'level', 'temperature' => 'temp'][$p] ?? null; if (!$k) continue;
    $v = (float)$r['val']; if ($p === 'flow_ls') { if (isset($o[$l]['flow'])) continue; $v /= 1000; }
    $o[$l][$k] = round($v, 4); $o[$l]['at'] = max((int)($o[$l]['at'] ?? 0), (int)$r['timestamp']); }
  return ['locs' => $locs, 'vals' => $o]; }
// Webseite: GeoJSON der Karte auf hydrodaten.admin.ch (LV95), Abfluss/Pegel mit Schwellen der Gefahrenstufen und Einordnung, dazu die Wassertemperatur
function hydroWeb() { $locs = []; $vals = [];
    $num = fn($x) => is_string($x) && preg_match('/^\s*(-?\d+(?:\.\d+)?)/', $x, $m) ? (float)$m[1] : (is_numeric($x) ? (float)$x : null);
    $ts = fn($x) => is_string($x) && ($t = strtotime($x)) ? $t : null; $old = time() - HYDRO_OLD;
    foreach (['pq', 'temperature'] as $n) { $j = json_decode((string)http_get(HYDRO_WEB . "hydro_sensor_$n.geojson"), true); if (!is_array($j['features'] ?? null)) return null;
      $leg = $j['meta']['de']['legend'] ?? [];
      foreach ($j['features'] as $f) { $p = $f['properties'] ?? []; $id = (string)($p['key'] ?? ''); $c = $f['geometry']['coordinates'] ?? null;
        if (!preg_match(HYDRO_ID, $id) || !is_array($c) || !is_numeric($c[0] ?? null) || !is_numeric($c[1] ?? null)) continue;
        if (!isset($locs[$id])) { [$la, $lo] = lv95ll((float)$c[0], (float)$c[1]); $w = trim((string)($p['hydro_body_name'] ?? '')); $lb = trim((string)($p['label'] ?? $p['name'] ?? $id));
          $nm = $w !== '' && str_starts_with($lb, "$w - ") ? substr($lb, strlen($w) + 3) : $lb;
          $locs[$id] = ['id' => $id, 'name' => mb_substr($nm, 0, 80), 'water' => mb_substr($w, 0, 80), 'type' => ($p['kind'] ?? '') === 'lake' || str_starts_with((string)($p['icon'] ?? ''), 'lake') ? 'lake' : 'river', 'lat' => round($la, 6), 'lon' => round($lo, 6)]; }
        if (!empty($p['failure_text'])) continue; $v = &$vals[$id]; $v = (array)$v;
        $put = function ($k, $x, $t) use (&$v, $old) { if ($x === null || !$t || $t < $old) return; $v[$k] = round($x, 4); $v['at'] = max((int)($v['at'] ?? 0), $t); };
        if ($n === 'temperature') { $put('temp', $num($p['last_value'] ?? null), $ts($p['last_measured_at'] ?? null)); unset($v); continue; }
        $q = $num($p['sensor_discharge_last_value'] ?? null); $qu = (string)($p['sensor_discharge_last_value'] ?? '');
        if ($q !== null && str_ends_with($qu, 'l/s')) $q /= 1000; $put('flow', $q, $ts($p['sensor_discharge_measured_at'] ?? null));
        $h = $num($p['sensor_waterlevel_last_value'] ?? null); $hu = (string)($p['sensor_waterlevel_last_value'] ?? '');
        $put(str_contains($hu, 'ü.M') ? 'height' : 'level', $h, $ts($p['sensor_waterlevel_measured_at'] ?? null));
        // Gefahrenstufe 1–5 aus den Schwellen wl_1 … wl_4 (gleiche Grösse wie der Hauptwert), Einordnung des BAFU (sehr tief … sehr hoch)
        $mv = ($p['metric'] ?? '') === 'masl' ? ($v['height'] ?? null) : ($v['flow'] ?? null);
        if ($mv !== null) { $g = 1; $ok = false; foreach ([1, 2, 3, 4] as $i) { $w = $num($p["wl_$i"] ?? null); if ($w === null) continue; $ok = true; if (str_ends_with((string)$p["wl_$i"], 'l/s')) $w /= 1000; if ($mv >= $w) $g = $i + 1; } if ($ok) $v['danger'] = $g; }
        if (isset($v['at']) && preg_match('/level_(\d)$/', (string)($p['icon'] ?? ''), $m) && isset($leg["level_$m[1]"])) $v['cls'] = mb_substr((string)$leg["level_$m[1]"], 0, 30);
        if (!$v) unset($vals[$id]); unset($v); } }
    return $locs ? ['locs' => $locs, 'vals' => $vals] : null; }
// Messstationen: {id: {id, name, water, type (river|lake), lat, lon}}
function hydroLocs() { $d = hydroBafu(); return is_array($d['data']['locs'] ?? null) ? $d['data']['locs'] : []; }
// aktuelle Werte je Station: flow (m³/s), height (Pegel m ü. M.), level (Pegel in m über dem Nullpunkt), temp (°C), danger (Gefahrenstufe 1–5), cls (Einordnung), at (Messzeit)
function hydroLatest() { $d = hydroBafu(); return [is_array($d['data']['vals'] ?? null) ? $d['data']['vals'] : [], $d]; }
// LV95 → WGS84 (Näherungsformeln von swisstopo, ca. 1 m)
function lv95ll($e, $n) { $y = ($e - 2600000) / 1e6; $x = ($n - 1200000) / 1e6;
  $lo = 2.6779094 + 4.728982 * $y + 0.791484 * $y * $x + 0.1306 * $y * $x * $x - 0.0436 * $y ** 3;
  $la = 16.9023892 + 3.238272 * $x - 0.270978 * $y * $y - 0.002528 * $x * $x - 0.0447 * $y * $y * $x - 0.0140 * $x ** 3;
  return [$la * 100 / 36, $lo * 100 / 36]; }
function hydroRow($s, $L) { $v = $L[$s['id']] ?? []; return $s + ['flow' => $v['flow'] ?? null, 'height' => $v['height'] ?? null, 'level' => $v['level'] ?? null, 'temp' => $v['temp'] ?? null, 'danger' => $v['danger'] ?? null, 'cls' => $v['cls'] ?? null, 'at' => $v['at'] ?? null]; }
function hydroName($r) { return ($r['water'] !== '' ? $r['water'] . ', ' : '') . $r['name']; }
function hydroNum($x, $d) { return number_format($x, $d, '.', '’'); }
function hydroText($r) { $p = [];
  if ($r['flow'] !== null) $p[] = 'Abfluss ' . hydroNum($r['flow'], $r['flow'] < 1 ? 3 : ($r['flow'] < 10 ? 2 : 1)) . ' m³/s';
  if ($r['height'] !== null) $p[] = 'Pegel ' . hydroNum($r['height'], 2) . ' m ü. M.';
  if ($r['level'] !== null) $p[] = 'Pegel ' . hydroNum($r['level'], 2) . ' m';
  if ($r['temp'] !== null) $p[] = 'Wassertemperatur ' . hydroNum($r['temp'], 1) . ' °C';
  if (($r['danger'] ?? null) !== null) $p[] = 'Gefahrenstufe ' . $r['danger'];
  if (!empty($r['cls'])) $p[] = (($r['type'] ?? '') === 'lake' ? 'Wasserstand ' : 'Abfluss ') . mb_strtolower($r['cls']);
  return $p ? implode(', ', $p) . ($r['at'] ? ', Messung ' . hm($r['at']) : '') : 'keine aktuellen Messwerte'; }
// Bezugspunkte des Einsatzes: PLS des Einsatzes und aller Rettlinge (auch gefundene: auch dort war das Wasser relevant)
function hydroRefs($inc) { $o = []; if ($inc['lat'] !== null) $o[] = [(float)$inc['lat'], (float)$inc['lon']];
  if (feature($inc, 'res')) foreach (incRescuees((int)$inc['id']) as $r) if ($r['pls_lat'] !== null) $o[] = [(float)$r['pls_lat'], (float)$r['pls_lon']];
  return $o; }
// je Bezugspunkt die n nächsten Stationen mit Messwerten im Umkreis (Seen nur mit lakes): {id: Station + dist}
function hydroNear($refs, $c, $L) { $sel = []; $locs = hydroLocs();
  foreach ($refs as [$la, $lo]) { $cand = [];
    foreach ($locs as $s) { if (($s['type'] === 'lake' && !$c['lakes']) || empty($L[$s['id']])) continue; $d = hav($la, $lo, $s['lat'], $s['lon']); if ($d <= $c['radius_km'] * 1000) $cand[] = $s + ['dist' => (int)round($d)]; }
    usort($cand, fn($a, $b) => $a['dist'] <=> $b['dist']);
    foreach (array_slice($cand, 0, $c['n']) as $s) if (!isset($sel[$s['id']]) || $s['dist'] < $sel[$s['id']]['dist']) $sel[$s['id']] = $s; }
  uasort($sel, fn($a, $b) => $a['dist'] <=> $b['dist']); return $sel; }
// Werte ins Log und in inc_hydro (phase start|end); false = BAFU nicht erreichbar (zu Beginn erneut versuchen)
function hydroLog($id, $phase) { global $db, $now;
  $s = $db->prepare("SELECT * FROM incidents WHERE id=?"); $s->execute([(int)$id]); $inc = $s->fetch(); $s->closeCursor(); if (!$inc || !feature($inc, 'hydro')) return true;
  $c = hydroCfg(); $refs = hydroRefs($inc); $W = $phase === 'start' ? 'zu Beginn' : 'am Einsatzende';
  [$L] = hydroLatest(); $locs = hydroLocs();
  if (!$L || !$locs) { if ($phase === 'end') logev($id, null, 'hydro', "Pegel und Abfluss $W: Messwerte des BAFU nicht abrufbar"); return false; }
  $sel = hydroNear($refs, $c, $L);
  if ($phase === 'end') { // dieselben Stationen wie zu Beginn, dazu die nächsten um die PLS am Ende
    $q = $db->prepare("SELECT DISTINCT station FROM inc_hydro WHERE incident_id=? AND phase='start'"); $q->execute([(int)$id]);
    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $sid) if (!isset($sel[$sid]) && isset($locs[$sid])) { $d = null; foreach ($refs as [$la, $lo]) { $x = hav($la, $lo, $locs[$sid]['lat'], $locs[$sid]['lon']); $d = $d === null ? $x : min($d, $x); } $sel[$sid] = $locs[$sid] + ['dist' => $d === null ? null : (int)round($d)]; } }
  if (!$sel) { if ($refs) logev($id, null, 'hydro', "Pegel und Abfluss $W: keine Messstation des BAFU im Umkreis von {$c['radius_km']} km um den PLS"); return true; }
  $ins = $db->prepare("INSERT INTO inc_hydro(incident_id,phase,ts,station,name,water,lat,lon,dist,flow,height,level,temp,danger,at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
  foreach ($sel as $st) { $r = hydroRow($st, $L);
    $ins->execute([(int)$id, $phase, $now, $r['id'], $r['name'], $r['water'], $r['lat'], $r['lon'], $r['dist'], $r['flow'], $r['height'], $r['level'], $r['temp'], $r['danger'], $r['at']]);
    logev($id, null, 'hydro', "Pegel und Abfluss $W – " . ($r['water'] !== '' ? $r['water'] . ', ' : '') . $r['name'] . ($r['dist'] !== null ? ' (' . hydroNum($r['dist'] / 1000, 1) . ' km vom PLS)' : '') . ': ' . hydroText($r)); }
  return true; }
// erfasste Werte eines Einsatzes für incident/state und die Verwaltung
function hydroInc($id) { global $db; $s = $db->prepare("SELECT phase,ts,station,name,water,lat,lon,dist,flow,height,level,temp,danger,at FROM inc_hydro WHERE incident_id=? ORDER BY id"); $s->execute([(int)$id]);
  return array_map(fn($r) => floats(ints($r, ['ts', 'dist', 'at', 'danger']), ['lat', 'lon']) + ['v' => array_map(fn($x) => $x === null ? null : (float)$x, ['flow' => $r['flow'], 'height' => $r['height'], 'level' => $r['level'], 'temp' => $r['temp']]) + ['danger' => $r['danger']]], $s->fetchAll()); }
function hydroOn() { return (bool)array_filter(featuresAll(), fn($f) => $f['hydro']); } // ohne Einsatz: für einen der Einsatztypen an

/* ---------- Gewässernetz aus PostGIS (Issue #131) ---------- */
// Hauptquelle fürs Gewässernetz (Suchgebiet, Flussdistanz), vor Overpass: eine eigene PostgreSQL-Datenbank mit PostGIS, gefüllt aus dem OSM-Auszug der Schweiz
// (Geofabrik, scripts/postgis/). Tabelle mit den Spalten id (OSM-Weg), waterway, name, geom (LineString, SRID 4326). Antwortet sie nicht, gilt der bisherige Ablauf
// (Cache, Overpass, Rückfall im Gerät). Einstellung postgis (JSON), Passwort getrennt in postgis_pass (nie an Geräte, leer = behalten). Statistik als «postgis» in overpass_stats.
const PG_DEFAULT = ['on' => 0, 'host' => '', 'port' => 5432, 'db' => '', 'user' => '', 'table' => 'sw_waterways', 'ssl' => 'prefer'];
const PG_SSL = ['prefer' => 'Verschlüsselt, wenn der Server es anbietet', 'require' => 'Nur verschlüsselt', 'disable' => 'Unverschlüsselt (nur im eigenen Netz)'];
const PG_STAT = 'postgis'; // Schlüssel in overpass_stats
function pgCfg() { $v = json_decode(setting('postgis', ''), true); $v = is_array($v) ? $v : []; $c = PG_DEFAULT;
  foreach (['host', 'db', 'user', 'table'] as $k) if (is_string($v[$k] ?? null) && $v[$k] !== '') $c[$k] = $v[$k];
  if (is_numeric($v['port'] ?? null)) $c['port'] = (int)$v['port']; if (isset(PG_SSL[$v['ssl'] ?? ''])) $c['ssl'] = $v['ssl']; $c['on'] = empty($v['on']) ? 0 : 1;
  return $c; }
function pgIn($v) { if (!is_array($v)) fail('Ungültige Einstellung'); $s = fn($k) => trim((string)($v[$k] ?? ''));
  $o = ['on' => empty($v['on']) ? 0 : 1, 'host' => $s('host'), 'port' => (int)($v['port'] ?? 5432), 'db' => $s('db'), 'user' => $s('user'), 'table' => $s('table') ?: PG_DEFAULT['table'], 'ssl' => (string)($v['ssl'] ?? 'prefer')];
  if ($o['host'] !== '' && !preg_match('/^[A-Za-z0-9.:\[\]-]{1,200}$/', $o['host'])) fail('Ungültiger Host');
  if ($o['port'] < 1 || $o['port'] > 65535) fail('Ungültiger Port');
  foreach (['db' => 'Datenbank', 'user' => 'Benutzer'] as $k => $n) if ($o[$k] !== '' && !preg_match('/^[A-Za-z0-9_.@-]{1,63}$/', $o[$k])) fail("Ungültiger Name: $n");
  if (!preg_match('/^[a-z_][a-z0-9_]{0,62}(\.[a-z_][a-z0-9_]{0,62})?$/', $o['table'])) fail('Ungültige Tabelle (Kleinbuchstaben, Ziffern, «_», optional mit Schema: schema.tabelle)');
  if (!isset(PG_SSL[$o['ssl']])) fail('Ungültige Verschlüsselung');
  if ($o['on'] && ($o['host'] === '' || $o['db'] === '' || $o['user'] === '')) fail('Zum Einschalten braucht es Host, Datenbank und Benutzer');
  return $o; }
function pgReady() { $c = pgCfg(); return $c['on'] && $c['host'] !== '' && $c['db'] !== '' && $c['user'] !== '' && extension_loaded('pdo_pgsql'); }
function pgConnect($c, $pass) { if (!extension_loaded('pdo_pgsql')) throw new RuntimeException('PHP-Erweiterung pdo_pgsql fehlt auf dem Server');
  $h = trim($c['host'], '[]'); $p = new PDO("pgsql:host=$h;port={$c['port']};dbname={$c['db']};sslmode={$c['ssl']};connect_timeout=5", $c['user'], $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_TIMEOUT => 5]);
  $p->exec("SET statement_timeout = 15000"); return $p; }
function pgTable($c) { return implode('.', array_map(fn($x) => '"' . $x . '"', explode('.', $c['table']))); }
// Wege wie bei Overpass (type, id, nodes, geometry, tags waterway/name). Die Knoten-IDs gelten nur innerhalb der Antwort: gleiche Koordinaten = gleicher Knoten,
// so verbinden sich die Wege an Zusammenflüssen und Gabelungen wie mit den OSM-Knoten (die Tabelle braucht keine Knotenliste). Teile von MultiLineStrings erhalten negative IDs.
function pgWays($p, $c, $area) { $t = pgTable($c);
  if ($area[0] === 'around') { [, $r, $la, $lo] = $area; $deg = $r / 111320 / max(.05, cos(deg2rad($la)));
    $s = $p->prepare("SELECT id, waterway, name, ST_AsGeoJSON(geom, 7) g FROM $t WHERE waterway IN ('river','stream','canal') AND geom && ST_Expand(ST_SetSRID(ST_MakePoint(?, ?), 4326), ?) AND ST_DWithin(geom::geography, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, ?) LIMIT 50001");
    $s->execute([$lo, $la, $deg, $lo, $la, $r]); }
  else { [, $so, $w, $n, $e] = $area;
    $s = $p->prepare("SELECT id, waterway, name, ST_AsGeoJSON(geom, 7) g FROM $t WHERE waterway IN ('river','stream','canal') AND geom && ST_MakeEnvelope(?, ?, ?, ?, 4326) AND ST_Intersects(geom, ST_MakeEnvelope(?, ?, ?, ?, 4326)) LIMIT 50001");
    $s->execute([$w, $so, $e, $n, $w, $so, $e, $n]); }
  $rows = $s->fetchAll(); if (count($rows) > 50000) throw new RuntimeException('Zu viele Gewässer im Ausschnitt');
  $nid = []; $out = []; $neg = 0;
  foreach ($rows as $r) { $g = json_decode((string)$r['g'], true); $ty = $g['type'] ?? '';
    foreach ($ty === 'LineString' ? [$g['coordinates']] : ($ty === 'MultiLineString' ? $g['coordinates'] : []) as $i => $cs) { if (!is_array($cs) || count($cs) < 2) continue; $nodes = []; $geo = [];
      foreach ($cs as $pt) { $k = sprintf('%.7F,%.7F', $pt[1], $pt[0]); $nodes[] = $nid[$k] ??= count($nid) + 1; $geo[] = ['lat' => (float)$pt[1], 'lon' => (float)$pt[0]]; }
      $out[] = ['type' => 'way', 'id' => $i ? --$neg : (int)$r['id'], 'nodes' => $nodes, 'geometry' => $geo, 'tags' => ['waterway' => (string)$r['waterway']] + (is_string($r['name']) && $r['name'] !== '' ? ['name' => mb_substr($r['name'], 0, 200)] : [])]; } }
  return $out; }
// Antwort als JSON wie Overpass oder null (Fehler zählt in der Statistik)
function pgFetch($area) { $t = microtime(true);
  try { $c = pgCfg(); $j = json_encode(['elements' => pgWays(pgConnect($c, (string)setting('postgis_pass', '')), $c, $area)], JSON_UNESCAPED_UNICODE); opStat(PG_STAT, 'server', true, (microtime(true) - $t) * 1000); return $j; }
  catch (Throwable $e) { opStat(PG_STAT, 'server', false, (microtime(true) - $t) * 1000, pgErr($e)); return null; } }
function pgErr($e) { $m = preg_replace('/\s+/', ' ', $e->getMessage()); $m = preg_replace('/^SQLSTATE\[[^\]]*\]\s*(\[\d+\]\s*)?/', '', $m); return mb_substr($m, 0, 150) ?: 'Fehler'; }

if ($route === 'login') {
  $st = $db->prepare("SELECT * FROM users WHERE username=? AND active=1"); $st->execute([$in['username'] ?? '']);
  $u = $st->fetch();
  if (!$u || !password_verify($in['password'] ?? '', $u['pass_hash'])) { alog('warn', 'auth', 'Anmeldung fehlgeschlagen', ['username' => mb_substr((string)($in['username'] ?? ''), 0, 60), 'known' => $u ? 1 : 0]); fail('Benutzername oder Passwort falsch', 401); }
  $uid = (int)$u['id']; alog('info', 'auth', 'Angemeldet', ['app' => trim((string)($in['device'] ?? '')) !== '' ? 1 : 0, 'platform' => in_array($in['platform'] ?? '', ['android', 'ios'], true) ? $in['platform'] : null]); // Applikationslog (Issue #139)
  $t = bin2hex(random_bytes(24));
  // Native App (Issue #108): mit device eine eigene Gerätesitzung, andere Geräte bleiben angemeldet; höchstens 20 je Person (die ältesten fallen weg)
  $dev = mb_substr(trim(preg_replace('/[\x00-\x1f]/u', ' ', (string)($in['device'] ?? ''))), 0, 80);
  if ($dev !== '') { $pf = in_array($in['platform'] ?? '', ['android', 'ios'], true) ? $in['platform'] : '';
    $db->prepare("INSERT INTO sessions(user_id,token,device,platform,created,last_seen) VALUES(?,?,?,?,?,?)")->execute([$u['id'], $t, $dev, $pf, time(), time()]);
    $db->prepare("UPDATE users SET last_login=? WHERE id=?")->execute([time(), $u['id']]);
    $s = $db->prepare("SELECT id FROM sessions WHERE user_id=? ORDER BY last_seen DESC, id DESC"); $s->execute([$u['id']]);
    foreach (array_slice($s->fetchAll(PDO::FETCH_COLUMN), 20) as $x) { $db->prepare("DELETE FROM push_native WHERE session_id=?")->execute([$x]); $db->prepare("DELETE FROM sessions WHERE id=?")->execute([$x]); }
    out(['token' => $t, 'session' => 1]); }
  $db->prepare("UPDATE users SET token=?, last_login=? WHERE id=?")->execute([$t, time(), $u['id']]);
  out(['token' => $t]);
}

/* ---------- Impressum, Lizenzen & Datenschutz (Issue #102) ---------- */
// Seite legal.html, ohne Anmeldung. Angaben der Organisation in der Einstellung legal (JSON, LEGAL_FIELDS, legalCfg), geändert über settings/save (Recht legal).
// Dazu der aktuelle Stand der Einstellungen, damit die Datenschutzerklärung stimmt: Funktionen, Overpass, Helikopter, Push, Fristen der Anonymisierung.
const LEGAL_FIELDS = ['org' => [120, 'Organisation'], 'addr' => [400, 'Adresse'], 'contact' => [300, 'Kontakt'], 'resp' => [200, 'Verantwortliche Person'], 'privacy' => [300, 'Kontakt für Datenschutzfragen'],
  'hosting' => [300, 'Hosting'], 'note' => [4000, 'Zusatztext Impressum'], 'privacy_note' => [4000, 'Zusatztext Datenschutz']];
function legalCfg() { $v = json_decode(setting('legal', ''), true); $v = is_array($v) ? $v : []; $r = [];
  foreach (LEGAL_FIELDS as $k => [$n]) $r[$k] = is_string($v[$k] ?? null) ? mb_substr(trim($v[$k]), 0, $n) : '';
  return $r; }
if ($route === 'legal') {
  // Funktionen und Anonymisierungsfristen je aktivem Einsatztyp (Issue #90): kinds = Spalten, on/days = {Typ-ID: Wert}
  $air = airCfg(); $ks = kindsActive(); $fk = array_map(fn($k) => features($k['id']), $ks);
  // AI-Assistent (Issue #111): Einstellung direkt lesen, AI_DEFAULT steht erst weiter unten; Datenschutz je Einsatztyp (fehlt = an, früher ein Wert für alle)
  $ai = json_decode((string)setting('ai', ''), true); $ai = is_array($ai) ? $ai : []; $aiPv = $ai['provider'] ?? 'openai'; $aiU = trim((string)($ai['base_url'] ?? ''));
  $aiOn = !empty($ai['on']) && trim((string)($ai['model'] ?? '')) !== '' && ($aiPv === 'anthropic' ? (string)setting('ai_key', '') !== '' : $aiU !== '');
  $aiP = fn($p) => (object)array_map(fn($k) => is_array($ai[$p] ?? null) ? (int)($ai[$p][(string)$k['id']] ?? 1) : (int)($ai[$p] ?? 1), $ks);
  out(['ai' => ['on' => $aiOn ? 1 : 0, 'host' => $aiOn ? ($aiPv === 'anthropic' && $aiU === '' ? 'api.anthropic.com' : (string)(parse_url($aiU, PHP_URL_HOST) ?: '')) : '', 'anon' => $aiP('anon'), 'round_loc' => $aiP('round_loc')],
    'cfg' => legalCfg(), 'updated' => (int)setting('legal_at', '0'),
    'kinds' => array_values(array_map(fn($k) => ['id' => $k['id'], 'name' => $k['name'], 'color' => $k['color']], $ks)),
    'features' => array_map(fn($k) => ['k' => $k, 'name' => FEATURES[$k], 'on' => (object)array_map(fn($f) => $f[$k], $fk)], array_keys(FEATURES)),
    'overpass' => ['servers' => overpassActive(), 'ttl' => (int)setting('overpass_ttl', '120'), 'postgis' => pgReady() ? 1 : 0],
    'hydro' => (object)array_map(fn($f) => $f['hydro'], $fk), // Pegel & Abfluss (Issue #125) je Einsatztyp
    'hydro_src' => array_values(array_map(fn($k) => ['k' => $k] + HYDRO_SOURCES[$k], array_keys(array_filter(hydroCfg()['src'])))), // eingeschaltete Quellen in der Reihenfolge des Vorrangs
    'air_home' => (int)$air['home_km'],
    'air' => ['on' => (int)$air['on'], 'public' => (int)$air['public'], 'replay' => (int)$air['replay'], 'src' => array_map(fn($k) => ['name' => AIR_SOURCES[$k]['name'], 'site' => AIR_SOURCES[$k]['site']], $air['src'])],
    'push' => pushOk() ? 1 : 0, 'push_native' => fcmOk() ? 1 : 0, 'applog_days' => applogDays(), 'anon' => array_map(fn($k, $c) => ['k' => $k, 'name' => ANON_STEPS[$k][0], 'on' => $c['on'], 'days' => (object)array_intersect_key($c['days'], $ks)], array_keys(anonCfg()), anonCfg())]);
}

// Öffentliche Ansicht (Public Mode): ohne Anmeldung, nur lesend, nur mit dem geheimen Link der Einsatzleitung (k = public_token).
// Geliefert wird nur, was zum Zuschauen nötig ist: keine Profilbilder, keine Benutzer-IDs; das Einsatzprotokoll (mit Nachrichten) nur mit public_log (Issue #54).
// Anonym (public_anon=1): statt Namen nur Spezialrolle bzw. Einrückoption, Rettlinge nummeriert und ohne Beschreibung.
function pubIncident() { global $db; $k = (string)($_GET['k'] ?? '');
  $s = $db->prepare("SELECT * FROM incidents WHERE public_token=?"); $s->execute([$k]); $inc = $s->fetch(); $s->closeCursor();
  if (!preg_match('/^[0-9a-f]{32}$/', $k) || !$inc || !feature($inc, 'pub')) fail('Dieser Link ist ungültig oder die öffentliche Ansicht wurde beendet.', 404);
  return $inc; }
// Gewässernetz fürs Suchgebiet: nur um einen PLS des Suchgebiets (la/lo, siehe plsSources) des laufenden Einsatzes, Radius m wie in der Einsatz-App (netRadius), gleicher Cache
if ($route === 'public/net') {
  $inc = pubIncident(); if ($inc['ended_at'] !== null) fail('Der Einsatz ist beendet.', 404);
  if (!feature($inc, 'area')) fail('Das Suchgebiet ist ausgeschaltet.', 403);
  $r = (int)(ceil(min(max(5000, (float)($_GET['m'] ?? 0)), 30000) / 1000) * 1000);
  $la = (float)($_GET['la'] ?? 0); $lo = (float)($_GET['lo'] ?? 0);
  $src = array_values(array_filter(plsSources($inc, incRes($inc)), fn($s) => abs($s['lat'] - $la) < 1e-6 && abs($s['lon'] - $lo) < 1e-6))[0] ?? fail('Kein PLS für das Suchgebiet', 404);
  $x = ['around' => [$r, $src['lat'], $src['lon']]]; overpassServe(overpassQuery($x), overpassArea($x));
}
// Helikopter in der öffentlichen Ansicht (Issue #73): nur mit Einstellung public, gleicher Cache wie die Einsatz-App
if ($route === 'public/air') {
  $inc = pubIncident(); if ($inc['ended_at'] !== null) fail('Der Einsatz ist beendet.', 404);
  if (!airInc($inc) || !airCfg()['public']) fail('Helikopter werden in der öffentlichen Ansicht nicht gezeigt.', 403);
  out(airServe($inc));
}
if ($route === 'public/state') {
  // vorbereitete Übung (Issue #29): Der Link gilt schon vor dem Start; bis dahin nur Titel und «noch nicht begonnen», ohne Aufgebotsmeldung
  $k = (string)($_GET['k'] ?? ''); $s = $db->prepare("SELECT title,kind_id FROM ex_plans WHERE public_token=? AND active=1 AND incident_id IS NULL"); $s->execute([$k]); $wp = $s->fetch(); $s->closeCursor();
  if ($wp && preg_match('/^[0-9a-f]{32}$/', $k)) out(kindAdd(['waiting' => 1, 'title' => $wp['title'], 'kind_id' => $wp['kind_id'], 'now' => $now]));
  $inc = pubIncident(); if ($inc['plan_id'] !== null) { exAuto($inc); $inc = pubIncident(); }
  $pi = ['message' => $inc['message'], 'kind_name' => kindOf($inc)['name'], 'kind_color' => kindOf($inc)['color'], 'alarm_at' => (int)$inc['alarm_at'], 'ended_at' => $inc['ended_at'] === null ? null : (int)$inc['ended_at']];
  if ($inc['ended_at'] !== null) out(['incident' => $pi, 'now' => $now]);
  $id = (int)$inc['id']; $anon = (int)$inc['public_anon'] === 1; $c = $anon ? anonCtx($id) : null; // anonym: Rettlinge wie im anonymisierten Protokoll nummeriert (Issue #54)
  $pi += ['lat' => $inc['lat'] === null ? null : (float)$inc['lat'], 'lon' => $inc['lon'] === null ? null : (float)$inc['lon'], 'pls_at' => plsAt($inc), 'flow_ms' => $inc['flow_ms'] === null ? null : (float)$inc['flow_ms']];
  $roles = array_map(fn($r) => ints($r, ['id', 'is_post']), $db->query("SELECT id,name,color,is_post FROM roles ORDER BY id")->fetchAll());
  $rn = array_column($roles, 'name', 'id');
  $ps = $db->prepare("SELECT p.id pid,p.role_id,p.lat,p.lon,p.loc_at,u.name,m.name mode_name FROM participants p JOIN users u ON u.id=p.user_id LEFT JOIN modes m ON m.code=p.mode WHERE p.incident_id=? AND p.mode<>'declined' AND p.left_at IS NULL ORDER BY p.id");
  $ps->execute([$id]); $parts = [];
  foreach ($ps->fetchAll() as $p) { // id = Teilnahme (participants.id), stabil fürs Folgen, keine Benutzer-ID
    $rid = $p['role_id'] === null ? null : (int)$p['role_id'];
    $parts[] = ['id' => (int)$p['pid'], 'name' => $anon ? ($rid !== null && isset($rn[$rid]) ? $rn[$rid] : ($p['mode_name'] ?: 'Einsatzkraft')) : $p['name'], 'mode_name' => $p['mode_name'], 'role_id' => $rid,
                'lat' => $p['lat'] === null ? null : (float)$p['lat'], 'lon' => $p['lon'] === null ? null : (float)$p['lon'], 'loc_at' => $p['loc_at'] === null ? null : (int)$p['loc_at']];
  }
  foreach (feature($inc, 'ext') ? incExternals($id) : [] as $e) // externe Einsatzkräfte (Issue #39): id negativ, damit sie sich nicht mit Teilnahmen überschneidet; ohne Beschreibung
    $parts[] = ['id' => -$e['id'], 'name' => $anon ? ($e['role_id'] !== null && isset($rn[$e['role_id']]) ? $rn[$e['role_id']] : 'Externe Einsatzkraft') : $e['name'], 'mode_name' => 'Extern', 'role_id' => $e['role_id'], 'lat' => $e['lat'], 'lon' => $e['lon'], 'loc_at' => $e['moved'] ?? $e['created'], 'ext' => 1, 'color' => $e['color']];
  $po = $db->prepare("SELECT id,kind,name,color,pts FROM pois WHERE incident_id=? AND removed IS NULL ORDER BY id"); $po->execute([$id]);
  $pois = !feature($inc, 'poi') ? [] : array_map(fn($p) => ['pts' => json_decode($p['pts'], true) ?: []] + ints($p, ['id']), $po->fetchAll());
  $res = [];
  foreach (incRes($inc) as $n => $r) $res[] = ['name' => $anon ? ($c['r'][$r['id']] ?? 'Rettling ' . ($n + 1)) : $r['name'], 'descr' => $anon ? null : $r['descr']] + array_intersect_key($r, array_flip(['id', 'lat', 'lon', 'loc_at', 'rescued_at', 'pls_lat', 'pls_lon', 'pls_at']));
  out(['incident' => $pi, 'sources' => plsSources($inc, $res), 'anon' => $anon ? 1 : 0, 'roles' => $roles, 'participants' => $parts, 'pois' => $pois, 'rescuees' => $res, 'coord_sys' => setting('coord_sys', 'lv95'), 'features' => features($inc['kind_id']), 'air' => airInc($inc) && airCfg()['public'] ? 1 : 0, 'log' => (int)$inc['public_log'] ? array_slice(logRows($id, false, $anon), 0, 300) : null, 'now' => $now]); // features: Fenster «Ansicht» (Issue #48); log: Einsatzprotokoll live, neueste zuerst, anonym wie die Ansicht (Issue #54, public_log)
}

$tok = (string)($_SERVER['HTTP_X_TOKEN'] ?? ''); $sid = null; // $sid = Gerätesitzung der nativen App (Issue #108), null = Anmeldung über users.token (Browser)
$ucols = 'u.id,u.username,u.name,u.photo,u.exercise_mode,u.must_change,u.coord_sys,u.nav_app,u.place_view,u.ai_modes,u.hydro';
$me = null; if ($tok !== '') { $st = $db->prepare("SELECT $ucols FROM users u WHERE u.token=? AND u.active=1"); $st->execute([$tok]); $me = $st->fetch() ?: null; $st->closeCursor(); } // Lesezugriff sofort beenden, nicht erst am Ende des Requests
if (!$me && $tok !== '') { $st = $db->prepare("SELECT $ucols, s.id sid, s.last_seen FROM sessions s JOIN users u ON u.id=s.user_id WHERE s.token=? AND u.active=1"); $st->execute([$tok]); $me = $st->fetch() ?: null; $st->closeCursor();
  if ($me) { $sid = (int)$me['sid']; if ((int)$me['last_seen'] < $now - 600) $db->prepare("UPDATE sessions SET last_seen=? WHERE id=?")->execute([$now, $sid]); unset($me['sid'], $me['last_seen']); } }
if (!$me) fail('Nicht angemeldet', 401);
$me = ints($me, ['id', 'exercise_mode', 'must_change']); $me['place_view'] = (object)placeView($me['place_view'] ?? null); unset($me['is_admin'], $me['is_planner']); $uid = $me['id'];
$me['ai_modes'] = json_decode((string)$me['ai_modes'], true) ?: []; // AI-Assistent (Issue #111): gewählter Modus je Funktion
$me['hydro'] = array_values(array_filter((array)(json_decode((string)$me['hydro'], true) ?: []), 'is_string')); // persönliche Messstationen (Issue #125)
// Benutzerrollen und Rechte (Issue #63): Vereinigung aller Rollen; is_admin = hat die Rolle Systemadministrator (alle Rechte)
$me['uroles'] = userRoles($uid); $me['is_admin'] = (int)array_reduce($me['uroles'], fn($c, $r) => $c || $r['sys'] === 'admin', false); $me['perms'] = permsOf($me['uroles']);
// Nach einem Zurücksetzen durch die Verwaltung ist nur der Passwortwechsel erlaubt
if ($me['must_change'] && !in_array($route, ['me', 'me/password', 'logout'])) fail('Bitte zuerst ein eigenes Passwort setzen', 403);

/* ---------- Hilfsfunktionen ---------- */
function ints($r, $ks) { foreach ($ks as $k) if (isset($r[$k])) $r[$k] = (int)$r[$k]; return $r; }
function floats($r, $ks) { foreach ($ks as $k) if (isset($r[$k])) $r[$k] = (float)$r[$k]; return $r; }
// Rechte (Issue #63): can() prüft ein Recht der angemeldeten Person, need() lehnt ohne ab
function can($p) { global $me; return in_array($p, $me['perms'], true); }
function need(...$ps) { foreach ($ps as $p) if (can($p)) return; fail('Dafür fehlt dir das Recht «' . (permsAll()[$ps[0]][1] ?? $ps[0]) . '»', 403); }
// Übungen vorbereiten (Issue #29): Recht ex_plan
function planner() { return can('ex_plan'); }
function plannerOnly() { need('ex_plan'); }
// Log und Replay eines Einsatzes: Administratoren alle, Übungsleiter die Durchführungen vorbereiteter Übungen (Verwaltung → Übungen),
// Teilnehmende ihre beendeten Einsätze (Issue #61, «Meine Einsätze», Einstellung my_runs). Rückgabe: 0 = Verwaltung, 1 = teilgenommen, mit allen Daten,
// 2 = teilgenommen, nur anonym ausser sich selbst. Endgültig anonymisierte Daten (rp_anon, log_anon) bleiben für alle anonym.
const MY_RUNS = ['off' => 'Ausgeschaltet', 'anon' => 'Anonymisiert (ausser sich selbst)', 'full' => 'Mit allen Daten'];
function myRuns() { $v = setting('my_runs', 'anon'); return isset(MY_RUNS[$v]) ? $v : 'anon'; }
// Knöpfe in «Meine Einsätze» (Einstellung my_btns); noch nicht gespeichert: Word und PDF wie in der Einrichtung des After Action Review (dl, Freigegeben), der Rest an
const MY_BTNS = ['log' => 'Log', 'replay' => 'Replay', 'aar' => 'After Action Review ansehen', 'docx' => 'After Action Review als Word', 'pdf' => 'After Action Review als PDF'];
function myBtns() { $v = json_decode(setting('my_btns', '') ?: 'null', true); $dl = aarCfg()['dl']; $r = []; foreach (MY_BTNS as $k => $_) $r[$k] = is_array($v) && isset($v[$k]) ? (int)!empty($v[$k]) : (isset($dl[$k]) ? (int)$dl[$k]['rel'] : 1); return $r; }
// Für Einsatzkräfte gibt es nur «anonymisiert»: Jede Stufe ausser track macht Log und Replay in «Meine Einsätze» anonym, auch bei my_runs full
function anonAny($i) { return $i['rp_anon'] !== null || (int)$i['log_anon'] || (int)$i['view_anon'] || (int)$i['txt_anon'] || (int)$i['part_anon']; }
function myAar() { $b = myBtns(); return $b['aar'] || $b['docx'] || $b['pdf']; } // Word und PDF entstehen im Browser aus denselben Daten wie die Ansicht
function myPart($id) { global $db, $uid; $s = $db->prepare("SELECT 1 FROM participants p JOIN incidents i ON i.id=p.incident_id WHERE p.incident_id=? AND p.user_id=? AND p.mode<>'declined' AND i.ended_at IS NOT NULL"); $s->execute([$id, $uid]); $r = (bool)$s->fetch(); $s->closeCursor(); return $r; }
// my=1 (Meine Einsätze): Ansicht als Einsatzkraft, auch für Administratoren und Übungsleiter, also nur eigene Einsätze gemäss my_runs
function incView($id) { global $db; $my = !empty($_GET['my']); if (can('stats') && !$my) return 0;
  if (planner() && !$my) { $s = $db->prepare("SELECT plan_id FROM incidents WHERE id=?"); $s->execute([$id]); $r = $s->fetch(); if ($r && $r['plan_id'] !== null) return 0; }
  if (myRuns() !== 'off' && myPart($id)) return myRuns() === 'full' ? 1 : 2;
  fail($my ? 'Kein Zugriff auf diesen Einsatz' : 'Dafür fehlt dir das Recht «' . PERMS['stats'][1] . '»', 403); }
// Benutzerrollen einer Person [{id, name, sys}] und die Rechte daraus (Administrator: alle aus PERMS)
function userRoles($u) { global $db; $s = $db->prepare("SELECT r.id,r.name,r.perms,r.sys FROM user_uroles x JOIN uroles r ON r.id=x.urole_id WHERE x.user_id=? ORDER BY r.id"); $s->execute([$u]);
  $r = array_map(fn($x) => ['id' => (int)$x['id'], 'name' => $x['name'], 'sys' => $x['sys'], 'perms' => rolePerms($x)], $s->fetchAll()); $s->closeCursor(); return $r; }
function rolePerms($r) { return $r['sys'] === 'admin' ? array_keys(permsAll()) : array_values(array_intersect(array_keys(permsAll()), json_decode($r['perms'] ?? '[]', true) ?: [])); }
function permsOf($roles) { $p = []; foreach ($roles as $r) $p = array_merge($p, $r['perms']); return array_values(array_intersect(array_keys(permsAll()), $p)); }
// Nur Rechte vergeben oder entziehen, die man selbst hat (sonst könnte sich jemand mit «Benutzer verwalten» zum Administrator machen)
function permsMine($ps) { global $me; return !array_diff($ps, $me['perms']); }
function uroleRow($id) { global $db; $s = $db->prepare("SELECT * FROM uroles WHERE id=?"); $s->execute([(int)$id]); $r = $s->fetch(); $s->closeCursor(); return $r ?: null; }
// Wer die Rolle Systemadministrator hat und aktiv ist; mindestens eine Person muss bleiben
function adminCount() { global $db; return (int)$db->query("SELECT COUNT(DISTINCT u.id) c FROM users u JOIN user_uroles x ON x.user_id=u.id JOIN uroles r ON r.id=x.urole_id WHERE u.active=1 AND r.sys='admin'")->fetch()['c']; }
// Benutzer bearbeiten (Name, Passwort, Rollen, deaktivieren) nur, wenn er keine Rechte hat, die man selbst nicht hat
function userEditable($id) { return permsMine(permsOf(userRoles($id))); }
// Konten, die irgendwo in Einsatz- oder Übungsdaten vorkommen (dürfen nie gelöscht werden, nur deaktiviert)
function usersUsed() { global $db; $q = []; foreach (['participants' => 'user_id', 'messages' => 'from_user', 'incident_log' => 'user_id', 'incidents' => 'created_by', 'track' => 'user_id', 'rescuee_marks' => 'user_id', 'pois' => 'created_by', 'rescuees' => 'created_by', 'externals' => 'created_by', 'ex_plans' => 'created_by', 'ex_injects' => 'released_by'] as $t => $c) $q[] = "SELECT $c u FROM $t WHERE $c IS NOT NULL";
  foreach (['loc_by', 'rescued_by'] as $c) $q[] = "SELECT $c u FROM rescuees WHERE $c IS NOT NULL";
  return array_flip(array_map('intval', $db->query("SELECT DISTINCT u FROM (" . implode(' UNION ', $q) . ") x")->fetchAll(PDO::FETCH_COLUMN))); }
function needUserEditable($id) { if (!userEditable($id)) fail('Diese Person hat Rechte, die du selbst nicht hast; nur jemand mit diesen Rechten kann sie bearbeiten', 403); }
function pwCheck($p) { if (!is_string($p) || $p === '') fail('Das Passwort darf nicht leer sein'); }
// Einsatztypen (Issue #90): alle Zeilen, auch gelöschte (bisherige Einsätze behalten ihre Einstellungen); $KINDS = Zwischenspeicher je Anfrage
$KINDS = null;
function kinds() { global $db, $KINDS; if ($KINDS === null) { $KINDS = []; foreach ($db->query("SELECT * FROM inc_kinds ORDER BY pos, name")->fetchAll() as $k) { $k = ints($k, ['id', 'pos', 'active', 'deleted', 'mode_up', 'rec', 'air', 'persons', 'open_all', 'plans']); foreach (['feat_off', 'grp_off', 'grp_def'] as $c) $k[$c] = json_decode($k[$c] ?? '[]', true) ?: []; $KINDS[$k['id']] = $k; } } return $KINDS; }
function kindsActive() { return array_filter(kinds(), fn($k) => $k['active'] && !$k['deleted']); }
function kindOf($inc) { $ks = kinds(); return $ks[(int)($inc['kind_id'] ?? 0)] ?? reset($ks); }
function kindAdd($r) { $k = kindOf($r); $r['kind_id'] = $k['id']; $r['kind_name'] = $k['name']; $r['kind_color'] = $k['color']; return $r; }
// fürs Frontend: was Startformular, Menü und Filter brauchen; air null = Hauptschalter aus
function kindPub($k) { return ['id' => $k['id'], 'name' => $k['name'], 'color' => $k['color'], 'alarm' => $k['alarm'], 'alarm_name' => ALARM_LEVELS[$k['alarm']][0] ?? $k['alarm'], 'mode_up' => $k['mode_up'], 'rec' => $k['rec'], 'air' => airCfg()['on'] ? $k['air'] : null, 'grp_off' => $k['grp_off'], 'grp_def' => $k['grp_def'], 'persons' => $k['persons'], 'open_all' => $k['open_all'], 'plans' => $k['plans'], 'can' => can($k['perm']) && kindMember($k) ? 1 : 0, 'no_grp' => can($k['perm']) && !kindMember($k) ? 1 : 0, 'features' => features($k['id'])]; }
function kindsPub() { return array_values(array_map('kindPub', kindsActive())); }
// Einsatztyp beim Start; ohne kind_id wie früher is_exercise (Geräte mit noch nicht neu geladener App)
function kindStart($in) { $ks = kinds(); $k = null; if (isset($in['kind_id'])) $k = $ks[(int)$in['kind_id']] ?? null; else foreach ($ks as $x) if ($x['perm'] === (empty($in['is_exercise']) ? 'inc_start' : 'ex_start')) $k = $x;
  if (!$k || $k['deleted'] || !$k['active']) fail('Unbekannter oder deaktivierter Einsatztyp'); return $k; }
// Einsatztyp einer vorbereiteten Übung: der eingetragene, sonst der erste, der vorbereitete Übungen erlaubt
function planKind($p) { $k = kinds()[(int)($p['kind_id'] ?? 0)] ?? null; if ($k && $k['plans'] && $k['active'] && !$k['deleted']) return $k; foreach (kindsActive() as $x) if ($x['plans']) return $x; return null; }
// Rechte: je Einsatztyp eines zum Starten, dann die festen aus PERMS
function permsAll() { static $p = null; if ($p === null) { $p = []; foreach (kinds() as $k) if (!$k['deleted']) $p[$k['perm']] = ['Einsatz-App', '«' . $k['name'] . '» starten', 'Einsatztyp «' . $k['name'] . '» auslösen (Alarmstufe ' . (ALARM_LEVELS[$k['alarm']][0] ?? $k['alarm']) . '; welche Einsatzgruppen, legt die Benutzerrolle fest)']; $p += PERMS; } return $p; }
// Alarmstufe für eine Person: die des Einsatztyps; Personen mit Übungsmodus kritisch, wenn der Typ das vorsieht
function alarmLvl($inc, $exm) { $k = kindOf($inc); return $exm && $k['mode_up'] ? 'crit' : (isset(ALARM_LEVELS[$k['alarm']]) ? $k['alarm'] : 'crit'); }
function critical($inc) { global $me; return alarmLvl($inc, $me['exercise_mode']) === 'crit'; }
function part($incId, $u = null) { global $db, $uid; $s = $db->prepare("SELECT * FROM participants WHERE incident_id=? AND user_id=?"); $s->execute([$incId, $u ?? $uid]); return $s->fetch(); }
function isActivePart($incId) { $p = part($incId); return $p && $p['mode'] !== 'declined' && $p['left_at'] === null; }
// Einsatzleitung: Spezialrolle mit is_lead oder Einrückoption mit lead_rights (z. B. Koordinator KP Rück)
function isLead($incId) {
  global $db; $p = part($incId);
  if (!$p || $p['left_at'] !== null || $p['mode'] === 'declined') return false;
  if (leadMode($p['mode'])) return true;
  if ($p['role_id'] === null) return false;
  $s = $db->prepare("SELECT is_lead FROM roles WHERE id=?"); $s->execute([$p['role_id']]); $r = $s->fetch();
  return $r && (int)$r['is_lead'];
}
function leadMode($code) { $m = modeRow($code); return $m && (int)$m['lead_rights']; }
// Einsatzleitung nur mit Teilnahme (Einrückoption gewählt, nicht abgelehnt/ausgetreten, Issue #82); die Übungsleitung auch ohne
function canManage($inc) { global $uid; return exLead($inc) || isActivePart((int)$inc['id']) && (can('inc_lead') || (int)$inc['created_by'] === $uid || isLead((int)$inc['id'])); }
// Vorbereitete Übung eines Einsatzes (Issue #29) und ob die angemeldete Person sie leitet (Übungsleitung: Rechte der Einsatzleitung, wird nicht alarmiert, kann zusätzlich teilnehmen)
// Feste Standorte (Issue #96): permanente bei scharfen Einsätzen und spontanen Übungen alle aktiven; bei vorbereiteten Übungen die gewählten (ex_plans.places, JSON, NULL = alle) plus die eigenen der Übung
// Bereich (Polygon) statt Punkt: pts = JSON [[lat, lon], …] mit 3–200 Eckpunkten, lat/lon = Mitte (für Navigation und Startansicht); NULL = Punkt
// Linie (Issue #134, z. B. Fahrrinne): line = 1, pts mit 2–200 Punkten in Zeichenreihenfolge, lat/lon = Punkt auf halber Länge
function placeRow($r) { $r = floats(ints($r, ['id', 'plan_id', 'active', 'sort', 'line']), ['lat', 'lon']); $r['cat_id'] = $r['cat_id'] === null ? null : (int)$r['cat_id']; $r['line'] = empty($r['line']) ? 0 : 1;
  $p = $r['pts'] === null ? null : json_decode($r['pts'], true); $r['pts'] = is_array($p) && count($p) >= ($r['line'] ? 2 : 3) ? array_map(fn($q) => [(float)$q[0], (float)$q[1]], $p) : null; if (!$r['pts']) $r['line'] = 0; return $r; }
function placePts($v, $line = false) { $n = $line ? 2 : 3; if (!is_array($v) || count($v) < $n || count($v) > 200) fail($line ? 'Eine Linie braucht 2–200 Punkte' : 'Ein Bereich braucht 3–200 Eckpunkte'); return array_map(fn($q) => is_array($q) && count($q) === 2 ? llIn($q[0], $q[1]) : fail('Ungültige Koordinaten'), array_values($v)); }
// Punkt auf halber Länge einer Linie (Navigation, Liste)
function lineMid($pts) { $d = [0]; for ($i = 1; $i < count($pts); $i++) $d[] = $d[$i - 1] + hav($pts[$i - 1][0], $pts[$i - 1][1], $pts[$i][0], $pts[$i][1]);
  $h = end($d) / 2; for ($i = 1; $i < count($pts); $i++) if ($d[$i] >= $h) { $f = $d[$i] > $d[$i - 1] ? ($h - $d[$i - 1]) / ($d[$i] - $d[$i - 1]) : 0;
    return [round($pts[$i - 1][0] + ($pts[$i][0] - $pts[$i - 1][0]) * $f, 7), round($pts[$i - 1][1] + ($pts[$i][1] - $pts[$i - 1][1]) * $f, 7)]; }
  return [$pts[0][0], $pts[0][1]]; }
// Kategorien fester Standorte: def_on = Standard eingeblendet; pro Person übersteuert in users.place_view (JSON {Kategorie-ID: 0|1}, '0' = ohne Kategorie)
// Reihenfolge (sort, in der Verwaltung mit ↑/↓): Kategorien untereinander, Standorte innerhalb ihrer Kategorie; NULL (noch nie verschoben, neu) nach den sortierten, nach Name
const SORT_ORDER = 'sort IS NULL, sort, name';
function placeCats() { global $db; return array_map(fn($r) => ints($r, ['id', 'def_on']), $db->query("SELECT id,name,def_on FROM place_cats ORDER BY " . SORT_ORDER)->fetchAll()); }
// Eintrag $id in der Liste (Abfrage $sql mit $args, liefert id in der geltenden Reihenfolge) um eine Stelle verschieben ($dir -1/1) und die ganze Liste neu nummerieren
function sortMove($tbl, $sql, $args, $id, $dir) { global $db; $s = $db->prepare($sql); $s->execute($args); $ids = array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
  $i = array_search($id, $ids, true); if ($i === false) fail('Nicht gefunden', 404); $j = $i + ($dir < 0 ? -1 : 1);
  if ($j >= 0 && $j < count($ids)) { [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]]; }
  $u = $db->prepare("UPDATE $tbl SET sort=? WHERE id=?"); $db->beginTransaction(); foreach ($ids as $n => $x) $u->execute([$n + 1, $x]); $db->commit(); }
// zusätzlich lbl = Namen auf der Karte (0 = nur beim Darüberfahren/im Popup)
function placeView($v) { $o = []; foreach ((array)(is_string($v) ? json_decode($v, true) : $v) as $k => $x) if (preg_match('/^\d{1,9}$/', (string)$k)) $o[(string)(int)$k] = empty($x) ? 0 : 1; elseif ($k === 'lbl') $o['lbl'] = empty($x) ? 0 : 1; return $o; }
function placesOf($planId) { global $db; $s = $db->prepare("SELECT id,plan_id,cat_id,name,descr,abbr,color,lat,lon,pts,line,active,sort FROM places WHERE plan_id" . ($planId === null ? ' IS NULL' : '=?') . " ORDER BY " . SORT_ORDER); $s->execute($planId === null ? [] : [(int)$planId]); return array_map('placeRow', $s->fetchAll()); }
function placeSel($p) { $v = $p['places'] ?? null; return $v === null ? null : array_values(array_map('intval', (array)json_decode($v, true))); }
function incPlaces($inc) { if (!feature($inc, 'place')) return [];
  $pl = array_values(array_filter(placesOf(null), fn($r) => $r['active']));
  if (!empty($inc['plan_id']) && ($p = exPlan($inc))) { $sel = placeSel($p); if ($sel !== null) $pl = array_values(array_filter($pl, fn($r) => in_array($r['id'], $sel, true)));
    $pl = array_merge($pl, placesOf((int)$p['id'])); }
  return array_map(fn($r) => array_diff_key($r, ['active' => 1]), $pl); }
function exPlan($inc) { global $db; if (empty($inc['plan_id'])) return null; $s = $db->prepare("SELECT * FROM ex_plans WHERE id=?"); $s->execute([(int)$inc['plan_id']]); return $s->fetch() ?: null; }
function exLeads($p) { return array_map('intval', json_decode($p['leads'] ?? '[]', true) ?: []); }
function exLead($inc) { global $uid; $p = exPlan($inc); return $p && in_array($uid, exLeads($p), true); }
// Aufgebotene Personen (NULL = alle) und ob die angemeldete Person alarmiert wird; teilnehmen dürfen alle
function exInvite($p) { return $p['invite'] === null ? null : array_map('intval', json_decode($p['invite'], true) ?: []); }
// Aufgebot eines Einsatzes (Issue #90): incidents.invite (beim Start aufgelöst), bei älteren vorbereiteten Übungen das Aufgebot der Übung; NULL = alle
function incInvite($inc) { $g = json_decode($inc['inv_groups'] ?? 'null', true); if (!empty($g['all'])) return null; if (($inc['invite'] ?? null) !== null) return array_map('intval', json_decode($inc['invite'], true) ?: []); $p = exPlan($inc); return $p ? exInvite($p) : null; }
function alarmed($inc) { global $uid; if (exLead($inc) || incAbsent($inc, $uid)) return false; $v = incInvite($inc); return $v === null || in_array($uid, $v, true); }
// Darf die Person den laufenden Einsatz sehen und ihm beitreten? Einsatztyp mit open_all: alle Mitglieder der beim Typ zugelassenen Gruppen (Nicht-Aufgebotene mit Hinweis). Sonst mit Aufgebot: nur Aufgebotene,
// bisherige Teilnehmende, wer ihn gestartet hat, wer jeden Einsatz leiten darf, die Übungsleitung, oder alle, wenn die Einsatzleitung den Beitritt für alle erlaubt (open_join)
function incAllowed($inc) { global $uid; $k = kindOf($inc); if (($k['open_all'] && kindMember($k)) || (int)($inc['open_join'] ?? 0) || (int)$inc['created_by'] === $uid || can('inc_lead') || exLead($inc)) return true;
  $v = incInvite($inc); if ($v === null || in_array($uid, $v, true)) return true; $p = part((int)$inc['id']); return $p && $p['mode'] !== 'declined'; }
// Abwesenheiten (Issue #91): abwesende Personen werden beim Aufbieten nicht alarmiert (incidents.inv_absent), bleiben aber im Aufgebot (sehen den Einsatz, dürfen beitreten)
function absentIds() { global $db, $now; $s = $db->prepare("SELECT DISTINCT a.user_id FROM absences a JOIN users u ON u.id=a.user_id WHERE a.removed=0 AND u.active=1 AND a.from_at<=? AND (a.until_at IS NULL OR a.until_at>?)"); $s->execute([$now, $now]); return array_map('intval', array_column($s->fetchAll(), 'user_id')); }
function incAbsent($inc, $u) { return in_array($u, array_map('intval', json_decode($inc['inv_absent'] ?? '[]', true) ?: []), true); }
// laufende Abwesenheit je Person: kind, until (NULL = bis auf Weiteres; bei mehreren die längste), note
function absNow($u = null) { global $db, $now; $s = $db->prepare("SELECT user_id,kind,until_at,note FROM absences WHERE removed=0 AND from_at<=? AND (until_at IS NULL OR until_at>?)" . ($u ? ' AND user_id=' . (int)$u : '') . " ORDER BY until_at IS NULL, until_at"); $s->execute([$now, $now]);
  $r = []; foreach ($s->fetchAll() as $a) $r[(int)$a['user_id']] = ['kind' => $a['kind'], 'until' => $a['until_at'] === null ? null : (int)$a['until_at'], 'note' => $a['note']]; return $r; }
function absText($n) { return $n === 1 ? '1 Person abwesend, nicht alarmiert' : "$n Personen abwesend, nicht alarmiert"; }
// Einsatzgruppen (Issue #90)
function idsIn($v) { return array_values(array_unique(array_filter(array_map('intval', (array)$v), fn($x) => $x > 0))); }
function qm($a) { return implode(',', array_fill(0, count($a), '?')); }
function grpRows($active = false) { global $db; return array_map(fn($g) => ints($g, ['id', 'pos', 'active', 'show_name']), $db->query("SELECT id,name,color,pos,active,show_name FROM inc_groups" . ($active ? ' WHERE active=1' : '') . " ORDER BY active DESC, pos, name")->fetchAll()); }
function grpMembers($gids) { global $db; if (!$gids) return []; // aktive Personen der aktiven Gruppen
  $s = $db->prepare("SELECT DISTINCT m.user_id FROM inc_group_members m JOIN inc_groups g ON g.id=m.group_id JOIN users u ON u.id=m.user_id WHERE g.active=1 AND u.active=1 AND m.group_id IN (" . qm($gids) . ")"); $s->execute($gids);
  return array_map('intval', array_column($s->fetchAll(), 'user_id')); }
// Für einen Einsatztyp zugelassene Gruppen: aktiv und beim Typ aufbietbar. Nur ihre Mitglieder können ihn starten und dafür aufgeboten werden.
function kindGroups($k) { return array_values(array_diff(array_column(grpRows(true), 'id'), array_map('intval', $k['grp_off']))); }
// Mitglieder der zugelassenen Gruppen; null = keine Einschränkung (es gibt keine aktive Einsatzgruppe)
function kindPeople($k) { return grpRows(true) ? grpMembers(kindGroups($k)) : null; }
function kindMember($k) { static $mine = null, $any; if ($mine === null) { $mine = array_column(myGroups(), 'id'); $any = (bool)grpRows(true); } return !$any || (bool)array_diff($mine, array_map('intval', $k['grp_off'])); }
function grpNames($gids, $gone = []) { global $db; if (!$gids) return []; $s = $db->prepare("SELECT id,name FROM inc_groups WHERE id IN (" . qm($gids) . ")"); $s->execute($gids); $n = array_column($s->fetchAll(), 'name', 'id') + (array)$gone; // gone = Namen gelöschter Gruppen
  return array_values(array_filter(array_map(fn($g) => $n[$g] ?? null, $gids))); }
// Gruppen, die die Person aufbieten darf (Issue #90, Antwort 2): nur Benutzerrollen mit dem Recht zum Starten zählen; NULL = alle (auch «alle Personen alarmieren»)
function startGroups($k) { global $db, $uid, $me; if ($me['is_admin']) return null; $perm = $k['perm']; $r = [];
  $s = $db->prepare("SELECT r.perms,r.grp_ids FROM user_uroles x JOIN uroles r ON r.id=x.urole_id WHERE x.user_id=?"); $s->execute([$uid]);
  foreach ($s->fetchAll() as $x) { if (!in_array($perm, json_decode($x['perms'] ?? '[]', true) ?: [], true)) continue; if ($x['grp_ids'] === null) return null; $r = array_merge($r, idsIn(json_decode($x['grp_ids'], true))); }
  return array_values(array_unique($r)); }
// fürs Nachbieten: wer den Typ starten darf, ist auf dieselben Gruppen beschränkt; eine Einsatzleitung ohne dieses Recht darf alle aufbietbaren Gruppen nachbieten
function addGroups($k) { return can($k['perm']) ? startGroups($k) : null; }
// Auswahl beim Start bzw. Nachbieten prüfen und auflösen: Gruppen (nur die beim Typ aufbietbaren), Einzelpersonen nur, wenn der Typ das erlaubt; $allowed = startGroups/addGroups
function resolveInvite($k, $groups, $persons, $allowed) { global $db;
  $g = idsIn($groups); $p = $k['persons'] ? idsIn($persons) : [];
  if ($g) { $s = $db->prepare("SELECT id FROM inc_groups WHERE active=1 AND id IN (" . qm($g) . ")"); $s->execute($g); $ok = $s->fetchAll();
    if (count($ok) !== count($g)) fail('Unbekannte oder deaktivierte Einsatzgruppe');
    if (array_intersect($g, array_map('intval', $k['grp_off']))) fail('Diese Einsatzgruppe ist bei «' . $k['name'] . '» nicht aufbietbar'); }
  if ($allowed !== null && array_diff($g, $allowed)) fail('Diese Einsatzgruppe darfst du nicht aufbieten', 403);
  if ($p) { $s = $db->prepare("SELECT COUNT(*) c FROM users WHERE active=1 AND id IN (" . qm($p) . ")"); $s->execute($p); if ((int)$s->fetch()['c'] !== count($p)) fail('Unbekannte oder deaktivierte Person');
    if (($kp = kindPeople($k)) !== null && array_diff($p, $kp)) fail('Aufgeboten werden kann nur, wer einer bei «' . $k['name'] . '» aufbietbaren Einsatzgruppe angehört');
    if ($allowed !== null && array_diff($p, grpMembers($allowed))) fail('Einzelpersonen darfst du nur aus deinen Einsatzgruppen aufbieten', 403); }
  return [array_values(array_unique(array_merge(grpMembers($g), $p))), $g, $p]; }
// Aufgebot zur Anzeige: null = alle, sonst Gruppen (Namen), Anzahl Einzelpersonen und Anzahl Aufgebotene
function invInfo($inc) { $na = count(json_decode($inc['inv_absent'] ?? '[]', true) ?: []); $v = incInvite($inc); if ($v === null) return $na ? ['all' => 1, 'groups' => [], 'persons' => 0, 'n' => null, 'absent' => $na] : null; $g = json_decode($inc['inv_groups'] ?? 'null', true) ?: [];
  return ['groups' => grpNames(idsIn($g['g'] ?? []), $g['gn'] ?? []), 'persons' => count($g['p'] ?? []), 'n' => count($v), 'absent' => $na]; }
function myGroups() { global $db, $uid; $s = $db->prepare("SELECT g.id,g.name,g.color FROM inc_group_members m JOIN inc_groups g ON g.id=m.group_id WHERE m.user_id=? AND g.active=1 ORDER BY g.pos, g.name"); $s->execute([$uid]); return array_map(fn($g) => ints($g, ['id']), $s->fetchAll()); }
function invText($g, $p) { $t = grpNames($g); if ($p) $t[] = count($p) === 1 ? '1 Einzelperson' : count($p) . ' Einzelpersonen'; return implode(', ', $t); }
// Elemente einer vorbereiteten Übung, Zahlen als Zahlen
function exInjects($planId) { global $db; $s = $db->prepare("SELECT i.*,u.name released_name FROM ex_injects i LEFT JOIN users u ON u.id=i.released_by WHERE i.plan_id=? AND i.removed=0 ORDER BY i.id"); $s->execute([$planId]);
  return array_map(fn($r) => floats(ints($r, ['id', 'plan_id', 'ago', 'auto', 'ref_id', 'released', 'released_by', 'rescuee_id', 'removed', 'role_id', 'ext_id']), ['lat', 'lon']), $s->fetchAll()); }
// Element einer vorbereiteten Übung freigeben (ex/release, automatisch über exAuto mit $by NULL): PLS setzen bzw. Rettling erfassen oder seinen PLS neu setzen, Meldung an alle.
// Liefert NULL oder den Grund, warum es (noch) nicht geht; doppelte Freigaben (mehrere Geräte gleichzeitig) verhindert die Bedingung released IS NULL
function exRelease($inc, $j, $ago, $by) { global $db; $db->beginTransaction(); // ganz oder gar nicht, sonst bliebe ein Element ohne Rettling freigegeben
  try { $r = exRelease1($inc, $j, $ago, $by); $db->commit(); return $r; } catch (Throwable $e) { $db->rollBack(); throw $e; } }
function exRelease1($inc, $j, $ago, $by) { global $db, $now;
  $xk = $j['kind'] === 'ext' || $j['kind'] === 'extdel';
  if ($xk && !feature($inc, 'ext')) return 'Die Funktion «Externe Einsatzkräfte» ist für Übungen ausgeschaltet';
  if (!$xk && $j['kind'] !== 'pls' && !feature($inc, 'res')) return 'Die Funktion «Rettlinge» ist für Übungen ausgeschaltet';
  $ref = null;
  if ($j['kind'] === 'extdel') {
    $s = $db->prepare("SELECT e.* FROM ex_injects i JOIN externals e ON e.id=i.ext_id WHERE i.id=? AND i.plan_id=?"); $s->execute([(int)$j['ref_id'], (int)$inc['plan_id']]); $ref = $s->fetch();
    if (!$ref) return 'Zuerst die externe Einsatzkraft freigeben, die hier entfernt wird';
  }
  if ($j['kind'] === 'seen' && $j['ref_id'] !== null) {
    $s = $db->prepare("SELECT r.* FROM ex_injects i JOIN rescuees r ON r.id=i.rescuee_id WHERE i.id=? AND i.plan_id=? AND r.removed IS NULL"); $s->execute([(int)$j['ref_id'], (int)$inc['plan_id']]); $ref = $s->fetch();
    if (!$ref) return 'Zuerst den Rettling freigeben, der hier gesichtet wird';
  }
  $u = $db->prepare("UPDATE ex_injects SET released=?, released_by=? WHERE id=? AND released IS NULL"); $u->execute([$now, $by, $j['id']]);
  if (!$u->rowCount()) return null;
  $at = $now - 60 * $ago; $la = (float)$j['lat']; $lo = (float)$j['lon']; $pos = sprintf('%.5F, %.5F, %s', $la, $lo, hm($at));
  logev($inc['id'], $by, 'ex', $by ? "gibt «{$j['label']}» frei (" . EX_KINDS[$j['kind']] . ')' : "«{$j['label']}» automatisch freigegeben (" . EX_KINDS[$j['kind']] . ", {$j['auto']} min nach dem Start)");
  if ($j['kind'] === 'ext') { // wie incident/external/save; die Spezialrolle nur, wenn sie noch aktiv ist
    $s = $db->prepare("SELECT id,name FROM roles WHERE id=? AND active=1"); $s->execute([(int)$j['role_id']]); $ro = $s->fetch() ?: null;
    $db->prepare("INSERT INTO externals(incident_id,name,descr,role_id,abbr,color,lat,lon,created_by,created,manual) VALUES(?,?,?,?,?,?,?,?,?,?,0)")->execute([$inc['id'], $j['name'], (string)$j['descr'], $ro ? (int)$ro['id'] : null, $j['abbr'], $j['color'], $la, $lo, $by ?? (int)$inc['created_by'], $now]);
    $db->prepare("UPDATE ex_injects SET ext_id=? WHERE id=?")->execute([(int)$db->lastInsertId(), $j['id']]);
    logev($inc['id'], $by, 'ext', sprintf('erfasst die externe Einsatzkraft «%s» (%s, %.5F, %.5F)', $j['name'], $ro ? $ro['name'] : 'ohne Spezialrolle', $la, $lo));
  } elseif ($j['kind'] === 'extdel') { // schon von Hand entfernt: nur die Freigabe zählt
    if ($ref['removed'] === null) { $db->prepare("UPDATE externals SET removed=? WHERE id=?")->execute([$now, $ref['id']]); logev($inc['id'], $by, 'ext', "entfernt die externe Einsatzkraft «{$ref['name']}»"); }
  } elseif ($j['kind'] === 'pls') {
    $s = $db->prepare("SELECT lat FROM incidents WHERE id=?"); $s->execute([$inc['id']]); $had = $s->fetch()['lat'] !== null; // aktueller Stand, exAuto gibt evtl. mehrere nacheinander frei
    $db->prepare("UPDATE incidents SET lat=?, lon=?, pls_at=? WHERE id=?")->execute([$la, $lo, $at === (int)$inc['alarm_at'] ? null : $at, $inc['id']]);
    logev($inc['id'], $by, 'pls', ($had ? 'ändert' : 'setzt') . " den PLS des Einsatzes ($pos)");
  } elseif (!$ref) { // neuer Rettling, auch gesichtet (neu oder unbekannt): Rettling mit PLS am Element erfassen
    $nm = $j['name'];
    if ($nm === null) $nm = unknownName((int)$inc['id']); // unbekannter Rettling
    $db->prepare("INSERT INTO rescuees(incident_id,name,descr,created_by,created,pls_lat,pls_lon,pls_at) VALUES(?,?,?,?,?,?,?,?)")->execute([$inc['id'], $nm, (string)$j['descr'], $by ?? (int)$inc['created_by'], $now, $la, $lo, $at]);
    $db->prepare("UPDATE ex_injects SET rescuee_id=? WHERE id=?")->execute([(int)$db->lastInsertId(), $j['id']]);
    logev($inc['id'], $by, 'rescuee', ($j['kind'] === 'seen' ? 'meldet einen gesichteten Rettling und erfasst ihn als ' : 'erfasst den Rettling ') . "«{$nm}»" . ((string)$j['descr'] !== '' ? ": {$j['descr']}" : ''));
    logev($inc['id'], $by, 'rescuee', "setzt den PLS von «{$nm}» ($pos)");
  } else {
    $db->prepare("UPDATE rescuees SET pls_lat=?, pls_lon=?, pls_at=? WHERE id=?")->execute([$la, $lo, $at, $ref['id']]);
    logev($inc['id'], $by, 'rescuee', "meldet «{$ref['name']}» gesichtet und setzt den PLS neu ($pos)");
  }
  if (trim((string)$j['msg']) !== '' && feature($inc, 'msg')) // Meldung als Nachricht an alle (erscheint im Einsatzprotokoll über logRows)
    $db->prepare("INSERT INTO messages(incident_id,from_user,text,recipients,created) VALUES(?,?,?,NULL,?)")->execute([$inc['id'], $by ?? (int)$inc['created_by'], $j['msg'], $now]);
  return null;
}
// fällige automatische Freigaben (auto Minuten nach dem Start) bei jedem Polling erledigen, mit dem vorbereiteten PLS-Zeitpunkt (ohne: Drift ab der Freigabe).
// Eine Sichtung wartet, bis ihr Rettling freigegeben ist
function exAuto($inc) { global $db, $now;
  if (empty($inc['plan_id']) || $inc['ended_at'] !== null) return;
  $s = $db->prepare("SELECT * FROM ex_injects WHERE plan_id=? AND removed=0 AND released IS NULL AND auto IS NOT NULL AND auto<=? ORDER BY auto,id"); // direkt mit der Spalte vergleichen, sonst vergleicht SQLite den Parameter als Text
  $s->execute([(int)$inc['plan_id'], intdiv($now - (int)$inc['alarm_at'], 60)]); $js = $s->fetchAll();
  do { $n = 0; foreach ($js as $k => $j) if (exRelease($inc, $j, (int)$j['ago'], null) === null) { unset($js[$k]); $n++; } } while ($n && $js); // nochmals, falls eine Sichtung auf ein eben freigegebenes Element wartet
}
function canFlow($inc) { return canManage($inc) || isActivePart((int)$inc['id']); }
function modeRow($code) { global $db; $s = $db->prepare("SELECT * FROM modes WHERE code=?"); $s->execute([$code]); return $s->fetch() ?: null; }
function modeShares($code) { $m = modeRow($code); return !$m || (int)$m['share_location']; }
function setting($k, $d) { global $db; $s = $db->prepare("SELECT v FROM settings WHERE k=?"); $s->execute([$k]); $r = $s->fetch(); return $r ? $r['v'] : $d; }
function setSetting($k, $v) { global $db, $my; $db->prepare($my ? "INSERT INTO settings(k,v) VALUES(?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)" : "INSERT INTO settings(k,v) VALUES(?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v")->execute([$k, $v]); }
// Nachrichten senden: nur wer den Einsatz angenommen hat; wer den Einsatz verwalten darf, immer;
// sonst je nach Spezialrolle (roles.can_message) bzw. Einstellung für Personen ohne Rolle (msg_no_role)
function canMessage($inc) {
  global $db; if (exLead($inc)) return true; // Übungsleitung darf senden, ohne teilzunehmen
  $p = part((int)$inc['id']);
  if (!$p || $p['mode'] === 'declined' || $p['left_at'] !== null) return false;
  if (canManage($inc)) return true;
  if ($p['role_id'] === null) return (bool)(int)setting('msg_no_role', '1');
  $s = $db->prepare("SELECT can_message FROM roles WHERE id=?"); $s->execute([$p['role_id']]); $r = $s->fetch();
  return !$r || (bool)(int)$r['can_message'];
}
function activeIncident() { global $db; return $db->query("SELECT * FROM incidents WHERE ended_at IS NULL ORDER BY id DESC LIMIT 1")->fetch() ?: null; }
// Rettling ohne Namen (Issue #86, Sichtungen in Übungen): «Unbekannter Rettling», fortlaufend, falls es schon unbekannte gibt
function unknownName($incId) { global $db;
  $s = $db->prepare("SELECT COUNT(*) c FROM rescuees WHERE incident_id=? AND name LIKE 'Unbekannter Rettling%'"); $s->execute([$incId]); $n = (int)$s->fetch()['c'];
  return 'Unbekannter Rettling' . ($n ? ' ' . ($n + 1) : ''); }
// Rettlinge eines Einsatzes (ohne entfernte), Zahlen als Zahlen
function incRescuees($id) { global $db;
  $s = $db->prepare("SELECT r.id,r.name,r.descr,r.lat,r.lon,r.loc_at,r.loc_by,r.rescued_at,r.rescued_by,r.created_by,r.created,r.pls_lat,r.pls_lon,r.pls_at,a.name loc_name,b.name rescued_name FROM rescuees r LEFT JOIN users a ON a.id=r.loc_by LEFT JOIN users b ON b.id=r.rescued_by WHERE r.incident_id=? AND r.removed IS NULL ORDER BY r.id");
  $s->execute([$id]); return array_map(fn($r) => floats(ints($r, ['id', 'loc_at', 'loc_by', 'rescued_at', 'rescued_by', 'created_by', 'created', 'pls_at']), ['lat', 'lon', 'pls_lat', 'pls_lon']), $s->fetchAll()); }
// Externe Einsatzkräfte eines Einsatzes (ohne entfernte), Zahlen als Zahlen
function incExternals($id) { global $db;
  $s = $db->prepare("SELECT e.id,e.name,e.descr,e.role_id,e.abbr,e.color,e.lat,e.lon,e.created_by,e.created,e.moved,u.name by_name FROM externals e LEFT JOIN users u ON u.id=e.created_by WHERE e.incident_id=? AND e.removed IS NULL ORDER BY e.id");
  $s->execute([$id]); return array_map(fn($r) => floats(ints($r, ['id', 'role_id', 'created_by', 'created', 'moved']), ['lat', 'lon']), $s->fetchAll()); }
// Kürzel (max. 4 Zeichen) und Farbe (#rrggbb) externer Einsatzkräfte und ihrer Vorlagen; leer = null
function extStyle($in) { $a = mb_substr(trim((string)($in['abbr'] ?? '')), 0, 4); $c = (string)($in['color'] ?? '');
  return [$a === '' ? null : $a, preg_match('/^#[0-9a-fA-F]{6}$/', $c) ? strtolower($c) : null]; }
// Spezialrolle aus der Anfrage: aktive Rolle oder null
function roleIn($v) { global $db; if (empty($v)) return null;
  $s = $db->prepare("SELECT id FROM roles WHERE id=? AND active=1"); $s->execute([(int)$v]); return $s->fetch() ? (int)$v : fail('Unbekannte Spezialrolle'); }
// Koordinaten aus der Anfrage (WGS84), gerundet; ungültig = Fehler
function llIn($la, $lo) { if (!is_numeric($la) || !is_numeric($lo) || abs($la) > 90 || abs($lo) > 180) fail('Ungültige Koordinaten'); return [round((float)$la, 7), round((float)$lo, 7)]; }
// Zeitpunkt des Einsatz-PLS (Beginn der Drift): eigener Zeitpunkt, sonst Alarmeingang
function plsAt($inc) { return (int)($inc['pls_at'] ?? $inc['alarm_at']); }
// PLS fürs Suchgebiet (Issue #24): Gibt es Rettlinge, zählen nur die vermissten (kein Standort, nicht gerettet), je mit eigenem PLS
// oder dem des Einsatzes; ohne Rettlinge der PLS des Einsatzes. Gleiche PLS (Ort und Zeit) werden zusammengefasst, names = Rettlinge.
function plsSources($inc, $res) {
  $ip = $inc['lat'] !== null ? ['lat' => (float)$inc['lat'], 'lon' => (float)$inc['lon'], 'at' => plsAt($inc), 'own' => 0] : null;
  if (!$res) return $ip ? [$ip + ['names' => []]] : [];
  $out = [];
  foreach ($res as $r) {
    if ($r['rescued_at'] !== null || $r['lat'] !== null) continue;
    $p = $r['pls_lat'] !== null ? ['lat' => (float)$r['pls_lat'], 'lon' => (float)$r['pls_lon'], 'at' => (int)$r['pls_at'], 'own' => 1] : $ip;
    if (!$p) continue;
    $k = sprintf('%.7F,%.7F,%d', $p['lat'], $p['lon'], $p['at']);
    $out[$k] = $out[$k] ?? $p + ['names' => []]; $out[$k]['names'][] = $r['name'];
  }
  return array_values($out);
}
// PLS aus der Anfrage (lat, lon, at): Koordinaten gültig, Zeitpunkt höchstens 7 Tage zurück und nicht in der Zukunft (fehlt = $def)
function plsIn($p, $def) { global $now;
  if (!is_array($p) || !isset($p['lat'], $p['lon']) || !is_numeric($p['lat']) || !is_numeric($p['lon']) || abs($p['lat']) > 90 || abs($p['lon']) > 180) fail('Ungültige Koordinaten für den PLS');
  $at = !empty($p['at']) ? (int)$p['at'] : $def;
  if ($at > $now + 60) fail('Der Zeitpunkt des PLS darf nicht in der Zukunft liegen');
  if ($at < $now - 7 * 86400) fail('Der Zeitpunkt des PLS liegt mehr als 7 Tage zurück');
  return [round((float)$p['lat'], 7), round((float)$p['lon'], 7), $at]; }
// Zeit für Log-Texte (Schweizer Zeit)
function hm($t) { return (new DateTime('@' . $t))->setTimezone(new DateTimeZone('Europe/Zurich'))->format('d.m. H:i'); }
function rescuee($inc, $id) { global $db; $s = $db->prepare("SELECT * FROM rescuees WHERE id=? AND incident_id=? AND removed IS NULL"); $s->execute([(int)$id, $inc['id']]); return $s->fetch() ?: fail('Rettling nicht gefunden', 404); }
// Funktionen (FEATURES) je Einsatztyp: inc_kinds.feat_off = ausgeschaltete Schlüssel, Neues ist also an
function features($kid) { $off = kinds()[(int)$kid]['feat_off'] ?? []; $r = []; foreach (FEATURES as $k => $_) $r[$k] = in_array($k, $off, true) ? 0 : 1; return $r; }
function feature($inc, $k) { return (bool)features(kindOf($inc)['id'])[$k]; }
function featuresAll() { $r = []; foreach (kindsActive() as $id => $_) $r[$id] = features($id); return $r; }
// Rettlinge des Einsatzes, leer bei ausgeschalteter Funktion (dann zählt fürs Suchgebiet nur der PLS des Einsatzes)
function incRes($inc) { return feature($inc, 'res') ? incRescuees((int)$inc['id']) : []; }
// Werkzeug Flussdistanz: Vergleichsgeschwindigkeiten in km/h (Einstellung rd_speeds, kommagetrennt)
function rdSpeeds() { return array_map('floatval', array_filter(explode(',', setting('rd_speeds', '10,20,30')), 'strlen')); }
// Aufzeichnung umschalten: Zeitraum öffnen (an) bzw. schliessen (aus), record = neuer Zustand
function setRec($incId, $state) { global $db, $now;
  $db->prepare("UPDATE rec_periods SET stop=? WHERE incident_id=? AND stop IS NULL")->execute([$now, $incId]);
  if ($state === 1) $db->prepare("INSERT INTO rec_periods(incident_id,start) VALUES(?,?)")->execute([$incId, $now]);
  $db->prepare("UPDATE incidents SET record=?" . ($state === 1 ? ', recorded=1' : '') . " WHERE id=?")->execute([$state, $incId]); }
/* ---------- Web-Push (Issue #67) ---------- */
// Payload verschlüsselt nach RFC 8291 (aes128gcm), Absender per VAPID (RFC 8292, ES256). Nur PHP mit openssl und curl, keine Bibliothek.
// Schlüsselpaar wird beim ersten Bedarf erzeugt und in settings (vapid) gespeichert. Ohne openssl/curl gibt es keinen Push, die App pollt wie bisher.
function b64u($s) { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function b64ud($s) { return base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4)); }
function pushOk() { return function_exists('openssl_pkey_new') && function_exists('curl_multi_init') && function_exists('openssl_pkey_derive'); }
function ecRaw($k) { $d = openssl_pkey_get_details($k)['ec']; return "\x04" . str_pad($d['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['y'], 32, "\0", STR_PAD_LEFT); } // unkomprimierter Punkt, 65 Byte
function ecNew() { return openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]); }
function ecPub($raw) { // öffentlicher P-256-Schlüssel (65 Byte) als PEM für openssl
  return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode(hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $raw), 64, "\n") . "-----END PUBLIC KEY-----\n"; }
function vapid() { static $v = null; if ($v) return $v;
  $v = json_decode(setting('vapid', ''), true);
  if (!$v || empty($v['pem'])) { $k = ecNew(); openssl_pkey_export($k, $pem); $v = ['pub' => b64u(ecRaw($k)), 'pem' => $pem]; setSetting('vapid', json_encode($v)); }
  return $v; }
function vapidJwt($aud) { global $cfg; $v = vapid();
  $sub = $cfg['push_subject'] ?? ('https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
  $h = b64u(json_encode(['typ' => 'JWT', 'alg' => 'ES256'])) . '.' . b64u(json_encode(['aud' => $aud, 'exp' => time() + 12 * 3600, 'sub' => $sub], JSON_UNESCAPED_SLASHES));
  openssl_sign($h, $der, openssl_pkey_get_private($v['pem']), OPENSSL_ALGO_SHA256);
  // DER (SEQUENCE{INTEGER r, INTEGER s}) → r||s mit je 32 Byte
  $o = 3 + (ord($der[1]) & 0x80 ? 1 : 0); $rl = ord($der[$o]); $r = substr($der, $o + 1, $rl); $o += 2 + $rl; $sl = ord($der[$o]); $sv = substr($der, $o + 1, $sl);
  $fix = fn($x) => str_pad(ltrim($x, "\0"), 32, "\0", STR_PAD_LEFT);
  return $h . '.' . b64u($fix($r) . $fix($sv)); }
function pushEncrypt($payload, $p256dh, $auth) { // RFC 8291: ein Datensatz, aes128gcm
  $ua = b64ud($p256dh); $as = b64ud($auth); if (strlen($ua) !== 65 || strlen($as) < 16) return null;
  $eph = ecNew(); $ep = ecRaw($eph); $sec = openssl_pkey_derive(openssl_pkey_get_public(ecPub($ua)), $eph, 32); if ($sec === false) return null;
  $ikm = hash_hkdf('sha256', $sec, 32, "WebPush: info\0" . $ua . $ep, $as);
  $salt = random_bytes(16); $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt); $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
  $ct = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
  return $salt . pack('N', 4096) . chr(65) . $ep . $ct . $tag; }
// Benachrichtigung für Personen vormerken; gesendet wird in out() nach der Antwort. $msg: t (Titel), b (Text), tag (wie die Quittierschlüssel der App), crit
function pushTo($userIds, $msg) { global $pushQ; if ((!pushOk() && !fcmOk()) || !$userIds) return; foreach (array_unique(array_map('intval', $userIds)) as $u) $pushQ[] = [$u, $msg]; }
function pushFlush() { global $db, $pushQ; $q = $pushQ; $pushQ = []; if (!$q) return;
  $ids = array_values(array_unique(array_column($q, 0))); $in = implode(',', array_fill(0, count($ids), '?'));
  $subs = []; if (pushOk()) { $s = $db->prepare("SELECT s.* FROM push_subs s JOIN users u ON u.id=s.user_id WHERE u.active=1 AND s.user_id IN ($in)"); $s->execute($ids); $subs = $s->fetchAll(); }
  $mh = curl_multi_init(); $hs = []; $jwt = [];
  alog('info', 'push', 'Push ausgelöst', ['msgs' => count($q), 'users' => count($ids), 'web_subs' => count($subs), 'app' => fcmOk() ? 1 : 0]); // zeigt, dass überhaupt gesendet wird
  $hn = fcmQueue($mh, $q, $ids); // native Apps (Issue #108), im selben curl_multi
  foreach ($q as [$u, $m]) foreach ($subs as $sub) { if ((int)$sub['user_id'] !== $u) continue;
    $pu = parse_url($sub['endpoint']); if (!$pu || !in_array($pu['scheme'] ?? '', ['https', 'http'], true)) continue;
    $aud = $pu['scheme'] . '://' . $pu['host'] . (isset($pu['port']) ? ':' . $pu['port'] : ''); $jwt[$aud] = $jwt[$aud] ?? vapidJwt($aud);
    $body = pushEncrypt(json_encode($m, JSON_UNESCAPED_UNICODE), $sub['p256dh'], $sub['auth']); if ($body === null) continue;
    $h = curl_init($sub['endpoint']);
    curl_setopt_array($h, CURL_CA + [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 4,
      CURLOPT_HTTPHEADER => ['Content-Type: application/octet-stream', 'Content-Encoding: aes128gcm', 'TTL: ' . (!empty($m['crit']) ? 3600 : 600), 'Urgency: high', 'Topic: ' . substr(preg_replace('/[^A-Za-z0-9_-]/', '_', (string)($m['tag'] ?? 'sw')), 0, 32), 'Authorization: vapid t=' . $jwt[$aud] . ', k=' . vapid()['pub']]]);
    curl_multi_add_handle($mh, $h); $hs[] = [$h, (int)$sub['id']]; }
  do { $st = curl_multi_exec($mh, $run); if ($run) curl_multi_select($mh, 1); } while ($run && $st === CURLM_OK);
  foreach ($hs as [$h, $id]) { $c = curl_getinfo($h, CURLINFO_HTTP_CODE); if ($c === 404 || $c === 410) $db->prepare("DELETE FROM push_subs WHERE id=?")->execute([$id]); // abgelaufen
    elseif ($c < 200 || $c >= 300) alog('warn', 'push', 'Web-Push abgelehnt', ['http' => $c, 'host' => parse_url((string)curl_getinfo($h, CURLINFO_EFFECTIVE_URL), PHP_URL_HOST), 'sub' => $id, 'err' => curl_error($h) ?: null]);
    curl_multi_remove_handle($mh, $h); }
  fcmDone($mh, $hn);
  curl_multi_close($mh); }
/* ---------- Nativer Push über Firebase Cloud Messaging, HTTP v1 (Issue #108) ---------- */
// Optional: config.php 'fcm' = Pfad zur JSON-Datei des Firebase-Dienstkontos des App-Projekts. iOS läuft über FCM zu Apple (APNs).
// Zugriffstoken per JWT (RS256, Dienstkonto) bei Google, zwischengespeichert in api/data/fcm_token.json bis kurz vor Ablauf. Fehler nur ins error_log.
function fcmCfg() { global $cfg; static $c = false; if ($c !== false) return $c; $c = null; $f = $cfg['fcm'] ?? null;
  if (!is_string($f) || $f === '' || !is_readable($f) || !function_exists('openssl_sign') || !function_exists('curl_multi_init')) return null;
  $j = json_decode((string)@file_get_contents($f), true);
  if (is_array($j) && !empty($j['project_id']) && !empty($j['client_email']) && !empty($j['private_key'])) $c = $j;
  return $c; }
// Warum App-Push nicht eingerichtet ist (für das Applikationslog), null = eingerichtet bzw. nicht vorgesehen ('fcm' fehlt in config.php)
function fcmWhy() { global $cfg; $f = $cfg['fcm'] ?? null; if (fcmOk()) return null;
  if (!is_string($f) || $f === '') return 'In config.php fehlt der Eintrag fcm (Pfad zur JSON-Datei des Firebase-Dienstkontos)';
  if (!is_readable($f)) return 'Datei des Firebase-Dienstkontos nicht lesbar: ' . basename($f);
  if (!function_exists('openssl_sign') || !function_exists('curl_multi_init')) return 'PHP ohne openssl oder curl';
  return 'Datei des Firebase-Dienstkontos ungültig (project_id, client_email oder private_key fehlt): ' . basename($f); }
function fcmOk() { return fcmCfg() !== null; }
// Gibt es Apps, die sich je für Push angemeldet haben oder als App angemeldet sind? Dann ist fehlendes FCM eine Warnung wert, sonst nicht (reine Web-Installation)
function pushNativeAny() { global $db; static $r = null; if ($r === null) { try { $r = (bool)$db->query("SELECT 1 FROM sessions LIMIT 1")->fetchColumn() || (bool)$db->query("SELECT 1 FROM push_native LIMIT 1")->fetchColumn(); } catch (Throwable $e) { $r = false; } } return $r; }
function fcmToken($renew = false) { $c = fcmCfg(); $f = __DIR__ . '/data/fcm_token.json'; if (!is_dir(dirname($f))) @mkdir(dirname($f), 0775, true);
  $t = json_decode((string)@file_get_contents($f), true);
  if (!$renew && is_array($t) && ($t['email'] ?? '') === $c['client_email'] && (int)($t['exp'] ?? 0) > time() + 300 && !empty($t['token'])) return $t['token'];
  $now = time(); $h = b64u(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])) . '.' . b64u(json_encode(['iss' => $c['client_email'], 'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
    'aud' => 'https://oauth2.googleapis.com/token', 'iat' => $now, 'exp' => $now + 3600], JSON_UNESCAPED_SLASHES));
  $k = openssl_pkey_get_private($c['private_key']); if (!$k || !openssl_sign($h, $sig, $k, OPENSSL_ALGO_SHA256)) { alog('err', 'push', 'FCM: privater Schlüssel des Dienstkontos ungültig'); return null; }
  $ch = curl_init('https://oauth2.googleapis.com/token');
  curl_setopt_array($ch, CURL_CA + [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 4,
    CURLOPT_POSTFIELDS => http_build_query(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $h . '.' . b64u($sig)])]);
  $raw = curl_exec($ch); $r = json_decode((string)$raw, true);
  if (empty($r['access_token'])) { alog('err', 'push', 'FCM: kein Zugriffstoken', ['http' => curl_getinfo($ch, CURLINFO_HTTP_CODE), 'resp' => mb_substr((string)$raw, 0, 300)]); return null; }
  @file_put_contents($f, json_encode(['token' => $r['access_token'], 'exp' => $now + (int)($r['expires_in'] ?? 3600), 'email' => $c['client_email']]), LOCK_EX);
  return $r['access_token']; }
// Basis der Web-App ohne api/ (Host und Pfad, z. B. example.ch/swiftwater/), damit die App weiss, von welchem Backend die Nachricht kommt
function appBase() { $d = str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/api/index.php'))); return ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim($d === '.' ? '' : $d, '/') . '/'; }
// Nachricht für ein Gerät: Android nur data (die App zeigt selbst an, auch Vollbild-Alarm), iOS mit alert und Stufe über APNs
function fcmMsg($tok, $pf, $u, $m) { $tag = (string)($m['tag'] ?? 'sw'); $cr = !empty($m['crit']); $sy = !empty($m['stay']); $si = !empty($m['silent']);
  $d = ['t' => (string)($m['t'] ?? ''), 'b' => mb_substr((string)($m['b'] ?? ''), 0, 1000), 'tag' => $tag, 'crit' => $cr ? '1' : '0', 'stay' => $sy ? '1' : '0', 'silent' => $si ? '1' : '0', 'srv' => appBase(), 'uid' => (string)$u];
  $o = ['token' => $tok, 'data' => $d];
  if ($pf !== 'ios') $o['android'] = ['priority' => 'HIGH', 'ttl' => $cr ? '3600s' : '600s', 'collapse_key' => $tag];
  if ($pf !== 'android') { $aps = ['alert' => ['title' => $d['t'], 'body' => $d['b']], 'thread-id' => $tag];
    if ($cr) $aps += ['sound' => ['critical' => 1, 'name' => 'default', 'volume' => 1.0], 'interruption-level' => 'critical'];
    elseif ($sy) $aps += ['sound' => 'default', 'interruption-level' => 'time-sensitive'];
    elseif ($si) $aps += ['interruption-level' => 'passive'];
    else $aps['sound'] = 'default';
    $o['apns'] = ['headers' => ['apns-priority' => $si ? '5' : '10', 'apns-push-type' => 'alert', 'apns-collapse-id' => substr($tag, 0, 64)], 'payload' => ['aps' => $aps]]; }
  return ['message' => $o]; }
// Anfragen an FCM in $mh einreihen; Rückgabe [[handle, push_native.id], …]
function fcmQueue($mh, $q, $ids) { global $db, $cfg;
  if (!fcmOk()) { if (!empty($cfg['fcm']) || pushNativeAny()) alog('warn', 'push', 'App-Push nicht gesendet', ['why' => fcmWhy()]); return []; }
  $in = implode(',', array_fill(0, count($ids), '?'));
  $s = $db->prepare("SELECT n.* FROM push_native n JOIN users u ON u.id=n.user_id LEFT JOIN sessions se ON se.id=n.session_id WHERE u.active=1 AND (n.session_id IS NULL OR se.id IS NOT NULL) AND n.user_id IN ($in)");
  $s->execute($ids); $ns = $s->fetchAll();
  if (!$ns) { alog('info', 'push', 'App-Push: kein Gerät der Empfänger für Push angemeldet', ['users' => count($ids)]); return []; }
  if (!($at = fcmToken())) return [];
  $url = 'https://fcm.googleapis.com/v1/projects/' . rawurlencode(fcmCfg()['project_id']) . '/messages:send'; $hn = [];
  foreach ($q as [$u, $m]) foreach ($ns as $n) { if ((int)$n['user_id'] !== $u) continue;
    $h = curl_init($url);
    curl_setopt_array($h, CURL_CA + [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode(fcmMsg($n['token'], (string)$n['platform'], $u, $m), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
      CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8', 'Authorization: Bearer ' . $at]]);
    curl_multi_add_handle($mh, $h); $hn[] = [$h, (int)$n['id']]; }
  return $hn; }
// Antworten auswerten: ungültige oder abgemeldete Tokens löschen, abgelehnter Zugriffstoken verwirft den Zwischenspeicher
function fcmDone($mh, $hn) { global $db; $ok = 0;
  foreach ($hn as [$h, $id]) { $c = curl_getinfo($h, CURLINFO_HTTP_CODE); if ($c === 200) $ok++;
    if ($c !== 200) { $e = json_decode((string)curl_multi_getcontent($h), true)['error'] ?? []; $st = (string)($e['status'] ?? ''); $msg = (string)($e['message'] ?? '');
      foreach ((array)($e['details'] ?? []) as $x) if (is_array($x) && !empty($x['errorCode'])) $st = (string)$x['errorCode']; // FcmError: UNREGISTERED, SENDER_ID_MISMATCH, …
      if ($c === 404 || $st === 'UNREGISTERED' || $st === 'SENDER_ID_MISMATCH' || ($st === 'INVALID_ARGUMENT' && stripos($msg, 'token') !== false)) $db->prepare("DELETE FROM push_native WHERE id=?")->execute([$id]);
      else { if ($c === 401) @unlink(__DIR__ . '/data/fcm_token.json'); alog('warn', 'push', 'App-Push (FCM) abgelehnt', ['http' => $c, 'status' => $st ?: null, 'msg' => mb_substr($msg, 0, 200) ?: null, 'sub' => $id]); } }
    curl_multi_remove_handle($mh, $h); }
  if ($hn) alog('info', 'push', 'App-Push an FCM übergeben', ['ok' => $ok, 'failed' => count($hn) - $ok]); }
// alle aktiven Benutzer ausser $skip (IDs), bei Aufgebot nur die aufgebotenen ($only, NULL = alle); Stufe wie alarmLvl je Person, «Keine» sendet nichts
function pushAlarm($inc, $skip, $only = null) { global $db; if (!pushOk() && !fcmOk()) return;
  foreach ($db->query("SELECT id,exercise_mode FROM users WHERE active=1")->fetchAll() as $u) { $id = (int)$u['id']; if (in_array($id, $skip, true) || ($only !== null && !in_array($id, $only, true))) continue;
    $l = alarmLvl($inc, (int)$u['exercise_mode']); if ($l === 'none') continue;
    pushTo([$id], ['t' => mb_strtoupper(kindOf($inc)['name']), 'b' => $inc['message'], 'tag' => 'i:' . $inc['id'], 'crit' => $l === 'crit' ? 1 : 0, 'stay' => $l === 'high' ? 1 : 0, 'silent' => $l === 'low' ? 1 : 0]); } }
function logev($incId, $userId, $kind, $text) { global $db, $now; $db->prepare("INSERT INTO incident_log(incident_id,ts,user_id,kind,text) VALUES(?,?,?,?,?)")->execute([$incId, $now, $userId, $kind, $text]); }
function hav($a, $b, $c, $d) { $p1 = deg2rad($a); $p2 = deg2rad($c); $dp = $p2 - $p1; $dl = deg2rad($d - $b); $x = sin($dp / 2) ** 2 + cos($p1) * cos($p2) * sin($dl / 2) ** 2; return 12742000 * asin(min(1, sqrt($x))); }
function avg($a) { $a = array_values(array_filter($a, fn($x) => $x !== null)); return $a ? round(array_sum($a) / count($a), 1) : null; }
function http_get($url) {
  if (function_exists('curl_init')) { $c = curl_init($url); curl_setopt_array($c, CURL_CA + [CURLOPT_RETURNTRANSFER => 1, CURLOPT_TIMEOUT => 10, CURLOPT_USERAGENT => 'SwiftwaterRescue/0.1']); $r = curl_exec($c); return $r; }
  return @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 10, 'header' => "User-Agent: SwiftwaterRescue/0.1\r\n"]]));
}
// Gewässernetz über den Server mit Datei-Cache, damit gleiche Abfragen (z. B. Suchgebiet aller Geräte eines Einsatzes) nur einmal
// zu Overpass gehen. Haltedauer: Einstellung overpass_ttl in Minuten (Standard 120, 0 = kein Cache).
// Quellen in dieser Reihenfolge (Issue #131): PostGIS (pgReady, area = Ausschnitt aus overpassArea), dann die Overpass-Server; Header X-Source postgis|overpass.
function overpassServe($q, $area = null) { global $now;
  $ttl = max(0, (int)setting('overpass_ttl', '120')) * 60;
  $dir = __DIR__ . '/data/overpass'; if (!is_dir($dir)) @mkdir($dir, 0775, true);
  $file = "$dir/" . sha1($q) . '.json';
  $hit = function () use ($ttl, $file) { clearstatcache(true, $file); if (!$ttl || !is_file($file) || time() - filemtime($file) >= $ttl) return false; header('X-Cache: HIT'); readfile($file); exit; };
  $hit();
  $pg = $area !== null && pgReady(); $op = (bool)overpassActive();
  if (!$pg && !$op) { // Zugriff auf Overpass in der Verwaltung ausgeschaltet (Issue #75) und kein PostGIS: nur noch Daten aus dem Cache, auch abgelaufene, ohne Rückfall im Gerät
    clearstatcache(true, $file); if (is_file($file)) { header('X-Cache: STALE'); readfile($file); exit; }
    fail('Overpass ist in der Verwaltung ausgeschaltet', 503);
  }
  // Sperre je Abfrage: gleichzeitige Anfragen warten auf die erste und lesen danach den Cache, statt selbst zu Overpass zu gehen
  $lk = @fopen("$file.lock", 'c'); if ($lk) flock($lk, LOCK_EX);
  $hit();
  foreach (array_merge(glob("$dir/*.json") ?: [], glob("$dir/*.lock") ?: []) as $x) if ($now - filemtime($x) > max($ttl, OVERPASS_KEEP_S)) @unlink($x); // aufräumen, auch als Rückfall zu alt
  set_time_limit(90);
  $r = $pg ? pgFetch($area) : null; $src = 'postgis';
  if ($r === null && $op) { $r = overpassFetch($q); $src = 'overpass'; }
  if ($r !== null) { if ($ttl && file_put_contents("$file.tmp", $r) !== false) rename("$file.tmp", $file); header('X-Cache: MISS'); header("X-Source: $src"); echo $r; }
  elseif (is_file($file)) { header('X-Cache: STALE'); readfile($file); } // nicht erreichbar: abgelaufene Daten sind besser als keine
  elseif ($op) out(['error' => 'Overpass antwortet nicht', 'query' => $q, 'servers' => overpassActive()], 502); // mit Abfrage und Servern: das Gerät fragt Overpass dann selbst (overpassNet in common.js)
  else fail('PostGIS antwortet nicht, und Overpass ist in der Verwaltung ausgeschaltet', 503);
  exit;
}
// Rückfall (Issue #28): Vom Hosting aus lehnt Overpass oft ab, vom Gerät aus nicht. Das Gerät liefert sein Ergebnis hier für den Cache
// aller ab. Der Schlüssel kommt aus denselben Parametern wie bei overpass; gespeichert werden nur geprüfte Wege mit den nötigen Feldern.
function overpassPut($q, $els) {
  $ttl = max(0, (int)setting('overpass_ttl', '120')) * 60;
  if (!overpassActive()) out(['ok' => true]); // Overpass ausgeschaltet: nichts annehmen
  if (!is_array($els) || count($els) > 50000) fail('Ungültige Gewässerdaten');
  $num = fn($x, $m) => (is_int($x) || is_float($x)) && abs($x) <= $m;
  $w = [];
  foreach ($els as $e) {
    $n = $e['nodes'] ?? null; $g = $e['geometry'] ?? null; $t = $e['tags'] ?? [];
    if (($e['type'] ?? '') !== 'way' || !is_int($e['id'] ?? null) || !is_array($n) || !is_array($g) || count($n) < 2 || count($n) !== count($g) || count($n) > 100000) fail('Ungültige Gewässerdaten');
    if (!is_array($t) || !preg_match('/^(river|stream|canal)$/', (string)($t['waterway'] ?? ''))) fail('Ungültige Gewässerdaten');
    $p = [];
    foreach ($n as $k => $id) {
      if (!is_int($id) || !$num($g[$k]['lat'] ?? null, 90) || !$num($g[$k]['lon'] ?? null, 180)) fail('Ungültige Gewässerdaten');
      $p[] = ['lat' => (float)$g[$k]['lat'], 'lon' => (float)$g[$k]['lon']];
    }
    $w[] = ['type' => 'way', 'id' => $e['id'], 'nodes' => array_values($n), 'geometry' => $p, 'tags' => ['waterway' => $t['waterway']] + (is_string($t['name'] ?? null) ? ['name' => mb_substr($t['name'], 0, 200)] : [])];
  }
  $dir = __DIR__ . '/data/overpass'; if (!is_dir($dir)) @mkdir($dir, 0775, true);
  $file = "$dir/" . sha1($q) . '.json'; clearstatcache(true, $file);
  if (!$ttl || (is_file($file) && time() - filemtime($file) < $ttl)) out(['ok' => true]); // kein Cache bzw. schon aktuell: nichts überschreiben
  $tmp = "$file." . bin2hex(random_bytes(4)) . '.tmp';
  if (file_put_contents($tmp, json_encode(['elements' => $w])) !== false) rename($tmp, $file);
  out(['ok' => true]);
}
// Ausschnitt aus den Parametern, geprüft und auf 5 Nachkommastellen gerundet wie in der Overpass-Abfrage: ['around', m, lat, lon] oder ['bbox', s, w, n, e]
function overpassArea($in) { $f = fn($x) => (float)sprintf('%.5F', (float)$x);
  if (isset($in['around'])) {
    [$r, $la, $lo] = array_map('floatval', array_values((array)$in['around']) + [0, 0, 0]);
    if ($r < 100 || $r > 30000 || abs($la) > 90 || abs($lo) > 180) fail('Ungültiger Umkreis');
    return ['around', (int)round($r), $f($la), $f($lo)];
  }
  if (isset($in['bbox'])) {
    [$s, $w, $n, $e] = array_map('floatval', array_values((array)$in['bbox']) + [0, 0, 0, 0]);
    if ($s >= $n || $w >= $e || $n - $s > 1 || $e - $w > 1.5 || abs($s) > 90 || abs($n) > 90 || abs($w) > 180 || abs($e) > 180) fail('Ungültiger Ausschnitt');
    return ['bbox', $f($s), $f($w), $f($n), $f($e)];
  }
  fail('Ausschnitt fehlt'); }
function overpassQuery($in) { // Abfrage aus Parametern bauen (keine freien Abfragen über den Server)
  $a = overpassArea($in); $f = fn($x) => sprintf('%.5F', $x);
  $sel = $a[0] === 'around' ? 'around:' . $a[1] . ',' . $f($a[2]) . ',' . $f($a[3]) : implode(',', array_map($f, array_slice($a, 1)));
  return '[out:json][timeout:25];way(' . $sel . ')["waterway"~"^(river|stream|canal)$"];out geom;';
}
function overpassFetch($q) { // erste Antwort mit elements, sonst null; jeder Versuch zählt in der Statistik (opStat)
  foreach (overpassActive() as $url) {
    $t = microtime(true); $err = '';
    if (function_exists('curl_init')) {
      $c = curl_init($url); curl_setopt_array($c, CURL_CA + [CURLOPT_RETURNTRANSFER => 1, CURLOPT_TIMEOUT => 15, CURLOPT_POST => 1, CURLOPT_POSTFIELDS => http_build_query(['data' => $q]), CURLOPT_USERAGENT => 'SwiftwaterRescue/0.1']);
      $r = curl_exec($c); $code = curl_getinfo($c, CURLINFO_HTTP_CODE);
      if ($r === false) $err = curl_errno($c) === CURLE_OPERATION_TIMEDOUT ? 'Zeitüberschreitung' : curl_error($c);
    } else {
      $r = @file_get_contents($url, false, stream_context_create(['http' => ['method' => 'POST', 'timeout' => 15, 'ignore_errors' => true, 'header' => "Content-Type: application/x-www-form-urlencoded\r\nUser-Agent: SwiftwaterRescue/0.1\r\n", 'content' => http_build_query(['data' => $q])]]));
      $code = preg_match('/^HTTP\/\S+ (\d{3})/', $http_response_header[0] ?? '', $m) ? (int)$m[1] : 0;
      if ($r === false) $err = 'Keine Antwort';
    }
    $good = $code === 200 && is_string($r) && isset(json_decode($r, true)['elements']);
    opStat($url, 'server', $good, (microtime(true) - $t) * 1000, $good ? null : ($err ?: ($code !== 200 ? "HTTP $code" : 'Ungültige Antwort')));
    if ($good) return $r;
  }
  return null;
}
// Verwendungsstatistik je Overpass-Server und Quelle (Issue #45, Tabelle overpass_stats); darf die Abfrage nie stören
function opStat($url, $src, $ok, $ms, $err = null) { global $db;
  if (!$ok) alog('warn', 'overpass', ($url === PG_STAT ? 'PostGIS' : 'Overpass') . ($src === 'device' ? ' (vom Gerät gemeldet)' : '') . ' ohne Antwort', ['url' => $url, 'err' => $err === null ? null : mb_substr($err, 0, 200), 'ms' => (int)$ms]); // Applikationslog (Issue #139)
  try {
    $now = time(); $err = $err === null ? null : mb_substr($err, 0, 150);
    $u = $db->prepare($ok ? "UPDATE overpass_stats SET uses=uses+1, ok=ok+1, ok_ms=ok_ms+?, last_ok=? WHERE url=? AND src=?" : "UPDATE overpass_stats SET uses=uses+1, fails=fails+1, last_fail=?, last_err=? WHERE url=? AND src=?");
    $p = $ok ? [(int)$ms, $now, $url, $src] : [$now, $err, $url, $src];
    $u->execute($p); if ($u->rowCount()) return;
    if (setting('overpass_stats_since', '') === '') setSetting('overpass_stats_since', (string)$now);
    try { $db->prepare("INSERT INTO overpass_stats(url,src,uses,ok,fails,ok_ms,last_ok,last_fail,last_err) VALUES(?,?,1,?,?,?,?,?,?)")->execute([$url, $src, $ok ? 1 : 0, $ok ? 0 : 1, $ok ? (int)$ms : 0, $ok ? $now : null, $ok ? null : $now, $err]); }
    catch (Throwable $e) { $u->execute($p); } // gleichzeitig angelegt: dann zählen
  } catch (Throwable $e) {}
}
// Ortsname zu Koordinaten: swisstopo-Ortschaftenverzeichnis, ausserhalb der Schweiz OpenStreetMap (Nominatim), sonst Koordinaten
function placeName($la, $lo) {
  $r = json_decode(http_get('https://api3.geo.admin.ch/rest/services/api/MapServer/identify?geometryType=esriGeometryPoint&sr=4326&tolerance=0&returnGeometry=false&lang=de&layers=all:ch.swisstopo-vd.ortschaftenverzeichnis_plz&geometry=' . sprintf('%.6F,%.6F', $lo, $la)) ?: '', true);
  $n = $r['results'][0]['attributes']['langtext'] ?? null;
  if (is_string($n) && $n !== '') return $n;
  $r = json_decode(http_get('https://nominatim.openstreetmap.org/reverse?format=jsonv2&zoom=14&accept-language=de&' . sprintf('lat=%.6F&lon=%.6F', $la, $lo)) ?: '', true);
  foreach (['village', 'town', 'city', 'suburb', 'hamlet', 'municipality'] as $k) if (!empty($r['address'][$k])) return $r['address'][$k];
  return sprintf('%.4F, %.4F', $la, $lo);
}
// Einsatzprotokoll: Log-Einträge plus alle Nachrichten im Volltext mit Empfängern (aus der Nachrichtentabelle,
// daher auch für ältere Einsätze vollständig); sortiert nach Zeit
// anon: anonymisierte Fassung (Issue #54, anonCtx/anonText); nach dem endgültigen Anonymisieren (log_anon) stehen die Bezeichnungen in actor, from_label und to_label
function logRows($id, $asc = false, $anon = false, $self = null) {
  global $db;
  $s = $db->prepare("SELECT l.id,l.ts,l.kind,l.text,l.user_id uid,COALESCE(u.name,l.actor) name FROM incident_log l LEFT JOIN users u ON u.id=l.user_id WHERE l.incident_id=? AND l.kind<>'message' ORDER BY l.id DESC LIMIT 500");
  $s->execute([$id]);
  $rows = array_map(fn($r) => ints($r, ['id', 'ts']), $s->fetchAll());
  $names = $db->query("SELECT id,name FROM users")->fetchAll(PDO::FETCH_KEY_PAIR);
  $c = $anon ? anonCtx($id, $self) : null;
  $who = fn($u) => $c ? ($c['u'][(int)$u] ?? 'Person') : ($names[$u] ?? '?');
  $m = $db->prepare("SELECT m.id,m.created,m.text,m.recipients,m.to_label,m.lat,m.lon,m.mark,m.mark_lat,m.mark_lon,m.from_user uid,COALESCE(u.name,m.from_label) name FROM messages m LEFT JOIN users u ON u.id=m.from_user WHERE m.incident_id=? ORDER BY m.id DESC LIMIT 500");
  $m->execute([$id]);
  $ll = fn($a, $b) => sprintf('%.5F, %.5F', $a, $b);
  foreach ($m->fetchAll() as $r) {
    $to = $r['to_label'] ?? ($r['recipients'] === null ? 'alle' : implode(', ', array_map($who, json_decode($r['recipients'], true) ?: [])));
    $extra = ($r['lat'] !== null ? ' · Standort ' . $ll($r['lat'], $r['lon']) : '') . ($r['mark_lat'] !== null ? ' · Kartenmarkierung ' . $ll($r['mark_lat'], $r['mark_lon']) . ((int)$r['mark'] ? '' : ' (entfernt)') : '');
    $rows[] = ['id' => 1000000000 + (int)$r['id'], 'ts' => (int)$r['created'], 'kind' => 'message', 'text' => "Nachricht an $to: " . ($c ? anonText($r['text'], $c) : $r['text']) . $extra, 'name' => $r['name'], 'uid' => $r['uid']];
  }
  foreach ($rows as &$r) {
    if ($c) { if ($r['uid'] !== null) $r['name'] = $who($r['uid']); if ($r['kind'] !== 'message') $r['text'] = anonText($r['text'], $c, $r['kind']); }
    unset($r['uid']);
  }
  unset($r);
  usort($rows, fn($a, $b) => $asc ? [$a['ts'], $a['id']] <=> [$b['ts'], $b['id']] : [$b['ts'], $b['id']] <=> [$a['ts'], $a['id']]);
  return array_slice($rows, 0, 1000);
}
/* ---------- Anonymisieren (Issue #54) ---------- */
// Bezeichnungen je Einsatz: Teilnehmende «Einsatzkraft N» (Reihenfolge der Teilnahme), übrige beteiligte Konten (Ersteller, Verwaltung) «Person N»,
// Rettlinge «Rettling N», externe Einsatzkräfte «Externe Einsatzkraft N» (je in der Reihenfolge der Erfassung, auch entfernte).
// Frühere Namen aus Umbenennungen im Log gelten mit. map: Text → Ersatz; ersetzt werden ganze Namen (nicht innerhalb von Wörtern), längste zuerst.
function anonCtx($id, $self = null) { global $db;
  $q = function ($sql) use ($db, $id) { $s = $db->prepare($sql); $s->execute([$id]); return array_column($s->fetchAll(), 'v'); };
  $u = []; $n = 0; $o = 0;
  foreach ($q("SELECT user_id v FROM participants WHERE incident_id=? ORDER BY id") as $x) $u[(int)$x] = 'Einsatzkraft ' . ++$n;
  foreach (array_merge($q("SELECT created_by v FROM incidents WHERE id=?"), $q("SELECT user_id v FROM incident_log WHERE incident_id=? AND user_id IS NOT NULL ORDER BY id"), $q("SELECT from_user v FROM messages WHERE incident_id=? AND from_user IS NOT NULL ORDER BY id")) as $x)
    if ($x !== null && !isset($u[(int)$x])) $u[(int)$x] = 'Person ' . ++$o;
  $nm = $db->query("SELECT id,name FROM users")->fetchAll(PDO::FETCH_KEY_PAIR);
  $map = [];
  // self (Issue #61): die eigene Person behält ihren Namen («anonymisiert ausser sich selbst»), die übrigen behalten ihre Nummern
  if ($self !== null && isset($u[$self], $nm[$self])) $u[$self] = $nm[$self];
  foreach ($u as $k => $l) if ($k !== $self && isset($nm[$k]) && mb_strlen(trim($nm[$k])) >= 2) $map[(string)$nm[$k]] = $l;
  // gleicher Name = gleiche Bezeichnung (das Log kennt nur Namen, z. B. entfernt und neu erfasst)
  $lab = function ($sql, $pre) use ($db, $id) { $s = $db->prepare($sql); $s->execute([$id]); $r = []; $byName = []; foreach ($s->fetchAll() as $x) { $nx = (string)$x['name']; $byName[$nx] = $byName[$nx] ?? $pre . (count($byName) + 1); $r[(int)$x['id']] = [$byName[$nx], $nx]; } return $r; };
  $res = $lab("SELECT id,name FROM rescuees WHERE incident_id=? ORDER BY id", 'Rettling ');
  $ext = $lab("SELECT id,name FROM externals WHERE incident_id=? ORDER BY id", 'Externe Einsatzkraft ');
  $rn = []; foreach ($res as [$l, $x]) $rn[$x] = $rn[$x] ?? $l;
  $en = []; foreach ($ext as [$l, $x]) $en[$x] = $en[$x] ?? $l;
  // Umbenennungen rückwärts: der alte Name erhält die Bezeichnung des neuen
  $s = $db->prepare("SELECT kind,text FROM incident_log WHERE incident_id=? AND kind IN ('rescuee','ext') ORDER BY id DESC"); $s->execute([$id]);
  foreach ($s->fetchAll() as $r) {
    if ($r['kind'] === 'rescuee' && preg_match('/^ändert den Rettling «([^»]*)» zu «([^»]*)»/u', $r['text'], $m) && isset($rn[$m[2]])) $rn[$m[1]] = $rn[$m[1]] ?? $rn[$m[2]];
    if ($r['kind'] === 'ext' && preg_match('/^ändert die externe Einsatzkraft «([^»]*)» zu «([^»]*)»/u', $r['text'], $m) && isset($en[$m[2]])) $en[$m[1]] = $en[$m[1]] ?? $en[$m[2]];
  }
  foreach ($rn as $x => $l) { $map["«{$x}»"] = "«{$l}»"; if (mb_strlen($x) >= 3 && !isset($map[(string)$x])) $map[(string)$x] = $l; } // Rettlinge auch ohne «» (z. B. in Nachrichten)
  foreach ($en as $x => $l) $map["«{$x}»"] = $map["«{$x}»"] ?? "«{$l}»";
  $keys = array_map('strval', array_keys($map)); usort($keys, fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));
  $re = $keys ? '/(?<![\p{L}\p{N}])(?:' . implode('|', array_map(fn($k) => preg_quote($k, '/'), $keys)) . ')(?![\p{L}\p{N}])/u' : null;
  return ['u' => $u, 'r' => array_map(fn($x) => $x[0], $res), 'e' => array_map(fn($x) => $x[0], $ext), 'map' => $map, 're' => $re];
}
// Text anonymisieren: Namen ersetzen; bei Rettlingen fällt die Beschreibung weg («…»: Beschreibung)
function anonText($t, $c, $kind = '') {
  if ($t === null || $t === '') return $t;
  if ($kind === 'rescuee') $t = preg_replace('/^((?:erfasst|ändert|meldet einen).*?»): .*$/su', '$1', $t);
  if (!$c['re']) return $t;
  $r = preg_replace_callback($c['re'], fn($m) => $c['map'][$m[0]] ?? $m[0], $t);
  return $r ?? '(Text nicht anonymisierbar)';
}
function anonCfg() { $c = json_decode(setting('anon_cfg', ''), true); $c = is_array($c) ? $c : []; $r = [];
  $d = fn($v) => $v === null || $v === '' || !is_numeric($v) ? null : max(0, min(36500, (int)$v));
  foreach (ANON_STEPS as $k => $_) { $x = is_array($c[$k] ?? null) ? $c[$k] : null; $days = []; // fehlt die Stufe: Vorgabe des Typs, days je Einsatztyp (auch gelöschte, ihre Einsätze laufen weiter nach ihrer Frist)
    foreach (kinds() as $id => $kd) $days[$id] = $d($x !== null ? ($x['days'][$id] ?? null) : anonDef($k, $kd));
    $r[$k] = ['on' => (int)($x['on'] ?? 1) ? 1 : 0, 'days' => $days]; }
  return $r; }
// Vorgabe aus ANON_DEFAULT: nur die beiden angelegten Typen, neue übernehmen die Fristen beim Anlegen
function anonDef($step, $kd) { $i = $kd['perm'] === 'inc_start' ? 0 : ($kd['perm'] === 'ex_start' ? 1 : null); return $i === null ? null : (ANON_DEFAULT[$step][$i] ?? null); }
// würde die Stufe an diesem Einsatz etwas ändern?
function anonTodo($inc, $k) { switch ($k) {
  case 'view': return !(int)$inc['view_anon'];
  case 'replay': return (int)$inc['recorded'] && $inc['rp_anon'] === null;
  case 'log': return !(int)$inc['log_anon'];
  case 'texts': return !(int)$inc['txt_anon'];
  case 'parts': return !(int)$inc['part_anon'];
  case 'track': return (bool)(int)$inc['recorded']; } return false; }
// eine Stufe an einem beendeten Einsatz ausführen, in einer Transaktion; false = nichts zu tun (schon erledigt, nicht beendet, keine Aufzeichnung)
// how: Zusatz im Log, z. B. «(Verwaltung)» oder «(automatisch …)». Die Einträge stehen ohne Person (wie Issue #54).
function anonDo($id, $k, $how) { global $db;
  $db->beginTransaction();
  try {
    $s = $db->prepare("SELECT * FROM incidents WHERE id=?"); $s->execute([(int)$id]); $inc = $s->fetch(); $s->closeCursor();
    if (!$inc || $inc['ended_at'] === null || !anonTodo($inc, $k)) { $db->rollBack(); return false; }
    if ($k !== 'track') aarArchive($inc); // vor dem ersten Eingriff, in derselben Transaktion: ohne Sicherung keine Anonymisierung («Aufzeichnung löschen» betrifft das Review nicht)
    if ($k === 'texts' && !(int)$inc['log_anon'] && anonStep($inc, 'log', $how)) $inc['log_anon'] = 1; // Freitexte setzen das pseudonymisierte Protokoll voraus (danach fehlten die Namen für die Ersetzung)
    if ($k === 'parts') { // Replay und Protokoll brauchen die Konten für ihre Bezeichnungen, also zuerst
      if ((int)$inc['recorded'] && $inc['rp_anon'] === null && anonStep($inc, 'replay', $how)) $inc['rp_anon'] = '[]';
      if (!(int)$inc['log_anon'] && anonStep($inc, 'log', $how)) $inc['log_anon'] = 1; }
    $ok = anonStep($inc, $k, $how);
    $db->commit(); return $ok;
  } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; } }
// zuerst schreibend sperren (UPDATE mit Bedingung): bei zwei gleichzeitigen Anfragen läuft nur eine durch
function anonStep($inc, $k, $how) { global $db; $id = (int)$inc['id'];
  $claim = function ($sql) use ($db, $id) { $s = $db->prepare($sql); $s->execute([$id]); return $s->rowCount() > 0; };
  switch ($k) {
  case 'view':
    if (!$claim("UPDATE incidents SET view_anon=1 WHERE id=? AND view_anon=0")) return false;
    logev($id, null, 'anon', "Anzeige anonymisiert $how"); return true;
  case 'replay':
    if (!$claim("UPDATE incidents SET rp_anon='[]' WHERE id=? AND rp_anon IS NULL AND recorded=1")) return false;
    $c = anonCtx($id); $people = [];
    $up = $db->prepare("UPDATE track SET user_id=? WHERE incident_id=? AND user_id=?");
    foreach (replayParts($id) as $p) { $pid = -(count($people) + 1); $up->execute([$pid, $id, $p['user_id']]);
      $people[] = ['user_id' => $pid, 'name' => $c['u'][$p['user_id']] ?? 'Einsatzkraft'] + array_intersect_key($p, array_flip(['mode', 'role_id', 'left_at', 'role', 'color', 'mode_name', 'joined'])); }
    $db->prepare("DELETE FROM track WHERE incident_id=? AND user_id>0")->execute([$id]); // Verläufe ohne Teilnahme (z. B. abgelehnt)
    $db->prepare("UPDATE participants SET lat=NULL, lon=NULL, loc_at=NULL, log_lat=NULL, log_lon=NULL, log_at=NULL, fast_at=NULL WHERE incident_id=?")->execute([$id]);
    $db->prepare("UPDATE incidents SET rp_anon=? WHERE id=?")->execute([json_encode($people), $id]);
    logev($id, null, 'anon', "Replay anonymisiert $how"); return true;
  case 'log':
    if (!$claim("UPDATE incidents SET log_anon=1 WHERE id=? AND log_anon=0")) return false;
    $c = anonCtx($id); $who = fn($u) => $c['u'][(int)$u] ?? 'Person';
    $s = $db->prepare("SELECT id,user_id,kind,text,actor FROM incident_log WHERE incident_id=?"); $s->execute([$id]);
    $up = $db->prepare("UPDATE incident_log SET text=?, actor=?, user_id=NULL WHERE id=?");
    foreach ($s->fetchAll() as $r) $up->execute([anonText($r['text'], $c, $r['kind']), $r['user_id'] !== null ? $who($r['user_id']) : $r['actor'], $r['id']]);
    $s = $db->prepare("SELECT id,from_user,from_label,recipients,to_label,text FROM messages WHERE incident_id=?"); $s->execute([$id]);
    $up = $db->prepare("UPDATE messages SET text=?, from_label=?, to_label=?, from_user=NULL, recipients=NULL WHERE id=?");
    foreach ($s->fetchAll() as $r) $up->execute([anonText($r['text'], $c), $r['from_user'] !== null ? $who($r['from_user']) : $r['from_label'],
      $r['to_label'] ?? ($r['recipients'] === null ? 'alle' : implode(', ', array_map($who, json_decode($r['recipients'], true) ?: []))), $r['id']]);
    $db->prepare("UPDATE incidents SET message=? WHERE id=?")->execute([anonText($inc['message'], $c), $id]);
    logev($id, null, 'anon', "Einsatzprotokoll anonymisiert $how"); return true;
  case 'texts':
    if (!$claim("UPDATE incidents SET txt_anon=1 WHERE id=? AND txt_anon=0")) return false;
    $c = anonCtx($id); // vor dem Umbenennen: Bezeichnungen aus den bisherigen Namen
    $up = $db->prepare("UPDATE rescuees SET name=?, descr=NULL WHERE id=?"); foreach ($c['r'] as $rid => $l) $up->execute([$l, $rid]);
    $up = $db->prepare("UPDATE externals SET name=?, descr=NULL WHERE id=?"); foreach ($c['e'] as $eid => $l) $up->execute([$l, $eid]);
    $s = $db->prepare("SELECT id,name FROM pois WHERE incident_id=?"); $s->execute([$id]); $up = $db->prepare("UPDATE pois SET name=? WHERE id=?");
    foreach ($s->fetchAll() as $r) $up->execute([anonText($r['name'], $c), $r['id']]);
    $db->prepare("UPDATE messages SET text='(Text entfernt)' WHERE incident_id=?")->execute([$id]);
    logev($id, null, 'anon', "Personenangaben und Freitexte entfernt $how"); return true;
  case 'parts': // negative ID in derselben Reihenfolge wie die Standortverläufe des Replays (Teilnahmen ohne Abgelehnte nach id, danach die Abgelehnten), Bezeichnung in label
    if (!$claim("UPDATE incidents SET part_anon=1 WHERE id=? AND part_anon=0")) return false;
    $c = anonCtx($id); $s = $db->prepare("SELECT id,user_id FROM participants WHERE incident_id=? ORDER BY CASE WHEN mode='declined' THEN 1 ELSE 0 END, id"); $s->execute([$id]);
    $up = $db->prepare("UPDATE participants SET user_id=?, label=?, lat=NULL, lon=NULL, loc_at=NULL, log_lat=NULL, log_lon=NULL, log_at=NULL, fast_at=NULL WHERE id=?"); $n = 0;
    foreach ($s->fetchAll() as $r) { $u = (int)$r['user_id']; $up->execute([-(++$n), $u < 0 ? null : ($c['u'][$u] ?? 'Einsatzkraft'), $r['id']]); }
    // übrige Verweise auf Konten in den Einsatzdaten (ausgelöst von, erfasst/markiert/gerettet von); das After Action Review bleibt unverändert
    $db->prepare("UPDATE incidents SET created_by=NULL WHERE id=?")->execute([$id]);
    $db->prepare("UPDATE rescuees SET created_by=NULL, loc_by=NULL, rescued_by=NULL WHERE incident_id=?")->execute([$id]);
    foreach (['externals', 'pois'] as $t) $db->prepare("UPDATE $t SET created_by=NULL WHERE incident_id=?")->execute([$id]);
    logev($id, null, 'anon', "Teilnahmen vom Konto gelöst $how"); return true;
  case 'track':
    if (!$claim("UPDATE incidents SET record=0, recorded=0, rp_anon=NULL WHERE id=? AND recorded=1")) return false;
    foreach (['track', 'rec_periods', 'air_track'] as $t) $db->prepare("DELETE FROM $t WHERE incident_id=?")->execute([$id]);
    logev($id, null, 'anon', "Aufzeichnung gelöscht $how"); return true; }
  return false; }
// automatisch fällige Stufen (kein Cron: höchstens alle 10 Minuten bei einer Anfrage, je Lauf höchstens 20 Schritte, der Rest beim nächsten Mal)
function anonAuto() { global $db, $now;
  if ((int)setting('anon_auto_at', 0) > $now - 600) return; setSetting('anon_auto_at', (string)$now);
  $n = 0;
  foreach (anonCfg() as $k => $c) { if (!$c['on']) continue;
    foreach ($c['days'] as $kid => $dd) { if ($dd === null) continue;
      $s = $db->prepare("SELECT * FROM incidents WHERE ended_at IS NOT NULL AND ended_at<=? AND kind_id=? ORDER BY ended_at"); $s->execute([$now - $dd * 86400, $kid]);
      foreach ($s->fetchAll() as $inc) { if (!anonTodo($inc, $k)) continue;
        if (anonDo((int)$inc['id'], $k, '(automatisch, ' . ($dd ? $dd . ($dd === 1 ? ' Tag' : ' Tage') . ' nach dem Einsatzende' : 'beim Einsatzende') . ')')) { $n++; alog('info', 'anon', 'Automatisch anonymisiert: ' . ANON_STEPS[$k][0], ['incident' => (int)$inc['id'], 'step' => $k, 'days' => $dd]); }
        if ($n >= 20) { setSetting('anon_auto_at', (string)($now - 540)); return; } } } } }
// Einsatzkräfte fürs Replay: Spezialrolle mit Stand am Ende (Rollenwechsel stehen im Log); joined = erste Annahme (nach dem Anonymisieren des Protokolls: erste Standortmeldung)
function replayParts($id) { global $db;
  $s = $db->prepare("SELECT p.user_id,p.mode,p.role_id,p.left_at,COALESCE(u.name,p.label,'Einsatzkraft') name,r.name role,r.color,m.name mode_name,COALESCE((SELECT MIN(ts) FROM incident_log l WHERE l.incident_id=p.incident_id AND l.user_id=p.user_id AND l.kind='accept'),(SELECT MIN(ts) FROM track t WHERE t.incident_id=p.incident_id AND t.user_id=p.user_id)) joined
     FROM participants p LEFT JOIN users u ON u.id=p.user_id LEFT JOIN roles r ON r.id=p.role_id LEFT JOIN modes m ON m.code=p.mode WHERE p.incident_id=? AND p.mode<>'declined' ORDER BY p.id"); // label: vom Konto gelöste Teilnahme
  $s->execute([$id]); return array_map(fn($r) => ints($r, ['user_id', 'role_id', 'left_at', 'joined']), $s->fetchAll()); }
function incById($id) { global $db; $s = $db->prepare("SELECT * FROM incidents WHERE id=?"); $s->execute([(int)$id]); return $s->fetch() ?: fail('Einsatz nicht gefunden', 404); }

/* ---------- AI-Assistent (Issue #111) ---------- */
// Der Server baut den Kontext je Funktion selbst (gleiche Rechte wie die Ansicht) und fragt den Anbieter per curl; ein API-Schlüssel verlässt den Server nie.
// Funktionen: Schlüssel => [Bezeichnung, Bereich, Companion möglich, Beschreibung]
// Bereich: inc = je Einsatztyp (dazu Spezialrollen im Einsatz), plan = je Einsatztyp mit vorbereiteten Übungen, adm = Verwaltung ohne Einsatz, any = Einsatztypen und Verwaltung
const AI_FN = [
  'aar' => ['After Action Review', 'inc', 1, 'Entwürfe für die Textfelder aus Einsatzdaten und Einsatzlog; begleitend: Entwurf für die leeren Felder beim Öffnen'],
  'log' => ['Zusammenfassung des Einsatzlogs', 'inc', 1, 'Zusammenfassung in Worten zum Log (Verwaltung und Meine Einsätze); begleitend: beim Öffnen des Logs'],
  'sit' => ['Lagezusammenfassung im Einsatz', 'inc', 1, 'Kurze Lagezusammenfassung im laufenden Einsatz (☰-Menü); begleitend: höchstens alle 5 Minuten neu'],
  'plan' => ['Übungsvorbereitung', 'plan', 0, 'Vorschläge für weitere Elemente, Meldungen und den Ablauf einer vorbereiteten Übung'],
  'stats' => ['Statistik in Worten', 'adm', 0, 'Auswertung der Einsatzstatistik in Worten: Entwicklung, Auffälligkeiten'],
  'chat' => ['Freie Fragen', 'any', 0, 'Eigene Fragen im Fenster des AI-Assistenten zu den Daten der jeweiligen Ansicht (Einsatz, Log, Übung, Statistik); in der Verwaltung auch ohne Daten'],
];
const AI_MODES = ['off' => 'Aus', 'cmd' => 'Auf Abruf', 'comp' => 'Begleitend'];
const AI_RANK = ['off' => 0, 'cmd' => 1, 'comp' => 2];
const AI_PROV = ['openai' => 'OpenAI-kompatibel (Chat Completions, auch Ollama, LM Studio)', 'anthropic' => 'Anthropic (Claude)'];
// anon, round_loc: je Einsatztyp {Typ-ID: 0|1}, fehlt = 1; matrix {u<Benutzerrolle>|r<Spezialrolle>: {Funktion: {Typ-ID|adm: Modus}}}
const AI_DEFAULT = ['on' => 0, 'provider' => 'openai', 'base_url' => '', 'model' => '', 'timeout' => 60, 'max_tokens' => 1500, 'anon' => [], 'round_loc' => [], 'day_limit' => 200, 'user_limit' => 30, 'matrix' => null];
const AI_SIT_S = 300; // Lagezusammenfassung: so lange gilt die letzte Antwort (Companion)
const AI_CHAT_N = 10; // freie Fragen: so viele frühere Fragen mit Antwort gehen mit
// Spalten der Freigabe je Funktion: aktive Einsatztypen (ID als Text) und/oder 'adm'
function aiCtxs($fn) { $s = AI_FN[$fn][1]; $r = [];
  if ($s !== 'adm') foreach (kindsActive() as $k) if ($s !== 'plan' || $k['plans']) $r[] = (string)$k['id'];
  if ($s === 'adm' || $s === 'any') $r[] = 'adm'; return $r; }
// gilt auch für Einsätze gelöschter Typen (deren Einstellung bleibt)
function aiCtxOk($fn, $ctx) { $s = AI_FN[$fn][1]; if ($ctx === 'adm') return $s === 'adm' || $s === 'any'; if ($s === 'adm' || !ctype_digit($ctx)) return false;
  $k = kinds()[(int)$ctx] ?? null; return $k && ($s !== 'plan' || $k['plans']); }
// Einstellung ai (ohne Schlüssel, der steht getrennt in ai_key). Ältere Fassungen: Rollen-ID ohne «u», Arten real/ex (= Typen ohne/mit vorbereiteten Übungen), anon/round_loc als ein Wert
// Ohne Einstellung: Systemadministrator überall auf Abruf, ausser Lagezusammenfassung bei Typen ohne vorbereitete Übungen (scharfe Einsätze)
function aiCfg() { global $AI_CFG, $db; if ($AI_CFG !== null) return $AI_CFG;
  $c = json_decode((string)setting('ai', ''), true); $c = (is_array($c) ? $c : []) + AI_DEFAULT; $ks = kinds();
  if (is_array($c['matrix'])) { $m = [];
    foreach ($c['matrix'] as $r => $fs) { $r = (string)$r; if (ctype_digit($r)) $r = 'u' . $r; if (!is_array($fs)) continue;
      foreach ($fs as $f => $xs) foreach ((array)$xs as $x => $v) { $x = (string)$x;
        if ($x === 'real' || $x === 'ex') { foreach ($ks as $k) if (!$k['deleted'] && (bool)$k['plans'] === ($x === 'ex')) $m[$r][$f][(string)$k['id']] = $m[$r][$f][(string)$k['id']] ?? $v; }
        else $m[$r][$f][$x] = $v; } }
    $c['matrix'] = $m; }
  else { $c['matrix'] = [];
    foreach ($db->query("SELECT id FROM uroles WHERE sys='admin'")->fetchAll(PDO::FETCH_COLUMN) as $r) foreach (AI_FN as $f => $_) foreach (aiCtxs($f) as $x)
      $c['matrix']['u' . $r][$f][$x] = $f === 'sit' && !($ks[(int)$x]['plans'] ?? 0) ? 'off' : 'cmd'; }
  foreach (['anon', 'round_loc'] as $p) { $v = $c[$p]; $c[$p] = []; foreach ($ks as $k) $c[$p][(string)$k['id']] = is_array($v) ? (int)($v[(string)$k['id']] ?? 1) : (int)$v; }
  return $AI_CFG = $c; }
// Datenschutz je Einsatztyp (Namen ersetzen, Koordinaten runden); Verwaltung ohne Einsatz: immer an
function aiPriv($ctx, $p) { return $ctx === 'adm' ? 1 : (int)(aiCfg()[$p][(string)$ctx] ?? 1); }
function aiKey() { return (string)setting('ai_key', ''); }
// eingerichtet: eingeschaltet, Modell, bei Anthropic ein Schlüssel, sonst eine Basis-URL (eigene Server wie Ollama brauchen keinen Schlüssel)
function aiReady() { $c = aiCfg(); return (int)$c['on'] && trim((string)$c['model']) !== '' && ($c['provider'] === 'anthropic' ? aiKey() !== '' : trim((string)$c['base_url']) !== ''); }
// höchster erlaubter Modus über alle Benutzerrollen der Person und, im Einsatz, ihre Spezialrolle (wie die Rechte: der höchste gilt)
function aiMax($fn, $ctx, $role = null) { global $me; $ctx = (string)$ctx; if (!aiReady() || !isset(AI_FN[$fn]) || !aiCtxOk($fn, $ctx)) return 'off'; $m = aiCfg()['matrix']; $r = 0;
  foreach ($me['uroles'] as $u) $r = max($r, AI_RANK[$m['u' . $u['id']][$fn][$ctx] ?? 'off'] ?? 0);
  if ($role && $ctx !== 'adm' && AI_FN[$fn][1] !== 'plan') $r = max($r, AI_RANK[$m['r' . (int)$role][$fn][$ctx] ?? 'off'] ?? 0);
  if (!AI_FN[$fn][2]) $r = min($r, 1); return array_search($r, AI_RANK, true); }
// gewählter Modus der Person (users.ai_modes, fehlt = höchster erlaubter), höchstens der erlaubte
function aiMode($fn, $ctx, $role = null) { global $me; $mx = aiMax($fn, $ctx, $role); $w = $me['ai_modes'][$fn] ?? null; return is_string($w) && isset(AI_RANK[$w]) && AI_RANK[$w] < AI_RANK[$mx] ? $w : $mx; }
// Spezialrolle der Person im Einsatz (nur mit Teilnahme)
function aiRole($inc) { $p = part((int)$inc['id']); return $p && $p['mode'] !== 'declined' && $p['role_id'] !== null ? (int)$p['role_id'] : null; }
// Modi der einsatzbezogenen Funktionen je Einsatz (Zeilen mit id und kind_id): ai = {Funktion: Modus}, ohne eingerichteten Assistenten nichts
function aiRows($rows) { global $db, $uid; if (!aiReady()) return $rows;
  $s = $db->prepare("SELECT incident_id,role_id FROM participants WHERE user_id=? AND mode<>'declined'"); $s->execute([$uid]); $ro = $s->fetchAll(PDO::FETCH_KEY_PAIR);
  foreach ($rows as &$r) { $o = []; foreach (AI_FN as $f => $d) if ($d[1] === 'inc' || $d[1] === 'any') $o[$f] = aiMode($f, (string)(int)$r['kind_id'], $ro[(int)$r['id']] ?? null); $r['ai'] = $o; }
  unset($r); return $rows; }
function aiInc($inc) { return aiReady() ? aiRows([$inc])[0]['ai'] : null; }
// für me: je Funktion die Modi je Bereich (ohne Spezialrolle), den höchsten erlaubten (Profil, mit Spezialrollen) und die Wahl; null = für diese Person gibt es keinen AI-Assistenten
function aiMe() { global $me, $db; if (!aiReady()) return null; $r = []; $roles = array_map('intval', $db->query("SELECT id FROM roles WHERE active=1")->fetchAll(PDO::FETCH_COLUMN));
  foreach (AI_FN as $f => $x) { $mx = 0; $m = [];
    foreach (aiCtxs($f) as $k) { $m[$k] = aiMode($f, $k); $mx = max($mx, AI_RANK[aiMax($f, $k)]); if ($k !== 'adm') foreach ($roles as $ro) $mx = max($mx, AI_RANK[aiMax($f, $k, $ro)]); }
    if ($mx) $r[$f] = ['name' => $x[0], 'descr' => $x[3], 'modes' => (object)$m, 'max' => array_search($mx, AI_RANK, true), 'choice' => $me['ai_modes'][$f] ?? null]; }
  return $r ?: null; }
function aiT($ts) { return $ts === null ? '–' : (new DateTime('@' . (int)$ts))->setTimezone(new DateTimeZone('Europe/Zurich'))->format('d.m.Y H:i'); }
function aiHm($ts) { return $ts === null ? '–' : (new DateTime('@' . (int)$ts))->setTimezone(new DateTimeZone('Europe/Zurich'))->format('H:i'); }
function aiDur($s) { $s = max(0, (int)$s); return $s >= 3600 ? floor($s / 3600) . ' h ' . floor($s % 3600 / 60) . ' min' : floor($s / 60) . ' min'; }
// Standorte gerundet senden: Koordinaten auf 3 Nachkommastellen (ca. 100 m)
function aiRound($t) { return preg_replace('/(?<![\d.])(\d{1,3}\.\d{3})\d+/', '$1', $t); }
// Einsatz als Text für die KI; c = anonCtx (Bezeichnungen statt Namen) oder null
function aiIncText($inc, $c, $withLog = true) { $d = aarDoc($inc, $c, (bool)$c, false, false, null); $i = $d['inc']; $L = [];
  $L[] = 'Einsatztyp «' . kindOf($inc)['name'] . '»' . ($i['plan'] ? ' (vorbereitete Übung «' . ($c ? anonText($i['plan'], $c) : $i['plan']) . '»)' : '') . ', Alarm ' . aiT($i['alarm_at']) . ($i['ended_at'] ? ', Ende ' . aiT($i['ended_at']) . ', Dauer ' . aiDur($i['ended_at'] - $i['alarm_at']) : ', läuft seit ' . aiDur(time() - $i['alarm_at']));
  $L[] = 'Alarmmeldung: ' . ($i['message'] ?: '–');
  if ($i['creator']) $L[] = 'Ausgelöst von: ' . $i['creator'];
  if ($i['flow_ms'] !== null) $L[] = 'Fliessgeschwindigkeit: ' . round($i['flow_ms'] * 3.6, 1) . ' km/h';
  if ($i['lat'] !== null) $L[] = 'PLS (Point last seen) des Einsatzes: ' . sprintf('%.5F, %.5F', $i['lat'], $i['lon']);
  if ($i['first_accept']) $L[] = 'Erste Annahme: ' . aiDur($i['first_accept'] - $i['alarm_at']) . ' nach dem Alarm';
  if ($i['first_area']) $L[] = 'Suchgebiet erstmals erreicht: ' . aiDur($i['first_area'] - $i['alarm_at']) . ' nach dem Alarm';
  $L[] = ''; $L[] = 'Einsatzkräfte (' . count($d['people']) . '):';
  foreach ($d['people'] as $p) $L[] = '- ' . $p['name'] . ($p['mode'] ? ', ' . $p['mode'] : '') . ($p['role'] ? ', Spezialrolle ' . $p['role'] : '') . ($p['joined'] ? ', dabei ab ' . aiHm($p['joined']) : '') . ($p['left_at'] ? ', ausgetreten ' . aiHm($p['left_at']) : '');
  $res = array_filter($d['rescuees'], fn($r) => !$r['removed']);
  if ($res) { $L[] = ''; $L[] = 'Rettlinge:'; foreach ($res as $r) $L[] = '- ' . $r['name'] . ($r['loc_at'] ? ', Fundort gemeldet ' . aiHm($r['loc_at']) : ', vermisst') . ($r['rescued_at'] ? ', gerettet ' . aiHm($r['rescued_at']) : ''); }
  $ext = array_filter($d['externals'], fn($r) => !$r['removed']);
  if ($ext) { $L[] = ''; $L[] = 'Externe Einsatzkräfte:'; foreach ($ext as $r) $L[] = '- ' . $r['name'] . ($r['role'] ? ' (' . $r['role'] . ')' : '') . ($r['descr'] ? ': ' . $r['descr'] : ''); }
  if ($withLog) { $L[] = ''; $L[] = 'Einsatzlog (chronologisch, Zeit Person: Eintrag):'; $n = 0;
    foreach ($d['log'] as $r) { if (++$n > 600) { $L[] = '… (weitere Einträge gekürzt)'; break; } $L[] = aiHm($r['ts']) . ' ' . ($r['name'] ?: 'System') . ': ' . mb_substr((string)$r['text'], 0, 500); } }
  return [implode("\n", $L), $d]; }
const AI_SYS = 'Du unterstützt eine Einsatzgruppe der Wasserrettung (Fliessgewässer, Schweiz). Schreibe auf Deutsch in Schweizer Rechtschreibung (immer «ss», nie «ß»), sachlich und knapp. '
  . 'Bezeichnungen wie «Einsatzkraft 3», «Person 1», «Rettling 2» oder «Externe Einsatzkraft 1» sind Pseudonyme: übernimm sie unverändert und versuche nie, Personen zu erraten.';
const AI_FACTS = ' Stütze dich nur auf die gelieferten Daten und erfinde keine Fakten, Zeiten, Namen oder Zahlen; fehlt etwas, schreibe «(bitte ergänzen)».';
// Anfrage beim Anbieter: msgs = Text oder Liste [role, content] (user/assistant abwechselnd); Rückgabe [Text, Tokens, Modell], Fehler als AiErr
function aiCall($sys, $msgs, $maxTok = null) { $c = aiCfg(); $key = aiKey();
  if ($c['provider'] === 'anthropic' && $key === '') fail('Für Anthropic ist kein API-Schlüssel hinterlegt', 503);
  if (!function_exists('curl_init')) fail('Auf dem Server fehlt curl', 503);
  if (is_string($msgs)) $msgs = [['role' => 'user', 'content' => $msgs]];
  $mt = (int)($maxTok ?? $c['max_tokens']); $model = trim((string)$c['model']); @set_time_limit((int)$c['timeout'] + 20);
  if ($c['provider'] === 'anthropic') { $url = rtrim(trim((string)$c['base_url']) ?: 'https://api.anthropic.com', '/') . '/v1/messages';
    $h = ['Content-Type: application/json', 'x-api-key: ' . $key, 'anthropic-version: 2023-06-01'];
    $body = ['model' => $model, 'max_tokens' => $mt, 'system' => $sys, 'messages' => $msgs]; }
  else { $url = rtrim(trim((string)$c['base_url']), '/') . '/chat/completions'; $h = ['Content-Type: application/json']; if ($key !== '') $h[] = 'Authorization: Bearer ' . $key;
    $body = ['model' => $model, 'max_tokens' => $mt, 'temperature' => 0.3, 'messages' => array_merge([['role' => 'system', 'content' => $sys]], $msgs)]; }
  $k = curl_init($url); curl_setopt_array($k, CURL_CA + [CURLOPT_POST => 1, CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE), CURLOPT_HTTPHEADER => $h, CURLOPT_RETURNTRANSFER => 1,
    CURLOPT_TIMEOUT => (int)$c['timeout'], CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_USERAGENT => 'SwiftwaterRescue/0.1']);
  $r = curl_exec($k); $code = curl_getinfo($k, CURLINFO_HTTP_CODE); $err = $r === false ? (curl_errno($k) === CURLE_OPERATION_TIMEDOUT ? 'Zeitüberschreitung' : curl_error($k)) : ''; // ohne curl_close: wirkungslos seit PHP 8.0, veraltet ab 8.5
  if ($r === false) throw new AiErr('Keine Antwort vom AI-Anbieter: ' . $err);
  $j = json_decode($r, true);
  if ($code !== 200) { $m = is_array($j) ? ($j['error']['message'] ?? (is_string($j['error'] ?? null) ? $j['error'] : null) ?? $j['message'] ?? null) : null; throw new AiErr('AI-Anbieter (HTTP ' . $code . '): ' . mb_substr((string)($m ?? $r), 0, 300)); }
  if ($c['provider'] === 'anthropic') { $t = implode('', array_map(fn($x) => $x['text'] ?? '', array_filter((array)($j['content'] ?? []), fn($x) => ($x['type'] ?? '') === 'text'))); $tok = (int)($j['usage']['input_tokens'] ?? 0) + (int)($j['usage']['output_tokens'] ?? 0); }
  else { $t = (string)($j['choices'][0]['message']['content'] ?? ''); $tok = (int)($j['usage']['total_tokens'] ?? 0); }
  if (trim($t) === '') throw new AiErr('Der AI-Anbieter lieferte keine Antwort');
  return [trim($t), $tok, (string)($j['model'] ?? $model)]; }
class AiErr extends Exception {}
function aiUse($fn, $incId, $tok, $ok, $err = null) { global $db, $now, $uid; if (!$ok) alog('warn', 'ai', 'Anfrage fehlgeschlagen', ['fn' => $fn, 'incident' => $incId, 'err' => $err === null ? null : mb_substr((string)$err, 0, 200)]);
$db->prepare("INSERT INTO ai_usage(ts,user_id,fn,incident_id,tokens,ok,err) VALUES(?,?,?,?,?,?,?)")->execute([$now, $uid, $fn, $incId, (int)$tok, $ok ? 1 : 0, $err === null ? null : mb_substr($err, 0, 300)]); }
// Kostenbremse: Abfragen der letzten 24 Stunden gesamt und je Person (0 = unbegrenzt)
function aiLimit() { global $db, $now, $uid; $c = aiCfg();
  $q = fn($w, $a) => (int)(function () use ($db, $w, $a) { $s = $db->prepare("SELECT COUNT(*) FROM ai_usage WHERE ts>? $w"); $s->execute($a); $v = $s->fetchColumn(); $s->closeCursor(); return $v; })();
  if ((int)$c['day_limit'] && $q('', [$now - 86400]) >= (int)$c['day_limit']) fail('Tageslimit des AI-Assistenten erreicht (' . (int)$c['day_limit'] . ' Abfragen in 24 Stunden)', 429);
  if ((int)$c['user_limit'] && $q('AND user_id=?', [$now - 86400, $uid]) >= (int)$c['user_limit']) fail('Dein Tageslimit für den AI-Assistenten ist erreicht (' . (int)$c['user_limit'] . ' Abfragen in 24 Stunden)', 429); }
// Pseudonyme der Antwort zurück in die Namen, die die Person sehen darf: c = Kontext der Anfrage, cv = anonCtx der Ansicht oder null (Namen)
function aiDeanon($t, $c, $cv, $id) { global $db; if (!$c) return $t; $map = [];
  $nm = $db->query("SELECT id,name FROM users")->fetchAll(PDO::FETCH_KEY_PAIR);
  foreach ($c['u'] as $u => $l) $map[$l] = $cv ? ($cv['u'][$u] ?? $l) : ($nm[$u] ?? $l);
  foreach (['r' => 'rescuees', 'e' => 'externals'] as $k => $tb) { $s = $db->prepare("SELECT id,name FROM $tb WHERE incident_id=?"); $s->execute([$id]); $n = $s->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach ($c[$k] as $x => $l) $map[$l] = $map[$l] ?? ($cv ? ($cv[$k][$x] ?? $l) : (trim((string)($n[$x] ?? '')) !== '' ? $n[$x] : $l)); }
  $map = array_filter($map, fn($v, $k) => $v !== $k, ARRAY_FILTER_USE_BOTH); if (!$map) return $t;
  $ks = array_keys($map); usort($ks, fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));
  return preg_replace_callback('/(?<![\p{L}\p{N}])(?:' . implode('|', array_map(fn($k) => preg_quote($k, '/'), $ks)) . ')(?![\p{L}\p{N}])/u', fn($m) => $map[$m[0]], $t) ?? $t; }
function aiJson($t) { if (preg_match('/\{.*\}/s', $t, $m)) { $j = json_decode($m[0], true); if (is_array($j)) return $j; } return null; }
// Übungsvorbereitung: vorgeschlagene Elemente prüfen und Standorte aus Bezugspunkt + Versatz (Meter Ost/Nord) berechnen.
// Ergebnis je Element in den Feldern von ex/inject/save; ref/of = E<id> (bestehend) oder N… (anderer Vorschlag, wird beim Erstellen ersetzt); ll null = kein Bezugspunkt
function aiPlanEls($L, $refs) { global $db; $R = [];
  foreach ($db->query("SELECT id,name FROM roles WHERE active=1")->fetchAll() as $r) $R[mb_strtolower(trim($r['name']))] = (int)$r['id'];
  $S = fn($v, $n) => mb_substr(trim(is_scalar($v) ? (string)$v : ''), 0, $n);
  $M = fn($v) => is_numeric($v) && $v >= 0 && $v <= 1440 ? (int)round($v) : null;
  $out = []; $own = [];
  foreach (array_slice(is_array($L) ? array_values($L) : [], 0, 12) as $e) { if (!is_array($e)) continue;
    $k = (string)($e['kind'] ?? ''); if (!isset(EX_KINDS[$k])) continue;
    $label = $S($e['label'] ?? '', 120); if ($label === '') continue;
    $key = preg_replace('/[^A-Za-z0-9]/', '', (string)($e['key'] ?? '')) ?: 'N' . (count($out) + 1); if (isset($refs[$key]) || isset($own[$key])) $key = 'N' . (count($out) + 1) . 'x';
    $o = ['key' => $key, 'kind' => $k, 'label' => $label, 'msg' => $S($e['msg'] ?? '', 2000), 'auto' => $M($e['auto'] ?? null), 'ago' => in_array($k, ['pls', 'res', 'seen'], true) ? $M($e['ago'] ?? null) : 0, 'll' => null, 'at' => null, 'east_m' => 0, 'north_m' => 0];
    $find = fn($r) => $refs[$r] ?? $own[$r] ?? null;
    if ($k === 'extdel') { $of = (string)($e['of'] ?? ''); $t = $find($of); if (!$t || $t['k'] !== 'ext') continue; $o['of'] = $of; $o['ll'] = $t['ll']; $o['at'] = $t['label']; }
    else { $at = (string)($e['at'] ?? ''); $t = $find($at);
      if ($t && $t['ll']) { $ea = max(-30000, min(30000, (float)($e['east_m'] ?? 0))); $no = max(-30000, min(30000, (float)($e['north_m'] ?? 0)));
        $la = $t['ll'][0] + $no / 111320; $o['ll'] = [round($la, 6), round($t['ll'][1] + $ea / (111320 * cos(deg2rad($la))), 6)]; $o['at'] = $t['label']; $o['east_m'] = (int)round($ea); $o['north_m'] = (int)round($no); } }
    if ($k === 'res' || $k === 'ext') $o['name'] = $S($e['name'] ?? '', 80) ?: mb_substr($label, 0, 80);
    if ($k === 'res' || $k === 'seen' || $k === 'ext') $o['descr'] = $S($e['descr'] ?? '', $k === 'ext' ? 500 : 1000);
    if ($k === 'seen') { $of = (string)($e['of'] ?? 'unk'); $t = $find($of);
      if ($of === 'new') $o['name'] = $S($e['name'] ?? '', 80) ?: mb_substr($label, 0, 80);
      elseif ($t && $t['k'] === 'res') { $o['of'] = $of; $o['of_label'] = $t['label']; } else $of = 'unk';
      if ($of === 'new' || $of === 'unk') $o['of'] = $of; }
    if ($k === 'ext') { $rn = mb_strtolower($S($e['role'] ?? '', 80)); $o['role_id'] = $R[$rn] ?? null; $o['role'] = $o['role_id'] ? $S($e['role'], 80) : null; $o['abbr'] = $S($e['abbr'] ?? '', 4); }
    $own[$key] = ['ll' => $o['ll'], 'label' => $label, 'k' => $k === 'ext' ? 'ext' : ($k === 'res' || $k === 'seen' && in_array($o['of'] ?? '', ['new', 'unk'], true) ? 'res' : null)];
    $out[] = $o; }
  return $out; }
// Eingaben der Verwaltung prüfen (ai/cfg/save)
function aiIn($in) { global $db; $o = aiCfg(); $c = [];
  $c['on'] = (int)!empty($in['on'] ?? $o['on']);
  $c['provider'] = (string)($in['provider'] ?? $o['provider']); if (!isset(AI_PROV[$c['provider']])) fail('Unbekannter Anbieter');
  $u = trim((string)($in['base_url'] ?? $o['base_url']));
  // eigene Server (z. B. Ollama im eigenen Netz) auch über http
  if ($u !== '' && (strlen($u) > 300 || !filter_var($u, FILTER_VALIDATE_URL) || !preg_match('#^https?://[^/?\#@\s]+(/|$)#i', $u))) fail('Ungültige Basis-URL (https://… oder http://…)');
  if ($u === '' && $c['provider'] === 'openai' && $c['on']) fail('Für OpenAI-kompatible Anbieter braucht es eine Basis-URL');
  $c['base_url'] = $u;
  $c['model'] = mb_substr(trim((string)($in['model'] ?? $o['model'])), 0, 120); if ($c['on'] && $c['model'] === '') fail('Bitte ein Modell angeben');
  foreach (['timeout' => [5, 300], 'max_tokens' => [100, 8000], 'day_limit' => [0, 100000], 'user_limit' => [0, 10000]] as $k => [$a, $b]) {
    $v = $in[$k] ?? $o[$k]; if (!is_numeric($v) || (int)$v < $a || (int)$v > $b) fail("Ungültiger Wert für $k ($a bis $b)"); $c[$k] = (int)$v; }
  // Datenschutz je Einsatztyp; nicht übergebene (z. B. gelöschte Typen) behalten ihren Wert
  foreach (['anon', 'round_loc'] as $p) { $c[$p] = $o[$p]; foreach ((array)($in[$p] ?? []) as $k => $v) if (isset(kinds()[(int)$k]) && ctype_digit((string)$k)) $c[$p][(string)$k] = (int)!empty($v); $c[$p] = (object)$c[$p]; }
  $ids = ['u' => array_map('strval', $db->query("SELECT id FROM uroles")->fetchAll(PDO::FETCH_COLUMN)), 'r' => array_map('strval', $db->query("SELECT id FROM roles")->fetchAll(PDO::FETCH_COLUMN))]; $m = [];
  foreach ((array)($in['matrix'] ?? $o['matrix']) as $r => $fs) { $r = (string)$r; $t = substr($r, 0, 1); if (!isset($ids[$t]) || !in_array(substr($r, 1), $ids[$t], true) || !is_array($fs)) continue;
    foreach ($fs as $f => $ks) { if (!isset(AI_FN[$f]) || !is_array($ks) || $t === 'r' && AI_FN[$f][1] !== 'inc' && AI_FN[$f][1] !== 'any') continue;
      foreach ($ks as $k => $v) { $k = (string)$k; if (!aiCtxOk($f, $k) || $t === 'r' && $k === 'adm' || !isset(AI_MODES[$v])) continue; if ($v === 'comp' && !AI_FN[$f][2]) $v = 'cmd'; $m[$r][$f][$k] = $v; } } }
  $c['matrix'] = (object)$m; return $c; }
// Kontext und Auftrag je Funktion; prüft die Rechte wie die zugehörige Ansicht.
// Rückgabe: p = Auftrag mit Daten, data = nur die Daten (freie Fragen), ctx = Einsatztyp-ID oder adm, inc = Einsatz-ID, c = anonCtx der Anfrage, cv = der Ansicht, role = Spezialrolle, x = Zusatz
// chat = 1: nur die Daten (freie Fragen dazu), ohne die Prüfungen des Auftrags (z. B. AAR: alle Felder ausgefüllt)
function aiTask($fn, $in) { global $db, $uid, $now; $q = trim(mb_substr((string)($in['q'] ?? ''), 0, 2000)); $chat = !empty($in['chat']);
  $wish = $q !== '' && !$chat ? "\n\nZusätzlicher Wunsch der Person: $q" : '';
  if ($fn === 'aar') { $inc = incById($in['id'] ?? 0); aarEditable($inc); $id = (int)$inc['id']; $ctx = (string)(int)$inc['kind_id'];
    $forced = (int)$inc['log_anon'] || $inc['rp_anon'] !== null || (int)$inc['view_anon']; $c = aiPriv($ctx, 'anon') || $forced ? anonCtx($id) : null; $cv = $forced ? anonCtx($id) : null;
    [$txt, $d] = aiIncText($inc, $c); $data = (array)$d['data'];
    $known = []; foreach (aarCfg()['fields'] as $f) if (in_array($f['type'], ['head', 'img'], true) || trim(is_scalar($data[$f['id']] ?? '') ? (string)($data[$f['id']] ?? '') : 'x') === '') continue; else $known[] = '- ' . $f['label'] . ': ' . mb_substr(is_scalar($data[$f['id']]) ? (string)$data[$f['id']] : json_encode($data[$f['id']], JSON_UNESCAPED_UNICODE), 0, 1500);
    $dt = "=== Einsatzdaten ===\n$txt" . ($known ? "\n\n=== Bereits erfasste Angaben im After Action Review ===\n" . implode("\n", $known) : '');
    $r = ['data' => $dt, 'ctx' => $ctx, 'inc' => $id, 'c' => $c, 'cv' => $cv, 'role' => aiRole($inc), 'p' => '', 'x' => []]; if ($chat) return $r;
    $fs = array_values(array_filter(aarCfg()['fields'], fn($f) => in_array($f['type'], ['text', 'area'], true)));
    $only = isset($in['field']) ? (string)$in['field'] : null; if ($only !== null) $fs = array_values(array_filter($fs, fn($f) => $f['id'] === $only));
    elseif (empty($in['all'])) $fs = array_values(array_filter($fs, fn($f) => trim((string)($data[$f['id']] ?? '')) === ''));
    if (!$fs) fail($only !== null ? 'Für dieses Feld gibt es keinen Vorschlag' : 'Alle Textfelder sind bereits ausgefüllt');
    $ask = implode("\n", array_map(fn($f) => '- ' . $f['id'] . ' (' . ($f['type'] === 'area' ? 'mehrzeilig, einige Sätze oder Stichpunkte' : 'einzeilig, kurz') . '): ' . $f['label'] . ($f['hint'] ? ' – Hinweis: ' . $f['hint'] : '') . (trim((string)($data[$f['id']] ?? '')) !== '' ? "\n  Bisheriger Text (überarbeiten, Inhalt beibehalten): " . mb_substr((string)$data[$f['id']], 0, 3000) : ''), $fs));
    $r['p'] = "Erstelle Entwürfe für Felder eines After Action Review (Nachbesprechung) zu diesem Einsatz.\n\n$dt\n\n"
      . "=== Gesuchte Felder (ID, Art: Bezeichnung) ===\n$ask\n\nAntworte ausschliesslich mit einem JSON-Objekt {\"<Feld-ID>\": \"<Text>\"} für genau diese Felder, ohne weitere Erklärung. Was sich aus den Daten nicht ableiten lässt (z. B. Bewertungen, Gefühle, Lehren), formuliere als Frage zur Nachbesprechung oder «(bitte ergänzen)».$wish";
    $r['x'] = ['fields' => array_column($fs, 'id'), 'key' => $only ?? (empty($in['all']) ? 'empty' : 'all')]; return $r; }
  if ($fn === 'log') { $inc = incById($in['id'] ?? 0); $id = (int)$inc['id']; $ctx = (string)(int)$inc['kind_id']; $v = incView($id); if ($v && !myBtns()['log']) fail('Das Einsatzlog ist für Einsatzkräfte ausgeschaltet', 403);
    $va = $v === 2 || (int)$inc['view_anon'] || ($v && anonAny($inc)); $self = $v ? $uid : null; $c = aiPriv($ctx, 'anon') || $va ? anonCtx($id) : null; $cv = $va ? anonCtx($id, $self) : null;
    [$txt] = aiIncText($inc, $c);
    $p = "Fasse diesen Einsatz anhand der Daten und des Einsatzlogs zusammen. Gliederung mit kurzen Zwischentiteln: Überblick (2–3 Sätze), Ablauf mit den wichtigsten Zeitpunkten, Kräfte und Rettlinge, Auffälligkeiten (z. B. lange Wartezeiten, offene Punkte). Höchstens 300 Wörter, keine Bewertung von Personen.\n\n$txt$wish";
    return ['p' => $p, 'data' => $txt, 'ctx' => $ctx, 'inc' => $id, 'c' => $c, 'cv' => $cv, 'role' => aiRole($inc), 'x' => ['key' => $v ? "v$v:$self" : 'adm']]; }
  if ($fn === 'sit') { $inc = activeIncident(); if (!$inc || (int)$inc['id'] !== (int)($in['id'] ?? 0)) fail('Der Einsatz läuft nicht mehr', 409); $id = (int)$inc['id']; $ctx = (string)(int)$inc['kind_id'];
    $pp = part($id); if (!(($pp && $pp['mode'] !== 'declined') || canManage($inc) || exLead($inc))) fail('Die Lagezusammenfassung gibt es nur für Teilnehmende', 403);
    $c = aiPriv($ctx, 'anon') ? anonCtx($id) : null; [$txt, $d] = aiIncText($inc, $c, false);
    $s = $db->prepare("SELECT p.user_id,p.lat,p.lon,p.loc_at,p.left_at,p.mode FROM participants p WHERE p.incident_id=? AND p.mode<>'declined'"); $s->execute([$id]); $loc = [];
    foreach ($s->fetchAll() as $r) if ($r['lat'] !== null && $r['left_at'] === null) $loc[] = '- ' . ($c ? ($c['u'][(int)$r['user_id']] ?? 'Einsatzkraft') : ($db->query('SELECT name FROM users WHERE id=' . (int)$r['user_id'])->fetchColumn() ?: '?')) . ': ' . sprintf('%.5F, %.5F', $r['lat'], $r['lon']) . ($r['loc_at'] ? ' (Stand ' . aiHm($r['loc_at']) . ')' : '');
    $src = array_map(fn($x) => sprintf('%.5F, %.5F', $x['lat'], $x['lon']) . (isset($x['at']) ? ' seit ' . aiHm($x['at']) : ''), plsSources($inc, incRes($inc)));
    $log = array_slice($d['log'], -80); $lt = implode("\n", array_map(fn($r) => aiHm($r['ts']) . ' ' . ($r['name'] ?: 'System') . ': ' . mb_substr((string)$r['text'], 0, 300), $log));
    $dt = "Jetzt: " . aiHm($now) . "\n\n$txt\n\nPLS fürs Suchgebiet:\n" . ($src ? '- ' . implode("\n- ", $src) : '–') . "\n\nStandorte der Einsatzkräfte:\n" . ($loc ? implode("\n", $loc) : '–') . "\n\nLetzte Log-Einträge und Nachrichten:\n" . ($lt ?: '–');
    $p = "Erstelle eine kurze Lagezusammenfassung für den laufenden Einsatz. Höchstens 8 kurze Punkte: Lage und Auftrag, Suchgebiet/PLS, eingesetzte Kräfte und Positionen, Rettlinge, offene Punkte und nächste sinnvolle Schritte (nur als Hinweis, keine Befehle).\n\n$dt$wish";
    return ['p' => $p, 'data' => $dt, 'ctx' => $ctx, 'inc' => $id, 'c' => $c, 'cv' => null, 'role' => aiRole($inc), 'x' => ['key' => 'u']]; }
  if ($fn === 'plan') { plannerOnly(); $s = $db->prepare("SELECT * FROM ex_plans WHERE id=? AND active=1"); $s->execute([(int)($in['id'] ?? 0)]); $pl = $s->fetch(); $s->closeCursor(); if (!$pl) fail('Übung nicht gefunden', 404);
    $k = planKind($pl); if (!$k) fail('Kein Einsatztyp erlaubt vorbereitete Übungen', 409);
    $roles = $db->query("SELECT name FROM roles WHERE active=1 ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    // Bezugspunkte für neue Elemente: bestehende Elemente (E<id>) und die festen Standorte der Übung (P<id>); Koordinaten rechnet aiPlanEls aus Versatz in Metern
    $J = exInjects((int)$pl['id']); $refs = [];
    foreach ($J as $j) if ($j['lat'] !== null) $refs['E' . $j['id']] = ['ll' => [(float)$j['lat'], (float)$j['lon']], 'k' => $j['kind'] === 'ext' ? 'ext' : ($j['kind'] === 'res' || $j['kind'] === 'seen' && $j['ref_id'] === null ? 'res' : null), 'label' => $j['label']];
    $fp = array_values(array_filter(placesOf(null), fn($r) => $r['active'])); $sel = placeSel($pl); if ($sel !== null) $fp = array_values(array_filter($fp, fn($r) => in_array($r['id'], $sel, true)));
    $fp = array_merge($fp, placesOf((int)$pl['id']));
    foreach ($fp as $r) $refs['P' . $r['id']] = ['ll' => [(float)$r['lat'], (float)$r['lon']], 'k' => null, 'label' => $r['name']];
    $pt = implode("\n", array_map(fn($r) => '- [P' . $r['id'] . '] ' . $r['name'] . ($r['descr'] ? ', ' . $r['descr'] : '') . sprintf(', Standort %.5F, %.5F', $r['lat'], $r['lon']), $fp));
    $el = array_map(fn($j) => '- [E' . $j['id'] . '] ' . (EX_KINDS[$j['kind']] ?? $j['kind']) . ': ' . $j['label'] . ($j['name'] ? ', Name ' . $j['name'] : '') . ($j['descr'] ? ', ' . $j['descr'] : '') . ($j['lat'] !== null ? sprintf(', Standort %.5F, %.5F', $j['lat'], $j['lon']) : '') . ($j['auto'] !== null ? ', automatisch ' . (int)$j['auto'] . ' min nach dem Start' : ', Freigabe von Hand') . ((int)$j['ago'] ? ', PLS ' . (int)$j['ago'] . ' min vor der Freigabe' : '') . ($j['msg'] ? ', Meldung: ' . $j['msg'] : '') . ($j['released'] !== null ? ' (bereits freigegeben)' : ''), $J);
    $dt = "Übung (Einsatztyp «{$k['name']}»): {$pl['title']}\nAufgebotsmeldung: " . ($pl['message'] ?: '–') . "\nSpezialrollen: " . (implode(', ', $roles) ?: '–') . "\n\nBisherige Elemente (Arten: " . implode(', ', EX_KINDS) . "):\n" . ($el ? implode("\n", $el) : '– (noch keine)') . "\n\nFeste Standorte:\n" . ($pt ?: '–');
    if (!empty($in['chat'])) return ['p' => '', 'data' => $dt, 'ctx' => (string)$k['id'], 'inc' => null, 'c' => null, 'cv' => null, 'role' => null, 'x' => []];
    $p = "Hilf bei der Vorbereitung einer Übung mit Vorschlägen für weitere Elemente. Antworte nur mit einem JSON-Objekt, ohne Text davor oder danach:\n"
      . "{\"text\": \"Hinweise zum Ablauf und zu Lernzielen, höchstens 200 Wörter\", \"elements\": [höchstens 8 neue Elemente in der Reihenfolge des Ablaufs]}\n"
      . "Jedes Element: {\"key\": \"N1\" (eigene Kennung N1, N2 …), \"kind\": \"pls|res|seen|ext|extdel\", \"label\": \"Bezeichnung\", \"name\": \"Name (bei res, ext und seen mit of new)\", \"descr\": \"Beschreibung (res, seen, ext)\", "
      . "\"at\": \"Bezugspunkt: E… (bestehendes Element), P… (fester Standort) oder N… (früheres neues Element)\", \"east_m\": Meter nach Osten (negativ = Westen), \"north_m\": Meter nach Norden (negativ = Süden), "
      . "\"of\": \"bei seen: gesichteter Rettling (E…/N… eines Elements res oder seen) oder new (neuer Rettling) oder unk (unbekannter Rettling); bei extdel: die externe Einsatzkraft (E…/N… eines Elements ext), dann ohne at\", "
      . "\"role\": \"Spezialrolle aus der Liste (nur ext)\", \"abbr\": \"Kürzel bis 4 Zeichen (nur ext)\", \"auto\": Minuten nach dem Start für die automatische Freigabe oder null (von Hand), \"ago\": PLS-Zeitpunkt in Minuten vor der Freigabe oder null (nur pls, res, seen), \"msg\": \"Meldung an die Teilnehmenden bei der Freigabe\"}\n"
      . "Arten: pls = PLS des Einsatzes setzen oder verschieben, res = neuer vermisster Rettling mit eigenem PLS, seen = Rettling gesichtet (neuer PLS), ext = externe Einsatzkraft (z. B. Posten einer anderen Organisation), extdel = externe Einsatzkraft abziehen. "
      . "Standorte immer relativ zu einem Bezugspunkt mit Versatz in Metern, keine Koordinaten und keine erfundenen Ortsnamen. Die Fliessrichtung ist nicht bekannt: leite sie aus den bestehenden Elementen ab, sonst kleine Abstände (bis ca. 1 km). Ohne Bezugspunkt at weglassen.\n\n$dt$wish";
    return ['p' => $p, 'data' => $dt, 'ctx' => (string)$k['id'], 'inc' => null, 'c' => null, 'cv' => null, 'role' => null, 'x' => ['key' => 'p' . (int)$pl['id'], 'refs' => $refs]]; }
  if ($fn === 'stats') { need('stats'); $rows = $db->query("SELECT i.id,i.kind_id,i.alarm_at,i.ended_at,(SELECT COUNT(*) FROM participants p WHERE p.incident_id=i.id AND p.mode<>'declined') acc,(SELECT MIN(ts) FROM incident_log l WHERE l.incident_id=i.id AND l.kind='accept') fa,(SELECT MIN(ts) FROM incident_log l WHERE l.incident_id=i.id AND l.kind='area') far,(SELECT COUNT(*) FROM rescuees r WHERE r.incident_id=i.id AND r.removed IS NULL) res,(SELECT COUNT(*) FROM rescuees r WHERE r.incident_id=i.id AND r.removed IS NULL AND r.rescued_at IS NOT NULL) rsd FROM incidents i WHERE i.ended_at IS NOT NULL ORDER BY i.id DESC LIMIT 200")->fetchAll();
    if (!$rows) fail('Es gibt noch keine beendeten Einsätze');
    $L = array_map(fn($r) => '- ' . kindOf($r)['name'] . ' ' . aiT($r['alarm_at']) . ', Dauer ' . aiDur($r['ended_at'] - $r['alarm_at']) . ', ' . (int)$r['acc'] . ' Einsatzkräfte' . ($r['fa'] ? ', erste Annahme nach ' . aiDur($r['fa'] - $r['alarm_at']) : '') . ($r['far'] ? ', Suchgebiet nach ' . aiDur($r['far'] - $r['alarm_at']) : '') . ((int)$r['res'] ? ', Rettlinge ' . (int)$r['res'] . ' (gerettet ' . (int)$r['rsd'] . ')' : ''), $rows);
    $dt = "Beendete Einsätze (neueste zuerst, heute ist " . aiT($now) . ", Einsatztyp am Anfang jeder Zeile):\n" . implode("\n", $L);
    $p = "Werte diese Einsatzstatistik in Worten aus (ohne Personen): Anzahl und Entwicklung je Einsatztyp, Reaktionszeiten (Alarm bis erste Annahme, bis Suchgebiet), Anzahl Einsatzkräfte, Auffälligkeiten und Anregungen. Die Einsatztypen getrennt betrachten (Übungen nicht mit scharfen Einsätzen vermischen). Höchstens 300 Wörter.\n\n$dt$wish";
    return ['p' => $p, 'data' => $dt, 'ctx' => 'adm', 'inc' => null, 'c' => null, 'cv' => null, 'role' => null, 'x' => ['key' => 's']]; }
  fail('Unbekannte Funktion'); }
// Aufgebot (Issue #90): Wer nicht aufgeboten ist, sieht einen Einsatz ohne open_all des Typs nicht und kann nichts daran tun (ausser die Einsatzleitung erlaubt allen den Beitritt, open_join)
if (strncmp($route, 'incident/', 9) === 0 && !in_array($route, ['incident/start', 'incident/state', 'incident/log', 'incident/replay', 'incident/anon/replay', 'incident/anon/log', 'incident/replay/delete'], true) && ($inc = activeIncident()) && !incAllowed($inc))
  fail('Du bist für diesen Einsatz nicht aufgeboten', 403);

// Einrückoption ohne Standortfreigabe: Standortmeldungen werden nicht gespeichert (serverseitig erzwungen)
if ($route === 'incident/location' && ($inc = activeIncident()) && ($p = part($inc['id'])) && !modeShares($p['mode'])) out(['ok' => true, 'shared' => false]);

// ausgeschaltete Funktionen serverseitig sperren (FEATURE_ROUTES)
if (array_key_exists($route, FEATURE_ROUTES) && ($inc = activeIncident()) && !feature($inc, FEATURE_ROUTES[$route]))
  fail('Die Funktion «' . FEATURES[FEATURE_ROUTES[$route]] . '» ist für «' . kindOf($inc)['name'] . '» ausgeschaltet', 403);

try { anonAuto(); } catch (Throwable $e) { alog('err', 'anon', 'Automatische Anonymisierung fehlgeschlagen: ' . $e->getMessage(), ['file' => basename($e->getFile()), 'line' => $e->getLine()]); } // fällige automatische Anonymisierung (Issue #87)
// Applikationslog (Issue #139): Einträge älter als applog_days löschen, höchstens einmal pro Stunde
try { if ((int)setting('applog_purge_at', 0) < $now - 3600) { setSetting('applog_purge_at', (string)$now); $db->prepare("DELETE FROM app_log WHERE at<?")->execute([$now - applogDays() * 86400]); } } catch (Throwable $e) {}
switch ($route) {
 case 'me': out(['me' => $me + ['coord_default' => setting('coord_sys', 'lv95'), 'rd_speeds' => rdSpeeds(), 'kinds' => kindsPub(), 'nav_apps' => navApps(), 'nav_default' => navDefault(), 'features' => featuresAll(), 'my_runs' => myRuns(), 'groups' => myGroups(), 'absent' => absNow($uid)[$uid] ?? null, 'ai' => aiMe(), 'org' => legalCfg()['org'], 'session' => $sid ? 1 : 0]]); // org: Name der Organisation, damit die native App das Backend benennen kann (Issue #108); session 1 = Gerätesitzung // ai: AI-Assistent je Funktion (Issue #111) // my_runs: Meine Einsätze (Issue #61); coord_sys null = Standard der Verwaltung; rd_speeds für das Werkzeug Flussdistanz; kinds: Einsatztypen für «… starten»; features je Einsatztyp für die Flussdistanz ohne Einsatz
 case 'roles': out(array_map(fn($r) => ints($r, ['id', 'is_lead', 'is_post', 'can_message']), $db->query("SELECT id,name,color,is_lead,is_post,can_message FROM roles WHERE active=1 ORDER BY id")->fetchAll()));
 case 'modes': // alle, auch deaktivierte (für die Anzeige bestehender Teilnahmen)
  out(array_map(fn($r) => ints($r, ['id', 'share_location', 'active', 'lead_rights', 'counts']), $db->query("SELECT id,code,name,share_location,active,lead_rights,counts FROM modes ORDER BY id")->fetchAll()));

 case 'overpass': // Gewässernetz für Suchgebiet und Flussdistanz (alle angemeldeten Personen): around [m, lat, lon] oder bbox [s, w, n, e]; Antwort wie Overpass
  overpassServe(overpassQuery($in), overpassArea($in));
 case 'overpass/stat': // Ergebnisse der direkten Abfragen des Geräts (Rückfall bei 502) für die Statistik: results [{url, ok, ms, err}], nur hinterlegte Server
  foreach (array_slice((array)($in['results'] ?? []), 0, 8) as $x) {
    if (!is_array($x) || !in_array($x['url'] ?? null, overpassActive(), true)) continue;
    opStat($x['url'], 'device', !empty($x['ok']), min(max(0, (float)($x['ms'] ?? 0)), 120000), empty($x['ok']) ? (mb_substr(trim((string)($x['err'] ?? '')), 0, 150) ?: 'Fehler') : null);
  }
  out(['ok' => true]);
 case 'overpass/stats': need('cfg_map'); // Statistik für die Verwaltung (Gewässerdaten)
  out(['since' => (int)setting('overpass_stats_since', '0') ?: null, 'rows' => array_map(fn($r) => ints($r, ['uses', 'ok', 'fails', 'ok_ms', 'last_ok', 'last_fail']), $db->query("SELECT * FROM overpass_stats ORDER BY url, src")->fetchAll())]);
 case 'overpass/stats/reset': need('cfg_map');
  $db->exec("DELETE FROM overpass_stats"); setSetting('overpass_stats_since', (string)time()); out(['ok' => true]);
 case 'overpass/put': // Ergebnis einer direkten Abfrage des Geräts (Rückfall bei 502), gleiche Parameter wie overpass plus elements
  overpassPut(overpassQuery($in), $in['elements'] ?? null);

 case 'incident/air': // Helikopter in der Relevanzzone des laufenden Einsatzes (Issue #73), Abruf alle paar Sekunden durch die Einsatz-App
  $inc = activeIncident(); if (!$inc) fail('Kein aktiver Einsatz', 404);
  if (!airAllowed($inc)) fail('Helikopter sind für dich nicht freigegeben', 403);
  out(airServe($inc));
 case 'air/home': // Helikopter ohne Einsatz (Issue #130): im Umkreis home_km um die aktiven permanenten festen Standorte, alle Angemeldeten
  if (!airHomeOn()) fail('Helikopter ohne Einsatz sind ausgeschaltet', 403);
  out(airServeZ(airHomeZone(), 'home', null, 'Keine aktiven festen Standorte'));
 /* ---------- Pegel und Abfluss (Issue #125) ---------- */
 case 'hydro': // Messstationen mit aktuellen Werten: ids = Liste der Stations-IDs, ohne ids alle (Auswahl im Profil); alle Angemeldeten, solange die Funktion für einen Einsatztyp an ist
  $inc = activeIncident(); if (!hydroOn() && !($inc && feature($inc, 'hydro'))) fail('Pegel & Abfluss ist ausgeschaltet', 403);
  $locs = hydroLocs(); [$L, $raw] = hydroLatest(); $ids = isset($in['ids']) ? array_slice(array_map('strval', (array)$in['ids']), 0, 60) : array_keys($locs); $o = [];
  foreach ($ids as $i) if (isset($locs[$i])) $o[] = hydroRow($locs[$i], $L);
  out(['stations' => $o, 'at' => (int)($raw['at'] ?? 0) ?: null, 'err' => !array_filter(hydroCfg()['src']) ? 'Keine Datenquelle eingeschaltet (Verwaltung → Pegel & Abfluss)' : (!$locs ? 'Messstationen des BAFU nicht abrufbar' : (!$L ? 'Messwerte des BAFU nicht abrufbar' : null))]);
 case 'hydro/status': need('cfg_map'); // Verwaltung: Stand der Zwischenspeicher, «Jetzt abrufen» mit fresh=1 (verwirft die zwischengespeicherten Messwerte)
  if (!empty($in['fresh'])) @unlink(__DIR__ . '/data/hydro/bafu.json');
  $t = microtime(true); $locs = hydroLocs(); [$L, $raw] = hydroLatest(); $ms = (int)((microtime(true) - $t) * 1000);
  out(['stations' => count($locs), 'rivers' => count(array_filter($locs, fn($s) => $s['type'] === 'river')), 'values' => count($L), 'at' => (int)($raw['at'] ?? 0) ?: null, 'fail_at' => (int)($raw['fail_at'] ?? 0) ?: null, 'ms' => $ms, 'status' => (object)hydroStat()]);
 case 'hydro/test': need('cfg_map'); // Verwaltung: eine Quelle direkt abfragen (ohne Zwischenspeicher der Messwerte), mit Beispiel Aare Brugg (2016)
  $k = (string)($in['src'] ?? ''); if (!isset(HYDRO_SOURCES[$k])) fail('Unbekannte Datenquelle');
  $t = microtime(true); $r = hydroSrc($k); $ms = (int)((microtime(true) - $t) * 1000);
  if (!$r) out(['ok' => false, 'ms' => $ms, 'err' => hydroStat()[$k]['err'] ?? null]);
  $ex = isset($r['locs']['2016']) ? hydroRow($r['locs']['2016'], $r['vals']) : null;
  out(['ok' => true, 'ms' => $ms, 'stations' => count($r['locs']), 'values' => count($r['vals']), 'example' => $ex ? hydroName($ex) . ': ' . hydroText($ex) : null]);
 /* ---------- Gewässernetz aus PostGIS (Issue #131) ---------- */
 case 'postgis/test': need('cfg_map'); // Verbindung, Tabelle und eine Beispielabfrage (Umkreis 5 km um lat/lon, Standard Bern); cfg = noch nicht gespeicherte Einstellung, Passwort leer = gespeichertes
  $c = isset($in['cfg']) ? pgIn(['on' => 0] + (array)$in['cfg']) : pgCfg(); $pw = is_string($in['cfg']['pass'] ?? null) && $in['cfg']['pass'] !== '' ? $in['cfg']['pass'] : (string)setting('postgis_pass', '');
  if ($c['host'] === '' || $c['db'] === '' || $c['user'] === '') out(['ok' => false, 'err' => 'Host, Datenbank und Benutzer angeben']);
  $t = microtime(true); $r = ['ok' => false, 'ext' => extension_loaded('pdo_pgsql') ? 1 : 0];
  try { $p = pgConnect($c, $pw); $r['connect_ms'] = (int)((microtime(true) - $t) * 1000);
    $r['postgis'] = (string)$p->query("SELECT PostGIS_Lib_Version()")->fetchColumn();
    $tb = explode('.', $c['table']); $s = $p->prepare("SELECT to_regclass(?)"); $s->execute([pgTable($c)]); if (!$s->fetchColumn()) throw new RuntimeException('Tabelle ' . $c['table'] . ' nicht gefunden');
    $s = $p->prepare("SELECT Find_SRID(?, ?, 'geom')"); $s->execute([count($tb) > 1 ? $tb[0] : 'public', end($tb)]); $r['srid'] = (int)$s->fetchColumn();
    if ($r['srid'] !== 4326) throw new RuntimeException('Spalte geom hat SRID ' . $r['srid'] . ', nötig ist 4326 (WGS84)');
    $s = $p->prepare("SELECT reltuples::bigint FROM pg_class WHERE oid = to_regclass(?)"); $s->execute([pgTable($c)]); $r['rows'] = (int)$s->fetchColumn(); // Schätzung der Statistik, ohne die ganze Tabelle zu zählen
    $la = is_numeric($in['lat'] ?? null) ? (float)$in['lat'] : 46.948; $lo = is_numeric($in['lon'] ?? null) ? (float)$in['lon'] : 7.4474;
    $t2 = microtime(true); $w = pgWays($p, $c, ['around', 5000, $la, $lo]); $r['query_ms'] = (int)((microtime(true) - $t2) * 1000);
    $r['ways'] = count($w); $r['names'] = array_slice(array_values(array_unique(array_filter(array_map(fn($x) => $x['tags']['name'] ?? null, $w)))), 0, 8); $r['ok'] = true; }
  catch (Throwable $e) { $r['err'] = pgErr($e); }
  $r['ms'] = (int)((microtime(true) - $t) * 1000); out($r);
 /* ---------- AI-Assistent (Issue #111) ---------- */
 case 'ai/cfg': need('cfg_ai'); $c = aiCfg();
  $u = fn($w, $a) => (function () use ($db, $w, $a) { $s = $db->prepare("SELECT COUNT(*) n, COALESCE(SUM(tokens),0) t, COALESCE(SUM(CASE WHEN ok=0 THEN 1 ELSE 0 END),0) e FROM ai_usage WHERE ts>? $w"); $s->execute($a); $r = $s->fetch(); $s->closeCursor(); return ints($r, ['n', 't', 'e']); })();
  $fu = $db->prepare("SELECT fn, COUNT(*) n, COALESCE(SUM(tokens),0) t FROM ai_usage WHERE ts>? GROUP BY fn"); $fu->execute([$now - 30 * 86400]);
  $last = $db->query("SELECT a.ts,a.fn,a.incident_id,a.tokens,a.ok,a.err,u.name FROM ai_usage a LEFT JOIN users u ON u.id=a.user_id ORDER BY a.id DESC LIMIT 10")->fetchAll();
  // Freigabe: Bereiche = aktive Einsatztypen und Verwaltung; Spalten = Benutzerrollen (u…) und Spezialrollen im Einsatz (r…)
  out(['cfg' => $c, 'has_key' => aiKey() !== '' ? 1 : 0, 'ready' => aiReady() ? 1 : 0, 'fn' => array_map(fn($x) => ['name' => $x[0], 'scope' => $x[1], 'comp' => $x[2], 'descr' => $x[3]], AI_FN),
   'ctx' => array_map(fn($f) => aiCtxs($f), array_combine(array_keys(AI_FN), array_keys(AI_FN))), 'modes' => AI_MODES, 'prov' => AI_PROV, 'curl' => function_exists('curl_init') ? 1 : 0,
   'kinds' => array_values(array_map(fn($k) => ['id' => $k['id'], 'name' => $k['name'], 'color' => $k['color'], 'plans' => $k['plans']], kindsActive())),
   'uroles' => array_map(fn($r) => ['id' => (int)$r['id'], 'name' => $r['name'], 'sys' => $r['sys']], $db->query("SELECT id,name,sys FROM uroles ORDER BY id")->fetchAll()),
   'roles' => array_map(fn($r) => ['id' => (int)$r['id'], 'name' => $r['name'], 'color' => $r['color']], $db->query("SELECT id,name,color FROM roles WHERE active=1 ORDER BY id")->fetchAll()),
   'usage' => ['day' => $u('', [$now - 86400]), 'month' => $u('', [$now - 30 * 86400]), 'fn' => array_map(fn($r) => ints($r, ['n', 't']), $fu->fetchAll()), 'last' => array_map(fn($r) => ints($r, ['ts', 'incident_id', 'tokens', 'ok']), $last)]]);
 case 'ai/cfg/save': need('cfg_ai'); $c = aiIn($in);
  if (isset($in['key']) && trim((string)$in['key']) !== '') { $k = trim((string)$in['key']); if (strlen($k) > 500 || preg_match('/\s/', $k)) fail('Ungültiger API-Schlüssel'); setSetting('ai_key', $k); }
  if (!empty($in['key_clear'])) setSetting('ai_key', '');
  if ($c['on'] && $c['provider'] === 'anthropic' && aiKey() === '') fail('Für Anthropic bitte zuerst einen API-Schlüssel hinterlegen');
  setSetting('ai', json_encode($c, JSON_UNESCAPED_UNICODE)); $AI_CFG = null; out(['ok' => true, 'ready' => aiReady() ? 1 : 0]);
 case 'ai/test': need('cfg_ai'); $t0 = microtime(true);
  try { [$t, $tok, $model] = aiCall('Antworte kurz auf Deutsch.', 'Antworte nur mit: Verbindung funktioniert.', 50); }
  catch (AiErr $e) { aiUse('test', null, 0, 0, $e->getMessage()); fail($e->getMessage(), 502); }
  aiUse('test', null, $tok, 1); out(['text' => $t, 'ms' => (int)round((microtime(true) - $t0) * 1000), 'tokens' => $tok, 'model' => $model]);
 case 'ai/ask': // fn, id, q (zusätzlicher Wunsch), auto (begleitend), fresh (Cache übergehen), field/all (aar); my=1 im GET für Meine Einsätze
  // fn chat (freie Fragen): about = Funktion, deren Daten mitgehen (leer = ohne Daten, nur Verwaltung), id, q = Frage, msgs = frühere [{q, a}]
  $fn = (string)($in['fn'] ?? ''); if (!isset(AI_FN[$fn])) fail('Unbekannte Funktion');
  if (!aiReady()) fail('Der AI-Assistent ist nicht eingerichtet', 503);
  if ($fn === 'chat') { $ab = (string)($in['about'] ?? ''); if ($ab !== '' && (!isset(AI_FN[$ab]) || $ab === 'chat')) fail('Unbekannte Funktion');
    $q = trim(mb_substr((string)($in['q'] ?? ''), 0, 2000)); if ($q === '') fail('Bitte eine Frage eingeben');
    $t = $ab !== '' ? aiTask($ab, ['chat' => 1, 'q' => ''] + $in) : ['ctx' => 'adm', 'inc' => null, 'c' => null, 'cv' => null, 'data' => '', 'role' => null];
    if (AI_RANK[aiMode('chat', $t['ctx'], $t['role'])] < 1) fail('Freie Fragen an den AI-Assistenten sind für dich hier nicht freigegeben', 403);
    $an = fn($s) => $t['c'] ? anonText($s, $t['c']) : $s; $msgs = []; // Namen in den Fragen wie in den Daten ersetzen
    foreach (array_slice(array_values(array_filter((array)($in['msgs'] ?? []), 'is_array')), -AI_CHAT_N) as $m) { $a = trim(mb_substr((string)($m['q'] ?? ''), 0, 2000)); $b = trim(mb_substr((string)($m['a'] ?? ''), 0, 6000));
      if ($a !== '' && $b !== '') array_push($msgs, ['role' => 'user', 'content' => $an($a)], ['role' => 'assistant', 'content' => $an($b)]); }
    $msgs[] = ['role' => 'user', 'content' => $an($q)];
    $sys = AI_SYS . ($t['data'] !== '' ? ' Beantworte die Fragen der Person zu den folgenden Daten. Zeiten, Namen und Zahlen nur aus den Daten; was sich daraus nicht beantworten lässt, sage offen. Allgemeine Fachfragen beantworte nach bestem Wissen und weise auf Unsicherheiten hin.' . "\n\n=== Daten ===\n" . $t['data']
      : ' Beantworte die Fragen der Person. Es liegen keine Einsatzdaten vor. Fachfragen (z. B. Wasserrettung, Ausbildung, Organisation) beantworte nach bestem Wissen und weise auf Unsicherheiten hin; verbindlich sind die Vorgaben der eigenen Organisation.');
    if (aiPriv($t['ctx'], 'round_loc')) $sys = aiRound($sys);
    aiLimit();
    try { [$txt, $tok] = aiCall($sys, $msgs); }
    catch (AiErr $e) { aiUse('chat', $t['inc'], 0, 0, $e->getMessage()); fail($e->getMessage(), 502); }
    aiUse('chat', $t['inc'], $tok, 1);
    out(['text' => aiDeanon($txt, $t['c'], $t['cv'], $t['inc']), 'cached' => 0, 'ts' => $now, 'tokens' => (int)$tok, 'fn' => 'chat', 'anon' => $t['c'] ? 1 : 0]); }
  $t = aiTask($fn, $in); $prompt = $t['p']; $ctx = $t['ctx']; $incId = $t['inc']; $c = $t['c']; $cv = $t['cv']; $x = $t['x'];
  $mode = aiMode($fn, $ctx, $t['role']); $auto = !empty($in['auto']); $q = trim((string)($in['q'] ?? ''));
  if (AI_RANK[$mode] < ($auto ? 2 : 1)) fail($auto ? 'Der begleitende Modus ist für dich nicht eingeschaltet' : 'Diese Funktion des AI-Assistenten ist für dich nicht freigegeben', 403);
  $cfg = aiCfg(); if (aiPriv($ctx, 'round_loc')) $prompt = aiRound($prompt);
  $ck = sha1($fn . '|' . ($incId ?? '') . '|' . $x['key']); $hash = sha1($cfg['model'] . '|' . $prompt);
  $deliver = function ($t, $cached, $ts, $tok) use ($fn, $c, $cv, $incId, $x) { $t = aiDeanon($t, $c, $cv, $incId);
    $o = ['text' => $t, 'cached' => $cached ? 1 : 0, 'ts' => (int)$ts, 'tokens' => (int)$tok, 'fn' => $fn, 'anon' => $c ? 1 : 0];
    if ($fn === 'aar') { $j = aiJson($t); if (!$j) fail('Die Antwort des AI-Anbieters liess sich nicht auswerten', 502);
      $o['fields'] = (object)array_filter(array_map(fn($v) => is_scalar($v) ? trim((string)$v) : null, array_intersect_key($j, array_flip($x['fields']))), fn($v) => $v !== null && $v !== ''); unset($o['text']); }
    if ($fn === 'plan') { $j = aiJson($t); // Text plus übernehmbare Elemente; ohne auswertbares JSON nur der Text
      if ($j && (isset($j['text']) || isset($j['elements']))) { $o['text'] = is_scalar($j['text'] ?? null) ? trim((string)$j['text']) : ''; $o['els'] = aiPlanEls($j['elements'] ?? [], $x['refs'] ?? []); } else $o['els'] = []; }
    out($o); };
  if ($q === '' && empty($in['fresh'])) { $s = $db->prepare("SELECT * FROM ai_cache WHERE k=?"); $s->execute([$ck]); $r = $s->fetch(); $s->closeCursor();
    if ($r && ($r['ctx_hash'] === $hash || $auto && ($fn === 'aar' || $fn === 'sit' && $now - (int)$r['ts'] < AI_SIT_S))) $deliver($r['text'], true, $r['ts'], $r['tokens']); }
  aiLimit();
  try { [$t, $tok, $model] = aiCall(AI_SYS . AI_FACTS, $prompt); }
  catch (AiErr $e) { aiUse($fn, $incId, 0, 0, $e->getMessage()); fail($e->getMessage(), 502); }
  aiUse($fn, $incId, $tok, 1);
  if ($q === '') { $db->prepare("DELETE FROM ai_cache WHERE k=?")->execute([$ck]); $db->prepare("INSERT INTO ai_cache(k,ts,ctx_hash,text,tokens,model) VALUES(?,?,?,?,?,?)")->execute([$ck, $now, $hash, $t, $tok, $model]); }
  $deliver($t, false, $now, $tok);

 case 'air/test': need('cfg_map'); // Verwaltung: eine Quelle direkt abfragen (ohne Cache), Umkreis 120 NM um die Mitte der Schweiz
  $k = (string)($in['src'] ?? ''); if (!isset(AIR_SOURCES[$k])) fail('Unbekannte Datenquelle');
  $c = airCfg(); $t = microtime(true); $r = airFetch([$k], 46.8, 8.2, 120 * 1852); $ms = (int)((microtime(true) - $t) * 1000);
  if (!$r) out(['ok' => false, 'ms' => $ms, 'status' => json_decode((string)@file_get_contents(__DIR__ . '/data/air/status.json'), true)[$k] ?? null]);
  $h = array_values(array_filter($r[1], fn($a) => $a['cat'] === 'A7' || airRescue($a, $c) !== null));
  out(['ok' => true, 'ms' => $ms, 'total' => count($r[1]), 'heli' => array_map(fn($a) => ['cs' => $a['cs'], 'hex' => $a['hex'], 'reg' => $a['reg'], 'type' => $a['type'], 'alt' => $a['gnd'] ? 0 : $a['alt'], 'rescue' => airRescue($a, $c), 'age' => $now - $a['at']], $h)]);

 case 'incident/start':
  $kind = kindStart($in); $kid = $kind['id']; need($kind['perm']); // Recht je Einsatztyp (Issue #63, #90)
  if (!kindMember($kind)) fail('Du bist in keiner Einsatzgruppe, die bei «' . $kind['name'] . '» aufbietbar ist', 403);
  if (activeIncident()) fail('Es läuft bereits ein Einsatz');
  if (trim($in['message'] ?? '') === '') fail('Die Meldung ist Pflicht');
  $at = !empty($in['alarm_at']) ? (int)$in['alarm_at'] : $now;
  if ($at > $now + 60) fail('Der Alarmeingang darf nicht in der Zukunft liegen');
  if ($at < $now - 7 * 86400) fail('Der Alarmeingang liegt mehr als 7 Tage zurück');
  // PLS optional (Issue #24): ohne PLS gibt es ein Suchgebiet erst mit dem PLS eines Rettlings; Zeitpunkt des PLS = Alarmeingang
  $pls = isset($in['lat'], $in['lon']) && $in['lat'] !== '' && $in['lon'] !== '' ? plsIn($in, $at) : [null, null];
  $rec = empty($in['record']) || !features($kid)['rec'] ? 0 : 1;
  $air = airCfg()['on'] ? (isset($in['air']) ? (empty($in['air']) ? 0 : 1) : airDef($kid)) : null; // Helikopter: Häkchen beim Start, sonst Vorauswahl; Hauptschalter aus = offen lassen (NULL)
  // Aufgebot (Issue #90): groups, persons (wenn der Typ das erlaubt) oder invite_all=1 (alle beim Typ aufbietbaren Gruppen); ohne aktive Einsatzgruppen wie bisher alle
  $allowed = startGroups($kind); $ids = null; $ig = null;
  if (grpRows(true)) { if (!empty($in['invite_all'])) { if ($allowed !== null) fail('Du darfst nur bestimmte Einsatzgruppen aufbieten', 403); $in['groups'] = kindGroups($kind); $in['persons'] = []; }
    [$ids, $g, $pp] = resolveInvite($kind, $in['groups'] ?? [], $in['persons'] ?? [], $allowed);
    if (!$g && !$pp) fail('Bitte mindestens eine Einsatzgruppe aufbieten');
    if (!array_diff($ids, [$uid])) fail('Die gewählten Einsatzgruppen haben keine (weiteren) aktiven Mitglieder');
    $ig = ['g' => $g, 'p' => $pp]; }
  $abs = array_values(array_diff($ids === null ? absentIds() : array_intersect(absentIds(), $ids), [$uid])); // Abwesende (Issue #91): aufgeboten, aber kein Alarm
  $db->prepare("INSERT INTO incidents(message,lat,lon,alarm_at,created_by,is_exercise,air,kind_id,invite,inv_groups,inv_absent) VALUES(?,?,?,?,?,?,?,?,?,?,?)")->execute([$in['message'], $pls[0], $pls[1], $at, $uid, $kind['plans'] ? 1 : 0, $air, $kid, $ids === null ? null : json_encode($ids), $ig === null ? null : json_encode($ig), $abs ? json_encode($abs) : null]);
  $nid = (int)$db->lastInsertId();
  if ($rec) setRec($nid, 1);
  logev($nid, $uid, 'start', 'hat den Einsatz gestartet (' . $kind['name'] . ($rec ? ', mit Aufzeichnung' : '') . ($air ? ', mit Helikoptern' : '') . ($pls[0] === null ? ', ohne PLS' : '') . '): ' . $in['message']);
  if ($ig) logev($nid, $uid, 'invite', 'bietet auf: ' . invText($ig['g'], $ig['p']) . ' (' . (($n = count(array_diff($ids, [$uid]))) === 1 ? '1 Person' : $n . ' Personen') . ($abs ? ', davon ' . absText(count($abs)) : '') . ')');
  elseif ($abs) logev($nid, $uid, 'invite', absText(count($abs)));
  pushAlarm(['id' => $nid, 'kind_id' => $kid, 'message' => $in['message']], array_merge([$uid], $abs), $ids);
  out(['id' => $nid]);

 case 'incident/state': // Polling-Endpunkt: ?since=<letzte Nachrichten-ID>
  $inc = activeIncident();
  if ($inc && $inc['plan_id'] !== null) { exAuto($inc); $inc = activeIncident(); } // fällige automatische Freigaben
  $blocked = $inc && !incAllowed($inc); // nicht aufgeboten (Issue #90): nur ein Hinweis, keine Daten
  if (!$inc || $blocked) {
    $last = $db->query("SELECT id,ended_at FROM incidents WHERE ended_at IS NOT NULL ORDER BY id DESC LIMIT 1")->fetch();
    // vorbereitete Übungen, die diese Person starten darf: nur die Übungsleitung der Übung (sonst fehlten ihr nach dem Start die Funktionen der Übungsleitung)
    $xp = array_values(array_filter($db->query("SELECT id,title,leads,invite FROM ex_plans WHERE active=1 AND incident_id IS NULL ORDER BY title")->fetchAll(), fn($p) => in_array($uid, exLeads($p), true)));
    // feste Standorte auch ohne Einsatz (Änderungswunsch #96): die aktiven permanenten, solange die Funktion für einen der Einsatztypen an ist
    $fpl = array_filter(featuresAll(), fn($f) => $f['place']) ? array_values(array_map(fn($r) => array_diff_key($r, ['active' => 1]), array_filter(placesOf(null), fn($r) => $r['active']))) : [];
    if ($blocked) $xp = [];
    out(['incident' => null, 'air_home' => airHomeOn() ? 1 : 0, 'my_abs' => absNow($uid)[$uid] ?? null, 'blocked' => $blocked ? 1 : 0, 'last_ended' => $last ?: null, 'places' => $fpl, 'place_cats' => placeCats(), 'kinds' => kindsPub(), 'features' => featuresAll(), 'ex_plans' => array_map(fn($p) => ['id' => (int)$p['id'], 'title' => $p['title'], 'invited' => $p['invite'] !== null ? 1 : 0], $xp)]); // features: Flussdistanz ohne Einsatz
  }
  $inc['id'] = (int)$inc['id']; $inc['plan_id'] = $inc['plan_id'] === null ? null : (int)$inc['plan_id']; $inc = floats($inc, ['lat', 'lon']); $inc['pls_at'] = plsAt($inc); $inc['alarm_at'] = (int)$inc['alarm_at']; $inc['created_by'] = (int)$inc['created_by']; $inc = kindAdd($inc); $inc['kind'] = kindPub(kindOf($inc)); $inc['record'] = (int)$inc['record']; $inc['recorded'] = (int)$inc['recorded'];
  if ($inc['flow_ms'] !== null) $inc['flow_ms'] = (float)$inc['flow_ms'];
  $id = $inc['id']; $since = (int)($_GET['since'] ?? 0); $F = features($inc['kind_id']); // ausgeschaltete Funktionen liefern nichts
  // Pegel und Abfluss zu Beginn (Issue #125): sobald ein PLS bekannt ist, einmal nach der Antwort (Sperre über das UPDATE); BAFU nicht erreichbar = nach 2 Minuten erneut
  $hs = $inc['hydro_s'] === null ? null : (int)$inc['hydro_s'];
  if ($F['hydro'] && ($hs === null || ($hs < 0 && -$hs < $now - 120)) && hydroRefs($inc)) {
    $u = $db->prepare("UPDATE incidents SET hydro_s=? WHERE id=? AND (hydro_s IS NULL OR (hydro_s<0 AND hydro_s>?))"); $u->execute([$now, $id, -($now - 120)]);
    if ($u->rowCount()) after(function () use ($id) { global $db; if (!hydroLog($id, 'start')) $db->prepare("UPDATE incidents SET hydro_s=? WHERE id=?")->execute([-time(), $id]); }); }
  unset($inc['hydro_s']);
  $ps = $db->prepare("SELECT p.user_id,p.mode,p.role_id,p.lat,p.lon,p.loc_at,p.left_at,u.name,u.photo FROM participants p JOIN users u ON u.id=p.user_id WHERE p.incident_id=?");
  $ps->execute([$id]);
  $isP = isActivePart($id);
  $msgs = []; $msgMarks = [];
  // Lesen: alle, die den Einsatz angenommen haben; mit Einstellung msg_read_all auch alle anderen angemeldeten Personen
  $xl = exLead($inc); // Übungsleitung (Issue #29)
  $canRead = $F['msg'] && ($isP || $xl || setting('msg_read_all', '0') === '1');
  if ($canRead) { // recipients = wer benachrichtigt wird (null = alle)
    $ms = $db->prepare("SELECT m.id,m.from_user,m.text,m.created,m.recipients,m.lat,m.lon,m.mark_lat,m.mark_lon,u.name from_name FROM messages m JOIN users u ON u.id=m.from_user WHERE m.incident_id=? AND m.id>? ORDER BY m.id");
    $ms->execute([$id, $since]);
    $msgs = array_map(fn($m) => floats(ints($m, ['id', 'from_user', 'created']), ['lat', 'lon', 'mark_lat', 'mark_lon']), $ms->fetchAll());
    // aktuelle Kartenmarkierungen separat (nicht inkrementell), damit entfernte Markierungen bei allen verschwinden
    $mm = $db->prepare("SELECT m.id,m.from_user,m.text,m.created,m.mark_lat lat,m.mark_lon lon,u.name from_name FROM messages m JOIN users u ON u.id=m.from_user WHERE m.incident_id=? AND m.mark=1 ORDER BY m.id");
    $mm->execute([$id]);
    $msgMarks = array_map(fn($m) => floats(ints($m, ['id', 'from_user', 'created']), ['lat', 'lon']), $mm->fetchAll());
  }
  $mk = $db->prepare("SELECT m.id,m.user_id,m.lat,m.lon,m.ts,m.manual,u.name FROM rescuee_marks m LEFT JOIN users u ON u.id=m.user_id WHERE m.incident_id=? ORDER BY m.id");
  $mk->execute([$id]);
  $marks = !$F['res'] ? [] : array_map(fn($m) => ints(array_merge($m, ['lat' => (float)$m['lat'], 'lon' => (float)$m['lon']]), ['id', 'user_id', 'ts', 'manual']), $mk->fetchAll());
  // Positionen und Bereiche der Einsatzleitung: für alle sichtbar, immer vollständig (Entfernen kommt bei allen an)
  $po = $db->prepare("SELECT p.id,p.kind,p.name,p.color,p.pts,p.created_by,p.created,u.name by_name FROM pois p LEFT JOIN users u ON u.id=p.created_by WHERE p.incident_id=? AND p.removed IS NULL ORDER BY p.id");
  $po->execute([$id]);
  $pois = !$F['poi'] ? [] : array_map(fn($p) => array_merge(ints($p, ['id', 'created_by', 'created']), ['pts' => json_decode($p['pts'], true) ?: []]), $po->fetchAll());
  // Rettlinge: für alle sichtbar, immer vollständig
  $rescuees = incRes($inc);
  $cf = canFlow($inc);
  // Öffentliche Ansicht: alle sehen, ob sie eingeschaltet ist (public); den geheimen Link (public_token) nur die Einsatzleitung
  $inc['public'] = $inc['public_token'] !== null ? 1 : 0; $inc['public_anon'] = (int)$inc['public_anon']; $inc['public_log'] = (int)$inc['public_log'];
  if (!canManage($inc)) unset($inc['public_token']);
  $inc['aufgebot'] = invInfo($inc); $inc['open_join'] = (int)$inc['open_join']; $absent = incAbsent($inc, $uid) ? 1 : 0; $alarm = alarmed($inc); unset($inc['invite'], $inc['inv_groups'], $inc['inv_absent']); // Aufgebot (Issue #90), Abwesende (Issue #91)
  // Externe Einsatzkräfte für alle; Vorlagen nur für die Einsatzleitung (nur sie erfasst Externe)
  // Einsatz-Vorlagen: von Hand erfasste Externe dieses Einsatzes (auch entfernte), je Name die neuste, ohne Standort und ohne Namen der Verwaltungsvorlagen
  $ext = $F['ext'] ? incExternals($id) : []; $tpl = []; $itpl = [];
  if ($F['ext'] && canManage($inc)) {
    $tpl = array_map(fn($r) => floats(ints($r, ['id', 'role_id']), ['lat', 'lon']), $db->query("SELECT id,name,descr,role_id,abbr,color,lat,lon FROM ext_templates WHERE active=1 ORDER BY name")->fetchAll());
    $seen = array_fill_keys(array_map(fn($t) => mb_strtolower($t['name']), $tpl), 1);
    $s = $db->prepare("SELECT name,descr,role_id,abbr,color FROM externals WHERE incident_id=? AND manual=1 ORDER BY id DESC"); $s->execute([$id]);
    foreach ($s->fetchAll() as $r) { $k = mb_strtolower($r['name']); if (isset($seen[$k])) continue; $seen[$k] = 1; $itpl[] = ints($r, ['role_id']); }
    usort($itpl, fn($x, $y) => strcasecmp($x['name'], $y['name']));
  }
  // Personeninfo (Issue #105): alle aktiven Einsatzgruppen (unabhängig von «Farbe beim Namen») und je Teilnahme die Gruppen der Person
  $pg = null; if ($F['pinfo']) { $pg = ['g' => array_map(fn($x) => ['id' => $x['id'], 'name' => $x['name'], 'color' => $x['color']], grpRows(true)), 'm' => []];
    $s = $db->prepare("SELECT m.user_id,m.group_id FROM inc_group_members m JOIN inc_groups g ON g.id=m.group_id JOIN participants p ON p.user_id=m.user_id AND p.incident_id=? WHERE g.active=1 ORDER BY g.pos, g.name"); $s->execute([$id]); foreach ($s->fetchAll() as $x) $pg['m'][(int)$x['user_id']][] = (int)$x['group_id']; }
  out(['incident' => $inc, 'pois' => $pois, 'rescuees' => $rescuees, 'externals' => $ext, 'places' => incPlaces($inc), 'place_cats' => placeCats(), 'ext_templates' => $tpl, 'ext_inc_templates' => $itpl, 'sources' => plsSources($inc, $rescuees),
       'participants' => array_map(function ($p) use ($pg) { foreach (['lat', 'lon'] as $k) if ($p[$k] !== null) $p[$k] = (float)$p[$k]; if ($pg) $p['groups'] = $pg['m'][(int)$p['user_id']] ?? []; return ints($p, ['user_id', 'role_id', 'left_at', 'loc_at']); }, $ps->fetchAll()),
       'groups' => $pg ? $pg['g'] : [], 'marks' => $marks, 'messages' => $msgs, 'msg_marks' => $msgMarks,'me' => part($id) ?: null, 'is_lead' => isLead($id), 'can_manage' => canManage($inc), 'ai' => aiInc($inc), 'can_flow' => $cf, 'can_message' => $F['msg'] && canMessage($inc), 'can_read_messages' => $canRead, 'critical' => critical($inc), 'level' => alarmLvl($inc, $me['exercise_mode']), 'alarm' => $alarm, 'absent' => $absent, 'my_abs' => absNow($uid)[$uid] ?? null, 'features' => $F,
       'air' => airAllowed($inc) ? 1 : 0, 'air_inc' => airCfg()['on'] ? (airInc($inc) ? 1 : 0) : null, 'ex_lead' => $xl, 'ex_title' => $inc['plan_id'] && ($xl || planner()) ? (exPlan($inc)['title'] ?? null) : null, 'ex_injects' => $inc['plan_id'] && ($xl || planner()) ? exInjects((int)$inc['plan_id']) : [],
       'log' => $cf ? logRows($id) : null, 'hydro' => $F['hydro'] ? hydroInc($id) : [], 'now' => $now]);

 case 'incident/invite/add': // Nachbieten (Issue #90, Einsatzleitung): groups, persons (wenn der Typ das erlaubt) oder all=1; Alarm nur an die neu Aufgebotenen
  $inc = activeIncident(); if (!$inc) fail('Kein aktiver Einsatz'); if (!canManage($inc)) fail('Nur die Einsatzleitung kann nachbieten', 403);
  $kind = kindOf($inc); $old = incInvite($inc); if ($old === null) fail('Es sind bereits alle Personen aufgeboten');
  $allowed = addGroups($kind); $o = json_decode($inc['inv_groups'] ?? 'null', true) ?: ['g' => [], 'p' => $old];
  if (!empty($in['all'])) { if ($allowed !== null) fail('Du darfst nur bestimmte Einsatzgruppen aufbieten', 403); $in['groups'] = kindGroups($kind); $in['persons'] = []; } // alle beim Typ aufbietbaren Gruppen
  [$new, $g, $pp] = resolveInvite($kind, $in['groups'] ?? [], $in['persons'] ?? [], $allowed);
  if (!$g && !$pp) fail('Bitte mindestens eine Einsatzgruppe wählen');
  $inv = json_encode(array_values(array_unique(array_merge($old, $new)))); $o = ['g' => idsIn(array_merge($o['g'] ?? [], $g)), 'p' => idsIn(array_merge($o['p'] ?? [], $pp))]; $txt = invText($g, $pp);
  $skip = ($p = exPlan($inc)) ? exLeads($p) : []; // die Übungsleitung wird nie alarmiert
  $new = array_values(array_diff($new, $old, $skip, [$uid]));
  $abs = array_values(array_intersect($new, absentIds())); $new = array_values(array_diff($new, $abs)); // Abwesende (Issue #91): aufgeboten, aber kein Alarm
  $ia = array_values(array_unique(array_merge(array_map('intval', json_decode($inc['inv_absent'] ?? '[]', true) ?: []), $abs)));
  $db->prepare("UPDATE incidents SET invite=?, inv_groups=?, inv_absent=? WHERE id=?")->execute([$inv, json_encode($o), $ia ? json_encode($ia) : null, $inc['id']]);
  logev($inc['id'], $uid, 'invite', 'bietet nach: ' . $txt . ' (' . (count($new) === 1 ? '1 weitere Person' : count($new) . ' weitere Personen') . ($abs ? ', dazu ' . absText(count($abs)) : '') . ')');
  if ($new) pushAlarm($inc, [], $new);
  out(['ok' => true, 'added' => count($new), 'absent' => count($abs)]);
 case 'incident/open': // Beitritt für alle erlauben (Issue #90, Einsatzleitung): on = 1/0
  $inc = activeIncident(); if (!$inc) fail('Kein aktiver Einsatz'); if (!canManage($inc)) fail('Nur die Einsatzleitung kann das ändern', 403);
  $on = empty($in['on']) ? 0 : 1;
  if ($on !== (int)$inc['open_join']) { $db->prepare("UPDATE incidents SET open_join=? WHERE id=?")->execute([$on, $inc['id']]);
    logev($inc['id'], $uid, 'invite', $on ? 'erlaubt allen den Beitritt' : 'erlaubt den Beitritt nur noch den Aufgebotenen'); }
  out(['ok' => true]);
 case 'start/options': // Startformular und Nachbieten (Issue #90): Einsatztypen, aktive Gruppen mit Anzahl aktiver Mitglieder, je Typ die erlaubten Gruppen (null = alle, dann auch «alle Personen»)
  $gs = grpRows(true); $cnt = [];
  foreach ($db->query("SELECT m.group_id,COUNT(*) c FROM inc_group_members m JOIN users u ON u.id=m.user_id WHERE u.active=1 GROUP BY m.group_id")->fetchAll() as $x) $cnt[(int)$x['group_id']] = (int)$x['c'];
  $ab = absentIds(); $na = []; if ($ab) { $s = $db->prepare("SELECT group_id,COUNT(*) c FROM inc_group_members WHERE user_id IN (" . qm($ab) . ") GROUP BY group_id"); $s->execute($ab); foreach ($s->fetchAll() as $x) $na[(int)$x['group_id']] = (int)$x['c']; }
  foreach ($gs as &$g) { $g['n'] = $cnt[$g['id']] ?? 0; $g['na'] = $na[$g['id']] ?? 0; } unset($g); // na = davon abwesend (Issue #91)
  $sg = []; $ag = []; $pl = []; foreach (kindsActive() as $id => $k) { $sg[$id] = can($k['perm']) && kindMember($k) ? startGroups($k) : []; $ag[$id] = addGroups($k); }
  // Einzelpersonen (Typen mit persons): alle aktiven, mit Einschränkung nur Mitglieder der erlaubten Gruppen
  foreach (kindsActive() as $id => $k) if ($k['persons']) foreach ([$sg[$id], $ag[$id]] as $a) { if ($a === null) { $pl = null; break 2; } $pl = array_merge($pl, $a); }
  if ($pl !== null) $pl = grpMembers(array_values(array_unique($pl)));
  $people = array_values(array_filter(array_map(fn($r) => ints($r, ['id']) + ['absent' => in_array((int)$r['id'], $ab, true) ? 1 : 0], $db->query("SELECT id,name FROM users WHERE active=1 ORDER BY name")->fetchAll()), fn($r) => $pl === null || in_array($r['id'], $pl, true)));
  out(['groups' => $gs, 'kinds' => kindsPub(), 'start' => (object)$sg, 'add' => (object)$ag, 'people' => $people, 'kind_people' => (object)array_map('kindPeople', kindsActive()), 'kind_absent' => (object)array_map(fn($k) => ($kp = kindPeople($k)) === null ? count($ab) : count(array_intersect($ab, $kp)), kindsActive())]);
 case 'incident/join': // mode: code einer aktiven Einrückoption oder declined
  $inc = activeIncident(); if (!$inc) fail('Kein aktiver Einsatz');
  $mode = (string)($in['mode'] ?? '');
  $m = $mode === 'declined' ? null : modeRow($mode);
  if ($mode !== 'declined' && (!$m || !(int)$m['active'])) fail('Ungültige Auswahl');
  $prev = part($inc['id']);
  $db->prepare($my ? "INSERT INTO participants(incident_id,user_id,mode) VALUES(?,?,?) ON DUPLICATE KEY UPDATE mode=VALUES(mode), left_at=NULL"
                   : "INSERT INTO participants(incident_id,user_id,mode) VALUES(?,?,?) ON CONFLICT(incident_id,user_id) DO UPDATE SET mode=excluded.mode, left_at=NULL")->execute([$inc['id'], $uid, $mode]);
  // Option ohne Standortfreigabe (z. B. Koordinator KP Rück): bisherigen Standort von der Karte nehmen
  if ($m && !(int)$m['share_location']) $db->prepare("UPDATE participants SET lat=NULL, lon=NULL, loc_at=NULL WHERE incident_id=? AND user_id=?")->execute([$inc['id'], $uid]);
  if ($m && (int)$m['lead_rights']) $db->prepare("UPDATE participants SET role_id=NULL WHERE incident_id=? AND user_id=?")->execute([$inc['id'], $uid]); // Leitungsrechte ohne Spezialrolle
  if (!$prev || $prev['mode'] !== $mode || $prev['left_at'] !== null) {
    if ($mode === 'declined') logev($inc['id'], $uid, 'decline', 'hat den Einsatz abgelehnt');
    else logev($inc['id'], $uid, 'accept', ($prev && $prev['left_at'] !== null ? 'ist wieder eingestiegen' : 'hat den Einsatz angenommen') . ' (' . $m['name'] . ')');
  }
  out(['ok' => true]);

 case 'incident/leave':
  $inc = activeIncident(); if (!$inc) fail('Kein aktiver Einsatz');
  $p = part($inc['id']);
  $db->prepare("UPDATE participants SET left_at=?, role_id=NULL WHERE incident_id=? AND user_id=?")->execute([$now, $inc['id'], $uid]);
  if ($p && $p['left_at'] === null && $p['mode'] !== 'declined') logev($inc['id'], $uid, 'leave', 'hat den Einsatz verlassen');
  out(['ok' => true]);

 case 'incident/role': // eigene Spezialrolle: role_id oder null
  $inc = activeIncident(); $p = $inc ? part($inc['id']) : null; if (!$p) fail('Nicht im Einsatz');
  if (leadMode($p['mode'])) fail('Mit dieser Einrückoption gibt es keine Spezialrolle');
  $rid = !empty($in['role_id']) ? (int)$in['role_id'] : null;
  if ($rid !== ($p['role_id'] !== null ? (int)$p['role_id'] : null)) {
    $db->prepare("UPDATE participants SET role_id=? WHERE incident_id=? AND user_id=?")->execute([$rid, $inc['id'], $uid]);
    if ($rid) { $s = $db->prepare("SELECT name FROM roles WHERE id=?"); $s->execute([$rid]); logev($inc['id'], $uid, 'role', 'übernimmt die Rolle ' . ($s->fetch()['name'] ?? '?')); }
    else logev($inc['id'], $uid, 'role', 'legt die Spezialrolle ab');
  }
  out(['ok' => true]);

 case 'incident/role/set': // Einsatzleitung setzt die Spezialrolle einer Einsatzkraft: user_id, role_id oder null
  $inc = activeIncident(); if (!$inc || !canManage($inc)) fail('Nur die Einsatzleitung kann Spezialrollen anderer Personen ändern', 403);
  $t = (int)($in['user_id'] ?? 0); $p = part($inc['id'], $t);
  if (!$p || $p['left_at'] !== null || $p['mode'] === 'declined') fail('Die Person ist nicht im Einsatz');
  if (leadMode($p['mode'])) fail('Mit dieser Einrückoption gibt es keine Spezialrolle');
  $rid = !empty($in['role_id']) ? (int)$in['role_id'] : null; $rn = null;
  if ($rid) { $s = $db->prepare("SELECT name FROM roles WHERE id=? AND active=1"); $s->execute([$rid]); $rn = $s->fetch()['name'] ?? null; if ($rn === null) fail('Unbekannte Spezialrolle'); }
  if ($rid !== ($p['role_id'] !== null ? (int)$p['role_id'] : null)) {
    $db->prepare("UPDATE participants SET role_id=? WHERE incident_id=? AND user_id=?")->execute([$rid, $inc['id'], $t]);
    $s = $db->prepare("SELECT name FROM users WHERE id=?"); $s->execute([$t]); $who = $s->fetch()['name'] ?? '?';
    logev($inc['id'], $uid, 'role', $rid ? "teilt $who die Rolle $rn zu" : "entfernt die Spezialrolle von $who");
  }
  out(['ok' => true]);

 case 'incident/location': // water=1: Gerät liegt am Gewässer (z. B. Boot), manual=1: von Hand gesetzt; beides zählt nicht als Fahrt
  $inc = activeIncident(); if (!$inc) fail('Kein aktiver Einsatz');
  $p = part($inc['id']); if (!$p || $p['left_at'] !== null || $p['mode'] === 'declined') out(['ok' => true]);
  $la = (float)$in['lat']; $lo = (float)$in['lon'];
  $db->prepare("UPDATE participants SET lat=?,lon=?,loc_at=? WHERE incident_id=? AND user_id=?")->execute([$la, $lo, $now, $inc['id'], $uid]);
  if ((int)$inc['record'] === 1) $db->prepare("INSERT INTO track(incident_id,user_id,ts,lat,lon) VALUES(?,?,?,?,?)")->execute([$inc['id'], $uid, $now, $la, $lo]);
  // Fahrt erkennen: Start = letzte Position vor der ersten schnellen Meldung (log_*), fast_at = letzte schnelle Meldung
  $dt = $p['loc_at'] !== null ? $now - (int)$p['loc_at'] : 0;
  $fast = $dt >= 4 && empty($in['water']) && empty($in['manual']) && hav((float)$p['lat'], (float)$p['lon'], $la, $lo) / $dt >= VEHICLE_MS;
  $trip = fn($a, $b, $c, $f) => $db->prepare("UPDATE participants SET log_lat=?,log_lon=?,log_at=?,fast_at=? WHERE incident_id=? AND user_id=?")->execute([$a, $b, $c, $f, $inc['id'], $uid]);
  if ($p['fast_at'] === null) { if ($fast) $trip((float)$p['lat'], (float)$p['lon'], (int)$p['loc_at'], $now); }
  else {
    $end = !$fast && $now - (int)$p['fast_at'] >= STOP_S; $long = $fast && $now - (int)$p['log_at'] >= TRIP_LOG_S;
    if ($end || $long) {
      $d = hav((float)$p['log_lat'], (float)$p['log_lon'], $la, $lo);
      if ($d >= TRIP_MIN_M) {
        $t = max(1, ($end ? (int)$p['fast_at'] : $now) - (int)$p['log_at']); $a = placeName((float)$p['log_lat'], (float)$p['log_lon']); $b = placeName($la, $lo);
        logev($inc['id'], $uid, 'move', ($a === $b ? "fährt innerhalb von $a" : "fährt von $a " . ($end ? 'nach' : 'Richtung') . " $b")
          . ' (ca. ' . number_format($d / 1000, 1, '.', '') . ' km Luftlinie, Ø ' . round($d / $t * 3.6) . ' km/h' . ($end ? '' : ', unterwegs') . ')');
      }
      $end ? $trip(null, null, null, null) : $trip($la, $lo, $now, $now); // lange Fahrt: ab hier neuer Abschnitt
    } elseif ($fast) $db->prepare("UPDATE participants SET fast_at=? WHERE incident_id=? AND user_id=?")->execute([$now, $inc['id'], $uid]);
  }
  out(['ok' => true]);

 case 'incident/event': // vom Gerät erkannte Ereignisse, z. B. Suchgebiet erreicht
  $inc = activeIncident(); if (!$inc || !isActivePart($inc['id'])) fail('Nicht im Einsatz');
  if (($in['kind'] ?? '') === 'area') {
    $s = $db->prepare("SELECT COUNT(*) c FROM incident_log WHERE incident_id=? AND user_id=? AND kind='area'"); $s->execute([$inc['id'], $uid]);
    if (!$s->fetch()['c']) logev($inc['id'], $uid, 'area', 'hat das Suchgebiet erreicht');
  }
  out(['ok' => true]);

 case 'incident/flow': // Fliessgeschwindigkeit in m/s (Teilnehmende und Verwaltung)
  $inc = activeIncident(); if (!$inc || !canFlow($inc)) fail('Nur Personen, die am Einsatz teilnehmen, können die Fliessgeschwindigkeit setzen', 403);
  $v = (float)($in['flow_ms'] ?? 0); if ($v <= 0 || $v > 15) fail('Fliessgeschwindigkeit muss zwischen 0 und 15 m/s liegen');
  $db->prepare("UPDATE incidents SET flow_ms=? WHERE id=?")->execute([$v, $inc['id']]);
  logev($inc['id'], $uid, 'flow', 'setzt die Fliessgeschwindigkeit auf ' . $v . ' m/s');
  out(['ok' => true]);

 case 'incident/mark': // Position des Rettlings: alle Teilnehmenden (eigener Standort), manuell nur Einsatzleitung
  $inc = activeIncident(); if (!$inc) fail('Kein aktiver Einsatz');
  $man = !empty($in['manual']);
  if ($man ? !canManage($inc) : !isActivePart($inc['id'])) fail($man ? 'Nur die Einsatzleitung kann die Position manuell setzen' : 'Nur Personen, die den Einsatz angenommen haben, können markieren', 403);
  if (!isset($in['lat'], $in['lon'])) fail('Standort fehlt');
  $la = (float)$in['lat']; $lo = (float)$in['lon'];
  if (!$man) $db->prepare("DELETE FROM rescuee_marks WHERE incident_id=? AND user_id=? AND manual=0")->execute([$inc['id'], $uid]);
  $db->prepare("INSERT INTO rescuee_marks(incident_id,user_id,lat,lon,ts,manual) VALUES(?,?,?,?,?,?)")->execute([$inc['id'], $uid, $la, $lo, $now, $man ? 1 : 0]);
  logev($inc['id'], $uid, 'mark', $man ? 'setzt die Position des Rettlings manuell' : 'markiert die Position des Rettlings an eigenem Standort');
  out(['ok' => true]);

 case 'incident/mark/remove': // Einsatzleitung/Verwaltung alle, sonst nur eigene
  $inc = activeIncident(); if (!$inc) fail('Kein aktiver Einsatz');
  $s = $db->prepare("SELECT * FROM rescuee_marks WHERE id=? AND incident_id=?"); $s->execute([(int)($in['id'] ?? 0), $inc['id']]); $m = $s->fetch();
  if (!$m) fail('Markierung nicht gefunden', 404);
  if (!canManage($inc) && (int)$m['user_id'] !== $uid) fail('Nur die Einsatzleitung kann fremde Markierungen entfernen', 403);
  $db->prepare("DELETE FROM rescuee_marks WHERE id=?")->execute([$m['id']]);
  logev($inc['id'], $uid, 'markdel', (int)$m['user_id'] === $uid ? 'entfernt die eigene Markierung des Rettlings' : 'entfernt eine Markierung des Rettlings');
  out(['ok' => true]);

 case 'incident/poi': // Position (kind=point, 1 Punkt) oder Bereich (kind=area, Polygon mit 3–200 Punkten) markieren: nur Einsatzleitung
  $inc = activeIncident(); if (!$inc) fail('Kein aktiver Einsatz');
  if (!canManage($inc)) fail('Nur die Einsatzleitung kann Markierungen und Bereiche setzen', 403);
  $kind = ($in['kind'] ?? '') === 'area' ? 'area' : 'point';
  $name = trim((string)($in['name'] ?? ''));
  if ($name === '') fail('Bezeichnung fehlt');
  $name = mb_substr($name, 0, 80);
  $col = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($in['color'] ?? '')) ? $in['color'] : '#ff9f1c';
  $pts = [];
  foreach ((array)($in['pts'] ?? []) as $q) {
    if (!is_array($q) || count($q) !== 2 || !is_numeric($q[0]) || !is_numeric($q[1]) || abs($q[0]) > 90 || abs($q[1]) > 180) fail('Ungültige Koordinaten');
    $pts[] = [round((float)$q[0], 7), round((float)$q[1], 7)];
  }
  if ($kind === 'point' ? count($pts) !== 1 : count($pts) < 3 || count($pts) > 200) fail($kind === 'point' ? 'Für eine Markierung ist genau ein Punkt nötig' : 'Ein Bereich braucht 3 bis 200 Punkte');
  $db->prepare("INSERT INTO pois(incident_id,kind,name,color,pts,created_by,created) VALUES(?,?,?,?,?,?,?)")->execute([$inc['id'], $kind, $name, $col, json_encode($pts), $uid, $now]);
  $pid = (int)$db->lastInsertId();
  if ($kind === 'point') $txt = sprintf('setzt die Markierung «%s» (%.5F, %.5F)', $name, $pts[0][0], $pts[0][1]);
  else { // Fläche grob über lokale Projektion (Shoelace), reicht für die Angabe im Log
    $k = 111320 * cos(deg2rad($pts[0][0])); $a = 0; $n = count($pts);
    for ($j = 0; $j < $n; $j++) { $p = $pts[$j]; $q = $pts[($j + 1) % $n]; $a += $p[1] * $k * $q[0] * 111320 - $q[1] * $k * $p[0] * 111320; }
    $txt = sprintf('markiert den Bereich «%s» (%d Punkte, ca. %s ha)', $name, $n, number_format(abs($a) / 2 / 10000, 1, '.', "'"));
  }
  logev($inc['id'], $uid, 'poi', $txt);
  out(['ok' => true, 'id' => $pid]);

 case 'incident/poi/remove': // Position oder Bereich entfernen: nur Einsatzleitung (bleibt in der Datenbank, removed gesetzt)
  $inc = activeIncident(); if (!$inc) fail('Kein aktiver Einsatz');
  if (!canManage($inc)) fail('Nur die Einsatzleitung kann Markierungen und Bereiche entfernen', 403);
  $s = $db->prepare("SELECT * FROM pois WHERE id=? AND incident_id=? AND removed IS NULL"); $s->execute([(int)($in['id'] ?? 0), $inc['id']]); $p = $s->fetch();
  if (!$p) fail('Markierung nicht gefunden', 404);
  $db->prepare("UPDATE pois SET removed=? WHERE id=?")->execute([$now, $p['id']]);
  logev($inc['id'], $uid, 'poidel', ($p['kind'] === 'area' ? 'entfernt den Bereich «' : 'entfernt die Markierung «') . $p['name'] . '»');
  out(['ok' => true]);

 // Rettlinge: erfassen, bearbeiten, Standort und «gerettet» markieren dürfen alle im Einsatz, entfernen nur die Einsatzleitung
 case 'incident/rescuee/save': // id fehlt = neu; name (leer = «Unbekannter Rettling»), descr; pls: {lat, lon, at} = eigener PLS, null = PLS des Einsatzes, fehlt = unverändert
  $inc = activeIncident(); if (!$inc) fail('Kein aktiver Einsatz');
  if (!isActivePart($inc['id']) && !canManage($inc)) fail('Nur Personen, die den Einsatz angenommen haben, können Rettlinge erfassen', 403);
  $name = mb_substr(trim((string)($in['name'] ?? '')), 0, 80); $descr = mb_substr(trim((string)($in['descr'] ?? '')), 0, 1000);
  $r = !empty($in['id']) ? rescuee($inc, $in['id']) : null;
  if ($name === '') $name = $r && strpos($r['name'], 'Unbekannter Rettling') === 0 ? $r['name'] : unknownName((int)$inc['id']);
  $hasPls = array_key_exists('pls', $in); $pls = $hasPls && $in['pls'] !== null ? plsIn($in['pls'], plsAt($inc)) : [null, null, null];
  $plsTxt = fn($n) => $pls[0] === null ? "übernimmt für «{$n}» den PLS des Einsatzes" : sprintf('setzt den PLS von «%s» (%.5F, %.5F, %s)', $n, $pls[0], $pls[1], hm($pls[2]));
  if ($r) {
    $db->prepare("UPDATE rescuees SET name=?, descr=? WHERE id=?")->execute([$name, $descr, $r['id']]);
    if ($r['name'] !== $name || (string)$r['descr'] !== $descr) logev($inc['id'], $uid, 'rescuee', ($r['name'] !== $name ? "ändert den Rettling «{$r['name']}» zu «{$name}»" : "ändert die Beschreibung von «{$name}»") . ($descr !== '' ? ": {$descr}" : ''));
    $old = [$r['pls_lat'] === null ? null : round((float)$r['pls_lat'], 7), $r['pls_lon'] === null ? null : round((float)$r['pls_lon'], 7), $r['pls_at'] === null ? null : (int)$r['pls_at']];
    if ($hasPls && $old !== $pls) {
      $db->prepare("UPDATE rescuees SET pls_lat=?, pls_lon=?, pls_at=? WHERE id=?")->execute([...$pls, $r['id']]);
      logev($inc['id'], $uid, 'rescuee', $plsTxt($name));
    }
    out(['ok' => true, 'id' => (int)$r['id']]);
  }
  $db->prepare("INSERT INTO rescuees(incident_id,name,descr,created_by,created,pls_lat,pls_lon,pls_at) VALUES(?,?,?,?,?,?,?,?)")->execute([$inc['id'], $name, $descr, $uid, $now, ...$pls]);
  $rid = (int)$db->lastInsertId();
  logev($inc['id'], $uid, 'rescuee', "erfasst den Rettling «{$name}»" . ($descr !== '' ? ": {$descr}" : ''));
  if ($pls[0] !== null) logev($inc['id'], $uid, 'rescuee', $plsTxt($name));
  out(['ok' => true, 'id' => $rid]);

 case 'incident/rescuee/locate': // own=1: eigener geteilter Standort (serverseitig), clear=1: Fundort zurücksetzen (Issue #85), sonst lat/lon (auf der Karte gewählt)
  $inc = activeIncident(); if (!$inc) fail('Kein aktiver Einsatz');
  if (!isActivePart($inc['id']) && !canManage($inc)) fail('Nur Personen, die den Einsatz angenommen haben, können den Standort markieren', 403);
  $r = rescuee($inc, $in['id'] ?? 0);
  if (!empty($in['clear'])) { // wieder vermisst (ohne Standort), zählt wieder fürs Suchgebiet; im Replay ab hier ohne Standort (track ohne lat/lon)
    if ($r['lat'] === null) out(['ok' => true]);
    $db->prepare("UPDATE rescuees SET lat=NULL, lon=NULL, loc_at=NULL, loc_by=NULL WHERE id=?")->execute([$r['id']]);
    if ((int)$inc['record'] === 1) $db->prepare("INSERT INTO track(incident_id,rescuee_id,ts,lat,lon) VALUES(?,?,?,NULL,NULL)")->execute([$inc['id'], $r['id'], $now]);
    logev($inc['id'], $uid, 'resmark', "setzt den Fundort von «{$r['name']}» zurück");
    out(['ok' => true]);
  }
  if (!empty($in['own'])) {
    $p = part($inc['id']);
    if (!$p || $p['lat'] === null) fail('Dein Standort ist nicht bekannt: Standortfreigabe erlauben oder Position auf der Karte wählen');
    [$la, $lo] = [(float)$p['lat'], (float)$p['lon']];
  } else {
    if (!isset($in['lat'], $in['lon']) || !is_numeric($in['lat']) || !is_numeric($in['lon']) || abs($in['lat']) > 90 || abs($in['lon']) > 180) fail('Ungültige Koordinaten');
    [$la, $lo] = [round((float)$in['lat'], 7), round((float)$in['lon'], 7)];
  }
  $db->prepare("UPDATE rescuees SET lat=?, lon=?, loc_at=?, loc_by=? WHERE id=?")->execute([$la, $lo, $now, $uid, $r['id']]);
  if ((int)$inc['record'] === 1) $db->prepare("INSERT INTO track(incident_id,rescuee_id,ts,lat,lon) VALUES(?,?,?,?,?)")->execute([$inc['id'], $r['id'], $now, $la, $lo]); // frühere Standorte fürs Replay
  logev($inc['id'], $uid, 'resmark', sprintf('markiert den Standort von «%s» %s (%.5F, %.5F)', $r['name'], !empty($in['own']) ? 'am eigenen Standort' : 'auf der Karte', $la, $lo));
  out(['ok' => true]);

 case 'incident/rescuee/rescued': // rescued=1 gerettet, 0 = zurücknehmen
  $inc = activeIncident(); if (!$inc) fail('Kein aktiver Einsatz');
  if (!isActivePart($inc['id']) && !canManage($inc)) fail('Nur Personen, die den Einsatz angenommen haben, können Rettlinge als gerettet markieren', 403);
  $r = rescuee($inc, $in['id'] ?? 0); $on = !empty($in['rescued']);
  if ($on === ($r['rescued_at'] !== null)) out(['ok' => true]); // unverändert, z. B. gleichzeitig von zwei Personen
  $db->prepare("UPDATE rescuees SET rescued_at=?, rescued_by=? WHERE id=?")->execute($on ? [$now, $uid, $r['id']] : [null, null, $r['id']]);
  logev($inc['id'], $uid, 'rescued', $on ? "meldet «{$r['name']}» als gerettet" : "nimmt «gerettet» bei «{$r['name']}» zurück");
  out(['ok' => true]);

 case 'incident/rescuee/remove': // nur Einsatzleitung (bleibt in der Datenbank, removed gesetzt)
  $inc = activeIncident(); if (!$inc) fail('Kein aktiver Einsatz');
  if (!canManage($inc)) fail('Nur die Einsatzleitung kann Rettlinge entfernen', 403);
  $r = rescuee($inc, $in['id'] ?? 0);
  $db->prepare("UPDATE rescuees SET removed=? WHERE id=?")->execute([$now, $r['id']]);
  logev($inc['id'], $uid, 'rescuee', "entfernt den Rettling «{$r['name']}»");
  out(['ok' => true]);

 // Externe Einsatzkräfte ohne App-Konto mit festem Standort (Issue #39): nur Einsatzleitung
 case 'incident/external/save': // id fehlt = neu (lat, lon Pflicht); name, descr, role_id; lat/lon bei bestehenden = verschieben
  $inc = activeIncident(); if (!$inc) fail('Kein aktiver Einsatz');
  if (!canManage($inc)) fail('Nur die Einsatzleitung kann externe Einsatzkräfte erfassen', 403);
  $name = mb_substr(trim((string)($in['name'] ?? '')), 0, 80); $descr = mb_substr(trim((string)($in['descr'] ?? '')), 0, 500);
  if ($name === '') fail('Name fehlt');
  $rid = roleIn($in['role_id'] ?? null); $ll = isset($in['lat'], $in['lon']) ? llIn($in['lat'], $in['lon']) : null; [$ab, $co] = extStyle($in);
  $rn = function ($r) use ($db) { if ($r === null) return 'ohne Spezialrolle'; $s = $db->prepare("SELECT name FROM roles WHERE id=?"); $s->execute([$r]); return $s->fetch()['name'] ?? '?'; };
  if (!empty($in['id'])) {
    $s = $db->prepare("SELECT * FROM externals WHERE id=? AND incident_id=? AND removed IS NULL"); $s->execute([(int)$in['id'], $inc['id']]); $e = $s->fetch() ?: fail('Externe Einsatzkraft nicht gefunden', 404);
    $old = $e['role_id'] === null ? null : (int)$e['role_id'];
    $db->prepare("UPDATE externals SET name=?, descr=?, role_id=?, abbr=?, color=? WHERE id=?")->execute([$name, $descr, $rid, $ab, $co, $e['id']]);
    if ($e['name'] !== $name || (string)$e['descr'] !== $descr || $old !== $rid) logev($inc['id'], $uid, 'ext', "ändert die externe Einsatzkraft «{$e['name']}»" . ($e['name'] !== $name ? " zu «{$name}»" : '') . ($old !== $rid ? ' (' . $rn($rid) . ')' : ''));
    if ($ll && $ll !== [round((float)$e['lat'], 7), round((float)$e['lon'], 7)]) {
      $db->prepare("UPDATE externals SET lat=?, lon=?, moved=? WHERE id=?")->execute([$ll[0], $ll[1], $now, $e['id']]);
      logev($inc['id'], $uid, 'ext', sprintf('verschiebt die externe Einsatzkraft «%s» (%.5F, %.5F)', $name, $ll[0], $ll[1]));
    }
    out(['ok' => true, 'id' => (int)$e['id']]);
  }
  if (!$ll) fail('Standort fehlt');
  // manual = von Hand erfasst (nicht ab Vorlage): steht danach für den Rest des Einsatzes als Vorlage bereit
  $db->prepare("INSERT INTO externals(incident_id,name,descr,role_id,abbr,color,lat,lon,created_by,created,manual) VALUES(?,?,?,?,?,?,?,?,?,?,?)")->execute([$inc['id'], $name, $descr, $rid, $ab, $co, $ll[0], $ll[1], $uid, $now, empty($in['manual']) ? 0 : 1]);
  $nid = (int)$db->lastInsertId();
  logev($inc['id'], $uid, 'ext', sprintf('erfasst die externe Einsatzkraft «%s» (%s, %.5F, %.5F)', $name, $rn($rid), $ll[0], $ll[1]));
  out(['ok' => true, 'id' => $nid]);

 case 'incident/external/remove': // nur Einsatzleitung (bleibt in der Datenbank, removed gesetzt)
  $inc = activeIncident(); if (!$inc) fail('Kein aktiver Einsatz');
  if (!canManage($inc)) fail('Nur die Einsatzleitung kann externe Einsatzkräfte entfernen', 403);
  $s = $db->prepare("SELECT * FROM externals WHERE id=? AND incident_id=? AND removed IS NULL"); $s->execute([(int)($in['id'] ?? 0), $inc['id']]); $e = $s->fetch() ?: fail('Externe Einsatzkraft nicht gefunden', 404);
  $db->prepare("UPDATE externals SET removed=? WHERE id=?")->execute([$now, $e['id']]);
  logev($inc['id'], $uid, 'ext', "entfernt die externe Einsatzkraft «{$e['name']}»");
  out(['ok' => true]);

 case 'incident/kick': // Einsatzkraft aus dem Einsatz entfernen (Wiedereinstieg bleibt möglich)
  $inc = activeIncident(); if (!$inc || !canManage($inc)) fail('Nur Einsatzleitung, Administratoren oder die Person, die den Einsatz gestartet hat, können Einsatzkräfte entfernen', 403);
  $t = (int)($in['user_id'] ?? 0);
  if ($t === $uid) fail('Zum Verlassen den Knopf «Einsatz verlassen» verwenden');
  $p = part($inc['id'], $t);
  if (!$p || $p['left_at'] !== null || $p['mode'] === 'declined') fail('Die Person ist nicht im Einsatz');
  $db->prepare("UPDATE participants SET left_at=?, role_id=NULL WHERE incident_id=? AND user_id=?")->execute([$now, $inc['id'], $t]);
  $s = $db->prepare("SELECT name FROM users WHERE id=?"); $s->execute([$t]);
  logev($inc['id'], $uid, 'kick', 'hat ' . ($s->fetch()['name'] ?? '?') . ' aus dem Einsatz entfernt');
  out(['ok' => true]);

 case 'incident/message': // to: leer = alle Teilnehmenden, sonst Liste von User-IDs (nur Teilnehmende)
  $inc = activeIncident(); if (!$inc) fail('Kein aktiver Einsatz');
  if (!isActivePart($inc['id']) && !exLead($inc)) fail('Nachrichten können nur Personen senden, die den Einsatz angenommen haben');
  if (!canMessage($inc)) fail('Deine Rolle darf keine Nachrichten senden', 403);
  if (trim($in['text'] ?? '') === '') fail('Text fehlt');
  $s = $db->prepare("SELECT user_id FROM participants WHERE incident_id=? AND mode<>'declined' AND left_at IS NULL"); $s->execute([$inc['id']]);
  $act = array_map('intval', array_column($s->fetchAll(), 'user_id'));
  $to = null;
  if (!empty($in['to'])) { $to = array_values(array_intersect(array_map('intval', $in['to']), $act)); if (!$to) fail('Die gewählten Personen haben den Einsatz nicht angenommen'); }
  // Standort der sendenden Person (nur wenn sie ihn teilt); Markierung an mark_lat/mark_lon oder am eigenen Standort
  $p = part($inc['id']); $la = $p && $p['lat'] !== null ? (float)$p['lat'] : null; $lo = $p && $p['lon'] !== null ? (float)$p['lon'] : null; // Übungsleitung: keine Teilnahme, kein Standort
  $mk = !empty($in['mark']) ? 1 : 0; $mla = $mlo = null;
  if ($mk) {
    $ok = isset($in['mark_lat'], $in['mark_lon']) && is_numeric($in['mark_lat']) && is_numeric($in['mark_lon']) && abs($in['mark_lat']) <= 90 && abs($in['mark_lon']) <= 180;
    [$mla, $mlo] = $ok ? [(float)$in['mark_lat'], (float)$in['mark_lon']] : [$la, $lo];
    if ($mla === null) fail('Für die Markierung ist ein Standort nötig: Position auf der Karte wählen');
  }
  $db->prepare("INSERT INTO messages(incident_id,from_user,text,recipients,created,lat,lon,mark,mark_lat,mark_lon) VALUES(?,?,?,?,?,?,?,?,?,?)")
     ->execute([$inc['id'], $uid, $in['text'], $to === null ? null : json_encode($to), $now, $la, $lo, $mk, $mla, $mlo]);
  // Push nur an die Empfänger (keine Auswahl = alle Teilnehmenden), nicht an die sendende Person
  pushTo(array_diff($to ?? $act, [$uid]), ['t' => 'Nachricht von ' . $me['name'], 'b' => mb_substr($in['text'], 0, 300), 'tag' => 'm:' . $db->lastInsertId(), 'crit' => 0]);
  out(['ok' => true]); // im Einsatzprotokoll erscheint die Nachricht über logRows()

 case 'incident/message/unmark': // Kartenmarkierung einer Nachricht entfernen: sendende Person oder Einsatzleitung
  $inc = activeIncident(); if (!$inc) fail('Kein aktiver Einsatz');
  $s = $db->prepare("SELECT * FROM messages WHERE id=? AND incident_id=? AND mark=1"); $s->execute([(int)($in['id'] ?? 0), $inc['id']]); $m = $s->fetch();
  if (!$m) fail('Markierung nicht gefunden', 404);
  if ((int)$m['from_user'] !== $uid && !canManage($inc)) fail('Nur wer die Nachricht gesendet hat oder die Einsatzleitung kann die Markierung entfernen', 403);
  $db->prepare("UPDATE messages SET mark=0, unmarked=? WHERE id=?")->execute([$now, $m['id']]);
  logev($inc['id'], $uid, 'markdel', 'entfernt die Kartenmarkierung der Nachricht «' . mb_substr($m['text'], 0, 60) . '»');
  out(['ok' => true]);

 case 'incident/air/set': // Helikopter für diesen Einsatz ein- oder ausschalten (Einsatzleitung), nur bei eingeschaltetem Hauptschalter
  $inc = activeIncident(); if (!$inc) fail('Kein aktiver Einsatz');
  if (!canManage($inc)) fail('Nur die Einsatzleitung kann die Helikopter ein- und ausschalten', 403);
  if (!airCfg()['on']) fail('Helikopterdaten sind in der Verwaltung ausgeschaltet', 403);
  $on = empty($in['on']) ? 0 : 1; if (airInc($inc) === (bool)$on) out(['ok' => true]);
  $db->prepare("UPDATE incidents SET air=? WHERE id=?")->execute([$on, $inc['id']]);
  logev($inc['id'], $uid, 'air', $on ? 'schaltet die Helikopter ein' : 'schaltet die Helikopter aus');
  out(['ok' => true]);

 case 'incident/record': // Aufzeichnung steuern (Einsatzleitung): start (auch nach «beenden» wieder), pause, resume, stop
  $inc = activeIncident(); if (!$inc) fail('Kein aktiver Einsatz');
  if (!canManage($inc)) fail('Nur die Einsatzleitung kann die Aufzeichnung steuern', 403);
  $cur = (int)$inc['record']; $a = (string)($in['action'] ?? '');
  // erlaubte Übergänge: Ziel-Zustand und Log-Text je Aktion
  $T = ['start' => [[0], 1, (int)$inc['recorded'] ? 'nimmt die Aufzeichnung wieder auf' : 'startet die Aufzeichnung'], 'pause' => [[1], 2, 'pausiert die Aufzeichnung'],
        'resume' => [[2], 1, 'setzt die Aufzeichnung fort'], 'stop' => [[1, 2], 0, 'beendet die Aufzeichnung']];
  if (!isset($T[$a])) fail('Unbekannte Aktion');
  if (!in_array($cur, $T[$a][0], true)) out(['ok' => true, 'record' => $cur]); // schon im gewünschten Zustand, z. B. gleichzeitig von zwei Personen
  setRec((int)$inc['id'], $T[$a][1]);
  logev($inc['id'], $uid, 'rec', $T[$a][2]);
  out(['ok' => true, 'record' => $T[$a][1]]);

 case 'incident/public': // Öffentliche Ansicht (nur Einsatzleitung): on = ein/aus, anon = Namen ausblenden, log = Einsatzprotokoll zeigen (Issue #54). Ausschalten macht den Link ungültig, beim nächsten Einschalten gibt es einen neuen.
  $inc = activeIncident(); if (!$inc) fail('Kein aktiver Einsatz');
  if (!canManage($inc)) fail('Nur die Einsatzleitung kann die öffentliche Ansicht steuern', 403);
  $was = $inc['public_token'] !== null; $wasAnon = (int)$inc['public_anon'] === 1;
  $on = array_key_exists('on', $in) ? !empty($in['on']) : $was; $anon = array_key_exists('anon', $in) ? !empty($in['anon']) : $wasAnon;
  $wasLog = (int)$inc['public_log'] === 1; $lg = array_key_exists('log', $in) ? !empty($in['log']) : $wasLog;
  $t = $on ? ($inc['public_token'] ?? bin2hex(random_bytes(16))) : null;
  $db->prepare("UPDATE incidents SET public_token=?, public_anon=?, public_log=? WHERE id=?")->execute([$t, $anon ? 1 : 0, $lg ? 1 : 0, $inc['id']]);
  $how = ($anon ? 'anonym, ohne Namen' : 'mit Namen') . ($lg ? ', mit Einsatzprotokoll' : ', ohne Einsatzprotokoll');
  if ($on && !$was) logev($inc['id'], $uid, 'pub', "schaltet die öffentliche Ansicht ein ($how)");
  elseif (!$on && $was) logev($inc['id'], $uid, 'pub', 'schaltet die öffentliche Ansicht aus');
  elseif ($on && ($anon !== $wasAnon || $lg !== $wasLog)) logev($inc['id'], $uid, 'pub', "stellt die öffentliche Ansicht um ($how)");
  out(['ok' => true, 'public_token' => $t]);

 case 'incident/pls': // PLS des Einsatzes setzen, verschieben oder entfernen (Einsatzleitung): lat, lon, at (fehlt = Alarmeingang) oder clear=1
  $inc = activeIncident(); if (!$inc) fail('Kein aktiver Einsatz');
  if (!canManage($inc)) fail('Nur die Einsatzleitung kann den PLS des Einsatzes ändern', 403);
  if (!empty($in['clear'])) {
    $db->prepare("UPDATE incidents SET lat=NULL, lon=NULL, pls_at=NULL WHERE id=?")->execute([$inc['id']]);
    logev($inc['id'], $uid, 'pls', 'entfernt den PLS des Einsatzes');
    out(['ok' => true]);
  }
  [$la, $lo, $at] = plsIn($in, (int)$inc['alarm_at']);
  $db->prepare("UPDATE incidents SET lat=?, lon=?, pls_at=? WHERE id=?")->execute([$la, $lo, $at === (int)$inc['alarm_at'] ? null : $at, $inc['id']]);
  logev($inc['id'], $uid, 'pls', sprintf('%s den PLS des Einsatzes (%.5F, %.5F, %s)', $inc['lat'] === null ? 'setzt' : 'ändert', $la, $lo, hm($at)));
  out(['ok' => true]);

 case 'incident/end':
  $inc = activeIncident(); if (!$inc || !canManage($inc)) fail('Nur Einsatzleitung, Administratoren oder die Person, die den Einsatz gestartet hat, können den Einsatz beenden', 403);
  if ((int)$inc['record']) setRec((int)$inc['id'], 0); // laufende Aufzeichnung endet mit dem Einsatz
  $db->prepare("UPDATE incidents SET ended_at=? WHERE id=?")->execute([$now, $inc['id']]);
  logev($inc['id'], $uid, 'end', 'hat den Einsatz beendet');
  if (feature($inc, 'hydro')) { $hid = (int)$inc['id']; after(fn() => hydroLog($hid, 'end')); } // Pegel und Abfluss am Einsatzende (Issue #125), nach der Antwort
  $s = $db->prepare("SELECT user_id FROM participants WHERE incident_id=? AND mode<>'declined' AND left_at IS NULL AND user_id<>?"); $s->execute([$inc['id'], $uid]);
  pushTo(array_column($s->fetchAll(), 'user_id'), ['t' => mb_strtoupper(kindOf($inc)['name']) . ' BEENDET', 'b' => kindOf($inc)['name'] . ' beendet.', 'tag' => 'e:' . $inc['id'], 'crit' => 0, 'silent' => in_array(kindOf($inc)['alarm'], ['low', 'none'], true) ? 1 : 0]);
  out(['ok' => true]);

 /* ---------- Vorbereitete Übungen (Issue #29) ---------- */
 case 'ex/start': // vorbereitete Übung starten: nur die Übungsleitung dieser Übung (auch Administratoren und Übungsleiter nur, wenn sie eingetragen sind); Alarm mit der Aufgebotsmeldung, ohne PLS (kommt über die Freigabe)
  if (activeIncident()) fail('Es läuft bereits ein Einsatz');
  $s = $db->prepare("SELECT * FROM ex_plans WHERE id=? AND active=1"); $s->execute([(int)($in['id'] ?? 0)]); $p = $s->fetch();
  if (!$p || $p['incident_id'] !== null) fail('Diese Übung ist nicht mehr startbar', 404);
  if (!in_array($uid, exLeads($p), true)) fail('Nur die Übungsleitung dieser Übung kann sie starten', 403);
  $kind = planKind($p); if (!$kind) fail('Kein aktiver Einsatztyp erlaubt vorbereitete Übungen (Verwaltung → Einsatztypen)'); $kid = $kind['id'];
  $rec = (int)$p['record'] && features($kid)['rec'] ? 1 : 0; $tok = features($kid)['pub'] ? $p['public_token'] : null;
  // Aufgebot (Issue #90): Gruppen (beim Start aufgelöst) und Einzelpersonen, ohne die Übungsleitung; beides leer = alle beim Typ aufbietbaren Gruppen.
  // Der Einsatztyp kann sich seit dem Vorbereiten geändert haben: Wer nicht zugelassen ist, fällt hier weg. Ohne aktive Einsatzgruppen wie bisher.
  $pg = idsIn(json_decode($p['grp_ids'] ?? '[]', true)); $pi = exInvite($p); $iv = null; $ig = null; $pall = !$pg && $pi === null; $kp = kindPeople($kind);
  if ($kp !== null) { $kg = kindGroups($kind); $pg = $pall ? $kg : array_values(array_intersect($pg, $kg)); if ($pi !== null) $pi = array_values(array_intersect($pi, $kp)); }
  if ($kp !== null || !$pall) { $iv = array_values(array_diff(array_unique(array_merge(grpMembers($pg), $pi ?? [])), exLeads($p))); $ig = ['g' => $pg, 'p' => $pi ?? []]; }
  $abs = array_values(array_diff($iv === null ? absentIds() : array_intersect(absentIds(), $iv), exLeads($p))); // Abwesende (Issue #91)
  $db->prepare("INSERT INTO incidents(message,alarm_at,created_by,is_exercise,kind_id,plan_id,public_token,public_anon,air,invite,inv_groups,inv_absent) VALUES(?,?,?,1,?,?,?,?,?,?,?,?)")->execute([$p['message'], $now, $uid, $kid, $p['id'], $tok, (int)$p['public_anon'], airCfg()['on'] ? airDef($kid) : null, $iv === null ? null : json_encode($iv), $ig === null ? null : json_encode($ig), $abs ? json_encode($abs) : null]);
  $nid = (int)$db->lastInsertId();
  $db->prepare("UPDATE ex_plans SET incident_id=? WHERE id=?")->execute([$nid, $p['id']]);
  if ($rec) setRec($nid, 1);
  logev($nid, $uid, 'start', "startet die vorbereitete Übung «{$p['title']}»" . ($rec ? ' (mit Aufzeichnung)' : '') . ($iv === null ? '' : ', alarmiert ' . count($iv) . (count($iv) === 1 ? ' aufgebotene Person' : ' aufgebotene Personen') . ' (' . invText($ig['g'], $ig['p']) . ')') . ($abs ? ' (' . absText(count($abs)) . ')' : '') . ': ' . $p['message']);
  if ($tok) logev($nid, $uid, 'pub', 'öffentliche Ansicht vorbereitet (' . ((int)$p['public_anon'] ? 'anonym, ohne Namen' : 'mit Namen') . ')');
  pushAlarm(['id' => $nid, 'kind_id' => $kid, 'message' => $p['message']], array_merge([$uid], exLeads($p), $abs), $iv); // wie alarmed(): nur Aufgebotene, nie die Übungsleitung
  out(['id' => $nid]);
 case 'ex/release': // vorbereitetes Element freigeben (Übungsleitung, Administratoren oder Übungsleiter): erst jetzt wird es für alle sichtbar und fürs Suchgebiet relevant
  $inc = activeIncident(); if (!$inc || empty($inc['plan_id'])) fail('Keine vorbereitete Übung aktiv');
  if (!exLead($inc) && !planner()) fail('Nur die Übungsleitung kann vorbereitete Elemente freigeben', 403);
  $s = $db->prepare("SELECT * FROM ex_injects WHERE id=? AND plan_id=? AND removed=0"); $s->execute([(int)($in['id'] ?? 0), (int)$inc['plan_id']]); $j = $s->fetch();
  if (!$j) fail('Element nicht gefunden', 404);
  if ($j['released'] !== null) out(['ok' => true]); // schon freigegeben, z. B. gleichzeitig von zwei Personen
  // ohne vorbereiteten Zeitpunkt gibt ihn die Übungsleitung bei der Freigabe an (ago, Minuten vor jetzt)
  $ago = $j['ago'] !== null ? (int)$j['ago'] : (int)($in['ago'] ?? 0); if ($ago < 0 || $ago > 1440) fail('Zeitpunkt: 0 bis 1440 Minuten vor der Freigabe');
  if ($e = exRelease($inc, $j, $ago, $uid)) fail($e);
  out(['ok' => true]);
 case 'ex/plans': plannerOnly(); // alle vorbereiteten Übungen mit Elementen und Stand der Übung
  $ps = $db->query("SELECT p.*,i.alarm_at,i.ended_at FROM ex_plans p LEFT JOIN incidents i ON i.id=p.incident_id WHERE p.active=1 ORDER BY p.incident_id IS NOT NULL, i.alarm_at DESC, p.title")->fetchAll();
  // Durchführungen (auch vor einem Zurücksetzen) mit Log und Replay
  $rs = []; foreach ($db->query("SELECT id,plan_id,kind_id,alarm_at,ended_at,recorded FROM incidents WHERE plan_id IS NOT NULL ORDER BY alarm_at DESC")->fetchAll() as $r) $rs[(int)$r['plan_id']][] = ints($r, ['id', 'plan_id', 'kind_id', 'alarm_at', 'ended_at', 'recorded']);
  out(array_map(fn($p) => ['leads' => exLeads($p), 'invite' => exInvite($p), 'grp_ids' => idsIn(json_decode($p['grp_ids'] ?? '[]', true)), 'injects' => exInjects((int)$p['id']), 'places' => placeSel($p), 'own_places' => placesOf((int)$p['id']), 'runs' => aiRows($rs[(int)$p['id']] ?? [])] + ints($p, ['id', 'kind_id', 'record', 'public_anon', 'created_by', 'created', 'incident_id', 'alarm_at', 'ended_at']), $ps));
 case 'ex/plan/save': plannerOnly(); // anlegen oder ändern (nur vor dem Start): title, message, leads, invite (null = alle alarmieren), record, public, public_anon
  $title = mb_substr(trim((string)($in['title'] ?? '')), 0, 120); $msg = trim((string)($in['message'] ?? ''));
  if ($title === '') fail('Titel fehlt'); if ($msg === '') fail('Die Aufgebotsmeldung ist Pflicht');
  $leads = array_values(array_unique(array_map('intval', (array)($in['leads'] ?? []))));
  if (!$leads) fail('Mindestens eine Person als Übungsleitung wählen');
  $s = $db->prepare("SELECT COUNT(*) c FROM users WHERE active=1 AND id IN (" . implode(',', array_fill(0, count($leads), '?')) . ")"); $s->execute($leads);
  if ((int)$s->fetch()['c'] !== count($leads)) fail('Unbekannte oder deaktivierte Person in der Übungsleitung');
  $inv = null; // aufgeboten: ohne die Übungsleitung (wird ohnehin nicht alarmiert); grp_ids = Einsatzgruppen (Issue #90), zusätzlich zu den Einzelpersonen
  $pg = idsIn($in['grp_ids'] ?? []);
  if ($pg) { $s = $db->prepare("SELECT COUNT(*) c FROM inc_groups WHERE active=1 AND id IN (" . qm($pg) . ")"); $s->execute($pg); if ((int)$s->fetch()['c'] !== count($pg)) fail('Unbekannte oder deaktivierte Einsatzgruppe'); }
  $pgj = $pg ? json_encode($pg) : null;
  if (($in['invite'] ?? null) !== null || $pg) {
    $inv = array_values(array_diff(array_unique(array_map('intval', (array)($in['invite'] ?? []))), $leads));
    if (!$inv && !$pg) fail('Mindestens eine Einsatzgruppe oder Person aufbieten oder «Alle» alarmieren');
    $s = $db->prepare("SELECT COUNT(*) c FROM users WHERE active=1 AND id IN (" . ($inv ? qm($inv) : 'NULL') . ")"); $s->execute($inv);
    if ($inv && (int)$s->fetch()['c'] !== count($inv)) fail('Unbekannte oder deaktivierte Person bei den Aufgebotenen');
    $inv = json_encode($inv);
  }
  $rec = empty($in['record']) ? 0 : 1; $anon = empty($in['public_anon']) ? 0 : 1;
  // feste Standorte (Issue #96): null = alle permanenten, sonst die gewählten (nur bekannte permanente)
  $pls = null; if (array_key_exists('places', $in) && $in['places'] !== null) { $ok = array_column(placesOf(null), 'id'); $pls = json_encode(array_values(array_intersect($ok, array_map('intval', (array)$in['places'])))); }
  $pk = kinds()[(int)($in['kind_id'] ?? 0)] ?? null; if (isset($in['kind_id']) && (!$pk || !$pk['plans'] || $pk['deleted'] || !$pk['active'])) fail('Dieser Einsatztyp erlaubt keine vorbereiteten Übungen');
  $pkid = $pk ? $pk['id'] : (($x = planKind([])) ? $x['id'] : null);
  if (!empty($in['id'])) {
    $s = $db->prepare("SELECT * FROM ex_plans WHERE id=? AND active=1"); $s->execute([(int)$in['id']]); $p = $s->fetch() ?: fail('Übung nicht gefunden', 404);
    if ($p['incident_id'] !== null) fail('Die Übung wurde bereits gestartet');
    $tok = empty($in['public']) ? null : ($p['public_token'] ?? bin2hex(random_bytes(16)));
    $db->prepare("UPDATE ex_plans SET title=?, message=?, leads=?, invite=?, grp_ids=?, record=?, public_token=?, public_anon=?, kind_id=?, places=? WHERE id=?")->execute([$title, $msg, json_encode($leads), $inv, $pgj, $rec, $tok, $anon, $pk ? $pk['id'] : ($p['kind_id'] ?? $pkid), array_key_exists('places', $in) ? $pls : $p['places'], $p['id']]);
    out(['ok' => true, 'id' => (int)$p['id']]);
  }
  $db->prepare("INSERT INTO ex_plans(title,message,leads,invite,grp_ids,record,public_token,public_anon,created_by,created,kind_id,places) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)")->execute([$title, $msg, json_encode($leads), $inv, $pgj, $rec, empty($in['public']) ? null : bin2hex(random_bytes(16)), $anon, $uid, $now, $pkid, $pls]);
  out(['ok' => true, 'id' => (int)$db->lastInsertId()]);
 case 'ex/plan/copy': plannerOnly(); // als neue, noch nicht gestartete Übung kopieren (mit allen Elementen, nicht freigegeben)
  $s = $db->prepare("SELECT * FROM ex_plans WHERE id=? AND active=1"); $s->execute([(int)($in['id'] ?? 0)]); $p = $s->fetch() ?: fail('Übung nicht gefunden', 404);
  $db->prepare("INSERT INTO ex_plans(kind_id,title,message,leads,invite,grp_ids,record,public_token,public_anon,created_by,created,places) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)")->execute([$p['kind_id'], mb_substr('Kopie von ' . $p['title'], 0, 120), $p['message'], $p['leads'], $p['invite'], $p['grp_ids'], $p['record'], $p['public_token'] === null ? null : bin2hex(random_bytes(16)), $p['public_anon'], $uid, $now, $p['places']]);
  $nid = (int)$db->lastInsertId(); $map = []; $js = exInjects((int)$p['id']);
  foreach (placesOf((int)$p['id']) as $f) $db->prepare("INSERT INTO places(plan_id,name,descr,abbr,color,lat,lon,active,cat_id,pts,line,sort) VALUES(?,?,?,?,?,?,?,1,?,?,?,?)")->execute([$nid, $f['name'], $f['descr'], $f['abbr'], $f['color'], $f['lat'], $f['lon'], $f['cat_id'], $f['pts'] ? json_encode($f['pts']) : null, $f['line'], $f['sort']]);
  foreach ($js as $j) {
    $db->prepare("INSERT INTO ex_injects(plan_id,kind,label,name,descr,lat,lon,ago,auto,msg,role_id,abbr,color) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute([$nid, $j['kind'], $j['label'], $j['name'], $j['descr'], $j['lat'], $j['lon'], $j['ago'], $j['auto'], $j['msg'], $j['role_id'], $j['abbr'], $j['color']]);
    $map[$j['id']] = (int)$db->lastInsertId();
  }
  foreach ($js as $j) if ($j['ref_id'] !== null && isset($map[$j['ref_id']])) // Verweise erst danach, sie können auch auf spätere Elemente zeigen
    $db->prepare("UPDATE ex_injects SET ref_id=? WHERE id=?")->execute([$map[$j['ref_id']], $map[$j['id']]]);
  out(['ok' => true, 'id' => $nid]);
 case 'ex/plan/reset': plannerOnly(); // beendete Übung zurücksetzen: wieder vorbereitet, alle Elemente nicht freigegeben; die bisherige Durchführung bleibt mit Log und Replay erhalten
  $s = $db->prepare("SELECT p.*,i.ended_at FROM ex_plans p LEFT JOIN incidents i ON i.id=p.incident_id WHERE p.id=? AND p.active=1"); $s->execute([(int)($in['id'] ?? 0)]); $p = $s->fetch() ?: fail('Übung nicht gefunden', 404);
  if ($p['incident_id'] === null) fail('Diese Übung ist noch nicht durchgeführt');
  if ($p['ended_at'] === null) fail('Die Übung läuft noch. Zuerst in der Einsatz-App beenden.');
  $db->beginTransaction();
  $db->prepare("UPDATE ex_plans SET incident_id=NULL WHERE id=?")->execute([$p['id']]);
  $db->prepare("UPDATE ex_injects SET released=NULL, released_by=NULL, rescuee_id=NULL, ext_id=NULL WHERE plan_id=?")->execute([$p['id']]);
  // der öffentliche Link gilt wieder für die nächste Durchführung (Warteseite), nicht mehr für die beendete
  if ($p['public_token'] !== null) $db->prepare("UPDATE incidents SET public_token=NULL WHERE id=?")->execute([$p['incident_id']]);
  $db->commit();
  logev((int)$p['incident_id'], $uid, 'ex', "setzt die vorbereitete Übung «{$p['title']}» für eine neue Durchführung zurück");
  out(['ok' => true]);
 case 'ex/plan/remove': plannerOnly(); // entfernen (nicht während sie läuft); gestartete bleiben im Einsatzlog erhalten
  $s = $db->prepare("SELECT p.*,i.ended_at FROM ex_plans p LEFT JOIN incidents i ON i.id=p.incident_id WHERE p.id=? AND p.active=1"); $s->execute([(int)($in['id'] ?? 0)]); $p = $s->fetch() ?: fail('Übung nicht gefunden', 404);
  if ($p['incident_id'] !== null && $p['ended_at'] === null) fail('Die Übung läuft noch');
  $db->prepare("UPDATE ex_plans SET active=0, public_token=CASE WHEN incident_id IS NULL THEN NULL ELSE public_token END WHERE id=?")->execute([$p['id']]);
  out(['ok' => true]);
 case 'ex/inject/save': plannerOnly(); // Element anlegen oder ändern (nur nicht freigegebene): plan_id, kind, label, lat, lon, ago, auto, msg; res: name, descr; seen: ref_id ('new' mit name/descr, 'unk' mit descr oder Element-ID); ext: name, descr, role_id, abbr, color; extdel: ref_id (ext-Element, ohne lat/lon)
  $s = $db->prepare("SELECT p.*,i.ended_at FROM ex_plans p LEFT JOIN incidents i ON i.id=p.incident_id WHERE p.id=? AND p.active=1"); $s->execute([(int)($in['plan_id'] ?? 0)]); $p = $s->fetch() ?: fail('Übung nicht gefunden', 404);
  if ($p['ended_at'] !== null) fail('Die Übung ist beendet');
  $kind = (string)($in['kind'] ?? ''); if (!isset(EX_KINDS[$kind])) fail('Unbekannte Art');
  $label = mb_substr(trim((string)($in['label'] ?? '')), 0, 120); if ($label === '') fail('Bezeichnung fehlt');
  $xk = $kind === 'ext' || $kind === 'extdel'; $ref = null;
  if ($kind === 'extdel') { // Standort wie die externe Einsatzkraft, die entfernt wird
    $s = $db->prepare("SELECT id,lat,lon FROM ex_injects WHERE id=? AND plan_id=? AND removed=0 AND kind='ext'"); $s->execute([(int)($in['ref_id'] ?? 0), $p['id']]); $x = $s->fetch() ?: fail('Externe Einsatzkraft dieser Übung wählen');
    $ref = (int)$x['id']; $la = (float)$x['lat']; $lo = (float)$x['lon'];
  } else [$la, $lo] = llIn($in['lat'] ?? null, $in['lon'] ?? null);
  $ago = $xk ? 0 : (($in['ago'] ?? null) === null || $in['ago'] === '' ? null : (int)$in['ago']); // NULL = die Übungsleitung gibt den Zeitpunkt bei der Freigabe ein; externe ohne PLS
  if ($ago !== null && ($ago < 0 || $ago > 1440)) fail('Zeitpunkt: 0 bis 1440 Minuten vor der Freigabe');
  // seen: ref_id = 'new' (neuer Rettling mit Name), 'unk' (unbekannter Rettling) oder ein Element, das einen Rettling erfasst (res oder seen ohne ref_id)
  $sm = $kind === 'seen' ? (string)($in['ref_id'] ?? '') : ''; $own = $kind === 'res' || $sm === 'new' || $sm === 'unk';
  $name = $kind === 'res' || $sm === 'new' || $kind === 'ext' ? mb_substr(trim((string)($in['name'] ?? '')), 0, 80) : null; if ($name === '') fail($kind === 'ext' ? 'Name fehlt' : 'Name des Rettlings fehlt');
  $descr = $own || $kind === 'ext' ? mb_substr(trim((string)($in['descr'] ?? '')), 0, $kind === 'ext' ? 500 : 1000) : null;
  $rid = $kind === 'ext' ? roleIn($in['role_id'] ?? null) : null; [$ab, $co] = $kind === 'ext' ? extStyle($in) : [null, null];
  if ($kind === 'seen' && !$own) { $s = $db->prepare("SELECT id FROM ex_injects WHERE id=? AND id<>? AND plan_id=? AND removed=0 AND (kind='res' OR (kind='seen' AND ref_id IS NULL))"); $s->execute([(int)$sm, (int)($in['id'] ?? 0), $p['id']]); $ref = (int)(($s->fetch() ?: [])['id'] ?? 0) ?: fail('Rettling wählen: neu, unbekannt oder ein Rettling dieser Übung'); }
  $auto = ($in['auto'] ?? null) === null || $in['auto'] === '' ? null : (int)$in['auto']; // NULL = nur von Hand freigeben
  if ($auto !== null && ($auto < 0 || $auto > 1440)) fail('Automatische Freigabe: 0 bis 1440 Minuten nach dem Start');
  $msg = mb_substr(trim((string)($in['msg'] ?? '')), 0, 2000);
  $v = [$kind, $label, $name, $descr, $la, $lo, $ago, $auto, $msg === '' ? null : $msg, $ref, $rid, $ab, $co];
  // Elemente, auf die andere verweisen (Rettling: res, seen ohne ref_id; externe Einsatzkraft: ext), dürfen das nicht verlieren
  $prov = fn($k, $refNull) => $k === 'ext' ? 'ext' : ($k === 'res' || ($k === 'seen' && $refNull) ? 'res' : null);
  if (!empty($in['id'])) {
    $s = $db->prepare("SELECT * FROM ex_injects WHERE id=? AND plan_id=? AND removed=0"); $s->execute([(int)$in['id'], $p['id']]); $j = $s->fetch() ?: fail('Element nicht gefunden', 404);
    if ($j['released'] !== null) fail('Freigegebene Elemente lassen sich nicht mehr ändern');
    if ($prov($j['kind'], $j['ref_id'] === null) !== $prov($kind, $ref === null)) { $s = $db->prepare("SELECT COUNT(*) c FROM ex_injects WHERE ref_id=? AND removed=0"); $s->execute([$j['id']]); if ((int)$s->fetch()['c']) fail('Auf dieses Element verweisen andere Elemente; zuerst diese ändern'); }
    $db->prepare("UPDATE ex_injects SET kind=?, label=?, name=?, descr=?, lat=?, lon=?, ago=?, auto=?, msg=?, ref_id=?, role_id=?, abbr=?, color=? WHERE id=?")->execute([...$v, $j['id']]);
    if ($kind === 'ext') $db->prepare("UPDATE ex_injects SET lat=?, lon=? WHERE ref_id=? AND kind='extdel'")->execute([$la, $lo, $j['id']]); // Entfernen am selben Ort
    out(['ok' => true]);
  }
  $db->prepare("INSERT INTO ex_injects(kind,label,name,descr,lat,lon,ago,auto,msg,ref_id,role_id,abbr,color,plan_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute([...$v, $p['id']]);
  out(['ok' => true, 'id' => (int)$db->lastInsertId()]); // id: Übernehmen von Vorschlägen des AI-Assistenten verweist darauf
 case 'ex/inject/remove': plannerOnly(); // nur nicht freigegebene
  $s = $db->prepare("SELECT * FROM ex_injects WHERE id=? AND removed=0"); $s->execute([(int)($in['id'] ?? 0)]); $j = $s->fetch() ?: fail('Element nicht gefunden', 404);
  if ($j['released'] !== null) fail('Freigegebene Elemente lassen sich nicht entfernen');
  $s = $db->prepare("SELECT COUNT(*) c FROM ex_injects WHERE ref_id=? AND removed=0"); $s->execute([$j['id']]); if ((int)$s->fetch()['c']) fail('Auf dieses Element verweisen andere Elemente; zuerst diese entfernen');
  $db->prepare("UPDATE ex_injects SET removed=1 WHERE id=?")->execute([$j['id']]);
  out(['ok' => true]);

 case 'incident/log': $v = incView((int)($_GET['id'] ?? 0)); if ($v && !myBtns()['log']) fail('Das Einsatzlog ist für Einsatzkräfte ausgeschaltet', 403); // anon=1: anonymisierte Fassung (Issue #54); Teilnehmende (Issue #61) behalten den eigenen Namen, bei my_runs anon immer anonym
  $inc = incById($_GET['id'] ?? 0); out(logRows((int)$inc['id'], true, $v === 2 || !empty($_GET['anon']) || (int)$inc['view_anon'] || ($v && anonAny($inc)), $v ? $uid : null)); // view_anon: Anzeige anonymisiert (Issue #87)

 case 'incident/replay': $v = incView((int)($_GET['id'] ?? 0)); if ($v && !myBtns()['replay']) fail('Das Replay ist für Einsatzkräfte ausgeschaltet', 403); $self = $v ? $uid : null; // alle Daten eines aufgezeichneten Einsatzes für das Replay in der Verwaltung; anon=1: ohne Personendaten (Issue #54)
  $inc = incById($_GET['id'] ?? 0);
  if (!(int)$inc['recorded']) fail('Für diesen Einsatz gibt es keine Aufzeichnung');
  $id = (int)$inc['id']; $q = function ($sql) use ($db, $id) { $s = $db->prepare($sql); $s->execute([$id]); return $s->fetchAll(); };
  // endgültig anonymisiertes Replay (rp_anon): Einsatzkräfte ohne Verbindung zu Konten, Standortverläufe mit negativer ID; immer anonym
  $rpa = $inc['rp_anon'] !== null; $va = (int)$inc['view_anon']; $fa = $v && anonAny($inc); $anon = $rpa || $va || $fa || $v === 2 || !empty($_GET['anon']); $c = $anon ? anonCtx($id, $self) : null;
  $parts = $rpa ? (json_decode($inc['rp_anon'], true) ?: []) : replayParts($id);
  if ($anon && !$rpa) foreach ($parts as &$p) $p['name'] = $c['u'][$p['user_id']] ?? 'Einsatzkraft';
  unset($p);
  // Teilnehmende (Issue #61): eigene Person markiert (me); anonym ohne Konto-IDs der anderen (Spuren mit negativer ID wie bei rp_anon)
  $uidMap = [];
  if ($self !== null && !$rpa) foreach ($parts as $i => &$p) { if ($p['user_id'] === $self) $p['me'] = 1; elseif ($anon) { $uidMap[$p['user_id']] = -($i + 1); $p['user_id'] = -($i + 1); } }
  unset($p);
  // Verlauf kompakt als [id, ts, lat, lon]
  $tr = ['u' => [], 'r' => []];
  foreach ($q("SELECT user_id,rescuee_id,ts,lat,lon FROM track WHERE incident_id=? ORDER BY ts,id") as $r)
    $r['user_id'] !== null ? $tr['u'][] = [$uidMap[(int)$r['user_id']] ?? (int)$r['user_id'], (int)$r['ts'], (float)$r['lat'], (float)$r['lon']] : $tr['r'][] = [(int)$r['rescuee_id'], (int)$r['ts'], $r['lat'] === null ? null : (float)$r['lat'], $r['lon'] === null ? null : (float)$r['lon']]; // ohne lat/lon = Fundort zurückgesetzt
  $pois = array_map(fn($r) => ['pts' => json_decode($r['pts'], true)] + ints($r, ['id', 'created', 'removed']), $q("SELECT id,kind,name,color,pts,created,removed FROM pois WHERE incident_id=? ORDER BY id"));
  $res = array_map(fn($r) => floats(ints($r, ['id', 'created', 'removed', 'loc_at', 'rescued_at', 'pls_at']), ['lat', 'lon', 'pls_lat', 'pls_lon']), $q("SELECT id,name,descr,lat,lon,loc_at,rescued_at,created,removed,pls_lat,pls_lon,pls_at FROM rescuees WHERE incident_id=? ORDER BY id"));
  $marks = array_map(fn($r) => floats(ints($r, ['id', 'created', 'unmarked', 'mark', 'uid']), ['mark_lat', 'mark_lon']), $q("SELECT m.id,m.text,m.created,m.mark,m.unmarked,m.mark_lat,m.mark_lon,m.from_user uid,COALESCE(u.name,m.from_label) name FROM messages m LEFT JOIN users u ON u.id=m.from_user WHERE m.incident_id=? AND m.mark_lat IS NOT NULL ORDER BY m.id"));
  $flow = array_map(fn($r) => ints($r, ['ts']), $q("SELECT ts,text FROM incident_log WHERE incident_id=? AND kind='flow' ORDER BY ts,id"));
  // externe Einsatzkräfte: Standort und Spezialrolle mit Stand Einsatzende
  $ext = array_map(fn($r) => floats(ints($r, ['id', 'role_id', 'created', 'removed']), ['lat', 'lon']), $q("SELECT id,name,descr,role_id,abbr,color,lat,lon,created,removed FROM externals WHERE incident_id=? ORDER BY id"));
  if ($anon) { // Rettlinge und Externe nummeriert, ohne Beschreibung; Nachrichten-Markierungen mit Bezeichnung der sendenden Person
    foreach ($res as &$r) { $r['name'] = $c['r'][$r['id']] ?? 'Rettling'; $r['descr'] = null; }
    unset($r);
    foreach ($ext as &$r) { $r['name'] = $c['e'][$r['id']] ?? 'Externe Einsatzkraft'; $r['descr'] = null; }
    unset($r);
    foreach ($marks as &$r) { if (isset($r['uid'])) $r['name'] = $c['u'][$r['uid']] ?? 'Person'; $r['text'] = anonText($r['text'], $c); }
    unset($r);
    $inc['message'] = anonText($inc['message'], $c);
  }
  foreach ($marks as &$r) unset($r['uid']);
  unset($r);
  $per = array_map(fn($r) => ints($r, ['start', 'stop']), $q("SELECT start,stop FROM rec_periods WHERE incident_id=? ORDER BY start,id"));
  $inc = kindAdd(floats(ints($inc, ['id', 'alarm_at', 'ended_at', 'created_by', 'is_exercise', 'record', 'recorded', 'log_anon']), ['lat', 'lon', 'flow_ms'])); $inc['pls_at'] = plsAt($inc);
  unset($inc['public_token']); $inc['rp_anon'] = $rpa ? 1 : 0; if ($anon && $v) $inc['created_by'] = null;
  out(['incident' => $inc, 'anon' => $anon ? 1 : 0, 'forced' => $v === 2 || $va || $fa ? 1 : 0, 'view_anon' => $va, 'periods' => $per, 'participants' => $parts, 'track' => $tr['u'], 'rtrack' => $tr['r'], 'pois' => $pois, 'rescuees' => $res, 'externals' => $ext, 'marks' => $marks, 'flow' => $flow, 'air' => airReplay($id), 'log' => $v && !myBtns()['log'] ? array_values(array_filter(logRows($id, true, $anon, $self), fn($l) => ($l['kind'] ?? '') === 'pls')) : logRows($id, true, $anon, $self), 'now' => $now]); // air: Helikopter (Issue #73)

 // Endgültig anonymisieren (Issue #54, nur Verwaltung, nur beendete Einsätze, nicht umkehrbar). Zwei getrennte Funktionen:
 // Replay: Einsatzkräfte im Replay ohne Verbindung zu den Konten (Bezeichnung, Einrückoption, Spezialrolle in rp_anon), Standortverläufe mit negativer ID,
 //   letzte Standorte der Teilnahmen gelöscht; das Replay zeigt danach auch Rettlinge, Externe und Log nur noch anonym. Einsatzprotokoll und Statistik bleiben (Statistik pro Person weiter personenbezogen, psRows).
 // Einsatzprotokoll: Log-Einträge und Nachrichten ohne Konto (Bezeichnung in actor, from_label, to_label), Namen im Text ersetzt, ebenso in der Alarmmeldung.
 case 'incident/anon/replay': need('anon'); // einzelne Stufen wie bisher (Issue #54); allgemein: anon/apply
  $inc = incById($in['id'] ?? 0);
  if ($inc['ended_at'] === null) fail('Nur beendete Einsätze lassen sich anonymisieren');
  if (!(int)$inc['recorded']) fail('Für diesen Einsatz gibt es keine Aufzeichnung');
  if (!anonDo((int)$inc['id'], 'replay', '(Verwaltung)')) fail('Das Replay ist bereits anonymisiert');
  out(['ok' => true]);
 case 'incident/anon/log': need('anon');
  $inc = incById($in['id'] ?? 0);
  if ($inc['ended_at'] === null) fail('Nur beendete Einsätze lassen sich anonymisieren');
  if (!anonDo((int)$inc['id'], 'log', '(Verwaltung)')) fail('Das Einsatzprotokoll ist bereits anonymisiert');
  out(['ok' => true]);
 /* ---------- Anonymisierung einstellen und anwenden (Issue #87) ---------- */
 case 'anon/cfg': need('anon'); // Stufen, Einstellung und alle beendeten Einsätze mit ihrem Stand (für die Massenanonymisierung)
  $rows = array_map(fn($r) => kindAdd(ints($r, ['id', 'alarm_at', 'ended_at', 'recorded', 'rp_anon', 'log_anon', 'view_anon', 'txt_anon', 'part_anon', 'accepted', 'rec_del'])), $db->query(
    "SELECT i.id,i.message,i.alarm_at,i.ended_at,i.kind_id,i.recorded,CASE WHEN i.rp_anon IS NULL THEN 0 ELSE 1 END rp_anon,i.log_anon,i.view_anon,i.txt_anon,i.part_anon,
      (SELECT COUNT(*) FROM participants p WHERE p.incident_id=i.id AND p.mode<>'declined') accepted,
      (SELECT COUNT(*) FROM incident_log l WHERE l.incident_id=i.id AND (l.kind='anon' AND l.text LIKE 'Aufzeichnung gelöscht%' OR l.kind='rec' AND l.text LIKE 'löscht die Aufzeichnung%')) rec_del FROM incidents i WHERE i.ended_at IS NOT NULL ORDER BY i.alarm_at DESC, i.id DESC")->fetchAll()); // rec_del: Aufzeichnung gelöscht, sonst nicht von «nie aufgezeichnet» zu unterscheiden; aus dem Log, gilt also auch für bereits gelöschte
  $kl = array_values(array_map(fn($k) => ['id' => $k['id'], 'name' => $k['name'], 'color' => $k['color']], array_filter(kinds(), fn($k) => !$k['deleted'])));
  out(['kinds' => $kl, 'steps' => array_map(function ($k, $v) { $def = []; foreach (kinds() as $id => $kd) if (!$kd['deleted'] && ($x = anonDef($k, $kd)) !== null) $def[$id] = $x; return ['key' => $k, 'name' => $v[0], 'descr' => $v[1], 'final' => $v[2], 'def' => (object)$def]; }, array_keys(ANON_STEPS), ANON_STEPS), 'cfg' => anonCfg(), 'incidents' => $rows]);
 case 'anon/cfg/save': need('anon'); // cfg: {Stufe: {on, days: {Einsatztyp: Tage}}}, Tage leer = nie; gelöschte Typen behalten ihre Frist
  $c = is_array($in['cfg'] ?? null) ? $in['cfg'] : fail('Ungültige Einstellung'); $r = []; $oc = anonCfg();
  foreach (ANON_STEPS as $k => $_) { $x = is_array($c[$k] ?? null) ? $c[$k] : []; $days = [];
    foreach (kinds() as $id => $kd) { if (!array_key_exists($id, (array)($x['days'] ?? []))) { $days[$id] = $oc[$k]['days'][$id] ?? null; continue; }
      $v = $x['days'][$id]; if ($v !== null && $v !== '' && (!is_numeric($v) || (int)$v < 0 || (int)$v > 36500)) fail('Tage: 0 bis 36500 oder leer (nie)'); $days[$id] = $v === null || $v === '' ? null : (int)$v; }
    $r[$k] = ['on' => empty($x['on']) ? 0 : 1, 'days' => $days]; }
  setSetting('anon_cfg', json_encode($r)); setSetting('anon_auto_at', '0'); out(['cfg' => anonCfg()]);
 case 'anon/apply': need('anon'); // ids = Einsätze (beendete, höchstens 1000), steps = Stufen; je Einsatz in der Reihenfolge der Stufen, schon Erledigtes wird übersprungen
  $ids = array_values(array_unique(array_map('intval', is_array($in['ids'] ?? null) ? $in['ids'] : [])));
  $steps = array_values(array_intersect(array_keys(ANON_STEPS), is_array($in['steps'] ?? null) ? $in['steps'] : []));
  if (!$ids) fail('Keine Einsätze gewählt'); if (count($ids) > 1000) fail('Höchstens 1000 Einsätze auf einmal');
  if (!$steps) fail('Bitte mindestens eine Stufe wählen');
  $cfg = anonCfg(); foreach ($steps as $k) if (!$cfg[$k]['on']) fail('Die Stufe «' . ANON_STEPS[$k][0] . '» ist ausgeschaltet');
  @set_time_limit(300); $done = array_fill_keys($steps, 0); $n = 0;
  foreach ($ids as $id) { $any = false; foreach ($steps as $k) if (anonDo($id, $k, '(Verwaltung)')) { $done[$k]++; $any = true; } if ($any) $n++; }
  out(['done' => $done, 'incidents' => $n]);
 case 'anon/undo': need('anon'); // nur die umkehrbare Stufe «Anzeige anonymisieren» aufheben
  $ids = array_values(array_unique(array_map('intval', is_array($in['ids'] ?? null) ? $in['ids'] : []))); if (!$ids) fail('Keine Einsätze gewählt');
  $up = $db->prepare("UPDATE incidents SET view_anon=0 WHERE id=? AND view_anon=1"); $n = 0;
  foreach ($ids as $id) { $up->execute([$id]); if ($up->rowCount()) { logev($id, null, 'anon', 'Anonymisierte Anzeige aufgehoben (Verwaltung)'); $n++; } }
  out(['incidents' => $n]);

 case 'incident/replay/delete': need('anon'); // Aufzeichnung eines Einsatzes löschen (Standortverläufe und Zeiträume); Log, Nachrichten usw. bleiben
  $s = $db->prepare("SELECT * FROM incidents WHERE id=?"); $s->execute([(int)($in['id'] ?? 0)]); $inc = $s->fetch();
  if (!$inc) fail('Einsatz nicht gefunden', 404);
  if (!(int)$inc['recorded']) fail('Für diesen Einsatz gibt es keine Aufzeichnung');
  foreach (['track', 'rec_periods', 'air_track'] as $t) $db->prepare("DELETE FROM $t WHERE incident_id=?")->execute([$inc['id']]);
  $db->prepare("UPDATE incidents SET record=0, recorded=0, rp_anon=NULL WHERE id=?")->execute([$inc['id']]); // läuft der Einsatz noch, endet auch die Aufzeichnung
  logev($inc['id'], $uid, 'rec', 'löscht die Aufzeichnung' . ($inc['ended_at'] === null ? ' (Aufzeichnung beendet)' : ''));
  out(['ok' => true]);

 case 'my/incidents': // Meine Einsätze (Issue #61): beendete Einsätze, an denen die Person teilgenommen hat (nicht abgelehnt), für Log und Replay
  if (myRuns() === 'off') fail('Die Einsicht in eigene Einsätze ist ausgeschaltet', 403);
  $s = $db->prepare("SELECT i.id,i.message,i.alarm_at,i.ended_at,i.kind_id,i.recorded,CASE WHEN i.rp_anon IS NULL THEN 0 ELSE 1 END rp_anon,i.log_anon,i.view_anon,i.txt_anon,m.name mode_name,(SELECT a.rel_at FROM aar a WHERE a.incident_id=i.id AND a.status=1 AND NOT EXISTS (SELECT 1 FROM aar_arch r WHERE r.incident_id=i.id)) aar_rel,(SELECT COUNT(*) FROM aar_arch r WHERE r.incident_id=i.id) aar_arch,(SELECT a.rel_at FROM aar a WHERE a.incident_id=i.id AND a.status=1) aar_at
    FROM participants p JOIN incidents i ON i.id=p.incident_id LEFT JOIN modes m ON m.code=p.mode WHERE p.user_id=? AND p.mode<>'declined' AND i.ended_at IS NOT NULL ORDER BY i.alarm_at DESC, i.id DESC");
  $s->execute([$uid]); $rows = array_map(fn($r) => kindAdd(ints($r, ['id', 'alarm_at', 'ended_at', 'recorded', 'rp_anon', 'log_anon', 'view_anon', 'txt_anon', 'aar_rel', 'aar_arch', 'aar_at'])), $s->fetchAll()); // aar_rel: Review verfügbar (Zeitpunkt der Freigabe, Issue #55); aar_at: freigegeben, auch wenn gesichert
  if (myRuns() === 'anon') foreach ($rows as &$r) $r['message'] = anonText($r['message'], anonCtx($r['id'], $uid)); // Alarmmeldung wie im Log
  unset($r);
  if (!myAar()) foreach ($rows as &$r) { $r['aar_rel'] = null; if (empty($r['aar_arch']) || !can('aar_arch')) $r['aar_at'] = null; }
  unset($r);
  if (!can('aar_arch')) foreach ($rows as &$r) unset($r['aar_arch']); // das gesicherte PDF nur mit dem Recht, unabhängig von my_btns
  unset($r);
  out(['mode' => myRuns(), 'btns' => myBtns(), 'incidents' => aiRows($rows)]); // ai: Modi des AI-Assistenten je Einsatz (Issue #111)
 case 'my/stats': // Meine Statistik (Issue #87): nur die eigenen Teilnahmen, für alle Angemeldeten, auch aus endgültig anonymisierten Einsätzen
  out(['rows' => psRows($uid)]);
 case 'stats': need('stats', 'aar', 'aar_rel', 'aar_arch'); // mit nur aar (After Action Review, Issue #55) ohne Log und Replay, die prüfen incView
  $rows = array_map(fn($r) => kindAdd(ints($r, ['id', 'alarm_at', 'ended_at', 'created_by', 'is_exercise', 'record', 'recorded', 'accepted', 'declined', 'first_accept', 'first_area', 'rp_anon', 'log_anon', 'view_anon', 'txt_anon', 'part_anon', 'aar_at', 'aar_st', 'aar_arch', 'aar_lock'])), $db->query(
   "SELECT i.id,i.message,i.alarm_at,i.ended_at,i.created_by,i.is_exercise,i.kind_id,i.invite,i.inv_groups,i.record,i.recorded,CASE WHEN i.rp_anon IS NULL THEN 0 ELSE 1 END rp_anon,i.log_anon,i.view_anon,i.txt_anon,i.part_anon,u.name creator,(SELECT a.updated FROM aar a WHERE a.incident_id=i.id) aar_at,(SELECT a.status FROM aar a WHERE a.incident_id=i.id) aar_st,(SELECT COUNT(*) FROM aar_arch r WHERE r.incident_id=i.id) aar_arch,CASE WHEN " . AAR_LOCK . " THEN 1 ELSE 0 END aar_lock,
     (SELECT COUNT(*) FROM participants p WHERE p.incident_id=i.id AND p.mode<>'declined') accepted,
     (SELECT COUNT(*) FROM participants p WHERE p.incident_id=i.id AND p.mode='declined') declined,
     (SELECT MIN(ts) FROM incident_log l WHERE l.incident_id=i.id AND l.kind='accept') first_accept,
     (SELECT MIN(ts) FROM incident_log l WHERE l.incident_id=i.id AND l.kind='area') first_area
    FROM incidents i LEFT JOIN users u ON u.id=i.created_by ORDER BY i.id DESC")->fetchAll());
  foreach ($rows as &$r) { $r['aufgebot'] = invInfo($r); unset($r['invite'], $r['inv_groups']); } unset($r); // Issue #90
  foreach ($rows as &$r) { $r['aar_seal'] = $r['aar_arch']; if (!can('aar_arch')) unset($r['aar_arch']); } unset($r); // aar_seal: Review nur noch als PDF, sehen alle; das PDF selbst nur mit dem Recht
  $ended = array_filter($rows, fn($r) => $r['ended_at']);
  out(['incidents' => aiRows($rows), 'totals' => [
    'count' => count($rows), 'ended' => count($ended), 'exercises' => count(array_filter($rows, fn($r) => $r['is_exercise'])),
    'avg_duration' => avg(array_map(fn($r) => $r['ended_at'] - $r['alarm_at'], $ended)),
    'avg_accepted' => avg(array_column($rows, 'accepted')),
    'avg_first_accept' => avg(array_map(fn($r) => $r['first_accept'] === null ? null : $r['first_accept'] - $r['alarm_at'], $rows)),
    'avg_first_area' => avg(array_map(fn($r) => $r['first_area'] === null ? null : $r['first_area'] - $r['alarm_at'], $rows))]]);

 case 'stats/people': need('stats'); // Statistik pro Person (Issue #87): eine Zeile je Teilnahme, ausgewertet (Zeitraum, Einsatz, Art) im Frontend
  out(['rows' => psRows()]);

 /* ---------- After Action Review (Issue #55) ---------- */
 case 'aar/cfg': if (myRuns() === 'off') need('aar', 'aar_rel', 'aar_cfg'); // mit Meine Einsätze für alle (Ansicht freigegebener Reviews)
  out(['cfg' => aarCfg(), 'logo' => setting('aar_logo', '') ?: null, 'types' => AAR_TYPES, 'auto' => AAR_AUTO, 'dls' => AAR_DL, 'dlst' => AAR_DL_ST]);
 case 'aar/cfg/save': need('aar_cfg'); setSetting('aar_cfg', json_encode(aarIn($in), JSON_UNESCAPED_UNICODE)); out(['cfg' => aarCfg()]);
 case 'aar/logo': need('aar_cfg'); // PNG oder JPEG als Data-URL (der Browser verkleinert vorher), null = entfernen
  $l = $in['logo'] ?? null;
  if ($l !== null) {
    if (!is_string($l) || !preg_match('~^data:image/(png|jpeg);base64,([A-Za-z0-9+/=]+)$~', $l, $m)) fail('Nur PNG- oder JPEG-Bilder');
    $b = base64_decode($m[2], true);
    if ($b === false || strlen($b) > 1500000) fail('Das Bild ist zu gross (höchstens 1.5 MB)');
    if (!@getimagesizefromstring($b)) fail('Die Datei ist kein gültiges Bild');
  }
  setSetting('aar_logo', (string)$l); out(['ok' => true]);
 case 'aar/get': // Daten fürs Dokument; anon=1 oder endgültig anonymisiert: Bezeichnungen wie Issue #54 (die erfassten Angaben bleiben, wie sie geschrieben wurden)
  // my=1 (Meine Einsätze): nur freigegebene Reviews eigener Einsätze, Namen gemäss my_runs (anon: alle anderen anonym, die Person selbst mit Namen)
  $inc = incById($_GET['id'] ?? 0); $id = (int)$inc['id']; $mine = aarView($id);
  $fin = (int)$inc['log_anon'] || $inc['rp_anon'] !== null; $forced = $fin || (int)$inc['view_anon']; $self = $mine && !$fin ? $uid : null; // Anzeige anonymisiert: die Person selbst behält den Namen
  $anon = $forced || !empty($_GET['anon']) || $mine && myRuns() === 'anon'; $c = $anon ? anonCtx($id, $self) : null;
  out(aarDoc($inc, $c, $anon, $forced, $mine, $self) + ['ai' => $mine ? null : aiInc($inc)]); // ai: Modi des AI-Assistenten (nur Verwaltung)
 /* ---------- Vor dem Anonymisieren gesicherte Reviews, alles nur mit Recht aar_arch; das fertige PDF lässt sich nicht mehr ändern ---------- */
 case 'aar/arch/pending': need('aar_arch'); // Sicherungen, aus denen der Browser noch das PDF erzeugen muss
  out(['ids' => array_map('intval', $db->query("SELECT incident_id FROM aar_arch WHERE pdf_at IS NULL AND snap IS NOT NULL ORDER BY incident_id")->fetchAll(PDO::FETCH_COLUMN))]);
 case 'aar/arch/snap': need('aar_arch'); $r = aarArchRow($_GET['id'] ?? 0); if ($r['pdf_at'] !== null) fail('Das PDF liegt bereits vor');
  $s = $db->prepare("SELECT snap FROM aar_arch WHERE incident_id=?"); $s->execute([(int)$r['incident_id']]); out(['created' => (int)$r['created'], 'doc' => json_decode($s->fetchColumn(), true)]);
 case 'aar/arch/img': need('aar_arch'); $s = $db->prepare("SELECT data FROM aar_arch_img WHERE incident_id=? AND img_id=?"); $s->execute([(int)($_GET['id'] ?? 0), (int)($_GET['img'] ?? 0)]); $v = $s->fetchColumn(); if ($v === false) fail('Bild nicht gefunden', 404); out(['src' => $v]);
 case 'aar/arch/put': need('aar_arch'); // ein Stück des PDF: id, n, data (Base64)
  $r = aarArchRow($in['id'] ?? 0); if ($r['pdf_at'] !== null) fail('Das gesicherte PDF lässt sich nicht mehr ändern', 409);
  $n = (int)($in['n'] ?? -1); $d = (string)($in['data'] ?? ''); if ($n < 0 || $n >= AAR_ARCH_PARTS || $d === '' || strlen($d) > AAR_ARCH_CHUNK || base64_decode($d, true) === false) fail('Ungültiges Stück');
  $db->prepare("DELETE FROM aar_arch_part WHERE incident_id=? AND n=?")->execute([(int)$r['incident_id'], $n]);
  $db->prepare("INSERT INTO aar_arch_part(incident_id,n,data) VALUES(?,?,?)")->execute([(int)$r['incident_id'], $n, $d]); out(['ok' => true]);
 case 'aar/arch/done': need('aar_arch'); // alle Stücke da: id, parts, sha (SHA-256 des PDF), name; ab hier unveränderlich
  $r = aarArchRow($in['id'] ?? 0); $id = (int)$r['incident_id']; if ($r['pdf_at'] !== null) fail('Das gesicherte PDF lässt sich nicht mehr ändern', 409);
  $parts = (int)($in['parts'] ?? 0); if ($parts < 1 || $parts > AAR_ARCH_PARTS) fail('Ungültige Anzahl Stücke');
  $h = hash_init('sha256'); $s = $db->prepare("SELECT data FROM aar_arch_part WHERE incident_id=? AND n=?");
  for ($n = 0; $n < $parts; $n++) { $s->execute([$id, $n]); $v = $s->fetchColumn(); $s->closeCursor(); if ($v === false) fail('Es fehlen Stücke des PDF'); $v = base64_decode($v); if (!$n && substr($v, 0, 5) !== '%PDF-') fail('Das ist kein PDF'); hash_update($h, $v); }
  $sha = hash_final($h); if (!hash_equals($sha, (string)($in['sha'] ?? ''))) fail('Prüfsumme stimmt nicht');
  $db->beginTransaction();
  $u = $db->prepare("UPDATE aar_arch SET pdf_at=?,pdf_parts=?,pdf_name=?,pdf_sha=?,snap=NULL WHERE incident_id=? AND pdf_at IS NULL"); $u->execute([$now, $parts, mb_substr(basename((string)($in['name'] ?? 'AAR.pdf')), 0, 150), $sha, $id]);
  if (!$u->rowCount()) { $db->rollBack(); fail('Das gesicherte PDF lässt sich nicht mehr ändern', 409); }
  $db->prepare("DELETE FROM aar_arch_part WHERE incident_id=? AND n>=?")->execute([$id, $parts]); $db->prepare("DELETE FROM aar_arch_img WHERE incident_id=?")->execute([$id]);
  $db->commit(); out(['ok' => true]);
 case 'aar/arch/part': need('aar_arch'); // Abrufen: ein Stück mit den Angaben zum PDF; das Dokument setzt der Browser zusammen und prüft die Summe
  $r = aarArchRow($_GET['id'] ?? 0); if ($r['pdf_at'] === null) out(['pending' => true]);
  $s = $db->prepare("SELECT data FROM aar_arch_part WHERE incident_id=? AND n=?"); $s->execute([(int)$r['incident_id'], (int)($_GET['n'] ?? 0)]); $v = $s->fetchColumn(); if ($v === false) fail('Stück nicht gefunden', 404);
  out(['parts' => (int)$r['pdf_parts'], 'name' => $r['pdf_name'], 'sha' => $r['pdf_sha'], 'data' => $v]);
 case 'aar/img': // ein Bild als Data-URL (Zugriff wie aar/get)
  $r = aarImgRow($_GET['id'] ?? 0); aarView((int)$r['incident_id']);
  $s = $db->prepare("SELECT data FROM aar_img WHERE id=?"); $s->execute([(int)$r['id']]); out(['src' => $s->fetchColumn()]);
 case 'aar/img/add': // id = Einsatz, field = Feld der Art img oder area (im Text über die Marke [Bild <id>] platziert), src = JPEG/PNG als Data-URL, caption optional
  $inc = incById($in['id'] ?? 0); $id = (int)$inc['id']; $ex = aarEditable($inc);
  $fid = (string)($in['field'] ?? ''); if (!array_filter(aarCfg()['fields'], fn($f) => $f['id'] === $fid && ($f['type'] === 'img' || $f['type'] === 'area'))) fail('Unbekanntes Bilderfeld');
  $src = $in['src'] ?? null;
  if (!is_string($src) || !preg_match('~^data:image/(png|jpeg);base64,([A-Za-z0-9+/=]+)$~', $src, $m)) fail('Nur PNG- oder JPEG-Bilder');
  $b = base64_decode($m[2], true); if ($b === false || strlen($b) > 3000000) fail('Das Bild ist zu gross (höchstens 3 MB)');
  $sz = @getimagesizefromstring($b); if (!$sz) fail('Die Datei ist kein gültiges Bild');
  $s = $db->prepare("SELECT COUNT(*) FROM aar_img WHERE incident_id=?"); $s->execute([$id]); if ((int)$s->fetchColumn() >= AAR_IMG_MAX) fail('Höchstens ' . AAR_IMG_MAX . ' Bilder je Review');
  $db->beginTransaction();
  $db->prepare("INSERT INTO aar_img(incident_id,field_id,caption,data,w,h,created,created_by) VALUES(?,?,?,?,?,?,?,?)")->execute([$id, $fid, mb_substr(trim((string)($in['caption'] ?? '')), 0, 300), $src, (int)$sz[0], (int)$sz[1], $now, $uid]);
  $nid = (int)$db->lastInsertId(); aarTouch($id, $ex); $db->commit();
  out(['id' => $nid, 'w' => (int)$sz[0], 'h' => (int)$sz[1]]);
 case 'aar/img/save': // Beschriftung ändern
  $r = aarImgRow($in['img'] ?? 0); $id = (int)$r['incident_id']; $ex = aarEditable(incById($id));
  $db->prepare("UPDATE aar_img SET caption=? WHERE id=?")->execute([mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($in['caption'] ?? ''))), 0, 300), (int)$r['id']]); aarTouch($id, $ex); out(['ok' => true]);
 case 'aar/img/remove':
  $r = aarImgRow($in['img'] ?? 0); $id = (int)$r['incident_id']; $ex = aarEditable(incById($id));
  $db->prepare("DELETE FROM aar_img WHERE id=?")->execute([(int)$r['id']]);
  // auch die Marken in den Texten entfernen, sonst stünde dort nach einem Abbrechen des Formulars «[Bild 12]» als Text
  $s = $db->prepare("SELECT data FROM aar WHERE incident_id=?"); $s->execute([$id]); $d = json_decode((string)$s->fetchColumn(), true); $s->closeCursor();
  if (is_array($d)) { foreach ($d as $k => $v) if (is_string($v)) { $v = preg_replace('/\n?\[Bild ' . (int)$r['id'] . '\]/', '', $v); if (trim($v) === '') unset($d[$k]); else $d[$k] = $v; }
    $db->prepare("UPDATE aar SET data=? WHERE incident_id=?")->execute([json_encode((object)$d, JSON_UNESCAPED_UNICODE), $id]); }
  aarTouch($id, $ex); out(['ok' => true]);
 case 'aar/save': need('aar'); // nur Felder der Einstellungen; Werte entfernter Felder bleiben erhalten
  $inc = incById($in['id'] ?? 0); $id = (int)$inc['id']; aarSealed($id);
  if ($inc['ended_at'] === null) fail('Ein After Action Review gibt es erst nach dem Einsatzende');
  $s = $db->prepare("SELECT data,status FROM aar WHERE incident_id=?"); $s->execute([$id]); $old = $s->fetch(); $s->closeCursor(); $d = $old ? (json_decode((string)$old['data'], true) ?: []) : [];
  if ($old && (int)$old['status'] === 1) fail('Das After Action Review ist freigegeben. Zum Bearbeiten zuerst die Freigabe zurücknehmen.');
  $v = (array)($in['data'] ?? []);
  foreach (aarCfg()['fields'] as $f) if ($f['type'] !== 'head' && $f['type'] !== 'img' && array_key_exists($f['id'], $v)) {
    $x = $v[$f['id']]; if (!is_scalar($x) && $x !== null) fail('Ungültiger Wert');
    $x = mb_substr(str_replace("\r", '', trim((string)$x)), 0, 20000);
    if ($x === '') unset($d[$f['id']]); else $d[$f['id']] = $x;
  }
  // Speichern macht ein als nicht relevant markiertes Review wieder zu einem in Arbeit
  $j = json_encode((object)$d, JSON_UNESCAPED_UNICODE);
  if ($old) $db->prepare("UPDATE aar SET data=?,updated=?,updated_by=?,status=0 WHERE incident_id=?")->execute([$j, $now, $uid, $id]);
  else $db->prepare("INSERT INTO aar(incident_id,data,updated,updated_by,status) VALUES(?,?,?,?,0)")->execute([$id, $j, $now, $uid]);
  out(['ok' => true]);
 case 'aar/status': // 0 = in Arbeit, 1 = freigeben (Recht aar_rel, sperrt das Review), 2 = nicht relevant; Freigabe zurücknehmen ebenfalls nur mit aar_rel
  $inc = incById($in['id'] ?? 0); $id = (int)$inc['id']; $st = (int)($in['status'] ?? -1);
  if (!isset(AAR_STATUS[$st])) fail('Ungültiger Status');
  if ($inc['ended_at'] === null) fail('Ein After Action Review gibt es erst nach dem Einsatzende');
  $s = $db->prepare("SELECT status FROM aar WHERE incident_id=?"); $s->execute([$id]); $cur = $s->fetchColumn(); $s->closeCursor(); $cur = $cur === false ? null : (int)$cur;
  if ($st === 1 || $cur === 1) need('aar_rel'); else need('aar');
  aarSealed($id);
  if ($cur === null) $db->prepare("INSERT INTO aar(incident_id,data,status) VALUES(?,'{}',0)")->execute([$id]);
  $db->prepare("UPDATE aar SET status=?,rel_at=?,rel_by=? WHERE incident_id=?")->execute([$st, $st === 1 ? $now : null, $st === 1 ? $uid : null, $id]);
  out(['ok' => true]);

 /* ---------- Web-Push (Issue #67): Abonnement des Geräts ---------- */
 case 'push/key': if ($sid && !fcmOk()) alog('warn', 'push', 'App fragt nach Push, App-Push ist nicht eingerichtet', ['why' => fcmWhy()]); // App-Sitzung ($sid): sonst bliebe das Log stumm
  out(['enabled' => pushOk(), 'key' => pushOk() ? vapid()['pub'] : null, 'native' => fcmOk() ? 1 : 0]); // öffentlicher VAPID-Schlüssel für pushManager.subscribe; native = FCM eingerichtet (Issue #108)
 case 'push/subscribe': // endpoint, keys: {p256dh, auth}; ein Gerät gehört immer der zuletzt angemeldeten Person
  if (!pushOk()) fail('Push ist auf diesem Server nicht verfügbar (PHP ohne openssl/curl)');
  $ep = (string)($in['endpoint'] ?? ''); $k = $in['keys'] ?? [];
  if (!preg_match('~^https://~', $ep) || strlen($ep) > 1000 || strlen(b64ud((string)($k['p256dh'] ?? ''))) !== 65 || strlen(b64ud((string)($k['auth'] ?? ''))) < 16) fail('Ungültiges Abonnement');
  $db->prepare("DELETE FROM push_subs WHERE endpoint=?")->execute([$ep]);
  $db->prepare("INSERT INTO push_subs(user_id,endpoint,p256dh,auth,created) VALUES(?,?,?,?,?)")->execute([$uid, $ep, $k['p256dh'], $k['auth'], $now]);
  out(['ok' => true]);
 case 'push/unsubscribe': $db->prepare("DELETE FROM push_subs WHERE endpoint=? AND user_id=?")->execute([(string)($in['endpoint'] ?? ''), $uid]); out(['ok' => true]);
 /* ---------- Native Apps (Issue #108): Push über Firebase Cloud Messaging, Gerätesitzungen ---------- */
 case 'push/native/subscribe': // token (FCM-Registrierungstoken), platform android|ios; ein Token gehört immer der zuletzt angemeldeten Person bzw. Sitzung
  if (!fcmOk()) { alog('warn', 'push', 'App wollte sich für Push anmelden, Push ist nicht eingerichtet', ['why' => fcmWhy()]); fail('Push für die App ist auf diesem Server nicht eingerichtet'); }
  $t = trim((string)($in['token'] ?? '')); if ($t === '' || strlen($t) > 4096 || !preg_match('/^[\x21-\x7e]+$/', $t)) fail('Ungültiger Token');
  $pf = in_array($in['platform'] ?? '', ['android', 'ios'], true) ? $in['platform'] : '';
  $db->prepare("DELETE FROM push_native WHERE token=?")->execute([$t]);
  $db->prepare("INSERT INTO push_native(user_id,session_id,token,platform,created) VALUES(?,?,?,?,?)")->execute([$uid, $sid, $t, $pf, $now]);
  alog('info', 'push', 'App für Push angemeldet', ['platform' => $pf ?: null]);
  out(['ok' => true]);
 case 'push/native/unsubscribe': $db->prepare("DELETE FROM push_native WHERE token=? AND user_id=?")->execute([trim((string)($in['token'] ?? '')), $uid]); out(['ok' => true]);
 case 'logout': // eigene Gerätesitzung samt ihren App-Abos löschen, sonst den Browser-Token
  if ($sid) { $db->prepare("DELETE FROM push_native WHERE session_id=?")->execute([$sid]); $db->prepare("DELETE FROM sessions WHERE id=?")->execute([$sid]); }
  else { $db->prepare("UPDATE users SET token=NULL WHERE id=?")->execute([$uid]); $db->prepare("DELETE FROM push_native WHERE user_id=? AND session_id IS NULL")->execute([$uid]); }
  out(['ok' => true]);
 case 'me/sessions': // eigene Gerätesitzungen ohne Token; cur 1 = diese Sitzung
  $s = $db->prepare("SELECT id,device,platform,created,last_seen FROM sessions WHERE user_id=? ORDER BY last_seen DESC, id DESC"); $s->execute([$uid]);
  out(array_map(fn($r) => ints($r, ['id', 'created', 'last_seen']) + ['cur' => (int)$r['id'] === $sid ? 1 : 0], $s->fetchAll()));
 case 'me/session/remove': // id; meldet die App auf diesem Gerät ab (sie erhält beim nächsten Aufruf 401)
  $x = (int)($in['id'] ?? 0); $s = $db->prepare("DELETE FROM sessions WHERE id=? AND user_id=?"); $s->execute([$x, $uid]);
  if (!$s->rowCount()) fail('Sitzung nicht gefunden', 404);
  $db->prepare("DELETE FROM push_native WHERE session_id=?")->execute([$x]); out(['ok' => true]);
 /* ---------- Abwesenheiten (Issue #91): eigene immer, die anderer Personen mit dem Recht avail ---------- */
 case 'absences': // laufende und künftige Einträge: eigene, user=<id> oder all=1 (beides mit avail)
  $all = !empty($_GET['all']); $u = (int)($_GET['user'] ?? 0) ?: $uid; if ($all || $u !== $uid) need('avail');
  $s = $db->prepare("SELECT a.id,a.user_id,a.from_at,a.until_at,a.kind,a.note,a.created_by,a.created_at,u.name,c.name by_name FROM absences a JOIN users u ON u.id=a.user_id LEFT JOIN users c ON c.id=a.created_by
    WHERE a.removed=0 AND (a.until_at IS NULL OR a.until_at>?)" . ($all ? ' AND u.active=1' : ' AND a.user_id=?') . " ORDER BY a.from_at, u.name");
  $s->execute($all ? [$now] : [$now, $u]);
  out(array_map(fn($a) => ints($a, ['id', 'user_id', 'from_at', 'until_at', 'created_by', 'created_at']) + ['now' => (int)$a['from_at'] <= $now ? 1 : 0], $s->fetchAll()));
 case 'absence/save': // id (ändern), user_id (andere nur mit avail), from (leer = jetzt), until (null = bis auf Weiteres), kind abs|sus, note
  $id = (int)($in['id'] ?? 0); $old = null;
  if ($id) { $s = $db->prepare("SELECT * FROM absences WHERE id=? AND removed=0"); $s->execute([$id]); $old = $s->fetch(); if (!$old) fail('Eintrag nicht gefunden', 404); }
  $u = $old ? (int)$old['user_id'] : ((int)($in['user_id'] ?? 0) ?: $uid); if ($u !== $uid) need('avail');
  $s = $db->prepare("SELECT id FROM users WHERE id=? AND active=1"); $s->execute([$u]); if (!$s->fetch()) fail('Unbekannte oder deaktivierte Person');
  $from = !empty($in['from']) ? (int)$in['from'] : $now; if ($from < $now - 60 && !$old) $from = $now;
  $until = isset($in['until']) && $in['until'] !== '' && $in['until'] !== null ? (int)$in['until'] : null;
  if ($until !== null && $until <= max($from, $now)) fail('«Bis» muss nach «Von» und in der Zukunft liegen');
  if ($from > $now + 2 * 366 * 86400) fail('«Von» liegt zu weit in der Zukunft');
  $k = in_array($in['kind'] ?? '', ['abs', 'sus'], true) ? $in['kind'] : 'abs'; $nt = mb_substr(trim((string)($in['note'] ?? '')), 0, 200); $nt = $nt === '' ? null : $nt;
  if ($old) $db->prepare("UPDATE absences SET from_at=?,until_at=?,kind=?,note=? WHERE id=?")->execute([$from, $until, $k, $nt, $id]);
  else { $db->prepare("INSERT INTO absences(user_id,from_at,until_at,kind,note,created_by,created_at) VALUES(?,?,?,?,?,?,?)")->execute([$u, $from, $until, $k, $nt, $uid, $now]); $id = (int)$db->lastInsertId(); }
  out(['ok' => true, 'id' => $id]);
 case 'absence/remove': // id; beendet bzw. löscht den Eintrag
  $s = $db->prepare("SELECT * FROM absences WHERE id=? AND removed=0"); $s->execute([(int)($in['id'] ?? 0)]); $a = $s->fetch(); if (!$a) fail('Eintrag nicht gefunden', 404);
  if ((int)$a['user_id'] !== $uid) need('avail');
  $db->prepare("UPDATE absences SET removed=1 WHERE id=?")->execute([(int)$a['id']]); out(['ok' => true]);
 case 'me/password': // eigenes Passwort ändern (aktuelles Passwort nötig)
  $s = $db->prepare("SELECT pass_hash FROM users WHERE id=?"); $s->execute([$uid]);
  if (!password_verify($in['old'] ?? '', $s->fetch()['pass_hash'])) fail('Das aktuelle Passwort ist falsch', 403);
  pwCheck($in['new'] ?? '');
  $db->prepare("UPDATE users SET pass_hash=?, must_change=0 WHERE id=?")->execute([password_hash($in['new'], PASSWORD_DEFAULT), $uid]);
  // Gerätesitzungen (Issue #108) bleiben bewusst bestehen: Wer das eigene Passwort ändert, soll die Alarmierung über die App nicht verlieren.
  // Abmelden einzelner Apps über «Angemeldete Apps» im Profil (me/session/remove); Zurücksetzen durch die Verwaltung löscht alle.
  out(['ok' => true]);
 case 'me/settings': // nur übergebene Werte ändern. exercise_mode: Übungen wie scharfe Einsätze kritisch alarmieren; coord_sys: '' = Standard der Verwaltung, none = ausblenden
  if (isset($in['exercise_mode'])) $db->prepare("UPDATE users SET exercise_mode=? WHERE id=?")->execute([empty($in['exercise_mode']) ? 0 : 1, $uid]);
  if (isset($in['coord_sys'])) {
    $c = (string)$in['coord_sys']; if ($c !== '' && $c !== 'none' && !in_array($c, COORDS, true)) fail('Unbekanntes Koordinatensystem'); // none = Koordinaten ausblenden (nur persönlich)
    $db->prepare("UPDATE users SET coord_sys=? WHERE id=?")->execute([$c === '' ? null : $c, $uid]);
  }
  if (isset($in['ai_modes'])) { // AI-Assistent (Issue #111): {Funktion: Modus oder '' = höchster erlaubter}; nur bekannte Funktionen und Modi
    $v = []; foreach ((array)$in['ai_modes'] as $f => $m) if (isset(AI_FN[$f]) && is_string($m) && isset(AI_RANK[$m])) $v[$f] = $m;
    $db->prepare("UPDATE users SET ai_modes=? WHERE id=?")->execute([$v ? json_encode($v) : null, $uid]);
  }
  if (isset($in['place_view'])) { // Kategorien fester Standorte: Objekt {Kategorie-ID: 0|1} (ersetzt alles), leer = Standard der Verwaltung
    $v = placeView($in['place_view']); $db->prepare("UPDATE users SET place_view=? WHERE id=?")->execute([$v ? json_encode($v) : null, $uid]);
  }
  if (isset($in['hydro'])) { // persönliche Messstationen (Issue #125): Liste der Stations-IDs in der gewünschten Reihenfolge, höchstens HYDRO_MAX
    $l = hydroLocs(); $v = [];
    foreach ((array)$in['hydro'] as $x) { $x = (string)$x; if (!preg_match(HYDRO_ID, $x) || ($l && !isset($l[$x]))) fail('Unbekannte Messstation'); if (!in_array($x, $v, true)) $v[] = $x; }
    if (count($v) > HYDRO_MAX) fail('Höchstens ' . HYDRO_MAX . ' Messstationen');
    $db->prepare("UPDATE users SET hydro=? WHERE id=?")->execute([$v ? json_encode($v) : null, $uid]);
  }
  if (isset($in['nav_app'])) { // '' = Standard der Verwaltung
    $c = (string)$in['nav_app']; if ($c !== '' && !in_array($c, navApps(), true)) fail('Diese Navigations-App ist nicht verfügbar');
    $db->prepare("UPDATE users SET nav_app=? WHERE id=?")->execute([$c === '' ? null : $c, $uid]);
  }
  out(['ok' => true]);

 /* ---------- Verwaltung (je nach Recht der Benutzerrollen, Issue #63) ---------- */
 case 'users': need('users', 'ex_plan', 'groups', 'avail'); // Übungsleiter (nur ex_plan) nur Name und Benutzername (Auswahl von Übungsleitung und Aufgebotenen)
  if (!can('users')) { $an = can('groups') || can('avail') ? absNow() : []; out(array_map(fn($r) => ints($r, ['id', 'active']) + ['absent' => $an[(int)$r['id']] ?? null], $db->query("SELECT id,username,name,active FROM users ORDER BY active DESC, name")->fetchAll())); } // absent: laufende Abwesenheit (Issue #91), für die Zuteilung der Einsatzgruppen
  $ur = []; foreach ($db->query("SELECT user_id,urole_id FROM user_uroles")->fetchAll() as $x) $ur[(int)$x['user_id']][] = (int)$x['urole_id'];
  $ug = []; foreach ($db->query("SELECT m.user_id,m.group_id FROM inc_group_members m JOIN inc_groups g ON g.id=m.group_id WHERE g.active=1 ORDER BY g.pos, g.name")->fetchAll() as $x) $ug[(int)$x['user_id']][] = (int)$x['group_id']; // Einsatzgruppen (Issue #90)
  $an = absNow(); // laufende Abwesenheit (Issue #91)
  $used = usersUsed(); // deletable = nie angemeldet, in keinen Daten, nicht man selbst (Issue #63)
  out(array_map(fn($r) => ints($r, ['id', 'active', 'exercise_mode', 'must_change', 'last_login']) + ['uroles' => $ur[(int)$r['id']] ?? [], 'groups' => $ug[(int)$r['id']] ?? [], 'absent' => $an[(int)$r['id']] ?? null, 'editable' => ($e = userEditable((int)$r['id'])) ? 1 : 0, 'deletable' => $e && $r['last_login'] === null && !isset($used[(int)$r['id']]) && (int)$r['id'] !== $uid ? 1 : 0], $db->query("SELECT id,username,name,photo,active,exercise_mode,must_change,last_login FROM users ORDER BY active DESC, name")->fetchAll())); // photo: Profilbild für die Benutzerliste (kleine Data-URL)
 case 'user/save': need('users'); // anlegen oder Name/Benutzerrollen ändern: uroles = Liste der Rollen-IDs (nicht übergeben = unverändert)
  $nm = trim($in['name'] ?? ''); $id = (int)($in['id'] ?? 0);
  if ($id) needUserEditable($id);
  $old = $id ? array_column(userRoles($id), 'id') : [];
  $new = isset($in['uroles']) ? array_values(array_unique(array_map('intval', (array)$in['uroles']))) : $old;
  foreach (array_merge(array_diff($new, $old), array_diff($old, $new)) as $rid) { // geänderte Rollen: nur mit eigenen Rechten
    $r = uroleRow($rid); if (!$r) fail('Unbekannte Benutzerrolle');
    if (!permsMine(rolePerms($r))) fail('Die Rolle «' . $r['name'] . '» hat Rechte, die du selbst nicht hast', 403);
    if ($id === $uid && $r['sys'] === 'admin' && !in_array($rid, $new, true)) fail('Die eigene Rolle Systemadministrator kann nicht entfernt werden');
  }
  if ($id) {
    if ($nm === '') fail('Name fehlt');
    $db->prepare("UPDATE users SET name=? WHERE id=?")->execute([$nm, $id]);
  } else {
    $un = trim($in['username'] ?? ''); if ($un === '') fail('Benutzername ist Pflicht');
    pwCheck($in['password'] ?? '');
    try { $db->prepare("INSERT INTO users(username,name,pass_hash,must_change) VALUES(?,?,?,1)")->execute([$un, $nm ?: $un, password_hash($in['password'], PASSWORD_DEFAULT)]); }
    catch (PDOException $e) { fail('Benutzername existiert bereits'); }
    $id = (int)$db->lastInsertId();
  }
  if ($new !== $old || !isset($in['id'])) {
    $db->beginTransaction();
    $db->prepare("DELETE FROM user_uroles WHERE user_id=?")->execute([$id]);
    $s = $db->prepare("INSERT INTO user_uroles(user_id,urole_id) VALUES(?,?)"); foreach ($new as $rid) $s->execute([$id, $rid]);
    if (!adminCount()) { $db->rollBack(); fail('Mindestens eine aktive Person muss die Rolle Systemadministrator behalten'); }
    $db->commit();
  }
  out(['ok' => true, 'id' => $id]);
 case 'user/password': need('users'); // zurücksetzen: Person wird abgemeldet und muss bei der nächsten Anmeldung ein eigenes Passwort setzen
  $id = (int)($in['id'] ?? 0);
  if ($id === $uid) fail('Das eigene Passwort bitte über «Passwort ändern» setzen');
  needUserEditable($id);
  pwCheck($in['password'] ?? '');
  $s = $db->prepare("UPDATE users SET pass_hash=?, must_change=1, token=NULL WHERE id=?"); $s->execute([password_hash($in['password'], PASSWORD_DEFAULT), $id]);
  $db->prepare("DELETE FROM push_subs WHERE user_id=?")->execute([$id]); // abgemeldet: Geräte erhalten keine Push-Nachrichten mehr
  foreach (['sessions', 'push_native'] as $t) $db->prepare("DELETE FROM $t WHERE user_id=?")->execute([$id]); // auch die Apps abmelden (Issue #108)
  if (!$s->rowCount()) fail('Benutzer nicht gefunden', 404);
  out(['ok' => true]);
 case 'user/delete': need('users'); // nur Personen, die sich nie angemeldet haben und in keinen Daten vorkommen; sonst deaktivieren
  $id = (int)($in['id'] ?? 0);
  if ($id === $uid) fail('Das eigene Konto kann nicht gelöscht werden');
  needUserEditable($id);
  $s = $db->prepare("SELECT last_login FROM users WHERE id=?"); $s->execute([$id]); $u = $s->fetch(); $s->closeCursor();
  if (!$u) fail('Benutzer nicht gefunden', 404);
  if ($u['last_login'] !== null || isset(usersUsed()[$id])) fail('Diese Person hat sich schon angemeldet oder kommt in Einsatzdaten vor; bitte deaktivieren');
  $db->beginTransaction();
  foreach (['user_uroles', 'push_subs', 'inc_group_members', 'absences', 'sessions', 'push_native'] as $t) $db->prepare("DELETE FROM $t WHERE user_id=?")->execute([$id]);
  $db->prepare("DELETE FROM users WHERE id=?")->execute([$id]);
  foreach ($db->query("SELECT id,leads,invite FROM ex_plans")->fetchAll() as $p) { // aus Übungsleitung und Aufgebot vorbereiteter Übungen entfernen
    $fx = fn($j) => $j === null ? null : json_encode(array_values(array_filter((array)json_decode($j, true), fn($x) => (int)$x !== $id)));
    if ($fx($p['leads']) !== $p['leads'] || $fx($p['invite']) !== $p['invite']) $db->prepare("UPDATE ex_plans SET leads=?, invite=? WHERE id=?")->execute([$fx($p['leads']), $fx($p['invite']), $p['id']]);
  }
  if (!adminCount()) { $db->rollBack(); fail('Mindestens eine aktive Person muss die Rolle Systemadministrator behalten'); }
  $db->commit();
  out(['ok' => true]);
 case 'user/active': need('users'); // Benutzer entfernen (deaktivieren) oder reaktivieren, Einsatzdaten bleiben erhalten
  $id = (int)($in['id'] ?? 0); $a = empty($in['active']) ? 0 : 1;
  if ($id === $uid) fail('Das eigene Konto kann nicht deaktiviert werden');
  needUserEditable($id);
  $s = $db->prepare("SELECT name FROM users WHERE id=?"); $s->execute([$id]); $u = $s->fetch();
  if (!$u) fail('Benutzer nicht gefunden', 404);
  $db->prepare("UPDATE users SET active=?" . ($a ? '' : ', token=NULL') . " WHERE id=?")->execute([$a, $id]);
  if (!$a) foreach (['push_subs', 'sessions', 'push_native'] as $t) $db->prepare("DELETE FROM $t WHERE user_id=?")->execute([$id]); // keine Push-Nachrichten mehr an deaktivierte Personen, Apps abgemeldet (Issue #108)
  if (!$a && ($inc = activeIncident())) { // aus laufendem Einsatz austragen
    $p = part($inc['id'], $id);
    if ($p && $p['left_at'] === null && $p['mode'] !== 'declined') {
      $db->prepare("UPDATE participants SET left_at=?, role_id=NULL WHERE incident_id=? AND user_id=?")->execute([$now, $inc['id'], $id]);
      logev($inc['id'], $uid, 'kick', 'hat ' . $u['name'] . ' aus dem Einsatz entfernt (Konto deaktiviert)');
    }
  }
  out(['ok' => true]);
 case 'me/photo': // kleines Bild als Data-URL (Client verkleinert auf ca. 96px)
  if (strlen($in['photo'] ?? '') > 200000) fail('Bild zu gross');
  $db->prepare("UPDATE users SET photo=? WHERE id=?")->execute([$in['photo'], $uid]);
  out(['ok' => true]);
 /* ---------- Benutzerrollen (Issue #63) ---------- */
 case 'uroles': need('users', 'uroles'); // alle Rollen mit Rechten und Anzahl Personen, dazu die Liste der Rechte (PERMS)
  $n = []; foreach ($db->query("SELECT x.urole_id,COUNT(*) c FROM user_uroles x JOIN users u ON u.id=x.user_id WHERE u.active=1 GROUP BY x.urole_id")->fetchAll() as $x) $n[(int)$x['urole_id']] = (int)$x['c'];
  out(['roles' => array_map(fn($r) => ['id' => (int)$r['id'], 'name' => $r['name'], 'sys' => $r['sys'], 'def' => (int)$r['def'], 'perms' => rolePerms($r), 'users' => $n[(int)$r['id']] ?? 0, 'grp_ids' => $r['grp_ids'] === null ? null : idsIn(json_decode($r['grp_ids'], true))],
    $db->query("SELECT * FROM uroles ORDER BY sys IS NULL, name")->fetchAll()), 'perms' => array_map(fn($k, $v) => ['k' => $k, 'group' => $v[0], 'name' => $v[1], 'hint' => $v[2]], array_keys(permsAll()), permsAll())]);
 case 'urole/save': need('uroles'); // anlegen oder ändern: id, name, perms (Liste), def (bei neuen Benutzern vorausgewählt); nur eigene Rechte
  $nm = mb_substr(trim((string)($in['name'] ?? '')), 0, 80); if ($nm === '') fail('Name fehlt');
  $ps = array_values(array_unique(array_filter((array)($in['perms'] ?? []), 'is_string'))); foreach ($ps as $p) if (!isset(permsAll()[$p])) fail('Unbekanntes Recht');
  $id = (int)($in['id'] ?? 0); $old = $id ? uroleRow($id) : null; if ($id && !$old) fail('Benutzerrolle nicht gefunden', 404);
  if ($old && $old['sys'] === 'admin') fail('Die Rolle Systemadministrator hat immer alle Rechte und kann nicht geändert werden');
  if (!permsMine(array_merge($ps, $old ? rolePerms($old) : []))) fail('Du kannst nur Rechte vergeben oder entziehen, die du selbst hast', 403);
  $s = $db->prepare("SELECT COUNT(*) c FROM uroles WHERE name=? AND id<>?"); $s->execute([$nm, $id]); if ($s->fetch()['c']) fail('Eine Benutzerrolle mit diesem Namen gibt es schon');
  $df = empty($in['def']) ? 0 : 1;
  // grp_ids (Issue #90): Einsatzgruppen, die die Rolle beim Starten aufbieten darf (null = alle); erweitern nur, wer selbst alle aufbieten darf
  $gi = array_key_exists('grp_ids', $in) ? ($in['grp_ids'] === null ? null : json_encode(idsIn($in['grp_ids']))) : ($old['grp_ids'] ?? null);
  if ($gi !== ($old['grp_ids'] ?? null) && (startGroups(0) !== null || startGroups(1) !== null)) fail('Die Einschränkung der Einsatzgruppen darf nur ändern, wer selbst alle Gruppen aufbieten darf', 403);
  if ($id) $db->prepare("UPDATE uroles SET name=?,perms=?,def=?,grp_ids=? WHERE id=?")->execute([$nm, json_encode($ps), $df, $gi, $id]);
  else { $db->prepare("INSERT INTO uroles(name,perms,def,grp_ids) VALUES(?,?,?,?)")->execute([$nm, json_encode($ps), $df, $gi]); $id = (int)$db->lastInsertId(); }
  out(['ok' => true, 'id' => $id]);
 case 'urole/remove': need('uroles'); // entfernen, die Personen verlieren die Rechte dieser Rolle
  $r = uroleRow($in['id'] ?? 0); if (!$r) fail('Benutzerrolle nicht gefunden', 404);
  if ($r['sys'] === 'admin') fail('Die Rolle Systemadministrator kann nicht entfernt werden');
  if (!permsMine(rolePerms($r))) fail('Die Rolle hat Rechte, die du selbst nicht hast', 403);
  $db->prepare("DELETE FROM user_uroles WHERE urole_id=?")->execute([(int)$r['id']]); $db->prepare("DELETE FROM uroles WHERE id=?")->execute([(int)$r['id']]);
  out(['ok' => true]);
 /* ---------- Einsatzgruppen und Einsatztypen (Issue #90) ---------- */
 case 'groups': need('groups', 'ex_plan', 'users', 'uroles'); // alle Gruppen (auch deaktivierte) mit Mitgliedern
  $m = []; foreach ($db->query("SELECT group_id,user_id FROM inc_group_members")->fetchAll() as $x) $m[(int)$x['group_id']][] = (int)$x['user_id'];
  out(array_map(fn($g) => $g + ['members' => $m[$g['id']] ?? []], grpRows()));
 case 'groups/names': // alle Angemeldeten: aktive Gruppen mit «Farbe beim Namen» und ihre Mitglieder, für die Farbpunkte bei Namen in der Verwaltung (Issue #105)
  $g = array_values(array_filter(grpRows(true), fn($g) => $g['show_name'])); $m = [];
  if ($g) { $ids = array_column($g, 'id'); $s = $db->prepare("SELECT user_id,group_id FROM inc_group_members WHERE group_id IN (" . qm($ids) . ")"); $s->execute($ids); foreach ($s->fetchAll() as $x) $m[(int)$x['user_id']][] = (int)$x['group_id']; }
  out(['groups' => array_map(fn($x) => ['id' => $x['id'], 'name' => $x['name'], 'color' => $x['color']], $g), 'members' => (object)$m]);
 case 'group/save': need('groups'); // anlegen oder ändern: id, name, color, active, members (Liste der User-IDs, nicht übergeben = unverändert)
  $nm = mb_substr(trim((string)($in['name'] ?? '')), 0, 80); if ($nm === '') fail('Name fehlt');
  $col = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($in['color'] ?? '')) ? $in['color'] : '#2563eb';
  $id = (int)($in['id'] ?? 0); $ac = array_key_exists('active', $in) ? (empty($in['active']) ? 0 : 1) : 1; $sn = array_key_exists('show_name', $in) ? (empty($in['show_name']) ? 0 : 1) : null; // show_name: Farbe beim Namen in der Verwaltung (Issue #105), fehlt = unverändert bzw. an
  $s = $db->prepare("SELECT COUNT(*) c FROM inc_groups WHERE name=? AND id<>?"); $s->execute([$nm, $id]); if ($s->fetch()['c']) fail('Eine Einsatzgruppe mit diesem Namen gibt es schon');
  $db->beginTransaction();
  if ($id) { $s = $db->prepare("SELECT id FROM inc_groups WHERE id=?"); $s->execute([$id]); if (!$s->fetch()) { $db->rollBack(); fail('Einsatzgruppe nicht gefunden', 404); }
    $db->prepare("UPDATE inc_groups SET name=?,color=?,active=?,show_name=COALESCE(?,show_name) WHERE id=?")->execute([$nm, $col, $ac, $sn, $id]); }
  else { $db->prepare("INSERT INTO inc_groups(name,color,active,show_name,pos) VALUES(?,?,?,?,?)")->execute([$nm, $col, $ac, $sn ?? 1, (int)$db->query("SELECT COALESCE(MAX(pos),0)+1 m FROM inc_groups")->fetch()['m']]); $id = (int)$db->lastInsertId(); }
  if (array_key_exists('members', $in)) { $mb = idsIn($in['members']);
    if ($mb) { $s = $db->prepare("SELECT COUNT(*) c FROM users WHERE id IN (" . qm($mb) . ")"); $s->execute($mb); if ((int)$s->fetch()['c'] !== count($mb)) { $db->rollBack(); fail('Unbekannte Person'); } }
    $db->prepare("DELETE FROM inc_group_members WHERE group_id=?")->execute([$id]);
    $st = $db->prepare("INSERT INTO inc_group_members(group_id,user_id) VALUES(?,?)"); foreach ($mb as $u) $st->execute([$id, $u]); }
  $db->commit();
  out(['ok' => true, 'id' => $id]);
 case 'group/delete': need('groups'); // endgültig löschen (Issue #90): Mitglieder, Vorauswahl der Einsatztypen, Aufgebot vorbereiteter Übungen und Einschränkungen der Benutzerrollen verlieren die Gruppe;
  // Einsätze behalten den Namen (inv_groups.gn), ihr Aufgebot ist ohnehin beim Start aufgelöst
  $id = (int)($in['id'] ?? 0); $s = $db->prepare("SELECT name FROM inc_groups WHERE id=?"); $s->execute([$id]); $g = $s->fetch(); if (!$g) fail('Einsatzgruppe nicht gefunden', 404);
  $db->beginTransaction();
  $drop = function ($tbl, $col, $null) use ($db, $id) { foreach ($db->query("SELECT id,$col FROM $tbl WHERE $col IS NOT NULL")->fetchAll() as $x) { $v = json_decode($x[$col], true); if (!is_array($v) || !in_array($id, array_map('intval', $v), true)) continue;
    $db->prepare("UPDATE $tbl SET $col=? WHERE id=?")->execute([json_encode(array_values(array_diff(idsIn($v), [$id]))), $x['id']]); } };
  $drop('inc_kinds', 'grp_off', 0); $drop('inc_kinds', 'grp_def', 0); $drop('ex_plans', 'grp_ids', 0); $drop('uroles', 'grp_ids', 1);
  foreach ($db->query("SELECT id,inv_groups FROM incidents WHERE inv_groups IS NOT NULL")->fetchAll() as $x) { $v = json_decode($x['inv_groups'], true); if (!in_array($id, idsIn($v['g'] ?? []), true)) continue;
    $v['gn'] = (array)($v['gn'] ?? []); $v['gn'][$id] = $g['name']; $db->prepare("UPDATE incidents SET inv_groups=? WHERE id=?")->execute([json_encode($v), $x['id']]); }
  $db->prepare("DELETE FROM inc_group_members WHERE group_id=?")->execute([$id]); $db->prepare("DELETE FROM inc_groups WHERE id=?")->execute([$id]);
  $db->commit(); out(['ok' => true]);
 case 'group/order': need('groups'); // ids = Reihenfolge
  $st = $db->prepare("UPDATE inc_groups SET pos=? WHERE id=?"); foreach (idsIn($in['ids'] ?? []) as $i => $g) $st->execute([$i + 1, $g]); out(['ok' => true]);
 case 'kinds': need('cfg_inc'); // Einsatztypen (Issue #90), auch deaktivierte, ohne gelöschte; dazu Alarmstufen und Einsatzgruppen fürs Formular
  $uc = []; foreach ($db->query("SELECT kind_id,COUNT(*) c FROM incidents GROUP BY kind_id")->fetchAll() as $x) $uc[(int)$x['kind_id']] = (int)$x['c'];
  out(['kinds' => array_values(array_map(fn($k) => $k + ['used' => $uc[$k['id']] ?? 0], array_filter(kinds(), fn($k) => !$k['deleted']))), 'levels' => array_map(fn($k, $v) => ['k' => $k, 'name' => $v[0], 'hint' => $v[1]], array_keys(ALARM_LEVELS), ALARM_LEVELS), 'groups' => grpRows()]);
 case 'kind/save': need('cfg_inc'); // anlegen oder ändern: id, name, color, alarm, mode_up, persons, open_all, plans, rec (Vorauswahl «Aufzeichnen», Issue #136), grp_off, grp_def, active; neu zusätzlich copy_from (Funktionen, Vorauswahl, Fristen und Startrecht der Rollen übernehmen)
  $nm = mb_substr(trim((string)($in['name'] ?? '')), 0, 80); if ($nm === '') fail('Name fehlt');
  $col = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($in['color'] ?? '')) ? $in['color'] : '#2563eb'; if (!isset(ALARM_LEVELS[$in['alarm'] ?? ''])) fail('Unbekannte Alarmstufe');
  $goff = idsIn($in['grp_off'] ?? []); $gdef = array_values(array_diff(idsIn($in['grp_def'] ?? []), $goff));
  foreach (array_merge($goff, $gdef) as $g) { $s = $db->prepare("SELECT id FROM inc_groups WHERE id=?"); $s->execute([$g]); if (!$s->fetch()) fail('Unbekannte Einsatzgruppe'); }
  $id = (int)($in['id'] ?? 0); $ac = array_key_exists('active', $in) ? (empty($in['active']) ? 0 : 1) : 1;
  if ($id && !isset(kinds()[$id])) fail('Einsatztyp nicht gefunden', 404);
  if (!$ac && !array_filter(kindsActive(), fn($k) => $k['id'] !== $id)) fail('Mindestens ein Einsatztyp muss aktiv bleiben');
  if ($id && !$ac && ($r = activeIncident()) && (int)$r['kind_id'] === $id) fail('Es läuft gerade ein Einsatz dieses Typs');
  $s = $db->prepare("SELECT COUNT(*) c FROM inc_kinds WHERE name=? AND id<>? AND deleted=0"); $s->execute([$nm, $id]); if ($s->fetch()['c']) fail('Einen Einsatztyp mit diesem Namen gibt es schon');
  $vals = [$nm, $col, $in['alarm'], empty($in['mode_up']) ? 0 : 1, empty($in['persons']) ? 0 : 1, empty($in['open_all']) ? 0 : 1, empty($in['plans']) ? 0 : 1, json_encode($goff), json_encode($gdef), $ac];
  $rec = array_key_exists('rec', $in) ? (empty($in['rec']) ? 0 : 1) : null; // fehlt = unverändert bzw. von der Vorlage
  if ($id) { $db->prepare("UPDATE inc_kinds SET name=?,color=?,alarm=?,mode_up=?,persons=?,open_all=?,plans=?,grp_off=?,grp_def=?,active=? WHERE id=?")->execute(array_merge($vals, [$id]));
    if ($rec !== null) $db->prepare("UPDATE inc_kinds SET rec=? WHERE id=?")->execute([$rec, $id]); }
  else { $from = kinds()[(int)($in['copy_from'] ?? 0)] ?? null; if (!$from || $from['deleted']) fail('Bitte wählen, von welchem Einsatztyp die Einstellungen übernommen werden');
    $db->beginTransaction();
    $db->prepare("INSERT INTO inc_kinds(name,color,alarm,mode_up,persons,open_all,plans,grp_off,grp_def,active,pos,perm,rec,air,feat_off) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute(array_merge($vals, [(int)$db->query("SELECT COALESCE(MAX(pos),0)+1 m FROM inc_kinds")->fetch()['m'], '', $rec ?? $from['rec'], $from['air'], json_encode($from['feat_off'])])); $id = (int)$db->lastInsertId();
    $db->prepare("UPDATE inc_kinds SET perm=? WHERE id=?")->execute(['start_' . $id, $id]);
    // Fristen der Anonymisierung übernehmen, damit Personendaten eines neuen Typs nicht unbemerkt nie anonymisiert werden
    $ac0 = anonCfg(); foreach ($ac0 as $st0 => &$x0) $x0['days'][$id] = $x0['days'][$from['id']] ?? null; unset($x0); setSetting('anon_cfg', json_encode($ac0));
    // Startrecht: Rollen, die den Vorlagen-Typ starten dürfen, dürfen auch den neuen; nur wenn man das Recht selbst hat (Schutz vor Ausweitung wie permsMine)
    if (can($from['perm'])) foreach ($db->query("SELECT id,perms FROM uroles WHERE sys IS NULL OR sys<>'admin'")->fetchAll() as $r) { $ps = json_decode($r['perms'] ?? '[]', true) ?: []; if (in_array($from['perm'], $ps, true)) { $ps[] = 'start_' . $id; $db->prepare("UPDATE uroles SET perms=? WHERE id=?")->execute([json_encode($ps), $r['id']]); } }
    $db->commit(); }
  out(['ok' => true, 'id' => $id]);
 case 'kind/delete': need('cfg_inc'); // löschen (Issue #90): die Zeile bleibt als gelöscht, bisherige Einsätze behalten Name, Farbe, Funktionen und Fristen; vorbereitete Übungen wechseln beim Start auf einen anderen Typ
  $id = (int)($in['id'] ?? 0); $k = kinds()[$id] ?? null; if (!$k || $k['deleted']) fail('Einsatztyp nicht gefunden', 404);
  if (!array_filter(kindsActive(), fn($x) => $x['id'] !== $id)) fail('Mindestens ein Einsatztyp muss aktiv bleiben');
  if (($r = activeIncident()) && (int)$r['kind_id'] === $id) fail('Es läuft gerade ein Einsatz dieses Typs');
  $db->prepare("UPDATE inc_kinds SET deleted=1, active=0 WHERE id=?")->execute([$id]);
  out(['ok' => true]);
 case 'kind/order': need('cfg_inc');
  $st = $db->prepare("UPDATE inc_kinds SET pos=? WHERE id=?"); foreach (idsIn($in['ids'] ?? []) as $i => $t) $st->execute([$i + 1, $t]); out(['ok' => true]);
 case 'role/save': need('cfg_inc');
  if (!empty($in['id'])) $db->prepare("UPDATE roles SET name=?,color=?,is_lead=?,is_post=?,active=?,can_message=? WHERE id=?")->execute([$in['name'], $in['color'], (int)$in['is_lead'], (int)($in['is_post'] ?? 0), (int)($in['active'] ?? 1), (int)($in['can_message'] ?? 1), $in['id']]);
  else $db->prepare("INSERT INTO roles(name,color,is_lead,can_message) VALUES(?,?,?,?)")->execute([$in['name'], $in['color'] ?: '#888888', (int)($in['is_lead'] ?? 0), (int)($in['can_message'] ?? 1)]);
  out(['ok' => true]);
 case 'settings': need('cfg_inc', 'cfg_func', 'cfg_map', 'cfg_my'); out(['my_runs' => myRuns(), 'my_runs_opts' => MY_RUNS, 'my_btns' => myBtns(), 'my_btns_opts' => MY_BTNS, 'msg_no_role' => (int)setting('msg_no_role', '1'), 'msg_read_all' => (int)setting('msg_read_all', '0'), 'coord_sys' => setting('coord_sys', 'lv95'), 'rd_speeds' => rdSpeeds(), 'kinds' => array_values(array_map(fn($k) => ['id' => $k['id'], 'name' => $k['name'], 'color' => $k['color'], 'rec' => $k['rec'], 'air' => $k['air'], 'plans' => $k['plans']], kindsActive())), 'nav_apps' => array_map(fn($k) => ['k' => $k, 'name' => NAV_APPS[$k], 'on' => in_array($k, navApps(), true)], array_keys(NAV_APPS)), 'nav_default' => navDefault(), 'features' => array_map(fn($k) => ['k' => $k, 'name' => FEATURES[$k]], array_keys(FEATURES)), 'feat_on' => (object)featuresAll(), 'overpass_ttl' => (int)setting('overpass_ttl', '120'), 'overpass_urls' => overpassUrls(), 'overpass_default' => OVERPASS, 'overpass_on' => (int)setting('overpass_on', '1'), 'overpass_off' => overpassOff(),
   'air' => airCfg(), 'air_default' => AIR_DEFAULT + array_map(fn($x) => $x[0], AIR_NUM), 'air_num' => AIR_NUM, 'air_filters' => AIR_FILTERS, 'air_app' => AIR_APP, 'air_sources' => array_map(fn($s) => ['name' => $s['name'], 'site' => $s['site'], 'note' => $s['note']], AIR_SOURCES),
   'air_status' => json_decode((string)@file_get_contents(__DIR__ . '/data/air/status.json'), true) ?: (object)[], // Helikopter (Issue #73)
   'hydro' => hydroCfg(), 'hydro_num' => HYDRO_NUM, 'hydro_sources' => HYDRO_SOURCES] // Pegel & Abfluss (Issue #125)
   + (can('cfg_map') ? ['postgis' => pgCfg() + ['pass_set' => setting('postgis_pass', '') !== '' ? 1 : 0, 'ext' => extension_loaded('pdo_pgsql') ? 1 : 0, 'ready' => pgReady() ? 1 : 0], 'postgis_ssl' => PG_SSL, 'postgis_default' => PG_DEFAULT] : [])); // PostGIS (Issue #131), ohne Passwort
 /* ---------- Technisches Applikationslog (Issue #139) ---------- */
 case 'applog': need('applog'); // neueste zuerst; lvl = mindestens diese Stufe (warn = Warnungen und Fehler), cat, q (Text, Route, Angaben), before = ältere als diese ID, after = neuere (Aktualisieren)
  $w = []; $p = [];
  $lv = (string)($in['lvl'] ?? ''); if ($lv === 'warn') $w[] = "l.lvl IN ('warn','err')"; elseif ($lv === 'err') $w[] = "l.lvl='err'"; elseif ($lv === 'info') $w[] = "l.lvl='info'";
  $ca = (string)($in['cat'] ?? ''); if ($ca !== '' && isset(APPLOG_CATS[$ca])) { $w[] = 'l.cat=?'; $p[] = $ca; }
  $q = trim(mb_substr((string)($in['q'] ?? ''), 0, 100));
  if ($q !== '') { $w[] = '(l.msg LIKE ? OR l.ctx LIKE ? OR l.route LIKE ? OR u.name LIKE ? OR u.username LIKE ?)'; array_push($p, ...array_fill(0, 5, '%' . $q . '%')); }
  if (!empty($in['before'])) { $w[] = 'l.id<?'; $p[] = (int)$in['before']; }
  if (!empty($in['after'])) { $w[] = 'l.id>?'; $p[] = (int)$in['after']; }
  $n = max(20, min(500, (int)($in['limit'] ?? 200)));
  $s = $db->prepare("SELECT l.*, u.name uname FROM app_log l LEFT JOIN users u ON u.id=l.user_id" . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . " ORDER BY l.id DESC LIMIT " . ($n + 1)); $s->execute($p);
  $rs = $s->fetchAll(); $more = count($rs) > $n; $rs = array_slice($rs, 0, $n);
  $rows = array_map(fn($r) => ['ctx' => $r['ctx'] === null ? null : json_decode($r['ctx'], true)] + ints($r, ['id', 'at', 'user_id']), $rs);
  $c = $db->prepare("SELECT lvl, COUNT(*) n FROM app_log WHERE at>=? GROUP BY lvl"); $c->execute([$now - 86400]);
  $day = ['info' => 0, 'warn' => 0, 'err' => 0]; foreach ($c->fetchAll() as $x) $day[$x['lvl']] = (int)$x['n'];
  out(['rows' => $rows, 'more' => $more ? 1 : 0, 'day' => $day, 'cats' => APPLOG_CATS, 'lvls' => APPLOG_LVL, 'days' => applogDays()]);
 case 'applog/client': // Fehler aus Browser und App (common.js), alle Angemeldeten; höchstens 10 je Person in 10 Minuten
  $c = $db->prepare("SELECT COUNT(*) n FROM app_log WHERE cat='client' AND user_id=? AND at>=?"); $c->execute([$uid, $now - 600]);
  if ((int)$c->fetch()['n'] >= 10) out(['ok' => false]);
  $t = fn($k, $n) => isset($in[$k]) && is_scalar($in[$k]) ? mb_substr(preg_replace('/[\x00-\x08\x0b-\x1f]/u', ' ', (string)$in[$k]), 0, $n) : null;
  $m = $t('msg', 500); if ($m === null || trim($m) === '') fail('Meldung fehlt');
  alog(($in['lvl'] ?? '') === 'warn' ? 'warn' : 'err', 'client', $m, array_filter(['page' => $t('page', 200), 'src' => $t('src', 200), 'line' => isset($in['line']) ? (int)$in['line'] : null,
    'col' => isset($in['col']) ? (int)$in['col'] : null, 'stack' => $t('stack', 1500), 'ver' => $t('ver', 40), 'ua' => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200)], fn($v) => $v !== null && $v !== ''));
  out(['ok' => true]);
 case 'settings/save': // jede übergebene Einstellung braucht ihr Recht (SETTING_PERMS)
  foreach (SETTING_PERMS as $k => $p) if (isset($in[$k])) need($p);
  if (isset($in['ai'])) fail('Den AI-Assistenten über ai/cfg/save einstellen');
  if (isset($in['legal'])) { // Impressum & Datenschutz (Issue #102): nur bekannte Felder, gekürzt
    if (!is_array($in['legal'])) fail('Ungültige Einstellung'); $v = legalCfg();
    foreach (LEGAL_FIELDS as $k => [$n]) if (array_key_exists($k, $in['legal'])) $v[$k] = mb_substr(trim((string)$in['legal'][$k]), 0, $n);
    setSetting('legal', json_encode($v, JSON_UNESCAPED_UNICODE)); setSetting('legal_at', (string)time()); }
  if (isset($in['air'])) setSetting('air', json_encode(airIn($in['air']), JSON_UNESCAPED_UNICODE)); // Helikopter (Issue #73)
  if (isset($in['hydro'])) { setSetting('hydro', json_encode(hydroIn($in['hydro']))); @unlink(__DIR__ . '/data/hydro/bafu.json'); } // Quellen geändert: neu abrufen // Pegel & Abfluss (Issue #125)
  if (isset($in['postgis'])) { // Gewässernetz aus PostGIS (Issue #131): Passwort nur, wenn angegeben (leer = behalten), pass_clear = entfernen
    $v = pgIn($in['postgis']); $pw = $in['postgis']['pass'] ?? '';
    if (is_string($pw) && $pw !== '') setSetting('postgis_pass', mb_substr($pw, 0, 200)); elseif (!empty($in['postgis']['pass_clear'])) setSetting('postgis_pass', '');
    setSetting('postgis', json_encode($v)); }
  if (isset($in['my_runs'])) { if (!is_string($in['my_runs']) || !isset(MY_RUNS[$in['my_runs']])) fail('Ungültige Einstellung'); setSetting('my_runs', (string)$in['my_runs']); } // Meine Einsätze (Issue #61)
  if (isset($in['my_btns'])) { if (!is_array($in['my_btns'])) fail('Ungültige Einstellung'); $b = myBtns(); foreach (MY_BTNS as $k => $_) if (array_key_exists($k, $in['my_btns'])) $b[$k] = (int)!empty($in['my_btns'][$k]); setSetting('my_btns', json_encode($b)); }
  if (isset($in['overpass_ttl'])) { // Haltedauer des Overpass-Caches in Minuten, 0 = kein Cache, höchstens 7 Tage
    if (!is_numeric($in['overpass_ttl']) || (int)$in['overpass_ttl'] < 0 || (int)$in['overpass_ttl'] > 10080) fail('Haltedauer: 0 bis 10080 Minuten');
    setSetting('overpass_ttl', (string)(int)$in['overpass_ttl']);
  }
  if (isset($in['applog_days'])) { // Aufbewahrung des Applikationslogs in Tagen (Issue #139)
    if (!is_numeric($in['applog_days']) || (int)$in['applog_days'] < 1 || (int)$in['applog_days'] > 365) fail('Aufbewahrung: 1 bis 365 Tage');
    setSetting('applog_days', (string)(int)$in['applog_days']); setSetting('applog_purge_at', '0'); }
  if (isset($in['overpass_urls'])) { // Overpass-Server in der Reihenfolge der Abfrage, 1–8 https-Adressen; leere Liste = Standard (OVERPASS)
    $v = [];
    foreach ((array)$in['overpass_urls'] as $u) {
      $u = trim((string)$u);
      if (strlen($u) > 300 || !filter_var($u, FILTER_VALIDATE_URL) || !preg_match('#^https://[^/?\#@]+/#i', $u)) fail('Ungültige Adresse: ' . mb_substr($u, 0, 80) . ' (nur https://…)');
      if (!in_array($u, $v, true)) $v[] = $u;
    }
    if (count($v) > 8) fail('Höchstens 8 Overpass-Server');
    setSetting('overpass_urls', $v ? json_encode($v, JSON_UNESCAPED_SLASHES) : '');
  }
  if (isset($in['overpass_off'])) // ausgeschaltete Server, nur hinterlegte (Issue #75)
    setSetting('overpass_off', json_encode(array_values(array_intersect(overpassUrls(), array_filter((array)$in['overpass_off'], 'is_string'))), JSON_UNESCAPED_SLASHES));
  if (isset($in['rd_speeds'])) { // 1–6 Geschwindigkeiten zwischen 1 und 200 km/h, sortiert und ohne Duplikate
    foreach ((array)$in['rd_speeds'] as $x) if (!is_numeric($x)) fail('Nur Zahlen sind erlaubt (km/h)');
    $v = array_values(array_unique(array_map(fn($x) => round((float)$x, 1), (array)$in['rd_speeds'])));
    if (!$v || count($v) > 6) fail('Bitte 1 bis 6 Geschwindigkeiten angeben');
    foreach ($v as $x) if ($x < 1 || $x > 200) fail('Geschwindigkeiten müssen zwischen 1 und 200 km/h liegen');
    sort($v); setSetting('rd_speeds', implode(',', $v));
  }
  foreach (['air_kinds' => 'air'] as $k => $col) if (isset($in[$k]) && is_array($in[$k])) { foreach ($in[$k] as $id => $v) $db->prepare("UPDATE inc_kinds SET $col=? WHERE id=?")->execute([empty($v) ? 0 : 1, (int)$id]); $KINDS = null; } // Vorauswahl je Einsatztyp
  foreach (['msg_no_role', 'msg_read_all', 'overpass_on'] as $k) if (isset($in[$k])) setSetting($k, empty($in[$k]) ? '0' : '1');
  if (isset($in['nav_off'])) { // ausgeschaltete Navigations-Apps, mindestens eine bleibt
    $v = array_values(array_intersect(array_keys(NAV_APPS), array_filter((array)$in['nav_off'], 'is_string')));
    if (count($v) >= count(NAV_APPS)) fail('Mindestens eine Navigations-App muss eingeschaltet bleiben');
    setSetting('nav_off', json_encode($v));
  }
  if (isset($in['nav_default'])) { if (!in_array($in['nav_default'], navApps(), true)) fail('Der Standard muss eine eingeschaltete App sein'); setSetting('nav_default', $in['nav_default']); }
  if (isset($in['coord_sys'])) { if (!in_array($in['coord_sys'], COORDS, true)) fail('Unbekanntes Koordinatensystem'); setSetting('coord_sys', $in['coord_sys']); }
  if (isset($in['features'])) { // {Einsatztyp: [ausgeschaltete Schlüssel]}; gilt sofort, auch im laufenden Einsatz
    foreach (kindsActive() as $id => $_) { if (!isset($in['features'][$id])) continue; $o = array_values(array_unique(array_filter((array)$in['features'][$id], 'is_string'))); foreach ($o as $k) if (!array_key_exists($k, FEATURES)) fail('Unbekannte Funktion');
      $db->prepare("UPDATE inc_kinds SET feat_off=? WHERE id=?")->execute([json_encode($o), $id]); }
    $KINDS = null;
    // laufender Einsatz: Aufzeichnung beenden bzw. öffentlichen Link ungültig machen, wenn die Funktion für seinen Typ jetzt aus ist
    if ($inc = activeIncident()) {
      if ((int)$inc['record'] && !feature($inc, 'rec')) { setRec((int)$inc['id'], 0); logev($inc['id'], $uid, 'rec', 'beendet die Aufzeichnung (Funktion in der Verwaltung ausgeschaltet)'); }
      if ($inc['public_token'] !== null && !feature($inc, 'pub')) { $db->prepare("UPDATE incidents SET public_token=NULL WHERE id=?")->execute([$inc['id']]); logev($inc['id'], $uid, 'pub', 'schaltet die öffentliche Ansicht aus (Funktion in der Verwaltung ausgeschaltet)'); }
    }
  }
  out(['ok' => true]);
 case 'places': need('cfg_map', 'ex_plan'); // permanente feste Standorte, auch deaktivierte (Issue #96; Übungsleiter: Auswahl je Übung)
  out(placesOf(null));
 case 'place/cats': need('cfg_map', 'ex_plan'); out(placeCats()); // Kategorien fester Standorte
 case 'place/cat/save': need('cfg_map'); // anlegen oder ändern: id, name, def_on (Standard eingeblendet)
  $nm = mb_substr(trim((string)($in['name'] ?? '')), 0, 60); if ($nm === '') fail('Name fehlt'); $on = !array_key_exists('def_on', $in) || !empty($in['def_on']) ? 1 : 0;
  if (!empty($in['id'])) { $s = $db->prepare("SELECT id FROM place_cats WHERE id=?"); $s->execute([(int)$in['id']]); if (!$s->fetchColumn()) fail('Kategorie nicht gefunden', 404);
    $db->prepare("UPDATE place_cats SET name=?, def_on=? WHERE id=?")->execute([$nm, $on, (int)$in['id']]); }
  else $db->prepare("INSERT INTO place_cats(name,def_on) VALUES(?,?)")->execute([$nm, $on]);
  out(['ok' => true]);
 case 'place/cat/move': need('cfg_map'); // Kategorie um eine Stelle nach oben (dir -1) oder unten (dir 1)
  sortMove('place_cats', "SELECT id FROM place_cats ORDER BY " . SORT_ORDER, [], (int)($in['id'] ?? 0), (int)($in['dir'] ?? 1)); out(['ok' => true]);
 case 'place/move': // Standort innerhalb seiner Kategorie (und seiner Übung bzw. der permanenten) verschieben, Rechte wie place/save
  $s = $db->prepare("SELECT * FROM places WHERE id=?"); $s->execute([(int)($in['id'] ?? 0)]); $f = $s->fetch() ?: fail('Standort nicht gefunden', 404);
  if ($f['plan_id'] === null) need('cfg_map'); else { plannerOnly(); $s = $db->prepare("SELECT incident_id FROM ex_plans WHERE id=?"); $s->execute([(int)$f['plan_id']]); if ($s->fetchColumn() !== null) fail('Die Übung wurde bereits gestartet'); }
  sortMove('places', "SELECT id FROM places WHERE plan_id" . ($f['plan_id'] === null ? ' IS NULL' : '=' . (int)$f['plan_id']) . " AND cat_id" . ($f['cat_id'] === null ? ' IS NULL' : '=' . (int)$f['cat_id']) . " ORDER BY " . SORT_ORDER, [], (int)$f['id'], (int)($in['dir'] ?? 1)); out(['ok' => true]);
 case 'place/cat/remove': need('cfg_map'); // Standorte der Kategorie gelten danach als «ohne Kategorie»
  $db->prepare("UPDATE places SET cat_id=NULL WHERE cat_id=?")->execute([(int)($in['id'] ?? 0)]); $db->prepare("DELETE FROM place_cats WHERE id=?")->execute([(int)($in['id'] ?? 0)]); out(['ok' => true]);
 case 'place/save': case 'place/remove': // fester Standort anlegen, ändern oder entfernen: permanent mit cfg_map, einer vorbereiteten Übung (plan_id) mit ex_plan, nur vor deren Start
  $old = null; if (!empty($in['id'])) { $s = $db->prepare("SELECT * FROM places WHERE id=?"); $s->execute([(int)$in['id']]); $old = $s->fetch() ?: fail('Standort nicht gefunden', 404); }
  $pid = $old ? ($old['plan_id'] === null ? null : (int)$old['plan_id']) : (empty($in['plan_id']) ? null : (int)$in['plan_id']);
  if ($pid === null) need('cfg_map');
  else { plannerOnly(); $s = $db->prepare("SELECT incident_id FROM ex_plans WHERE id=? AND active=1"); $s->execute([$pid]); $p = $s->fetch() ?: fail('Übung nicht gefunden', 404); if ($p['incident_id'] !== null) fail('Die Übung wurde bereits gestartet'); }
  if ($route === 'place/remove') { if (!$old) fail('Standort nicht gefunden', 404); $db->prepare("DELETE FROM places WHERE id=?")->execute([(int)$old['id']]); out(['ok' => true]); }
  $nm = mb_substr(trim((string)($in['name'] ?? '')), 0, 80); if ($nm === '') fail('Name fehlt');
  $de = mb_substr(trim((string)($in['descr'] ?? '')), 0, 500); [$ab, $co] = extStyle($in);
  $ln = !empty($in['line']) ? 1 : 0; // Linie (Issue #134, z. B. Fahrrinne): pts mit 2–200 Punkten
  $pts = null; if (!empty($in['pts'])) { $pts = placePts($in['pts'], $ln); $ll = $ln ? lineMid($pts) : [round(array_sum(array_column($pts, 0)) / count($pts), 7), round(array_sum(array_column($pts, 1)) / count($pts), 7)]; } // Bereich: Mitte = Schwerpunkt der Eckpunkte, Linie: Punkt auf halber Länge
  elseif ($ln) fail('Eine Linie braucht 2–200 Punkte');
  elseif (!isset($in['lat'], $in['lon']) || $in['lat'] === '' || $in['lon'] === '') fail('Standort fehlt'); else $ll = llIn($in['lat'], $in['lon']);
  $pj = $pts ? json_encode($pts) : null; if ($pts) $ab = null; // Bereiche und Linien haben kein Kürzel (nur Marker zeigen es)
  $ac = $pid !== null || !array_key_exists('active', $in) || !empty($in['active']) ? 1 : 0;
  $cid = null; if (!empty($in['cat_id'])) { $s = $db->prepare("SELECT id FROM place_cats WHERE id=?"); $s->execute([(int)$in['cat_id']]); $cid = $s->fetchColumn() ? (int)$in['cat_id'] : fail('Kategorie nicht gefunden', 404); }
  if ($old) $db->prepare("UPDATE places SET name=?,descr=?,abbr=?,color=?,lat=?,lon=?,active=?,cat_id=?,pts=?,line=?,sort=? WHERE id=?")->execute([$nm, $de, $ab, $co, $ll[0], $ll[1], $ac, $cid, $pj, $ln, ($old['cat_id'] === null ? null : (int)$old['cat_id']) === $cid ? $old['sort'] : null, (int)$old['id']]); // andere Kategorie: dort ans Ende
  else $db->prepare("INSERT INTO places(plan_id,name,descr,abbr,color,lat,lon,active,cat_id,pts,line) VALUES(?,?,?,?,?,?,?,?,?,?,?)")->execute([$pid, $nm, $de, $ab, $co, $ll[0], $ll[1], $ac, $cid, $pj, $ln]);
  out(['ok' => true]);
 case 'ext_templates': need('cfg_inc', 'ex_plan'); // Vorlagen für externe Einsatzkräfte, auch deaktivierte (Übungsleiter: Übernahme ins Element «Neue externe Einsatzkraft»)
  out(array_map(fn($r) => floats(ints($r, ['id', 'role_id', 'active']), ['lat', 'lon']), $db->query("SELECT id,name,descr,role_id,abbr,color,lat,lon,active FROM ext_templates ORDER BY active DESC, name")->fetchAll()));
 case 'ext_template/save': need('cfg_inc'); // anlegen oder ändern: name, descr, role_id, abbr, color, lat/lon (beide leer = Standort beim Erfassen wählen), active
  $nm = mb_substr(trim((string)($in['name'] ?? '')), 0, 80); if ($nm === '') fail('Name fehlt');
  $de = mb_substr(trim((string)($in['descr'] ?? '')), 0, 500); $rid = roleIn($in['role_id'] ?? null); [$ab, $co] = extStyle($in);
  $ll = isset($in['lat'], $in['lon']) && $in['lat'] !== '' && $in['lon'] !== '' ? llIn($in['lat'], $in['lon']) : [null, null];
  $ac = array_key_exists('active', $in) && empty($in['active']) ? 0 : 1;
  if (!empty($in['id'])) $db->prepare("UPDATE ext_templates SET name=?,descr=?,role_id=?,abbr=?,color=?,lat=?,lon=?,active=? WHERE id=?")->execute([$nm, $de, $rid, $ab, $co, $ll[0], $ll[1], $ac, (int)$in['id']]);
  else $db->prepare("INSERT INTO ext_templates(name,descr,role_id,abbr,color,lat,lon,active) VALUES(?,?,?,?,?,?,?,?)")->execute([$nm, $de, $rid, $ab, $co, $ll[0], $ll[1], $ac]);
  out(['ok' => true]);
 case 'mode/save': need('cfg_inc'); // Einrückoption anlegen oder ändern
  $nm = trim($in['name'] ?? ''); if ($nm === '') fail('Name fehlt');
  $s = $db->prepare("SELECT * FROM modes WHERE id=?"); $s->execute([(int)($in['id'] ?? 0)]); $old = $s->fetch() ?: ['share_location' => 1, 'active' => 1, 'lead_rights' => 0, 'counts' => 1];
  $flag = fn($k) => isset($in[$k]) ? (empty($in[$k]) ? 0 : 1) : (int)$old[$k]; // nicht übergeben = bisheriger Wert
  $sh = $flag('share_location'); $ac = $flag('active'); $lr = $flag('lead_rights'); $co = $flag('counts');
  if (!empty($in['id'])) {
    $id = (int)$in['id']; if (empty($old['id'])) fail('Einrückoption nicht gefunden', 404);
    $s = $db->prepare("SELECT COUNT(*) c FROM modes WHERE active=1 AND id<>?"); $s->execute([$id]);
    if (!$ac && !$s->fetch()['c']) fail('Mindestens eine Einrückoption muss aktiv bleiben');
    $db->prepare("UPDATE modes SET name=?,share_location=?,active=?,lead_rights=?,counts=? WHERE id=?")->execute([$nm, $sh, $ac, $lr, $co, $id]);
  } else {
    $db->prepare("INSERT INTO modes(code,name,share_location,active,lead_rights,counts) VALUES(?,?,?,1,?,?)")->execute(['neu-' . bin2hex(random_bytes(6)), $nm, $sh, $lr, $co]);
    $id = (int)$db->lastInsertId(); $db->prepare("UPDATE modes SET code=? WHERE id=?")->execute(['m' . $id, $id]);
  }
  out(['ok' => true]);
}
fail('Unbekannte Route', 404);
