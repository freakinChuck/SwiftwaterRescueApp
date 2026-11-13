// Demo-Modus (Issue #74): simuliertes Backend über Api.call wie in der Einsatz-App, ohne Netz.
import 'package:flutter_test/flutter_test.dart';
import 'package:swiftwater_rescue/api.dart';
import 'package:swiftwater_rescue/demo.dart';

void main() {
  final api = demoAccount().api;
  Future<Map<String, dynamic>> c(String r, [Map<String, dynamic>? b, Map<String, String>? q]) => api.call(r, body: b, query: q);
  Future<String> err(String r, [Map<String, dynamic>? b]) async {
    try {
      await c(r, b ?? {});
      return '';
    } on ApiError catch (e) {
      return e.message;
    }
  }

  test('Beispieldaten: laufender Einsatz mit simulierten Einsatzkräften', () async {
    Demo.restart('bsp');
    final st = await c('incident/state', null, {'since': '0'});
    expect(st['incident']['kind_name'], 'Einsatz');
    expect((st['participants'] as List).length, greaterThanOrEqualTo(2));
    expect((st['rescuees'] as List).length, 1);
    expect(st['alarm'], true);
    expect(st['can_manage'], false); // noch nicht angenommen
    expect(((await c('roles'))['data'] as List).length, 4);
    expect((await c('me'))['me']['perms'], contains('inc_lead'));
  });

  test('Annehmen, Leitung, Rettling, Nachricht mit Antwort, Ende', () async {
    Demo.restart('bsp');
    await c('incident/join', {'mode': 'direct'});
    await c('incident/location', {'lat': 47.054, 'lon': 8.302});
    await c('incident/role', {'role_id': 2});
    var st = await c('incident/state');
    expect(st['can_manage'], true);
    expect(st['me']['role_id'], 2);
    expect(
      (await c('incident/poi', {
        'kind': 'point',
        'name': '',
        'sym': 'sw-ew',
        'pts': [
          [47.06, 8.29],
        ],
      }))['ok'],
      true,
    );
    expect(
      await err('incident/poi', {
        'kind': 'area',
        'name': 'x',
        'pts': [
          [47.06, 8.29],
        ],
      }),
      'Ein Bereich braucht 3 bis 200 Punkte',
    );
    final r = await c('incident/rescuee/save', {
      'name': '',
      'descr': 'Kind',
      'pls': {'lat': 47.055, 'lon': 8.30},
    });
    await c('incident/rescuee/locate', {'id': r['id'], 'own': 1});
    await c('incident/rescuee/rescued', {'id': r['id'], 'rescued': 1});
    await c('incident/external/save', {'name': 'Polizei', 'lat': 47.05, 'lon': 8.3, 'role_id': 4, 'manual': 1});
    await c('incident/message', {
      'text': 'Test?',
      'to': [101],
    });
    await c('incident/record', {'action': 'start'});
    st = await c('incident/state', null, {'since': '0'});
    expect((st['rescuees'] as List).any((x) => x['name'] == 'Unbekannter Rettling 2' && x['rescued_at'] != null), true);
    expect(st['incident']['record'], 1);
    expect((st['ext_inc_templates'] as List).length, 1);
    expect((st['log'] as List).any((l) => '${l['text']}'.contains('Nachricht an Anna Muster: Test?')), true);
    expect(await err('incident/public', {'on': 1}), contains('Demo'));
    // Overpass: wie der Server ohne Antwort, das Gerät fragt selbst
    try {
      await c('overpass', {
        'around': [5000, 47.05, 8.3],
      });
      fail('Overpass ohne Fehler');
    } on ApiError catch (e) {
      expect(e.status, 502);
      expect('${e.data?['query']}', contains('around:5000,47.05000,8.30000'));
    }
    await c('overpass/put', {
      'around': [5000, 47.05, 8.3],
      'elements': [],
    });
    expect(
      (await c('overpass', {
        'around': [5000, 47.05, 8.3],
      }))['elements'],
      isEmpty,
    );
    await c('incident/end');
    st = await c('incident/state');
    expect(st['incident'], null);
    expect(st['last_ended'], isNotNull);
  });

  test('Ohne Daten: eigener Einsatz, ohne Leitungsrechte', () async {
    final d = Demo.restart('leer');
    d.lead = false;
    expect((await c('incident/state'))['incident'], null);
    await c('incident/start', {'kind_id': 2, 'message': 'Übung', 'lat': 47.05, 'lon': 8.3, 'record': 1});
    var st = await c('incident/state');
    expect(st['incident']['kind_name'], 'Übung');
    expect(st['alarm'], false);
    expect(st['level'], 'normal');
    // Ersteller: Rechte der Einsatzleitung auch ohne inc_lead
    await c('incident/join', {'mode': 'base'});
    expect((await c('incident/state'))['can_manage'], true);
    // fremder Einsatz ohne Leitung: keine Markierungen
    Demo.restart('bsp').lead = false;
    await c('incident/join', {'mode': 'base'});
    expect(
      await err('incident/poi', {
        'kind': 'point',
        'name': 'x',
        'pts': [
          [47, 8],
        ],
      }),
      'Nur die Einsatzleitung kann Markierungen und Bereiche setzen',
    );
    st = await c('incident/state');
    expect(st['can_manage'], false);
    expect(await err('users'), 'Im Demo-Modus nicht verfügbar');
  });
}
