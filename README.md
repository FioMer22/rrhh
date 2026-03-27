# PWA empaquetada como APK

##  Objetivo

Migración progresiva hacia una aplicación nativa.

##  Estado actual

* **Producción:**

  * PWA empaquetada como APK

* **Desarrollo (local):**

  * Android Studio (Kotlin)
  * Compatibilidad: Android 8.0 Oreo
  * Uso de funciones nativas específicas:

    * Notificaciones nativas
    * Acceso a GPS
    * Widget de marcación (entrada/salida)

* **Arquitectura:**

  * WebView que contiene:

    * Frontend y Backend  en PHP, html y js
  * Base de datos: MySQL

---

##  Uso

### App para RRHH

La aplicación permite:

* Gestión de usuarios
* Marcación de:

  * Entrada
  * Inicio a almuerzo o pausa
  * Fin de almuerzo o pausa
  * Salida final
* Creación de actividades
* Registro de inicio y fin de actividades
* Envío de notificaciones a empleados

---

##  Propósito actual

* Corregir errores para despliegue en producción
* Resolver conflictos de integración de la app híbrida
* Realizar QA y verificación de la APK en entorno de producción parcial antes del lanzamiento
