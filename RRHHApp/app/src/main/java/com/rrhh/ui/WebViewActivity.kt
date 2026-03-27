package com.rrhh.ui

import android.Manifest
import android.content.pm.PackageManager
import android.os.Build
import android.os.Bundle
import android.webkit.WebSettings
import android.webkit.WebView
import android.webkit.WebViewClient
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AppCompatActivity
import androidx.core.content.ContextCompat
import com.rrhh.app.R
import com.rrhh.native.JsBridge
import androidx.activity.addCallback

class WebViewActivity : AppCompatActivity() {

    // URL base de tu sistema — igual que base_url en app.php
    private val PHP_BASE_URL = "http://192.168.1.81/rrhh-j/public"

    private lateinit var webView: WebView

    // Launcher para solicitar permiso de ubicación
    private val requestPermissionLauncher =
        registerForActivityResult(ActivityResultContracts.RequestMultiplePermissions()) { perms ->
            // El usuario respondió — la próxima vez que pulse un botón,
            // LocationHelper ya sabrá si tiene permiso o no
            // No recargamos la página, el bridge devolverá "denied" si no tiene permiso
        }

    private val requestNotifLauncher =
        registerForActivityResult(ActivityResultContracts.RequestPermission()) { }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_webview)

        webView = findViewById(R.id.webView)
        configurarWebView()
        pedirPermisoUbicacionSiNecesario()
        pedirPermisoNotificaciones()          // ← línea nueva

        onBackPressedDispatcher.addCallback(this) {
            if (webView.canGoBack()) {
                webView.goBack()
            } else {
                finish()
            }
        }
        val urlDestino = intent.getStringExtra("url_destino")
        val urlInicial = if (!urlDestino.isNullOrBlank()) urlDestino
        else "$PHP_BASE_URL/dashboard.php"
        webView.loadUrl(urlInicial)

    }

    private fun configurarWebView() {
        webView.settings.apply {
            javaScriptEnabled = true
            domStorageEnabled = true
            // Necesario para que las cookies de sesión PHP funcionen
            // (la sesión de login ya debe estar establecida)
            databaseEnabled = true
            // Permitir mixed content solo si tu servidor es HTTP (dev)
            // En producción con HTTPS esto no es necesario
            mixedContentMode = WebSettings.MIXED_CONTENT_COMPATIBILITY_MODE
        }

        val cookieManager = android.webkit.CookieManager.getInstance()
        cookieManager.setAcceptCookie(true)
        cookieManager.setAcceptThirdPartyCookies(webView, true)

        // Registrar el bridge con el nombre "Android"
        // El JS del PHP usará window.Android.solicitarGps()
        webView.addJavascriptInterface(
            JsBridge(this, webView),
            "Android"   // ← Este es el nombre que usará window.Android en el PHP
        )

        webView.webViewClient = object : WebViewClient() {
            override fun onPageFinished(view: WebView?, url: String?) {
                if (url != null && url.contains("login.php")) {
                    // ya está en login
                }

                //super.onPageFinished(view, url)
                // La página ya cargó — el script de marcar.php
                // detectará window.Android y usará GPS nativo automáticamente
            }
        }
    }

    private fun pedirPermisoUbicacionSiNecesario() {
        val fineGranted = ContextCompat.checkSelfPermission(
            this, Manifest.permission.ACCESS_FINE_LOCATION
        ) == PackageManager.PERMISSION_GRANTED

        if (!fineGranted) {
            requestPermissionLauncher.launch(
                arrayOf(
                    Manifest.permission.ACCESS_FINE_LOCATION,
                    Manifest.permission.ACCESS_COARSE_LOCATION
                )
            )
        }
    }
    private fun pedirPermisoNotificaciones() {
        // POST_NOTIFICATIONS solo existe desde Android 13 (API 33)
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            val ok = ContextCompat.checkSelfPermission(
                this, Manifest.permission.POST_NOTIFICATIONS
            ) == PackageManager.PERMISSION_GRANTED
            if (!ok) {
                requestNotifLauncher.launch(Manifest.permission.POST_NOTIFICATIONS)
            }
        }
        // En Android 12 y anteriores no hace falta pedir permiso
    }

}