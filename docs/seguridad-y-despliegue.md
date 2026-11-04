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
| Integridad financiera | Importe calculado en el servidor y validado al centavo; precio cerrado con cotizaciones de un solo uso, ligadas a la cuenta y al trayecto; recargo dinámico acotado por `recargo_maximo_pct`; pagos digitales manuales pendientes hasta conciliación; referencia única por método; un pago por viaje (`UNIQUE` más bloqueo); historial de transiciones; tarifas versionadas y snapshot en el viaje; cálculos en centavos. |
| Mercado Pago | El webhook es público pero exige la firma `x-signature` (HMAC-SHA256, comparación en tiempo constante) y tiene límite por IP. Nunca se confía en el cuerpo: se consulta el pago en la API y se exige referencia propia, moneda ARS e importe exacto. Idempotente por operación y estado. Las anomalías no modifican el pago: van al historial y a un log `critical`. El access token solo viaja en el header `Authorization`; los logs no incluyen URL, token ni cuerpo de las respuestas. No se envían datos personales del pasajero en la preferencia ni se guardan datos de tarjetas: el pago ocurre en Checkout Pro. |
| Comprobantes | Registro inmutable: el modelo impide modificar o borrar, las claves foráneas son `restrict`, el PDF se guarda fuera de `public` (`storage/app/private/comprobantes`) con su SHA-256 y cada descarga lo verifica (409 y log `critical` si no coincide). La administración solo tiene endpoints de lectura y descarga. |
| Concurrencia | Transacciones con `SELECT … FOR UPDATE` en un orden fijo (viaje → oferta → conductor); restricciones `UNIQUE` como última barrera; jobs y eventos *after commit*. Verificado con tests de dos conexiones (`tests/Feature/Concurrencia`). |
| Tiempo real | Canales privados con autorización por viaje o conductor; `admin.despacho` solo para administradores; payloads mínimos, sin datos personales del cliente en las ofertas. |
| Errores | Las excepciones de dominio son propias y no se captura `RuntimeException` genérica (antes un `QueryException` devolvía el SQL en un 409). Con `APP_DEBUG=false`, los 500 no exponen detalles. |
| Servicios externos | Clave de Google en un header, no en la URL; los logs de fallas no incluyen URL, credenciales ni coordenadas. `MAPAS_DRIVER=ninguno` por defecto, así que no se envían coordenadas a terceros sin configuración explícita. |
| Archivos generados | Los reportes PDF y XLSX se generan al vuelo, sin almacenamiento; los comprobantes se guardan en un disco privado. Todos se sirven con `Cache-Control: no-store` y sin URLs públicas. El generador de PDF no interpreta HTML ni carga recursos remotos; en el XLSX, los textos nunca se interpretan como fórmulas. |
| Dependencias | `composer audit` sin advertencias (actualizados `symfony/http-foundation` y `league/commonmark`). `npm audit --omit=dev`: 0 vulnerabilidades; las de `vite` y `launch-editor` afectan solo al servidor de desarrollo. |

## Límites conocidos

- **GPS:** el backend confía en las coordenadas del dispositivo. Las validaciones (saltos imposibles, precisión, vigencia) no detectan una falsificación consistente. Como el precio es cerrado, un GPS falso ya no altera el importe, pero sí puede permitir finalizar un viaje lejos del destino o recibir ofertas sin estar cerca. Para mayor garantía haría falta atestación del dispositivo (Play Integrity / App Attest).
- **Mercado Pago:** la verificación de la firma sigue la documentación oficial, pero **no se probó contra notificaciones reales**: validarla en el sandbox antes de producción. Si el pasajero paga con Mercado Pago después de que se registró el cobro por otro medio, el sistema no lo aplica y lo marca para reintegro: la devolución se hace a mano desde el panel de Mercado Pago. Los reintegros parciales no cambian el estado del pago. Los cobros manuales de QR o tarjeta fuera de Mercado Pago siguen dependiendo de la conciliación del administrador.
- **Recargo por lluvia:** depende de un servicio de clima externo; si falla, la cotización sale sin recargo por lluvia. La API pública de Open-Meteo no admite uso comercial.
- **Comprobantes:** no son facturas electrónicas (AFIP). Los PDF viven en el disco local del servidor: deben entrar en los backups junto con la base, porque sin el archivo el registro no se puede descargar.
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
10. Backups automáticos de MySQL **y de `storage/app/private/comprobantes`**, con prueba de restauración; retención de `storage/logs`.
11. Monitoreo de `failed_jobs`, de los logs `Falla del servicio de mapas` y `Store de ubicaciones no disponible`, de los logs `critical` de Mercado Pago y de comprobantes, y de las búsquedas agotadas.
12. Mercado Pago: credenciales productivas (`MERCADOPAGO_ACCESS_TOKEN`), clave secreta de Webhooks (`MERCADOPAGO_WEBHOOK_SECRET`), `MERCADOPAGO_NOTIFICATION_URL` con HTTPS público y `MERCADOPAGO_SANDBOX=false`. En el panel de Mercado Pago, configurar Webhooks con el evento "Pagos" apuntando a la misma URL.
13. Recargo dinámico: si se usa el recargo por lluvia, `CLIMA_DRIVER=openmeteo` con un plan comercial (`CLIMA_OPENMETEO_URL`, `CLIMA_OPENMETEO_API_KEY`). Los porcentajes de recargo se definen en la tarifa (0 % por defecto).
14. Antes de la operación real: pruebas de carga con más de 200 conductores enviando pings (RNF01, RNF02) y el flujo completo de Mercado Pago en sandbox (pago aprobado, rechazado, reintegro y webhook perdido).
