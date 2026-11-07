package ch.swiftwaterrescue.app

import android.os.Build
import android.os.Bundle
import io.flutter.embedding.android.FlutterActivity

// Die Systemfunktionen (Standortberechtigung, Akku, Weckton) stehen im Plugin packages/sw_sys, damit sie auch im Vordergrunddienst gehen.
class MainActivity : FlutterActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        // Vollbild-Alarm auch auf dem Sperrbildschirm
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O_MR1) {
            setShowWhenLocked(true)
            setTurnScreenOn(true)
        }
    }
}
