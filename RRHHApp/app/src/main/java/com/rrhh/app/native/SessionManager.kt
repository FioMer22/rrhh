package com.rrhh.app.native

import android.content.Context

/**
 * Guarda y recupera el token de autenticación del widget.
 * Se graba cuando el usuario hace login en el WebView,
 * y se usa desde el widget para llamar al PHP sin sesión.
 */
object SessionManager {

    private const val PREFS_NAME = "rrhh_session"
    private const val KEY_TOKEN  = "widget_token"
    private const val KEY_UID    = "uid"

    fun guardarToken(context: Context, token: String, uid: Int) {
        context.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
            .edit()
            .putString(KEY_TOKEN, token)
            .putInt(KEY_UID, uid)
            .apply()
    }

    fun getToken(context: Context): String? {
        val token = context.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
            .getString(KEY_TOKEN, null)

        android.util.Log.d("SessionManager", "Token obtenido: $token")

        return token
    }


    fun getUid(context: Context): Int =
        context.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
            .getInt(KEY_UID, 0)

    fun limpiar(context: Context) {
        context.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
            .edit().clear().apply()
    }
}