package com.rrhh.app.native

object AppConfig {
    // Desarrollo: IP local de tu PC en la red
    // Producción: cambiar por tu dominio real
    const val BASE_URL = "http://192.168.1.81/rrhh-j/public"

    const val API_MARCAR  = "$BASE_URL/api/marcar_widget.php"
    const val API_ESTADO  = "$BASE_URL/api/estado_widget.php"

    // Coordenadas de la empresa
    const val EMPRESA_LAT = -25.370632 // ← tus coordenadas reales
    const val EMPRESA_LNG = -57.560991
    const val GEOFENCE_RADIO_M = 550f

}