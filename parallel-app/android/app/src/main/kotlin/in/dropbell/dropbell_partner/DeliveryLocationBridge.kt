package `in`.dropbell.dropbell_partner

import android.Manifest
import android.app.Activity
import android.content.pm.PackageManager
import android.location.Location
import android.location.LocationListener
import android.location.LocationManager
import android.os.Build
import android.os.Bundle
import android.os.CancellationSignal
import android.os.Handler
import android.os.Looper
import android.os.SystemClock
import io.flutter.plugin.common.BinaryMessenger
import io.flutter.plugin.common.MethodChannel

class DeliveryLocationBridge(private val activity: Activity, messenger: BinaryMessenger) {
    private val manager = activity.getSystemService(LocationManager::class.java)
    private val handler = Handler(Looper.getMainLooper())
    private var pending: MethodChannel.Result? = null
    private var cancellation: CancellationSignal? = null
    private var listener: LocationListener? = null
    private val timeout = Runnable { finish(null, "Location timed out. Check GPS and try again.") }
    init {
        MethodChannel(messenger, "dropbell/location").setMethodCallHandler { call, result ->
            if (call.method != "currentLocation") { result.notImplemented() }
            else if (pending != null) { result.error("location_busy", "A location request is already running.", null) }
            else {
                pending = result
                handler.postDelayed(timeout, 22000)
                if (activity.checkSelfPermission(Manifest.permission.ACCESS_FINE_LOCATION) != PackageManager.PERMISSION_GRANTED && activity.checkSelfPermission(Manifest.permission.ACCESS_COARSE_LOCATION) != PackageManager.PERMISSION_GRANTED) {
                    activity.requestPermissions(arrayOf(Manifest.permission.ACCESS_FINE_LOCATION, Manifest.permission.ACCESS_COARSE_LOCATION), 731)
                } else { locate() }
            }
        }
    }
    fun permissionsResult(requestCode: Int) { if (requestCode == 731 && pending != null) locate() }
    private fun locate() {
        try {
            if (activity.checkSelfPermission(Manifest.permission.ACCESS_FINE_LOCATION) != PackageManager.PERMISSION_GRANTED && activity.checkSelfPermission(Manifest.permission.ACCESS_COARSE_LOCATION) != PackageManager.PERMISSION_GRANTED) { finish(null, "Allow location access to share this delivery's position."); return }
            val fine = activity.checkSelfPermission(Manifest.permission.ACCESS_FINE_LOCATION) == PackageManager.PERMISSION_GRANTED
            val provider = if (fine && manager.isProviderEnabled(LocationManager.GPS_PROVIDER)) LocationManager.GPS_PROVIDER else if (manager.isProviderEnabled(LocationManager.NETWORK_PROVIDER)) LocationManager.NETWORK_PROVIDER else null
            if (provider == null) { finish(null, "Turn on location services and try again."); return }
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.R) {
                cancellation = CancellationSignal()
                manager.getCurrentLocation(provider, cancellation, activity.mainExecutor) { location -> finish(location, "Could not get a fresh location. Try again outdoors.") }
            } else {
                val callback = object : LocationListener {
                    override fun onLocationChanged(location: Location) { finish(location, "Could not get a fresh location.") }
                    override fun onProviderDisabled(provider: String) { finish(null, "Location services are disabled.") }
                    override fun onProviderEnabled(provider: String) {}
                    @Deprecated("Platform callback") override fun onStatusChanged(provider: String?, status: Int, extras: Bundle?) {}
                }
                listener = callback
                @Suppress("DEPRECATION")
                manager.requestSingleUpdate(provider, callback, Looper.getMainLooper())
            }
        } catch (_: Exception) { finish(null, "Location is unavailable. Check permission and GPS settings.") }
    }
    private fun finish(location: Location?, message: String) {
        val result = pending ?: return
        pending = null
        handler.removeCallbacks(timeout)
        cancellation?.cancel(); cancellation = null
        listener?.let { manager.removeUpdates(it) }; listener = null
        if (location == null || !location.hasAccuracy() || location.accuracy > 200 || SystemClock.elapsedRealtimeNanos() - location.elapsedRealtimeNanos > 60000000000L) result.error("location_unavailable", message, null)
        else result.success(mapOf("latitude" to location.latitude, "longitude" to location.longitude, "accuracy_meters" to location.accuracy))
    }
    fun close() { finish(null, "Delivery screen closed.") }
}
