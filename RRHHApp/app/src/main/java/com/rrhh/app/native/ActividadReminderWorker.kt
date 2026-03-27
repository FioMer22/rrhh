package com.rrhh.app.native

import android.content.Context
import androidx.work.CoroutineWorker
import androidx.work.WorkerParameters

class ActividadReminderWorker(
    private val context: Context,
    params: WorkerParameters
) : CoroutineWorker(context, params) {

    override suspend fun doWork(): Result {
        val titulo    = inputData.getString("titulo")    ?: "una actividad"
        val actividadId = inputData.getInt("actividad_id", 0)

        // Verificar si la actividad sigue en progreso antes de notificar
        val token = SessionManager.getToken(context)
        if (token.isNullOrBlank()) return Result.success() // sin sesión, no notificar

        val sigueAbierta = try {
            ApiActividades(context).estaEnProgreso(actividadId)
        } catch (e: Exception) {
            true // si falla la red, notificar igual por las dudas
        }

        if (sigueAbierta) {
            NotificationHelper(context).mostrar(
                titulo    = "⏳ Actividad en curso: $titulo",
                mensaje   = "Tenés una actividad abierta. Si ya la terminaste, tocá para finalizarla.",
                url       = "/rrhh-j/public/actividades/index.php"
            )
        }
        // Si ya no está en progreso, el Worker fue cancelado o terminó naturalmente
        return Result.success()
    }
}