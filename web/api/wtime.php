<?php
/* ---------- Wasserzeit-Überwachung (Issue #241) ----------
 Eingebunden von index.php nach der Dokumentenablage. Ähnlich der Atemschutzüberwachung: Wer im Wasser arbeitet (Swimmer, Bootsbesatzung im Wasser),
 soll nicht unbemerkt zu lange darin bleiben (Unterkühlung). Funktion «wtime» in FEATURES (je Einsatztyp), Routen in FEATURE_ROUTES.
 Richtwert in Minuten je Kälteschutz (WT_SUITS) und Wassertemperatur (Stufen bands), Einstellung wtime (Verwaltung → Funktionen → «Wasserzeit», Recht cfg_func).
 Temperatur des Einsatzes: von Hand gesetzt (incidents.wt_temp) vor der nächsten BAFU-Station mit Wassertemperatur (incidents.wt_bafu, nach der Antwort
 höchstens alle WT_BAFU_S nachgeführt), ohne beides gilt die kälteste Stufe. Der Richtwert laufender Wasserzeiten folgt der Temperatur des Einsatzes.
 Start/Stopp von Hand (selbst oder durch die Einsatzleitung) oder automatisch (auto): Das Gerät meldet mit incident/location den Abstand zur Gewässerachse
 (wd, nur mit geladenem Gewässernetz); liegt er auto_s lang unter auto_m, ohne Fahrzeug und ohne Fahrt, beginnt die Wasserzeit (ab dem ersten Wert).
 Automatisch begonnene enden, wenn das Gerät stop_s lang ausserhalb meldet. Warnungen bei warn % und 100 % (Push an die Person und die Einsatzleitung,
 Log, alerts in incident/state). Tabelle water_time; Auswertung in der Statistik pro Person (psRows: wt, wt_n) und im After Action Review (wtDoc). */

foreach ([
  // part_id = participants.id (bleibt beim Anonymisieren erhalten); src manual|bafu|none; warned 0, 1 = warn %, 2 = 100 %; auto = automatisch erkannt;
  // by_start/by_end = wer gestartet bzw. beendet hat (NULL = automatisch), end_auto = automatisch beendet
  "CREATE TABLE IF NOT EXISTS water_time(id $ai, incident_id INT, part_id INT, start_at BIGINT, end_at BIGINT NULL, suit $tx, temp DOUBLE NULL, src $tx, src_txt TEXT NULL, lim_s INT,
    warned INT DEFAULT 0, w1_at BIGINT NULL, w2_at BIGINT NULL, auto INT DEFAULT 0, by_start INT NULL, by_end INT NULL, end_auto INT DEFAULT 0)",
] as $s) $db->exec($s);
try { $db->exec("CREATE INDEX water_time_inc ON water_time(incident_id,end_at)"); } catch (Throwable $e) {}
// incidents: von Hand gesetzte Temperatur und zuletzt ermittelte der BAFU-Station; participants: Erkennung (wt_wet = seit im Wasser, wt_dry = seit ausserhalb) und zuletzt gewählter Kälteschutz
foreach (['incidents' => ['wt_temp DOUBLE NULL', 'wt_temp_at BIGINT NULL', 'wt_temp_by INT NULL', 'wt_bafu DOUBLE NULL', 'wt_bafu_txt TEXT NULL', 'wt_bafu_at BIGINT NULL'],
  'participants' => ['wt_wet BIGINT NULL', 'wt_dry BIGINT NULL', 'wt_suit TEXT NULL']] as $t => $cs) foreach ($cs as $c) { try { $db->exec("ALTER TABLE $t ADD COLUMN $c"); } catch (Throwable $e) {} }

// Kälteschutz: Schlüssel => [Bezeichnung, Erklärung]
const WT_SUITS = ['none' => ['Ohne Kälteschutz', 'Badekleidung oder Alltagskleidung'], 'wet' => ['Neoprenanzug', 'Nassanzug (z. B. 5–7 mm) mit Haube, Handschuhen und Füsslingen'], 'dry' => ['Trockenanzug', 'Trockenanzug mit wärmender Unterbekleidung']];
// Vorschlag (Issue #241): Stufen < 5 °C, 5–10, 10–15, 15–20, ab 20 °C; Minuten je Kälteschutz. Konservative Richtwerte für aktive Arbeit in der Strömung,
// mit den Ausbildungsunterlagen der Organisation abgleichen. Anstrengung, Wind, Erschöpfung und wiederholte Einsätze verkürzen die Zeit.
const WT_DEFAULT = ['bands' => [5, 10, 15, 20], 'min' => ['none' => [5, 10, 15, 30, 45], 'wet' => [15, 20, 30, 45, 60], 'dry' => [30, 45, 60, 75, 90]], 'off' => [], 'def' => 'wet',
  'warn' => 75, 'lvl' => 'high', 'auto' => 1, 'auto_s' => 60, 'stop_s' => 120, 'auto_m' => 20, 'radius_km' => 20];
const WT_NUM = ['warn' => [50, 95, 'Vorwarnung bei (Prozent des Richtwerts)'], 'auto_s' => [10, 600, 'Im Wasser erkannt nach (s)'], 'stop_s' => [30, 1800, 'An Land erkannt nach (s)'],
  'auto_m' => [5, 80, 'Höchstens so weit von der Gewässerachse (m)'], 'radius_km' => [1, 100, 'BAFU-Station mit Wassertemperatur im Umkreis (km)']];
const WT_LVL = ['crit', 'high', 'normal', 'low'];
const WT_BAFU_S = 600; // Temperatur der BAFU-Station höchstens so oft nachführen
const WT_ALERT_S = 900; // Warnungen so lange in incident/state (für Geräte ohne Push), die Geräte entdoppeln über den Schlüssel

function wtIn($v) { $d = WT_DEFAULT; if (!is_array($v)) $v = []; $r = [];
  $b = array_values(array_unique(array_map(fn($x) => round(max(-2, min(35, (float)$x)), 1), array_filter((array)($v['bands'] ?? $d['bands']), 'is_numeric')))); sort($b);
  if (!$b || count($b) > 8) $b = $d['bands']; $r['bands'] = $b; $n = count($b) + 1;
  foreach (WT_SUITS as $k => $_) { $m = array_values((array)(($v['min'] ?? [])[$k] ?? []));
    $r['min'][$k] = []; for ($i = 0; $i < $n; $i++) { $x = $m[$i] ?? null; $r['min'][$k][] = is_numeric($x) ? (int)max(1, min(600, round((float)$x))) : ($d['min'][$k][min($i, count($d['min'][$k]) - 1)]); } }
  $r['off'] = array_values(array_intersect(array_keys(WT_SUITS), array_filter((array)($v['off'] ?? []), 'is_string'))); if (count($r['off']) === count(WT_SUITS)) $r['off'] = [];
  $on = array_values(array_diff(array_keys(WT_SUITS), $r['off']));
  $r['def'] = isset($v['def']) && in_array($v['def'], $on, true) ? $v['def'] : (in_array($d['def'], $on, true) ? $d['def'] : $on[0]);
  foreach (WT_NUM as $k => [$lo, $hi]) $r[$k] = isset($v[$k]) && is_numeric($v[$k]) ? (int)max($lo, min($hi, round((float)$v[$k]))) : $d[$k];
  $r['lvl'] = isset($v['lvl']) && in_array($v['lvl'], WT_LVL, true) ? $v['lvl'] : $d['lvl'];
  $r['auto'] = array_key_exists('auto', $v) ? (int)!empty($v['auto']) : $d['auto']; return $r; }
function wtCfg() { static $c = null; return $c ?? ($c = wtIn(json_decode((string)setting('wtime', ''), true))); }
function wtSuits() { $c = wtCfg(); $o = []; foreach (WT_SUITS as $k => [$n, $h]) if (!in_array($k, $c['off'], true)) $o[] = ['k' => $k, 'name' => $n, 'hint' => $h]; return $o; }
function wtSuitName($k) { return WT_SUITS[$k][0] ?? $k; }
// Richtwert in Sekunden; ohne Temperatur die kälteste Stufe
function wtLimit($suit, $t) { $c = wtCfg(); $i = 0; if ($t !== null) { $i = count($c['bands']); foreach ($c['bands'] as $n => $b) if ($t < $b) { $i = $n; break; } }
  $m = $c['min'][$suit] ?? $c['min'][$c['def']]; return 60 * (int)$m[min($i, count($m) - 1)]; }
function wtNum($x) { return rtrim(rtrim(number_format((float)$x, 1, '.', ''), '0'), '.'); }
function wtMin($s) { return (int)floor(max(0, $s) / 60); }

// Temperatur des Einsatzes: [t, src, Text, Zeitpunkt]
function wtTemp($inc) { if ($inc['wt_temp'] !== null) return [(float)$inc['wt_temp'], 'manual', 'von Hand gesetzt', (int)$inc['wt_temp_at']];
  if ($inc['wt_bafu'] !== null) return [(float)$inc['wt_bafu'], 'bafu', (string)$inc['wt_bafu_txt'], (int)$inc['wt_bafu_at']];
  return [null, 'none', '', null]; }
function wtTempTxt($t) { return $t[0] === null ? 'Wassertemperatur unbekannt, Richtwert der kältesten Stufe' : 'Wasser ' . wtNum($t[0]) . ' °C' . ($t[1] === 'bafu' ? ', BAFU ' . $t[2] : ($t[1] === 'manual' ? ', von Hand' : '')); }
// nächste BAFU-Station mit Wassertemperatur zu den PLS bzw. den Standorten der Einsatzkräfte; liest den Zwischenspeicher der Messwerte (hydroBafu, 5 min)
function wtBafu($id) { global $db, $now;
  $s = $db->prepare("SELECT * FROM incidents WHERE id=?"); $s->execute([(int)$id]); $inc = $s->fetch(); $s->closeCursor(); if (!$inc || $inc['ended_at'] !== null) return;
  $refs = hydroRefs($inc);
  if (!$refs) { $q = $db->prepare("SELECT lat,lon FROM participants WHERE incident_id=? AND lat IS NOT NULL AND left_at IS NULL AND mode<>'declined'"); $q->execute([(int)$id]); foreach ($q->fetchAll() as $p) $refs[] = [(float)$p['lat'], (float)$p['lon']]; }
  [$L] = hydroLatest(); $best = null;
  if ($refs && $L) foreach (hydroLocs() as $st) { $t = $L[$st['id']]['temp'] ?? null; if ($t === null) continue;
    foreach ($refs as [$la, $lo]) { $d = hav($la, $lo, $st['lat'], $st['lon']); if ($d <= wtCfg()['radius_km'] * 1000 && (!$best || $d < $best[1])) $best = [$st, $d, (float)$t]; } }
  $old = $inc['wt_bafu'] === null ? null : (float)$inc['wt_bafu'];
  $txt = $best ? (($best[0]['water'] ?? '') !== '' ? $best[0]['water'] . ', ' : '') . $best[0]['name'] . ' (' . wtNum($best[1] / 1000) . ' km)' : null;
  $db->prepare("UPDATE incidents SET wt_bafu=?,wt_bafu_txt=?,wt_bafu_at=? WHERE id=?")->execute([$best ? $best[2] : null, $txt, $now, (int)$id]);
  if (($best ? $best[2] : null) !== $old && $inc['wt_temp'] === null) wtApply((int)$id); }
// Richtwert der laufenden Wasserzeiten neu berechnen (Temperatur geändert); bereits gemeldete Stufen bleiben nur, solange sie noch gelten
function wtApply($id) { global $db, $now;
  $s = $db->prepare("SELECT * FROM incidents WHERE id=?"); $s->execute([(int)$id]); $inc = $s->fetch(); $s->closeCursor(); if (!$inc) return; $t = wtTemp($inc);
  $q = $db->prepare("SELECT * FROM water_time WHERE incident_id=? AND end_at IS NULL"); $q->execute([(int)$id]);
  $u = $db->prepare("UPDATE water_time SET temp=?,src=?,src_txt=?,lim_s=?,warned=? WHERE id=?");
  foreach ($q->fetchAll() as $w) { $lim = wtLimit($w['suit'], $t[0]); $u->execute([$t[0], $t[1], $t[2], $lim, min((int)$w['warned'], wtStage($now - (int)$w['start_at'], $lim)), (int)$w['id']]); } }
function wtStage($el, $lim) { $p = $lim > 0 ? 100 * $el / $lim : 0; return $p >= 100 ? 2 : ($p >= wtCfg()['warn'] ? 1 : 0); }

function wtRunning($partId) { global $db; $s = $db->prepare("SELECT * FROM water_time WHERE part_id=? AND end_at IS NULL ORDER BY id DESC LIMIT 1"); $s->execute([(int)$partId]); $r = $s->fetch(); $s->closeCursor(); return $r ?: null; }
// zuletzt gewählter Kälteschutz: in diesem Einsatz, sonst in einem früheren, sonst die Vorgabe
function wtSuitOf($p) { global $db; $on = array_column(wtSuits(), 'k');
  if (!empty($p['wt_suit']) && in_array($p['wt_suit'], $on, true)) return $p['wt_suit'];
  $s = $db->prepare("SELECT w.suit FROM water_time w JOIN participants x ON x.id=w.part_id WHERE x.user_id=? ORDER BY w.id DESC LIMIT 1"); $s->execute([(int)$p['user_id']]); $v = $s->fetchColumn(); $s->closeCursor();
  return $v && in_array($v, $on, true) ? $v : wtCfg()['def']; }
function wtName($u) { global $db; $s = $db->prepare("SELECT name FROM users WHERE id=?"); $s->execute([(int)$u]); $v = $s->fetchColumn(); $s->closeCursor(); return $v ?: '?'; }
// Einsatzleitung des Einsatzes (wie canManage, für Push und Hinweise): Teilnehmende mit Leitungsrechten und die Übungsleitung
function wtLeads($inc) { global $db; $s = $db->prepare("SELECT user_id FROM participants WHERE incident_id=? AND mode<>'declined' AND left_at IS NULL"); $s->execute([(int)$inc['id']]);
  $us = array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN)); $xp = exPlan($inc); if ($xp) $us = array_merge($us, exLeads($xp));
  return array_values(array_filter(array_unique($us), fn($u) => in_array('lead', voiceTok($inc, $u), true))); }

// Beginnen: $p = Teilnahme, $by = wer (NULL = automatisch), $at = Beginn
function wtStart($inc, $p, $suit, $by, $at) { global $db; $t = wtTemp($inc); $lim = wtLimit($suit, $t[0]);
  $db->prepare("INSERT INTO water_time(incident_id,part_id,start_at,suit,temp,src,src_txt,lim_s,auto,by_start) VALUES(?,?,?,?,?,?,?,?,?,?)")->execute([(int)$inc['id'], (int)$p['id'], $at, $suit, $t[0], $t[1], $t[2], $lim, $by === null ? 1 : 0, $by]);
  $db->prepare("UPDATE participants SET wt_suit=?,wt_wet=NULL,wt_dry=NULL WHERE id=?")->execute([$suit, (int)$p['id']]);
  $w = wtSuitName($suit) . ', ' . wtTempTxt($t) . ', Richtwert ' . wtMin($lim) . ' min';
  $u = (int)$p['user_id'];
  if ($by === null) logev($inc['id'], $u, 'wtime', "geht ins Wasser (automatisch erkannt; $w)");
  elseif ($by === $u) logev($inc['id'], $u, 'wtime', "geht ins Wasser ($w)");
  else logev($inc['id'], $by, 'wtime', 'startet die Wasserzeit für ' . wtName($u) . " ($w)"); }
function wtStop($inc, $w, $by, $at) { global $db; $at = max($at, (int)$w['start_at']); $db->prepare("UPDATE water_time SET end_at=?,by_end=?,end_auto=? WHERE id=? AND end_at IS NULL")->execute([$at, $by, $by === null ? 1 : 0, (int)$w['id']]);
  $s = $db->prepare("SELECT user_id FROM participants WHERE id=?"); $s->execute([(int)$w['part_id']]); $u = (int)$s->fetchColumn(); $s->closeCursor();
  $db->prepare("UPDATE participants SET wt_wet=NULL,wt_dry=NULL WHERE id=?")->execute([(int)$w['part_id']]);
  $d = 'Wasserzeit ' . wtMin($at - (int)$w['start_at']) . ' min, Richtwert ' . wtMin((int)$w['lim_s']) . ' min';
  if ($by === null) logev($inc['id'], $u, 'wtime', "ist zurück an Land (automatisch erkannt; $d)");
  elseif ($by === $u) logev($inc['id'], $u, 'wtime', "ist zurück an Land ($d)");
  else logev($inc['id'], $by, 'wtime', 'beendet die Wasserzeit von ' . wtName($u) . " ($d)"); }
// alle laufenden einer Teilnahme bzw. eines Einsatzes beenden (Austritt, entfernt, Einsatzende); Log nur beim Austritt
function wtClose($incId, $partId = null) { global $db, $now; $q = $db->prepare("SELECT * FROM water_time WHERE incident_id=? AND end_at IS NULL" . ($partId ? ' AND part_id=' . (int)$partId : '')); $q->execute([(int)$incId]);
  foreach ($q->fetchAll() as $w) $db->prepare("UPDATE water_time SET end_at=?,end_auto=1 WHERE id=?")->execute([$now, (int)$w['id']]); }

// Standortmeldung (incident/location): wd = Abstand zur Gewässerachse in m (nur mit geladenem Gewässernetz), $fast = Fahrt (auch auf dem Wasser)
function wtLoc($inc, $p, $in, $fast) { global $db, $now; if (!feature($inc, 'wtime')) return; $c = wtCfg();
  if (!$c['auto'] || !isset($in['wd']) || !is_numeric($in['wd']) || !empty($in['manual'])) return;
  $wet = (float)$in['wd'] <= $c['auto_m'] && $p['veh_id'] === null && !$fast; $run = wtRunning($p['id']); $set = fn($k, $v) => $db->prepare("UPDATE participants SET $k=? WHERE id=?")->execute([$v, (int)$p['id']]);
  if ($wet) { if ($run) { if ($p['wt_dry'] !== null) $set('wt_dry', null); }
    elseif ($p['wt_wet'] === null) $set('wt_wet', $now);
    elseif ($now - (int)$p['wt_wet'] >= $c['auto_s']) wtStart($inc, $p, wtSuitOf($p), null, (int)$p['wt_wet']);
    return; }
  if ($p['wt_wet'] !== null) $set('wt_wet', null);
  if ($run && (int)$run['auto']) { if ($p['wt_dry'] === null) $set('wt_dry', $now); elseif ($now - (int)$p['wt_dry'] >= $c['stop_s']) wtStop($inc, $run, null, (int)$p['wt_dry']); } }

// Warnungen bei warn % und 100 % (bei jedem Polling, Sperre über das UPDATE): Log, Push an die Person und die Einsatzleitung
function wtCheck($inc) { global $db, $now; $c = wtCfg(); $q = $db->prepare("SELECT w.*,p.user_id FROM water_time w JOIN participants p ON p.id=w.part_id WHERE w.incident_id=? AND w.end_at IS NULL"); $q->execute([(int)$inc['id']]);
  foreach ($q->fetchAll() as $w) { $el = $now - (int)$w['start_at']; $sg = wtStage($el, (int)$w['lim_s']); if ($sg <= (int)$w['warned']) continue;
    $u = $db->prepare("UPDATE water_time SET warned=?,w1_at=COALESCE(w1_at,?)" . ($sg === 2 ? ',w2_at=?' : '') . " WHERE id=? AND warned<?"); $u->execute($sg === 2 ? [$sg, $now, $now, (int)$w['id'], $sg] : [$sg, $now, (int)$w['id'], $sg]);
    if (!$u->rowCount()) continue; $uid = (int)$w['user_id']; $nm = wtName($uid); $m = wtMin($el); $l = wtMin((int)$w['lim_s']);
    logev($inc['id'], $uid, 'wtime', $sg === 2 ? "hat den Richtwert der Wasserzeit erreicht ($m von $l min)" : "ist seit $m min im Wasser ({$c['warn']} Prozent des Richtwerts von $l min)");
    $tag = 'w:' . (int)$w['id'] . ':' . $sg; $lv = pushLvl($c['lvl']);
    pushTo([$uid], ['t' => $sg === 2 ? 'Wasserzeit erreicht' : 'Wasserzeit', 'up' => 1, 'b' => wtMsg($sg, null, $m, $l), 'tag' => $tag] + $lv);
    pushTo(array_diff(wtLeads($inc), [$uid]), ['t' => $sg === 2 ? 'Wasserzeit erreicht' : 'Wasserzeit', 'up' => 1, 'b' => wtMsg($sg, $nm, $m, $l), 'tag' => $tag] + $lv); } }
function wtMsg($sg, $nm, $m, $l) { return $nm === null ? ($sg === 2 ? "Du bist seit $m min im Wasser und hast den Richtwert von $l min erreicht. Bitte an Land gehen." : "Du bist seit $m min im Wasser (Richtwert $l min).")
  : ($sg === 2 ? "$nm ist seit $m min im Wasser und hat den Richtwert von $l min erreicht." : "$nm ist seit $m min im Wasser (Richtwert $l min)."); }

// incident/state: laufende Wasserzeiten (alle), Summe der beendeten je Person, Temperatur, Kälteschutz; alerts = Warnungen für mich (Person oder Einsatzleitung)
function wtState($inc) { global $db, $uid, $now; $c = wtCfg(); $id = (int)$inc['id'];
  if ($inc['wt_bafu_at'] === null || $now - (int)$inc['wt_bafu_at'] >= WT_BAFU_S) { // Temperatur der BAFU-Station nach der Antwort nachführen
    $u = $db->prepare("UPDATE incidents SET wt_bafu_at=? WHERE id=? AND (wt_bafu_at IS NULL OR wt_bafu_at<?)"); $u->execute([$now, $id, $now - WT_BAFU_S]); if ($u->rowCount()) after(fn() => wtBafu($id)); }
  wtCheck($inc);
  $q = $db->prepare("SELECT w.id,w.part_id,w.start_at,w.end_at,w.suit,w.temp,w.src,w.lim_s,w.warned,w.w1_at,w.w2_at,w.auto,p.user_id,u.name FROM water_time w JOIN participants p ON p.id=w.part_id LEFT JOIN users u ON u.id=p.user_id WHERE w.incident_id=? ORDER BY w.id"); $q->execute([$id]);
  $run = []; $sum = []; $n = []; $al = []; $lead = canManage($inc);
  foreach ($q->fetchAll() as $w) { $u = (int)$w['user_id'];
    if ($w['end_at'] !== null) { $sum[$u] = ($sum[$u] ?? 0) + (int)$w['end_at'] - (int)$w['start_at']; $n[$u] = ($n[$u] ?? 0) + 1; continue; }
    $run[] = ['id' => (int)$w['id'], 'user_id' => $u, 'name' => $w['name'], 'start_at' => (int)$w['start_at'], 'lim_s' => (int)$w['lim_s'], 'suit' => $w['suit'], 'suit_name' => wtSuitName($w['suit']),
      'temp' => $w['temp'] === null ? null : (float)$w['temp'], 'src' => $w['src'], 'warned' => (int)$w['warned'], 'auto' => (int)$w['auto']];
    $mine = $u === $uid; if (!$mine && !$lead) continue; $m = wtMin($now - (int)$w['start_at']); $l = wtMin((int)$w['lim_s']);
    foreach ([1 => 'w1_at', 2 => 'w2_at'] as $sg => $k) if ((int)$w['warned'] === $sg && $w[$k] !== null && $now - (int)$w[$k] <= WT_ALERT_S)
      $al[] = ['tag' => 'w:' . (int)$w['id'] . ':' . $sg, 't' => $sg === 2 ? 'Wasserzeit erreicht' : 'Wasserzeit', 'b' => wtMsg($sg, $mine ? null : $w['name'], $m, $l), 'lvl' => $c['lvl']];
    if ($mine && (int)$w['auto'] && $now - (int)$w['start_at'] <= WT_ALERT_S + $c['auto_s'])
      $al[] = ['tag' => 'w:' . (int)$w['id'] . ':a', 't' => 'Wasserzeit läuft', 'b' => "Automatisch erkannt: Du bist im Wasser, Richtwert $l min. Falls nicht, «Zurück an Land» antippen.", 'lvl' => 'normal']; }
  $t = wtTemp($inc); $p = part($id);
  return [['auto' => $c['auto'], 'warn' => $c['warn'], 'suits' => wtSuits(), 'suit' => $p ? wtSuitOf($p) : $c['def'], 'temp' => $t[0], 'temp_src' => $t[1], 'temp_txt' => $t[2], 'temp_at' => $t[3],
    'lim' => array_map(fn($s) => wtLimit($s['k'], $t[0]), array_column(wtSuits(), null, 'k')), 'run' => $run, 'sum' => (object)$sum, 'n' => (object)$n, 'can_temp' => $lead ? 1 : 0], $al]; }

// After Action Review (aarDoc): alle Wasserzeiten des Einsatzes, Namen wie die Einsatzkräfte ($c = anonCtx)
function wtDoc($id, $c) { global $db; $q = $db->prepare("SELECT w.*,p.user_id,p.label,u.name FROM water_time w JOIN participants p ON p.id=w.part_id LEFT JOIN users u ON u.id=p.user_id WHERE w.incident_id=? ORDER BY w.start_at"); $q->execute([(int)$id]);
  return array_map(fn($w) => ['name' => $c ? ($c['u'][(int)$w['user_id']] ?? 'Einsatzkraft') : ($w['name'] ?? ($w['label'] ?? 'Einsatzkraft')), 'start_at' => (int)$w['start_at'], 'end_at' => $w['end_at'] === null ? null : (int)$w['end_at'],
    'suit' => wtSuitName($w['suit']), 'temp' => $w['temp'] === null ? null : (float)$w['temp'], 'lim_s' => (int)$w['lim_s'], 'warned' => (int)$w['warned'], 'auto' => (int)$w['auto']], $q->fetchAll()); }
// Statistik pro Person (psRows): Sekunden und Anzahl je «Einsatz:Person»
function wtPs() { global $db, $now; $o = []; try { $q = $db->query("SELECT w.start_at,w.end_at,p.incident_id,p.user_id,i.ended_at FROM water_time w JOIN participants p ON p.id=w.part_id JOIN incidents i ON i.id=w.incident_id"); } catch (Throwable $e) { return []; }
  foreach ($q as $w) { $k = $w['incident_id'] . ':' . $w['user_id']; $e = $w['end_at'] ?? $w['ended_at'] ?? $now; $o[$k][0] = ($o[$k][0] ?? 0) + max(0, (int)$e - (int)$w['start_at']); $o[$k][1] = ($o[$k][1] ?? 0) + 1; }
  return $o; }

// Routen (aufgerufen vor dem switch, beendet die Anfrage bei eigenen Routen)
function wtRoute($route) { global $db, $in, $uid, $now;
  if (!str_starts_with($route, 'wtime/') && !str_starts_with($route, 'incident/wtime/')) return;
  switch ($route) {
  case 'wtime/cfg': need('cfg_func'); out(['cfg' => wtCfg(), 'def' => WT_DEFAULT, 'suits' => WT_SUITS, 'num' => WT_NUM, 'lvls' => array_intersect_key(ALARM_LEVELS, array_flip(WT_LVL))]);
  case 'wtime/cfg/save': need('cfg_func'); setSetting('wtime', json_encode(wtIn($in['cfg'] ?? null), JSON_UNESCAPED_UNICODE)); out(['ok' => true]);
  }
  $inc = activeIncident(); if (!$inc) fail('Kein aktiver Einsatz'); if (!feature($inc, 'wtime')) fail('Die Wasserzeit ist für diesen Einsatztyp ausgeschaltet', 403);
  $lead = canManage($inc);
  switch ($route) {
  case 'incident/wtime/start': // user = andere Person (Einsatzleitung), suit = Kälteschutz; läuft schon eine, ändert suit den Kälteschutz
  case 'incident/wtime/stop':
    $u = (int)($in['user_id'] ?? $uid); if ($u !== $uid && !$lead) fail('Nur die Einsatzleitung kann die Wasserzeit anderer Personen starten und beenden', 403);
    $p = part($inc['id'], $u); if (!$p || $p['left_at'] !== null || $p['mode'] === 'declined') fail($u === $uid ? 'Du nimmst nicht am Einsatz teil' : 'Die Person ist nicht im Einsatz');
    $run = wtRunning($p['id']);
    if ($route === 'incident/wtime/stop') { if ($run) wtStop($inc, $run, $uid, $now); out(['ok' => true]); }
    $suit = isset($in['suit']) ? (string)$in['suit'] : wtSuitOf($p); if (!in_array($suit, array_column(wtSuits(), 'k'), true)) fail('Unbekannter Kälteschutz');
    if (!$run) { wtStart($inc, $p, $suit, $uid, $now); out(['ok' => true]); }
    if ($run['suit'] !== $suit) { $t = wtTemp($inc); $lim = wtLimit($suit, $t[0]);
      $db->prepare("UPDATE water_time SET suit=?,lim_s=?,warned=? WHERE id=?")->execute([$suit, $lim, min((int)$run['warned'], wtStage($now - (int)$run['start_at'], $lim)), (int)$run['id']]);
      $db->prepare("UPDATE participants SET wt_suit=? WHERE id=?")->execute([$suit, (int)$p['id']]);
      logev($inc['id'], $uid, 'wtime', ($u === $uid ? 'ändert den Kälteschutz' : 'ändert den Kälteschutz von ' . wtName($u)) . ' auf ' . wtSuitName($suit) . ' (Richtwert ' . wtMin($lim) . ' min)'); }
    out(['ok' => true]);
  case 'incident/wtime/temp': // Wassertemperatur von Hand (Einsatzleitung), null = wieder die der BAFU-Station
    if (!$lead) fail('Nur die Einsatzleitung kann die Wassertemperatur setzen', 403);
    $t = $in['temp'] ?? null; if ($t !== null && $t !== '' && (!is_numeric($t) || (float)$t < -2 || (float)$t > 35)) fail('Wassertemperatur muss zwischen -2 und 35 °C liegen');
    $t = $t === null || $t === '' ? null : round((float)$t, 1);
    $db->prepare("UPDATE incidents SET wt_temp=?,wt_temp_at=?,wt_temp_by=? WHERE id=?")->execute([$t, $t === null ? null : $now, $t === null ? null : $uid, (int)$inc['id']]);
    logev($inc['id'], $uid, 'wtime', $t === null ? 'setzt die Wassertemperatur zurück auf die Messung des BAFU' : 'setzt die Wassertemperatur auf ' . wtNum($t) . ' °C');
    wtApply((int)$inc['id']); out(['ok' => true]);
  }
  fail('Unbekannte Route', 404); }
