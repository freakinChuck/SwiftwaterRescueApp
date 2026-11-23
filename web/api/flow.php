<?php
/* ---------- Fliessgeschwindigkeit an den Messstationen (Issue #254, Konzept docs/konzepte/254/KONZEPT.md) ----------
 Eingebunden von index.php nach docs.php. Gehört zur Funktion «hydro» (Pegel & Abfluss), keine eigene Funktion.
 Quellen: BAFU-Datenplattform (data.bafu.admin.ch, GraphQL: Stationen, 10-Minuten- und Stundenwerte, Flussvermessung), BAFU-Hochwasservorhersage
 (hydrodaten.admin.ch, Ensemble Median/Min./Max.). Alle über den Server mit Datei-Cache in api/data/hydro/.
 Kennlinie je Station (hydro_curve): benetzte Fläche A(W) aus den Querprofilen beim Pegel, mittlere Geschwindigkeit v̄ = Q / A, angepasst als v̄ = a · Q^m;
 ohne Querprofil Schätzung nach Strickler aus Sohlenbreite und Gefälle des Längenprofils. Driftspanne = Faktor (f_lo … f_hi) × v̄ / 0.85 (Oberfläche).
 Vorhersage (flowPred): BAFU-Vorhersage, angeglichen an den letzten Messwert, sonst bleibt der Abfluss. Abschnitte (hydro_reach)
 mit Faktor auf die Geschwindigkeit der Station. Relevante Veränderungen (flowCheck) als Hinweis, Log-Eintrag und Push an die Einsatzleitung,
 die App ändert Fliessgeschwindigkeit und Suchgebiet nie selbst. */

foreach ([
  // Kennlinie je Station: src prof|strickler|manual|none, prof = Querprofile [{g, at, pts [[x, z]]}], fit = Stichprobe [[Q, v]], kl = Tabelle [[Q, W, A, v]] (Spalten err, err0, rest aus einer früheren Fassung, ungenutzt)
  "CREATE TABLE IF NOT EXISTS hydro_curve(station $tx PRIMARY KEY, name $tx NULL, river $tx NULL, gwl $tx NULL, gewiss INT NULL, a DOUBLE NULL, m DOUBLE NULL, src $tx NULL, manual INT DEFAULT 0, prof MEDIUMTEXT NULL, fit TEXT NULL, kl TEXT NULL, q_lo DOUBLE NULL, q_hi DOUBLE NULL, n INT NULL, r2 DOUBLE NULL, err DOUBLE NULL, err0 DOUBLE NULL, rest DOUBLE NULL, msg TEXT NULL, upd BIGINT NULL)",
  // Abschnitte entlang eines Gewässers (GEWISS-Adressen: Meter ab Mündung, flussabwärts abnehmend): kind stau|schwellen|eng|frei, fac = Faktor auf die Geschwindigkeit der Station, pts [[lat, lon, g]]
  "CREATE TABLE IF NOT EXISTS hydro_reach(id $ai, grp $tx NULL, name $tx, kind $tx, gwl $tx, g_from INT, g_to INT, pts MEDIUMTEXT NULL, len_m INT NULL, width $tx NULL, slope $tx NULL, fac DOUBLE, station $tx NULL, src $tx NULL, sort INT NULL, active INT DEFAULT 1)",
  // Meldungen im Einsatz (je Art höchstens eine pro Stunde)
  "CREATE TABLE IF NOT EXISTS hydro_note(id $ai, incident_id INT, kind $tx, at BIGINT, title $tx, text TEXT)",
] as $s) $db->exec($s);
try { $db->exec("ALTER TABLE incidents ADD COLUMN flow_chk BIGINT NULL"); } catch (Throwable $e) {}
try { $db->exec("ALTER TABLE hydro_note ADD COLUMN title $tx"); } catch (Throwable $e) {}
try { $db->exec("ALTER TABLE hydro_reach ADD COLUMN mg DOUBLE NULL"); } catch (Throwable $e) {} // Zusatzmarge Hauptströmung in %, NULL = Einstellung

const BAFU_GQ = 'https://data.bafu.admin.ch/api';
const BAFU_FC = 'https://www.hydrodaten.admin.ch/';
const FLOW_DEFAULT = ['f_lo' => 0.8, 'f_hi' => 1.0, 'mg' => 20, 'dev' => 20, 'min_kmh' => 1.0, 'hor' => 2, 'dq' => 25, 'danger' => 1, 'fc_new' => 1, 'all' => 0, 'kinds' => []];
// [Standard, Minimum, Maximum, Schritt]
const FLOW_NUM = ['f_lo' => [0.8, 0.5, 1.0], 'f_hi' => [1.0, 0.6, 1.2], 'mg' => [20, 0, 100], 'dev' => [20, 5, 100], 'min_kmh' => [1.0, 0.2, 5], 'hor' => [2, 1, 48], 'dq' => [25, 5, 100]];
const FLOW_KINDS = ['stau' => ['Stau', 'Staustrecke', 0.33, '#1d7fd1', 'pool'], 'schwellen' => ['Schwellen', 'Schwellenstrecke', 0.75, '#ff8a3d', 'thr'], 'eng' => ['Engstelle', 'Engstelle', 1.0, '#c34fff', 'gorge'], 'frei' => ['frei', 'frei', 0.8, '#35c27a', 'free']]; // Kurzname, Name, Faktor, Farbe, Klasse
const FLOW_SURF = 0.85; // mittlere Geschwindigkeit ≈ 0.85 × Oberfläche
const FLOW_PRED_S = 300; // Vorhersage je Station zwischenspeichern
const FLOW_NOTE_S = 3600; // je Art höchstens eine Meldung pro Stunde
const FLOW_CHK_S = 300; // Prüfung je Einsatz höchstens alle 5 min
const FLOW_ID = '/^[0-9A-Za-z_-]{1,12}$/';

function flowCfg() { $v = json_decode(setting('flow', ''), true); $v = is_array($v) ? $v : []; $c = FLOW_DEFAULT;
  foreach (FLOW_NUM as $k => [$d, $lo, $hi]) if (is_numeric($v[$k] ?? null)) $c[$k] = min(max((float)$v[$k], $lo), $hi);
  foreach (['danger', 'fc_new', 'all'] as $k) if (isset($v[$k])) $c[$k] = $v[$k] ? 1 : 0;
  if ($c['f_hi'] < $c['f_lo']) $c['f_hi'] = $c['f_lo'];
  $ks = []; foreach (kinds() as $id => $k) { $x = $v['kinds'][$id] ?? $v['kinds'][(string)$id] ?? null; $ks[$id] = ['show' => isset($x['show']) ? ($x['show'] ? 1 : 0) : 1, 'note' => isset($x['note']) ? ($x['note'] ? 1 : 0) : ($k['plans'] ? 0 : 1)]; }
  $c['kinds'] = $ks; return $c; }
function flowIn($v) { if (!is_array($v)) fail('Ungültige Einstellung'); $o = [];
  foreach (FLOW_NUM as $k => [$d, $lo, $hi]) { if (!is_numeric($v[$k] ?? null) || $v[$k] < $lo || $v[$k] > $hi) fail("Wert ausserhalb des Bereichs ($lo–$hi)"); $o[$k] = round((float)$v[$k], 2); }
  if ($o['f_hi'] < $o['f_lo']) fail('Der Driftfaktor «bis» muss mindestens so gross sein wie «von»');
  foreach (['danger', 'fc_new', 'all'] as $k) $o[$k] = empty($v[$k]) ? 0 : 1; $o['kinds'] = [];
  foreach ((array)($v['kinds'] ?? []) as $id => $x) if (isset(kinds()[(int)$id])) $o['kinds'][(int)$id] = ['show' => empty($x['show']) ? 0 : 1, 'note' => empty($x['note']) ? 0 : 1];
  return $o; }
function flowKind($inc) { return flowCfg()['kinds'][(int)$inc['kind_id']] ?? ['show' => 1, 'note' => 1]; }

/* ---------- Abfragen beim BAFU ---------- */
// GraphQL der BAFU-Datenplattform (POST, Fehler kommen mit Status 200 und «errors»): data oder null; höchstens 500 Anfragen in 5 Minuten
function bafuGq($q, $to = 20) { if (!function_exists('curl_init')) return null;
  $c = curl_init(BAFU_GQ); curl_setopt_array($c, CURL_CA + [CURLOPT_RETURNTRANSFER => 1, CURLOPT_TIMEOUT => $to, CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_POST => 1, CURLOPT_POSTFIELDS => json_encode(['query' => $q]),
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'], CURLOPT_USERAGENT => 'SwiftwaterRescue/0.1']);
  $r = curl_exec($c); $j = is_string($r) ? json_decode($r, true) : null;
  if (!is_array($j) || !empty($j['errors']) || !is_array($j['data'] ?? null)) { $GLOBALS['BAFU_ERR'] = is_array($j['errors'] ?? null) ? mb_substr((string)($j['errors'][0]['message'] ?? 'Fehler'), 0, 200) : ($r === false ? curl_error($c) : 'keine gültige Antwort'); return null; }
  return $j['data']; }
function gqs($s) { return json_encode((string)$s, JSON_UNESCAPED_UNICODE); }
function gts($t) { return gmdate('Y-m-d\TH:i:s\Z', (int)$t); }
// Stationen der Datenplattform: {no: {no, name, river, lat, lon}}, 7 Tage zwischengespeichert
function flowStations() { $d = hydroFile('gq_st', 7 * 86400, function () {
    $r = bafuGq('{water{observations{stations(limit:5000){no name riverName latitude longitude}}}}'); $l = $r['water']['observations']['stations'] ?? null; if (!is_array($l)) return null; $o = [];
    foreach ($l as $s) { $id = (string)($s['no'] ?? ''); if (!preg_match(FLOW_ID, $id) || !is_numeric($s['latitude'] ?? null)) continue;
      $o[$id] = ['no' => $id, 'name' => mb_substr((string)$s['name'], 0, 80), 'river' => mb_substr((string)($s['riverName'] ?? ''), 0, 80), 'lat' => round((float)$s['latitude'], 6), 'lon' => round((float)$s['longitude'], 6)]; }
    return $o ?: null; });
  return is_array($d['data'] ?? null) ? $d['data'] : []; }
// Quelle «bafu» (Datenplattform) für Stand und Test: Stationen und die letzten 10-Minuten-Werte (Q, W, WT)
function flowSrcBafu() { $st = flowStations(); if (!$st) return null; $o = []; $old = time() - HYDRO_OLD;
  $r = bafuGq('{water{observations{data_live(where:{timestamp:{_gte:' . gqs(gts(time() - 1800)) . '}},limit:10000){timestamp value parameterName stationNo}}}}');
  $l = $r['water']['observations']['data_live'] ?? null; if (!is_array($l)) return null;
  foreach ($l as $x) { $id = (string)($x['stationNo'] ?? ''); $t = strtotime((string)($x['timestamp'] ?? '')); if (!isset($st[$id]) || !is_numeric($x['value'] ?? null) || !$t || $t < $old || $t < ($o[$id]['_' . $x['parameterName']] ?? 0)) continue;
    $v = (float)$x['value']; $k = ['Q' => 'flow', 'W' => $v > 150 ? 'height' : 'level', 'WT' => 'temp'][$x['parameterName']] ?? null; if (!$k) continue;
    $o[$id][$k] = round($v, 4); $o[$id]['_' . $x['parameterName']] = $t; $o[$id]['at'] = max((int)($o[$id]['at'] ?? 0), $t); }
  foreach ($o as &$v) $v = array_filter($v, fn($k) => $k[0] !== '_', ARRAY_FILTER_USE_KEY); unset($v);
  $locs = []; foreach ($st as $id => $s) $locs[$id] = ['id' => $id, 'name' => $s['name'], 'water' => $s['river'], 'type' => 'river', 'lat' => $s['lat'], 'lon' => $s['lon']];
  return ['locs' => $locs, 'vals' => $o]; }
// Quelle «fc» (Hochwasservorhersage): Liste der Stationen mit Vorhersage
function flowFcList() { $d = hydroFile('fc_list', 1800, function () {
    $j = json_decode((string)http_get(BAFU_FC . 'web-hydro-maps/hydro_sensor_pq_forecast.geojson'), true); if (!is_array($j['features'] ?? null)) return null; $o = [];
    foreach ($j['features'] as $f) { $p = $f['properties'] ?? []; $id = (string)($p['key'] ?? ''); $c = $f['geometry']['coordinates'] ?? null; if (!preg_match(FLOW_ID, $id) || !is_numeric($c[0] ?? null)) continue;
      [$la, $lo] = lv95ll((float)$c[0], (float)$c[1]); $o[$id] = ['id' => $id, 'name' => mb_substr((string)($p['label'] ?? $id), 0, 80), 'lat' => round($la, 6), 'lon' => round($lo, 6)]; }
    return $o ?: null; });
  return is_array($d['data'] ?? null) ? $d['data'] : []; }
function flowSrcFc() { @unlink(__DIR__ . '/data/hydro/fc_list.json'); $l = flowFcList(); if (!$l) return null;
  $locs = []; foreach ($l as $id => $s) $locs[$id] = ['id' => $id, 'name' => $s['name'], 'water' => '', 'type' => 'river', 'lat' => $s['lat'], 'lon' => $s['lon']];
  $f = flowFc('2016'); return ['locs' => $locs, 'vals' => $f ? ['2016' => ['flow' => $f['q0'], 'at' => $f['at']]] : []]; }
// BAFU-Vorhersage einer Station: {at = Ausgabe, q0 = zuletzt gemessen, s = [[t, Median, Min., Max.]]} oder null; 30 min zwischengespeichert
function flowFc($id) { if (!preg_match(FLOW_ID, (string)$id) || empty(hydroCfg()['src']['fc'])) return null;
  if (!isset(flowFcList()[$id])) return null;
  $d = hydroFile("fc_$id", 1800, function () use ($id) {
    $j = json_decode((string)http_get(BAFU_FC . "plots/q_forecast/{$id}_q_forecast_de.json"), true); $tr = $j['plot']['data'] ?? null; if (!is_array($tr)) return null;
    $med = []; $lo = []; $hi = []; $meas = [];
    foreach ($tr as $t) { $nm = (string)($t['name'] ?? ''); foreach ((array)($t['x'] ?? []) as $i => $x) { $y = $t['y'][$i] ?? null; $ts = strtotime((string)$x); if (!$ts || !is_numeric($y)) continue; $y = (float)$y;
        if ($nm === 'Median') $med[$ts] = $y; elseif ($nm === 'Gemessen') $meas[$ts] = $y;
        elseif (str_starts_with($nm, 'Min')) { $lo[$ts] = min($lo[$ts] ?? $y, $y); $hi[$ts] = max($hi[$ts] ?? $y, $y); } } }
    if (!$med) return null; ksort($med); $at = $meas ? max(array_keys($meas)) : min(array_keys($med)); $s = [];
    foreach ($med as $t => $q) $s[] = [$t, round($q, 1), round($lo[$t] ?? $q, 1), round($hi[$t] ?? $q, 1)];
    return ['at' => $at, 'q0' => $meas ? round($meas[$at], 1) : null, 's' => $s]; });
  return is_array($d['data'] ?? null) ? $d['data'] : null; }
// Stundenmittel einer Station: [t => Wert], neuste zuerst geliefert, hier aufsteigend
function flowHourly($id, $par, $from, $n = 9000) {
  $r = bafuGq('{water{observations{data_1hour_mean(where:{station:{no:{_eq:' . gqs($id) . '}},parameterName:{_eq:' . gqs($par) . '},timestamp:{_gte:' . gqs(gts($from)) . '}},limit:' . (int)$n . '){timestamp value}}}}', 40);
  $l = $r['water']['observations']['data_1hour_mean'] ?? null; if (!is_array($l)) return null; $o = [];
  foreach ($l as $x) { $t = strtotime((string)$x['timestamp']); if ($t && is_numeric($x['value'] ?? null)) $o[$t] = (float)$x['value']; }
  ksort($o); return $o; }
// aktueller Abfluss: letzter 10-Minuten-Wert
function flowNow($id) { [$L] = hydroLatest(); if (isset($L[$id]['flow'])) return [(float)$L[$id]['flow'], (int)$L[$id]['at']];
  $r = bafuGq('{water{observations{data_live(where:{stationNo:{_eq:' . gqs($id) . '},parameterName:{_eq:"Q"}},limit:200){timestamp value}}}}');
  $best = null; foreach ((array)($r['water']['observations']['data_live'] ?? []) as $x) { $t = strtotime((string)$x['timestamp']); if ($t && is_numeric($x['value']) && (!$best || $t > $best[1])) $best = [(float)$x['value'], $t]; }
  return $best ? array_replace($best, [0 => (string)$best[0]]) : null; }
// Vermessungspunkte eines Gewässers (Mitte zwischen den Punkten links und rechts): [[gwl, g, lat, lon, Abschnitt]], 30 Tage zwischengespeichert
function flowBm($river) { $k = substr(sha1(mb_strtolower($river)), 0, 12);
  $d = hydroFile("bm_$k", 30 * 86400, function () use ($river) {
    $r = bafuGq('{water{river_profile{benchmarks(where:{gwlNo:{_startswith:"CH"},riverName:{_eq:' . gqs($river) . '}},limit:10000){gwlNo gewissAddress eastingLeft northingLeft eastingRight northingRight sectionName}}}}', 40);
    $l = $r['water']['river_profile']['benchmarks'] ?? null; if (!is_array($l)) return null; $o = [];
    foreach ($l as $b) { if (!is_numeric($b['eastingLeft'] ?? null) || !is_numeric($b['eastingRight'] ?? null) || !is_numeric($b['gewissAddress'] ?? null)) continue;
      [$la, $lo] = lv95ll(($b['eastingLeft'] + $b['eastingRight']) / 2, ($b['northingLeft'] + $b['northingRight']) / 2);
      $o[] = [(string)$b['gwlNo'], (int)$b['gewissAddress'], round($la, 6), round($lo, 6), mb_substr((string)($b['sectionName'] ?? ''), 0, 80)]; }
    return $o; });
  return is_array($d['data'] ?? null) ? $d['data'] : []; }
// nächster Vermessungspunkt: [gwl, g, lat, lon, Abschnitt, Distanz] oder null
function flowBmNear($bm, $la, $lo, $max = 500) { $b = null; $bd = $max;
  foreach ($bm as $x) { $d = hav($la, $lo, $x[2], $x[3]); if ($d < $bd) { $bd = $d; $b = $x; } }
  return $b ? array_merge($b, [(int)round($bd)]) : null; }
// Querprofile in einem Bereich der GEWISS-Adressen: {g: {at, sec, pts [[x, z]]}} (je Profil die neuste Vermessung)
function flowXs($gwl, $g0, $g1) {
  $r = bafuGq('{water{river_profile{cross_sections(where:{gwlNo:{_eq:' . gqs($gwl) . '},gewissAddress:{_gte:' . (int)$g0 . ',_lt:' . (int)$g1 . '}},limit:10000){gewissAddress crossDistance height surveyDate sectionName pointRole}}}}', 40);
  $l = $r['water']['river_profile']['cross_sections'] ?? null; if (!is_array($l)) return null; $o = [];
  foreach ($l as $p) { if (!is_numeric($p['crossDistance'] ?? null) || !is_numeric($p['height'] ?? null) || (float)$p['height'] < 100 || ($p['pointRole'] ?? '') === 'Lichtraumprofil') continue; // Lichtraumprofil = Unterkante von Brücken, Höhe 0 = ungültig
    $g = (int)$p['gewissAddress']; $t = (string)$p['surveyDate'];
    if (isset($o[$g]) && $o[$g]['at'] > $t) continue; if (!isset($o[$g]) || $o[$g]['at'] < $t) $o[$g] = ['at' => $t, 'sec' => (string)($p['sectionName'] ?? ''), 'pts' => []];
    $o[$g]['pts'][] = [round((float)$p['crossDistance'], 2), round((float)$p['height'], 3)]; }
  foreach ($o as &$x) usort($x['pts'], fn($a, $b) => $a[0] <=> $b[0]); unset($x);
  return $o; }
// benetzte Fläche beim Wasserspiegel wl (m ü. M.) aus den Profilpunkten [[x, z]] (Lücken > 50 m gelten als ausserhalb des Gerinnes)
function flowArea($pts, $wl) { $A = 0.0;
  for ($i = 1; $i < count($pts); $i++) { [$x1, $z1] = $pts[$i - 1]; [$x2, $z2] = $pts[$i]; $dx = $x2 - $x1; if ($dx > 50 || $dx <= 0) continue;
    $d1 = $wl - $z1; $d2 = $wl - $z2; if ($d1 <= 0 && $d2 <= 0) continue;
    if ($d1 > 0 && $d2 > 0) $A += ($d1 + $d2) / 2 * $dx; else { $mx = max($d1, $d2); $f = $mx / (abs($d1) + abs($d2)); $A += $mx * $f * $dx / 2; } }
  return $A; }
// Längenprofil: [[g, Sohlenbreite, mittlere Sohle]] aufsteigend nach g
function flowLp($gwl, $g0, $g1) {
  $r = bafuGq('{water{river_profile{longitudinal_profiles(where:{gwlNo:{_eq:' . gqs($gwl) . '},gewissAddress:{_gte:' . (int)$g0 . ',_lt:' . (int)$g1 . '}},limit:10000){gewissAddress bedWidth meanBedLevel surveyDate}}}}', 40);
  $l = $r['water']['river_profile']['longitudinal_profiles'] ?? null; if (!is_array($l)) return null; $o = [];
  foreach ($l as $p) { $g = (int)$p['gewissAddress']; if (!is_numeric($p['meanBedLevel'] ?? null)) continue; if (isset($o[$g]) && $o[$g][3] >= (string)$p['surveyDate']) continue;
    $o[$g] = [$g, is_numeric($p['bedWidth'] ?? null) ? (float)$p['bedWidth'] : null, (float)$p['meanBedLevel'], (string)$p['surveyDate']]; }
  ksort($o); return array_values(array_map(fn($x) => array_slice($x, 0, 3), $o)); }
// Bauwerke der Flussvermessung (Wehre, Schwellen, Blockrampen): [[g, Art]]
function flowObj($gwl, $g0, $g1) {
  $r = bafuGq('{water{river_profile{special_objects(where:{gwlNo:{_eq:' . gqs($gwl) . '},gewissAddress:{_gte:' . (int)$g0 . ',_lt:' . (int)$g1 . '}},limit:10000){gewissAddress pointRole}}}}', 40);
  $l = $r['water']['river_profile']['special_objects'] ?? null; if (!is_array($l)) return []; $o = [];
  foreach ($l as $p) { $pr = (string)$p['pointRole']; $k = str_contains($pr, 'Wehr') ? 'Wehr' : (str_contains($pr, 'Blockrampe') ? 'Blockrampe' : (str_contains($pr, 'Schwelle') ? 'Schwelle' : null)); if ($k) $o[(int)$p['gewissAddress']] = $k; }
  krsort($o); $r = []; foreach ($o as $g => $k) $r[] = [$g, $k]; return $r; }
function flowSlope($lp, $g) { $a = null; $b = null; foreach ($lp as $x) { if ($x[0] >= $g - 1000 && (!$a || $x[0] < $a[0])) $a = $x; if ($x[0] <= $g + 1000 && (!$b || $x[0] > $b[0])) $b = $x; }
  return $a && $b && $b[0] - $a[0] > 200 ? max(0.00005, ($b[2] - $a[2]) / ($b[0] - $a[0])) : null; }
function flowKm($g) { return 'km ' . number_format($g / 1000, 1, '.', ''); }
function flowFit($pairs) { $n = count($pairs); if ($n < 20) return null; $sx = $sy = $sxx = $sxy = 0.0;
  foreach ($pairs as [$q, $v]) { $x = log($q); $y = log($v); $sx += $x; $sy += $y; $sxx += $x * $x; $sxy += $x * $y; }
  $den = $n * $sxx - $sx * $sx; if (abs($den) < 1e-9) return null; $m = ($n * $sxy - $sx * $sy) / $den; $a = exp(($sy - $m * $sx) / $n);
  $my = $sy / $n; $ss = $sr = 0.0; foreach ($pairs as [$q, $v]) { $y = log($v); $ss += ($y - $my) ** 2; $sr += ($y - log($a) - $m * log($q)) ** 2; }
  return ['a' => $a, 'm' => $m, 'r2' => $ss > 0 ? 1 - $sr / $ss : 1.0]; }

/* ---------- Kennlinie ---------- */
// Kennlinie einer Station neu berechnen (Verwaltung, Nachlauf, nachts): Ergebnis in hydro_curve; true = Kennlinie vorhanden
function flowCalc($id) { global $db, $now; $st = flowStations()[$id] ?? null;
  $old = flowCurve($id); if ($old && (int)$old['manual']) { $db->prepare("UPDATE hydro_curve SET upd=? WHERE station=?")->execute([$now, $id]); return true; }
  $save = function ($c) use ($db, $id, $now, $st) { $c += ['name' => $st['name'] ?? null, 'river' => $st['river'] ?? null, 'upd' => $now];
    $cols = ['name', 'river', 'gwl', 'gewiss', 'a', 'm', 'src', 'prof', 'fit', 'kl', 'q_lo', 'q_hi', 'n', 'r2', 'err', 'err0', 'rest', 'msg', 'upd'];
    $db->prepare("DELETE FROM hydro_curve WHERE station=?")->execute([$id]);
    $db->prepare("INSERT INTO hydro_curve(station," . implode(',', $cols) . ") VALUES(?" . str_repeat(',?', count($cols)) . ")")->execute(array_merge([$id], array_map(fn($k) => isset($c[$k]) && is_array($c[$k]) ? json_encode($c[$k]) : ($c[$k] ?? null), $cols)));
    return $c['a'] !== null; };
  if (!$st) return $save(['src' => 'none', 'a' => null, 'msg' => 'Station auf der BAFU-Datenplattform nicht gefunden']);
  $bm = flowBm($st['river']); $b = $bm ? flowBmNear($bm, $st['lat'], $st['lon']) : null;
  if (!$b) return $save(['src' => 'none', 'a' => null, 'msg' => 'Keine Flussvermessung des BAFU in 500 m']);
  [$gwl, $g] = $b; $from = $now - 365 * 86400;
  $Q = flowHourly($id, 'Q', $from); $W = flowHourly($id, 'W', $from);
  if (!$Q) return $save(['src' => 'none', 'a' => null, 'gwl' => $gwl, 'gewiss' => $g, 'msg' => 'Keine Stundenwerte des Abflusses: ' . ($GLOBALS['BAFU_ERR'] ?? 'leer')]);
  $c = ['gwl' => $gwl, 'gewiss' => $g, 'q_lo' => round(min($Q), 1), 'q_hi' => round(max($Q), 1)];
  $xs = flowXs($gwl, $g - 300, $g + 300) ?: [];
  uksort($xs, fn($x, $y) => abs($x - $g) <=> abs($y - $g)); $xs = array_slice($xs, 0, 2, true);
  $pairs = []; $kl = [];
  if ($xs && $W && min($W) > 150) { // Pegel in m ü. M.: Fläche aus den Querprofilen
    $i = 0; foreach ($Q as $t => $q) { if ($i++ % 3 || !isset($W[$t]) || $q <= 0) continue; $A = 0; $n = 0; foreach ($xs as $x) { $a = flowArea($x['pts'], $W[$t]); if ($a > 1) { $A += $a; $n++; } }
      if ($n) { $v = $q / ($A / $n); if ($v > 0.05 && $v < 6) $pairs[] = [$q, $v, $W[$t], $A / $n]; } }
    $f = flowFit($pairs);
    if ($f && $f['m'] > 0 && $f['m'] < 1.2) {
      usort($pairs, fn($x, $y) => $x[0] <=> $y[0]); $np = count($pairs);
      foreach ([0.05, 0.25, 0.5, 0.75, 0.95, 0.999] as $p) { $x = $pairs[min($np - 1, (int)floor($p * $np))]; $kl[] = [round($x[0]), round($x[2], 2), round($x[3]), round($x[1], 2)]; }
      $fit = []; $step = max(1, intdiv($np, 60)); for ($j = 0; $j < $np; $j += $step) $fit[] = [round($pairs[$j][0], 1), round($pairs[$j][1], 3)];
      $prof = []; foreach ($xs as $gg => $x) $prof[] = ['g' => $gg, 'd' => abs($gg - $g), 'at' => substr($x['at'], 0, 10), 'sec' => $x['sec'], 'pts' => $x['pts']];
      return $save($c + ['src' => 'prof', 'a' => round($f['a'], 5), 'm' => round($f['m'], 4), 'r2' => round($f['r2'], 3), 'n' => count($Q), 'fit' => $fit, 'kl' => $kl, 'prof' => $prof, 'msg' => null]); } }
  // Rückfall: Strickler aus Sohlenbreite und Gefälle (v̄ = (k·√S)^0.6 · Q^0.4 / B^0.4, k ≈ 30)
  $lp = flowLp($gwl, $g - 1500, $g + 1500) ?: []; $S = flowSlope($lp, $g); $B = null; $bd = 1e9;
  foreach ($lp as $x) if ($x[1] && abs($x[0] - $g) < $bd) { $bd = abs($x[0] - $g); $B = $x[1]; }
  if ($S && $B) { $a = (30 * sqrt($S)) ** 0.6 / $B ** 0.4; $m = 0.4; $kl = [];
    foreach ([0.05, 0.5, 0.95] as $p) { $qs = array_values($Q); sort($qs); $q = $qs[min(count($qs) - 1, (int)floor($p * count($qs)))]; $kl[] = [round($q), null, round($q / ($a * $q ** $m)), round($a * $q ** $m, 2)]; }
    return $save($c + ['src' => 'strickler', 'a' => round($a, 5), 'm' => $m, 'n' => count($Q), 'kl' => $kl, 'msg' => 'Kein Querprofil beim Pegel: Schätzung nach Strickler aus Sohlenbreite ' . round($B) . ' m und Gefälle ' . number_format($S * 1000, 1, '.', '') . ' ‰ (±30–50 %)']); }
  return $save($c + ['src' => 'none', 'a' => null, 'msg' => 'Weder Querprofil noch Längenprofil beim Pegel']); }
function flowCurve($id) { global $db; $s = $db->prepare("SELECT * FROM hydro_curve WHERE station=?"); $s->execute([(string)$id]); $r = $s->fetch(); $s->closeCursor(); if (!$r) return null;
  $r = floats(ints($r, ['gewiss', 'manual', 'n', 'upd']), ['a', 'm', 'q_lo', 'q_hi', 'r2', 'err', 'err0', 'rest']);
  foreach (['prof', 'fit', 'kl'] as $k) $r[$k] = json_decode((string)$r[$k], true); return $r; }
// mittlere Geschwindigkeit und Driftspanne (m/s) zu einem Abfluss; die obere Grenze erhält die Zusatzmarge der Hauptströmung (mg in %, null = Einstellung)
function flowV($c, $q) { return $c && $c['a'] !== null && $q > 0 ? $c['a'] * $q ** $c['m'] : null; }
function flowDrift($c, $q, $lo = null, $hi = null, $fac = 1.0, $mg = null) { $f = flowCfg(); $v = flowV($c, $q); if ($v === null) return null; $m = 1 + ($mg ?? $f['mg']) / 100;
  return [round($f['f_lo'] * flowV($c, $lo ?? $q) * $fac / FLOW_SURF, 2), round($f['f_hi'] * flowV($c, $hi ?? $q) * $fac * $m / FLOW_SURF, 2), round(($f['f_lo'] + $f['f_hi']) / 2 * $v * $fac / FLOW_SURF, 2)]; }

/* ---------- Vorhersage ---------- */
// Abfluss einer Station von −12 h bis +96 h stündlich: {q, at, ser [[t, q, lo, hi]] (lo/hi null = gemessen), fc_at}; null = keine Werte
function flowPred($id) { $d = hydroFile("pred_$id", FLOW_PRED_S, function () use ($id) { $now = time();
    $nq = flowNow($id); if (!$nq) return null; [$q0, $t0] = $nq; $h0 = intdiv($t0, 3600) * 3600;
    $hist = flowHourly($id, 'Q', $now - 13 * 3600, 30) ?: [];
    $fc = flowFc($id); $ser = [];
    foreach ($hist as $t => $q) if ($t < $t0) $ser[] = [$t, round($q, 1), null, null];
    $ser[] = [$t0, round($q0, 1), null, null]; $last = $q0; $off = null;
    for ($h = 1; $h <= 96; $h++) { $t = $h0 + $h * 3600; $q = null; $lo = $hi = null;
      if ($fc) { $p = null; foreach ($fc['s'] as $x) if (abs($x[0] - $t) <= 1800) { $p = $x; break; } if (!$p) { foreach ($fc['s'] as $x) if ($x[0] <= $t) $p = $x; }
        if ($p && $p[0] >= $t - 7200) { $off ??= $last - $p[1]; $w = max(0, 1 - $h / 12); $q = $p[1] + $off * $w; $lo = min($q, $p[2] + $off * $w); $hi = max($q, $p[3] + $off * $w); } }
      if ($q === null) break; $last = $q; $ser[] = [$t, round($q, 1), round($lo, 1), round($hi, 1)]; }
    return ['q' => round($q0, 1), 'at' => $t0, 'ser' => $ser, 'fc_at' => $fc['at'] ?? null, 'fc_med' => $fc ? round(max(array_map(fn($x) => $x[1], $fc['s'])), 1) : null]; });
  return is_array($d['data'] ?? null) ? $d['data'] : null; }
// Wert der Vorhersage nach h Stunden: [q, lo, hi]
function flowAt($p, $h) { $t = $p['at'] + $h * 3600; $b = null; foreach ($p['ser'] as $x) if ($x[0] <= $t + 1800) $b = $x; return $b ? [$b[1], $b[2] ?? $b[1], $b[3] ?? $b[1]] : null; }

/* ---------- Station und Abschnitte im Einsatz ---------- */
// Bezugsstation eines Punkts: nächste Station mit Kennlinie flussabwärts am selben Gewässer, sonst oberhalb, sonst nach Luftlinie; [Station, Distanz m, rel unterhalb|oberhalb|luft, g des Punkts, gwl]
function flowStationFor($la, $lo) { global $db; $c = hydroCfg(); $locs = hydroLocs(); $st = flowStations(); $best = null;
  $have = []; foreach ($db->query("SELECT station,gwl,gewiss,a FROM hydro_curve")->fetchAll() as $r) $have[$r['station']] = $r;
  foreach ($locs + $st as $id => $s) { $x = $locs[$id] ?? ['lat' => $s['lat'], 'lon' => $s['lon'], 'water' => $s['river'], 'type' => 'river']; if (($x['type'] ?? 'river') === 'lake') continue;
    $d = hav($la, $lo, $x['lat'], $x['lon']); if ($d > $c['radius_km'] * 1000 * 2) continue;
    $cv = $have[$id] ?? null; $rel = 'luft'; $dist = $d; $g = null; $gwl = $cv['gwl'] ?? null;
    if ($cv && $cv['gewiss'] !== null && ($river = $st[$id]['river'] ?? '') !== '') { $b = flowBmNear(flowBm($river), $la, $lo, 1500);
      if ($b && $b[0] === $cv['gwl']) { $g = $b[1]; $rel = (int)$cv['gewiss'] <= $g ? 'unterhalb' : 'oberhalb'; $dist = abs($g - (int)$cv['gewiss']); } }
    $score = ($cv && $cv['a'] !== null ? 0 : 3) + ['unterhalb' => 0, 'oberhalb' => 1, 'luft' => 2][$rel] + $dist / 1e6;
    if (!$best || $score < $best[5]) $best = [$id, (int)round($dist), $rel, $g, $gwl, $score, $cv ? 1 : 0]; }
  return $best ? array_replace($best, [0 => (string)$best[0]]) : null; }
// Abschnitte zwischen den GEWISS-Adressen gA (oben) und gB (unten), zugeschnitten: [{id, name, kind, len_m, fac, pts, g0, g1}]
function flowReachesBetween($gwl, $gA, $gB) { global $db; if (!$gwl) return []; $s = $db->prepare("SELECT * FROM hydro_reach WHERE gwl=? AND active=1 AND g_from>? AND g_to<? ORDER BY g_from DESC"); $s->execute([$gwl, $gB, $gA]); $o = [];
  foreach ($s->fetchAll() as $r) { $a = min((int)$r['g_from'], $gA); $b = max((int)$r['g_to'], $gB); if ($a <= $b) continue;
    $nm = (string)$r['name']; $pp = explode(' – ', $nm, 2);
    if ($a < (int)$r['g_from'] && count($pp) === 2) $nm = 'PLS – ' . $pp[1]; if ($b > (int)$r['g_to']) $nm = (count($pp) === 2 && !str_starts_with($nm, 'PLS') ? $pp[0] : explode(' – ', $nm)[0]) . ' – Ende Suchgebiet';
    $pts = array_values(array_filter(json_decode((string)$r['pts'], true) ?: [], fn($p) => $p[2] <= $a && $p[2] >= $b));
    $o[] = ['id' => (int)$r['id'], 'name' => $nm, 'kind' => $r['kind'], 'len_m' => $a - $b, 'fac' => (float)$r['fac'], 'mg' => $r['mg'] !== null ? (float)$r['mg'] : null, 'station' => $r['station'], 'pts' => array_map(fn($p) => [$p[0], $p[1]], $pts), 'g0' => $a, 'g1' => $b]; }
  return $o; }
// Drift je Abschnitt mit der Vorhersage der Station jetzt: v0/v1 (m/s), t0/t1 (min)
function flowReachV($rs, $c, $p) { $o = [];
  foreach ($rs as $r) { $cc = $r['station'] && $r['station'] !== ($c['station'] ?? null) ? (flowCurve($r['station']) ?: $c) : $c; $pp = $cc === $c ? $p : (flowPred($cc['station']) ?: $p);
    $d = $pp ? flowDrift($cc, $pp['q'], null, null, $r['fac'], $r['mg']) : null; if (!$d) continue;
    $o[] = $r + ['v0' => $d[0], 'v1' => $d[1], 't0' => (int)round($r['len_m'] / max(0.05, $d[1]) / 60), 't1' => (int)round($r['len_m'] / max(0.05, $d[0]) / 60)]; }
  return $o; }
// Vorhersage für incident/state: null = keine Station mit Kennlinie in der Nähe
function flowIncPred($inc, $F) { global $db, $now; if (!$F['hydro'] || !flowKind($inc)['show']) return null;
  $refs = hydroRefs($inc); if (!$refs) return null; [$la, $lo] = $refs[0];
  $sel = flowStationFor($la, $lo); if (!$sel) return null; [$id, $dist, $rel, $g, $gwl, , $hasC] = $sel;
  if (!$hasC) { // Kennlinie noch nie berechnet: im Nachlauf (Sperre über die Zeile)
    $db->prepare("INSERT INTO hydro_curve(station,src,upd) SELECT ?,'calc',? WHERE NOT EXISTS (SELECT 1 FROM hydro_curve WHERE station=?)")->execute([$id, $now, $id]);
    after(fn() => flowCalc($id)); return null; }
  $c = flowCurve($id); if (!$c || $c['a'] === null) return null; $p = flowPred($id); if (!$p) return null;
  $loc = hydroLocs()[$id] ?? null; $st = flowStations()[$id] ?? null; $f = flowCfg();
  $reach = []; if ($g !== null && $inc['flow_ms'] !== null) { $len = (float)$inc['flow_ms'] * max(0, $now - plsAt($inc)); $reach = flowReachV(flowReachesBetween($gwl, $g, (int)($g - $len)), $c, $p); }
  $wr = null; if ($inc['flow_ms'] !== null && ($w = flowWorst($c, $p, (float)$inc['flow_ms'], $f))) $wr = ['h' => $w[1], 'd0' => $w[2][0], 'd1' => $w[2][1], 'q0' => round($p['q']), 'q' => round($w[3][0]), 'fc' => $p['fc_at'] ? 1 : 0, 'up' => $w[2][2] > (float)$inc['flow_ms'] ? 1 : 0];
  $note = $db->prepare("SELECT id,kind,at,title,text FROM hydro_note WHERE incident_id=? ORDER BY id DESC LIMIT 1"); $note->execute([(int)$inc['id']]); $nt = $note->fetch(); $note->closeCursor();
  return ['station' => $id, 'name' => $loc['name'] ?? ($st['name'] ?? $id), 'water' => $loc['water'] ?? ($st['river'] ?? ''), 'lat' => $loc['lat'] ?? $st['lat'] ?? null, 'lon' => $loc['lon'] ?? $st['lon'] ?? null,
    'dist' => $dist, 'rel' => $rel, 'a' => $c['a'], 'm' => $c['m'], 'src' => $c['src'], 'f_lo' => $f['f_lo'], 'f_hi' => $f['f_hi'], 'mg' => $f['mg'], 'surf' => FLOW_SURF, 'hor' => $f['hor'],
    'q' => $p['q'], 'at' => $p['at'], 'ser' => $p['ser'], 'fc_at' => $p['fc_at'], 'reaches' => $reach, 'kinds' => array_map(fn($k) => ['s' => $k[0], 'n' => $k[1], 'c' => $k[3], 'cl' => $k[4]], FLOW_KINDS), 'warn' => $wr, 'note' => $nt ? ['id' => (int)$nt['id'], 'kind' => $nt['kind'], 'at' => (int)$nt['at'], 'title' => $nt['title'], 'text' => $nt['text'], 'me' => in_array($GLOBALS['uid'] ?? 0, flowTo($inc), true) ? 1 : 0, 'lvl' => noteLvl($inc, 'msg')] : null]; }
// Fliessgeschwindigkeit an allen Stationen mit Kennlinie (Popup, Messstationen): {id: {v, d0, d1, h12 [lo, hi], src}}
function flowStationsV($ids) { $o = []; foreach (array_slice(array_values(array_unique(array_map('strval', $ids))), 0, 30) as $id) { $c = flowCurve($id); if (!$c || $c['a'] === null) continue; [$L] = hydroLatest(); $q = $L[$id]['flow'] ?? null; if ($q === null) continue;
    $d = flowDrift($c, $q); $p = flowPred($id); $h = $p ? flowAt($p, 12) : null; $dh = $h ? flowDrift($c, $h[0], $h[1], $h[2]) : null;
    $o[$id] = ['v' => round(flowV($c, $q), 2), 'd0' => $d[0], 'd1' => $d[1], 'h12' => $dh ? [$dh[0], $dh[1]] : null, 'src' => $c['src'], 'at' => substr((string)($c['prof'][0]['at'] ?? ''), 0, 4), 'fc_at' => $p['fc_at'] ?? null]; }
  return $o; }

/* ---------- Meldungen ---------- */
// grösste Abweichung zur eingestellten Geschwindigkeit im Horizont, nur wenn relevant (Abweichung in % und mindestens min_kmh): [Abweichung, h, Drift, Abfluss]
function flowWorst($c, $pr, $set, $f) { $worst = null; for ($h = 1; $h <= $f['hor']; $h++) { $x = flowAt($pr, $h); if (!$x) break; $d = flowDrift($c, $x[0], $x[1], $x[2]); $dv = abs($d[2] - $set); if (!$worst || $dv > $worst[0]) $worst = [$dv, $h, $d, $x]; }
  return $worst && $set > 0 && $worst[0] >= $set * $f['dev'] / 100 && $worst[0] >= $f['min_kmh'] / 3.6 ? $worst : null; }
function flowNum($x, $d = 1) { return number_format($x, $d, '.', ''); }
function flowCheck($id) { global $db, $now; $s = $db->prepare("SELECT * FROM incidents WHERE id=? AND ended_at IS NULL"); $s->execute([(int)$id]); $inc = $s->fetch(); $s->closeCursor();
  if (!$inc || !feature($inc, 'hydro') || !flowKind($inc)['note'] || $inc['flow_ms'] === null) return; $F = features($inc['kind_id']);
  $p = flowIncPred($inc, $F); if (!$p) return; $c = flowCurve($p['station']); $pr = flowPred($p['station']); if (!$c || !$pr) return; $f = flowCfg();
  $set = (float)$inc['flow_ms']; $nm = ($p['water'] !== '' ? $p['water'] . ' – ' : '') . $p['name']; $q0 = $pr['q']; $notes = [];
  // Abweichung zur eingestellten Geschwindigkeit im Horizont
  $worst = flowWorst($c, $pr, $set, $f);
  if ($worst) { [, $h, $d, $x] = $worst; $up = $d[2] > $set;
    $notes['dev'] = ['Fliessgeschwindigkeit: deutliche Änderung erwartet', "$nm: in $h h " . flowNum($d[0]) . '–' . flowNum($d[1]) . ' m/s statt eingestellt ' . flowNum($set) . ' m/s (Abfluss ' . ($x[0] > $q0 ? 'steigt' : 'sinkt') . ' von ' . round($q0) . ' auf ' . round($x[0]) . ' m³/s' . ($pr['fc_at'] ? ', BAFU-Vorhersage' : '') . ').',
      "Pegel und Abfluss, Vorhersage – $nm (" . flowNum($p['dist'] / 1000) . ' km vom PLS): Abfluss ' . ($x[0] > $q0 ? 'steigt' : 'sinkt') . ' von ' . round($q0) . ' auf ' . round($x[0]) . " m³/s in $h h" . ($pr['fc_at'] ? ' (BAFU-Vorhersage vom ' . date('d.m., H:i', $pr['fc_at']) . ')' : '') . '. Fliessgeschwindigkeit dann ' . flowNum($d[0]) . '–' . flowNum($d[1]) . ' m/s, eingestellt ' . flowNum($set) . ' m/s.']; }
  // starke Änderung des Abflusses in 3 h
  $x = flowAt($pr, 3); if ($x && $q0 > 0 && abs($x[0] - $q0) / $q0 * 100 >= $f['dq']) $notes['dq'] = ['Abfluss ändert sich stark', "$nm: Abfluss in 3 h " . round($x[0]) . ' statt ' . round($q0) . ' m³/s.', "Pegel und Abfluss, Vorhersage – $nm: Abfluss in 3 h " . round($x[0]) . ' statt ' . round($q0) . ' m³/s (' . ($x[0] > $q0 ? '+' : '') . round(($x[0] - $q0) / $q0 * 100) . ' %).'];
  // höhere Gefahrenstufe vorhergesagt
  [$L] = hydroLatest(); $wl = $L[$p['station']]['wl'] ?? null; $cur = (int)($L[$p['station']]['danger'] ?? 1);
  if ($f['danger'] && is_array($wl)) { $mx = 0; for ($h = 1; $h <= 96; $h++) { $y = flowAt($pr, $h); if (!$y) break; $mx = max($mx, $y[0]); } $g = 1; foreach ($wl as $i => $w) if ($w !== null && $mx >= $w) $g = $i + 2;
    if ($g > $cur) $notes['danger'] = ['Höhere Gefahrenstufe vorhergesagt', "$nm: Gefahrenstufe $g erwartet (jetzt $cur).", "Pegel und Abfluss, Vorhersage – $nm: Gefahrenstufe $g erwartet, jetzt $cur."]; }
  // neue BAFU-Vorhersage mit deutlich höherem Median
  if ($f['fc_new'] && $pr['fc_at']) { $k = "flow_fc_{$id}"; $old = json_decode(setting($k, ''), true); $med = flowFc($p['station']); $mm = $med ? max(array_map(fn($y) => $y[1], $med['s'])) : null;
    if ($mm !== null && (!$old || $old['at'] !== $pr['fc_at'])) { setSetting($k, json_encode(['at' => $pr['fc_at'], 'med' => $mm]));
      if ($old && $old['med'] > 0 && $mm >= $old['med'] * 1.2) $notes['fc_new'] = ['Neue BAFU-Vorhersage', "$nm: neue Vorhersage mit höherem Abfluss (höchstens " . round($mm) . ' statt ' . round($old['med']) . ' m³/s).', "Pegel und Abfluss, neue BAFU-Vorhersage – $nm vom " . date('d.m., H:i', $pr['fc_at']) . ': höchstens ' . round($mm) . ' statt ' . round($old['med']) . ' m³/s.']; } }
  if (!$notes) return;
  $to = flowTo($inc); $lvl = noteLvl($inc, 'msg');
  foreach ($notes as $k => [$t, $b, $log]) { $q = $db->prepare("SELECT MAX(at) FROM hydro_note WHERE incident_id=? AND kind=?"); $q->execute([(int)$id, $k]); if ((int)$q->fetchColumn() > $now - FLOW_NOTE_S) continue;
    $db->prepare("INSERT INTO hydro_note(incident_id,kind,at,title,text) VALUES(?,?,?,?,?)")->execute([(int)$id, $k, $now, $t, $b]); $nid = (int)$db->lastInsertId();
    logev($id, null, 'hydro', $log . ' ' . ($f['all'] ? 'Einsatzkräfte' : 'Einsatzleitung') . ' benachrichtigt.');
    if ($lvl !== 'none' && $to) pushTo($to, ['t' => $t, 'b' => $b . ' Antippen zum Ansehen.', 'tag' => "h:$nid"] + pushLvl($lvl));
    alog('info', 'hydro', 'Meldung zur Fliessgeschwindigkeit', ['incident' => (int)$id, 'kind' => $k]); } }
// Empfänger: Einsatzleitung (und Übungsleitung), mit «all» alle Teilnehmenden
function flowTo($inc) { global $db; $s = $db->prepare("SELECT user_id FROM participants WHERE incident_id=? AND mode<>'declined' AND left_at IS NULL"); $s->execute([(int)$inc['id']]); $o = [];
  $all = flowCfg()['all']; foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $u) if ($all || in_array('lead', voiceTok($inc, (int)$u), true)) $o[] = (int)$u;
  if ($xp = exPlan($inc)) $o = array_merge($o, exLeads($xp)); return array_values(array_unique($o)); }
// im Polling: höchstens alle FLOW_CHK_S je Einsatz im Nachlauf
function flowPoll($inc) { global $db, $now; if (!flowKind($inc)['note']) return; $id = (int)$inc['id'];
  $u = $db->prepare("UPDATE incidents SET flow_chk=? WHERE id=? AND (flow_chk IS NULL OR flow_chk<?)"); $u->execute([$now, $id, $now - FLOW_CHK_S]);
  if ($u->rowCount()) after(fn() => flowCheck($id)); }
// nachts: Kennlinien älter als 20 h neu berechnen, höchstens 3 je Lauf (Sperre über flow_auto_at)
function flowAuto() { global $db, $now; $h = (int)date('G', $now); if ($h < 1 || $h > 5) return; $at = (int)setting('flow_auto_at', '0'); if ($at > $now - 900) return;
  $u = $db->prepare("UPDATE settings SET v=? WHERE k='flow_auto_at' AND v=?"); $u->execute([(string)$now, (string)$at]); if (!$u->rowCount()) { if ($at === 0) setSetting('flow_auto_at', (string)$now); else return; }
  $ids = $db->query("SELECT station FROM hydro_curve WHERE manual=0 AND (upd IS NULL OR upd<" . ($now - 72000) . ") ORDER BY upd LIMIT 3")->fetchAll(PDO::FETCH_COLUMN);
  if ($ids) after(function () use ($ids) { foreach ($ids as $i) flowCalc($i); }); }

/* ---------- Abschnitte vorschlagen ---------- */
// aus Längenprofil (Gefälle, Sohlenbreite) und Bauwerken: lückenlose Abschnitte zwischen g_from (oben) und g_to (unten) auf dem Gewässer gwl; $sts = {Station: GEWISS-Adresse}, je Station ein eigener Abschnitt ±450 m, die übrigen gehören zur nächsten Station
function flowPropose($gwl, $gA, $gB, $sts, $grp) {
  $lp = flowLp($gwl, $gB, $gA) ?: []; $obj = flowObj($gwl, $gB, $gA); $bm = flowBmG($gwl, array_keys($sts), $gA, $gB);
  $in = array_filter($sts, fn($g) => $g < $gA && $g > $gB);
  $cuts = [[$gA, null]]; foreach ($obj as [$g, $k]) if ($g < $gA - 300 && $g > $gB + 300 && abs($g - end($cuts)[0]) > 400) $cuts[] = [$g, $k]; $cuts[] = [$gB, null];
  if ($in) { $cuts = array_values(array_filter($cuts, function ($x) use ($in, $gA, $gB) { if ($x[0] === $gA || $x[0] === $gB) return true; foreach ($in as $gs) if (abs($x[0] - $gs) <= 600) return false; return true; }));
    foreach ($in as $gs) { $cuts[] = [min($gA, $gs + 450), null]; $cuts[] = [max($gB, $gs - 450), null]; } usort($cuts, fn($x, $y) => $y[0] <=> $x[0]); }
  $ws = array_values(array_filter(array_map(fn($x) => $x[1], $lp))); sort($ws); $wMed = $ws ? $ws[intdiv(count($ws), 2)] : null; $o = [];
  for ($i = 1; $i < count($cuts); $i++) { [$a, $ka] = $cuts[$i - 1]; [$b, $kb] = $cuts[$i]; if ($a - $b < 100) { if ($o && $a - $b > 0) { $o[count($o) - 1]['g_to'] = $b; $o[count($o) - 1]['len_m'] = $o[count($o) - 1]['g_from'] - $b; } continue; }
    $mid = ($a + $b) / 2; $S = flowSlope($lp, $mid);
    $w = array_values(array_filter(array_map(fn($x) => $x[0] <= $a && $x[0] >= $b ? $x[1] : null, $lp))); $wmin = $w ? min($w) : null; $wmax = $w ? max($w) : null;
    $sIn = null; foreach ($in as $st => $gs) if ($gs <= $a && $gs >= $b) $sIn = (string)$st;
    $near = null; $nd = INF; foreach ($sts as $st => $gs) { $d = abs($gs - $mid); if ($d < $nd) { $nd = $d; $near = (string)$st; } } $station = $sIn ?? $near;
    $isSt = $sIn !== null; $stauDown = $kb === 'Wehr';
    $kind = $isSt && $wMed && $wmin && $wmin < 0.75 * $wMed ? 'eng' : ($stauDown || ($S !== null && $S < 0.0006) ? 'stau' : (($kb === 'Schwelle' || $kb === 'Blockrampe' || ($S !== null && $S > 0.0015)) ? 'schwellen' : ($wMed && $wmin && $wmin < 0.65 * $wMed ? 'eng' : 'frei')));
    $lbl = fn($g, $k) => ($k ? "$k " : '') . flowKm($g);
    $name = $lbl($a, $ka) . ' – ' . $lbl($b, $kb); if ($isSt) $name = (flowStations()[$sIn]['name'] ?? 'Station') . " (Station $sIn)";
    $o[] = ['grp' => $grp, 'name' => $name, 'kind' => $kind, 'gwl' => $gwl, 'g_from' => $a, 'g_to' => $b, 'pts' => flowPtsG($bm, $a, $b), 'len_m' => $a - $b, 'width' => $wmin ? ($wmin === $wmax ? round($wmin) : round($wmin) . '–' . round($wmax)) : null,
      'slope' => $S !== null ? flowNum($S * 1000) : null, 'fac' => $isSt ? 1.0 : FLOW_KINDS[$kind][2], 'station' => $station, 'src' => 'prop']; }
  return $o; }
// Vermessungspunkte des Gewässers gwl (über die Gewässernamen der Stationen) zwischen gA und gB, absteigend nach g
function flowBmG($gwl, $sts, $gA = PHP_INT_MAX, $gB = PHP_INT_MIN) { $rv = []; foreach ($sts as $st) if (($r = flowStations()[(string)$st]['river'] ?? '') !== '') $rv[$r] = 1;
  $bm = []; foreach (array_keys($rv) as $r) foreach (flowBm($r) as $x) if ($x[0] === $gwl && $x[1] <= $gA && $x[1] >= $gB) $bm[$x[1]] = $x;
  krsort($bm); return array_values($bm); }
// Linie eines Abschnitts aus den Vermessungspunkten: [[lat, lon, g]]
function flowPtsG($bm, $a, $b) { return array_values(array_map(fn($x) => [$x[2], $x[3], $x[1]], array_filter($bm, fn($x) => $x[1] <= $a + 150 && $x[1] >= $b - 150))); }
// Punkt auf dem Gewässer gwl fangen: GEWISS-Adresse oder null
function flowSnapG($gwl, $sts, $la, $lo) { $b = flowBmNear(flowBmG($gwl, $sts), (float)$la, (float)$lo, 1500); return $b ? (int)$b[1] : null; }
// von Hand gesetzte Abschnitte aus den vorgeschlagenen aussparen (Vorschlag lückenlos zwischen ihnen)
function flowClip($o, $man, $bm) { foreach ($man as [$ma, $mb]) { $n = [];
    foreach ($o as $r) { if ($mb >= $r['g_from'] || $ma <= $r['g_to']) { $n[] = $r; continue; }
      foreach ([[$r['g_from'], $ma], [$mb, $r['g_to']]] as [$a, $b]) if ($a - $b >= 100 && $a <= $r['g_from'] && $b >= $r['g_to']) $n[] = array_replace($r, ['g_from' => $a, 'g_to' => $b, 'len_m' => $a - $b, 'pts' => flowPtsG($bm, $a, $b)]); }
    $o = $n; } return $o; }
// Nachbarn eines geänderten Abschnitts anpassen, damit die Strecke lückenlos und ohne Überlappung bleibt: überdeckte entfallen, angeschnittene werden gekürzt bzw. geteilt, angrenzende schliessen eine Lücke
function flowNbFit($gwl, $id, $g0, $g1, $old0, $old1, $bm) { global $db; $s = $db->prepare("SELECT * FROM hydro_reach WHERE gwl=? AND id<>?"); $s->execute([$gwl, $id]);
  $up = $db->prepare("UPDATE hydro_reach SET g_from=?, g_to=?, len_m=?, pts=? WHERE id=?");
  foreach ($s->fetchAll() as $r) { $a = (int)$r['g_from']; $b = (int)$r['g_to']; $rid = (int)$r['id'];
    if ($old0 !== null && $b === $old0 && $g0 < $old0 && $a > $g0) { $up->execute([$a, $g0, $a - $g0, json_encode(flowPtsG($bm, $a, $g0)), $rid]); continue; } // oberer Nachbar schliesst die Lücke
    if ($old1 !== null && $a === $old1 && $g1 > $old1 && $b < $g1) { $up->execute([$g1, $b, $g1 - $b, json_encode(flowPtsG($bm, $g1, $b)), $rid]); continue; } // unterer Nachbar
    if ($b >= $g0 || $a <= $g1) continue; // keine Überlappung
    if ($a <= $g0 && $b >= $g1) { $db->prepare("DELETE FROM hydro_reach WHERE id=?")->execute([$rid]); continue; }
    if ($a > $g0 && $b < $g1) { $up->execute([$a, $g0, $a - $g0, json_encode(flowPtsG($bm, $a, $g0)), $rid]); // teilen: unterer Teil als Kopie
      $c = $r; unset($c['id']); $c = array_replace($c, ['g_from' => $g1, 'g_to' => $b, 'len_m' => $g1 - $b, 'pts' => json_encode(flowPtsG($bm, $g1, $b)), 'name' => flowSplitName((string)$r['name'], $g1, false)]);
      $db->prepare("INSERT INTO hydro_reach(" . implode(',', array_keys($c)) . ") VALUES(" . implode(',', array_fill(0, count($c), '?')) . ")")->execute(array_values($c)); continue; }
    if ($a > $g0) $up->execute([$a, $g0, $a - $g0, json_encode(flowPtsG($bm, $a, $g0)), $rid]); else $up->execute([$g1, $b, $g1 - $b, json_encode(flowPtsG($bm, $g1, $b)), $rid]); } }
// Bezeichnung eines geteilten Abschnitts: «A – B» wird zu «A – km X» (oben) bzw. «km X – B» (unten)
function flowSplitName($nm, $g, $top) { $pp = explode(' – ', $nm, 2); if (count($pp) === 2) return $top ? $pp[0] . ' – ' . flowKm($g) : flowKm($g) . ' – ' . $pp[1];
  return mb_substr($nm . ($top ? ' (oberhalb ' : ' (unterhalb ') . flowKm($g) . ')', 0, 100); }
// Gewässer mit Stationen der Flussvermessung: {gwl: {Station: GEWISS-Adresse}}
function flowGwls() { global $db; $o = []; foreach ($db->query("SELECT station,gwl,gewiss FROM hydro_curve WHERE gwl IS NOT NULL AND gewiss IS NOT NULL")->fetchAll() as $r) $o[(string)$r['gwl']][(string)$r['station']] = (int)$r['gewiss']; return $o; }
// Bezeichnung der Strecke eines Gewässers: Name des Gewässers (Stationen)
function flowGrpName($gwl, $sts) { $rv = []; foreach (array_keys($sts) as $st) if (($x = flowStations()[(string)$st]['river'] ?? '') !== '') $rv[$x] = 1; return mb_substr($rv ? implode(' / ', array_keys($rv)) : $gwl, 0, 80); }
function flowReachRows($grp = null) { global $db; $q = "SELECT * FROM hydro_reach" . ($grp !== null ? " WHERE grp=?" : '') . " ORDER BY grp, g_from DESC"; $s = $db->prepare($q); $s->execute($grp !== null ? [$grp] : []);
  return array_map(fn($r) => floats(ints($r, ['id', 'g_from', 'g_to', 'len_m', 'sort', 'active']), ['fac', 'mg']) + ['pts_n' => count($r['pts'])], array_map(function ($r) { $r['pts'] = json_decode((string)$r['pts'], true) ?: []; return $r; }, $s->fetchAll())); }
function flowAdmin($id) { $c = flowCurve($id); $locs = hydroLocs(); $st = flowStations(); [$L] = hydroLatest(); $q = $L[$id]['flow'] ?? null;
  return ['station' => $id, 'name' => $locs[$id]['name'] ?? $st[$id]['name'] ?? ($c['name'] ?? $id), 'water' => $locs[$id]['water'] ?? $st[$id]['river'] ?? ($c['river'] ?? ''), 'curve' => $c,
    'lat' => $locs[$id]['lat'] ?? $st[$id]['lat'] ?? null, 'lon' => $locs[$id]['lon'] ?? $st[$id]['lon'] ?? null, 'q' => $q, 'v' => $c ? flowV($c, $q) : null, 'drift' => $c && $q ? flowDrift($c, $q) : null, 'fc' => isset(flowFcList()[$id]) ? 1 : 0,
    'wl' => $L[$id]['wl'] ?? null, 'danger' => $L[$id]['danger'] ?? null]; } // wl = Abfluss ab Gefahrenstufe 2–5 (BAFU), für das Querprofil

/* ---------- Routen (vor dem switch) ---------- */
function flowRoute($route) { global $db, $in, $now;
  flowAuto();
  if (!str_starts_with($route, 'hydro/') || in_array($route, ['hydro/status', 'hydro/test'], true)) return;
  switch ($route) {
  case 'hydro/flow': // Fliessgeschwindigkeit an Stationen (Popup, Messstationen): ids
    $inc = activeIncident(); if (!hydroOn() && !($inc && feature($inc, 'hydro'))) fail('Pegel & Abfluss ist ausgeschaltet', 403);
    out(['flow' => (object)flowStationsV((array)($in['ids'] ?? [])), 'f_lo' => flowCfg()['f_lo'], 'f_hi' => flowCfg()['f_hi'], 'mg' => flowCfg()['mg']]);
  case 'hydro/along': // Flussdistanz (Werkzeug): Abschnitte zwischen A und B ([lat, lon]) mit Treibzeit
    $inc = activeIncident(); if (!hydroOn() && !($inc && feature($inc, 'hydro'))) fail('Pegel & Abfluss ist ausgeschaltet', 403);
    $A = $in['a'] ?? null; $B = $in['b'] ?? null; if (!is_numeric($A[0] ?? null) || !is_numeric($B[0] ?? null)) fail('Punkte fehlen');
    $sel = flowStationFor((float)$A[0], (float)$A[1]); if (!$sel || !$sel[6]) out(['reaches' => []]); $c = flowCurve($sel[0]); $river = flowStations()[$sel[0]]['river'] ?? '';
    $bm = $river !== '' ? flowBm($river) : []; $a = flowBmNear($bm, (float)$A[0], (float)$A[1], 1500); $b = flowBmNear($bm, (float)$B[0], (float)$B[1], 1500);
    if (!$c || !$a || !$b || $a[0] !== $b[0]) out(['reaches' => []]); $p = flowPred($sel[0]);
    $rs = flowReachV(flowReachesBetween($a[0], max($a[1], $b[1]), min($a[1], $b[1])), $c, $p);
    out(['reaches' => array_map(fn($r) => array_diff_key($r, ['pts' => 1]), $rs), 'station' => $sel[0]]);
  case 'hydro/curve/view': // Kennlinie ansehen (Einsatz-App: Popup und Liste der Messstationen), nur mit berechneter Kennlinie
    $inc = activeIncident(); if (!hydroOn() && !($inc && feature($inc, 'hydro'))) fail('Pegel & Abfluss ist ausgeschaltet', 403);
    $id = (string)($in['id'] ?? ''); if (!preg_match(FLOW_ID, $id)) fail('Ungültige Station'); $r = flowAdmin($id);
    if (!$r['curve'] || $r['curve']['a'] === null) fail('Keine Kennlinie für diese Station', 404);
    out($r + ['f_lo' => flowCfg()['f_lo'], 'f_hi' => flowCfg()['f_hi'], 'mg' => flowCfg()['mg']]);
  case 'hydro/curves': need('cfg_map'); // Verwaltung: Stationen mit Kennlinie
    $ids = $db->query("SELECT station FROM hydro_curve")->fetchAll(PDO::FETCH_COLUMN);
    out(['stations' => array_values(array_map('flowAdmin', $ids)), 'kinds' => FLOW_KINDS, 'cfg' => flowCfg(), 'num' => FLOW_NUM, 'all' => array_values(array_map(fn($s) => ['id' => $s['no'], 'name' => $s['name'], 'water' => $s['river']], flowStations()))]);
  case 'hydro/curve': need('cfg_map'); $id = (string)($in['id'] ?? ''); if (!preg_match(FLOW_ID, $id)) fail('Ungültige Station'); out(flowAdmin($id));
  case 'hydro/curve/calc': need('cfg_map'); $id = (string)($in['id'] ?? ''); if (!preg_match(FLOW_ID, $id) || !isset(flowStations()[$id])) fail('Station auf der BAFU-Datenplattform nicht gefunden');
    set_time_limit(120); if (!empty($in['reset'])) $db->prepare("UPDATE hydro_curve SET manual=0 WHERE station=?")->execute([$id]);
    flowCalc($id); @unlink(__DIR__ . "/data/hydro/pred_$id.json"); out(flowAdmin($id));
  case 'hydro/curve/save': need('cfg_map'); // von Hand: a, m (manual 1) bzw. manual 0
    $id = (string)($in['id'] ?? ''); if (!preg_match(FLOW_ID, $id)) fail('Ungültige Station');
    if (isset($in['manual'])) { if (!flowCurve($id)) $db->prepare("INSERT INTO hydro_curve(station,src,upd) VALUES(?,'none',?)")->execute([$id, $now]);
      if ($in['manual']) { $a = (float)($in['a'] ?? 0); $m = (float)($in['m'] ?? 0); if ($a <= 0 || $a > 5 || $m <= 0 || $m > 1.2) fail('Kennlinie: a zwischen 0 und 5, m zwischen 0 und 1.2');
        $db->prepare("UPDATE hydro_curve SET manual=1, a=?, m=?, src='manual', upd=? WHERE station=?")->execute([$a, $m, $now, $id]); }
      else { $db->prepare("UPDATE hydro_curve SET manual=0 WHERE station=?")->execute([$id]); set_time_limit(120); flowCalc($id); } }
    @unlink(__DIR__ . "/data/hydro/pred_$id.json"); out(flowAdmin($id));
  case 'hydro/curve/remove': need('cfg_map'); $id = (string)($in['id'] ?? ''); $db->prepare("DELETE FROM hydro_curve WHERE station=?")->execute([$id]); out(['ok' => true]);
  case 'hydro/reaches': need('cfg_map'); $rs = flowReachRows();
    $grps = []; foreach ($rs as $r) $grps[(string)$r['grp']] ??= ['grp' => (string)$r['grp'], 'gwl' => $r['gwl'], 'station' => $r['station']];
    $rv = []; foreach (flowGwls() as $g => $sts) $rv[] = ['gwl' => (string)$g, 'name' => flowGrpName((string)$g, $sts), 'stations' => array_keys($sts)];
    out(['reaches' => $rs, 'groups' => array_values($grps), 'kinds' => FLOW_KINDS, 'rivers' => $rv]);
  case 'hydro/reach/propose': need('cfg_map'); // Vorschlag lückenlos je Gewässer mit allen Stationen der Flussvermessung: von up_km oberhalb der obersten bis down_km unterhalb der untersten; gwl bzw. station = nur dieses Gewässer, sonst alle; von Hand gesetzte bleiben und werden ausgespart
    set_time_limit(300); $up = (int)(min(60, max(1, (float)($in['up_km'] ?? 10))) * 1000); $dn = (int)(min(30, max(0.5, (float)($in['down_km'] ?? 3))) * 1000);
    $only = (string)($in['gwl'] ?? ''); if ($only === '' && ($id = (string)($in['station'] ?? '')) !== '') { $c = flowCurve($id); if (!$c || !$c['gwl'] || $c['gewiss'] === null) fail('Für diese Station gibt es keine Flussvermessung (Kennlinie zuerst berechnen)'); $only = $c['gwl']; }
    $G = flowGwls(); if ($only !== '') $G = array_intersect_key($G, [$only => 1]); if (!$G) fail('Keine Station mit Flussvermessung. Zuerst eine Station erfassen und ihre Kennlinie berechnen.');
    $n = 0; $done = []; $err = [];
    foreach ($G as $gwl => $sts) { $gwl = (string)$gwl; $gA = max($sts) + $up; $gB = max(0, min($sts) - $dn); $grp = flowGrpName($gwl, $sts);
      $o = flowPropose($gwl, $gA, $gB, $sts, $grp); if (!$o) { $err[] = $grp; continue; }
      $m = $db->prepare("SELECT g_from,g_to FROM hydro_reach WHERE gwl=? AND src<>'prop'"); $m->execute([$gwl]); $o = flowClip($o, array_map(fn($r) => [(int)$r['g_from'], (int)$r['g_to']], $m->fetchAll()), flowBmG($gwl, array_keys($sts)));
      $db->beginTransaction(); $db->prepare("DELETE FROM hydro_reach WHERE gwl=? AND src='prop'")->execute([$gwl]); $db->prepare("UPDATE hydro_reach SET grp=? WHERE gwl=?")->execute([$grp, $gwl]);
      $i = $db->prepare("INSERT INTO hydro_reach(grp,name,kind,gwl,g_from,g_to,pts,len_m,width,slope,fac,station,src,active) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,1)");
      foreach ($o as $r) $i->execute([$r['grp'], $r['name'], $r['kind'], $r['gwl'], $r['g_from'], $r['g_to'], json_encode($r['pts']), $r['len_m'], $r['width'], $r['slope'], $r['fac'], $r['station'], 'prop']);
      $db->commit(); $n += count($o); $done[] = $grp; }
    if (!$done) fail('Kein Längenprofil gefunden: ' . ($GLOBALS['BAFU_ERR'] ?? 'leer'));
    out(['ok' => true, 'n' => $n, 'grps' => $done, 'err' => $err]);
  case 'hydro/reach/save': need('cfg_map'); // Abschnitt speichern: km_from/km_to oder auf der Karte pa/pb [lat, lon] (gefangen auf das Gewässer); fit = Nachbarn anpassen (lückenlos)
    $id = (int)($in['id'] ?? 0); $k = (string)($in['kind'] ?? ''); if (!isset(FLOW_KINDS[$k])) fail('Unbekannte Art');
    $nm = trim((string)($in['name'] ?? '')); $fac = (float)($in['fac'] ?? 0); if ($fac <= 0 || $fac > 2) fail('Faktor zwischen 0 und 2');
    $mg = ($in['mg'] ?? '') === '' || $in['mg'] === null ? null : (float)$in['mg']; if ($mg !== null && ($mg < 0 || $mg > 100)) fail('Zusatzmarge zwischen 0 und 100 %');
    $r = null; if ($id) { $s = $db->prepare("SELECT * FROM hydro_reach WHERE id=?"); $s->execute([$id]); $r = $s->fetch(); $s->closeCursor(); if (!$r) fail('Abschnitt nicht gefunden', 404); $gwl = (string)$r['gwl']; }
    else { $gwl = (string)($in['gwl'] ?? ''); if ($gwl === '' && ($st = (string)($in['station'] ?? '')) !== '') { $c = flowCurve($st); $gwl = (string)($c['gwl'] ?? ''); } }
    $G = flowGwls(); $sts = $G[$gwl] ?? null; if (!$sts) fail('Gewässer ohne Station mit Flussvermessung');
    if (is_array($in['pa'] ?? null) && is_array($in['pb'] ?? null)) { $ga = flowSnapG($gwl, array_keys($sts), $in['pa'][0] ?? 0, $in['pa'][1] ?? 0); $gb = flowSnapG($gwl, array_keys($sts), $in['pb'][0] ?? 0, $in['pb'][1] ?? 0);
      if ($ga === null || $gb === null) fail('Punkt nicht am Gewässer (höchstens 1.5 km von der Flussvermessung)'); $g0 = max($ga, $gb); $g1 = min($ga, $gb); }
    else { $g0 = (int)round((float)($in['km_from'] ?? 0) * 1000); $g1 = (int)round((float)($in['km_to'] ?? 0) * 1000); }
    if ($g0 - $g1 < 100) fail('Ein Abschnitt ist mindestens 100 m lang («von km» oberhalb, also grösser als «bis km»)');
    if ($nm === '') $nm = flowKm($g0) . ' – ' . flowKm($g1);
    $bm = flowBmG($gwl, array_keys($sts)); $pts = $r && $g0 === (int)$r['g_from'] && $g1 === (int)$r['g_to'] ? (json_decode((string)$r['pts'], true) ?: []) : flowPtsG($bm, $g0, $g1);
    $station = $r ? (string)$r['station'] : (string)($in['station'] ?? ''); if (!isset($sts[$station])) { $mid = ($g0 + $g1) / 2; $nd = INF; foreach ($sts as $st => $gs) if (abs($gs - $mid) < $nd) { $nd = abs($gs - $mid); $station = (string)$st; } }
    $db->beginTransaction();
    if ($r) $db->prepare("UPDATE hydro_reach SET name=?, kind=?, fac=?, mg=?, g_from=?, g_to=?, len_m=?, pts=?, src='manual', active=? WHERE id=?")->execute([mb_substr($nm, 0, 100), $k, $fac, $mg, $g0, $g1, $g0 - $g1, json_encode($pts), empty($in['off']) ? 1 : 0, $id]);
    else { $db->prepare("INSERT INTO hydro_reach(grp,name,kind,gwl,g_from,g_to,pts,len_m,fac,mg,station,src,active) VALUES(?,?,?,?,?,?,?,?,?,?,?,'manual',1)")->execute([flowGrpName($gwl, $sts), mb_substr($nm, 0, 100), $k, $gwl, $g0, $g1, json_encode($pts), $g0 - $g1, $fac, $mg, $station]); $id = (int)$db->lastInsertId(); }
    if (!empty($in['fit'])) flowNbFit($gwl, $id, $g0, $g1, $r ? (int)$r['g_from'] : null, $r ? (int)$r['g_to'] : null, $bm);
    $db->commit(); out(['ok' => true, 'id' => $id]);
  case 'hydro/reach/split': need('cfg_map'); // Abschnitt teilen: at [lat, lon] (gefangen auf das Gewässer) oder km; beide Teile gelten danach als von Hand gesetzt
    $id = (int)($in['id'] ?? 0); $s = $db->prepare("SELECT * FROM hydro_reach WHERE id=?"); $s->execute([$id]); $r = $s->fetch(); $s->closeCursor(); if (!$r) fail('Abschnitt nicht gefunden', 404);
    $gwl = (string)$r['gwl']; $sts = flowGwls()[$gwl] ?? [(string)$r['station'] => 0];
    $g = is_array($in['at'] ?? null) ? flowSnapG($gwl, array_keys($sts), $in['at'][0] ?? 0, $in['at'][1] ?? 0) : (isset($in['km']) ? (int)round((float)$in['km'] * 1000) : null);
    if ($g === null) fail('Punkt nicht am Gewässer'); $a = (int)$r['g_from']; $b = (int)$r['g_to']; if ($g >= $a - 50 || $g <= $b + 50) fail('Der Punkt liegt nicht innerhalb des Abschnitts (' . flowKm($a) . ' bis ' . flowKm($b) . ')');
    $bm = flowBmG($gwl, array_keys($sts)); $db->beginTransaction();
    $db->prepare("UPDATE hydro_reach SET g_to=?, len_m=?, pts=?, name=?, src='manual' WHERE id=?")->execute([$g, $a - $g, json_encode(flowPtsG($bm, $a, $g)), flowSplitName((string)$r['name'], $g, true), $id]);
    $c = $r; unset($c['id']); $c = array_replace($c, ['g_from' => $g, 'g_to' => $b, 'len_m' => $g - $b, 'pts' => json_encode(flowPtsG($bm, $g, $b)), 'name' => flowSplitName((string)$r['name'], $g, false), 'src' => 'manual']);
    $db->prepare("INSERT INTO hydro_reach(" . implode(',', array_keys($c)) . ") VALUES(" . implode(',', array_fill(0, count($c), '?')) . ")")->execute(array_values($c)); $nid = (int)$db->lastInsertId();
    $db->commit(); out(['ok' => true, 'id' => $id, 'new' => $nid, 'km' => round($g / 1000, 1)]);
  case 'hydro/reach/remove': need('cfg_map'); $db->prepare("DELETE FROM hydro_reach WHERE id=?")->execute([(int)($in['id'] ?? 0)]); out(['ok' => true]);
  } }
