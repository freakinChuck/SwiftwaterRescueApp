#!/usr/bin/env python3
"""Töne für den kritischen Alarm der nativen App (Issue #171).

Alle Töne sind hier synthetisch erzeugt (keine Aufnahmen, keine fremden Lizenzen). Signalhörner und Sirenen sind dem Klang
nachgebildet, keine Aufnahmen echter Fahrzeuge oder Sirenen.

Ausgabe: mobile/app/assets/sounds/alarm_<Schlüssel>.wav (PCM 16 bit, mono, 11 025 Hz). Jede Datei ist kürzer als 30 s
(Grenze von iOS für Benachrichtigungstöne) und besteht aus ganzen Perioden ihres Musters, damit Android sie nahtlos in
Schleife spielen kann. Katalog (Namen, Gruppen) in mobile/app/lib/sounds.dart und ALARM_SOUNDS in web/api/index.php.

Aufruf (aus dem Hauptordner): python3 scripts/sounds/gen.py   (braucht numpy)
"""
import os
import sys
import wave

import numpy as np

SR = 11025
MAX_S = 29.0  # iOS spielt Benachrichtigungstöne höchstens 30 s
OUT = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..', 'mobile', 'app', 'assets', 'sounds')


def t_of(sec):
    return np.arange(int(round(sec * SR))) / SR


def phase(freq):
    """Phase aus einer (zeitlich veränderlichen) Frequenz, damit Gleittöne ohne Sprünge klingen."""
    return 2 * np.pi * np.cumsum(freq) / SR


def rich(ph, parts):
    """Klang aus Teiltönen: parts = [(Vielfaches, Stärke), …]."""
    return sum(a * np.sin(k * ph) for k, a in parts)


HORN = [(1, 1.0), (2, 0.55), (3, 0.35), (4, 0.2), (5, 0.12), (6, 0.07)]  # Kompressorhorn: obertonreich
SIREN = [(1, 1.0), (2, 0.3), (3, 0.15), (4, 0.06)]


def env(n, a=0.01, r=0.02):
    """Hüllkurve gegen Knackser: Anstieg a s, Abklingen r s."""
    e = np.ones(n)
    na, nr = min(n, int(a * SR)), min(n, int(r * SR))
    if na:
        e[:na] = np.linspace(0, 1, na)
    if nr:
        e[n - nr:] = np.minimum(e[n - nr:], np.linspace(1, 0, nr))
    return e


def steps(seq, parts=HORN, a=0.015, r=0.015):
    """Folge fester Töne: seq = [(Frequenz oder 0 = Pause, Dauer s), …]."""
    out = []
    for f, d in seq:
        n = int(round(d * SR))
        if f <= 0:
            out.append(np.zeros(n))
            continue
        ph = 2 * np.pi * f * np.arange(n) / SR
        out.append(rich(ph, parts) * env(n, a, r))
    return np.concatenate(out)


def bell(f, d, decay=1.6, parts=((1, 1.0), (2.76, 0.5), (5.4, 0.25), (8.9, 0.12))):
    """Glocken- bzw. Gongton (unharmonische Teiltöne, klingt aus)."""
    t = t_of(d)
    s = sum(a * np.sin(2 * np.pi * f * k * t) * np.exp(-t * decay * (1 + k / 3)) for k, a in parts)
    return s * env(len(t), 0.003, 0.05)


def beep(f, d, sq=False):
    t = t_of(d)
    s = np.sign(np.sin(2 * np.pi * f * t)) * 0.6 if sq else np.sin(2 * np.pi * f * t)
    return s * env(len(t), 0.004, 0.01)


def silence(d):
    return np.zeros(int(round(d * SR)))


# ---------- Signalhörner ----------
def horn2():  # Zweiklang wie das Martinshorn: a' und d'' (Quarte), je ca. 0.75 s
    return steps([(440, 0.75), (587.3, 0.75)])


def horn2_fast():  # schneller Zweiklang
    return steps([(440, 0.36), (587.3, 0.36)])


def hilo():  # Hi-Lo, wie bei vielen elektronischen Anlagen in Europa
    return steps([(960, 0.5), (770, 0.5)], parts=[(1, 1.0), (3, 0.3), (5, 0.15)], a=0.005, r=0.005)


def wail():  # Heulton: langsam auf und ab
    t = t_of(4.0)
    f = 650 + 650 * (0.5 - 0.5 * np.cos(2 * np.pi * t / 4.0))
    return rich(phase(f), [(1, 1.0), (3, 0.25), (5, 0.1)])


def yelp():  # Yelp: schnell auf und ab
    t = t_of(0.32)
    f = 700 + 900 * (0.5 - 0.5 * np.cos(2 * np.pi * t / 0.32))
    return rich(phase(f), [(1, 1.0), (3, 0.25), (5, 0.1)])


def airhorn():  # Presslufthorn: zwei tiefe, raue Stösse
    def blast(d):
        t = t_of(d)
        ph1, ph2 = 2 * np.pi * 233 * t, 2 * np.pi * 294 * t
        s = rich(ph1, [(k, 1 / k) for k in range(1, 12)]) + 0.8 * rich(ph2, [(k, 1 / k) for k in range(1, 10)])
        return np.tanh(1.6 * s) * env(len(t), 0.03, 0.08)
    return np.concatenate([blast(0.9), silence(0.25), blast(0.9), silence(0.9)])


# ---------- Sirenen ----------
def mech_sound(f):
    return rich(phase(f), SIREN)


def siren_general():  # Allgemeiner Alarm: regelmässig auf- und absteigender Heulton (Periode hier 4 s)
    t = t_of(4.0)
    f = 360 + 160 * (0.5 - 0.5 * np.cos(2 * np.pi * t / 4.0))
    return mech_sound(f)


def siren_water():  # Wasseralarm: tiefe Dauertöne mit Pausen (gegenüber dem Original stark verkürzt: 5 s Ton, 2.5 s Pause)
    t = t_of(5.0)
    f = 200 + 8 * np.sin(2 * np.pi * 0.7 * t)
    s = mech_sound(f) * env(len(t), 0.6, 0.8)
    return np.concatenate([s, silence(2.5)])


def siren_mech():  # mechanische Sirene: läuft an, hält, läuft aus
    t = t_of(7.0)
    up = np.clip(t / 2.5, 0, 1)
    down = np.clip((7.0 - t) / 2.0, 0, 1)
    k = np.minimum(up, down)
    f = 80 + 420 * np.sqrt(k)
    return mech_sound(f) * np.clip(k * 3, 0, 1) ** 0.7


# ---------- Melodien und Signale ----------
def beep3():  # dreifacher Ton wie in der Web-App
    return np.concatenate([beep(880, 0.4), silence(0.2), beep(880, 0.4), silence(0.2), beep(880, 0.4), silence(0.9)])


def clock():  # Digitalwecker: vier kurze Pieptöne
    b = np.concatenate([beep(2000, 0.09, sq=True), silence(0.06)])
    return np.concatenate([b, b, b, b, silence(0.5)])


def bell_ring():  # elektrische Alarmglocke: schnelle Klöppelschläge, 2 s läuten, 0.6 s Pause
    hit = bell(1250, 1 / 18, decay=8)
    ring = np.concatenate([hit] * 36)
    return np.concatenate([ring * env(len(ring), 0.005, 0.05), silence(0.6)])


def gong():  # Gong-Dreiklang (c'' e'' g''), absteigend wie ein Durchsagegong
    tone = lambda f: bell(f, 1.0, decay=1.2, parts=((1, 1.0), (2, 0.25), (3, 0.08)))
    a = np.zeros(int(3.0 * SR))
    for i, f in enumerate([784.0, 659.3, 523.3]):
        s = tone(f)
        o = int(i * 0.45 * SR)
        a[o:o + len(s)] += s
    return a


def pager():  # Pager: Tonfolge hoch-tief, dreimal, Pause
    one = np.concatenate([beep(2500, 0.12), beep(2000, 0.12)])
    return np.concatenate([one, one, one, silence(0.6)])


def rising():  # aufsteigende Tonfolge (Dreiklang über zwei Oktaven)
    notes = [523.3, 659.3, 784.0, 1046.5, 1318.5, 1568.0]
    return np.concatenate([np.concatenate([beep(f, 0.13), silence(0.03)]) for f in notes] + [silence(0.5)])


SOUNDS = {
    'horn2': horn2, 'horn2_fast': horn2_fast, 'hilo': hilo, 'wail': wail, 'yelp': yelp, 'airhorn': airhorn,
    'siren_general': siren_general, 'siren_water': siren_water, 'siren_mech': siren_mech,
    'beep3': beep3, 'clock': clock, 'bell': bell_ring, 'gong': gong, 'pager': pager, 'rising': rising,
}


def render(fn):
    p = fn()
    n = int(MAX_S * SR) // len(p)  # ganze Perioden, nahtlos in Schleife
    s = np.tile(p, max(1, n))
    s = s / (np.max(np.abs(s)) or 1)
    if np.sqrt(np.mean(s ** 2)) < 0.35:  # leise Klänge (Glocke, Gong) verdichten, damit sie so laut wirken wie die übrigen
        s = np.tanh(3 * s) / np.tanh(3)
    s = s * 0.89  # Spitze ca. -1 dBFS
    return (s * 32767).astype('<i2')


def main():
    os.makedirs(OUT, exist_ok=True)
    only = set(sys.argv[1:])
    for k, fn in SOUNDS.items():
        if only and k not in only:
            continue
        d = render(fn)
        p = os.path.join(OUT, f'alarm_{k}.wav')
        with wave.open(p, 'wb') as w:
            w.setnchannels(1)
            w.setsampwidth(2)
            w.setframerate(SR)
            w.writeframes(d.tobytes())
        print(f'{p}: {len(d) / SR:.1f} s, {os.path.getsize(p) // 1024} KB')


if __name__ == '__main__':
    main()
