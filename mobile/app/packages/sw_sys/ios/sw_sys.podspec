# CocoaPods-Fallback für Builds ohne Swift Package Manager; Quellen wie im Swift Package
Pod::Spec.new do |s|
  s.name             = 'sw_sys'
  s.version          = '1.0.0'
  s.summary          = 'Systemfunktionen der App Swiftwater Rescue (iOS: Beacons der Fahrzeuge)'
  s.homepage         = 'https://github.com/freakinChuck/SwiftwaterRescueApp'
  s.license          = { :type => 'Proprietary' }
  s.author           = { 'Swiftwater Rescue' => 'noreply@example.invalid' }
  s.source           = { :path => '.' }
  s.source_files     = 'sw_sys/Sources/sw_sys/**/*.swift'
  s.dependency 'Flutter'
  s.platform         = :ios, '15.0'
  s.swift_version    = '5.0'
end
