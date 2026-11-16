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
| Tiempo real | Canales privados con autorización por viaje o conductor; `conductor.{id}` solo para el conductor y el admin (SEC-01: Reverb autoriza solo al suscribirse, así que el pasajero no puede escuchar ese canal); `admin.despacho` solo para administradores; eventos `client-*` deshabilitados (SEC-02); la API HTTP de publicación de Reverb (`/apps/...`) no se expone. |
| Errores | Las excepciones de dominio son propias y no se captura `RuntimeException` genérica (antes un `QueryException` devolvía el SQL en un 409). Con `APP_DEBUG=false`, los 500 no exponen detalles. |
| Servicios externos | Clave de Google en un header, no en la URL; los logs de fallas no incluyen URL, credenciales ni coordenadas. `MAPAS_DRIVER=ninguno` por defecto, así que no se envían coordenadas a terceros sin configuración explícita. |
| Archivos generados | Los reportes PDF y XLSX se generan al vuelo, sin almacenamiento; los comprobantes se guardan en un disco privado. Todos se sirven con `Cache-Control: no-store` y sin URLs públicas. El generador de PDF no interpreta HTML ni carga recursos remotos; en el XLSX, los textos nunca se interpretan como fórmulas. |
| Dependencias | `composer audit` sin advertencias (2026-11-15: se actualizaron `laravel/framework` 13.30 → 13.42 y los paquetes `symfony/*` afectados por 9 avisos: `http-foundation`, `http-kernel`, `routing`, `mime` y `yaml`). `npm audit --omit=dev`: 0 vulnerabilidades; las de `vite` y `launch-editor` afectan solo al servidor de desarrollo. |
| Mercado Pago: webhooks (2026-11-15) | Además de la firma: **ventana temporal** del `ts` de `x-signature` (`MERCADOPAGO_WEBHOOK_TOLERANCIA_SEGUNDOS`, 900 s, en milisegundos o segundos) y **protección contra repeticiones** (una firma ya procesada con éxito no se procesa de nuevo; un reintento tras un 503 sí). Temas `payment` y `order`. |
| Mercado Pago: medios guardados | El backend **nunca recibe** número de tarjeta, vencimiento ni CVV: solo tokens de un solo uso generados por MercadoPago.js o el SDK (validados como alfanuméricos). Se guardan solo los identificadores de Mercado Pago, la marca, los últimos 4 dígitos y el vencimiento. Consentimiento explícito con fecha, IP y versión del texto. Un pasajero solo ve, usa o borra sus tarjetas (404 para las ajenas). Candado por viaje contra cobros simultáneos. Claves de idempotencia en pagos, órdenes y reintegros. La confirmación siempre sale de **consultar** la API. |
| Mercado Pago: cobro reemplazado | Al registrar otro medio, o al pagar con tarjeta, se **vence** en Mercado Pago el enlace de Checkout Pro vigente (job con reintentos). Un pago tardío o duplicado no se aplica y puede **reintegrarse** (admin o `MERCADOPAGO_REINTEGRO_AUTOMATICO`), nunca la operación que confirmó el pago. |
| Mapas | Claves solo en el backend. Cuota local por minuto, día y mes, y circuit breaker para no superar los cupos gratuitos ni generar cargos. Sin recorrido por calles: 422 en lugar de un precio inventado. |
| Proxy de confianza | `TRUSTED_PROXIES` con la subred exacta de NGINX (`config/trustedproxy.php`), para que los límites por IP, los logs y el consentimiento usen la IP real del cliente. Sin `*` ni el atajo `private_ranges`. |
| Caché | `cache.serializable_classes = false` (Laravel 13): la caché no deserializa objetos, como protección contra cadenas de deserialización si se filtra `APP_KEY`. El código guarda solo arrays y escalares. |
| Infraestructura (`compose.prod.yaml`) | Solo NGINX publica puertos; MySQL, Redis (con contraseña y `noeviction`), Reverb y PHP-FPM solo en la red interna. TLS 1.2/1.3, HSTS, cabeceras de seguridad, `server_tokens off`, archivos ocultos y cualquier `.php` que no sea `index.php` devuelven 404. Límites por IP en NGINX (API, webhook y conexiones WebSocket). PHP con `expose_php=Off` y OPcache sin revalidación. Contenedores con el usuario `www-data`, límite de memoria y logs rotados. La imagen no incluye `.env`, tests ni dependencias de desarrollo. |

## Límites conocidos

- **GPS:** el backend confía en las coordenadas del dispositivo. Las validaciones (saltos imposibles, precisión, vigencia) no detectan una falsificación consistente. Como el precio es cerrado, un GPS falso ya no altera el importe, pero sí puede permitir finalizar un viaje lejos del destino o recibir ofertas sin estar cerca. Para mayor garantía haría falta atestación del dispositivo (Play Integrity / App Attest).
- **Mercado Pago:** la verificación de la firma, la ventana temporal y los formatos de órdenes y perfiles de pago siguen la documentación oficial, pero **no se probaron contra la API real**: todas las pruebas usan respuestas simuladas. Validar en sandbox antes de producción (sección siguiente). Si el pasajero paga con Mercado Pago después de que se registró el cobro por otro medio, el sistema no lo aplica: lo marca para reintegro, y el reintegro se pide desde el panel (`POST /admin/pagos/{id}/reintegrar`) o automáticamente. Vencer un enlace no detiene un pago que el pasajero ya había iniciado. Los reintegros parciales no cambian el estado del pago. Los cobros manuales de QR o tarjeta fuera de Mercado Pago siguen dependiendo de la conciliación del administrador.
- **Cobros automáticos sin CVV:** requieren que Mercado Pago habilite comercialmente el producto "Pagos automáticos". El código está listo detrás de `MERCADOPAGO_PAGOS_AUTOMATICOS=false`, pero el formato de `stored_credential` para cobros iniciados por el comercio debe confirmarse con Mercado Pago.
- **Billeteras virtuales:** vincular una cuenta de Mercado Pago para cobrar sin intervención ("Wallet Connect") exige requisitos comerciales (más de 100 mil usuarios, ticket promedio bajo) y autorización del ejecutivo de cuenta. No se implementó. Hoy el pasajero puede pagar con su cuenta de Mercado Pago (dinero en cuenta) desde el enlace de Checkout Pro.
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
6. Servicio de mapas: `MAPAS_DRIVER=ors` con `ORS_API_KEY` (gratuito) y `MAPAS_CACHE_STORE=redis`. Google Routes solo con autorización, clave restringida (por API y por IP), cuotas en la consola y `MAPAS_GOOGLE_CUOTA_POR_MES` por debajo del cupo gratuito. OSRM propio, en otra máquina. El servidor público de OSRM no es apto para producción.
7. Cargar las zonas tarifarias de contingencia y verificar la tarifa vigente.
8. `php artisan config:cache`, `route:cache` y `event:cache`: los ejecuta el `entrypoint` de la imagen (`deploy/docker/php/entrypoint.sh`).
9. Contraseña del administrador inicial vía `ADMIN_PASSWORD`, y cambiarla después del primer acceso.
10. Backups automáticos y cifrados de MySQL **y de `storage/app/private/comprobantes`** fuera del servidor (`deploy/backup/`), con prueba de restauración mensual.
11. Monitoreo de `failed_jobs`, de los logs `Falla del servicio de mapas` y `Store de ubicaciones no disponible`, de los logs `critical` de Mercado Pago y de comprobantes, y de las búsquedas agotadas.
12. Mercado Pago: credenciales productivas (`MERCADOPAGO_ACCESS_TOKEN`), clave secreta de Webhooks (`MERCADOPAGO_WEBHOOK_SECRET`), `MERCADOPAGO_NOTIFICATION_URL` con HTTPS público y `MERCADOPAGO_SANDBOX=false`. En el panel de Mercado Pago, configurar Webhooks con el evento "Pagos" apuntando a la misma URL.
13. Recargo dinámico: si se usa el recargo por lluvia, `CLIMA_DRIVER=openmeteo` con un plan comercial (`CLIMA_OPENMETEO_URL`, `CLIMA_OPENMETEO_API_KEY`). Los porcentajes de recargo se definen en la tarifa (0 % por defecto).
14. Antes de la operación real: pruebas de carga (`deploy/carga/`) **en la VM de producción o en una equivalente**, y el flujo completo de Mercado Pago en sandbox (sección siguiente).
15. Despliegue con `compose.prod.yaml`: guía en [DESPLIEGUE_ORACLE_CLOUD.md](DESPLIEGUE_ORACLE_CLOUD.md).

## Validación de Mercado Pago en sandbox

Requisitos:

- credenciales de prueba (`TEST-…`) y clave pública de prueba;
- clave secreta de Webhooks;
- un usuario vendedor y uno comprador de prueba;
- URL pública HTTPS del *staging*.

**Las pruebas automáticas usan respuestas simuladas y no reemplazan este procedimiento.**

| # | Escenario | Cómo | Resultado esperado |
|---|---|---|---|
| 1 | Pago aprobado (enlace) | `POST /viajes/{id}/pago/mercadopago`, pagar con una tarjeta de prueba aprobada | Webhook 200; pago `confirmado`; `pago.actualizado`; comprobante emitido |
| 2 | Pago rechazado | Tarjeta de prueba con estado rechazado | Pago `pendiente` con `novedad_mercadopago` en el historial |
| 3 | Firma real | Revisar los logs del caso 1 | No aparece "Webhook de Mercado Pago rechazado". Si aparece `invalida`, comparar el manifiesto con la notificación real; si aparece `vencida`, revisar la unidad de `ts` y el reloj del servidor |
| 4 | Webhook duplicado | Reenviar la notificación desde el panel de Mercado Pago | 200 "ya procesada" o `duplicado`; sin cambios en el pago |
| 5 | Webhook perdido | Pausar el webhook (URL inválida), pagar y esperar 10 minutos | `pagos:conciliar-mercadopago` confirma el pago |
| 6 | Reintegro | Reintegrar desde el panel de Mercado Pago | Pago `reintegrado`; aparece en reportes como reintegro |
| 7 | Cambio a efectivo con enlace abierto | Generar el enlace, registrar efectivo, intentar pagar el enlace | El enlace ya no acepta pagos. Si el pago entra igual: `mercadopago_aprobado_sin_aplicar` y reintegro con `POST /admin/pagos/{id}/reintegrar` |
| 8 | Guardar tarjeta | Tokenizar con MercadoPago.js de prueba y `POST /cliente/medios-pago` | 201 con marca y últimos 4 dígitos |
| 9 | Pagar con tarjeta guardada | `createCardToken({cardId})` con CVV y `POST /cliente/viajes/{id}/pago/tarjeta` | `confirmado` |
| 10 | Cargo rechazado con tarjeta guardada | Tarjeta de prueba para rechazo | `pendiente` con `mp_status_detail` |
| 11 | Desvincular | `DELETE /cliente/medios-pago/{id}` | La tarjeta ya no figura en el customer de Mercado Pago |
| 12 | Cobro automático | Solo si Mercado Pago habilitó "Pagos automáticos" | Orden `processed`, pago `confirmado` por webhook `order` |

Anotar los resultados reales (fecha, IDs de prueba sin datos personales) antes de pasar a producción.

## Auditoría final de seguridad (2026-11-15)

Controles agregados (los identificadores SEC-xx se citan en los commits y en los tests de `tests/Feature/Seguridad/AuditoriaFinalTest.php`):

- Límite de intentos fallidos de login **por cuenta**, además del límite por IP.
- Revocación de todos los tokens al cambiar la contraseña o el email.
- Límite por hora para vincular tarjetas (anti *card testing*).
- Vista acotada del pago para pasajeros y conductores.
- `trustHosts`: solo el host exacto de `APP_URL` y `TRUSTED_HOSTS`. NGINX descarta los hosts desconocidos (444 / `ssl_reject_handshake`).
- Documentación OpenAPI deshabilitada en producción (`API_DOCS_PUBLICA`).
- Backups autenticados con **HMAC-SHA256**, verificado antes de descifrar. La restauración solo va a bases nuevas y bloquea las bases del sistema.
- Logs de difusión sin la firma de Reverb.
- Contenedores PHP sin capacidades de Linux (`cap_drop: ALL`), todos con `no-new-privileges`, y el código de la imagen de solo lectura para `www-data`.
- Cabeceras CSP (`form-action`, `base-uri`), `Permissions-Policy`, COOP y CORP.

Variables nuevas: `TRUSTED_HOSTS`, `API_DOCS_PUBLICA`, `REVERB_ACCEPT_CLIENT_EVENTS_FROM` (default `none`), `LOGIN_INTENTOS_POR_CUENTA`, `LOGIN_BLOQUEO_SEGUNDOS` y `VINCULAR_TARJETAS_POR_HORA`.
