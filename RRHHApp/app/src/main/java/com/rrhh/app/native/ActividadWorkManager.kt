package com.rrhh.app.native

import android.content.Context
import androidx.work.*
import java.util.concurrent.TimeUnit

object ActividadWorkManager {

    // Cada actividad tiene su propio tag único para poder cancelarla
    private fun tag(actividadId: Int) = "actividad_reminder_$actividadId"

    fun programar(context: Context, actividadId: Int, titulo: String) {
        val data = workDataOf(
            "actividad_id" to actividadId,
            "titulo"       to titulo
        )

        val request = PeriodicWorkRequestBuilder<ActividadReminderWorker>(
            20, TimeUnit.MINUTES
        )
            .setInputData(data)
            .setInitialDelay(20, TimeUnit.MINUTES) // primera notif a los 30min
            .addTag(tag(actividadId))
            .setConstraints(
                Constraints.Builder()
                    .setRequiredNetworkType(NetworkType.CONNECTED)
                    .build()
            )
            .build()

        WorkManager.getInstance(context).enqueueUniquePeriodicWork(
            tag(actividadId),
            ExistingPeriodicWorkPolicy.KEEP, // si ya existe, no reiniciar
            request
        )

        android.util.Log.d("ActividadWork", "Recordatorio programado para actividad $actividadId")
    }

    fun cancelar(context: Context, actividadId: Int) {
        WorkManager.getInstance(context).cancelAllWorkByTag(tag(actividadId))
        android.util.Log.d("ActividadWork", "Recordatorio cancelado para actividad $actividadId")
    }

    fun cancelarTodos(context: Context) {
        // Por si el usuario cierra sesión — cancela todos los recordatorios activos
        WorkManager.getInstance(context).cancelAllWork()
    }
}