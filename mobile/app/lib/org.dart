// Logo der Organisation (Issue #123): dasselbe Bild wie im After Action Review (Verwaltung → «After Action Review einrichten»), vom Server des Kontos
// (Route org/logo ohne Anmeldung; info = me.org_info bzw. Route org: {name, logo}, logo = Stand, ändert die Adresse). Auf hellem Grund, damit auch
// dunkle Logos gut sichtbar sind. Ohne Logo, im Demo oder bei einem Fehler nichts.
import 'package:flutter/material.dart';

String? orgLogoUrl(String base, Object? info) {
  if (info is! Map || base.startsWith('demo:')) return null;
  final v = info['logo'];
  if (v is! String || v.isEmpty) return null;
  final o = info['o']; // Mandanten (Issue #60): Logo der Organisation des Kontos
  return Uri.parse('${base}api/index.php').replace(queryParameters: {'r': 'org/logo', 'v': v, if (o is num && o > 1) 'o': '$o'}).toString();
}

class OrgLogo extends StatelessWidget {
  const OrgLogo(this.base, this.info, {super.key, this.h = 32, this.maxW = 110});
  final String base;
  final Object? info;
  final double h, maxW;

  @override
  Widget build(BuildContext context) {
    final u = orgLogoUrl(base, info);
    if (u == null) return const SizedBox.shrink();
    return Container(
      constraints: BoxConstraints(maxWidth: maxW, maxHeight: h),
      padding: const EdgeInsets.symmetric(horizontal: 4, vertical: 2),
      decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(5)),
      child: Image.network(u, height: h - 4, fit: BoxFit.contain, semanticLabel: 'Logo der Organisation', errorBuilder: (_, _, _) => const SizedBox.shrink()),
    );
  }
}
