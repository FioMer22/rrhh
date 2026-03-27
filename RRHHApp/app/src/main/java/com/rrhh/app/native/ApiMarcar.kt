package com.rrhh.app.native

import android.content.Context
import android.util.Log
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import java.io.OutputStreamWriter
import java.net.HttpURLConnection
import java.net.URL

data class MarcarResult(
    val ok: Boolean,
    val mensaje: String
)
data class EstadoResult(
    val canEntrada: Boolean,
    val canSalida: Boolean,
    val serverTime: String?,
    val tipo_entrada: String?
)

class ApiMarcar(private val context: Context) {

    // Cambiá por tu URL de producción cuando corresponda
    //private val BASE_URL = "http://192.168.1.81/rrhh-j/public/api/marcar_widget.php"
    private val BASE_URL  = AppConfig.API_MARCAR
    suspend fun marcar(
        tipo: String,
        lat: Double?,
        lng: Double?,
        accuracyM: Int?,
        locationStatus: String
    ): MarcarResult = withContext(Dispatchers.IO) {

        val token = SessionManager.getToken(context)
        if (token.isNullOrBlank()) {
            return@withContext MarcarResult(false, "No hay sesión activa. Abrí la app primero.")
        }

        try {
            val params = buildString {
                append("token=${encode(token)}")
                append("&tipo=${encode(tipo)}")
                append("&location_status=${encode(locationStatus)}")
                if (lat != null) append("&lat=$lat")
                if (lng != null) append("&lng=$lng")
                if (accuracyM != null) append("&accuracy_m=$accuracyM")
            }

            val url = URL(BASE_URL)
            val conn = url.openConnection() as HttpURLConnection
            conn.requestMethod = "POST"
            conn.doOutput = true
            conn.connectTimeout = 15_000
            conn.readTimeout    = 15_000
            conn.setRequestProperty("Content-Type", "application/x-www-form-urlencoded")

            OutputStreamWriter(conn.outputStream).use { it.write(params) }

            val code     = conn.responseCode
            val response = conn.inputStream.bufferedReader().readText()
            conn.disconnect()

            if (code != 200) {
                return@withContext MarcarResult(false, "Error del servidor ($code)")
            }

            // Parseo JSON manual simple — evita dependencia de Gson para esto
            val ok      = response.contains("\"ok\":true")
            val mensaje = Regex("\"mensaje\":\"([^\"]+)\"")
                .find(response)?.groupValues?.get(1)
                ?: if (ok) "Registrado" else "Error al registrar"

            MarcarResult(ok, mensaje)

        } catch (e: Exception) {
            MarcarResult(false, "Sin conexión: ${e.message}. Verificá internet o servidor")
        }
    }

    suspend fun obtenerEstado(): EstadoResult = withContext(Dispatchers.IO) {
        val token = SessionManager.getToken(context)
            ?: return@withContext EstadoResult(false, false, null, tipo_entrada=null)

        try {
            val url = URL(AppConfig.API_ESTADO)
            val conn = url.openConnection() as HttpURLConnection
            conn.requestMethod = "POST"
            conn.doOutput = true
            conn.connectTimeout = 10_000
            conn.readTimeout    = 10_000
            conn.setRequestProperty("Content-Type", "application/x-www-form-urlencoded")

            OutputStreamWriter(conn.outputStream).use {
                it.write("token=${encode(token)}")
            }

            val response = conn.inputStream.bufferedReader().readText()
            conn.disconnect()

            android.util.Log.d("API_ESTADO", response)

            val canEntrada = response.contains("\"canEntrada\":true")
            val canSalida  = response.contains("\"canSalida\":true")

            val serverTime = Regex("\"serverTime\":\"([^\"]+)\"")
                .find(response)?.groupValues?.get(1)

            val tipo_entrada = Regex("\"tipo_entrada\":\"([^\"]+)\"")
                .find(response)?.groupValues?.get(1)

            EstadoResult(canEntrada, canSalida, serverTime, tipo_entrada)


        } catch (e: Exception) {
            EstadoResult(false, false, null, null)
        }
    }

    private fun encode(s: String) =
        java.net.URLEncoder.encode(s, "UTF-8")
}