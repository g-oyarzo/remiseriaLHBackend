# API REST — Remisería LH

Prefijo: `/api/v1`. La especificación completa (esquemas de request/response) se genera con `php artisan l5-swagger:generate` y se sirve en `/api/documentation`. Este documento resume endpoints, permisos y convenciones.

## Autenticación

- `POST /auth/login` devuelve un token Bearer de Sanctum (`Authorization: Bearer <token>`). Login y registro tienen un límite de 6 intentos por minuto.
- Un login nuevo revoca los tokens anteriores de la cuenta (una sesión activa).
- Los tokens vencen según `SANCTUM_TOKEN_EXPIRATION` (minutos). `POST /auth/refresh-token` rota el token y `POST /auth/logout` lo revoca.
- Un conductor dado de baja no puede iniciar sesión, y la baja revoca sus tokens.

## Formato de errores

| Código | Cuándo | Cuerpo |
|---|---|---|
| 401 | Sin token o token inválido/vencido. | `{"message": "..."}` |
| 403 | Rol sin permiso o recurso ajeno (por ejemplo, el viaje de otro cliente). | `{"message": "..."}` |
| 404 | Recurso inexistente. | `{"message": "..."}` |
| 405 | Método no soportado. | `{"message": "..."}` |
| 409 | Transición de estado inválida o conflicto de negocio (oferta vencida, pago existente, sin tarifa vigente, cotización vencida o ya usada). | `{"message": "..."}` y, en algunos casos, datos extra. |
| 503 | Servicio externo no disponible (Mercado Pago). Reintentar. | `{"message": "..."}` |
| 422 | Validación. | `{"message": "...", "errors": {"campo": ["..."]}}` |
| 429 | Límite de frecuencia superado. | `{"message": "..."}` |
| 500 | Error inesperado (sin detalles internos con `APP_DEBUG=false`). | `{"message": "Server Error"}` |

Los montos se intercambian como strings con dos decimales (`"3250.50"`). Las coordenadas usan `lat`/`lng` en grados decimales (WGS84). Las fechas se expresan en ISO 8601, con la zona horaria `America/Argentina/Buenos_Aires`.

Los listados usan el formato de paginación de Laravel (`current_page`, `data`, `per_page`, `total`, …), con `per_page` acotado entre 1 y 100.

## Límites de frecuencia

Todos se miden por usuario (o por IP si no hay sesión) y se configuran en `config/remiseria.php`:

| Nombre | Por defecto | Aplica a |
|---|---|---|
| `api` | 120/min | Toda la API. |
| `ubicacion` | 60/min | Pings GPS. |
| `chat` | 30/min | Envío de mensajes. |
| `cotizar` | 30/min | Cotizaciones. |
| `viajes` | 10/min | Solicitar, reservar o crear viajes. |
| `pagos` | 20/min | Registro de pagos y generación de cobros con Mercado Pago. |
| `reportes` | 10/min | Exportaciones. |
| `webhooks` | 300/min por IP | Webhook de Mercado Pago. |

## Endpoints

### Comunes (cualquier rol autenticado)

| Método | Ruta | Descripción |
|---|---|---|
| GET | `/auth/me` | Datos de la cuenta autenticada. |
| GET | `/marcas` | Marcas de vehículos. |
| GET | `/tarifas/vigente` | Tarifa vigente. |
| GET | `/viajes` | Viajes visibles para el usuario: el admin ve todos, el cliente los propios y el conductor los asignados. Filtros: `estado`, `tipo`. |
| GET | `/viajes/{id}` | Detalle. Cliente dueño, conductor asignado (o con una oferta pendiente) o admin. |
| PATCH | `/viajes/{id}/cancelar` | Cliente dueño o admin. Un viaje en curso solo lo cancela un admin. |
| GET | `/viajes/{id}/comprobante` | PDF del comprobante (pago confirmado). Cliente, conductor asignado o admin. |
| POST | `/viajes/{id}/pago/mercadopago` | Genera (o reutiliza) el enlace de Checkout Pro por el importe exacto de un viaje finalizado: `{pago_id, monto, moneda, estado, enlace_pago, vigente_hasta}`. Cliente dueño, conductor asignado o admin. 503 si Mercado Pago no está habilitado o no responde. |
| GET | `/viajes/{id}/mensajes` | Historial del chat: participantes y admin. Parámetros: `limite`, `antes_de_id`. |
| POST | `/viajes/{id}/mensajes` | Enviar un mensaje (solo cliente y conductor; con el viaje aceptado o en curso). |
| PATCH | `/viajes/{id}/mensajes/marcar-leidos` | Marca como leídos los mensajes recibidos. |
| POST | `/viajes/cotizar` | Cotización con precio cerrado (solo cliente y admin): `monto`, `monto_base`, `recargo`, `cotizacion_id` y `vigente_hasta`. Con `reserva: true` se cotiza sin recargo dinámico, para reservar. |
| POST | `/broadcasting/auth` | Autorización de canales privados de Reverb. |
| GET | `/pagos/mercadopago/configuracion` | Clave pública de Mercado Pago (para tokenizar tarjetas) y funciones habilitadas: `{habilitado, public_key, tarjetas_guardadas, pagos_automaticos, consentimiento_version}`. |

### Público

| Método | Ruta | Descripción |
|---|---|---|
| POST | `/webhooks/mercadopago` | Solo para Mercado Pago. Exige la firma `x-signature` válida y un `ts` dentro de la ventana permitida (401 si no). Temas `payment` y `order`. Verifica la operación contra la API del proveedor antes de confirmarla; 503 si no pudo consultarla, para que Mercado Pago reintente. Las notificaciones repetidas se ignoran. |

### Cliente (`role:cliente`)

| Método | Ruta | Descripción |
|---|---|---|
| POST | `/cliente/viajes/solicitar` | Viaje inmediato (CU 01). Requiere `metodo_pago`. Con `cotizacion_id` se cobra exactamente el precio cotizado (409 si venció o ya se usó; 422 si el trayecto no coincide). Alternativa: `monto_confirmado`. |
| POST | `/cliente/viajes/reservar` | Reserva (CU 09). Requiere `fecha_viaje` entre 60 minutos y 30 días desde ahora. Admite `cotizacion_id` de una cotización hecha con `reserva: true`. |
| POST | `/cliente/viajes` | Compatibilidad: según `tipo`, solicita o reserva (`metodo_pago` por defecto: efectivo). |
| PATCH | `/cliente/viajes/{id}/calificar` | Calificación de 1 a 5 de un viaje finalizado (una sola vez). |
| GET | `/cliente/medios-pago` | Tarjetas guardadas activas (predeterminada primero): `id`, `card_id`, `marca`, `tipo`, `ultimos_cuatro`, vencimiento, `predeterminado`, `cobro_automatico`. |
| POST | `/cliente/medios-pago` | Vincula una tarjeta **tokenizada** en el dispositivo: `token`, `payment_method_id`, `tipo`, `acepto_guardar_tarjeta` (obligatorio), `predeterminado`, `cobro_automatico` y `autorizo_cobro_automatico`. 409 si ya está vinculada; 422 si Mercado Pago no la valida, por el límite de tarjetas o si los cobros automáticos no están habilitados. |
| PATCH | `/cliente/medios-pago/{id}/predeterminado` | Elige la tarjeta predeterminada (404 si es ajena). |
| DELETE | `/cliente/medios-pago/{id}` | Desvincula: deja de usarse en el momento y se borra en Mercado Pago en segundo plano. |
| POST | `/cliente/viajes/{id}/pago/tarjeta` | Paga un viaje finalizado con una tarjeta guardada: `medio_pago_id` y un `token` nuevo generado con `card_id` + CVV. Responde el pago con `mp_status` y `mp_status_detail`. 409 si hay otro cobro en curso o ya está pagado; 422 si el token es rechazado. |

### Conductor (`role:conductor`)

| Método | Ruta | Descripción |
|---|---|---|
| PATCH | `/conductor/estado-servicio` | `en_servicio` true/false. |
| POST (PATCH) | `/conductor/ubicacion` | Ping GPS (`lat`, `lng`, `precision_metros` opcional). |
| GET | `/conductor/viajes/pendientes` | Ofertas vigentes del conductor, cada una con `asignacion.expira_en`. |
| POST (PATCH) | `/conductor/viajes/{id}/aceptar` | Acepta la oferta (CU 05). |
| POST (PATCH) | `/conductor/viajes/{id}/rechazar` | Rechaza la oferta; el viaje se ofrece al siguiente conductor. |
| PATCH | `/conductor/viajes/{id}/iniciar` | Aceptado → en curso. |
| POST (PATCH) | `/conductor/viajes/{id}/finalizar` | En curso → finalizado, con geocerca de 100 m (`lat`/`lng` opcionales). |
| POST | `/conductor/viajes/{id}/pago` | Registra un cobro manual (efectivo, o QR/tarjeta fuera de Mercado Pago). Conductor asignado o admin. Puede reemplazar un cobro de Mercado Pago todavía pendiente. |

### Administrador (`role:administrador`)

| Método | Ruta | Descripción |
|---|---|---|
| GET/PUT | `/admin/tarifas` | Tarifa vigente / nueva versión de la tarifa, incluidos los parámetros del recargo dinámico (`demanda_umbral_ratio`, `demanda_recargo_pct`, `lluvia_umbral_mm`, `lluvia_recargo_pct`, `recargo_maximo_pct`; si se omiten, se conservan los de la versión anterior). `POST` se mantiene por compatibilidad. |
| GET | `/admin/tarifas/historial` | Versiones de la tarifa. |
| GET/POST/PUT/DELETE | `/admin/zonas-tarifarias[/{id}]` | Zonas de contingencia del cotizador (DELETE = desactivar). |
| POST | `/admin/viajes/registrar` | Viaje telefónico (CU 10), con `cliente_id` o `cliente{dni,nombre,apellido,telefono}`. |
| POST | `/admin/viajes/{id}/reasignar` | Reinicia una búsqueda de conductor agotada. |
| GET | `/admin/pagos?estado=pendiente` | Pagos para conciliar. |
| PATCH | `/admin/pagos/{id}/conciliar` | `estado`: `confirmado` o `rechazado`, más `observacion`. No aplica a cobros de Mercado Pago (409). |
| POST | `/admin/pagos/{id}/reintegrar` | `mp_operacion_id`: reintegra en Mercado Pago un cobro aprobado que **no** se aplicó (pago doble o tardío). Idempotente; 202. Nunca la operación que confirmó el pago (409). |
| GET | `/admin/comprobantes` | Registro de comprobantes emitidos (solo lectura). Filtros: `fecha_desde`, `fecha_hasta`, `numero`, `viaje_id`, `metodo_pago`. |
| GET | `/admin/comprobantes/{id}` | Detalle de un comprobante. |
| GET | `/admin/comprobantes/{id}/descargar` | PDF, verificado contra su SHA-256 (409 si no coincide). |
| GET | `/admin/reportes/estadisticas` | CU 15. Filtros: `fecha_desde`, `fecha_hasta`, `conductor_dni`, `estado`. |
| GET | `/admin/reportes/exportar?formato=pdf\|excel` | CU 16, con los mismos filtros. |
| CRUD | `/admin/conductores` | Alta, modificación, baja lógica y listado (CU 12-14). |
| PATCH | `/admin/conductores/{id}/estado` | Activar o dar de baja. |
| GET | `/admin/clientes[/{id}]` | Clientes. |
| GET/POST/PUT | `/admin/vehiculos[/{id}]` | Vehículos (no hay baja). |
| GET | `/conductores/cercanos` | Conductores disponibles cerca de un punto. |

## Correspondencia con `Hacer.txt`

| Tarea | Implementación |
|---|---|
| `GET/PUT /api/v1/admin/tarifas` | Igual. |
| `POST /api/v1/viajes/cotizar` | Igual. |
| `POST /api/v1/cliente/viajes/solicitar`, `/reservar` | Igual. |
| `POST /api/v1/admin/viajes/registrar` | Igual. |
| `POST /api/v1/conductor/viajes/{id}/aceptar`, `/rechazar`, `/finalizar` | Igual (también aceptan PATCH). |
| `POST /api/v1/conductor/ubicacion` | Igual (también acepta PATCH). |
| Chat "GET /chat" y "POST /chat" | `GET`/`POST /viajes/{id}/mensajes` (ruta existente, ya usada por los frontends). |
| `POST /api/v1/conductor/viajes/{id}/pago` | Igual. |
| Comprobante PDF | `GET /viajes/{id}/comprobante`, `comprobante_url` en la respuesta del pago y registro `/admin/comprobantes`. |
| `GET /api/v1/admin/reportes/estadisticas`, `/exportar` | Igual. |
| `UbicacionActualizadaEvent` | `App\Events\UbicacionConductorActualizada`, difundido como `ubicacion.actualizada`. |

## Canales y eventos WebSocket (Reverb)

Todos los canales son privados (`private-…`) y se autorizan con `POST /api/v1/broadcasting/auth` y el mismo token Bearer.

| Canal | Quién puede escuchar | Eventos |
|---|---|---|
| `viaje.{id}` | Cliente del viaje, conductor asignado y admin | `viaje.estado` (`{viaje_id, estado, conductor_id}`), `pago.actualizado` (`{viaje_id, pago_id, estado}`), `mensaje.nuevo`, `ubicacion.actualizada` |
| `conductor.{id}` | El propio conductor y el admin (el pasajero **no**: recibe la ubicación por `viaje.{id}`) | `asignacion.ofrecida`, `asignacion.retirada`, `ubicacion.actualizada` |
| `admin.despacho` | Administradores | `busqueda.agotada`, `ubicacion.actualizada` (mapa de flota) |

`ubicacion.actualizada` informa `registrado_en` en ISO 8601 con milisegundos.

Los eventos se emiten después de confirmar la transacción. Si Reverb no está disponible, la operación de negocio no falla: los clientes deben resincronizar por REST al reconectarse.

Los eventos de cliente (`client-*`) de Reverb están deshabilitados: los frontends no pueden enviar mensajes por el WebSocket, solo recibirlos.
