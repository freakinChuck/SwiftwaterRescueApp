<?php
/* ---------- Einsatzkonzepte (Issue #260) ----------
 Eingebunden von index.php nach docs.php. Vorbereitete Lagen für einen Gewässerabschnitt: Posten, Markierungen, Bereiche, Linien, Externe, Nachricht,
 Dokumente, Flusssperren, Funkkanäle, Nachbieten, Helikopter und Aufzeichnung. Geltungsbereich = Bereich auf der Karte (Polygon) oder entlang eines
 Gewässers von A bis B (Linien des Gewässernetzes, im Browser berechnet, mit Puffer in Metern). Der Server prüft nur «Punkt im Polygon» bzw. «Abstand zur Linie».
 Liegt ein PLS im Geltungsbereich, gilt das Verhalten des Konzepts bzw. des Einsatztyps (Einstellung cc_kinds): off, hand, suggest, auto.
 Geladene Elemente sind gewöhnliche Markierungen (pois) und Externe (externals) mit Herkunft cc_id (= inc_concepts.id) und cc_item.
 Aktionen mit Meldungen an Personen (Nachricht, Zuteilung der Posten, Nachbieten) löst immer eine Person aus: beim automatischen Laden bleiben sie offen (pend). */

foreach ([
  // scope area|river; area = JSON [[lat,lon],…]; river = JSON {a:[lat,lon], b:[lat,lon], buf: Meter, lines:[[[lat,lon],…],…]}; mode NULL = wie Einsatztyp; kinds NULL = alle Einsatztypen
  "CREATE TABLE IF NOT EXISTS concepts(id $ai, name $tx, descr TEXT NULL, scope $tx, area MEDIUMTEXT NULL, river MEDIUMTEXT NULL, mode $tx NULL, kinds TEXT NULL, sort INT NULL, active INT DEFAULT 1, created BIGINT NULL, created_by INT NULL, updated BIGINT NULL, updated_by INT NULL)",
  // kind aus CC_KINDS; refs = JSON je Art (siehe ccItemIn); def_on = beim Laden vorausgewählt
  "CREATE TABLE IF NOT EXISTS concept_items(id $ai, concept_id INT, kind $tx, name $tx, descr TEXT NULL, color $tx NULL, sym $tx NULL, abbr $tx NULL, role_id INT NULL, lat DOUBLE NULL, lon DOUBLE NULL, pts TEXT NULL, text TEXT NULL, refs TEXT NULL, def_on INT DEFAULT 1, sort INT NULL)",
  // je Einsatz und Konzept höchstens eine Zeile: state suggest|loaded|dismissed|removed; done = {Element-ID: [Art, ID]}; pend = offene Aktionen (Element-IDs); name = Name beim Laden
  "CREATE TABLE IF NOT EXISTS inc_concepts(id $ai, incident_id INT, concept_id INT, name $tx, state $tx, auto INT DEFAULT 0, at BIGINT, by_user INT NULL, run_by INT NULL, done TEXT NULL, pend TEXT NULL, removed_at BIGINT NULL, removed_by INT NULL)",
] as $s) $db->exec($s);
foreach (['pois' => ['cc_id INT NULL', 'cc_item INT NULL', 'cc_role INT NULL', 'cc_users TEXT NULL', 'cc_sent TEXT NULL'], 'externals' => ['cc_id INT NULL', 'cc_item INT NULL'], 'incidents' => ['cc_key TEXT NULL', 'cc_fit TEXT NULL']] as $t => $cs)
  foreach ($cs as $c) { try { $db->exec("ALTER TABLE $t ADD COLUMN $c"); } catch (Throwable $e) {} }

// Arten der Elemente: Schlüssel => [Bezeichnung, Funktion (FEATURES) oder null, braucht eine Person zum Auslösen]
const CC_KINDS = ['post' => ['Posten', 'poi', 0], 'point' => ['Markierung', 'poi', 0], 'area' => ['Bereich', 'poi', 0], 'line' => ['Linie', 'poi', 0], 'ext' => ['Externe Einsatzkraft', 'ext', 0],
  'msg' => ['Nachricht an alle', 'msg', 1], 'doc' => ['Dokumente', 'docs', 0], 'stop' => ['Flusssperre', 'area', 0], 'voice' => ['Funkkanäle', 'voice', 0], 'invite' => ['Nachbieten', null, 1],
  'opt' => ['Einstellung des Einsatzes', null, 0]];
const CC_OPTS = ['air' => 'Helikopter einschalten', 'rec' => 'Aufzeichnung starten'];
// Verhalten, wenn ein PLS im Geltungsbereich liegt
const CC_MODES = ['off' => 'Aus', 'hand' => 'Nur von Hand', 'suggest' => 'Vorschlagen', 'auto' => 'Automatisch laden'];
const CC_ITEMS_MAX = 100; const CC_PTS_MAX = 20000; const CC_BUF = 150; // Puffer entlang Gewässer in Metern (Standard)
const CC_POST_M = 150; // Posten gilt als besetzt, wenn eine Person mit der Rolle höchstens so weit entfernt ist (nur Anzeige, im Gerät)

// Verhalten je Einsatztyp: {Typ-ID: {mode, next}}; Vorgabe: Typen mit vorbereiteten Übungen «Nur von Hand», übrige «Vorschlagen»; next = Anschlusskonzepte vorschlagen
function ccKindCfg($kid = null) { $v = json_decode((string)setting('cc_kinds', ''), true); $v = is_array($v) ? $v : []; $r = [];
  foreach (kinds() as $id => $k) { $x = is_array($v[(string)$id] ?? null) ? $v[(string)$id] : [];
    $r[(string)$id] = ['mode' => isset(CC_MODES[$x['mode'] ?? '']) ? $x['mode'] : ($k['plans'] ? 'hand' : 'suggest'), 'next' => array_key_exists('next', $x) ? ((int)$x['next'] ? 1 : 0) : 1]; }
  return $kid === null ? $r : ($r[(string)(int)$kid] ?? ['mode' => 'hand', 'next' => 1]); }
function ccMode($c, $kid) { return $c['mode'] !== null && isset(CC_MODES[$c['mode']]) ? $c['mode'] : ccKindCfg($kid)['mode']; }
function ccJ($v, $d = null) { $j = is_string($v) ? json_decode($v, true) : $v; return $j === null ? $d : $j; }
function ccRows($active = true) { global $db; return $db->query("SELECT * FROM concepts" . ($active ? " WHERE active=1" : "") . " ORDER BY active DESC, " . SORT_ORDER)->fetchAll(); }
function ccRow($id) { global $db; $s = $db->prepare("SELECT * FROM concepts WHERE id=?"); $s->execute([(int)$id]); return $s->fetch() ?: fail('Einsatzkonzept nicht gefunden', 404); }
function ccItems($cid) { global $db; $s = $db->prepare("SELECT * FROM concept_items WHERE concept_id=? ORDER BY sort, id"); $s->execute([(int)$cid]); return array_map('ccItemPub', $s->fetchAll()); }
function ccItemPub($r) { $r = floats(ints($r, ['id', 'concept_id', 'role_id', 'def_on', 'sort']), ['lat', 'lon']); $r['pts'] = ccJ($r['pts']); $r['refs'] = ccJ($r['refs'], []) ?: (object)[]; return $r; }
function ccPub($c, $items = true) { $o = ints($c, ['id', 'sort', 'active', 'created', 'created_by', 'updated', 'updated_by']); $o['area'] = ccJ($c['area']); $o['river'] = ccJ($c['river']); $o['kinds'] = ccJ($c['kinds']);
  if ($items) $o['items'] = ccItems($c['id']); return $o; }
function ccForKind($c, $kid) { $k = ccJ($c['kinds']); return !is_array($k) || in_array((int)$kid, array_map('intval', $k), true); }

/* --- Geometrie: Punkt im Polygon, Abstand zu Linien (lokale Projektion, genügt für Abschnitte von wenigen Kilometern) --- */
function ccPip($la, $lo, $poly) { $in = false; $n = count($poly);
  for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) { [$ya, $xa] = $poly[$i]; [$yb, $xb] = $poly[$j];
    if (($ya > $la) !== ($yb > $la) && $lo < ($xb - $xa) * ($la - $ya) / (($yb - $ya) ?: 1e-12) + $xa) $in = !$in; }
  return $in; }
function ccSegDist($la, $lo, $a, $b) { $k = 111320 * cos(deg2rad($la)); $px = ($lo - $a[1]) * $k; $py = ($la - $a[0]) * 111320; $bx = ($b[1] - $a[1]) * $k; $by = ($b[0] - $a[0]) * 111320;
  $l = $bx * $bx + $by * $by; $t = $l ? max(0, min(1, ($px * $bx + $py * $by) / $l)) : 0; return hypot($px - $t * $bx, $py - $t * $by); }
function ccHit($c, $la, $lo) { if ($c['scope'] === 'river') { $r = ccJ($c['river'], []); $buf = (float)($r['buf'] ?? CC_BUF);
    foreach ((array)($r['lines'] ?? []) as $l) for ($i = 1; $i < count($l); $i++) if (ccSegDist($la, $lo, $l[$i - 1], $l[$i]) <= $buf) return true; return false; }
  $a = ccJ($c['area'], []); return count($a) >= 3 && ccPip($la, $lo, $a); }
// Konzepte, deren Geltungsbereich einen PLS des Einsatzes enthält
function ccFit($inc) { $src = plsSources($inc, incRes($inc)); if (!$src) return []; $r = [];
  foreach (ccRows() as $c) if (ccForKind($c, $inc['kind_id'])) foreach ($src as $p) if (ccHit($c, $p['lat'], $p['lon'])) { $r[] = (int)$c['id']; break; }
  return $r; }

/* --- im Einsatz --- */
function ccIncRows($incId, $all = false) { global $db; $s = $db->prepare("SELECT i.*,u.name by_name FROM inc_concepts i LEFT JOIN users u ON u.id=i.by_user WHERE i.incident_id=?" . ($all ? '' : " AND i.state<>'removed'") . " ORDER BY i.id"); $s->execute([(int)$incId]); return $s->fetchAll(); }
function ccIncRow($inc, $id) { global $db; $s = $db->prepare("SELECT * FROM inc_concepts WHERE id=? AND incident_id=?"); $s->execute([(int)$id, (int)$inc['id']]); return $s->fetch() ?: fail('Einsatzkonzept im Einsatz nicht gefunden', 404); }
// nach jeder Änderung der PLS (geprüft im Polling über einen Schlüssel der PLS, Sperre über das UPDATE): Vorschläge anlegen bzw. automatisch laden
function ccCheck($inc) { global $db; if (!feature($inc, 'cc')) return false;
  $key = md5(json_encode(array_map(fn($p) => [round($p['lat'], 6), round($p['lon'], 6)], plsSources($inc, incRes($inc)))) . '|' . (int)$inc['kind_id']);
  if (($inc['cc_key'] ?? null) === $key) return false;
  $u = $db->prepare("UPDATE incidents SET cc_key=? WHERE id=? AND (cc_key IS NULL OR cc_key<>?)"); $u->execute([$key, $inc['id'], $key]); if (!$u->rowCount()) return false;
  $fit = ccFit($inc); $db->prepare("UPDATE incidents SET cc_fit=? WHERE id=?")->execute([json_encode($fit), $inc['id']]);
  $have = array_map('intval', array_column(ccIncRows($inc['id'], true), 'concept_id'));
  foreach ($fit as $cid) { if (in_array($cid, $have, true)) continue; $c = ccRow($cid); $m = ccMode($c, $inc['kind_id']);
    if ($m === 'suggest') { $db->prepare("INSERT INTO inc_concepts(incident_id,concept_id,name,state,at) VALUES(?,?,?,?,?)")->execute([$inc['id'], $cid, $c['name'], 'suggest', time()]);
      logev($inc['id'], null, 'cc', 'schlägt das Einsatzkonzept «' . $c['name'] . '» vor (PLS im Geltungsbereich)'); }
    elseif ($m === 'auto') ccLoad($inc, $c, null, true); }
  return true; }
// Dokumente geladener Konzepte (Fenster «Dokumente», «Für diesen Einsatz»)
function ccDocIds($inc) { if (!$inc || !feature($inc, 'cc')) return []; $r = [];
  foreach (ccIncRows($inc['id']) as $x) if ($x['state'] === 'loaded') foreach (ccJ($x['done'], []) ?: [] as $d) if (($d[0] ?? '') === 'doc') $r = array_merge($r, array_map('intval', (array)($d[1] ?? [])));
  return array_values(array_unique($r)); }
// Datenschutzauszug: Posten in Einsatzkonzepten, denen die Person zugeteilt ist
function ccDsar($u) { global $db; $r = []; try { foreach ($db->query("SELECT i.name,i.refs,c.name cn FROM concept_items i JOIN concepts c ON c.id=i.concept_id WHERE i.kind='post'")->fetchAll() as $x)
  if (in_array((int)$u, idsIn((json_decode((string)$x['refs'], true) ?: [])['users'] ?? []), true)) $r[] = ['concept' => $x['cn'], 'post' => $x['name']]; } catch (Throwable $e) {} return $r; }
// Zustand fürs Polling: geladene Konzepte (alle, für die Gruppen im Fenster «Markierungen»), Vorschläge und Passende nur für die Einsatzleitung
function ccState($inc) { global $db; if (!feature($inc, 'cc')) return null; $cm = canManage($inc); $rows = ccIncRows($inc['id']); $its = [];
  $s = $db->prepare("SELECT id,concept_id,kind,name FROM concept_items WHERE concept_id IN (SELECT concept_id FROM inc_concepts WHERE incident_id=?)"); $s->execute([$inc['id']]); foreach ($s->fetchAll() as $x) $its[(int)$x['id']] = $x;
  $loaded = []; $sug = [];
  foreach ($rows as $r) { $done = ccJ($r['done'], []) ?: []; $pend = ccJ($r['pend'], []) ?: [];
    $o = ['id' => (int)$r['id'], 'concept_id' => (int)$r['concept_id'], 'name' => $r['name'], 'at' => (int)$r['at'], 'auto' => (int)$r['auto'], 'by_name' => $r['by_name'], 'n' => count($done)];
    if ($r['state'] === 'loaded') { $o['docs'] = []; foreach ($done as $iid => $d) if (($d[0] ?? '') === 'doc') $o['docs'] = array_merge($o['docs'], array_map('intval', (array)($d[1] ?? [])));
      if ($cm) $o['pend'] = array_values(array_filter(array_map(fn($i) => isset($its[$i]) ? ['item' => (int)$i, 'kind' => $its[$i]['kind'], 'name' => $its[$i]['name']] : null, array_map('intval', $pend))));
      $loaded[] = $o; }
    elseif ($r['state'] === 'suggest' && $cm) { $o['n'] = count(array_filter($its, fn($x) => (int)$x['concept_id'] === (int)$r['concept_id'])); $sug[] = $o; } }
  $o = ['loaded' => $loaded, 'can' => $cm ? 1 : 0];
  if ($cm) { $o['sug'] = $sug; $f = $db->prepare("SELECT cc_fit FROM incidents WHERE id=?"); $f->execute([$inc['id']]); $o['fit'] = array_map('intval', ccJ($f->fetchColumn(), []) ?: []); $o['dismissed'] = array_map('intval', array_column(array_filter(ccIncRows($inc['id'], true), fn($r) => $r['state'] === 'dismissed'), 'concept_id'));
    $o['next'] = ccKindCfg($inc['kind_id'])['next']; $o['ver'] = (int)($db->query("SELECT MAX(COALESCE(updated,created,0)) FROM concepts")->fetchColumn() ?: 0) + count(ccRows()); }
  return $o; }
// Personen eines Postens: zugeteilte Personen und Mitglieder der zugeteilten Gruppen
function ccPostUsers($refs) { $u = idsIn($refs['users'] ?? []); $g = idsIn($refs['groups'] ?? []); return array_values(array_unique(array_merge($u, $g ? grpMembers($g) : []))); }
// eine Nachricht der Person $from (Einsatzleitung) an $to (null = alle Teilnehmenden), mit Push wie incident/message
function ccMessage($inc, $from, $text, $to = null, $mark = null) { global $db, $now; if ($text === '' || !feature($inc, 'msg')) return;
  $s = $db->prepare("SELECT user_id FROM participants WHERE incident_id=? AND mode<>'declined' AND left_at IS NULL"); $s->execute([$inc['id']]); $act = array_map('intval', array_column($s->fetchAll(), 'user_id'));
  if ($to !== null) { $to = array_values(array_intersect($to, $act)); if (!$to) return; }
  $p = part($inc['id'], $from); $la = $p && $p['lat'] !== null ? (float)$p['lat'] : null; $lo = $p && $p['lon'] !== null ? (float)$p['lon'] : null;
  $db->prepare("INSERT INTO messages(incident_id,from_user,text,recipients,created,lat,lon,mark,mark_lat,mark_lon) VALUES(?,?,?,?,?,?,?,?,?,?)")
     ->execute([$inc['id'], $from, $text, $to === null ? null : json_encode($to), $now, $la, $lo, $mark ? 1 : 0, $mark[0] ?? null, $mark[1] ?? null]);
  $s = $db->prepare("SELECT name FROM users WHERE id=?"); $s->execute([$from]); $fn = $s->fetchColumn() ?: '';
  if (($ml = noteLvl($inc, 'msg')) !== 'none') pushTo(array_diff($to ?? $act, [$from]), ['t' => 'Nachricht von ' . $fn, 'b' => mb_substr($text, 0, 300), 'tag' => 'm:' . $db->lastInsertId()] + pushLvl($ml)); }
// Zuteilung eines Postens an die teilnehmenden zugeteilten Personen: Nachricht mit Markierung, auf Wunsch Spezialrolle; jede Person nur einmal (cc_sent)
function ccAssign($inc, $poi, $item, $from, $only = null) { global $db; $refs = ccJ($item['refs'], []) ?: []; $all = ccPostUsers($refs); if (!$all) return 0;
  $sent = array_map('intval', ccJ($poi['cc_sent'], []) ?: []); $s = $db->prepare("SELECT user_id,mode FROM participants WHERE incident_id=? AND mode<>'declined' AND left_at IS NULL"); $s->execute([$inc['id']]);
  $go = []; foreach ($s->fetchAll() as $p) { $u = (int)$p['user_id']; if (!in_array($u, $all, true) || in_array($u, $sent, true) || ($only !== null && $u !== $only)) continue; $go[$u] = $p['mode']; }
  if (!$go) return 0; $rn = null;
  if ($item['role_id']) { $r = $db->prepare("SELECT name FROM roles WHERE id=? AND active=1"); $r->execute([(int)$item['role_id']]); $rn = $r->fetchColumn() ?: null; }
  $vn = null; if (!empty($refs['veh'])) { $r = $db->prepare("SELECT name FROM vehicles WHERE id=?"); $r->execute([(int)$refs['veh']]); $vn = $r->fetchColumn() ?: null; }
  $pt = ccJ($poi['pts'], [])[0] ?? null;
  $txt = 'Auftrag: ' . $poi['name'] . ($rn ? " ($rn)" : '') . ($vn ? ", Fahrzeug $vn" : '') . ($item['descr'] ? ' – ' . $item['descr'] : '');
  ccMessage($inc, $from, $txt, array_keys($go), $pt);
  $nm = [];
  foreach ($go as $u => $mode) { $q = $db->prepare("SELECT name FROM users WHERE id=?"); $q->execute([$u]); $nm[] = $q->fetchColumn();
    if (!empty($refs['sr']) && $item['role_id'] && $rn && !leadMode($mode)) { try { $rid = roleIn($item['role_id'], incSec($inc)); } catch (Throwable $e) { $rid = null; }
      if ($rid) $db->prepare("UPDATE participants SET role_id=? WHERE incident_id=? AND user_id=?")->execute([$rid, $inc['id'], $u]); } }
  $db->prepare("UPDATE pois SET cc_sent=? WHERE id=?")->execute([json_encode(array_values(array_unique(array_merge($sent, array_keys($go))))), $poi['id']]);
  logev($inc['id'], $from, 'cc', 'teilt ' . implode(', ', $nm) . ' den Posten «' . $poi['name'] . '» zu' . (!empty($refs['sr']) && $rn ? " (Spezialrolle $rn)" : ''));
  return count($go); }
// eine Person nimmt den Einsatz an: offene Zuteilungen geladener Posten (nur ausgelöste, nicht offene)
function ccJoin($inc, $u) { global $db; if (!feature($inc, 'cc')) return;
  foreach (ccIncRows($inc['id']) as $r) { if ($r['state'] !== 'loaded') continue; $pend = array_map('intval', ccJ($r['pend'], []) ?: []);
    $s = $db->prepare("SELECT * FROM pois WHERE cc_id=? AND removed IS NULL AND cc_users IS NOT NULL"); $s->execute([$r['id']]);
    foreach ($s->fetchAll() as $p) { if (in_array((int)$p['cc_item'], $pend, true)) continue; $it = $db->prepare("SELECT * FROM concept_items WHERE id=?"); $it->execute([(int)$p['cc_item']]);
      if ($item = $it->fetch()) ccAssign($inc, $p, $item, (int)($r['run_by'] ?: $r['by_user'] ?: $u), $u); } } }
// Element ausführen bzw. anlegen; liefert [Art, ID] für done oder null (übersprungen); $act = Aktionen mit Meldungen jetzt ausführen
function ccDo($inc, $ccId, $item, $by, $act) { global $db, $now; $k = $item['kind']; $refs = ccJ($item['refs'], []) ?: []; $cn = CC_KINDS[$k] ?? null; if (!$cn) return null;
  if ($cn[1] && !feature($inc, $cn[1])) return null;
  $pts = ccJ($item['pts'], []) ?: ($item['lat'] !== null ? [[(float)$item['lat'], (float)$item['lon']]] : []);
  if (in_array($k, ['post', 'point', 'area', 'line'], true)) { $pk = $k === 'post' ? 'point' : $k; if (count($pts) < ['point' => 1, 'line' => 2, 'area' => 3][$pk]) return null;
    $sym = $pk === 'point' && $item['sym'] !== null && isset(SYMBOLS[$item['sym']]) ? $item['sym'] : null;
    $users = $k === 'post' && ccPostUsers($refs) ? json_encode(['users' => idsIn($refs['users'] ?? []), 'groups' => idsIn($refs['groups'] ?? [])]) : null;
    $db->prepare("INSERT INTO pois(incident_id,kind,name,color,pts,sym,created_by,created,cc_id,cc_item,cc_role,cc_users) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)")
       ->execute([$inc['id'], $pk, $item['name'], $item['color'] ?: '#ff9f1c', json_encode($pk === 'point' ? [$pts[0]] : $pts), $sym, $by, $now, $ccId, $item['id'], $k === 'post' ? $item['role_id'] : null, $users]);
    $pid = (int)$db->lastInsertId();
    if ($act && $users) { $s = $db->prepare("SELECT * FROM pois WHERE id=?"); $s->execute([$pid]); ccAssign($inc, $s->fetch(), $item, $by); }
    return ['poi', $pid]; }
  if ($k === 'ext') { if ($item['lat'] === null) return null; try { $rid = roleIn($item['role_id'], incSec($inc), true); } catch (Throwable $e) { $rid = null; }
    $db->prepare("INSERT INTO externals(incident_id,name,descr,role_id,abbr,color,lat,lon,created_by,created,manual,cc_id,cc_item) VALUES(?,?,?,?,?,?,?,?,?,?,0,?,?)")
       ->execute([$inc['id'], $item['name'], (string)$item['descr'], $rid, $item['abbr'], $item['color'], (float)$item['lat'], (float)$item['lon'], $by, $now, $ccId, $item['id']]);
    return ['ext', (int)$db->lastInsertId()]; }
  if ($k === 'doc') return ['doc', idsIn($refs['docs'] ?? [])];
  if ($k === 'stop') { $pl = array_column(incPlaces($inc), null, 'id'); $f = $pl[(int)($refs['place'] ?? 0)] ?? null; if (!$f) return null;
    $off = stopOff($inc); $x = !empty($refs['off']); $new = $x ? array_values(array_unique(array_merge($off, [$f['id']]))) : array_values(array_diff($off, [$f['id']]));
    if ($new !== $off) { $db->prepare("UPDATE incidents SET stop_off=? WHERE id=?")->execute([$new ? json_encode($new) : null, $inc['id']]); $inc['stop_off'] = $new ? json_encode($new) : null;
      logev($inc['id'], $by, 'stop', 'berücksichtigt die Flusssperre «' . $f['name'] . ($x ? '» im Suchgebiet nicht mehr' : '» im Suchgebiet') . ' (Einsatzkonzept)'); }
    return ['stop', $f['id']]; }
  if ($k === 'voice') { $all = array_column(voiceChannels(), 'name', 'id'); $ids = array_values(array_intersect(array_keys($all), idsIn($refs['ids'] ?? []))); if (!$ids) return null;
    $db->prepare("UPDATE incidents SET voice_ch=? WHERE id=?")->execute([json_encode($ids), $inc['id']]);
    logev($inc['id'], $by, 'voicech', 'setzt die Funkkanäle: ' . implode(', ', array_map(fn($i) => $all[$i], $ids)) . ' (Einsatzkonzept)'); return ['voice', $ids]; }
  if ($k === 'opt') { $o = (string)($refs['k'] ?? '');
    if ($o === 'air') { if (!airCfg()['on']) return null; if (!airInc($inc)) { $db->prepare("UPDATE incidents SET air=1 WHERE id=?")->execute([$inc['id']]); logev($inc['id'], $by, 'air', 'schaltet die Helikopter ein (Einsatzkonzept)'); } return ['opt', 'air']; }
    if ($o === 'rec') { if (!feature($inc, 'rec')) return null; $s = $db->prepare("SELECT record FROM incidents WHERE id=?"); $s->execute([$inc['id']]);
      if ((int)$s->fetchColumn() === 0) { setRec((int)$inc['id'], 1); logev($inc['id'], $by, 'rec', 'startet die Aufzeichnung (Einsatzkonzept)'); } return ['opt', 'rec']; }
    return null; }
  if (!$act) return ['pend', null]; // Nachricht, Nachbieten: erst, wenn eine Person sie auslöst
  if ($k === 'msg') { ccMessage($inc, $by, trim((string)$item['text']) ?: $item['name']); return ['msg', null]; }
  if ($k === 'invite') return ['invite', ccInvite($inc, idsIn($refs['groups'] ?? []), $by)];
  return null; }
// Nachbieten wie incident/invite/add, nur Gruppen, die die auslösende Person aufbieten darf
function ccInvite($inc, $groups, $by) { global $db; $kind = kindOf($inc); $old = incInvite($inc); if ($old === null || !$groups) return 0; $allowed = addGroups($kind);
  if ($allowed !== null) $groups = array_values(array_intersect($groups, $allowed)); if (!$groups) return 0;
  [$new, $g, $pp] = resolveInvite($kind, $groups, [], $allowed); if (!$g) return 0;
  $o = json_decode($inc['inv_groups'] ?? 'null', true) ?: ['g' => [], 'p' => $old];
  $inv = json_encode(array_values(array_unique(array_merge($old, $new)))); $o = ['g' => idsIn(array_merge($o['g'] ?? [], $g)), 'p' => idsIn($o['p'] ?? [])];
  $skip = ($p = exPlan($inc)) ? exLeads($p) : []; $new = array_values(array_diff($new, $old, $skip, [$by]));
  $abs = array_values(array_intersect($new, absentIds())); $new = array_values(array_diff($new, $abs));
  $ia = array_values(array_unique(array_merge(array_map('intval', json_decode($inc['inv_absent'] ?? '[]', true) ?: []), $abs)));
  $db->prepare("UPDATE incidents SET invite=?, inv_groups=?, inv_absent=? WHERE id=?")->execute([$inv, json_encode($o), $ia ? json_encode($ia) : null, $inc['id']]);
  logev($inc['id'], $by, 'invite', 'bietet nach: ' . invText($g, []) . ' (' . (count($new) === 1 ? '1 weitere Person' : count($new) . ' weitere Personen') . ($abs ? ', dazu ' . absText(count($abs)) : '') . ', Einsatzkonzept)');
  if ($new) pushAlarm($inc, [], $new); return count($new); }
// Konzept laden: $ids = Element-IDs (null = vorausgewählte); bereits geladene, noch bestehende Elemente kommen nicht doppelt
function ccLoad($inc, $c, $ids, $auto) { global $db, $uid, $now; $by = $auto ? null : $uid;
  $s = $db->prepare("SELECT * FROM inc_concepts WHERE incident_id=? AND concept_id=?"); $s->execute([$inc['id'], $c['id']]); $r = $s->fetch();
  if ($r) { $db->prepare("UPDATE inc_concepts SET state='loaded', name=?, removed_at=NULL, removed_by=NULL" . ($auto ? '' : ', run_by=?') . " WHERE id=?")->execute($auto ? [$c['name'], $r['id']] : [$c['name'], $uid, $r['id']]);
    $ccId = (int)$r['id']; $done = $r['state'] === 'removed' ? [] : (ccJ($r['done'], []) ?: []); $pend = $r['state'] === 'removed' ? [] : array_map('intval', ccJ($r['pend'], []) ?: []); }
  else { $db->prepare("INSERT INTO inc_concepts(incident_id,concept_id,name,state,auto,at,by_user,run_by) VALUES(?,?,?,?,?,?,?,?)")->execute([$inc['id'], $c['id'], $c['name'], 'loaded', $auto ? 1 : 0, $now, $by, $by]);
    $ccId = (int)$db->lastInsertId(); $done = []; $pend = []; }
  if (!$r || $r['state'] !== 'loaded') $db->prepare("UPDATE inc_concepts SET auto=?, at=?, by_user=? WHERE id=?")->execute([$auto ? 1 : 0, $now, $by, $ccId]);
  $items = ccItems($c['id']); $sel = $ids === null ? array_filter($items, fn($x) => $x['def_on']) : array_filter($items, fn($x) => in_array($x['id'], $ids, true));
  $n = 0; $names = [];
  foreach ($sel as $it) { $raw = $it; $raw['pts'] = $it['pts'] === null ? null : json_encode($it['pts']); $raw['refs'] = json_encode($it['refs']);
    $d = $done[(string)$it['id']] ?? null;
    if ($d) { if (!in_array($d[0], ['poi', 'ext'], true)) continue; // Aktionen nur einmal
      $q = $db->prepare("SELECT removed FROM " . ($d[0] === 'poi' ? 'pois' : 'externals') . " WHERE id=?"); $q->execute([(int)$d[1]]); $rm = $q->fetchColumn();
      if ($rm === null) continue; } // besteht noch; entfernt bzw. fehlt = neu anlegen
    if (in_array($it['id'], $pend, true)) continue;
    $x = ccDo($inc, $ccId, $raw, $by, !$auto);
    if ($x === null) continue;
    if ($x[0] === 'pend') { $pend[] = $it['id']; $names[] = $it['name'] . ' (offen)'; continue; }
    $done[(string)$it['id']] = $x; $n++; $names[] = $it['name'];
    if ($auto && $it['kind'] === 'post' && ccPostUsers((array)$it['refs'])) $pend[] = $it['id']; } // Zuteilung erst auslösen
  $db->prepare("UPDATE inc_concepts SET done=?, pend=? WHERE id=?")->execute([json_encode((object)$done), json_encode(array_values(array_unique($pend))), $ccId]);
  logev($inc['id'], $by, 'cc', ($auto ? 'lädt automatisch' : 'lädt') . ' das Einsatzkonzept «' . $c['name'] . '»' . ($names ? ': ' . mb_substr(implode(', ', $names), 0, 400) : ' (keine neuen Elemente)'));
  if ($auto) alog('info', 'inc', 'Einsatzkonzept automatisch geladen', ['incident' => (int)$inc['id'], 'concept' => (int)$c['id'], 'n' => $n]);
  return $ccId; }
// offene Aktionen (Nachricht, Zuteilung, Nachbieten) auslösen
function ccRun($inc, $r, $only = null) { global $db, $uid; $pend = array_map('intval', ccJ($r['pend'], []) ?: []); $done = ccJ($r['done'], []) ?: []; $left = []; $nm = [];
  foreach ($pend as $iid) { if ($only !== null && !in_array($iid, $only, true)) { $left[] = $iid; continue; }
    $s = $db->prepare("SELECT * FROM concept_items WHERE id=?"); $s->execute([$iid]); $it = $s->fetch(); if (!$it) continue; $nm[] = $it['name'];
    if ($it['kind'] === 'post') { $d = $done[(string)$iid] ?? null; if ($d && $d[0] === 'poi') { $q = $db->prepare("SELECT * FROM pois WHERE id=? AND removed IS NULL"); $q->execute([(int)$d[1]]); if ($p = $q->fetch()) ccAssign($inc, $p, $it, $uid); } continue; }
    $x = ccDo($inc, (int)$r['id'], $it, $uid, true); if ($x && $x[0] !== 'pend') $done[(string)$iid] = $x; }
  $db->prepare("UPDATE inc_concepts SET done=?, pend=?, run_by=? WHERE id=?")->execute([json_encode((object)$done), json_encode($left), $uid, $r['id']]);
  return $nm; }
// Konzept entfernen: Markierungen und Externe des Konzepts, die noch bestehen ($keep = nur unveränderte, d. h. nicht verschobene Externe)
function ccRemove($inc, $r, $keep) { global $db, $uid, $now; $done = ccJ($r['done'], []) ?: []; $n = 0;
  foreach ($done as $d) { if (!in_array($d[0] ?? '', ['poi', 'ext'], true)) continue;
    if ($d[0] === 'poi') { $u = $db->prepare("UPDATE pois SET removed=? WHERE id=? AND removed IS NULL"); $u->execute([$now, (int)$d[1]]); $n += $u->rowCount(); }
    else { $u = $db->prepare("UPDATE externals SET removed=? WHERE id=? AND removed IS NULL" . ($keep ? ' AND moved IS NULL' : '')); $u->execute([$now, (int)$d[1]]); $n += $u->rowCount(); } }
  $db->prepare("UPDATE inc_concepts SET state='removed', removed_at=?, removed_by=?, pend=NULL WHERE id=?")->execute([$now, $uid, $r['id']]);
  logev($inc['id'], $uid, 'cc', 'entfernt das Einsatzkonzept «' . $r['name'] . '» (' . ($n === 1 ? '1 Element' : "$n Elemente") . ')'); return $n; }

/* --- Eingaben der Verwaltung --- */
function ccPtsIn($v, $min, $max = 200) { $r = []; foreach ((array)$v as $q) { if (!is_array($q) || count($q) < 2 || !is_numeric($q[0]) || !is_numeric($q[1]) || abs($q[0]) > 90 || abs($q[1]) > 180) fail('Ungültige Koordinaten'); $r[] = [round((float)$q[0], 7), round((float)$q[1], 7)]; }
  if (count($r) < $min || count($r) > $max) fail("Es braucht $min bis $max Punkte"); return $r; }
function ccItemIn($x, $i) { global $db; if (!is_array($x)) fail('Ungültiges Element'); $k = (string)($x['kind'] ?? ''); if (!isset(CC_KINDS[$k])) fail('Unbekannte Art eines Elements');
  $name = mb_substr(trim((string)($x['name'] ?? '')), 0, 80); $refs = is_array($x['refs'] ?? null) ? $x['refs'] : [];
  $o = ['id' => (int)($x['id'] ?? 0), 'kind' => $k, 'name' => $name, 'descr' => mb_substr(trim((string)($x['descr'] ?? '')), 0, 500) ?: null, 'color' => preg_match('/^#[0-9a-fA-F]{6}$/', (string)($x['color'] ?? '')) ? strtolower($x['color']) : null,
    'sym' => null, 'abbr' => null, 'role_id' => null, 'lat' => null, 'lon' => null, 'pts' => null, 'text' => null, 'refs' => [], 'def_on' => array_key_exists('def_on', $x) ? ((int)$x['def_on'] ? 1 : 0) : 1, 'sort' => $i + 1];
  $role = function ($v) use ($db) { if (empty($v)) return null; $s = $db->prepare("SELECT id FROM roles WHERE id=?"); $s->execute([(int)$v]); return $s->fetchColumn() ? (int)$v : fail('Unbekannte Spezialrolle'); };
  if (in_array($k, ['post', 'point', 'ext'], true)) { [$o['lat'], $o['lon']] = llIn($x['lat'] ?? null, $x['lon'] ?? null); }
  if (in_array($k, ['post', 'point'], true)) { $sy = trim((string)($x['sym'] ?? '')); if ($sy !== '' && !isset(SYMBOLS[$sy])) fail('Unbekanntes Symbol'); $o['sym'] = $sy ?: null; if ($o['name'] === '' && $o['sym']) $o['name'] = SYMBOLS[$o['sym']][1]; }
  if ($k === 'area' || $k === 'line') $o['pts'] = ccPtsIn($x['pts'] ?? [], $k === 'area' ? 3 : 2);
  if ($k === 'post') { $o['role_id'] = $role($x['role_id'] ?? null); $o['refs'] = ['users' => idsIn($refs['users'] ?? []), 'groups' => idsIn($refs['groups'] ?? []), 'sr' => !empty($refs['sr']) ? 1 : 0, 'veh' => !empty($refs['veh']) ? (int)$refs['veh'] : null]; }
  if ($k === 'ext') { $o['role_id'] = $role($x['role_id'] ?? null); [$o['abbr'], $o['color']] = extStyle($x); $o['refs'] = ['tpl' => !empty($refs['tpl']) ? (int)$refs['tpl'] : null]; }
  if ($k === 'msg') { $o['text'] = mb_substr(trim((string)($x['text'] ?? '')), 0, 2000); if ($o['text'] === '') fail('Text der Nachricht fehlt'); if ($o['name'] === '') $o['name'] = 'Nachricht an alle'; }
  if ($k === 'doc') { $o['refs'] = ['docs' => idsIn($refs['docs'] ?? [])]; if (!$o['refs']['docs']) fail('Bitte mindestens ein Dokument wählen'); if ($o['name'] === '') $o['name'] = 'Dokumente'; }
  if ($k === 'stop') { $p = (int)($refs['place'] ?? 0); $s = $db->prepare("SELECT name FROM places WHERE id=? AND plan_id IS NULL"); $s->execute([$p]); $pn = $s->fetchColumn() ?: fail('Fester Standort für die Flusssperre fehlt');
    $o['refs'] = ['place' => $p, 'off' => !empty($refs['off']) ? 1 : 0]; if ($o['name'] === '') $o['name'] = ($o['refs']['off'] ? 'Flusssperre ausnehmen: ' : 'Flusssperre berücksichtigen: ') . $pn; }
  if ($k === 'voice') { $o['refs'] = ['ids' => idsIn($refs['ids'] ?? [])]; if (!$o['refs']['ids']) fail('Bitte mindestens einen Funkkanal wählen'); if ($o['name'] === '') $o['name'] = 'Funkkanäle'; }
  if ($k === 'invite') { $o['refs'] = ['groups' => idsIn($refs['groups'] ?? [])]; if (!$o['refs']['groups']) fail('Bitte mindestens eine Einsatzgruppe zum Nachbieten wählen'); if ($o['name'] === '') $o['name'] = 'Nachbieten'; }
  if ($k === 'opt') { $ok = (string)($refs['k'] ?? ''); if (!isset(CC_OPTS[$ok])) fail('Unbekannte Einstellung'); $o['refs'] = ['k' => $ok]; if ($o['name'] === '') $o['name'] = CC_OPTS[$ok]; }
  if ($o['name'] === '') fail('Bezeichnung eines Elements fehlt (' . CC_KINDS[$k][0] . ')');
  return $o; }
function ccSave($in) { global $db, $uid, $now; $id = (int)($in['id'] ?? 0); $name = mb_substr(trim((string)($in['name'] ?? '')), 0, 120); if ($name === '') fail('Bezeichnung fehlt');
  $scope = ($in['scope'] ?? '') === 'river' ? 'river' : 'area'; $area = null; $river = null;
  if ($scope === 'area') $area = json_encode(ccPtsIn($in['area'] ?? [], 3, 300));
  else { $r = is_array($in['river'] ?? null) ? $in['river'] : []; $a = ccPtsIn([$r['a'] ?? null], 1, 1)[0]; $b = ccPtsIn([$r['b'] ?? null], 1, 1)[0]; $buf = max(20, min(2000, (int)($r['buf'] ?? CC_BUF)));
    $ls = []; $np = 0; foreach ((array)($r['lines'] ?? []) as $l) { $l = ccPtsIn($l, 2, CC_PTS_MAX); $np += count($l); $ls[] = $l; }
    if (!$ls) fail('Der Abschnitt entlang des Gewässers fehlt (Gewässernetz laden und A und B setzen)'); if ($np > CC_PTS_MAX) fail('Der Abschnitt ist zu lang');
    $river = json_encode(['a' => $a, 'b' => $b, 'buf' => $buf, 'lines' => $ls, 'km' => round((float)($r['km'] ?? 0), 2)]); }
  $mode = isset(CC_MODES[$in['mode'] ?? '']) ? $in['mode'] : null; $kinds = isset($in['kinds']) && is_array($in['kinds']) ? json_encode(idsIn($in['kinds'])) : null;
  $items = []; foreach (array_values((array)($in['items'] ?? [])) as $i => $x) $items[] = ccItemIn($x, $i); if (count($items) > CC_ITEMS_MAX) fail('Höchstens ' . CC_ITEMS_MAX . ' Elemente');
  $descr = mb_substr(trim((string)($in['descr'] ?? '')), 0, 1000) ?: null;
  $db->beginTransaction();
  if ($id) { ccRow($id); $db->prepare("UPDATE concepts SET name=?, descr=?, scope=?, area=?, river=?, mode=?, kinds=?, updated=?, updated_by=? WHERE id=?")->execute([$name, $descr, $scope, $area, $river, $mode, $kinds, $now, $uid, $id]); }
  else { $db->prepare("INSERT INTO concepts(name,descr,scope,area,river,mode,kinds,active,created,created_by,updated,updated_by) VALUES(?,?,?,?,?,?,?,1,?,?,?,?)")->execute([$name, $descr, $scope, $area, $river, $mode, $kinds, $now, $uid, $now, $uid]); $id = (int)$db->lastInsertId(); }
  $old = array_map('intval', $db->query("SELECT id FROM concept_items WHERE concept_id=" . $id)->fetchAll(PDO::FETCH_COLUMN)); $keep = [];
  $cols = ['kind', 'name', 'descr', 'color', 'sym', 'abbr', 'role_id', 'lat', 'lon', 'pts', 'text', 'refs', 'def_on', 'sort'];
  foreach ($items as $o) { $v = array_map(fn($c) => in_array($c, ['pts', 'refs'], true) ? ($o[$c] === null ? null : json_encode($o[$c])) : $o[$c], $cols);
    if ($o['id'] && in_array($o['id'], $old, true)) { $db->prepare("UPDATE concept_items SET " . implode(',', array_map(fn($c) => "$c=?", $cols)) . " WHERE id=?")->execute(array_merge($v, [$o['id']])); $keep[] = $o['id']; }
    else $db->prepare("INSERT INTO concept_items(concept_id," . implode(',', $cols) . ") VALUES(?," . qm($cols) . ")")->execute(array_merge([$id], $v)); }
  $gone = array_values(array_diff($old, $keep)); if ($gone) $db->prepare("DELETE FROM concept_items WHERE id IN (" . qm($gone) . ")")->execute($gone);
  $db->commit(); $db->exec("UPDATE incidents SET cc_key=NULL WHERE ended_at IS NULL"); // laufende Einsätze prüfen die PLS beim nächsten Polling neu
  return $id; }
// Lage eines Einsatzes als Entwurf: Markierungen, Bereiche, Linien, Externe, Flusssperren und Funkkanäle; Geltungsbereich = Rechteck um alle Punkte plus 300 m
function ccFromIncident($iid) { global $db; $s = $db->prepare("SELECT * FROM incidents WHERE id=?"); $s->execute([(int)$iid]); $inc = $s->fetch() ?: fail('Einsatz nicht gefunden', 404);
  $its = []; $all = [];
  $s = $db->prepare("SELECT * FROM pois WHERE incident_id=? AND removed IS NULL ORDER BY id"); $s->execute([$inc['id']]);
  foreach ($s->fetchAll() as $p) { $pts = ccJ($p['pts'], []) ?: []; if (!$pts) continue; $all = array_merge($all, $pts);
    $its[] = $p['kind'] === 'point' ? ['kind' => $p['cc_role'] ? 'post' : 'point', 'name' => $p['name'], 'color' => $p['color'], 'sym' => $p['sym'], 'role_id' => $p['cc_role'] === null ? null : (int)$p['cc_role'], 'lat' => (float)$pts[0][0], 'lon' => (float)$pts[0][1], 'def_on' => 1, 'refs' => (object)[]]
      : ['kind' => $p['kind'], 'name' => $p['name'], 'color' => $p['color'], 'pts' => $pts, 'def_on' => 1, 'refs' => (object)[]]; }
  foreach (incExternals((int)$inc['id']) as $e) { $all[] = [$e['lat'], $e['lon']]; $its[] = ['kind' => 'ext', 'name' => $e['name'], 'descr' => $e['descr'], 'role_id' => $e['role_id'], 'abbr' => $e['abbr'], 'color' => $e['color'], 'lat' => $e['lat'], 'lon' => $e['lon'], 'def_on' => 1, 'refs' => (object)[]]; }
  foreach (stopOff($inc) as $p) $its[] = ['kind' => 'stop', 'name' => '', 'refs' => ['place' => $p, 'off' => 1], 'def_on' => 1];
  if ($inc['voice_ch'] !== null) $its[] = ['kind' => 'voice', 'name' => 'Funkkanäle', 'refs' => ['ids' => idsIn(json_decode($inc['voice_ch'], true))], 'def_on' => 1];
  if ($inc['lat'] !== null) $all[] = [(float)$inc['lat'], (float)$inc['lon']];
  $area = null; if ($all) { $la = array_column($all, 0); $lo = array_column($all, 1); $d = 0.003; $area = [[max($la) + $d, min($lo) - $d * 1.5], [max($la) + $d, max($lo) + $d * 1.5], [min($la) - $d, max($lo) + $d * 1.5], [min($la) - $d, min($lo) - $d * 1.5]]; }
  return ['name' => mb_substr((string)$inc['message'], 0, 80), 'scope' => 'area', 'area' => $area, 'items' => $its]; }

/* --- Routen --- */
function ccRoute($route) { global $db, $in, $uid, $now;
  if (!(str_starts_with($route, 'concept') || str_starts_with($route, 'incident/concept'))) return;
  $id = (int)($in['id'] ?? $_GET['id'] ?? 0);
  switch ($route) {
  case 'concepts': // Verwaltung: alle Konzepte mit Elementen, Verhalten je Typ und die Auswahllisten
    need('concepts');
    $used = []; foreach ($db->query("SELECT concept_id,COUNT(*) n,MAX(at) last FROM inc_concepts WHERE state IN ('loaded','removed') GROUP BY concept_id")->fetchAll() as $x) $used[(int)$x['concept_id']] = ['n' => (int)$x['n'], 'last' => (int)$x['last']];
    $pl = array_values(array_map(fn($p) => ['id' => $p['id'], 'name' => $p['name'], 'lat' => $p['lat'], 'lon' => $p['lon'], 'pts' => $p['pts'], 'line' => $p['line'], 'props' => $p['props'], 'color' => $p['color'], 'abbr' => $p['abbr']], array_filter(placesOf(null), fn($p) => $p['active'])));
    $stopP = array_column(array_filter(placeProps(), fn($p) => $p['effect'] === 'stop'), 'id');
    $docs = []; try { $docs = array_values(array_map(fn($d) => ['id' => (int)$d['id'], 'title' => $d['title']], $db->query("SELECT id,title FROM docs WHERE active=1 ORDER BY title")->fetchAll())); } catch (Throwable $e) {}
    $veh = []; try { $veh = array_map(fn($v) => ints($v, ['id']), $db->query("SELECT id,name FROM vehicles WHERE active=1 AND COALESCE(deleted,0)=0 ORDER BY name")->fetchAll()); } catch (Throwable $e) {}
    out(['concepts' => array_map(fn($c) => ccPub($c) + ['used' => $used[(int)$c['id']] ?? ['n' => 0, 'last' => null]], ccRows(false)), 'cfg' => ccKindCfg(), 'modes' => CC_MODES, 'kinds_cc' => array_map(fn($k) => $k[0], CC_KINDS), 'opts' => CC_OPTS,
      'kinds' => array_values(array_map(fn($k) => ['id' => $k['id'], 'name' => $k['name'], 'color' => $k['color'], 'plans' => $k['plans'], 'sec' => $k['sec']], kindsActive())),
      'roles' => array_map(fn($r) => ints($r, ['id', 'is_post', 'sec']), $db->query("SELECT id,name,color,is_post,sec FROM roles WHERE active=1 ORDER BY id")->fetchAll()),
      'groups' => array_map(fn($g) => ['id' => $g['id'], 'name' => $g['name'], 'color' => $g['color']], grpRows(true)),
      'users' => array_map(fn($u) => ints($u, ['id', 'active']), $db->query("SELECT id,name,username,active FROM users WHERE active=1 AND COALESCE(purged,0)=0 ORDER BY name")->fetchAll()),
      'places' => $pl, 'stop_props' => $stopP, 'docs' => $docs, 'channels' => array_map(fn($c) => ['id' => $c['id'], 'name' => $c['name']], voiceChannels()), 'vehicles' => $veh, 'symbols' => symActive(),
      'ext_templates' => array_map(fn($r) => floats(ints($r, ['id', 'role_id']), ['lat', 'lon']), $db->query("SELECT id,name,descr,role_id,abbr,color,lat,lon FROM ext_templates WHERE active=1 ORDER BY name")->fetchAll()),
      'buf' => CC_BUF, 'post_m' => CC_POST_M]);
  case 'concept/save': need('concepts'); out(['ok' => true, 'id' => ccSave($in)]);
  case 'concept/move': need('concepts'); sortMove('concepts', "SELECT id FROM concepts WHERE active=1 ORDER BY " . SORT_ORDER, [], $id, (int)($in['dir'] ?? 1)); out(['ok' => true]);
  case 'concept/active': need('concepts'); ccRow($id); $db->prepare("UPDATE concepts SET active=?, updated=?, updated_by=? WHERE id=?")->execute([empty($in['on']) ? 0 : 1, $now, $uid, $id]); $db->exec("UPDATE incidents SET cc_key=NULL WHERE ended_at IS NULL"); out(['ok' => true]);
  case 'concept/remove': need('concepts'); ccRow($id); $s = $db->prepare("SELECT COUNT(*) FROM inc_concepts WHERE concept_id=?"); $s->execute([$id]);
    if ((int)$s->fetchColumn()) fail('Das Konzept wurde schon in Einsätzen geladen oder vorgeschlagen; bitte deaktivieren');
    $db->prepare("DELETE FROM concept_items WHERE concept_id=?")->execute([$id]); $db->prepare("DELETE FROM concepts WHERE id=?")->execute([$id]); out(['ok' => true]);
  case 'concept/copy': need('concepts'); $c = ccPub(ccRow($id)); unset($c['id']); $c['name'] = mb_substr('Kopie von ' . $c['name'], 0, 120); foreach ($c['items'] as &$x) unset($x['id']); unset($x); out(['ok' => true, 'id' => ccSave($c)]);
  case 'concepts/cfg/save': need('concepts'); $v = is_array($in['kinds'] ?? null) ? $in['kinds'] : []; $r = [];
    foreach (kinds() as $kid => $_) { $x = is_array($v[(string)$kid] ?? null) ? $v[(string)$kid] : null; if (!$x) continue; $r[(string)$kid] = ['mode' => isset(CC_MODES[$x['mode'] ?? '']) ? $x['mode'] : 'hand', 'next' => empty($x['next']) ? 0 : 1]; }
    setSetting('cc_kinds', json_encode($r)); $db->exec("UPDATE incidents SET cc_key=NULL WHERE ended_at IS NULL"); out(['ok' => true]);
  case 'concept/sources': need('concepts'); // Einsätze für «Aus Einsatz übernehmen»: die letzten 60 mit Markierungen oder Externen
    $r = $db->query("SELECT i.id,i.message,i.alarm_at,i.ended_at,i.kind_id,(SELECT COUNT(*) FROM pois p WHERE p.incident_id=i.id AND p.removed IS NULL) np,(SELECT COUNT(*) FROM externals e WHERE e.incident_id=i.id AND e.removed IS NULL) ne FROM incidents i WHERE COALESCE(i.txt_anon,0)=0 ORDER BY i.id DESC LIMIT 200")->fetchAll();
    out(['list' => array_slice(array_values(array_map(fn($x) => ints($x, ['id', 'alarm_at', 'ended_at', 'np', 'ne']) + ['kind_name' => kindOf($x)['name'], 'kind_color' => kindOf($x)['color']], array_filter($r, fn($x) => (int)$x['np'] + (int)$x['ne'] > 0))), 0, 60)]);
  case 'concept/from': need('concepts'); out(['draft' => ccFromIncident((int)($in['incident_id'] ?? $_GET['incident_id'] ?? 0))]);

  case 'incident/concepts': // Einsatzleitung: Konzepte des Einsatztyps mit Elementen und Geltungsbereich (Vorschau, Anschlusskonzepte, «Anderes Konzept laden»)
    $inc = activeIncident(); if (!$inc) fail('Kein aktiver Einsatz'); if (!canManage($inc)) fail('Nur die Einsatzleitung kann Einsatzkonzepte laden', 403);
    $fit = ccFit($inc); $rows = array_column(ccIncRows($inc['id'], true), null, 'concept_id');
    out(['list' => array_values(array_map(function ($c) use ($fit, $rows, $inc) { $o = ccPub($c); $r = $rows[$c['id']] ?? null; $o['fit'] = in_array((int)$c['id'], $fit, true) ? 1 : 0;
      $o['state'] = $r['state'] ?? null; $o['cc'] = $r ? (int)$r['id'] : null; $o['done'] = $r && $r['state'] === 'loaded' ? array_map('intval', array_keys(ccJ($r['done'], []) ?: [])) : []; $o['mode'] = ccMode($c, $inc['kind_id']); return $o; },
      array_filter(ccRows(), fn($c) => ccForKind($c, $inc['kind_id'])))), 'post_m' => CC_POST_M]);
  case 'incident/concept/load': // id = Konzept, items = Element-IDs (fehlt = vorausgewählte)
    $inc = activeIncident(); if (!$inc) fail('Kein aktiver Einsatz'); if (!canManage($inc)) fail('Nur die Einsatzleitung kann Einsatzkonzepte laden', 403);
    $c = ccRow($id); if (!(int)$c['active'] || !ccForKind($c, $inc['kind_id'])) fail('Dieses Einsatzkonzept gilt nicht für diesen Einsatztyp');
    $db->beginTransaction(); $cc = ccLoad($inc, $c, isset($in['items']) ? idsIn($in['items']) : null, false); $db->commit(); out(['ok' => true, 'cc' => $cc]);
  case 'incident/concept/run': // offene Aktionen auslösen: id = inc_concepts.id, items = nur diese (fehlt = alle)
    $inc = activeIncident(); if (!$inc) fail('Kein aktiver Einsatz'); if (!canManage($inc)) fail('Nur die Einsatzleitung kann das auslösen', 403);
    $r = ccIncRow($inc, $id); $db->beginTransaction(); $nm = ccRun($inc, $r, isset($in['items']) ? idsIn($in['items']) : null); $db->commit(); out(['ok' => true, 'done' => $nm]);
  case 'incident/concept/drop': // offene Aktionen verwerfen
    $inc = activeIncident(); if (!$inc) fail('Kein aktiver Einsatz'); if (!canManage($inc)) fail('Nur die Einsatzleitung kann das ändern', 403);
    $r = ccIncRow($inc, $id); $p = array_values(array_diff(array_map('intval', ccJ($r['pend'], []) ?: []), idsIn($in['items'] ?? ccJ($r['pend'], []) ?: [])));
    $db->prepare("UPDATE inc_concepts SET pend=? WHERE id=?")->execute([json_encode($p), $r['id']]); logev($inc['id'], $uid, 'cc', 'verwirft offene Aktionen des Einsatzkonzepts «' . $r['name'] . '»'); out(['ok' => true]);
  case 'incident/concept/remove': // id = inc_concepts.id; keep = verschobene Externe behalten
    $inc = activeIncident(); if (!$inc) fail('Kein aktiver Einsatz'); if (!canManage($inc)) fail('Nur die Einsatzleitung kann Einsatzkonzepte entfernen', 403);
    $r = ccIncRow($inc, $id); if ($r['state'] !== 'loaded') fail('Das Einsatzkonzept ist nicht geladen'); $db->beginTransaction(); $n = ccRemove($inc, $r, !empty($in['keep'])); $db->commit(); out(['ok' => true, 'n' => $n]);
  case 'incident/concept/dismiss': // Vorschlag nicht laden (für alle der Einsatzleitung)
    $inc = activeIncident(); if (!$inc) fail('Kein aktiver Einsatz'); if (!canManage($inc)) fail('Nur die Einsatzleitung kann das ändern', 403);
    $r = ccIncRow($inc, $id); if ($r['state'] === 'suggest') { $db->prepare("UPDATE inc_concepts SET state='dismissed' WHERE id=?")->execute([$r['id']]); logev($inc['id'], $uid, 'cc', 'lädt das vorgeschlagene Einsatzkonzept «' . $r['name'] . '» nicht'); }
    out(['ok' => true]);
  }
  fail('Unbekannte Route', 404); }
