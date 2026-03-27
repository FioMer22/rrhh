
package com.rrhh.app.native

import android.app.PendingIntent
import android.appwidget.AppWidgetManager
import android.appwidget.AppWidgetProvider
import android.content.ComponentName
import android.content.Context
import android.content.Intent
import android.util.Log
import android.view.View
import android.widget.RemoteViews
import com.rrhh.app.R
import kotlinx.coroutines.*

class AsistenciaWidget : AppWidgetProvider() {

    override fun onUpdate(
        context: Context,
        appWidgetManager: AppWidgetManager,
        appWidgetIds: IntArray
    ) {
        appWidgetIds.forEach { id ->
            actualizarWidget(context, appWidgetManager, id)
        }
    }

    companion object {

        fun mostrarEstadoTemporal(context: Context, texto: String, colorHex: String = "#ef4444") {
            val manager = AppWidgetManager.getInstance(context)
            val ids = manager.getAppWidgetIds(
                ComponentName(context, AsistenciaWidget::class.java)
            )
            if (ids.isEmpty()) return

            ids.forEach { widgetId ->
                val views = RemoteViews(context.packageName, R.layout.widget_asistencia)

                // Mostrar el mensaje de error en el estado
                views.setTextViewText(R.id.widget_estado, texto)
                views.setInt(
                    R.id.widget_estado,
                    "setTextColor",
                    android.graphics.Color.parseColor(colorHex)
                )

                // Mantener los botones visibles pero no hacer nada por ahora
                manager.updateAppWidget(widgetId, views)
            }

            // Volver al estado normal después de 4 segundos
            android.os.Handler(android.os.Looper.getMainLooper()).postDelayed({
                actualizarTodos(context)
            }, 4_000)
        }
        fun actualizarTodos(context: Context) {
            val manager = AppWidgetManager.getInstance(context)
            val ids = manager.getAppWidgetIds(
                ComponentName(context, AsistenciaWidget::class.java)
            )
            ids.forEach { id -> actualizarWidget(context, manager, id) }
        }

        fun actualizarWidget(
            context: Context,
            manager: AppWidgetManager,
            widgetId: Int
        ) {
            val views = RemoteViews(context.packageName, R.layout.widget_asistencia)

            // PendingIntent ENTRADA
            val intentEntrada = Intent(context, WidgetActionReceiver::class.java).apply {
                action = WidgetActionReceiver.ACTION_ENTRADA
            }
            val piEntrada = PendingIntent.getBroadcast(
                context, 1, intentEntrada,
                PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
            )
            views.setOnClickPendingIntent(R.id.widget_btn_entrada, piEntrada)

            // PendingIntent SALIDA
            val intentSalida = Intent(context, WidgetActionReceiver::class.java).apply {
                action = WidgetActionReceiver.ACTION_SALIDA
            }
            val piSalida = PendingIntent.getBroadcast(
                context, 2, intentSalida,
                PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
            )
            views.setOnClickPendingIntent(R.id.widget_btn_salida, piSalida)

            // Estado inicial
            val token = SessionManager.getToken(context)
            if (token.isNullOrBlank()) {
                views.setTextViewText(R.id.widget_estado, "Iniciá sesión en la app")

                // Oculta botones si no hay sesión
                views.setViewVisibility(R.id.widget_btn_entrada, View.GONE)
                views.setViewVisibility(R.id.widget_btn_salida, View.GONE)

                manager.updateAppWidget(widgetId, views)
                return
            } else {
                views.setTextViewText(R.id.widget_estado, "Sincronizando...")
            }

            CoroutineScope(Dispatchers.IO).launch {

                val estado = ApiMarcar(context).obtenerEstado()

                val canEntrada = estado.canEntrada
                val canSalida  = estado.canSalida
                val tipo_entrada = estado.tipo_entrada

                val minutos = estado.serverTime?.let { time ->
                    val partes = time.split(":")
                    val h = partes.getOrNull(0)?.toIntOrNull() ?: 0
                    val m = partes.getOrNull(1)?.toIntOrNull() ?: 0
                    h * 60 + m
                } ?: run {
                    val ahora = java.util.Calendar.getInstance()
                    ahora.get(java.util.Calendar.HOUR_OF_DAY) * 60 +
                            ahora.get(java.util.Calendar.MINUTE)
                }

                val entradaIni = 7 * 60
                val entradaFin = 8 * 60 + 30
                val salidaIni  = 17 * 60
                val salidaFin  = 18 * 60 + 30

                val mostrarEntrada = minutos in entradaIni..entradaFin && canEntrada
                val mostrarSalida  = minutos in salidaIni..salidaFin && canSalida

                val sinAcciones = !canEntrada && !canSalida
                Log.d("Widget", "tipo_entrada = $tipo_entrada, canEntrada = $canEntrada, canSalida = $canSalida")

                // 👉 VOLVER AL MAIN THREAD
                withContext(Dispatchers.Main) {

                    val views = RemoteViews(context.packageName, R.layout.widget_asistencia)

                    if (tipo_entrada == "pausa_inicio") {
                        views.setTextViewText(R.id.widget_estado, "⚠️ Pausa abierta")
                        views.setInt(
                            R.id.widget_estado,
                            "setTextColor",
                            android.graphics.Color.parseColor("#f59e0b")
                        )
                    } else if (sinAcciones) {
                        views.setTextViewText(R.id.widget_estado, "Sin acciones disponibles")
                    } else {
                        views.setTextViewText(R.id.widget_estado, "Listo para marcar")
                    }

                    views.setViewVisibility(
                        R.id.widget_btn_entrada,
                        if (mostrarEntrada) View.VISIBLE else View.GONE
                    )

                    views.setViewVisibility(
                        R.id.widget_btn_salida,
                        if (mostrarSalida) View.VISIBLE else View.GONE
                    )
                    Log.d("Widget", "tipo_entrada = $tipo_entrada, canEntrada = $canEntrada, canSalida = $canSalida")
                    manager.updateAppWidget(widgetId, views)
                }
            }
        }
    }
}