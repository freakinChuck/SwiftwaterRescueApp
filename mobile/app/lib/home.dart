// Oberfläche: Kontenliste (mehrere Backends, je «aktiv» ein-/ausschaltbar), Anmeldung und Einstellungen.
import 'dart:async';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_background_service/flutter_background_service.dart';

import 'accounts.dart';
import 'api.dart';
import 'main.dart';
import 'monitor.dart';
import 'notify.dart';
import 'push.dart';
import 'service.dart';
import 'sys.dart';

void snack(BuildContext c, String t) => ScaffoldMessenger.of(c).showSnackBar(SnackBar(content: Text(t), duration: const Duration(seconds: 3)));

Color? hexColor(String? s) {
  if (s == null) return null;
  final h = s.replaceFirst('#', '');
  final v = int.tryParse(h.length == 3 ? h.split('').map((c) => '$c$c').join() : h, radix: 16);
  return v == null || (h.length != 3 && h.length != 6) ? null : Color(0xFF000000 | v);
}

String _ago(int ms) {
  if (ms == 0) return 'noch nie';
  final d = DateTime.now().difference(DateTime.fromMillisecondsSinceEpoch(ms));
  if (d.inSeconds < 60) return 'vor ${d.inSeconds} s';
  if (d.inMinutes < 60) return 'vor ${d.inMinutes} min';
  final t = DateTime.fromMillisecondsSinceEpoch(ms);
  return 'um ${t.hour.toString().padLeft(2, '0')}:${t.minute.toString().padLeft(2, '0')}';
}

class HomePage extends StatefulWidget {
  const HomePage({super.key, required this.onIntervalChanged});
  final VoidCallback onIntervalChanged;
  @override
  State<HomePage> createState() => _HomeState();
}

class _HomeState extends State<HomePage> {
  List<Account> _acc = [];
  Map<String, AccState> _st = {};
  Map<String, String> _push = {};
  Timer? _t;
  StreamSubscription? _sub;
  bool _loaded = false;

  @override
  void initState() {
    super.initState();
    _load();
    _t = Timer.periodic(const Duration(seconds: 5), (_) => _load());
    if (Platform.isAndroid) _sub = FlutterBackgroundService().on('update').listen((_) => _load());
  }

  @override
  void dispose() {
    _t?.cancel();
    _sub?.cancel();
    super.dispose();
  }

  Future<void> _load() async {
    final l = await Accounts.load();
    final st = <String, AccState>{}, pu = <String, String>{};
    for (final a in l) {
      st[a.id] = await accState(a.id);
      pu[a.id] = await pushStatus(a);
    }
    if (!mounted) return;
    setState(() {
      _acc = l;
      _st = st;
      _push = pu;
      _loaded = true;
    });
  }

  Future<void> _refresh() async {
    await pollAll();
    await _load();
  }

  Future<void> _changed() async {
    await syncService();
    await syncPush();
    await _load();
  }

  Future<void> _add() async {
    await Navigator.push(context, MaterialPageRoute(builder: (_) => const LoginPage()));
    await _changed();
  }

  Future<void> _remove(Account a) async {
    final ok = await showDialog<bool>(
        context: context,
        builder: (c) => AlertDialog(
              title: const Text('Konto entfernen?'),
              content: Text('${a.label} (${a.username}) wird von diesem Gerät entfernt und abgemeldet. Alarme dieses Backends kommen danach nicht mehr an.'),
              actions: [TextButton(onPressed: () => Navigator.pop(c, false), child: const Text('Abbrechen')), FilledButton(onPressed: () => Navigator.pop(c, true), child: const Text('Entfernen'))],
            ));
    if (ok != true) return;
    await pushForget(a);
    if (!a.loggedOut) await a.api.logout();
    await Accounts.remove(a.id);
    await clearState(a.id);
    await _changed();
  }

  Future<void> _setActive(Account a, bool on) async {
    await Accounts.update(a.id, (x) => x.active = on);
    if (on) await pollOne(a..active = true);
    await _changed();
  }

  Widget _card(Account a) {
    final s = _st[a.id] ?? AccState({});
    final th = Theme.of(context);
    final rows = <Widget>[];
    if (a.loggedOut) {
      rows.add(Text('Abgemeldet – bitte neu anmelden', style: TextStyle(color: th.colorScheme.error, fontWeight: FontWeight.w600)));
    } else if (!a.active) {
      rows.add(Text('Nicht aktiv: keine Alarme von diesem Backend', style: TextStyle(color: th.hintColor)));
    } else {
      if (s.inc != null) {
        final c = hexColor(s.color) ?? Colors.red;
        rows.add(Row(children: [
          Container(
              padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
              decoration: BoxDecoration(color: c, borderRadius: BorderRadius.circular(4)),
              child: Text(s.kind.toUpperCase(), style: const TextStyle(color: Colors.white, fontWeight: FontWeight.bold, fontSize: 12))),
          const SizedBox(width: 8),
          Expanded(child: Text(s.joined ? 'Angenommen' : (s.alarmed ? 'Alarm – noch nicht angenommen' : 'Laufender Einsatz'), style: const TextStyle(fontWeight: FontWeight.w600))),
        ]));
        if (s.title.isNotEmpty) rows.add(Padding(padding: const EdgeInsets.only(top: 4), child: Text(s.title, maxLines: 3, overflow: TextOverflow.ellipsis)));
      } else if (s.blocked) {
        rows.add(const Text('Laufender Einsatz ohne Aufgebot'));
      } else {
        rows.add(const Text('Kein Einsatz'));
      }
      rows.add(Padding(
          padding: const EdgeInsets.only(top: 4),
          child: Text(s.err != null ? 'Fehler: ${s.err} · zuletzt erreicht ${_ago(s.at)}' : 'Abgefragt ${_ago(s.at)}',
              style: TextStyle(fontSize: 12, color: s.err != null ? th.colorScheme.error : th.hintColor))));
      final p = _push[a.id];
      if (p == 'server') rows.add(Text('Push: auf diesem Server nicht eingerichtet${Platform.isIOS ? ' – Alarme nur bei offener App' : ''}', style: TextStyle(fontSize: 12, color: th.hintColor)));
    }
    return Card(
      margin: const EdgeInsets.fromLTRB(12, 6, 12, 6),
      child: InkWell(
        borderRadius: BorderRadius.circular(12),
        onTap: () => openAccount(a.id),
        child: Padding(
          padding: const EdgeInsets.fromLTRB(16, 12, 8, 12),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Row(children: [
              Expanded(
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(a.label, style: th.textTheme.titleMedium),
                Text('${a.name.isNotEmpty ? a.name : a.username} · ${hostPath(a.base)}', style: TextStyle(fontSize: 12, color: th.hintColor), overflow: TextOverflow.ellipsis),
              ])),
              Switch(value: a.active, onChanged: a.loggedOut ? null : (v) => _setActive(a, v)),
              PopupMenuButton<String>(
                onSelected: (v) async {
                  if (v == 'open') openAccount(a.id);
                  if (v == 'login') {
                    await Navigator.push(context, MaterialPageRoute(builder: (_) => LoginPage(relogin: a)));
                    await _changed();
                  }
                  if (v == 'remove') _remove(a);
                },
                itemBuilder: (_) => [
                  const PopupMenuItem(value: 'open', child: Text('Öffnen')),
                  const PopupMenuItem(value: 'login', child: Text('Neu anmelden')),
                  const PopupMenuItem(value: 'remove', child: Text('Entfernen')),
                ],
              ),
            ]),
            const SizedBox(height: 6),
            ...rows,
          ]),
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Swiftwater Rescue'), actions: [
        IconButton(
            tooltip: 'Einstellungen',
            icon: const Icon(Icons.settings),
            onPressed: () async {
              await Navigator.push(context, MaterialPageRoute(builder: (_) => SettingsPage(onIntervalChanged: widget.onIntervalChanged)));
              await _load();
            }),
      ]),
      floatingActionButton: FloatingActionButton.extended(onPressed: _add, icon: const Icon(Icons.add), label: const Text('Backend hinzufügen')),
      body: !_loaded
          ? const Center(child: CircularProgressIndicator())
          : RefreshIndicator(
              onRefresh: _refresh,
              child: _acc.isEmpty
                  ? ListView(padding: const EdgeInsets.all(24), children: const [
                      SizedBox(height: 60),
                      Icon(Icons.water, size: 64),
                      SizedBox(height: 16),
                      Text('Noch kein Backend', textAlign: TextAlign.center, style: TextStyle(fontSize: 20)),
                      SizedBox(height: 8),
                      Text('Melde dich mit «Backend hinzufügen» bei deiner Organisation an. Du kannst mehrere Backends hinzufügen und auf allen gleichzeitig aktiv sein.',
                          textAlign: TextAlign.center),
                    ])
                  : ListView(padding: const EdgeInsets.only(top: 6, bottom: 96), children: [
                      ..._acc.map(_card),
                      Padding(
                        padding: const EdgeInsets.all(16),
                        child: Text(
                            Platform.isAndroid
                                ? 'Aktive Konten werden im Hintergrund abgefragt (Anzeige «Bereit für Alarme»).'
                                : 'Bei geschlossener App kommen Alarme nur über Push, sofern der Server ihn eingerichtet hat.',
                            style: TextStyle(fontSize: 12, color: Theme.of(context).hintColor),
                            textAlign: TextAlign.center),
                      ),
                    ]),
            ),
    );
  }
}

class LoginPage extends StatefulWidget {
  const LoginPage({super.key, this.relogin});
  final Account? relogin;
  @override
  State<LoginPage> createState() => _LoginState();
}

class _LoginState extends State<LoginPage> {
  final _srv = TextEditingController(), _u = TextEditingController(), _p = TextEditingController();
  bool _busy = false;
  String? _err;

  @override
  void initState() {
    super.initState();
    final r = widget.relogin;
    if (r != null) {
      _srv.text = r.base;
      _u.text = r.username;
    }
  }

  Future<void> _go() async {
    final base = normBase(_srv.text), user = _u.text.trim();
    if (base.isEmpty || user.isEmpty || _p.text.isEmpty) {
      setState(() => _err = 'Bitte alle Felder ausfüllen.');
      return;
    }
    setState(() {
      _busy = true;
      _err = null;
    });
    try {
      final api = Api(base);
      await api.login(user, _p.text, '${Platform.isIOS ? 'iOS' : 'Android'}-App');
      final me = (await api.call('me'))['me'] as Map? ?? {};
      final l = await Accounts.load();
      // dasselbe Backend mit derselben Person ersetzen (z. B. neu anmelden)
      final old = widget.relogin ?? l.where((a) => a.base == base && a.username.toLowerCase() == user.toLowerCase()).firstOrNull;
      final org = '${me['org'] ?? ''}'.trim();
      final a = Account(
          id: old?.id ?? newId(),
          base: base,
          username: user,
          token: api.token!,
          name: '${me['name'] ?? ''}',
          org: org,
          active: old?.active ?? true,
          added: old?.added ?? DateTime.now().millisecondsSinceEpoch,
          uid: (me['id'] as num?)?.toInt() ?? 0);
      if (old != null && old.token != a.token && !old.loggedOut) await old.api.logout();
      if (old != null && old.base != base) await clearState(old.id);
      await Accounts.put(a);
      await requestNotePerms();
      await syncService();
      await syncPush(force: true);
      if (!mounted) return;
      Navigator.pop(context);
      openAccount(a.id);
    } on ApiError catch (e) {
      setState(() => _err = e.message);
    } catch (e) {
      setState(() => _err = '$e');
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final re = widget.relogin != null;
    return Scaffold(
      appBar: AppBar(title: Text(re ? 'Neu anmelden' : 'Backend hinzufügen')),
      body: SafeArea(
        child: ListView(padding: const EdgeInsets.all(20), children: [
          TextField(
              controller: _srv,
              enabled: !re,
              keyboardType: TextInputType.url,
              autocorrect: false,
              decoration: const InputDecoration(labelText: 'Server-Adresse', hintText: 'z. B. https://example.ch/swiftwater/', border: OutlineInputBorder())),
          const SizedBox(height: 12),
          TextField(controller: _u, autocorrect: false, decoration: const InputDecoration(labelText: 'Benutzername', border: OutlineInputBorder()), autofillHints: const [AutofillHints.username]),
          const SizedBox(height: 12),
          TextField(
              controller: _p,
              obscureText: true,
              decoration: const InputDecoration(labelText: 'Passwort', border: OutlineInputBorder()),
              autofillHints: const [AutofillHints.password],
              onSubmitted: (_) => _go()),
          if (_err != null) Padding(padding: const EdgeInsets.only(top: 12), child: Text(_err!, style: TextStyle(color: Theme.of(context).colorScheme.error))),
          const SizedBox(height: 20),
          FilledButton(onPressed: _busy ? null : _go, child: _busy ? const SizedBox(width: 20, height: 20, child: CircularProgressIndicator(strokeWidth: 2)) : const Text('Anmelden')),
          const SizedBox(height: 16),
          Text('Die Adresse ist dieselbe wie die der Web-App deiner Organisation. Die App erhält eine eigene Anmeldung; der Browser und andere Geräte bleiben angemeldet.',
              style: TextStyle(fontSize: 12, color: Theme.of(context).hintColor)),
        ]),
      ),
    );
  }
}

class SettingsPage extends StatefulWidget {
  const SettingsPage({super.key, required this.onIntervalChanged});
  final VoidCallback onIntervalChanged;
  @override
  State<SettingsPage> createState() => _SettingsState();
}

class _SettingsState extends State<SettingsPage> with WidgetsBindingObserver {
  int _iv = 15;
  bool? _bat, _dnd, _loc;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _load();
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState s) {
    if (s == AppLifecycleState.resumed) _load(); // zurück aus den Systemeinstellungen
  }

  Future<void> _load() async {
    final iv = await pollInterval(), bat = await batteryExempt(), dnd = await hasDndAccess(), loc = await hasLocation();
    if (mounted) {
      setState(() {
        _iv = iv;
        _bat = bat;
        _dnd = dnd;
        _loc = loc;
      });
    }
  }

  Widget _state(bool? ok) => ok == null ? const SizedBox() : Icon(ok ? Icons.check_circle : Icons.warning_amber, color: ok ? Colors.green : Colors.orange);

  @override
  Widget build(BuildContext context) {
    final hint = TextStyle(fontSize: 12, color: Theme.of(context).hintColor);
    return Scaffold(
      appBar: AppBar(title: const Text('Einstellungen')),
      body: ListView(children: [
        ListTile(
          title: const Text('Abfrage-Intervall'),
          subtitle: Text(Platform.isAndroid ? 'So oft fragt die App alle aktiven Backends im Hintergrund ab.' : 'So oft fragt die App bei offener App ab.'),
          trailing: DropdownButton<int>(
              value: intervals.contains(_iv) ? _iv : 15,
              items: intervals.map((s) => DropdownMenuItem(value: s, child: Text('$s s'))).toList(),
              onChanged: (v) async {
                if (v == null) return;
                await prefs.setInt('interval', v);
                setState(() => _iv = v);
                if (Platform.isAndroid) service.invoke('interval');
                widget.onIntervalChanged();
              }),
        ),
        const Divider(),
        ListTile(
            leading: const Icon(Icons.notifications_active),
            title: const Text('Benachrichtigungen erlauben'),
            subtitle: const Text('Alarme, Nachrichten und Einsatzende'),
            onTap: () async {
              final ok = await requestNotePerms();
              if (!ok) await openAppSettings();
              _load();
            }),
        if (Platform.isAndroid) ...[
          ListTile(
              leading: const Icon(Icons.do_not_disturb_on),
              title: const Text('«Nicht stören» umgehen'),
              subtitle: const Text('Damit kritische Alarme auch bei «Nicht stören» klingeln'),
              trailing: _state(_dnd),
              onTap: () async {
                await requestDndAccess();
                _load();
              }),
          ListTile(
              leading: const Icon(Icons.battery_alert),
              title: const Text('Akku-Optimierung ausnehmen'),
              subtitle: const Text('Sonst kann Android die Abfrage im Hintergrund anhalten'),
              trailing: _state(_bat),
              onTap: () async {
                await requestBatteryExempt();
                _load();
              }),
          ListTile(
              leading: const Icon(Icons.location_on),
              title: const Text('Standort erlauben'),
              subtitle: const Text('Für die Standortfreigabe im Einsatz'),
              trailing: _state(_loc),
              onTap: () async {
                await requestLocation();
                _load();
              }),
        ],
        ListTile(leading: const Icon(Icons.app_settings_alt), title: const Text('Systemeinstellungen der App'), onTap: openAppSettings, enabled: Platform.isAndroid),
        const Divider(),
        Padding(
          padding: const EdgeInsets.all(16),
          child: Text(
              'Push: ${pushBuilt ? 'in dieser App eingebaut, aktiv auf allen Backends, die ihn eingerichtet haben' : 'in dieser App nicht eingebaut'}.\n'
              '${Platform.isIOS ? 'iOS erlaubt keine Abfrage im Hintergrund: Bei geschlossener App kommen Alarme nur über Push. Kritische Alarme umgehen «Nicht stören» nur mit der Berechtigung für kritische Hinweise.' : 'Die App fragt alle aktiven Backends in einem Vordergrunddienst ab, auch bei geschlossener App und nach einem Neustart.'}\n\n'
              'Version $appUa',
              style: hint),
        ),
      ]),
    );
  }
}
