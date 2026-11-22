// Mehrsprachigkeit (Issue #66): Wörterbücher der App = web/i18n, Übersetzung von Texten und Vorlagen
import 'dart:convert';
import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:swiftwater_rescue/i18n.dart';

void main() {
  for (final l in ['en', 'fr', 'it']) {
    test('assets/i18n/$l.json gleich wie web/i18n/$l.json', () {
      final a = File('assets/i18n/$l.json').readAsStringSync();
      final w = File('../../web/i18n/$l.json').readAsStringSync();
      expect(a, w, reason: 'nach Änderungen scripts/i18n/merge.py ausführen (kopiert die Wörterbücher in die App)');
      expect(jsonDecode(a), isA<Map>());
    });
  }

  test('Texte, Vorlagen und Doppelpunkt', () {
    I18n.setDict(jsonDecode(File('assets/i18n/fr.json').readAsStringSync()) as Map<String, dynamic>, 'fr');
    expect(tr('Abbrechen'), 'Annuler');
    expect(tr('  Speichern '), '  Enregistrer ');
    expect(tr('Einsatz starten'), 'Démarrer Intervention'); // Vorlage «{0} starten», Teil übersetzt
    expect(tr('Mein Standort:'), 'Ma position :');
    expect(tr('Anna Muster'), 'Anna Muster'); // unbekannt bleibt
    expect(trUp('EINSATZ'), 'INTERVENTION');
    I18n.setDict({}, 'de');
    expect(tr('Abbrechen'), 'Abbrechen');
  });
}
