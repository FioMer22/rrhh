package com.rrhh.app.native

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.util.Log
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.TimeoutCancellationException
import kotlinx.coroutines.launch
import kotlinx.coroutines.suspendCancellableCoroutine
import kotlinx.coroutines.withTimeout
import kotlin.coroutines.resume
import com.rrhh.native.LocationHelper

class WidgetActionReceiver : BroadcastReceiver() {

    companion object {
        const val ACTION_ENTRADA = "com.rrhh.app.WIDGET_ENTRADA"
        const val ACTION_SALIDA  = "com.rrhh.app.WIDGET_SALIDA"
    }

    override fun onReceive(context: Context, intent: Intent) {
        val tipo = when (intent.action) {
            ACTION_ENTRADA -> "inicio_jornada"
            ACTION_SALIDA  -> "fin_jornada"
            else -> return
        }
        Log.d("WidgetReceiver", "onReceive action=${intent.action}, tipo=$tipo")
        val label       = if (tipo == "inicio_jornada") "Entrada" else "Salida"
        val notifHelper = NotificationHelper(context)

        notifHelper.mostrar("Registrando $label…", "Obteniendo ubicación GPS…")

        val pendingResult = goAsync()

        CoroutineScope(Dispatchers.IO).launch {
            try {
                // 1. GPS con timeout explícito
                val gps: LocationHelper.GpsResult = try {
                    withTimeout(10_000L) {
                        suspendCancellableCoroutine { cont: kotlinx.coroutines.CancellableContinuation<LocationHelper.GpsResult> ->
                            LocationHelper(context).getLocation(timeoutSeconds = 8) { result ->
                                if (cont.isActive) cont.resume(result)
                            }
                        }
                    }
                } catch (e: TimeoutCancellationException) {
                    LocationHelper.GpsResult(null, null, null, "error", "Timeout GPS")
                }
                // Bloque geofence — reemplazar el existente
                if (gps.status == "ok") {
                    val distancia = LocationHelper.distanciaEmpresaMetros(gps.lat, gps.lng)
                    val fueraDeRango = distancia == null || distancia > AppConfig.GEOFENCE_RADIO_M

                    if (fueraDeRango) {
                        val distTexto = if (distancia != null) " (${distancia.toInt()}m)" else ""

                        // Notificación del sistema
                        notifHelper.mostrar(
                            "📍 Fuera de rango$distTexto",
                            "Acercate a la empresa para marcar. Radio: ${AppConfig.GEOFENCE_RADIO_M.toInt()}m"
                        )

                        // Feedback visual en el widget durante 4 segundos
                        AsistenciaWidget.mostrarEstadoTemporal(
                            context,
                            "📍 Fuera de rango$distTexto",
                            "#ef4444"
                        )

                        return@launch  // sin pendingResult.finish() porque está en el finally
                    }
                }
                Log.d("WidgetReceiver", "GPS obtenido: $gps")
                // 2. Llamar al PHP
                val resultado = ApiMarcar(context).marcar(
                    tipo           = tipo,
                    lat            = gps.lat,
                    lng            = gps.lng,
                    accuracyM      = gps.accuracyMeters,
                    locationStatus = gps.status
                )
                Log.d("WidgetReceiver", "Resultado API: $resultado")
                // 3. Notificación con resultado
                if (resultado.ok) {
                    notifHelper.mostrar(
                        "✅ $label registrada",
                        resultado.mensaje +
                                if (gps.status == "ok") " · GPS ±${gps.accuracyMeters}m" else ""
                    )
                } else {
                    notifHelper.mostrar(
                        "❌ Error al registrar $label",
                        resultado.mensaje
                    )
                }

                // 4. Actualizar widget
                AsistenciaWidget.actualizarTodos(context)

            } finally {
                pendingResult.finish()
            }
        }
    }
}