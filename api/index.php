<?php
// Swiftwater Rescue API – Aufruf: api/index.php?r=<route>
$cfg = require __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function out($d, $c = 200) { http_response_code($c); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
function fail($m, $c = 400) { out(['error' => $m], $c); }

$db = new PDO($cfg['dsn'], $cfg['db_user'], $cfg['db_pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$my = str_starts_with($cfg['dsn'], 'mysql');
$ai = $my ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
$tx = $my ? 'VARCHAR(190)' : 'TEXT';
foreach ([
 "CREATE TABLE IF NOT EXISTS users(id $ai, username $tx UNIQUE, name $tx, pass_hash $tx, is_admin INT DEFAULT 0, active INT DEFAULT 1, photo MEDIUMTEXT, token $tx, exercise_mode INT DEFAULT 0, must_change INT DEFAULT 0)",
 "CREATE TABLE IF NOT EXISTS roles(id $ai, name $tx, color $tx, is_lead INT DEFAULT 0, active INT DEFAULT 1, is_post INT DEFAULT 0)",
 "CREATE TABLE IF NOT EXISTS incidents(id $ai, message TEXT, lat DOUBLE, lon DOUBLE, alarm_at BIGINT, ended_at BIGINT NULL, flow_ms DOUBLE NULL, created_by INT, is_exercise INT DEFAULT 0)",
 "CREATE TABLE IF NOT EXISTS participants(id $ai, incident_id INT, user_id INT, mode $tx, role_id INT NULL, lat DOUBLE NULL, lon DOUBLE NULL, loc_at BIGINT NULL, left_at BIGINT NULL, log_lat DOUBLE NULL, log_lon DOUBLE NULL, log_at BIGINT NULL, UNIQUE(incident_id,user_id))",
 "CREATE TABLE IF NOT EXISTS messages(id $ai, incident_id INT, from_user INT, text TEXT, recipients TEXT NULL, created BIGINT)",
 "CREATE TABLE IF NOT EXISTS incident_log(id $ai, incident_id INT, ts BIGINT, user_id INT NULL, kind $tx, text TEXT)",
 "CREATE TABLE IF NOT EXISTS rescuee_marks(id $ai, incident_id INT, user_id INT, lat DOUBLE, lon DOUBLE, ts BIGINT, manual INT DEFAULT 0)",
] as $s) $db->exec($s);
// Migrationen für ältere Installationen (schlagen bei vorhandenen Spalten still fehl)
try { $db->exec("ALTER TABLE roles ADD COLUMN is_post INT DEFAULT 0"); $db->exec("UPDATE roles SET is_post=1 WHERE name LIKE 'Sicherungs%'"); } catch (Throwable $e) {}
foreach (['participants' => ['log_lat DOUBLE NULL', 'log_lon DOUBLE NULL', 'log_at BIGINT NULL'], 'users' => ['exercise_mode INT DEFAULT 0', 'must_change INT DEFAULT 0'], 'incidents' => ['is_exercise INT DEFAULT 0']] as $t => $cs)
  foreach ($cs as $c) { try { $db->exec("ALTER TABLE $t ADD COLUMN $c"); } catch (Throwable $e) {} }
if (!$db->query("SELECT COUNT(*) c FROM roles")->fetch()['c']) {
  $st = $db->prepare("INSERT INTO roles(name,color,is_lead,is_post) VALUES(?,?,?,?)");
  foreach ([['Einsatzleitung','#d62828',1,0],['Rettungsboot','#1d7fd1',0,0],['Rettungsschlitten','#e6a100',0,0],['Sicherungs- und Beobachtungsposten','#2a9d5c',0,1]] as $r) $st->execute($r);
}
if (!$db->query("SELECT COUNT(*) c FROM users")->fetch()['c'])
  $db->prepare("INSERT INTO users(username,name,pass_hash,is_admin,must_change) VALUES(?,?,?,1,1)")->execute([$cfg['admin_user'], 'Administrator', password_hash($cfg['admin_pass'], PASSWORD_DEFAULT)]);

$route = $_GET['r'] ?? '';
$in = json_decode(file_get_contents('php://input'), true) ?: [];
$now = time();
$MODES = ['base' => 'Anfahrt via Stützpunkt', 'direct' => 'Anfahrt direkt', 'logistics' => 'Logistik', 'coordinator' => 'Koordinator KP Rück'];

if ($route === 'login') {
  $st = $db->prepare("SELECT * FROM users WHERE username=? AND active=1"); $st->execute([$in['username'] ?? '']);
  $u = $st->fetch();
  if (!$u || !password_verify($in['password'] ?? '', $u['pass_hash'])) fail('Benutzername oder Passwort falsch', 401);
  $t = bin2hex(random_bytes(24));
  $db->prepare("UPDATE users SET token=? WHERE id=?")->execute([$t, $u['id']]);
  out(['token' => $t]);
}

$tok = $_SERVER['HTTP_X_TOKEN'] ?? '';
$st = $db->prepare("SELECT id,username,name,is_admin,photo,exercise_mode,must_change FROM users WHERE token=? AND active=1"); $st->execute([$tok]);
$me = $tok ? $st->fetch() : null;
if (!$me) fail('Nicht angemeldet', 401);
$me = ints($me, ['id', 'is_admin', 'exercise_mode', 'must_change']); $uid = $me['id'];
// Nach einem Zurücksetzen durch die Verwaltung ist nur der Passwortwechsel erlaubt
if ($me['must_change'] && !in_array($route, ['me', 'me/password'])) fail('Bitte zuerst ein eigenes Passwort setzen', 403);

/* ---------- Hilfsfunktionen ---------- */
function ints($r, $ks) { foreach ($ks as $k) if (isset($r[$k])) $r[$k] = (int)$r[$k]; return $r; }
function admin() { global $me; if (!$me['is_admin']) fail('Nur für Administratoren', 403); }
function pwCheck($p) { if (!is_string($p) || mb_strlen($p) < 8) fail('Das Passwort muss mindestens 8 Zeichen lang sein'); }
// Kritisch alarmieren: scharfe Einsätze immer, Übungen nur bei aktiviertem Übungsmodus
function critical($inc) { global $me; return !(int)$inc['is_exercise'] || $me['exercise_mode']; }
function part($incId, $u = null) { global $db, $uid; $s = $db->prepare("SELECT * FROM participants WHERE incident_id=? AND user_id=?"); $s->execute([$incId, $u ?? $uid]); return $s->fetch(); }
function isActivePart($incId) { $p = part($incId); return $p && $p['mode'] !== 'declined' && $p['left_at'] === null; }
function isLead($incId) { global $db, $uid; $s = $db->prepare("SELECT COUNT(*) c FROM participants p JOIN roles r ON r.id=p.role_id WHERE p.incident_id=? AND p.user_id=? AND p.left_at IS NULL AND r.is_lead=1"); $s->execute([$incId, $uid]); return $s->fetch()['c'] > 0; }
function canManage($inc) { global $me, $uid; return $me['is_admin'] || (int)$inc['created_by'] === $uid || isLead((int)$inc['id']); }
function canFlow($inc) { return canManage($inc) || isActivePart((int)$inc['id']); }
function activeIncident() { global $db; return $db->query("SELECT * FROM incidents WHERE ended_at IS NULL ORDER BY id DESC LIMIT 1")->fetch() ?: null; }
function logev($incId, $userId, $kind, $text) { global $db, $now; $db->prepare("INSERT INTO incident_log(incident_id,ts,user_id,kind,text) VALUES(?,?,?,?,?)")->execute([$incId, $now, $userId, $kind, $text]); }
function hav($a, $b, $c, $d) { $p1 = deg2rad($a); $p2 = deg2rad($c); $dp = $p2 - $p1; $dl = deg2rad($d - $b); $x = sin($dp / 2) ** 2 + cos($p1) * cos($p2) * sin($dl / 2) ** 2; return 12742000 * asin(min(1, sqrt($x))); }
function avg($a) { $a = array_values(array_filter($a, fn($x) => $x !== null)); return $a ? round(array_sum($a) / count($a), 1) : null; }
function http_get($url) {
  if (function_exists('curl_init')) { $c = curl_init($url); curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => 1, CURLOPT_TIMEOUT => 10, CURLOPT_USERAGENT => 'SwiftwaterRescue/0.1']); $r = curl_exec($c); curl_close($c); return $r; }
  return @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 10, 'header' => "User-Agent: SwiftwaterRescue/0.1\r\n"]]));
}
function logRows($id, $asc = false) {
  global $db;
  $s = $db->prepare("SELECT l.id,l.ts,l.kind,l.text,u.name FROM incident_log l LEFT JOIN users u ON u.id=l.user_id WHERE l.incident_id=? ORDER BY l.id " . ($asc ? 'ASC' : 'DESC') . " LIMIT 500");
  $s->execute([$id]);
  return array_map(fn($r) => ints($r, ['id', 'ts']), $s->fetchAll());
}

switch ($route) {
 case 'me': out(['me' => $me]);
 case 'roles': out(array_map(fn($r) => ints($r, ['id', 'is_lead', 'is_post']), $db->query("SELECT id,name,color,is_lead,is_post FROM roles WHERE active=1 ORDER BY id")->fetchAll()));

 case 'incident/start':
  if (activeIncident()) fail('Es läuft bereits ein Einsatz');
  if (trim($in['message'] ?? '') === '' || !isset($in['lat'], $in['lon'])) fail('Meldung und Standort (PLS) sind Pflicht');
  $at = !empty($in['alarm_at']) ? (int)$in['alarm_at'] : $now;
  if ($at > $now + 60) fail('Der Alarmeingang darf nicht in der Zukunft liegen');
  if ($at < $now - 7 * 86400) fail('Der Alarmeingang liegt mehr als 7 Tage zurück');
  $ex = empty($in['is_exercise']) ? 0 : 1;
  $db->prepare("INSERT INTO incidents(message,lat,lon,alarm_at,created_by,is_exercise) VALUES(?,?,?,?,?,?)")->execute([$in['message'], (float)$in['lat'], (float)$in['lon'], $at, $uid, $ex]);
  $nid = (int)$db->lastInsertId();
  logev($nid, $uid, 'start', 'hat den Einsatz gestartet (' . ($ex ? 'Übung' : 'scharfer Einsatz') . '): ' . $in['message']);
  out(['id' => $nid]);

 case 'incident/state': // Polling-Endpunkt: ?since=<letzte Nachrichten-ID>
  $inc = activeIncident();
  if (!$inc) {
    $last = $db->query("SELECT id,ended_at FROM incidents ORDER BY id DESC LIMIT 1")->fetch();
    out(['incident' => null, 'last_ended' => $last ?: null]);
  }
  $inc['id'] = (int)$inc['id']; $inc['lat'] = (float)$inc['lat']; $inc['lon'] = (float)$inc['lon']; $inc['alarm_at'] = (int)$inc['alarm_at']; $inc['created_by'] = (int)$inc['created_by']; $inc['is_exercise'] = (int)$inc['is_exercise'];
  if ($inc['flow_ms'] !== null) $inc['flow_ms'] = (float)$inc['flow_ms'];
  $id = $inc['id']; $since = (int)($_GET['since'] ?? 0);
  $ps = $db->prepare("SELECT p.user_id,p.mode,p.role_id,p.lat,p.lon,p.loc_at,p.left_at,u.name,u.photo FROM participants p JOIN users u ON u.id=p.user_id WHERE p.incident_id=?");
  $ps->execute([$id]);
  $isP = isActivePart($id);
  $msgs = [];
  if ($isP) { // Nachrichten sehen nur Personen, die den Einsatz angenommen haben
    $ms = $db->prepare("SELECT m.id,m.from_user,m.text,m.created,m.recipients,u.name from_name FROM messages m JOIN users u ON u.id=m.from_user WHERE m.incident_id=? AND m.id>? ORDER BY m.id");
    $ms->execute([$id, $since]);
    $msgs = array_values(array_filter(array_map(fn($m) => ints($m, ['id', 'from_user', 'created']), $ms->fetchAll()), fn($m) => $m['recipients'] === null || in_array($uid, json_decode($m['recipients'], true))));
  }
  $mk = $db->prepare("SELECT m.id,m.user_id,m.lat,m.lon,m.ts,m.manual,u.name FROM rescuee_marks m LEFT JOIN users u ON u.id=m.user_id WHERE m.incident_id=? ORDER BY m.id");
  $mk->execute([$id]);
  $marks = array_map(fn($m) => ints(array_merge($m, ['lat' => (float)$m['lat'], 'lon' => (float)$m['lon']]), ['id', 'user_id', 'ts', 'manual']), $mk->fetchAll());
  $cf = canFlow($inc);
  out(['incident' => $inc,
       'participants' => array_map(function ($p) { foreach (['lat', 'lon'] as $k) if ($p[$k] !== null) $p[$k] = (float)$p[$k]; return ints($p, ['user_id', 'role_id', 'left_at', 'loc_at']); }, $ps->fetchAll()),
       'marks' => $marks, 'messages' => $msgs, 'me' => part($id) ?: null, 'is_lead' => isLead($id), 'can_manage' => canManage($inc), 'can_flow' => $cf, 'critical' => critical($inc),
       'log' => $cf ? logRows($id) : null, 'now' => $now]);

 case 'incident/join': // mode: base | direct | logistics | coordinator | declined
  $inc = activeIncident(); if (!$inc) fail('Kein aktiver Einsatz');
  $mode = $in['mode'] ?? '';
  if (!in_array($mode, ['base', 'direct', 'logistics', 'coordinator', 'declined'])) fail('Ungültige Auswahl');
  $prev = part($inc['id']);
  $db->prepare($my ? "INSERT INTO participants(incident_id,user_id,mode) VALUES(?,?,?) ON DUPLICATE KEY UPDATE mode=VALUES(mode), left_at=NULL"
                   : "INSERT INTO participants(incident_id,user_id,mode) VALUES(?,?,?) ON CONFLICT(incident_id,user_id) DO UPDATE SET mode=excluded.mode, left_at=NULL")->execute([$inc['id'], $uid, $mode]);
  if (!$prev || $prev['mode'] !== $mode || $prev['left_at'] !== null) {
    if ($mode === 'declined') logev($inc['id'], $uid, 'decline', 'hat den Einsatz abgelehnt');
    else logev($inc['id'], $uid, 'accept', ($prev && $prev['left_at'] !== null ? 'ist wieder eingestiegen' : 'hat den Einsatz angenommen') . ' (' . $MODES[$mode] . ')');
  }
  out(['ok' => true]);

 case 'incident/leave':
  $inc = activeIncident(); if (!$inc) fail('Kein aktiver Einsatz');
  $p = part($inc['id']);
  $db->prepare("UPDATE participants SET left_at=?, role_id=NULL WHERE incident_id=? AND user_id=?")->execute([$now, $inc['id'], $uid]);
  if ($p && $p['left_at'] === null && $p['mode'] !== 'declined') logev($inc['id'], $uid, 'leave', 'hat den Einsatz verlassen');
  out(['ok' => true]);

 case 'incident/role': // role_id oder null
  $inc = activeIncident(); $p = $inc ? part($inc['id']) : null; if (!$p) fail('Nicht im Einsatz');
  $rid = !empty($in['role_id']) ? (int)$in['role_id'] : null;
  if ($rid !== ($p['role_id'] !== null ? (int)$p['role_id'] : null)) {
    $db->prepare("UPDATE participants SET role_id=? WHERE incident_id=? AND user_id=?")->execute([$rid, $inc['id'], $uid]);
    if ($rid) { $s = $db->prepare("SELECT name FROM roles WHERE id=?"); $s->execute([$rid]); logev($inc['id'], $uid, 'role', 'übernimmt die Rolle ' . ($s->fetch()['name'] ?? '?')); }
    else logev($inc['id'], $uid, 'role', 'legt die Spezialrolle ab');
  }
  out(['ok' => true]);

 case 'incident/location':
  $inc = activeIncident(); if (!$inc) fail('Kein aktiver Einsatz');
  $p = part($inc['id']); if (!$p || $p['left_at'] !== null || $p['mode'] === 'declined') out(['ok' => true]);
  $la = (float)$in['lat']; $lo = (float)$in['lon'];
  $db->prepare("UPDATE participants SET lat=?,lon=?,loc_at=? WHERE incident_id=? AND user_id=?")->execute([$la, $lo, $now, $inc['id'], $uid]);
  if ($p['log_lat'] === null) $db->prepare("UPDATE participants SET log_lat=?,log_lon=?,log_at=? WHERE incident_id=? AND user_id=?")->execute([$la, $lo, $now, $inc['id'], $uid]);
  else { // grössere Verschiebung (> 300 m, höchstens einmal pro Minute) protokollieren
    $d = hav((float)$p['log_lat'], (float)$p['log_lon'], $la, $lo);
    if ($d > 300 && $now - (int)$p['log_at'] >= 60) {
      logev($inc['id'], $uid, 'move', 'hat sich um ca. ' . (round($d / 10) * 10) . ' m verschoben');
      $db->prepare("UPDATE participants SET log_lat=?,log_lon=?,log_at=? WHERE incident_id=? AND user_id=?")->execute([$la, $lo, $now, $inc['id'], $uid]);
    }
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
  logev($inc['id'], $uid, 'flow', 'setzt die Fliessgeschwindigkeit auf ' . $v . ' m/s (' . (($in['src'] ?? '') === 'BAFU-Vorschlag' ? 'BAFU-Vorschlag' : 'manuell') . ')');
  out(['ok' => true]);

 case 'flow/suggest': // Vorschlag aus BAFU-Abflussdaten (via api.existenz.ch), grobe Schätzung
  $lat = (float)($in['lat'] ?? 0); $lon = (float)($in['lon'] ?? 0); $river = mb_strtolower(trim($in['river'] ?? ''));
  $cache = __DIR__ . '/data/hydro_locations.json'; $q = 'app=swiftwater.rescue&version=0.1';
  $raw = (is_file($cache) && filemtime($cache) > $now - 7 * 86400) ? file_get_contents($cache) : http_get("https://api.existenz.ch/apiv1/hydro/locations?$q");
  $pl = json_decode($raw ?: '', true)['payload'] ?? null;
  if (!is_array($pl) || !$pl) fail('BAFU-Stationsliste nicht abrufbar', 502);
  if (!is_file($cache) || filemtime($cache) <= $now - 7 * 86400) @file_put_contents($cache, $raw);
  $c = [];
  foreach ($pl as $key => $e) {
    if (!is_array($e)) continue;
    $la = $lo = null;
    array_walk_recursive($e, function ($v, $k) use (&$la, &$lo) { $k = strtolower((string)$k); if (!is_numeric($v)) return; if ($k === 'lat' || $k === 'latitude') $la = (float)$v; if ($k === 'lon' || $k === 'lng' || $k === 'longitude') $lo = (float)$v; });
    if ($la === null || $lo === null) continue;
    $d = hav($lat, $lon, $la, $lo); if ($d > 25000) continue;
    $nm = is_string($e['name'] ?? null) ? $e['name'] : (string)$key;
    $c[] = ['id' => (string)($e['id'] ?? $key), 'd' => $d, 'name' => $nm, 'm' => $river !== '' && str_contains(mb_strtolower(json_encode($e, JSON_UNESCAPED_UNICODE)), $river)];
  }
  if (!$c) fail('Keine BAFU-Messstation innerhalb von 25 km gefunden', 404);
  usort($c, fn($a, $b) => [$b['m'], $a['d']] <=> [$a['m'], $b['d']]);
  $c = array_slice($c, 0, 6);
  $lt = json_decode(http_get('https://api.existenz.ch/apiv1/hydro/latest?locations=' . implode(',', array_column($c, 'id')) . "&parameters=flow&$q") ?: '', true)['payload'] ?? null;
  if (!is_array($lt)) fail('BAFU-Abflussdaten nicht abrufbar', 502);
  $flow = []; foreach ($lt as $r) if (($r['par'] ?? '') === 'flow' && is_numeric($r['val'] ?? null)) $flow[(string)$r['loc']] = (float)$r['val'];
  foreach ($c as $s) if (isset($flow[$s['id']]) && $flow[$s['id']] > 0) {
    $qv = $flow[$s['id']];
    out(['name' => $s['name'], 'station' => $s['id'], 'distance_km' => round($s['d'] / 1000, 1), 'q' => round($qv, 1), 'v' => max(0.3, round(0.8 * pow($qv, 0.18), 1)), 'same_river' => $s['m']]);
  }
  fail('Keine aktuellen Abflussdaten in der Nähe verfügbar', 404);

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
  if (!isActivePart($inc['id'])) fail('Nachrichten können nur Personen senden, die den Einsatz angenommen haben');
  if (trim($in['text'] ?? '') === '') fail('Text fehlt');
  $s = $db->prepare("SELECT user_id FROM participants WHERE incident_id=? AND mode<>'declined' AND left_at IS NULL"); $s->execute([$inc['id']]);
  $act = array_map('intval', array_column($s->fetchAll(), 'user_id'));
  $to = null;
  if (!empty($in['to'])) { $to = array_values(array_intersect(array_map('intval', $in['to']), $act)); if (!$to) fail('Die gewählten Personen haben den Einsatz nicht angenommen'); }
  $db->prepare("INSERT INTO messages(incident_id,from_user,text,recipients,created) VALUES(?,?,?,?,?)")->execute([$inc['id'], $uid, $in['text'], $to === null ? null : json_encode($to), $now]);
  logev($inc['id'], $uid, 'message', 'sendet Nachricht an ' . ($to === null ? 'alle' : count($to) . ' Person(en)') . ': ' . mb_substr($in['text'], 0, 120));
  out(['ok' => true]);

 case 'incident/end':
  $inc = activeIncident(); if (!$inc || !canManage($inc)) fail('Nur Einsatzleitung, Administratoren oder die Person, die den Einsatz gestartet hat, können den Einsatz beenden', 403);
  $db->prepare("UPDATE incidents SET ended_at=? WHERE id=?")->execute([$now, $inc['id']]);
  logev($inc['id'], $uid, 'end', 'hat den Einsatz beendet');
  out(['ok' => true]);

 case 'incident/log': admin(); out(logRows((int)($_GET['id'] ?? 0), true));

 case 'stats': admin();
  $rows = array_map(fn($r) => ints($r, ['id', 'alarm_at', 'ended_at', 'created_by', 'is_exercise', 'accepted', 'declined', 'first_accept', 'first_area']), $db->query(
   "SELECT i.id,i.message,i.alarm_at,i.ended_at,i.created_by,i.is_exercise,u.name creator,
     (SELECT COUNT(*) FROM participants p WHERE p.incident_id=i.id AND p.mode<>'declined') accepted,
     (SELECT COUNT(*) FROM participants p WHERE p.incident_id=i.id AND p.mode='declined') declined,
     (SELECT MIN(ts) FROM incident_log l WHERE l.incident_id=i.id AND l.kind='accept') first_accept,
     (SELECT MIN(ts) FROM incident_log l WHERE l.incident_id=i.id AND l.kind='area') first_area
    FROM incidents i LEFT JOIN users u ON u.id=i.created_by ORDER BY i.id DESC")->fetchAll());
  $ended = array_filter($rows, fn($r) => $r['ended_at']);
  out(['incidents' => $rows, 'totals' => [
    'count' => count($rows), 'ended' => count($ended), 'exercises' => count(array_filter($rows, fn($r) => $r['is_exercise'])),
    'avg_duration' => avg(array_map(fn($r) => $r['ended_at'] - $r['alarm_at'], $ended)),
    'avg_accepted' => avg(array_column($rows, 'accepted')),
    'avg_first_accept' => avg(array_map(fn($r) => $r['first_accept'] === null ? null : $r['first_accept'] - $r['alarm_at'], $rows)),
    'avg_first_area' => avg(array_map(fn($r) => $r['first_area'] === null ? null : $r['first_area'] - $r['alarm_at'], $rows))]]);

 case 'me/password': // eigenes Passwort ändern (aktuelles Passwort nötig)
  $s = $db->prepare("SELECT pass_hash FROM users WHERE id=?"); $s->execute([$uid]);
  if (!password_verify($in['old'] ?? '', $s->fetch()['pass_hash'])) fail('Das aktuelle Passwort ist falsch', 403);
  pwCheck($in['new'] ?? '');
  if ($in['new'] === ($in['old'] ?? '')) fail('Das neue Passwort muss sich vom aktuellen unterscheiden');
  $db->prepare("UPDATE users SET pass_hash=?, must_change=0 WHERE id=?")->execute([password_hash($in['new'], PASSWORD_DEFAULT), $uid]);
  out(['ok' => true]);
 case 'me/settings': // Übungsmodus: Übungen wie scharfe Einsätze kritisch alarmieren
  $db->prepare("UPDATE users SET exercise_mode=? WHERE id=?")->execute([empty($in['exercise_mode']) ? 0 : 1, $uid]);
  out(['ok' => true]);

 /* ---------- Verwaltung (nur Benutzerrolle Administrator) ---------- */
 case 'users': admin();
  out(array_map(fn($r) => ints($r, ['id', 'is_admin', 'active', 'exercise_mode', 'must_change']), $db->query("SELECT id,username,name,is_admin,active,exercise_mode,must_change FROM users ORDER BY active DESC, name")->fetchAll()));
 case 'user/save': admin(); // anlegen oder Name/Benutzerrolle ändern
  $adm = empty($in['is_admin']) ? 0 : 1; $nm = trim($in['name'] ?? '');
  if (!empty($in['id'])) {
    $id = (int)$in['id'];
    // Die eigene Rolle bleibt bestehen, damit immer mindestens ein Administrator übrig ist
    if ($id === $uid && !$adm) fail('Die eigene Administratorrolle kann nicht entfernt werden');
    if ($nm === '') fail('Name fehlt');
    $db->prepare("UPDATE users SET name=?,is_admin=? WHERE id=?")->execute([$nm, $adm, $id]);
  } else {
    $un = trim($in['username'] ?? ''); if ($un === '') fail('Benutzername ist Pflicht');
    pwCheck($in['password'] ?? '');
    try { $db->prepare("INSERT INTO users(username,name,pass_hash,is_admin,must_change) VALUES(?,?,?,?,1)")->execute([$un, $nm ?: $un, password_hash($in['password'], PASSWORD_DEFAULT), $adm]); }
    catch (PDOException $e) { fail('Benutzername existiert bereits'); }
  }
  out(['ok' => true]);
 case 'user/password': admin(); // zurücksetzen: Person wird abgemeldet und muss bei der nächsten Anmeldung ein eigenes Passwort setzen
  $id = (int)($in['id'] ?? 0);
  if ($id === $uid) fail('Das eigene Passwort bitte über «Passwort ändern» setzen');
  pwCheck($in['password'] ?? '');
  $s = $db->prepare("UPDATE users SET pass_hash=?, must_change=1, token=NULL WHERE id=?"); $s->execute([password_hash($in['password'], PASSWORD_DEFAULT), $id]);
  if (!$s->rowCount()) fail('Benutzer nicht gefunden', 404);
  out(['ok' => true]);
 case 'user/active': admin(); // Benutzer entfernen (deaktivieren) oder reaktivieren, Einsatzdaten bleiben erhalten
  $id = (int)($in['id'] ?? 0); $a = empty($in['active']) ? 0 : 1;
  if ($id === $uid) fail('Das eigene Konto kann nicht deaktiviert werden');
  $s = $db->prepare("SELECT name FROM users WHERE id=?"); $s->execute([$id]); $u = $s->fetch();
  if (!$u) fail('Benutzer nicht gefunden', 404);
  $db->prepare("UPDATE users SET active=?" . ($a ? '' : ', token=NULL') . " WHERE id=?")->execute([$a, $id]);
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
 case 'role/save': admin();
  if (!empty($in['id'])) $db->prepare("UPDATE roles SET name=?,color=?,is_lead=?,is_post=?,active=? WHERE id=?")->execute([$in['name'], $in['color'], (int)$in['is_lead'], (int)($in['is_post'] ?? 0), (int)($in['active'] ?? 1), $in['id']]);
  else $db->prepare("INSERT INTO roles(name,color,is_lead) VALUES(?,?,?)")->execute([$in['name'], $in['color'] ?: '#888888', (int)($in['is_lead'] ?? 0)]);
  out(['ok' => true]);
}
fail('Unbekannte Route', 404);
