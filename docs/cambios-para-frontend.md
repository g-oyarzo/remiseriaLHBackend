# Cambios de contrato para los frontends

Rama `feature/backend-completion-security-hardening`. Se conservaron las rutas y la forma del JSON existentes salvo en los puntos marcados como **ruptura**, que responden a requisitos de `Hacer.txt` o a correcciones de seguridad.

## Rupturas (requieren cambios)

| Qué cambia | Antes | Ahora | Acción en el frontend |
|---|---|---|---|
| `GET /admin/tarifas` | Historial paginado | Tarifa vigente (`{data: {...}}`), según `Hacer.txt` | El panel debe usar `GET /admin/tarifas/historial` para el historial. |
| Aceptación de viajes | Cualquier conductor podía aceptar cualquier viaje `solicitado` | Solo el conductor al que el sistema le **ofreció** el viaje, antes de `expira_en` | La app del conductor escucha `asignacion.ofrecida` en `conductor.{id}`, muestra la oferta con su cuenta regresiva y llama a `aceptar` o `rechazar`. |
| `GET /conductor/viajes/pendientes` | Todos los viajes solicitados | Solo las ofertas vigentes del conductor, con `asignacion.{id, expira_en, distancia_metros}` | Mostrarlo como "ofertas". |
| Evento `viaje.programado.disponible` | Aviso a todos los conductores | Eliminado: las reservas pasan por la asignación automática | Dejar de escucharlo. |
| Registro de pagos | Cualquier `monto >= 0`; todo pago quedaba confirmado | `monto` debe ser **exactamente** `costo_final` del viaje (si no, 422 con `importe_esperado`). QR y tarjetas requieren `referencia_externa` y quedan `pendiente` hasta la conciliación del admin | Mostrar `costo_final` al finalizar; pedir el número de operación en cobros digitales; mostrar "pendiente de verificación". |
| `finalizar` | Sin validación de ubicación | Exige estar a 100 m del destino, con una ubicación reciente | Enviar `lat`/`lng` actuales en el body; manejar 422 (`distancia_al_destino_metros`) y 409 (sin ubicación reciente). |
| Cancelación | El cliente podía cancelar un viaje en curso | Un viaje en curso solo lo cancela un administrador (409 para el cliente) | Ocultar o deshabilitar "cancelar" durante el viaje en la app del pasajero. |
| Datos personales en viajes y chat | Se incluían DNI, teléfono, CUIL, domicilio y ubicación de la contraparte | `cliente.persona` y `conductor.persona` de la contraparte traen solo `id`, `nombre` y `apellido`; `conductor` trae `persona_id`, `calificacion` y `foto`. Admin y la propia persona siguen viendo todo | No depender de esos campos en las apps de pasajero y conductor. |
| Validación de tarifas | Se aceptaba `0` | Montos mayores a 0 con hasta 2 decimales | Validar igual en el formulario. |
| `DELETE /admin/vehiculos/{id}` | Respondía 500 | 405 (no existe la baja de vehículos) | No ofrecer la acción. |

## Endpoints nuevos

| Endpoint | Uso |
|---|---|
| `POST /viajes/cotizar` | Cotización previa: `monto`, `distancia_km`, `duracion_minutos`, `metodo`. |
| `POST /cliente/viajes/solicitar`, `POST /cliente/viajes/reservar` | Reemplazan a `POST /cliente/viajes`, que se mantiene. Requieren `metodo_pago`. Con `monto_confirmado`, el backend devuelve 409 y la cotización nueva si el monto cambió. |
| `POST /admin/viajes/registrar` | Viajes telefónicos. |
| `POST /conductor/viajes/{id}/rechazar` | Rechazar una oferta. |
| `POST /admin/viajes/{id}/reasignar` | Reintentar una búsqueda agotada. |
| `GET /admin/pagos`, `PATCH /admin/pagos/{id}/conciliar` | Conciliación de cobros digitales. |
| `GET /viajes/{id}/comprobante` | PDF (también llega `comprobante_url` en la respuesta del pago). |
| `GET /admin/reportes/estadisticas`, `GET /admin/reportes/exportar` | Reportes. |
| `/admin/zonas-tarifarias` | Zonas de contingencia del cotizador. |
| `GET /admin/tarifas/historial` | Historial de tarifas. |

Las rutas `aceptar`, `finalizar` y `ubicacion` aceptan ahora `POST` (contrato de `Hacer.txt`) y siguen aceptando `PATCH`.

## Campos nuevos en el viaje

`metodo_pago`, `canal`, `destino_localidad`/`destino_calle`/`destino_numero`, `precio_base_aplicado`, `precio_por_km_aplicado`, `distancia_estimada_km`, `duracion_estimada_min`, `metodo_cotizacion`, `despachado_en`, `busqueda_agotada_en`, `iniciado_en`, `distancia_recorrida_metros`, `finalizado_en`, `distancia_final_km`, `duracion_final_min`, `costo_final`, `costo_final_origen` y `distancia_al_destino_metros`. El estado admite el nuevo valor `reservado`.

## Eventos WebSocket nuevos o modificados

| Evento | Canal | Payload |
|---|---|---|
| `viaje.estado` (nuevo) | `viaje.{id}` | `{viaje_id, estado, conductor_id}` |
| `asignacion.ofrecida` (nuevo) | `conductor.{id}` | `{asignacion_id, viaje_id, expira_en, distancia_al_origen_metros, origen, destino, origen_direccion, destino_direccion, costo_estimado, metodo_pago}` |
| `asignacion.retirada` (nuevo) | `conductor.{id}` | `{asignacion_id, viaje_id, motivo}` (`expirada` o `cancelada`) |
| `busqueda.agotada` (nuevo) | `admin.despacho` | `{viaje_id, motivo}` |
| `ubicacion.actualizada` | también en `admin.despacho` | Agrega `registrado_en`: descartar posiciones más viejas que la última mostrada. |

Ante una reconexión del WebSocket, resincronizar el estado con `GET /viajes/{id}` (o `GET /conductor/viajes/pendientes`): los eventos perdidos no se reenvían.
