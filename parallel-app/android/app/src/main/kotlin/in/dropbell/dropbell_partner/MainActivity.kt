package `in`.dropbell.dropbell_partner

import android.app.NotificationChannel
import android.app.NotificationManager
import android.media.AudioAttributes
import android.media.MediaPlayer
import android.net.Uri
import android.os.Build
import io.flutter.embedding.android.FlutterActivity
import io.flutter.embedding.engine.FlutterEngine
import io.flutter.plugin.common.MethodChannel

class MainActivity : FlutterActivity() {
    private var alertPlayer: MediaPlayer? = null
    private var locationBridge: DeliveryLocationBridge? = null
    private val alertAttributes = AudioAttributes.Builder()
        .setUsage(AudioAttributes.USAGE_NOTIFICATION)
        .setContentType(AudioAttributes.CONTENT_TYPE_SONIFICATION)
        .build()

    override fun configureFlutterEngine(flutterEngine: FlutterEngine) {
        super.configureFlutterEngine(flutterEngine)
        locationBridge = DeliveryLocationBridge(this, flutterEngine.dartExecutor.binaryMessenger)
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            val channel = NotificationChannel("dropbell_assignments_v1", "New delivery assignments", NotificationManager.IMPORTANCE_HIGH)
            channel.description = "Ringtone when a delivery is assigned to you"
            channel.enableVibration(true)
            channel.setSound(Uri.parse("android.resource://$packageName/raw/partner_assignment"), alertAttributes)
            getSystemService(NotificationManager::class.java).createNotificationChannel(channel)
        }
        MethodChannel(flutterEngine.dartExecutor.binaryMessenger, "dropbell/order_alerts").setMethodCallHandler { call, result ->
            if (call.method != "playAssignment") {
                result.notImplemented()
            } else {
                try {
                    alertPlayer?.release()
                    val player = MediaPlayer()
                    alertPlayer = player
                    player.setAudioAttributes(alertAttributes)
                    resources.openRawResourceFd(R.raw.partner_assignment).use { file ->
                        player.setDataSource(file.fileDescriptor, file.startOffset, file.length)
                    }
                    player.setOnCompletionListener { completed ->
                        completed.release()
                        if (alertPlayer === completed) alertPlayer = null
                    }
                    player.setOnErrorListener { failed, _, _ ->
                        failed.release()
                        if (alertPlayer === failed) alertPlayer = null
                        true
                    }
                    player.prepare()
                    player.start()
                    result.success(null)
                } catch (_: Exception) {
                    alertPlayer?.release()
                    alertPlayer = null
                    result.error("audio_unavailable", "Could not play assignment sound", null)
                }
            }
        }
    }

    override fun onRequestPermissionsResult(requestCode: Int, permissions: Array<out String>, grantResults: IntArray) {
        super.onRequestPermissionsResult(requestCode, permissions, grantResults)
        locationBridge?.permissionsResult(requestCode)
    }

    override fun onDestroy() {
        locationBridge?.close()
        alertPlayer?.release()
        alertPlayer = null
        super.onDestroy()
    }
}
