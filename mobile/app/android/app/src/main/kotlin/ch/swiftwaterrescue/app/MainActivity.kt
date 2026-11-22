package ch.swiftwaterrescue.app

import android.os.Build
import android.os.Bundle
import androidx.core.view.WindowCompat
import io.flutter.embedding.android.FlutterActivity

// Die Systemfunktionen (Standortberechtigung, Akku, Weckton) stehen im Plugin packages/sw_sys, damit sie auch im Vordergrunddienst gehen.
class MainActivity : FlutterActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        // Randlose Anzeige auch vor Android 15 (dort ab SDK 35 Pflicht), Abstände zu den Systemleisten hält Flutter über SafeArea
        WindowCompat.enableEdgeToEdge(window)
        super.onCreate(savedInstanceState)
        // Vollbild-Alarm auch auf dem Sperrbildschirm
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O_MR1) {
            setShowWhenLocked(true)
            setTurnScreenOn(true)
        }
    }
}
