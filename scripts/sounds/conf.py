#!/usr/bin/env python3
"""Töne beim Beitreten und Verlassen der Live-Konferenz (Issue #113), für die native App.

Zwei weiche Sinustöne mit kurzem Einschwingen und Ausklingen, steigend beim Beitreten, fallend beim Verlassen.
Die Web-App erzeugt dieselben Töne mit der Web Audio API (vxTone in web/voice.js); Frequenzen und Dauer hier gleich halten.

Ausgabe: mobile/app/assets/sounds/conf_join.wav und conf_leave.wav (PCM 16 bit, mono, 22 050 Hz).
Aufruf (aus dem Hauptordner): python3 scripts/sounds/conf.py   (nur Standardbibliothek)
"""
import math
import os
import struct
import wave

SR = 22050
OUT = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..', 'mobile', 'app', 'assets', 'sounds')
NOTES = {'join': (587.33, 880.0), 'leave': (880.0, 587.33)}  # D5 → A5 bzw. umgekehrt
STEP = 0.09   # Abstand der beiden Töne in s
DUR = 0.22    # Klingdauer je Ton in s
PEAK = 0.32   # leise: höchstens ein Drittel der vollen Lautstärke


def tone(f, t):
    if t < 0 or t > DUR:
        return 0.0
    env = min(1.0, t / 0.012) * math.exp(-t * 14)  # 12 ms einschwingen, dann ausklingen
    return env * (math.sin(2 * math.pi * f * t) + 0.12 * math.sin(4 * math.pi * f * t))


def main():
    os.makedirs(OUT, exist_ok=True)
    n = int((STEP + DUR + 0.02) * SR)
    for k, (a, b) in NOTES.items():
        s = [tone(a, i / SR) + tone(b, i / SR - STEP) for i in range(n)]
        m = max(abs(x) for x in s)
        data = b''.join(struct.pack('<h', int(x / m * PEAK * 32767)) for x in s)
        p = os.path.join(OUT, f'conf_{k}.wav')
        with wave.open(p, 'wb') as w:
            w.setnchannels(1)
            w.setsampwidth(2)
            w.setframerate(SR)
            w.writeframes(data)
        print(p)


if __name__ == '__main__':
    main()
