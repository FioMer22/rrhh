package com.rrhh.app.native

import android.content.Context
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import java.io.OutputStreamWriter
import java.net.HttpURLConnection
import java.net.URL

class ApiActividades(private val context: Context) {

    suspend fun estaEnProgreso(actividadId: Int): Boolean = withContext(Dispatchers.IO) {
        val token = SessionManager.getToken(context) ?: return@withContext false
        try {
            val url  = URL("${AppConfig.BASE_URL}/api/actividad_estado.php")
            val conn = url.openConnection() as HttpURLConnection
            conn.requestMethod = "POST"
            conn.doOutput     = true
            conn.connectTimeout = 8_000
            conn.readTimeout    = 8_000
            conn.setRequestProperty("Content-Type", "application/x-www-form-urlencoded")
            OutputStreamWriter(conn.outputStream).use {
                it.write("token=${java.net.URLEncoder.encode(token, "UTF-8")}&actividad_id=$actividadId")
            }
            val response = conn.inputStream.bufferedReader().readText()
            conn.disconnect()
            response.contains("\"en_progreso\":true")
        } catch (e: Exception) {
            true // conservador: si no puede verificar, sigue notificando
        }
    }
}