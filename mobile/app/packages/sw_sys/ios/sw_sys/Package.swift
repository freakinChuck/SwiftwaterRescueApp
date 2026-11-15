// swift-tools-version: 5.9
// iOS-Teil des Plugins sw_sys (Swift Package Manager, wie die übrigen Plugins der App)
import PackageDescription

let package = Package(
    name: "sw_sys",
    platforms: [.iOS("15.0")],
    products: [.library(name: "sw-sys", targets: ["sw_sys"])],
    dependencies: [],
    targets: [.target(name: "sw_sys", dependencies: [])]
)
