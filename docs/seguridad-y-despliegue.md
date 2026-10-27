# Seguridad y despliegue

## Controles implementados

| Área | Control |
|---|---|
| Autenticación | Tokens Sanctum con vencimiento (`SANCTUM_TOKEN_EXPIRATION`), una sesión activa por cuenta, rotación con `refresh-token`. Login con tiempo de respuesta uniforme (anti-enumeración) y registro con mensaje genérico ante DNI o email duplicados. Al dar de baja un conductor se revocan sus tokens y se bloquea su login. |
| Autorización vertical | Middleware `role:*` por grupo de rutas, más una verificación explícita del rol en las acciones sensibles (defensa en profundidad). |
| Autorización horizontal (IDOR/BOLA) | `ViajePolicy::ver` (cliente dueño, conductor asignado o con oferta pendiente, admin) en detalle, chat, comprobante, cancelación e inicio. Aceptar, rechazar y finalizar exigen ser el destinatario o el conductor asignado. La ubicación siempre se asocia al conductor del token. |
| Exposición de datos | `ViajeResource`, `MensajeResource` y `PersonaPublicaResource`: la contraparte de un viaje ve solo nombre y apellido (más calificación y vehículo del conductor). DNI, teléfono, CUIL, domicilio, fecha de nacimiento y GPS del conductor no se exponen al cliente. El comprobante PDF no incluye DNI ni teléfono y enmascara la referencia de pago. |
| Validación | Form Requests en todos los endpoints nuevos: coordenadas en rango; montos mayores a cero con dos decimales; fechas acotadas; referencias de pago con lista blanca de caracteres; caracteres de control eliminados del chat. Consultas parametrizadas y comodines `LIKE` escapados. |
| Abuso | Límites de frecuencia por usuario (ver [api.md](api.md#límites-de-frecuencia)); `per_page` acotado a 100; rango de reportes y filas exportables acotados. |
| Integridad financiera | Importe calculado en el servidor y validado al centavo; pagos digitales pendientes hasta conciliación; referencia única por método; un pago por viaje (`UNIQUE` más bloqueo); historial de transiciones; tarifas versionadas y snapshot en el viaje; cálculos en centavos. |
| Concurrencia | Transacciones con `SELECT … FOR UPDATE` en un orden fijo (viaje → oferta → conductor); restricciones `UNIQUE` como última barrera; jobs y eventos *after commit*. Verificado con tests de dos conexiones (`tests/Feature/Concurrencia`). |
| Tiempo real | Canales privados con autorización por viaje o conductor; `admin.despacho` solo para administradores; payloads mínimos, sin datos personales del cliente en las ofertas. |
| Errores | Las excepciones de dominio son propias y no se captura `RuntimeException` genérica (antes un `QueryException` devolvía el SQL en un 409). Con `APP_DEBUG=false`, los 500 no exponen detalles. |
| Servicios externos | Clave de Google en un header, no en la URL; los logs de fallas no incluyen URL, credenciales ni coordenadas. `MAPAS_DRIVER=ninguno` por defecto, así que no se envían coordenadas a terceros sin configuración explícita. |
| Archivos generados | PDF y XLSX se generan al vuelo, con `Cache-Control: no-store`, sin URLs públicas ni almacenamiento. El generador de PDF no interpreta HTML ni carga recursos remotos; en el XLSX, los textos nunca se interpretan como fórmulas. |
| Dependencias | `composer audit` sin advertencias (actualizados `symfony/http-foundation` y `league/commonmark`). `npm audit --omit=dev`: 0 vulnerabilidades; las de `vite` y `launch-editor` afectan solo al servidor de desarrollo. |

## Límites conocidos

- **GPS:** el backend confía en las coordenadas del dispositivo. Las validaciones (saltos imposibles, precisión, vigencia) no detectan una falsificación consistente. Para mayor garantía haría falta atestación del dispositivo (Play Integrity / App Attest) o un control administrativo de los viajes con `costo_final_origen = estimado`.
- **Pagos digitales:** no hay integración con un proveedor. La confirmación de QR y tarjetas depende de la conciliación manual del administrador.
- **Comprobantes:** no son facturas electrónicas (AFIP).
- **Tokens:** los tokens Bearer viven en el dispositivo; su protección depende de las apps (almacenamiento seguro).
- **Reverb y colas:** si el worker o Reverb caen, la operación sigue, pero las notificaciones en tiempo real no llegan. Las difusiones fallidas quedan en `failed_jobs`.

## Checklist de despliegue

1. `APP_ENV=production`, `APP_DEBUG=false`, `APP_KEY` propio, `APP_URL` con HTTPS (TLS terminado en NGINX).
2. `php artisan migrate --force` **antes** de servir tráfico con la nueva versión. La migración `2026_10_27_000010` corrige las coordenadas guardadas con los ejes invertidos: si se escribieran filas con el código nuevo antes de migrar, quedarían invertidas.
3. Credenciales propias de Reverb (`REVERB_APP_KEY/SECRET`), `REVERB_ALLOWED_ORIGINS` y `CORS_ALLOWED_ORIGINS` con los dominios reales, y `SANCTUM_STATEFUL_DOMAINS` acorde.
4. Redis disponible (`UBICACION_CACHE_STORE=redis`); se recomienda `QUEUE_CONNECTION=redis` por el volumen de pings.
5. Procesos supervisados (systemd o supervisord): `queue:work --tries=3 --backoff=5` (uno o más), `schedule:work` (o cron `* * * * * php artisan schedule:run`) y `reverb:start`.
6. Servicio de mapas: instancia propia de OSRM o Google Routes API con clave restringida (por API y por IP). El servidor público de OSRM no es apto para producción.
7. Cargar las zonas tarifarias de contingencia y verificar la tarifa vigente.
8. `php artisan config:cache`, `route:cache` y `event:cache` (verificados sin errores).
9. Contraseña del administrador inicial vía `ADMIN_PASSWORD`, y cambiarla después del primer acceso.
10. Backups automáticos de MySQL con prueba de restauración; retención de `storage/logs`.
11. Monitoreo de `failed_jobs`, de los logs `Falla del servicio de mapas` y `Store de ubicaciones no disponible`, y de las búsquedas agotadas.
12. Antes de la operación real: pruebas de carga con más de 200 conductores enviando pings (RNF01, RNF02) y prueba de conciliación de pagos con el proveedor real.
