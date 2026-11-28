<?php
// Verbund eigenständiger Server (Issue #60): Vereinbarungen zwischen zwei Organisationen mit je eigener Installation.
// Jede Seite trägt die andere unter Verwaltung → Konnektivität → «Partnerorganisationen» ein (Adresse und gemeinsamer Schlüssel)
// und legt selbst fest, was die andere darf (Einsatzgruppen, Zuordnung der Einsatztypen). Anfragen zwischen den Servern sind
// signiert: X-Fed-Key (Kennung des Schlüssels), X-Fed-Ts (Unix-Zeit), X-Fed-Nonce (Einmalwert, 32 hex) und
// X-Fed-Sig = HMAC-SHA256(Schlüssel, Ts \n Nonce \n Route \n Body). Jeder Einmalwert gilt nur einmal (Tabelle fed_nonce, Issue #273),
// eine abgefangene Anfrage lässt sich also nicht wiederholen. Ältere Fassungen ohne Einmalwert werden abgelehnt.
// Ein gemeinsamer Einsatz hat eine auslösende Organisation (host); die übrigen sprechen nur mit ihr, sie leitet weiter (Stern).
// Wird von index.php vor der Anmeldeprüfung eingebunden; die Funktionen nutzen die Hilfsfunktionen aus index.php.

const FED_WIN = 120;      // erlaubte Zeitabweichung der Signatur in s (Issue #273: kürzer, Einmalwerte werden so lange gemerkt)
const FED_TO = 4;         // Wartezeit je Anfrage an einen Partner in s
const FED_STATE_S = 3;    // Lage eines Partners zwischengespeichert in s
const FED_DOWN_S = 15;    // nach einem Ausfall so lange nicht erneut fragen
const FED_OPT_S = 60;     // Einsatzgruppen und Typen eines Partners zwischengespeichert in s
const FED_RES_F = ['name', 'descr', 'lat', 'lon', 'loc_at', 'rescued_at', 'removed', 'pls_lat', 'pls_lon', 'pls_at']; // gemeinsame Felder der Rettlinge
$FED = null; // Partner einer signierten Anfrage (fed/*), sonst null

/* ---------- Partner und Signatur ---------- */
function fedName() { $n = orgInfo()['name'] ?? ''; return $n !== '' ? $n : 'Partnerorganisation'; } // eigener Name für die anderen
function fedSelf() { $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
  return ($https ? 'https://' : 'http://') . appBase(); }
function fedTag() { return 'f' . substr(sha1(appBase()), 0, 8); } // Kennung dieses Servers in Schlüsseln (Einsätze, Konferenz)
function fedKid($secret) { return substr(hash('sha256', (string)$secret), 0, 16); } // gleich auf beiden Seiten, verrät den Schlüssel nicht
// Adresse eines Partners: Basis der Installation (Ordner von index.html), https; http nur zu localhost und nur, wenn dieser Server selbst lokal läuft (Entwicklung, Issue #273)
function fedLocal($h) { return in_array(strtolower(trim((string)$h, '[]')), ['localhost', '127.0.0.1', '::1'], true); }
function fedUrl($u) { $u = trim((string)$u); if ($u === '') return null; $p = parse_url($u); if (!$p || empty($p['host']) || !in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true)) return null;
  $loc = fedLocal($p['host']) && fedLocal(parse_url('http://' . appBase(), PHP_URL_HOST)); if (strtolower($p['scheme']) !== 'https' && !$loc) return null;
  $path = $p['path'] ?? '/'; if (preg_match('~/[^/]*\.[a-z0-9]+$~i', $path)) $path = dirname($path) . '/'; // index.html, admin.html … weglassen
  $path = preg_replace('~/api/?$~', '/', rtrim($path, '/') . '/');
  return strtolower($p['scheme']) . '://' . $p['host'] . (isset($p['port']) ? ':' . (int)$p['port'] : '') . $path; }
function fedEp($p, $route) { return $p['url'] . 'api/index.php?r=' . $route; }
function fedRow($p) { if (!$p) return null; $p = ints($p, ['id', 'active', 'created', 'last_ok']);
  $g = json_decode((string)$p['grp'], true); $p['grp'] = is_array($g) ? array_values(array_map('intval', $g)) : null;
  $p['kinds'] = array_map('intval', json_decode((string)$p['kinds'], true) ?: []); $p['info'] = json_decode((string)$p['info'], true) ?: null;
  $p['last_err'] = json_decode((string)$p['last_err'], true) ?: null; return $p; }
function fedPartner($id) { global $db; $s = $db->prepare("SELECT * FROM fed_partners WHERE id=?"); $s->execute([(int)$id]); $r = $s->fetch() ?: null; $s->closeCursor(); return fedRow($r); }
function fedPartners($all = false) { global $db; return array_map('fedRow', $db->query("SELECT * FROM fed_partners" . ($all ? '' : ' WHERE active=1') . " ORDER BY name")->fetchAll()); }
function fedPub($p) { return ['id' => $p['id'], 'name' => $p['name'], 'url' => $p['url'], 'active' => $p['active'], 'grp' => $p['grp'], 'kinds' => (object)$p['kinds'], 'info' => $p['info'],
  'key_set' => $p['secret'] !== '' ? 1 : 0, 'kid' => $p['kid'], 'last_ok' => $p['last_ok'], 'last_err' => $p['last_err'], 'created' => $p['created']]; } // nie den Schlüssel

// Eingehende signierte Anfrage prüfen, liefert den Partner
function fedAuth() { global $db, $route;
  $kid = (string)($_SERVER['HTTP_X_FED_KEY'] ?? ''); $ts = (int)($_SERVER['HTTP_X_FED_TS'] ?? 0); $sig = (string)($_SERVER['HTTP_X_FED_SIG'] ?? ''); $nc = (string)($_SERVER['HTTP_X_FED_NONCE'] ?? '');
  $s = $db->prepare("SELECT * FROM fed_partners WHERE kid=? AND active=1"); $s->execute([$kid]); $p = $s->fetch() ?: null; $s->closeCursor();
  $okN = (bool)preg_match('/^[0-9a-f]{32}$/', $nc);
  $ok = $p && $p['secret'] !== '' && $okN && abs(time() - $ts) <= FED_WIN && hash_equals(hash_hmac('sha256', $ts . "\n" . $nc . "\n" . $route . "\n" . file_get_contents('php://input'), $p['secret']), $sig);
  if ($ok) { // Einmalwert merken; schon gesehen = Wiederholung
    $db->prepare("DELETE FROM fed_nonce WHERE at<?")->execute([time() - 2 * FED_WIN - 60]);
    try { $db->prepare("INSERT INTO fed_nonce(kid,n,at) VALUES(?,?,?)")->execute([$kid, $nc, time()]); }
    catch (Throwable $e) { alog('warn', 'fed', 'Wiederholte Anfrage eines Partners abgelehnt', ['route' => $route]); fail('Anfrage wurde bereits verarbeitet', 401); } }
  if (!$ok) { alog('warn', 'fed', 'Anfrage eines Partners abgelehnt', ['route' => $route, 'known' => $p ? 1 : 0, 'nonce' => $okN ? 1 : 0, 'skew' => $p && abs(time() - $ts) > FED_WIN ? 1 : 0]); fail('Ungültige Signatur oder unbekannter Partner', 401); }
  return fedRow($p); }

// Anfragen an Partner, gleichzeitig über curl_multi. $calls = [Schlüssel => [Partner, Route, Body]]; Rückgabe je Schlüssel {ok, code, data, err}
function fedMulti($calls, $to = FED_TO) { global $db, $now; if (!$calls) return []; $mh = curl_multi_init(); $hs = []; $pre = [];
  foreach ($calls as $i => [$p, $r, $b]) { $body = json_encode((object)$b, JSON_UNESCAPED_UNICODE); $ts = time(); $nc = bin2hex(random_bytes(16));
    [$ne, $no] = netCheck(fedEp($p, $r)); if ($ne) { $pre[$i] = $ne; continue; } // Issue #274
    $h = curl_init(fedEp($p, $r));
    curl_setopt_array($h, CURL_CA + $no + [CURLOPT_POST => 1, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => 1, CURLOPT_TIMEOUT => $to, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_USERAGENT => 'SwiftwaterRescue/fed',
      CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Fed-Key: ' . $p['kid'], 'X-Fed-Ts: ' . $ts, 'X-Fed-Nonce: ' . $nc, 'X-Fed-Sig: ' . hash_hmac('sha256', $ts . "\n" . $nc . "\n" . $r . "\n" . $body, $p['secret'])]]);
    curl_multi_add_handle($mh, $h); $hs[$i] = $h; }
  do { $st = curl_multi_exec($mh, $run); if ($run) curl_multi_select($mh, 0.5); } while ($run && $st === CURLM_OK);
  $out = []; foreach ($pre as $i => $e) $out[$i] = ['ok' => false, 'code' => 0, 'data' => null, 'err' => $e];
  foreach ($hs as $i => $h) { $c = (int)curl_getinfo($h, CURLINFO_HTTP_CODE); $d = json_decode((string)curl_multi_getcontent($h), true); $e = curl_error($h); curl_multi_remove_handle($mh, $h);
    $ok = $c === 200 && is_array($d); $p = $calls[$i][0]; $err = $ok ? null : ($c ? (is_array($d) && isset($d['error']) ? (string)$d['error'] : 'HTTP ' . $c) : ($e !== '' ? $e : 'keine Antwort'));
    $out[$i] = ['ok' => $ok, 'code' => $c, 'data' => $ok ? $d : null, 'err' => $err];
    // Stand je Partner (Verwaltung), Erfolg höchstens jede Minute geschrieben
    if ($ok && (int)$p['last_ok'] < $now - 60) $db->prepare("UPDATE fed_partners SET last_ok=? WHERE id=?")->execute([$now, $p['id']]);
    if (!$ok) { $db->prepare("UPDATE fed_partners SET last_err=? WHERE id=?")->execute([json_encode(['at' => $now, 'route' => $calls[$i][1], 'msg' => mb_substr($err, 0, 200)], JSON_UNESCAPED_UNICODE), $p['id']]);
      if ($calls[$i][1] !== 'fed/state') alog('warn', 'fed', 'Partner antwortet nicht', ['partner' => $p['id'], 'route' => $calls[$i][1], 'code' => $c, 'err' => mb_substr($err, 0, 120)]); } }
  curl_multi_close($mh); return $out; }
function fedCall($p, $r, $b = [], $to = FED_TO) { return fedMulti(['x' => [$p, $r, $b]], $to)['x']; }
function fedFile($name) { $f = __DIR__ . '/data/fed/' . $name . '.json'; return $f; }
function fedCacheGet($name, $ttl) { $c = json_decode((string)@file_get_contents(fedFile($name)), true); return is_array($c) && ($c['at'] ?? 0) >= time() - $ttl ? $c['v'] : null; }
function fedCachePut($name, $v, $at = null) { $f = fedFile($name); @mkdir(dirname($f), 0775, true); @file_put_contents($f, json_encode(['at' => $at ?? time(), 'v' => $v], JSON_UNESCAPED_UNICODE), LOCK_EX); }

/* ---------- Aufgebot über Partner ---------- */
// Einsatzgruppen und Typen eines Partners (was er uns erlaubt), 60 s zwischengespeichert; $people = Personen der Gruppen (Übungsleitung gemeinsamer Übungen)
function fedOptions($p, $people = false) { $n = 'opt' . $p['id'] . ($people ? 'p' : ''); $v = fedCacheGet($n, FED_OPT_S); if ($v !== null) return $v;
  $r = fedCall($p, 'fed/options', ['people' => $people ? 1 : 0]); $v = $r['ok'] ? $r['data'] : ['down' => 1];
  fedCachePut($n, $v, $r['ok'] ? null : time() - FED_OPT_S + FED_DOWN_S); return $v; }
// Partner, die wir aufbieten dürfen (start/options ext): [{org = Partner-ID, name, kinds {unser Typ: Typ dort}, groups, people?}]
function fedExtPartners($people = false) { $r = []; foreach (fedPartners() as $p) { $o = fedOptions($p, $people);
    if (empty($o['groups']) || empty((array)($o['kinds'] ?? []))) continue;
    $r[] = ['org' => $p['id'], 'name' => $p['name'], 'kinds' => (object)$o['kinds'], 'groups' => $o['groups']] + ($people ? ['people' => $o['people'] ?? []] : []); }
  return $r; }
// Auswahl {Partner-ID: [Gruppen-IDs]} für einen eigenen Einsatztyp prüfen: [[Partner-ID, Gruppen, null, Name], …] oder Fehlertext
function fedJointCheck($kid, $ext) { if (!is_array($ext) || !$ext) return []; $r = [];
  foreach ($ext as $o => $gs) { $gs = array_values(array_unique(array_filter(array_map('intval', (array)$gs), fn($x) => $x > 0))); if (!$gs) continue;
    $p = fedPartner($o); if (!$p || !$p['active']) return 'Diese Partnerorganisation darfst du nicht aufbieten';
    $op = fedOptions($p); if (!empty($op['down'])) return '«' . $p['name'] . '» ist im Moment nicht erreichbar';
    if (!isset(((array)($op['kinds'] ?? []))[(string)(int)$kid])) return 'Für diesen Einsatztyp gibt es keine Vereinbarung mit «' . $p['name'] . '»';
    if (array_diff($gs, array_column($op['groups'] ?? [], 'id'))) return 'Diese Einsatzgruppe von «' . $p['name'] . '» darfst du nicht aufbieten';
    $r[] = [(int)$p['id'], $gs, null, $p['name']]; }
  return $r; }

/* ---------- Gemeinsame Einsätze ---------- */
function fedJ($r) { if (!$r) return null; $r = ints($r, ['id', 'incident_id', 'host', 'host_partner', 'host_inc', 'ended_at', 'created']); $r['leads'] = array_map('intval', json_decode((string)$r['leads'], true) ?: []); return $r; }
function fedJointOf($incId) { global $db; $s = $db->prepare("SELECT * FROM fed_joint WHERE incident_id=?"); $s->execute([(int)$incId]); $r = $s->fetch() ?: null; $s->closeCursor(); return fedJ($r); }
// gemeinsamer Einsatz mit Schlüssel $jk, an dem der Partner $pid beteiligt ist (als auslösende Organisation oder als Mitglied bei uns)
function fedJointFor($jk, $pid) { global $db; $s = $db->prepare("SELECT j.* FROM fed_joint j WHERE j.jkey=? AND ((j.host=0 AND j.host_partner=?) OR (j.host=1 AND EXISTS (SELECT 1 FROM fed_joint_m m WHERE m.joint_id=j.id AND m.partner_id=?)))");
  $s->execute([(string)$jk, (int)$pid, (int)$pid]); $r = $s->fetch() ?: null; $s->closeCursor(); return fedJ($r); }
function fedMembers($jid) { global $db; $s = $db->prepare("SELECT partner_id,inc FROM fed_joint_m WHERE joint_id=? ORDER BY id"); $s->execute([(int)$jid]); return array_map(fn($r) => ints($r, ['partner_id', 'inc']), $s->fetchAll()); }
// Konten der Übungsleitung eines verknüpften Einsatzes bei uns (gemeinsame Übungen, Übungsleitung von der auslösenden Organisation eingetragen)
function fedJointLeads($incId) { static $c = []; $k = (int)$incId; if (!isset($c[$k])) { $j = fedJointOf($k); $c[$k] = $j ? $j['leads'] : []; } return $c[$k]; }
// Partner, mit denen wir sprechen: als auslösende Organisation alle Mitglieder, sonst nur die auslösende ($skip = nicht an diesen Partner)
function fedPeers($j, $skip = 0) { $r = []; if ($j['host']) { foreach (fedMembers($j['id']) as $m) if ($m['partner_id'] !== (int)$skip && ($p = fedPartner($m['partner_id'])) && $p['active']) $r[] = $p; }
  elseif ((int)$j['host_partner'] !== (int)$skip && ($p = fedPartner($j['host_partner'])) && $p['active']) $r[] = $p;
  return $r; }
// an alle Partner des gemeinsamen Einsatzes senden, nach der Antwort an das Gerät
function fedSend($j, $route, $body, $skip = 0) { $ps = fedPeers($j, $skip); if (!$ps) return; $body['jkey'] = $j['jkey'];
  after(function () use ($ps, $route, $body) { $c = []; foreach ($ps as $p) $c[$p['id']] = [$p, $route, $body]; fedMulti($c, 6); }); }
// Log-Eintrag ohne Konto, Person als Bezeichnung (actor)
function fedLog($incId, $kind, $actor, $text) { global $db, $now; $db->prepare("INSERT INTO incident_log(incident_id,ts,user_id,kind,text,actor) VALUES(?,?,NULL,?,?,?)")->execute([(int)$incId, $now, $kind, $text, $actor]); }

// Partner aufbieten (Start, Nachbieten, gemeinsame Übung): $list aus fedJointCheck, $leads = {Partner-ID: [Konten der Übungsleitung dort]}.
// Rückgabe: Aufgebot je Partner [{o, n, g, gn, c, a}]; Fehler je Partner stehen im Log.
function fedJointLink($inc, $list, $leads = [], $ex = false) { global $db, $now; if (!$list) return [];
  $j = fedJointOf($inc['id']);
  if ($j && !$j['host']) { logev((int)$inc['id'], null, 'invite', 'Weitere Organisationen kann nur die auslösende Organisation aufbieten'); return []; }
  if (!$j) { $db->prepare("INSERT INTO fed_joint(jkey,incident_id,host,created) VALUES(?,?,1,?)")->execute([bin2hex(random_bytes(12)), (int)$inc['id'], $now]); $j = fedJointOf($inc['id']); }
  $have = array_column(fedMembers($j['id']), 'inc', 'partner_id'); $seed = array_map('fedResOut', fedResAll((int)$inc['id'])); $calls = [];
  foreach ($list as [$pid, $gs, , $nm]) { $p = fedPartner($pid); if (!$p) continue; $ld = array_values(array_map('intval', $leads[$pid] ?? $leads[(string)$pid] ?? []));
    $calls[$pid] = [$p, 'fed/start', ['jkey' => $j['jkey'], 'inc' => (int)$inc['id'], 'kind' => (int)$inc['kind_id'], 'groups' => $gs, 'message' => $inc['message'], 'lat' => $inc['lat'] === null ? null : (float)$inc['lat'], 'lon' => $inc['lon'] === null ? null : (float)$inc['lon'],
      'pls_at' => $inc['pls_at'] === null ? null : (int)$inc['pls_at'], 'alarm_at' => (int)$inc['alarm_at'], 'res' => $seed, 'leads' => $ld, 'ex' => $ex ? 1 : 0]]; }
  $out = [];
  foreach (fedMulti($calls, 10) as $pid => $r) { $nm = $calls[$pid][0]['name'];
    if (!$r['ok']) { logev((int)$inc['id'], null, 'invite', '«' . $nm . '» konnte nicht aufgeboten werden: ' . $r['err']); continue; }
    $d = $r['data']; if (!isset($have[$pid])) $db->prepare("INSERT INTO fed_joint_m(joint_id,partner_id,inc,created) VALUES(?,?,?,?)")->execute([$j['id'], $pid, (int)$d['inc'], $now]);
    alog('info', 'fed', 'Partnerorganisation aufgeboten', ['id' => (int)$inc['id'], 'partner' => $pid, 'n' => (int)($d['c'] ?? 0)]);
    $out[] = ['o' => $pid, 'n' => $nm, 'g' => $calls[$pid][2]['groups'], 'gn' => array_values((array)($d['gn'] ?? [])), 'c' => (int)($d['c'] ?? 0), 'a' => (int)($d['a'] ?? 0)]; }
  return $out; }
// Aufgebot im eigenen Einsatz festhalten (inv_groups.x) und ins Log schreiben
function jointInvite($incId, $list, $leads = [], $ex = false) { global $db, $uid; $s = $db->prepare("SELECT * FROM incidents WHERE id=?"); $s->execute([(int)$incId]); $inc = $s->fetch(); $s->closeCursor();
  $r = fedJointLink($inc, $list, $leads, $ex); if (!$r) return [];
  $g = json_decode($inc['inv_groups'] ?? 'null', true) ?: []; $x = []; foreach ($g['x'] ?? [] as $o) $x[(int)$o['o']] = $o;
  foreach ($r as $o) { $p = $x[$o['o']] ?? ['o' => $o['o'], 'n' => $o['n'], 'g' => [], 'gn' => [], 'c' => 0];
    $p['g'] = array_values(array_unique(array_merge($p['g'], $o['g']))); $p['gn'] = array_values(array_unique(array_merge($p['gn'], $o['gn']))); $p['c'] += $o['c']; $p['n'] = $o['n']; $x[$o['o']] = $p;
    logev($inc['id'], $uid ?: null, 'invite', ($uid ? 'bietet' : 'Aufgebot') . ' bei «' . $o['n'] . '» auf: ' . implode(', ', $o['gn']) . ' (' . ($o['c'] === 1 ? '1 Person' : $o['c'] . ' Personen') . ($o['a'] ? ', davon ' . absText($o['a']) : '') . ', eigener Einsatz dort)'); }
  $g['x'] = array_values($x); $db->prepare("UPDATE incidents SET inv_groups=? WHERE id=?")->execute([json_encode($g, JSON_UNESCAPED_UNICODE), $inc['id']]); return $r; }

/* ---------- Gemeinsame Rettlinge ---------- */
function fedResKey(&$r) { global $db; if (empty($r['jkey'])) { $r['jkey'] = bin2hex(random_bytes(8)); $db->prepare("UPDATE rescuees SET jkey=? WHERE id=?")->execute([$r['jkey'], $r['id']]); } return $r; }
function fedResAll($inc) { global $db; $s = $db->prepare("SELECT * FROM rescuees WHERE incident_id=? AND removed IS NULL ORDER BY id"); $s->execute([(int)$inc]); return array_map('fedResKey', $s->fetchAll()); }
function fedResOut($r) { $o = ['jkey' => $r['jkey'], 'created' => (int)$r['created']]; foreach (FED_RES_F as $k) $o[$k] = $r[$k] ?? null; return $o; }
// Rettling im eigenen Einsatz anlegen oder nachführen (gleicher Rettling = gleicher Schlüssel jkey)
function fedResPut($incId, $r, $kind = '', $actor = '', $text = '') { global $db, $now; $jk = (string)($r['jkey'] ?? ''); if (!preg_match('/^[0-9a-f]{8,40}$/', $jk)) return;
  $s = $db->prepare("SELECT id,record FROM incidents WHERE id=? AND ended_at IS NULL"); $s->execute([(int)$incId]); $i = $s->fetch(); $s->closeCursor(); if (!$i) return;
  $s = $db->prepare("SELECT id,lat,lon FROM rescuees WHERE incident_id=? AND jkey=?"); $s->execute([(int)$incId, $jk]); $o = $s->fetch(); $s->closeCursor();
  $v = []; foreach (FED_RES_F as $k) { $x = $r[$k] ?? null; $v[] = $x === null ? null : (in_array($k, ['name', 'descr'], true) ? mb_substr((string)$x, 0, $k === 'name' ? 120 : 2000) : (in_array($k, ['lat', 'lon', 'pls_lat', 'pls_lon'], true) ? (float)$x : (int)$x)); }
  $nm = (string)($v[0] ?? ''); if ($nm === '') $v[0] = 'Rettling';
  if ($o) { $db->prepare("UPDATE rescuees SET " . implode(',', array_map(fn($k) => "$k=?", FED_RES_F)) . " WHERE id=?")->execute([...$v, $o['id']]); $id = (int)$o['id']; }
  else { $db->prepare("INSERT INTO rescuees(incident_id,jkey,created," . implode(',', FED_RES_F) . ") VALUES(?,?,?" . str_repeat(',?', count(FED_RES_F)) . ")")->execute([(int)$incId, $jk, (int)($r['created'] ?? $now), ...$v]); $id = (int)$db->lastInsertId(); }
  $moved = $o ? [(float)$o['lat'], (float)$o['lon'], $o['lat'] === null] != [(float)$v[2], (float)$v[3], $v[2] === null] : $v[2] !== null;
  if ($moved && (int)$i['record'] === 1) $db->prepare("INSERT INTO track(incident_id,rescuee_id,ts,lat,lon) VALUES(?,?,?,?,?)")->execute([(int)$incId, $id, $now, $v[2], $v[3]]);
  if ($text !== '') fedLog($incId, $kind, $actor, $text); }
// Änderung an einem Rettling eines gemeinsamen Einsatzes an die Partner weitergeben
function fedResSync($incId, $rid, $kind = '', $text = '') { global $db, $me; $j = fedJointOf((int)$incId); if (!$j || $j['ended_at']) return;
  $s = $db->prepare("SELECT * FROM rescuees WHERE id=?"); $s->execute([(int)$rid]); $r = $s->fetch(); $s->closeCursor(); if (!$r) return; fedResKey($r);
  fedSend($j, 'fed/res', ['r' => fedResOut($r), 'kind' => $kind, 'text' => $text, 'actor' => $me['name'] ?? '', 'org' => null]); }

/* ---------- Lage ---------- */
function fedKey($incId) { return fedTag() . '-' . (int)$incId; }
// eigene Lage eines Einsatzes für die Partner (wie incOther, dazu Einsatzkräfte, feste Standorte und Fahrzeuge); Rettlinge sind gemeinsam
function fedOwnState($incId) { global $db; $s = $db->prepare("SELECT * FROM incidents WHERE id=?"); $s->execute([(int)$incId]); $i = $s->fetch(); $s->closeCursor(); if (!$i) return null;
  $k = kindOf($i); $F = features($i['kind_id']); $o = incOther($i); $rn = $db->query("SELECT id,name FROM roles")->fetchAll(PDO::FETCH_KEY_PAIR);
  $o['ppl'] = array_map(fn($p) => ['id' => $p['id'], 'name' => $p['name'], 'role' => $p['role_id'] !== null ? ($rn[$p['role_id']] ?? null) : null, 'role_id' => null, 'lat' => $p['lat'], 'lon' => $p['lon']], $o['ppl']);
  $mn = $db->query("SELECT code,name FROM modes")->fetchAll(PDO::FETCH_KEY_PAIR);
  $s = $db->prepare("SELECT p.user_id,p.role_id,p.mode,p.lat,p.lon,p.loc_at,u.name,u.phone FROM participants p JOIN users u ON u.id=p.user_id WHERE p.incident_id=? AND p.mode<>'declined' AND p.left_at IS NULL ORDER BY u.name"); $s->execute([(int)$incId]);
  // Direktanruf: nur, wenn wir Nummern allen Teilnehmenden zeigen (who all); geprüft wird die Teilnahme beim Partner
  $pc = phoneCfg(); $ph = $F['call'] && ($pc['show'] || $pc['call']) && $pc['who'] === 'all'; if ($ph) $o['phone_cfg'] = ['show' => $pc['show'] ? 1 : 0, 'call' => $pc['call'] ? 1 : 0];
  $o['team'] = array_map(fn($p) => ['id' => (int)$p['user_id'], 'phone' => $ph ? ($p['phone'] ?? null) : null, 'name' => $p['name'], 'role' => $p['role_id'] !== null ? ($rn[$p['role_id']] ?? null) : null, 'mode' => $mn[$p['mode']] ?? null,
    'lat' => $p['lat'] !== null ? (float)$p['lat'] : null, 'lon' => $p['lon'] !== null ? (float)$p['lon'] : null, 'loc_at' => $p['loc_at'] !== null ? (int)$p['loc_at'] : null], $s->fetchAll());
  $o['res'] = []; $o['pls'] = array_values(array_filter($o['pls'], fn($p) => empty($p['own'])));
  return $o + ['k' => fedKey($incId), 'ended' => $i['ended_at'] !== null ? 1 : 0, 'message' => (int)$i['txt_anon'] ? '' : $i['message'], 'kind_name' => $k['name'], 'kind_color' => $k['color'],
    'places' => incPlaces($i), 'veh' => $F['veh'] && vehVisible($i) ? vehState($i, true) : null, 'msg' => $F['msg'] ? 1 : 0, 'conf' => voiceConfOn($i) ? 1 : 0]; }
// Lage der Mitglieder (nur auslösende Organisation), je 3 s zwischengespeichert, nach Ausfall 15 s Pause; $skip = Partner, der fragt
function fedMemberStates($j, $skip = 0) { $r = []; $ask = [];
  foreach (fedMembers($j['id']) as $m) { if ($m['partner_id'] === (int)$skip) continue; $p = fedPartner($m['partner_id']); if (!$p) continue;
    $c = fedCacheGet('s' . $j['id'] . '-' . $p['id'], FED_STATE_S); if ($c !== null) $r[$p['id']] = $c; elseif ($p['active']) $ask[$p['id']] = [$p, 'fed/state', ['jkey' => $j['jkey']]]; else $r[$p['id']] = ['id' => $m['inc'], 'k' => 'p' . $p['id'], 'down' => 1];
    $r[$p['id']]['_n'] = $p['name']; }
  foreach (fedMulti($ask) as $pid => $x) { $v = $x['ok'] && is_array($x['data']['self'] ?? null) ? $x['data']['self'] : ['id' => 0, 'k' => 'p' . $pid, 'down' => 1];
    fedCachePut('s' . $j['id'] . '-' . $pid, $v, $x['ok'] ? null : time() - FED_STATE_S + FED_DOWN_S); $r[$pid] = $v + ['_n' => $ask[$pid][0]['name']]; }
  $out = []; foreach ($r as $pid => $v) { $n = $v['_n']; unset($v['_n']); $out[] = ['org' => (int)$pid, 'org_name' => $n, 'joint' => 1, 'host' => 0] + $v; } return $out; }
// Nachrichten des gemeinsamen Kanals (bei der auslösenden Organisation gespeichert); $for = fragender Partner (0 = wir)
function fedMsgs($jid, $for = 0) { global $db; $s = $db->prepare("SELECT * FROM fed_jmsg WHERE joint_id=? ORDER BY id DESC LIMIT 200"); $s->execute([(int)$jid]); $me0 = fedName();
  return array_reverse(array_map(fn($m) => ['id' => (int)$m['id'], 'at' => (int)$m['at'], 'name' => $m['name'], 'text' => $m['text'], 'org_name' => (int)$m['src'] ? (string)$m['org'] : $me0,
    'own' => (int)$m['src'] === (int)$for ? 1 : 0, 'uid' => (int)$m['uid']], $s->fetchAll())); }
// Lage der Partner für incident/state: {list: Einträge für others, msgs: gemeinsamer Kanal, ended}
function fedJointOthers($j) { global $uid;
  if ($j['host']) { $msgs = array_map(fn($m) => $m + ['mine' => $m['own'] && $m['uid'] === $uid ? 1 : 0], fedMsgs($j['id'])); return ['list' => fedMemberStates($j), 'msgs' => $msgs]; }
  $p = fedPartner($j['host_partner']); if (!$p) return ['list' => [], 'msgs' => []]; $n = 'h' . $j['id'];
  $c = fedCacheGet($n, FED_STATE_S);
  if ($c === null) { $x = $p['active'] ? fedCall($p, 'fed/state', ['jkey' => $j['jkey']]) : ['ok' => false]; $c = $x['ok'] ? $x['data'] : ['down' => 1]; fedCachePut($n, $c, $x['ok'] ? null : time() - FED_STATE_S + FED_DOWN_S); }
  if (!empty($c['down']) || !is_array($c['self'] ?? null)) return ['list' => [['k' => 'p' . $p['id'], 'id' => $j['host_inc'], 'org' => $p['id'], 'org_name' => $p['name'], 'joint' => 1, 'host' => 1, 'down' => 1]], 'msgs' => []];
  $list = [['org' => $p['id'], 'org_name' => $p['name'], 'joint' => 1, 'host' => 1] + $c['self']];
  foreach ($c['others'] ?? [] as $o) $list[] = ['org' => 0, 'org_name' => $o['org_name'] ?? 'Partnerorganisation', 'joint' => 1, 'host' => 0] + $o;
  $msgs = array_map(fn($m) => $m + ['mine' => !empty($m['own']) && (int)$m['uid'] === $uid ? 1 : 0], $c['msgs'] ?? []);
  if (!empty($c['ended']) && !$j['ended_at']) { global $db; $db->prepare("UPDATE fed_joint SET ended_at=? WHERE id=?")->execute([time(), $j['id']]); }
  return ['list' => $list, 'msgs' => $msgs]; }
// Block joint für incident/state (Lage, gemeinsamer Kanal, Konferenz); $oth wird um die Lage der Partner ergänzt
function fedJointState($inc, $F, $canRead, &$oth) { $id = (int)$inc['id']; $j = fedJointOf($id); if (!$j) return null;
  $x = fedJointOthers($j); $jo = $x['list'];
  if (!isActivePart($id) && !canManage($inc)) foreach ($jo as &$jx) { unset($jx['phone_cfg']); foreach ($jx['team'] ?? [] as &$jp) $jp['phone'] = null; unset($jp); } unset($jx);
  $oth = array_merge($oth, $jo); $up = array_values(array_filter($jo, fn($o) => empty($o['down'])));
  $jmsg = $F['msg'] && array_filter($up, fn($o) => !empty($o['msg'])) ? 1 : 0; $jread = $jmsg && (isActivePart($id) || canManage($inc) || $canRead); $since = (int)($_GET['jm'] ?? 0);
  return ['id' => $j['id'], 'host' => $j['host'], 'ended' => $j['ended_at'] ? 1 : 0,
    'partners' => array_map(fn($o) => ['org' => (int)$o['org'], 'name' => $o['org_name'], 'inc' => (int)($o['id'] ?? 0), 'host' => (int)$o['host'], 'down' => empty($o['down']) ? 0 : 1, 'ended' => (int)($o['ended'] ?? 0), 'kind_name' => $o['kind_name'] ?? '', 'msg' => (int)($o['msg'] ?? 0), 'conf' => (int)($o['conf'] ?? 0)], $jo),
    'msg' => $jmsg, 'can_msg' => $jread && (canMessage($inc) || canManage($inc)) ? 1 : 0,
    'msgs' => $jread ? array_values(array_map(fn($m) => ['id' => $m['id'], 'at' => $m['at'], 'name' => $m['name'], 'text' => $m['text'], 'org_name' => $m['org_name'], 'mine' => $m['mine']], array_filter($x['msgs'], fn($m) => $m['id'] > $since))) : [],
    'conf' => $F['voice'] && (voiceConfOn($inc) || array_filter($up, fn($o) => !empty($o['conf']))) && (isActivePart($id) || canManage($inc)) ? 1 : 0]; }

/* ---------- Gemeinsamer Kanal, Konferenz, Ende ---------- */
function fedJmsg($inc, $text) { global $db, $me, $uid, $now; $j = fedJointOf($inc['id']); if (!$j || $j['ended_at']) fail('Kein gemeinsamer Einsatz', 404);
  if ($j['host']) { $db->prepare("INSERT INTO fed_jmsg(joint_id,src,uid,org,name,text,at) VALUES(?,0,?,NULL,?,?,?)")->execute([$j['id'], $uid, $me['name'], $text, $now]); $mid = (int)$db->lastInsertId(); }
  else { $p = fedPartner($j['host_partner']); $r = $p ? fedCall($p, 'fed/jmsg', ['jkey' => $j['jkey'], 'uid' => $uid, 'name' => $me['name'], 'text' => $text]) : ['ok' => false, 'err' => 'Partner fehlt'];
    if (!$r['ok']) fail('Die auslösende Organisation ist nicht erreichbar: ' . $r['err'], 502); $mid = (int)($r['data']['id'] ?? 0); @unlink(fedFile('h' . $j['id'])); }
  logev($inc['id'], $uid, 'jmsg', 'schreibt im gemeinsamen Kanal: ' . $text);
  if ($j['host']) fedSend($j, 'fed/log', ['kind' => 'jmsg', 'actor' => $me['name'], 'text' => 'schreibt im gemeinsamen Kanal: ' . $text, 'org' => null]);
  return $mid; }
// Token der gemeinsamen Konferenz: LiveKit der auslösenden Organisation, sonst des ersten Mitglieds mit Konferenz (alle im selben Raum)
function fedVoiceTok($j, $ident, $name, $speak, $direct = false) { $s = $GLOBALS['db']->prepare("SELECT * FROM incidents WHERE id=?"); $s->execute([$j['incident_id']]); $i = $s->fetch(); $s->closeCursor();
  if ($i && $i['ended_at'] === null && voiceConfOn($i)) { $room = 'swf' . $j['jkey']; return ['url' => voiceCfg()['lk']['url'], 'token' => voiceLkToken($room, $ident, $name, $speak), 'room' => $room]; }
  if ($direct) return null;
  if ($j['host']) { foreach (fedMembers($j['id']) as $m) { $p = fedPartner($m['partner_id']); if (!$p || !$p['active']) continue;
      $r = fedCall($p, 'fed/voice', ['jkey' => $j['jkey'], 'id' => $ident, 'name' => $name, 'speak' => $speak ? 1 : 0, 'direct' => 1]); if ($r['ok'] && !empty($r['data']['token'])) return $r['data']; } return null; }
  $p = fedPartner($j['host_partner']); if (!$p) return null; $r = fedCall($p, 'fed/voice', ['jkey' => $j['jkey'], 'id' => $ident, 'name' => $name, 'speak' => $speak ? 1 : 0]);
  return $r['ok'] && !empty($r['data']['token']) ? $r['data'] : null; }
function fedVoiceJoin($inc) { global $uid; $j = fedJointOf($inc['id']); if (!$j || $j['ended_at']) fail('Kein gemeinsamer Einsatz', 404);
  if (!isActivePart($inc['id']) && !canManage($inc)) fail('Nur Teilnehmende können beitreten', 403);
  $spk = (bool)array_filter(voiceMine($inc), fn($c) => $c['speak']) || canManage($inc);
  $lk = fedVoiceTok($j, fedTag() . 'u' . $uid, fedName() . ': ' . voiceAnonName($inc, $uid), $spk); if (!$lk) fail('Keine der Organisationen hat eine Live-Konferenz eingerichtet', 503);
  return $lk + ['speak' => $spk ? 1 : 0, 'muted' => 0]; }
// eigener Einsatz endet: als auslösende Organisation enden die Einsätze der Mitglieder mit, sonst nur ein Hinweis bei den anderen
function fedJointEnd($inc) { global $db, $now; $j = fedJointOf($inc['id']); if (!$j) return;
  if (!$j['host']) { fedSend($j, 'fed/log', ['kind' => 'end', 'actor' => '', 'text' => '«{org}» hat ihren Teil des gemeinsamen Einsatzes beendet', 'org' => null]); return; }
  $db->prepare("UPDATE fed_joint SET ended_at=? WHERE id=?")->execute([$now, $j['id']]); fedSend($j, 'fed/end', []); }
// Einsatz eines Mitglieds beenden (Auftrag der auslösenden Organisation), wie incident/end
function fedEndLocal($incId, $who) { global $db, $now; $s = $db->prepare("SELECT * FROM incidents WHERE id=? AND ended_at IS NULL"); $s->execute([(int)$incId]); $i = $s->fetch(); $s->closeCursor(); if (!$i) return;
  if ((int)$i['record']) setRec((int)$i['id'], 0); $db->prepare("UPDATE incidents SET ended_at=? WHERE id=?")->execute([$now, $i['id']]);
  logev($i['id'], null, 'end', '«' . $who . '» hat den gemeinsamen Einsatz beendet'); voiceCloseAll($i['id']); voiceEnd($i);
  $s = $db->prepare("SELECT user_id FROM participants WHERE incident_id=? AND mode<>'declined' AND left_at IS NULL"); $s->execute([$i['id']]); pushEnd($i, array_map('intval', array_column($s->fetchAll(), 'user_id'))); }
// Planungen der Partner, in denen ich als Übungsleitung eingetragen bin
function fedPartnerPlans() { global $uid; $c = []; foreach (fedPartners() as $p) $c[$p['id']] = [$p, 'fed/plans', ['uid' => $uid]]; $r = [];
  foreach (fedMulti($c) as $pid => $x) if ($x['ok']) foreach ((array)($x['data']['plans'] ?? []) as $pl) $r[] = $pl + ['org' => $pid, 'org_name' => $c[$pid][0]['name']];
  return $r; }

/* ---------- Signierte Routen (Anfragen anderer Server) ---------- */
function fedServe($route) { global $db, $in, $now, $FED; $P = $FED;
  $jk = (string)($in['jkey'] ?? ''); $J = fn() => fedJointFor($jk, $P['id']) ?: fail('Gemeinsamer Einsatz nicht gefunden', 404);
  switch ($route) {
  case 'fed/hello': // Verbindung testen: Name, Version, aktive Einsatztypen, erlaubte Einsatzgruppen
    $gr = array_values(array_filter(grpRows(true), fn($g) => $P['grp'] === null || in_array((int)$g['id'], $P['grp'], true)));
    out(['name' => fedName(), 'version' => verPub()['version'] ?? '', 'kinds' => array_values(array_map(fn($k) => ['id' => (int)$k['id'], 'name' => $k['name'], 'color' => $k['color'], 'plans' => $k['plans'] ? 1 : 0], kindsActive())),
      'groups' => array_map(fn($g) => ['id' => (int)$g['id'], 'name' => $g['name']], $gr), 'mapped' => count($P['kinds'])]);
  case 'fed/options': // was der Partner bei uns aufbieten darf: Gruppen (mit Anzahl, Abwesende), Typen {Typ beim Partner: Name des Typs hier}, mit people die Personen der Gruppen
    $ks = kinds(); $kn = []; foreach ($P['kinds'] as $a => $b) if (isset($ks[$b]) && $ks[$b]['active'] && !$ks[$b]['deleted']) $kn[(string)$a] = $ks[$b]['name'];
    $ab = absentIds(); $gs = [];
    foreach (grpRows(true) as $g) { if ($P['grp'] !== null && !in_array((int)$g['id'], $P['grp'], true)) continue; $m = grpMembers([(int)$g['id']]);
      $gs[] = ['id' => (int)$g['id'], 'name' => $g['name'], 'color' => $g['color'], 'n' => count($m), 'na' => count(array_intersect($m, $ab))]; }
    $pp = []; if (!empty($in['people']) && $gs && ($m = grpMembers(array_column($gs, 'id')))) { $s = $db->prepare("SELECT id,name FROM users WHERE active=1 AND purged=0 AND id IN (" . implode(',', array_fill(0, count($m), '?')) . ") ORDER BY name"); $s->execute($m);
      $pp = array_map(fn($x) => ['gid' => (int)$x['id'], 'name' => $x['name']], $s->fetchAll()); }
    out(['kinds' => (object)$kn, 'groups' => $gs] + (!empty($in['people']) ? ['people' => $pp] : []));
  case 'fed/start': // Aufgebot eines Partners: eigenen, verknüpften Einsatz anlegen bzw. nachbieten
    if (!preg_match('/^[0-9a-f]{16,40}$/', $jk)) fail('Ungültiger Schlüssel');
    $bk = $P['kinds'][(string)(int)($in['kind'] ?? 0)] ?? null; $k = $bk ? (kinds()[$bk] ?? null) : null;
    if (!$k || !$k['active'] || $k['deleted']) fail('Für diesen Einsatztyp gibt es keine Vereinbarung', 403);
    $gs = array_values(array_unique(array_filter(array_map('intval', (array)($in['groups'] ?? [])), fn($x) => $x > 0)));
    if ($P['grp'] !== null && array_diff($gs, $P['grp'])) fail('Diese Einsatzgruppen dürft ihr nicht aufbieten', 403);
    $gn = array_column(grpRows(true), 'name', 'id'); $gs = array_values(array_filter($gs, fn($g) => isset($gn[$g]))); if (!$gs) fail('Die Einsatzgruppen sind hier nicht aktiv');
    $ld = array_values(array_unique(array_map('intval', (array)($in['leads'] ?? [])))); $lu = [];
    if ($ld) { $s = $db->prepare("SELECT id FROM users WHERE active=1 AND id IN (" . implode(',', array_fill(0, count($ld), '?')) . ")"); $s->execute($ld); $lu = array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN)); }
    $ids = array_values(array_diff(grpMembers($gs), $lu)); $abs = array_values(array_intersect(absentIds(), $ids)); $txt = implode(', ', array_map(fn($g) => $gn[$g], $gs)); $gnl = array_map(fn($g) => $gn[$g], $gs);
    $s = $db->prepare("SELECT * FROM fed_joint WHERE jkey=? AND host=0 AND host_partner=?"); $s->execute([$jk, $P['id']]); $j = fedJ($s->fetch() ?: null); $s->closeCursor();
    if ($j) { $s = $db->prepare("SELECT * FROM incidents WHERE id=? AND ended_at IS NULL"); $s->execute([$j['incident_id']]); $b = $s->fetch(); $s->closeCursor(); if (!$b) fail('Der verknüpfte Einsatz ist hier schon beendet', 409);
      $old = json_decode((string)$b['invite'], true); $old = is_array($old) ? array_map('intval', $old) : null; $new = $old === null ? [] : array_values(array_diff($ids, $old));
      $ig = json_decode((string)$b['inv_groups'], true) ?: ['g' => [], 'p' => []]; $ig['g'] = array_values(array_unique(array_merge($ig['g'] ?? [], $gs)));
      $ia = array_values(array_unique(array_merge(array_map('intval', json_decode($b['inv_absent'] ?? '[]', true) ?: []), array_intersect($abs, $new))));
      if ($old !== null) $db->prepare("UPDATE incidents SET invite=?, inv_groups=?, inv_absent=? WHERE id=?")->execute([json_encode(array_values(array_unique(array_merge($old, $ids)))), json_encode($ig), $ia ? json_encode($ia) : null, $b['id']]);
      if ($lu) $db->prepare("UPDATE fed_joint SET leads=? WHERE id=?")->execute([json_encode(array_values(array_unique(array_merge($j['leads'], $lu)))), $j['id']]);
      $na = array_values(array_diff($new, $abs)); $nab = count(array_intersect($abs, $new));
      logev($b['id'], null, 'invite', '«' . $P['name'] . '» bietet nach: ' . $txt . ' (' . (count($na) === 1 ? '1 weitere Person' : count($na) . ' weitere Personen') . ($nab ? ', dazu ' . absText($nab) : '') . ')');
      if ($na) pushAlarm($b, [], $na); out(['inc' => (int)$b['id'], 'c' => count($na), 'a' => $nab, 'gn' => $gnl]); }
    $msg = mb_substr((string)($in['message'] ?? ''), 0, 500) . ' (gemeinsam mit «' . $P['name'] . '»)'; $air = airCfg()['on'] ? airDef($bk) : null; $rec = $k['rec'] && features($bk)['rec'] ? 1 : 0;
    $la = isset($in['lat']) && $in['lat'] !== null ? (float)$in['lat'] : null; $lo = $la !== null && isset($in['lon']) ? (float)$in['lon'] : null; if ($lo === null) $la = null;
    $db->prepare("INSERT INTO incidents(message,lat,lon,pls_at,alarm_at,created_by,is_exercise,air,kind_id,invite,inv_groups,inv_absent) VALUES(?,?,?,?,?,NULL,?,?,?,?,?,?)")
      ->execute([$msg, $la, $lo, isset($in['pls_at']) && $in['pls_at'] !== null ? (int)$in['pls_at'] : null, (int)($in['alarm_at'] ?? $now) ?: $now, $k['plans'] ? 1 : 0, $air, $bk, json_encode($ids), json_encode(['g' => $gs, 'p' => []]), $abs ? json_encode($abs) : null]);
    $nid = (int)$db->lastInsertId(); if ($rec) setRec($nid, 1);
    $db->prepare("INSERT INTO fed_joint(jkey,incident_id,host,host_partner,host_inc,leads,created) VALUES(?,?,0,?,?,?,?)")->execute([$jk, $nid, $P['id'], (int)($in['inc'] ?? 0), $lu ? json_encode($lu) : null, $now]);
    foreach ((array)($in['res'] ?? []) as $sr) if (is_array($sr)) fedResPut($nid, $sr); // bestehende Rettlinge übernehmen (gemeinsam)
    $ex = !empty($in['ex']);
    logev($nid, null, 'start', '«' . $P['name'] . '» hat ' . ($ex ? 'die gemeinsame Übung' : 'den gemeinsamen Einsatz') . ' ausgelöst (' . $k['name'] . ($rec ? ', mit Aufzeichnung' : '') . '): ' . mb_substr((string)($in['message'] ?? ''), 0, 500));
    logev($nid, null, 'invite', 'aufgeboten: ' . $txt . ' (' . (count($ids) === 1 ? '1 Person' : count($ids) . ' Personen') . ($abs ? ', davon ' . absText(count($abs)) : '') . ')');
    alog('info', 'fed', 'Gemeinsamer Einsatz von einem Partner ausgelöst', ['id' => $nid, 'partner' => $P['id'], 'n' => count($ids)]);
    $s = $db->prepare("SELECT * FROM incidents WHERE id=?"); $s->execute([$nid]); $b = $s->fetch(); $s->closeCursor(); pushAlarm($b, array_merge($abs, $lu), $ids);
    out(['inc' => $nid, 'c' => count(array_diff($ids, $abs)), 'a' => count($abs), 'gn' => $gnl]);
  case 'fed/state': // Lage unseres Einsatzes; als auslösende Organisation dazu die der übrigen Mitglieder und der gemeinsame Kanal
    $j = $J(); $self = fedOwnState($j['incident_id']); if (!$self) fail('Einsatz nicht gefunden', 404);
    out(['self' => $self] + ($j['host'] ? ['others' => fedMemberStates($j, $P['id']), 'msgs' => fedMsgs($j['id'], $P['id']), 'ended' => $j['ended_at'] ? 1 : 0] : []));
  case 'fed/res': // Rettling eines gemeinsamen Einsatzes nachführen; die auslösende Organisation leitet weiter
    $j = $J(); $r = (array)($in['r'] ?? []); $lbl = (string)($in['org'] ?? '') !== '' ? (string)$in['org'] : $P['name']; $a = trim((string)($in['actor'] ?? ''));
    fedResPut($j['incident_id'], $r, (string)($in['kind'] ?? ''), $a !== '' ? mb_substr($a, 0, 80) . ' (' . $lbl . ')' : $lbl, mb_substr((string)($in['text'] ?? ''), 0, 500));
    if ($j['host']) fedSend($j, 'fed/res', ['r' => $r, 'kind' => (string)($in['kind'] ?? ''), 'text' => (string)($in['text'] ?? ''), 'actor' => $a, 'org' => $lbl], $P['id']);
    out(['ok' => true]);
  case 'fed/log': // Log-Eintrag im eigenen verknüpften Einsatz (gemeinsamer Kanal, Ende eines Teils)
    $j = $J(); $lbl = (string)($in['org'] ?? '') !== '' ? (string)$in['org'] : $P['name']; $a = trim((string)($in['actor'] ?? '')); $k = in_array($in['kind'] ?? '', ['jmsg', 'end', 'invite'], true) ? $in['kind'] : 'jmsg';
    fedLog($j['incident_id'], $k, $a !== '' ? mb_substr($a, 0, 80) . ' («' . $lbl . '»)' : 'System', str_replace('{org}', $lbl, mb_substr((string)($in['text'] ?? ''), 0, 2100)));
    if ($j['host']) fedSend($j, 'fed/log', ['kind' => $k, 'actor' => $a, 'text' => (string)($in['text'] ?? ''), 'org' => $lbl], $P['id']);
    out(['ok' => true]);
  case 'fed/jmsg': // Nachricht eines Mitglieds im gemeinsamen Kanal (nur bei der auslösenden Organisation)
    $j = $J(); if (!$j['host']) fail('Nur bei der auslösenden Organisation', 409); if ($j['ended_at']) fail('Der gemeinsame Einsatz ist beendet', 409);
    $t = mb_substr(trim((string)($in['text'] ?? '')), 0, 2000); $nm = mb_substr(trim((string)($in['name'] ?? '')), 0, 80); if ($t === '') fail('Kein Text');
    $db->prepare("INSERT INTO fed_jmsg(joint_id,src,uid,org,name,text,at) VALUES(?,?,?,?,?,?,?)")->execute([$j['id'], $P['id'], (int)($in['uid'] ?? 0), $P['name'], $nm, $t, $now]); $mid = (int)$db->lastInsertId();
    fedLog($j['incident_id'], 'jmsg', $nm . ' («' . $P['name'] . '»)', 'schreibt im gemeinsamen Kanal: ' . $t);
    fedSend($j, 'fed/log', ['kind' => 'jmsg', 'actor' => $nm, 'text' => 'schreibt im gemeinsamen Kanal: ' . $t, 'org' => $P['name']], $P['id']);
    out(['ok' => true, 'id' => $mid]);
  case 'fed/end': // die auslösende Organisation hat den gemeinsamen Einsatz beendet
    $j = $J(); if ($j['host'] || $j['host_partner'] !== $P['id']) fail('Nur die auslösende Organisation kann beenden', 403);
    $db->prepare("UPDATE fed_joint SET ended_at=? WHERE id=?")->execute([$now, $j['id']]); fedEndLocal($j['incident_id'], $P['name']); out(['ok' => true]);
  case 'fed/voice': // Token der gemeinsamen Konferenz (direct = nur aus dem eigenen LiveKit)
    $j = $J(); if ($j['ended_at']) fail('Der gemeinsame Einsatz ist beendet', 409);
    $id = preg_replace('/[^a-z0-9]/i', '', (string)($in['id'] ?? '')); $nm = mb_substr((string)($in['name'] ?? ''), 0, 80); if ($id === '') fail('Keine Kennung');
    $lk = fedVoiceTok($j, $id, $nm, !empty($in['speak']), !empty($in['direct']) || !$j['host']); if (!$lk) fail('Keine Live-Konferenz eingerichtet', 503); out($lk);
  case 'fed/plans': // gemeinsame Übungen, in denen die Person des Partners (uid) als Übungsleitung eingetragen ist (nur lesend)
    $g = (int)($in['uid'] ?? 0); $out = [];
    foreach ($db->query("SELECT * FROM ex_plans WHERE active=1 AND jleads IS NOT NULL ORDER BY COALESCE(plan_at, created) DESC")->fetchAll() as $p) {
      $jl = json_decode((string)$p['jleads'], true) ?: []; if (!in_array($g, array_map('intval', (array)($jl[(string)$P['id']] ?? [])), true)) continue;
      $inc = null; if ($p['incident_id'] !== null) { $s = $db->prepare("SELECT id,ended_at FROM incidents WHERE id=?"); $s->execute([(int)$p['incident_id']]); $inc = $s->fetch() ?: null; $s->closeCursor(); }
      $lu = exLeads($p); $ln = $lu ? $db->query("SELECT name FROM users WHERE id IN (" . implode(',', $lu) . ") ORDER BY name")->fetchAll(PDO::FETCH_COLUMN) : [];
      $out[] = ['id' => (int)$p['id'], 'title' => $p['title'], 'message' => $p['message'], 'kind_name' => kindOf($p)['name'], 'kind_color' => kindOf($p)['color'], 'plan_at' => $p['plan_at'] === null ? null : (int)$p['plan_at'], 'plan_end' => $p['plan_end'] === null ? null : (int)$p['plan_end'],
        'plan_loc' => $p['plan_loc'], 'plan_info' => $p['plan_info'], 'leads' => $ln, 'state' => !$inc ? 'plan' : ($inc['ended_at'] === null ? 'run' : 'ended'),
        'injects' => array_map(fn($x) => ['kind' => $x['kind'], 'name' => $x['name'] ?? '', 'auto' => $x['auto'] ?? null, 'released' => $x['released'] ? 1 : 0], exInjects((int)$p['id']))]; }
    out(['plans' => $out]);
  }
  fail('Unbekannte Route', 404); }

/* ---------- Verwaltung (angemeldet, Recht cfg_conn) ---------- */
function fedSecretNew() { return 'swf1_' . bin2hex(random_bytes(24)); }
function fedAdmin($route) { global $db, $in, $now; need('cfg_conn');
  switch ($route) {
  case 'fed/cfg': // eigene Adresse, Partner (ohne Schlüssel), eigene Einsatztypen und Gruppen für die Zuordnung
    out(['self' => fedSelf(), 'name' => fedName(), 'partners' => array_map('fedPub', fedPartners(true)),
      'kinds' => array_values(array_map(fn($k) => ['id' => (int)$k['id'], 'name' => $k['name'], 'color' => $k['color']], kindsActive())),
      'groups' => array_map(fn($g) => ['id' => (int)$g['id'], 'name' => $g['name'], 'color' => $g['color']], grpRows(true))]);
  case 'fed/partner/save': // id (fehlt = neu), name, url, active, grp (null = alle), kinds {Typ beim Partner: unser Typ}, key = 'new' (erzeugen, einmal angezeigt) oder secret (erhaltener Schlüssel)
    $p = !empty($in['id']) ? (fedPartner($in['id']) ?: fail('Partner nicht gefunden', 404)) : null;
    $name = mb_substr(trim((string)($in['name'] ?? ($p['name'] ?? ''))), 0, 80); if ($name === '') fail('Bitte einen Namen eingeben');
    $url = array_key_exists('url', $in) ? fedUrl($in['url']) : ($p['url'] ?? null); if (!$url) fail('Bitte die Adresse der Installation eingeben (https://…/)');
    if (rtrim($url, '/') === rtrim(fedSelf(), '/')) fail('Das ist die Adresse dieser Installation');
    $grp = array_key_exists('grp', $in) ? ($in['grp'] === null ? null : array_values(array_intersect(array_map('intval', (array)$in['grp']), array_map(fn($g) => (int)$g['id'], grpRows(true))))) : ($p['grp'] ?? null);
    $ks = kinds(); $kn = array_key_exists('kinds', $in) ? [] : ($p['kinds'] ?? []);
    if (array_key_exists('kinds', $in)) foreach ((array)$in['kinds'] as $a => $b) { $a = (int)$a; $b = (int)$b; if ($a > 0 && $b > 0 && isset($ks[$b]) && $ks[$b]['active'] && !$ks[$b]['deleted']) $kn[(string)$a] = $b; }
    $sec = null; $gen = null;
    if (($in['key'] ?? '') === 'new') $sec = $gen = fedSecretNew();
    elseif (($in['secret'] ?? '') !== '') { $sec = trim((string)$in['secret']); if (!preg_match('/^swf1_[0-9a-f]{48}$/', $sec)) fail('Der Schlüssel hat nicht das erwartete Format (swf1_…)'); }
    if (!$p && $sec === null) fail('Bitte einen Schlüssel erzeugen oder den erhaltenen eingeben');
    if ($sec !== null) { $s = $db->prepare("SELECT id FROM fed_partners WHERE kid=? AND id<>?"); $s->execute([fedKid($sec), (int)($p['id'] ?? 0)]); if ($s->fetch()) fail('Dieser Schlüssel gehört schon zu einem anderen Partner'); $s->closeCursor(); }
    $act = array_key_exists('active', $in) ? (empty($in['active']) ? 0 : 1) : ($p['active'] ?? 1);
    if ($p) { $db->prepare("UPDATE fed_partners SET name=?, url=?, active=?, grp=?, kinds=? WHERE id=?")->execute([$name, $url, $act, $grp === null ? null : json_encode($grp), json_encode((object)$kn), $p['id']]); $id = $p['id'];
      if ($sec !== null) $db->prepare("UPDATE fed_partners SET secret=?, kid=? WHERE id=?")->execute([$sec, fedKid($sec), $id]); }
    else { $db->prepare("INSERT INTO fed_partners(name,url,secret,kid,active,grp,kinds,created) VALUES(?,?,?,?,?,?,?,?)")->execute([$name, $url, $sec, fedKid($sec), $act, $grp === null ? null : json_encode($grp), json_encode((object)$kn), $now]); $id = (int)$db->lastInsertId(); }
    foreach (glob(__DIR__ . '/data/fed/opt' . $id . '*.json') ?: [] as $f) @unlink($f);
    alog('info', 'fed', $p ? 'Partnerorganisation geändert' : 'Partnerorganisation eingetragen', ['id' => $id, 'key' => $sec !== null ? ($gen ? 'neu' : 'eingegeben') : 'unverändert']);
    out(['ok' => true, 'id' => $id] + ($gen ? ['secret' => $gen] : []));
  case 'fed/partner/test': // Verbindung prüfen (fed/hello), Angaben des Partners merken
    $p = fedPartner($in['id'] ?? 0) ?: fail('Partner nicht gefunden', 404); if ($p['secret'] === '') fail('Für diesen Partner ist kein Schlüssel eingetragen');
    $r = fedCall($p, 'fed/hello', []); if (!$r['ok']) fail('Keine Verbindung: ' . $r['err'] . ($r['code'] === 401 ? ' (beim Partner ist diese Installation nicht mit demselben Schlüssel eingetragen, oder die Uhrzeit weicht ab)' : ''), 502);
    $info = ['name' => mb_substr((string)($r['data']['name'] ?? ''), 0, 120), 'version' => (string)($r['data']['version'] ?? ''), 'kinds' => array_slice((array)($r['data']['kinds'] ?? []), 0, 100), 'groups' => array_slice((array)($r['data']['groups'] ?? []), 0, 200), 'mapped' => (int)($r['data']['mapped'] ?? 0), 'at' => $now];
    $db->prepare("UPDATE fed_partners SET info=?, last_ok=?, last_err=NULL WHERE id=?")->execute([json_encode($info, JSON_UNESCAPED_UNICODE), $now, $p['id']]);
    foreach (glob(__DIR__ . '/data/fed/opt' . $p['id'] . '*.json') ?: [] as $f) @unlink($f);
    out(['ok' => true, 'info' => $info]);
  case 'fed/partner/remove':
    $p = fedPartner($in['id'] ?? 0) ?: fail('Partner nicht gefunden', 404);
    $s = $db->prepare("SELECT COUNT(*) FROM fed_joint j JOIN incidents i ON i.id=j.incident_id WHERE i.ended_at IS NULL AND (j.host_partner=? OR EXISTS (SELECT 1 FROM fed_joint_m m WHERE m.joint_id=j.id AND m.partner_id=?))"); $s->execute([$p['id'], $p['id']]);
    if ((int)$s->fetchColumn()) fail('Mit diesem Partner läuft ein gemeinsamer Einsatz; zuerst deaktivieren oder den Einsatz beenden');
    $db->prepare("DELETE FROM fed_partners WHERE id=?")->execute([$p['id']]); foreach (glob(__DIR__ . '/data/fed/opt' . $p['id'] . '*.json') ?: [] as $f) @unlink($f);
    alog('info', 'fed', 'Partnerorganisation entfernt', ['id' => $p['id']]); out(['ok' => true]);
  }
  fail('Unbekannte Route', 404); }
