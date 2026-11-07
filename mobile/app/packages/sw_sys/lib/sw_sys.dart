// Kanal zum Plugin (android/…/SwSysPlugin.kt). Als Plugin registriert Flutter ihn in jeder Engine, also auch im Isolate des Vordergrunddienstes.
import 'package:flutter/services.dart';

const swSys = MethodChannel('ch.swiftwaterrescue/sys');
