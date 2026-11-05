import AVFoundation
import CoreLocation
import Flutter
import UIKit

@main
@objc class AppDelegate: FlutterAppDelegate, FlutterImplicitEngineDelegate {
  private var locationBridge: DeliveryLocationBridge?
  private var alertPlayer: AVAudioPlayer?
  private var alertChannel: FlutterMethodChannel?

  override func application(
    _ application: UIApplication,
    didFinishLaunchingWithOptions launchOptions: [UIApplication.LaunchOptionsKey: Any]?
  ) -> Bool {
    return super.application(application, didFinishLaunchingWithOptions: launchOptions)
  }

  func didInitializeImplicitFlutterEngine(_ engineBridge: FlutterImplicitEngineBridge) {
    GeneratedPluginRegistrant.register(with: engineBridge.pluginRegistry)
    locationBridge = DeliveryLocationBridge(messenger: engineBridge.applicationRegistrar.messenger())
    alertChannel = FlutterMethodChannel(
      name: "dropbell/order_alerts",
      binaryMessenger: engineBridge.applicationRegistrar.messenger()
    )
    alertChannel?.setMethodCallHandler { [weak self] call, result in
      guard call.method == "playAssignment" else {
        result(FlutterMethodNotImplemented)
        return
      }
      do {
        guard let url = Bundle.main.url(forResource: "partner_assignment", withExtension: "wav") else {
          result(FlutterError(code: "audio_missing", message: "Assignment sound missing", details: nil))
          return
        }
        try AVAudioSession.sharedInstance().setCategory(.ambient)
        self?.alertPlayer?.stop()
        self?.alertPlayer = try AVAudioPlayer(contentsOf: url)
        self?.alertPlayer?.play()
        result(nil)
      } catch {
        result(FlutterError(code: "audio_unavailable", message: "Could not play assignment sound", details: nil))
      }
    }
  }
}


private final class DeliveryLocationBridge: NSObject, CLLocationManagerDelegate {
  private let manager = CLLocationManager()
  private var pending: FlutterResult?
  private var channel: FlutterMethodChannel?
  private var generation = 0
  init(messenger: FlutterBinaryMessenger) {
    super.init()
    manager.delegate = self
    manager.desiredAccuracy = kCLLocationAccuracyBest
    channel = FlutterMethodChannel(name: "dropbell/location", binaryMessenger: messenger)
    channel?.setMethodCallHandler { [weak self] call, result in
      guard call.method == "currentLocation" else { result(FlutterMethodNotImplemented); return }
      guard let self = self else { return }
      guard self.pending == nil else { result(FlutterError(code: "location_busy", message: "A location request is running.", details: nil)); return }
      self.pending = result
      self.generation += 1
      let generation = self.generation
      DispatchQueue.main.asyncAfter(deadline: .now() + 22) { [weak self] in
        guard let self = self, self.generation == generation else { return }
        self.finish(nil, "Location timed out. Check permission and GPS.")
      }
      if self.authorization == .notDetermined { self.manager.requestWhenInUseAuthorization() }
      else { self.locate() }
    }
  }
  private var authorization: CLAuthorizationStatus {
    if #available(iOS 14.0, *) { return manager.authorizationStatus }
    return CLLocationManager.authorizationStatus()
  }
  private func locate() {
    guard pending != nil else { return }
    guard authorization == .authorizedWhenInUse || authorization == .authorizedAlways else { finish(nil, "Allow location access to share this delivery's position."); return }
    manager.requestLocation()
  }
  func locationManagerDidChangeAuthorization(_ manager: CLLocationManager) { locate() }
  func locationManager(_ manager: CLLocationManager, didUpdateLocations locations: [CLLocation]) {
    guard let location = locations.last, location.horizontalAccuracy >= 0, location.horizontalAccuracy <= 200, abs(location.timestamp.timeIntervalSinceNow) < 60 else { finish(nil, "Could not obtain an accurate current location."); return }
    finish(["latitude": location.coordinate.latitude, "longitude": location.coordinate.longitude, "accuracy_meters": location.horizontalAccuracy], nil)
  }
  func locationManager(_ manager: CLLocationManager, didFailWithError error: Error) { finish(nil, "Location is unavailable. Check GPS and try again.") }
  private func finish(_ value: [String: Any]?, _ message: String?) {
    guard let result = pending else { return }
    pending = nil
    generation += 1
    manager.stopUpdatingLocation()
    if let value = value { result(value) } else { result(FlutterError(code: "location_unavailable", message: message, details: nil)) }
  }
}
