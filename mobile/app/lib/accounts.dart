// Konten: je Backend (Server-Adresse) und Person ein Eintrag. Mehrere Konten können gleichzeitig «aktiv» sein, dann überwacht die App alle (monitor.dart).
// Gespeichert nur auf dem Gerät, im sicheren Speicher (Keychain bzw. verschlüsselt), weil der Anmeldeschlüssel darin steht.
import 'dart:convert';
import 'dart:math';

import 'package:flutter_secure_storage/flutter_secure_storage.dart';

import 'api.dart';

const _key = 'accounts';
// iOS: auch lesbar, solange das Gerät gesperrt ist (Hintergrund, Push); Android: verschlüsselte Ablage
const storage = FlutterSecureStorage(iOptions: IOSOptions(accessibility: KeychainAccessibility.first_unlock));

class Account {
  Account({required this.id, required this.base, required this.username, required this.token, this.name = '', this.org = '', this.alias = '', this.active = true, this.loggedOut = false, this.added = 0, this.uid = 0});
  final String id;
  final String base; // Web-Ordner mit «/» am Ende, z. B. https://example.ch/swiftwater/
  final String username;
  String token;
  String name; // Name der Person (me)
  String org; // Organisation aus dem Impressum des Backends, sonst leer
  String alias; // eigene Bezeichnung auf diesem Gerät (Kontenliste), leer = Organisation bzw. Adresse
  bool active; // Alarme und Nachrichten dieses Backends empfangen
  bool loggedOut; // Server hat den Schlüssel abgelehnt (z. B. Passwort zurückgesetzt): neu anmelden
  int added;
  int uid; // Benutzer-ID auf diesem Backend (Nachrichten an mich, Push-Zuordnung)

  String get label => alias.isNotEmpty ? alias : (org.isNotEmpty ? org : hostPath(base));
  Api get api => Api(base, token);

  Map<String, dynamic> toJson() => {'id': id, 'base': base, 'username': username, 'token': token, 'name': name, 'org': org, 'alias': alias, 'active': active, 'loggedOut': loggedOut, 'added': added, 'uid': uid};
  factory Account.fromJson(Map<String, dynamic> j) => Account(
      id: j['id'] as String,
      base: j['base'] as String,
      username: j['username'] as String? ?? '',
      token: j['token'] as String? ?? '',
      name: j['name'] as String? ?? '',
      org: j['org'] as String? ?? '',
      alias: j['alias'] as String? ?? '',
      active: j['active'] != false,
      loggedOut: j['loggedOut'] == true,
      added: (j['added'] as num?)?.toInt() ?? 0,
      uid: (j['uid'] as num?)?.toInt() ?? 0);
}

String newId() {
  final r = Random.secure();
  return List.generate(12, (_) => r.nextInt(36).toRadixString(36)).join();
}

class Accounts {
  static Future<List<Account>> load() async {
    try {
      final s = await storage.read(key: _key);
      if (s == null || s.isEmpty) return [];
      return (jsonDecode(s) as List).map((e) => Account.fromJson(Map<String, dynamic>.from(e as Map))).toList();
    } catch (_) {
      return [];
    }
  }

  static Future<void> save(List<Account> l) => storage.write(key: _key, value: jsonEncode(l.map((a) => a.toJson()).toList()));

  /// Ein Konto ändern, ohne gleichzeitige Änderungen anderer Konten (z. B. aus dem Hintergrunddienst) zu überschreiben.
  static Future<void> update(String id, void Function(Account a) f) async {
    final l = await load();
    for (final a in l) {
      if (a.id == id) f(a);
    }
    await save(l);
  }

  static Future<void> put(Account a) async {
    final l = await load();
    final i = l.indexWhere((x) => x.id == a.id);
    if (i >= 0) {
      l[i] = a;
    } else {
      l.add(a);
    }
    await save(l);
  }

  static Future<void> remove(String id) async {
    final l = await load();
    l.removeWhere((a) => a.id == id);
    await save(l);
  }
}
