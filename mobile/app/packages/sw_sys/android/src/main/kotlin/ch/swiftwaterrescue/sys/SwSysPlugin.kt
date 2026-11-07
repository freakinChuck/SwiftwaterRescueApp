package ch.swiftwaterrescue.sys

import android.Manifest
import android.annotation.SuppressLint
import android.app.Activity
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.media.AudioAttributes
import android.media.AudioManager
import android.media.MediaPlayer
import android.media.RingtoneManager
import android.net.Uri
import android.os.Build
import android.os.Handler
import android.os.Looper
import android.os.PowerManager
import android.os.VibrationEffect
import android.os.Vibrator
import android.os.VibratorManager
import android.provider.Settings
import io.flutter.embedding.engine.plugins.FlutterPlugin
import io.flutter.embedding.engine.plugins.activity.ActivityAware
import io.flutter.embedding.engine.plugins.activity.ActivityPluginBinding
import io.flutter.plugin.common.MethodChannel
import io.flutter.plugin.common.PluginRegistry

// Systemfunktionen für lib/sys.dart: Akku-Optimierung, Standortberechtigung (auch «Immer zulassen»), App-Einstellungen und der Weckton
// kritischer Alarme. Als Plugin steht der Kanal in jeder Engine bereit, auch im Vordergrunddienst (dort ohne Activity: Abfragen der
// Berechtigung liefern nur den Stand). Gleichzeitige Anfragen zum Standort warten gemeinsam auf dieselbe Systemabfrage.
class SwSysPlugin : FlutterPlugin, ActivityAware, PluginRegistry.RequestPermissionsResultListener {
    private lateinit var ctx: Context
    private var channel: MethodChannel? = null
    private var activity: Activity? = null
    private var binding: ActivityPluginBinding? = null
    private val locResults = mutableListOf<MethodChannel.Result>()
    private var locAsking = false

    companion object {
        // Weckton gemeinsam für alle Engines im Prozess (App und Dienst)
        private var player: MediaPlayer? = null
        private var vib: Vibrator? = null
        private var volBefore: Int? = null
        private val main = Handler(Looper.getMainLooper())
        private val autoStop = Runnable { stopAlarm() }
        private const val MAX_MS = 5 * 60 * 1000L

        @Suppress("DEPRECATION")
        fun startAlarm(c: Context) {
            stopAlarm()
            val am = c.getSystemService(Context.AUDIO_SERVICE) as AudioManager
            // Lautstärke des Weckers mindestens auf 80 %, danach zurück (ein Wecker auf 0 bliebe sonst stumm)
            try {
                val max = am.getStreamMaxVolume(AudioManager.STREAM_ALARM)
                val cur = am.getStreamVolume(AudioManager.STREAM_ALARM)
                val want = (max * 0.8).toInt().coerceAtLeast(1)
                if (cur < want) {
                    volBefore = cur
                    am.setStreamVolume(AudioManager.STREAM_ALARM, want, 0)
                }
            } catch (_: Exception) {
                volBefore = null
            }
            val uri = RingtoneManager.getDefaultUri(RingtoneManager.TYPE_ALARM)
                ?: RingtoneManager.getDefaultUri(RingtoneManager.TYPE_RINGTONE)
                ?: RingtoneManager.getDefaultUri(RingtoneManager.TYPE_NOTIFICATION)
            try {
                player = MediaPlayer().apply {
                    setAudioAttributes(AudioAttributes.Builder().setUsage(AudioAttributes.USAGE_ALARM).setContentType(AudioAttributes.CONTENT_TYPE_SONIFICATION).build())
                    setDataSource(c, uri)
                    isLooping = true
                    prepare()
                    start()
                }
            } catch (_: Exception) {
                player = null
            }
            try {
                val v = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) (c.getSystemService(Context.VIBRATOR_MANAGER_SERVICE) as VibratorManager).defaultVibrator
                else c.getSystemService(Context.VIBRATOR_SERVICE) as Vibrator
                val pattern = longArrayOf(0, 800, 400, 800, 400, 800, 1200)
                if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
                    v.vibrate(VibrationEffect.createWaveform(pattern, 0), android.os.VibrationAttributes.createForUsage(android.os.VibrationAttributes.USAGE_ALARM))
                } else {
                    v.vibrate(VibrationEffect.createWaveform(pattern, 0), AudioAttributes.Builder().setUsage(AudioAttributes.USAGE_ALARM).build())
                }
                vib = v
            } catch (_: Exception) {
                vib = null
            }
            main.postDelayed(autoStop, MAX_MS)
            restoreCtx = c.applicationContext
        }

        private var restoreCtx: Context? = null

        fun stopAlarm() {
            main.removeCallbacks(autoStop)
            try {
                player?.stop()
            } catch (_: Exception) {
            }
            player?.release()
            player = null
            vib?.cancel()
            vib = null
            val v = volBefore
            val c = restoreCtx
            if (v != null && c != null) {
                try {
                    (c.getSystemService(Context.AUDIO_SERVICE) as AudioManager).setStreamVolume(AudioManager.STREAM_ALARM, v, 0)
                } catch (_: Exception) {
                }
            }
            volBefore = null
        }
    }

    override fun onAttachedToEngine(b: FlutterPlugin.FlutterPluginBinding) {
        ctx = b.applicationContext
        channel = MethodChannel(b.binaryMessenger, "ch.swiftwaterrescue/sys").also { it.setMethodCallHandler { call, result -> handle(call.method, result) } }
    }

    override fun onDetachedFromEngine(b: FlutterPlugin.FlutterPluginBinding) {
        channel?.setMethodCallHandler(null)
        channel = null
    }

    override fun onAttachedToActivity(b: ActivityPluginBinding) {
        activity = b.activity
        binding = b
        b.addRequestPermissionsResultListener(this)
    }

    override fun onDetachedFromActivity() {
        binding?.removeRequestPermissionsResultListener(this)
        binding = null
        activity = null
        finishLoc()
    }

    override fun onDetachedFromActivityForConfigChanges() = onDetachedFromActivity()
    override fun onReattachedToActivityForConfigChanges(b: ActivityPluginBinding) = onAttachedToActivity(b)

    private fun granted(p: String) = ctx.checkSelfPermission(p) == PackageManager.PERMISSION_GRANTED
    private fun hasLoc() = granted(Manifest.permission.ACCESS_FINE_LOCATION) || granted(Manifest.permission.ACCESS_COARSE_LOCATION)
    private fun hasBg() = Build.VERSION.SDK_INT < Build.VERSION_CODES.Q || granted(Manifest.permission.ACCESS_BACKGROUND_LOCATION)

    /** 0 = kein Standort, 1 = nur während der Nutzung, 2 = immer */
    private fun locLevel() = if (!hasLoc()) 0 else if (hasBg()) 2 else 1

    private fun askLoc(result: MethodChannel.Result, perms: Array<String>, code: Int) {
        val a = activity
        if (a == null) {
            result.success(locLevel())
            return
        }
        locResults.add(result)
        if (locAsking) return
        locAsking = true
        a.requestPermissions(perms, code)
    }

    private fun finishLoc() {
        locAsking = false
        val l = locLevel()
        val r = locResults.toList()
        locResults.clear()
        r.forEach { it.success(l) }
    }

    private fun start(i: Intent) {
        val a = activity
        if (a != null) a.startActivity(i) else ctx.startActivity(i.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
    }

    @SuppressLint("BatteryLife")
    private fun handle(m: String, result: MethodChannel.Result) {
        when (m) {
            "batteryExempt" -> result.success((ctx.getSystemService(Context.POWER_SERVICE) as PowerManager).isIgnoringBatteryOptimizations(ctx.packageName))
            "requestBatteryExempt" -> {
                try {
                    start(Intent(Settings.ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS, Uri.parse("package:${ctx.packageName}")))
                } catch (e: Exception) {
                    start(Intent(Settings.ACTION_IGNORE_BATTERY_OPTIMIZATION_SETTINGS))
                }
                result.success(null)
            }
            "locationLevel" -> result.success(locLevel())
            "requestLocation" -> {
                if (hasLoc()) result.success(locLevel())
                else askLoc(result, arrayOf(Manifest.permission.ACCESS_FINE_LOCATION, Manifest.permission.ACCESS_COARSE_LOCATION), 4711)
            }
            // «Immer zulassen»: erst nach der Freigabe während der Nutzung möglich; ab Android 11 öffnet das System dafür die Einstellungsseite
            "requestBackgroundLocation" -> {
                if (!hasLoc() || hasBg()) result.success(locLevel())
                else askLoc(result, arrayOf(Manifest.permission.ACCESS_BACKGROUND_LOCATION), 4712)
            }
            "appSettings" -> {
                start(Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS, Uri.parse("package:${ctx.packageName}")))
                result.success(null)
            }
            "alarmStart" -> {
                startAlarm(ctx)
                result.success(player != null)
            }
            "alarmStop" -> {
                stopAlarm()
                result.success(null)
            }
            "alarmActive" -> result.success(player != null)
            else -> result.notImplemented()
        }
    }

    override fun onRequestPermissionsResult(requestCode: Int, permissions: Array<out String>, grantResults: IntArray): Boolean {
        if (requestCode != 4711 && requestCode != 4712) return false
        finishLoc()
        return true
    }
}
