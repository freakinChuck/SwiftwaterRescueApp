import 'package:flutter_test/flutter_test.dart';
import 'package:swiftwater_rescue/api.dart';
import 'package:swiftwater_rescue/notify.dart';

void main() {
  test('Server-Adresse vereinheitlichen', () {
    expect(normBase('example.ch/swiftwater'), 'https://example.ch/swiftwater/');
    expect(normBase('https://example.ch/sw/index.html'), 'https://example.ch/sw/');
    expect(normBase('https://example.ch/sw/api/index.php'), 'https://example.ch/sw/');
    expect(normBase('http://localhost:8000'), 'http://localhost:8000/');
    expect(hostPath('https://example.ch/swiftwater/'), 'example.ch/swiftwater/');
  });
  test('Kennung je Konto und Schlüssel stabil', () {
    expect(noteId('a', 'i:1'), noteId('a', 'i:1'));
    expect(noteId('a', 'i:1') == noteId('b', 'i:1'), false);
    expect(noteId('a', 'i:1') >= 10, true);
  });
}
