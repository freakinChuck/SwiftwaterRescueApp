package ch.swiftwaterrescue.app

import android.Manifest
import android.annotation.SuppressLint
import android.content.Intent
import android.content.pm.PackageManager
import android.net.Uri
import android.os.Build
import android.os.Bundle
import android.os.PowerManager
import android.provider.Settings
import io.flutter.embedding.android.FlutterActivity
import io.flutter.embedding.engine.FlutterEngine
import io.flutter.plugin.common.MethodChannel

// Systemfunktionen für lib/sys.dart: Akku-Optimierung, Standortberechtigung (auch «Immer zulassen»), App-Einstellungen.
// Gleichzeitige Anfragen zum Standort (WebView, Einstellungen) warten gemeinsam auf dieselbe Systemabfrage, statt sich gegenseitig abzubrechen.
class MainActivity : FlutterActivity() {
    private val locResults = mutableListOf<MethodChannel.Result>()
    private var locAsking = false

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        // Vollbild-Alarm auch auf dem Sperrbildschirm
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O_MR1) {
            setShowWhenLocked(true)
            setTurnScreenOn(true)
        }
    }

    private fun hasLoc() = checkSelfPermission(Manifest.permission.ACCESS_FINE_LOCATION) == PackageManager.PERMISSION_GRANTED ||
        checkSelfPermission(Manifest.permission.ACCESS_COARSE_LOCATION) == PackageManager.PERMISSION_GRANTED

    private fun hasBg() = Build.VERSION.SDK_INT < Build.VERSION_CODES.Q ||
        checkSelfPermission(Manifest.permission.ACCESS_BACKGROUND_LOCATION) == PackageManager.PERMISSION_GRANTED

    /// 0 = kein Standort, 1 = nur während der Nutzung, 2 = immer
    private fun locLevel() = if (!hasLoc()) 0 else if (hasBg()) 2 else 1

    private fun askLoc(result: MethodChannel.Result, perms: Array<String>, code: Int) {
        locResults.add(result)
        if (locAsking) return
        locAsking = true
        requestPermissions(perms, code)
    }

    @SuppressLint("BatteryLife")
    override fun configureFlutterEngine(flutterEngine: FlutterEngine) {
        super.configureFlutterEngine(flutterEngine)
        MethodChannel(flutterEngine.dartExecutor.binaryMessenger, "ch.swiftwaterrescue/sys").setMethodCallHandler { call, result ->
            when (call.method) {
                "batteryExempt" -> result.success((getSystemService(POWER_SERVICE) as PowerManager).isIgnoringBatteryOptimizations(packageName))
                "requestBatteryExempt" -> {
                    try {
                        startActivity(Intent(Settings.ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS, Uri.parse("package:$packageName")))
                    } catch (e: Exception) {
                        startActivity(Intent(Settings.ACTION_IGNORE_BATTERY_OPTIMIZATION_SETTINGS))
                    }
                    result.success(null)
                }
                "hasLocation" -> result.success(hasLoc())
                "locationLevel" -> result.success(locLevel())
                "requestLocation" -> {
                    if (hasLoc()) {
                        result.success(locLevel())
                    } else {
                        askLoc(result, arrayOf(Manifest.permission.ACCESS_FINE_LOCATION, Manifest.permission.ACCESS_COARSE_LOCATION), 4711)
                    }
                }
                // «Immer zulassen»: erst nach der Freigabe während der Nutzung möglich; ab Android 11 öffnet das System dafür die Einstellungsseite
                "requestBackgroundLocation" -> {
                    if (!hasLoc() || hasBg()) {
                        result.success(locLevel())
                    } else {
                        askLoc(result, arrayOf(Manifest.permission.ACCESS_BACKGROUND_LOCATION), 4712)
                    }
                }
                "appSettings" -> {
                    startActivity(Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS, Uri.parse("package:$packageName")))
                    result.success(null)
                }
                else -> result.notImplemented()
            }
        }
    }

    override fun onRequestPermissionsResult(requestCode: Int, permissions: Array<out String>, grantResults: IntArray) {
        super.onRequestPermissionsResult(requestCode, permissions, grantResults)
        if (requestCode == 4711 || requestCode == 4712) {
            locAsking = false
            val l = locLevel()
            val r = locResults.toList()
            locResults.clear()
            r.forEach { it.success(l) }
        }
    }
}
