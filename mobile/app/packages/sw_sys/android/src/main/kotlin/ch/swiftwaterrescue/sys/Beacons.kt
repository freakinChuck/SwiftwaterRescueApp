package ch.swiftwaterrescue.sys

import android.Manifest
import android.annotation.SuppressLint
import android.app.PendingIntent
import android.bluetooth.BluetoothManager
import android.bluetooth.le.ScanCallback
import android.bluetooth.le.ScanFilter
import android.bluetooth.le.ScanResult
import android.bluetooth.le.ScanSettings
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.os.Build
import android.os.Handler
import android.os.Looper
import org.json.JSONObject

// Fahrzeuge mit BLE-Beacons (Issue #177): iBeacons erkennen (Herstellerdaten von Apple 0x004C, Typ 0x02 0x15, UUID, Major, Minor, Sendeleistung).
// scan: kurzer aktiver Scan (im Einsatz, «Beacon einlernen»), Ergebnis je Beacon mit dem stärksten Signal.
// watch: stromsparender Hintergrund-Scan über PendingIntent mit Hardware-Filter auf die UUIDs (SCAN_MODE_LOW_POWER). Der Bluetooth-Chip filtert
// selbst; das System weckt nur den Empfänger BeaconReceiver, der die Sichtung in SharedPreferences ablegt. Die App holt sie mit pending ab
// (Vordergrunddienst bei jeder Abfrage, App beim Start), der Standort kommt von dort. Ohne laufenden Prozess startet Android den Empfänger selbst.
object Beacons {
    private const val PREFS = "sw_beacons"
    private const val APPLE = 0x004C
    private val main = Handler(Looper.getMainLooper())

    fun permitted(c: Context): Boolean =
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) c.checkSelfPermission(Manifest.permission.BLUETOOTH_SCAN) == PackageManager.PERMISSION_GRANTED
        else c.checkSelfPermission(Manifest.permission.ACCESS_FINE_LOCATION) == PackageManager.PERMISSION_GRANTED

    private fun scanner(c: Context) = try {
        val a = (c.getSystemService(Context.BLUETOOTH_SERVICE) as BluetoothManager).adapter
        if (a != null && a.isEnabled) a.bluetoothLeScanner else null
    } catch (_: Exception) {
        null
    }

    /** 0 = kein Bluetooth bzw. aus, 1 = an, aber keine Berechtigung, 2 = bereit */
    fun state(c: Context): Int = if (scanner(c) == null) 0 else if (permitted(c)) 2 else 1

    private fun hex(b: ByteArray, from: Int, n: Int) = (from until from + n).joinToString("") { "%02X".format(b[it]) }

    /** iBeacon aus den Herstellerdaten: {uuid, major, minor} oder null */
    fun parse(r: ScanResult): Triple<String, Int, Int>? {
        val d = r.scanRecord?.getManufacturerSpecificData(APPLE) ?: return null
        if (d.size < 23 || d[0].toInt() != 0x02 || d[1].toInt() != 0x15) return null
        val h = hex(d, 2, 16)
        val uuid = "${h.substring(0, 8)}-${h.substring(8, 12)}-${h.substring(12, 16)}-${h.substring(16, 20)}-${h.substring(20)}"
        val major = ((d[18].toInt() and 0xFF) shl 8) or (d[19].toInt() and 0xFF)
        val minor = ((d[20].toInt() and 0xFF) shl 8) or (d[21].toInt() and 0xFF)
        return Triple(uuid, major, minor)
    }

    /** Filter auf iBeacons, je UUID einer (leer = alle iBeacons, für «Beacon einlernen») */
    private fun filters(uuids: List<String>): List<ScanFilter> {
        if (uuids.isEmpty()) return listOf(ScanFilter.Builder().setManufacturerData(APPLE, byteArrayOf(0x02, 0x15), byteArrayOf(-1, -1)).build())
        return uuids.mapNotNull { u ->
            val h = u.replace("-", "")
            if (h.length != 32) return@mapNotNull null
            val data = ByteArray(18)
            val mask = ByteArray(18) { -1 }
            data[0] = 0x02; data[1] = 0x15
            for (i in 0 until 16) data[2 + i] = h.substring(i * 2, i * 2 + 2).toInt(16).toByte()
            ScanFilter.Builder().setManufacturerData(APPLE, data, mask).build()
        }
    }

    /** Aktiver Scan für ms Millisekunden; Ergebnis über done (Liste von Maps uuid, major, minor, rssi) */
    @SuppressLint("MissingPermission")
    fun scan(c: Context, uuids: List<String>, ms: Long, done: (List<Map<String, Any>>) -> Unit) {
        val s = scanner(c)
        if (s == null || !permitted(c)) {
            done(emptyList()); return
        }
        val best = HashMap<String, MutableMap<String, Any>>()
        val cb = object : ScanCallback() {
            override fun onScanResult(type: Int, r: ScanResult) {
                val b = parse(r) ?: return
                val k = "${b.first}|${b.second}|${b.third}"
                val o = best[k]
                if (o == null || (o["rssi"] as Int) < r.rssi) best[k] = mutableMapOf("uuid" to b.first, "major" to b.second, "minor" to b.third, "rssi" to r.rssi)
            }

            override fun onBatchScanResults(rs: MutableList<ScanResult>) = rs.forEach { onScanResult(0, it) }
        }
        try {
            s.startScan(filters(uuids), ScanSettings.Builder().setScanMode(ScanSettings.SCAN_MODE_BALANCED).build(), cb)
        } catch (_: Exception) {
            done(emptyList()); return
        }
        main.postDelayed({
            try {
                s.stopScan(cb)
            } catch (_: Exception) {
            }
            done(best.values.map { it.toMap() })
        }, ms)
    }

    private fun pi(c: Context): PendingIntent {
        val i = Intent(c, BeaconReceiver::class.java).setAction("ch.swiftwaterrescue.sys.BEACON")
        val f = PendingIntent.FLAG_UPDATE_CURRENT or (if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) PendingIntent.FLAG_MUTABLE else 0)
        return PendingIntent.getBroadcast(c, 4714, i, f)
    }

    /** Hintergrund-Scan auf diese UUIDs starten (leer = beenden). Gibt true zurück, wenn er läuft. */
    @SuppressLint("MissingPermission")
    fun watch(c: Context, uuids: List<String>): Boolean {
        val s = scanner(c)
        val sp = c.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        val key = uuids.sorted().joinToString(",")
        if (s == null || !permitted(c)) {
            sp.edit().remove("watch").apply(); return false
        }
        if (uuids.isEmpty()) {
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) try {
                s.stopScan(pi(c))
            } catch (_: Exception) {
            }
            sp.edit().remove("watch").apply(); return false
        }
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) return false // Scan über PendingIntent erst ab Android 8
        // läuft schon: nicht bei jeder Abfrage neu starten (Android erlaubt höchstens 5 Starts in 30 s). Nach einem Neustart des Geräts oder
        // «Beenden erzwingen» ist der Scan weg, deshalb spätestens nach einer Stunde neu anmelden.
        if (sp.getString("watch", null) == key && System.currentTimeMillis() - sp.getLong("watch_at", 0) < 3600_000) return true
        return try {
            try {
                s.stopScan(pi(c))
            } catch (_: Exception) {
            }
            s.startScan(filters(uuids), ScanSettings.Builder().setScanMode(ScanSettings.SCAN_MODE_LOW_POWER).setCallbackType(ScanSettings.CALLBACK_TYPE_ALL_MATCHES).build(), pi(c))
            sp.edit().putString("watch", key).putLong("watch_at", System.currentTimeMillis()).apply(); true
        } catch (_: Exception) {
            false
        }
    }

    /** Sichtung ablegen: je Beacon die letzte (Zeitpunkt in s, stärkstes Signal innerhalb einer Minute) */
    fun store(c: Context, uuid: String, major: Int, minor: Int, rssi: Int) {
        val sp = c.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        val o = try {
            JSONObject(sp.getString("pending", "{}") ?: "{}")
        } catch (_: Exception) {
            JSONObject()
        }
        val k = "$uuid|$major|$minor"
        val now = System.currentTimeMillis() / 1000
        val p = o.optJSONObject(k)
        if (p != null && now - p.optLong("at") < 60 && p.optInt("rssi") >= rssi) return
        o.put(k, JSONObject().put("uuid", uuid).put("major", major).put("minor", minor).put("rssi", rssi).put("at", now))
        sp.edit().putString("pending", o.toString()).apply()
    }

    /** abgelegte Sichtungen abholen und löschen */
    fun pending(c: Context): List<Map<String, Any>> {
        val sp = c.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        val o = try {
            JSONObject(sp.getString("pending", "{}") ?: "{}")
        } catch (_: Exception) {
            JSONObject()
        }
        sp.edit().remove("pending").apply()
        return o.keys().asSequence().mapNotNull { k ->
            val p = o.optJSONObject(k) ?: return@mapNotNull null
            mapOf("uuid" to p.optString("uuid"), "major" to p.optInt("major"), "minor" to p.optInt("minor"), "rssi" to p.optInt("rssi"), "at" to p.optLong("at"))
        }.toList()
    }
}

/** Empfänger des Hintergrund-Scans: legt die Sichtungen nur ab, gesendet wird von der App (mit Standort) */
class BeaconReceiver : BroadcastReceiver() {
    override fun onReceive(c: Context, i: Intent) {
        @Suppress("DEPRECATION")
        val rs: List<ScanResult> = (if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) i.getParcelableArrayListExtra("android.bluetooth.le.extra.LIST_SCAN_RESULT", ScanResult::class.java)
        else i.getParcelableArrayListExtra("android.bluetooth.le.extra.LIST_SCAN_RESULT")) ?: return
        for (r in rs) {
            val b = Beacons.parse(r) ?: continue
            Beacons.store(c, b.first, b.second, b.third, r.rssi)
        }
    }
}
