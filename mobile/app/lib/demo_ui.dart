// Demo-Modus (Issue #74): Einstieg in der Kontenliste und Steuerung über die Kennzeichnung «DEMO» in der Einsatz-App
// (Gegenstück zu panel/badge in web/demo.js).
import 'package:flutter/material.dart';

import 'demo.dart';
import 'main.dart';

const demoColor = Color(0xFF7B1FA2);

/// Karte in der Kontenliste: Demo mit oder ohne Beispieldaten starten bzw. weiterführen
class DemoCard extends StatelessWidget {
  const DemoCard({super.key});
  @override
  Widget build(BuildContext context) => Card(
    margin: const EdgeInsets.fromLTRB(12, 8, 12, 4),
    child: Padding(
      padding: const EdgeInsets.all(14),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Text('🎓 Demo-Modus', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 16)),
          const SizedBox(height: 4),
          Text(
            'Die Einsatz-App ohne Konto und ohne Server ausprobieren: Niemand wird alarmiert, nichts wird gespeichert oder gesendet.',
            style: TextStyle(fontSize: 13, color: Theme.of(context).hintColor),
          ),
          const SizedBox(height: 8),
          Wrap(
            spacing: 8,
            runSpacing: 6,
            children: [
              if (Demo.started)
                FilledButton(
                  onPressed: () => openDemo(),
                  style: FilledButton.styleFrom(backgroundColor: demoColor),
                  child: const Text('Weiterführen'),
                ),
              if (Demo.started)
                OutlinedButton(onPressed: () => openDemo('bsp'), child: const Text('Mit Beispieldaten'))
              else
                FilledButton(onPressed: () => openDemo('bsp'), child: const Text('Mit Beispieldaten')),
              OutlinedButton(onPressed: () => openDemo('leer'), child: const Text('Ohne Daten')),
            ],
          ),
        ],
      ),
    ),
  );
}

/// Kennzeichnung oben links in der Einsatz-App; Antippen öffnet die Steuerung
class DemoBadge extends StatelessWidget {
  const DemoBadge({super.key, required this.onChanged});
  final VoidCallback onChanged;
  @override
  Widget build(BuildContext context) => GestureDetector(
    onTap: () => demoPanel(context, onChanged),
    child: Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 3),
      decoration: BoxDecoration(
        color: demoColor,
        borderRadius: BorderRadius.circular(99),
        border: Border.all(color: Colors.white, width: 2),
      ),
      child: const Text(
        'DEMO',
        style: TextStyle(color: Colors.white, fontWeight: FontWeight.w800, fontSize: 12, letterSpacing: 1),
      ),
    ),
  );
}

/// Steuerung: Simulation und Leitungsrechte umschalten, neu starten, beenden. onChanged: Stand neu laden.
Future<void> demoPanel(BuildContext context, VoidCallback onChanged, {bool first = false}) => showDialog<void>(
  context: context,
  builder: (c) => StatefulBuilder(
    builder: (c, set) {
      final d = Demo.i;
      return AlertDialog(
        title: const Text('🎓 Demo-Modus'),
        scrollable: true,
        content: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          mainAxisSize: MainAxisSize.min,
          children: [
            const Text(
              'Hier kannst du die Einsatz-App gefahrlos ausprobieren. Alles läuft nur in dieser App: Nichts geht an einen Server, '
              'niemand wird alarmiert, und deine Konten bleiben unberührt. Beim nächsten Start der App beginnt das Demo von vorn.',
            ),
            const SizedBox(height: 8),
            Text(
              d.mode == 'bsp'
                  ? 'Beispieldaten: Ein Einsatz an der Reuss in Luzern läuft bereits (fiktiv). Nimm ihn über das Menü an und beobachte, wie die simulierten '
                        'Einsatzkräfte anfahren, Nachrichten senden und den Rettling finden.'
                  : 'Ohne Daten: Es läuft kein Einsatz. Starte über das Menü einen eigenen und setze einen PLS an einem Fluss; simulierte Einsatzkräfte nehmen an und fahren hin.',
            ),
            SwitchListTile(
              contentPadding: EdgeInsets.zero,
              value: d.sim,
              onChanged: (v) => set(() => d.sim = v),
              title: const Text('Andere Einsatzkräfte simulieren'),
              subtitle: const Text('Annahmen, Standorte, Nachrichten'),
            ),
            SwitchListTile(
              contentPadding: EdgeInsets.zero,
              value: d.lead,
              onChanged: (v) {
                set(() => d.lead = v);
                onChanged();
              },
              title: const Text('Rechte der Einsatzleitung'),
              subtitle: const Text('Nach dem Annehmen alle Funktionen; aus = wie eine gewöhnliche Einsatzkraft'),
            ),
          ],
        ),
        actions: [
          TextButton(onPressed: () => _restart(c, 'bsp'), child: const Text('Neu mit Beispieldaten')),
          TextButton(onPressed: () => _restart(c, 'leer'), child: const Text('Neu ohne Daten')),
          TextButton(
            onPressed: () {
              Navigator.pop(c);
              navKey.currentState?.popUntil((r) => r.isFirst);
            },
            child: const Text('Demo beenden'),
          ),
          FilledButton(onPressed: () => Navigator.pop(c), child: Text(first ? 'Los geht’s' : 'Schliessen')),
        ],
      );
    },
  ),
);

void _restart(BuildContext c, String mode) {
  Navigator.pop(c);
  navKey.currentState?.popUntil((r) => r.isFirst);
  openDemo(mode);
}
