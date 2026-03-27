package com.rrhh.native

import android.content.Context
import android.webkit.JavascriptInterface
import android.webkit.WebView
import com.rrhh.app.native.ActividadWorkManager
import com.rrhh.app.native.AsistenciaWidget
import com.rrhh.app.native.NotificationHelper
import com.rrhh.app.native.SessionManager

/**
 * Puente entre el JavaScript del sistema PHP (marcar.php) y el GPS nativo.
 *
 * El PHP llama: window.Android.solicitarGps()
 * Kotlin responde inyectando: window.rrhhNative.onGpsResult(jsonString)
 */
class JsBridge(
    private val context: Context,
    private val webView: WebView
) {
    private val locationHelper = LocationHelper(context)
    private val notificationHelper = NotificationHelper(context)

    /**
     * Llamado desde el JS de marcar.php cuando el usuario pulsa un botón de marcación.
     * Kotlin obtiene el GPS y devuelve los datos al JS mediante callback.
     */
    @JavascriptInterface
    fun solicitarGps() {
        //sacar en produccion
        android.util.Log.d("RRHHBridge", "solicitarGps() llamado desde JS")
        //
        locationHelper.getLocation(timeoutSeconds = 12) { result ->
            // sacar en produccion
            android.util.Log.d("RRHHBridge", "GPS result: ${result.status} lat=${result.lat} lng=${result.lng}")
            //
            // Construir JSON seguro (escapar posibles caracteres problemáticos)
            val distancia = LocationHelper.distanciaEmpresaMetros(result.lat, result.lng)
            val dentroGeofence = LocationHelper.dentroDelGeofence(result.lat, result.lng)
            val json = buildString {
                append("{")
                append("\"status\":\"${result.status}\",")
                append("\"lat\":${result.lat ?: "null"},")
                append("\"lng\":${result.lng ?: "null"},")
                append("\"accuracy_m\":${result.accuracyMeters ?: "null"},")
                append("\"distancia_m\":${distancia?.toInt() ?: "null"},")
                append("\"dentro_geofence\":$dentroGeofence,")
                val noteEscaped = result.note
                    ?.replace("\\", "\\\\")
                    ?.replace("\"", "\\\"")
                    ?.replace("\n", "\\n")
                    ?: ""
                append("\"note\":\"$noteEscaped\"")
                append("}")
            }

            // Devolver al hilo principal (UI thread) para evaluar JS
            webView.post {
                webView.evaluateJavascript(
                    "if(window.rrhhNative && window.rrhhNative.onGpsResult) { window.rrhhNative.onGpsResult($json); }",
                    null
                )
            }
        }
    }

    /**
     * Permite al PHP consultar si está corriendo en la app nativa.
     * Uso: if (window.Android && window.Android.esAppNativa()) { ... }
     */
    @JavascriptInterface
    fun esAppNativa(): Boolean = true

    @JavascriptInterface
    fun mostrarNotificacion(titulo: String, mensaje: String, url: String) {
        android.util.Log.d("RRHHNotif", "mostrarNotificacion() llamado: $titulo")
        // Se ejecuta en hilo secundario (JavascriptInterface), no necesita post()
        notificationHelper.mostrar(titulo, mensaje, url)
    }

    @JavascriptInterface
    fun guardarSesion(token: String, uid: Int) {
        android.util.Log.d("RRHHBridge", "Sesión guardada uid=$uid")
        SessionManager.guardarToken(context, token, uid)
        AsistenciaWidget.actualizarTodos(context)
    }

    @JavascriptInterface
    fun cerrarSesion() {
        android.util.Log.d("RRHHBridge", "Sesión cerrada desde PHP")
        SessionManager.limpiar(context)
        ActividadWorkManager.cancelarTodos(context)
        // Actualizar el widget inmediatamente para mostrar "Iniciá sesión"
        AsistenciaWidget.actualizarTodos(context)
    }

    @JavascriptInterface
    fun actividadIniciada(actividadId: Int, titulo: String) {
        android.util.Log.d("RRHHBridge", "Actividad iniciada: id=$actividadId titulo=$titulo")
        ActividadWorkManager.programar(context, actividadId, titulo)
    }

    @JavascriptInterface
    fun actividadFinalizada(actividadId: Int) {
        android.util.Log.d("RRHHBridge", "Actividad finalizada: id=$actividadId")
        ActividadWorkManager.cancelar(context, actividadId)
    }


}