import CoreBluetooth
import CoreLocation
import Flutter
import UIKit

// iOS-Teil des Kanals ch.swiftwaterrescue/sys: nur die Beacons der Fahrzeuge (Issue #177); die übrigen Systemfunktionen gibt es nur unter Android.
// iOS erkennt iBeacons im Hintergrund nur über CoreLocation, und nur mit bekannter UUID:
// - beaconScan: Ranging für ms Millisekunden auf die UUIDs (im Einsatz, «Beacon einlernen» nur für eingetragene UUIDs).
// - beaconWatch: Region Monitoring je UUID (höchstens 20 Regionen je App). Das System überwacht selbst, fast ohne Akku, und weckt die App beim
//   Betreten, auch wenn sie geschlossen ist; dann höchstens 10 s Ranging, um Major/Minor zu lesen. Die Sichtungen liegen bis zum Abholen
//   (beaconPending) in UserDefaults; die App erhält «beaconEvent» und meldet sie mit dem Standort. Braucht den Standort «Immer».
//   Bei jedem Aufruf fragt es zusätzlich den Zustand der Regionen ab (requestState): Wer schon beim Fahrzeug ist, betritt die Region nicht neu.
// Ohne «Genauer Standort» (reduzierte Genauigkeit) gibt es weder Ranging noch Region Monitoring: Zustand 3, requestBluetooth bittet dann
// um den genauen Standort für diese Nutzung (Schlüssel «beacons» in NSLocationTemporaryUsageDescriptionDictionary).
// Batteriestand (Issue #224): iBeacon-Frames sieht nur CoreLocation, den Frame «Device Info» von DX-Smart (Service-Daten 0xFEAB, Byte 0 = Prozent,
// Bytes 1–6 = MAC) nur CoreBluetooth. beaconScan sucht ihn deshalb parallel und liefert ihn als eigene Einträge {mac, batt, rssi}; die App ordnet
// ihn über die MAC des Fahrzeugs zu. Ohne MAC im Frame (nur 1 Byte) lässt er sich unter iOS keinem Gerät zuordnen und fällt weg.
public class SwSysPlugin: NSObject, FlutterPlugin, CLLocationManagerDelegate, CBCentralManagerDelegate {
    private let lm = CLLocationManager()
    private var channel: FlutterMethodChannel?
    private var scanBest: [String: [String: Any]]? = nil // laufender Scan
    private var scanResult: FlutterResult?
    private var scanCons: [CLBeaconIdentityConstraint] = []
    private var bgCons: [CLBeaconIdentityConstraint] = []
    private var bgTask: UIBackgroundTaskIdentifier = .invalid
    private var cm: CBCentralManager? = nil // nur während eines Scans
    private var battBest: [String: [String: Any]] = [:]
    private static let dxInfo = CBUUID(string: "FEAB")
    private static let pendingKey = "sw_beacons_pending", prefix = "sw-veh-"

    public static func register(with registrar: FlutterPluginRegistrar) {
        let ch = FlutterMethodChannel(name: "ch.swiftwaterrescue/sys", binaryMessenger: registrar.messenger())
        let i = SwSysPlugin()
        i.channel = ch
        registrar.addMethodCallDelegate(i, channel: ch)
    }

    override init() {
        super.init()
        lm.delegate = self
    }

    private func auth() -> CLAuthorizationStatus { lm.authorizationStatus }

    /// 0 = kein Ranging möglich, 1 = keine Standortberechtigung, 2 = bereit, 3 = nur ungefährer Standort (iOS sucht dann keine Beacons)
    private func state() -> Int {
        if !CLLocationManager.isRangingAvailable() { return 0 }
        let a = auth()
        if a != .authorizedAlways && a != .authorizedWhenInUse { return 1 }
        return lm.accuracyAuthorization == .reducedAccuracy ? 3 : 2
    }

    public func handle(_ call: FlutterMethodCall, result: @escaping FlutterResult) {
        let args = call.arguments as? [String: Any] ?? [:]
        let uuids = (args["uuids"] as? [String] ?? []).compactMap { UUID(uuidString: $0) }
        switch call.method {
        case "btState": result(state())
        case "requestBluetooth": // die Standortberechtigung fragt geolocator an; hier nur der genaue Standort
            if state() != 3 { result(state()); return }
            lm.requestTemporaryFullAccuracyAuthorization(withPurposeKey: "beacons") { [weak self] _ in result(self?.state() ?? 0) }
        case "beaconScan":
            if state() != 2 || uuids.isEmpty || scanResult != nil { result([]); return }
            let ms = max(500, min(20000, args["ms"] as? Int ?? 5000))
            scanBest = [:]
            scanResult = result
            scanCons = uuids.map { CLBeaconIdentityConstraint(uuid: $0) }
            scanCons.forEach { lm.startRangingBeacons(satisfying: $0) }
            battBest = [:]
            cm = CBCentralManager(delegate: self, queue: nil, options: [CBCentralManagerOptionShowPowerAlertKey: false])
            DispatchQueue.main.asyncAfter(deadline: .now() + .milliseconds(ms)) { [weak self] in self?.finishScan() }
        case "beaconWatch":
            let want = Set(uuids.map { SwSysPlugin.prefix + $0.uuidString })
            for r in lm.monitoredRegions where r.identifier.hasPrefix(SwSysPlugin.prefix) && !want.contains(r.identifier) { lm.stopMonitoring(for: r) }
            if uuids.isEmpty || auth() != .authorizedAlways || !CLLocationManager.isMonitoringAvailable(for: CLBeaconRegion.self) { result(false); return }
            let have = Set(lm.monitoredRegions.map { $0.identifier })
            for u in uuids {
                if have.contains(SwSysPlugin.prefix + u.uuidString) {
                    // schon überwacht: Zustand abfragen, sonst meldet iOS ein Fahrzeug, bei dem man schon ist, erst nach Verlassen und erneutem Betreten
                    if let r = lm.monitoredRegions.first(where: { $0.identifier == SwSysPlugin.prefix + u.uuidString }) { lm.requestState(for: r) }
                    continue
                }
                let r = CLBeaconRegion(uuid: u, identifier: SwSysPlugin.prefix + u.uuidString)
                r.notifyOnEntry = true
                r.notifyOnExit = false
                r.notifyEntryStateOnDisplay = true // beim Einschalten des Bildschirms in der Region: kurz nachschauen
                lm.startMonitoring(for: r)
            }
            result(true)
        case "beaconPending":
            let d = UserDefaults.standard
            let p = d.dictionary(forKey: SwSysPlugin.pendingKey) as? [String: [String: Any]] ?? [:]
            d.removeObject(forKey: SwSysPlugin.pendingKey)
            result(Array(p.values))
        default: result(FlutterMethodNotImplemented)
        }
    }

    private func finishScan() {
        scanCons.forEach { lm.stopRangingBeacons(satisfying: $0) }
        scanCons = []
        if cm?.state == .poweredOn { cm?.stopScan() }
        cm = nil
        let r = scanResult, b = scanBest
        scanResult = nil
        scanBest = nil
        r?(Array((b ?? [:]).values) + Array(battBest.values))
        battBest = [:]
    }

    public func centralManagerDidUpdateState(_ c: CBCentralManager) {
        if c === cm, c.state == .poweredOn { c.scanForPeripherals(withServices: [SwSysPlugin.dxInfo], options: [CBCentralManagerScanOptionAllowDuplicatesKey: true]) }
    }

    public func centralManager(_ c: CBCentralManager, didDiscover p: CBPeripheral, advertisementData ad: [String: Any], rssi: NSNumber) {
        guard c === cm, let sd = ad[CBAdvertisementDataServiceDataKey] as? [CBUUID: Data], let d = sd[SwSysPlugin.dxInfo], d.count >= 7 else { return }
        let b = [UInt8](d)
        if b[0] > 100 { return }
        let mac = b[1...6].map { String(format: "%02X", $0) }.joined(separator: ":")
        let r = rssi.intValue
        if r == 127 || r == 0 { return }
        if let o = battBest[mac], (o["rssi"] as? Int ?? -127) >= r { return }
        battBest[mac] = ["mac": mac, "batt": Int(b[0]), "rssi": r]
    }

    private static func key(_ b: CLBeacon) -> String { "\(b.uuid.uuidString)|\(b.major)|\(b.minor)" }

    private func store(_ bs: [CLBeacon]) {
        let d = UserDefaults.standard
        var p = d.dictionary(forKey: SwSysPlugin.pendingKey) as? [String: [String: Any]] ?? [:]
        let now = Int(Date().timeIntervalSince1970)
        for b in bs where b.rssi != 0 {
            let k = SwSysPlugin.key(b)
            if let o = p[k], now - (o["at"] as? Int ?? 0) < 60, (o["rssi"] as? Int ?? -127) >= b.rssi { continue }
            p[k] = ["uuid": b.uuid.uuidString, "major": b.major.intValue, "minor": b.minor.intValue, "rssi": b.rssi, "at": now]
        }
        d.set(p, forKey: SwSysPlugin.pendingKey)
    }

    public func locationManager(_ m: CLLocationManager, didRange beacons: [CLBeacon], satisfying c: CLBeaconIdentityConstraint) {
        if scanCons.contains(c), scanBest != nil {
            for b in beacons where b.rssi != 0 {
                let k = SwSysPlugin.key(b)
                if let o = scanBest?[k], (o["rssi"] as? Int ?? -127) >= b.rssi { continue }
                scanBest?[k] = ["uuid": b.uuid.uuidString, "major": b.major.intValue, "minor": b.minor.intValue, "rssi": b.rssi]
            }
        }
        if bgCons.contains(c), !beacons.isEmpty { store(beacons) }
    }

    public func locationManager(_ m: CLLocationManager, didFailRangingFor c: CLBeaconIdentityConstraint, error: Error) {
        NSLog("sw_sys: Ranging fehlgeschlagen für %@: %@", c.uuid.uuidString, error.localizedDescription)
    }

    public func locationManager(_ m: CLLocationManager, monitoringDidFailFor region: CLRegion?, withError error: Error) {
        NSLog("sw_sys: Region Monitoring fehlgeschlagen für %@: %@", region?.identifier ?? "-", error.localizedDescription)
    }

    // Region betreten (auch bei geschlossener App): höchstens 10 s Ranging, dann der App melden
    public func locationManager(_ m: CLLocationManager, didDetermineState s: CLRegionState, for region: CLRegion) {
        guard s == .inside, let r = region as? CLBeaconRegion, r.identifier.hasPrefix(SwSysPlugin.prefix) else { return }
        let c = CLBeaconIdentityConstraint(uuid: r.uuid)
        if bgCons.contains(c) { return }
        bgCons.append(c)
        if bgTask == .invalid { bgTask = UIApplication.shared.beginBackgroundTask { [weak self] in self?.endBg() } }
        lm.startRangingBeacons(satisfying: c)
        DispatchQueue.main.asyncAfter(deadline: .now() + 10) { [weak self] in
            guard let self = self else { return }
            self.lm.stopRangingBeacons(satisfying: c)
            self.bgCons.removeAll { $0 == c }
            self.channel?.invokeMethod("beaconEvent", arguments: nil)
            if self.bgCons.isEmpty { DispatchQueue.main.asyncAfter(deadline: .now() + 15) { self.endBg() } } // Zeit zum Senden
        }
    }

    public func locationManager(_ m: CLLocationManager, didEnterRegion region: CLRegion) {
        locationManager(m, didDetermineState: .inside, for: region)
    }

    private func endBg() {
        if bgTask != .invalid {
            UIApplication.shared.endBackgroundTask(bgTask)
            bgTask = .invalid
        }
    }
}
