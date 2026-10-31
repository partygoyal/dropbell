import AVFoundation
import Flutter
import UIKit

@main
@objc class AppDelegate: FlutterAppDelegate, FlutterImplicitEngineDelegate {
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
