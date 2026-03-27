package com.rrhh.native

import android.Manifest
import android.content.Context
import android.content.pm.PackageManager
import android.location.Location
import android.os.Looper
import androidx.core.app.ActivityCompat
import com.google.android.gms.location.*
import com.rrhh.app.native.AppConfig

class LocationHelper(private val context: Context) {

    data class GpsResult(
        val lat: Double?,
        val lng: Double?,
        val accuracyMeters: Int?,
        val status: String,   // "ok" | "denied" | "unavailable" | "error"
        val note: String?
    )

    private val fusedClient = LocationServices.getFusedLocationProviderClient(context)

    fun getLocation(
        timeoutSeconds: Int = 10,
        onResult: (GpsResult) -> Unit
    ) {
        if (!hasPermission()) {
            onResult(GpsResult(null, null, null, "denied", "Permiso de ubicación no otorgado"))
            return
        }

        try {
            fusedClient.lastLocation
                .addOnSuccessListener { lastLoc ->
                    val ageMs = if (lastLoc != null)
                        System.currentTimeMillis() - lastLoc.time
                    else Long.MAX_VALUE

                    if (lastLoc != null && ageMs < 30_000) {
                        onResult(buildResult(lastLoc))
                    } else {
                        requestFreshLocation(timeoutSeconds, onResult)
                    }
                }
                .addOnFailureListener {
                    requestFreshLocation(timeoutSeconds, onResult)
                }

        } catch (e: SecurityException) {
            onResult(GpsResult(null, null, null, "denied", "Permiso requerido"))
        }
    }

    private fun requestFreshLocation(
        timeoutSeconds: Int,
        onResult: (GpsResult) -> Unit
    ) {
        try {
            fusedClient.getCurrentLocation(
                Priority.PRIORITY_HIGH_ACCURACY,
                null
            ).addOnSuccessListener { loc ->
                if (loc != null) {
                    onResult(buildResult(loc))
                } else {
                    onResult(
                        GpsResult(null, null, null, "unavailable", "Sin ubicación actual")
                    )
                }
            }.addOnFailureListener {
                onResult(
                    GpsResult(null, null, null, "error", "Error obteniendo ubicación")
                )
            }
        } catch (e: SecurityException) {
            onResult(GpsResult(null, null, null, "denied", "Permiso requerido"))
        }
    }

    companion object {
        /**
         * Retorna la distancia en metros entre el GPS del usuario y la empresa.
         * Retorna null si las coordenadas son nulas.
         */
        fun distanciaEmpresaMetros(lat: Double?, lng: Double?): Float? {
            if (lat == null || lng == null) return null
            val results = FloatArray(1)
            Location.distanceBetween(
                lat, lng,
                AppConfig.EMPRESA_LAT,
                AppConfig.EMPRESA_LNG,
                results
            )
            return results[0]
        }

        fun dentroDelGeofence(lat: Double?, lng: Double?): Boolean {
            val dist = distanciaEmpresaMetros(lat, lng) ?: return false
            return dist <= AppConfig.GEOFENCE_RADIO_M
        }
    }
    private fun buildResult(loc: Location) = GpsResult(
        lat = loc.latitude,
        lng = loc.longitude,
        accuracyMeters = if (loc.hasAccuracy()) loc.accuracy.toInt() else null,
        status = "ok",
        note = null
    )

    private fun hasPermission(): Boolean {
        return ActivityCompat.checkSelfPermission(
            context,
            Manifest.permission.ACCESS_FINE_LOCATION
        ) == PackageManager.PERMISSION_GRANTED
    }
}