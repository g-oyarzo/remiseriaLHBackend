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
| 409 | Transición de estado inválida o conflicto de negocio (oferta vencida, pago existente, sin tarifa vigente). | `{"message": "..."}` y, en algunos casos, datos extra. |
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
| `pagos` | 20/min | Registro de pagos. |
| `reportes` | 10/min | Exportaciones. |

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
| GET | `/viajes/{id}/mensajes` | Historial del chat: participantes y admin. Parámetros: `limite`, `antes_de_id`. |
| POST | `/viajes/{id}/mensajes` | Enviar un mensaje (solo cliente y conductor; con el viaje aceptado o en curso). |
| PATCH | `/viajes/{id}/mensajes/marcar-leidos` | Marca como leídos los mensajes recibidos. |
| POST | `/viajes/cotizar` | Cotización (solo cliente y admin). |
| POST | `/broadcasting/auth` | Autorización de canales privados de Reverb. |

### Cliente (`role:cliente`)

| Método | Ruta | Descripción |
|---|---|---|
| POST | `/cliente/viajes/solicitar` | Viaje inmediato (CU 01). Requiere `metodo_pago`. Admite `monto_confirmado` opcional. |
| POST | `/cliente/viajes/reservar` | Reserva (CU 09). Requiere `fecha_viaje`. |
| POST | `/cliente/viajes` | Compatibilidad: según `tipo`, solicita o reserva (`metodo_pago` por defecto: efectivo). |
| PATCH | `/cliente/viajes/{id}/calificar` | Calificación de 1 a 5 de un viaje finalizado (una sola vez). |

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
| POST | `/conductor/viajes/{id}/pago` | Registra el cobro (conductor asignado o admin). |

### Administrador (`role:administrador`)

| Método | Ruta | Descripción |
|---|---|---|
| GET/PUT | `/admin/tarifas` | Tarifa vigente / nueva versión de la tarifa. `POST` se mantiene por compatibilidad. |
| GET | `/admin/tarifas/historial` | Versiones de la tarifa. |
| GET/POST/PUT/DELETE | `/admin/zonas-tarifarias[/{id}]` | Zonas de contingencia del cotizador (DELETE = desactivar). |
| POST | `/admin/viajes/registrar` | Viaje telefónico (CU 10), con `cliente_id` o `cliente{dni,nombre,apellido,telefono}`. |
| POST | `/admin/viajes/{id}/reasignar` | Reinicia una búsqueda de conductor agotada. |
| GET | `/admin/pagos?estado=pendiente` | Pagos para conciliar. |
| PATCH | `/admin/pagos/{id}/conciliar` | `estado`: `confirmado` o `rechazado`, más `observacion`. |
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
| Comprobante PDF | `GET /viajes/{id}/comprobante` y `comprobante_url` en la respuesta del pago. |
| `GET /api/v1/admin/reportes/estadisticas`, `/exportar` | Igual. |
| `UbicacionActualizadaEvent` | `App\Events\UbicacionConductorActualizada`, difundido como `ubicacion.actualizada`. |

## Canales y eventos WebSocket (Reverb)

Todos los canales son privados (`private-…`) y se autorizan con `POST /api/v1/broadcasting/auth` y el mismo token Bearer.

| Canal | Quién puede escuchar | Eventos |
|---|---|---|
| `viaje.{id}` | Cliente del viaje, conductor asignado y admin | `viaje.estado` (`{viaje_id, estado, conductor_id}`), `mensaje.nuevo`, `ubicacion.actualizada` |
| `conductor.{id}` | El propio conductor, el admin y el cliente con un viaje activo con ese conductor | `asignacion.ofrecida`, `asignacion.retirada`, `ubicacion.actualizada` |
| `admin.despacho` | Administradores | `busqueda.agotada`, `ubicacion.actualizada` (mapa de flota) |

Los eventos se emiten después de confirmar la transacción. Si Reverb no está disponible, la operación de negocio no falla: los clientes deben resincronizar por REST al reconectarse.
