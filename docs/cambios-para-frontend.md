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
| Precio final | Recalculado con la distancia recorrida | **Siempre el precio cotizado** (`costo_final_origen = cotizado`) | Mostrar al pasajero el monto de la cotización como precio cerrado. |
| Reservas | Anticipación mínima de 30 minutos | **60 minutos** (máximo 30 días) | Ajustar la validación del selector de fecha. |
| Estado de pago | `pendiente`, `confirmado`, `rechazado` | Se agrega `reintegrado` (Mercado Pago devolvió el cobro) | Mostrarlo en el panel; no cuenta como cobrado. |

## Endpoints nuevos

| Endpoint | Uso |
|---|---|
| `POST /viajes/cotizar` | Cotización previa: `monto`, `monto_base`, `recargo {porcentaje, motivos, …}`, `distancia_km`, `duracion_minutos`, `metodo`, `cotizacion_id` y `vigente_hasta`. Enviar `reserva: true` al cotizar una reserva. |
| `POST /cliente/viajes/solicitar`, `POST /cliente/viajes/reservar` | Reemplazan a `POST /cliente/viajes`, que se mantiene. Requieren `metodo_pago`. **Recomendado:** enviar el `cotizacion_id` de la cotización que vio el pasajero; si venció (5 minutos) o ya se usó, 409 y hay que volver a cotizar. Alternativa: `monto_confirmado`, que devuelve 409 con la cotización nueva si el monto cambió. |
| `POST /admin/viajes/registrar` | Viajes telefónicos. |
| `POST /conductor/viajes/{id}/rechazar` | Rechazar una oferta. |
| `POST /admin/viajes/{id}/reasignar` | Reintentar una búsqueda agotada. |
| `GET /admin/pagos`, `PATCH /admin/pagos/{id}/conciliar` | Conciliación de cobros digitales manuales. |
| `POST /viajes/{id}/pago/mercadopago` | Cobro con Mercado Pago al finalizar: abrir `enlace_pago` o mostrarlo como QR; esperar `pago.actualizado` (o consultar el viaje). Si vence (`vigente_hasta`), volver a llamarlo. 503: ofrecer otro medio de pago. |
| `GET /admin/comprobantes[/{id}[/descargar]]` | Registro de comprobantes (solo lectura y descarga). |
| `GET /viajes/{id}/comprobante` | PDF (también llega `comprobante_url` en la respuesta del pago). |
| `GET /admin/reportes/estadisticas`, `GET /admin/reportes/exportar` | Reportes. |
| `/admin/zonas-tarifarias` | Zonas de contingencia del cotizador. |
| `GET /admin/tarifas/historial` | Historial de tarifas. |
| `GET /pagos/mercadopago/configuracion` | Clave pública de Mercado Pago y funciones habilitadas (ver abajo). |
| `GET/POST /cliente/medios-pago`, `PATCH /cliente/medios-pago/{id}/predeterminado`, `DELETE /cliente/medios-pago/{id}` | Tarjetas guardadas del pasajero. |
| `POST /cliente/viajes/{id}/pago/tarjeta` | Pagar con una tarjeta guardada (token nuevo con CVV). |
| `POST /admin/pagos/{id}/reintegrar` | Reintegrar un cobro de Mercado Pago aprobado que no se aplicó. |

Las rutas `aceptar`, `finalizar` y `ubicacion` aceptan ahora `POST` (contrato de `Hacer.txt`) y siguen aceptando `PATCH`.

## Novedades del 2026-11-15 (mapas, tarjetas guardadas y seguridad de pagos)

### Cotizaciones

| Caso | Respuesta | Acción en el frontend |
|---|---|---|
| El servicio de mapas confirma que **no hay recorrido por calles** entre los puntos (un punto en el agua, en un predio cerrado, etc.) | **422** con `errors.destino_lat` y el mensaje "No se encontró un recorrido por calles…" | Pedir al usuario que mueva el pin de origen o destino. Antes se cobraba por zonas; ahora no se inventa un precio. |
| El servicio de mapas no responde, agotó la cuota gratuita o está suspendido temporalmente | 200, como siempre, con `metodo = "zona"` o `"estimacion_lineal"` y `duracion_minutos = null` | Mostrar el precio igual (es el precio cerrado) y ocultar la duración estimada. |
| `proveedor_mapas` | Ahora puede valer `ors` (openrouteservice), `google`, `osrm` o `null` (contingencia) | Si se muestra un mapa o una ruta con datos de openrouteservice, incluir la atribución: **"© openrouteservice.org by HeiGIT · Map data © OpenStreetMap contributors"** (licencias CC-BY 4.0 y ODbL). El backend no expone ninguna clave de mapas: los frontends nunca deben llamar a ORS ni a Google con claves del backend. |

### Tarjetas guardadas (Mercado Pago)

Dos mecanismos distintos. **No son equivalentes:**

| Mecanismo | Qué permite | Disponibilidad |
|---|---|---|
| **Tarjeta guardada** | El pasajero no vuelve a cargar los datos de la tarjeta; en **cada** pago ingresa solo el CVV (Mercado Pago no guarda el CVV). | Siempre que Mercado Pago esté habilitado (`tarjetas_guardadas: true`). |
| **Cobro automático** | Se cobra solo al finalizar el viaje, sin CVV ni intervención del pasajero. | Solo si `pagos_automaticos: true`. Requiere que Mercado Pago habilite el producto "Pagos automáticos" a la cuenta (aprobación comercial). Hoy está **desactivado**. |

Flujo:

1. `GET /pagos/mercadopago/configuracion` → `{habilitado, public_key, tarjetas_guardadas, pagos_automaticos, consentimiento_version}`. Si `tarjetas_guardadas` es `false`, ocultar la opción.
2. Tokenizar en el dispositivo con la `public_key`: **MercadoPago.js / Card Payment Brick** (panel web) o el **SDK de Mercado Pago para React Native / Core Methods** (apps). **Nunca** enviar al backend el número, el vencimiento ni el CVV. El backend rechaza cualquier `token` que no sea alfanumérico.
3. `POST /cliente/medios-pago` con `{token, payment_method_id, tipo: "credit_card"|"debit_card"|"prepaid_card", acepto_guardar_tarjeta: true, predeterminado?, cobro_automatico?, autorizo_cobro_automatico?}`.
   - Mostrar antes un texto de consentimiento explícito, con versión igual a `consentimiento_version`. `acepto_guardar_tarjeta` es obligatorio. Con `cobro_automatico: true` también es obligatorio `autorizo_cobro_automatico: true`, con un texto que diga que se cobrará automáticamente el precio cerrado al finalizar cada viaje.
   - Respuestas: **201** `{data: {id, card_id, marca, tipo, ultimos_cuatro, vencimiento_mes, vencimiento_anio, emisor, predeterminado, cobro_automatico, vinculado_en}}`; **409** si la tarjeta ya está vinculada; **422** por validación, si Mercado Pago no pudo validar la tarjeta (volver a tokenizar), por el límite de 5 tarjetas o si los cobros automáticos no están habilitados; **503** si Mercado Pago no responde.
   - El token es de un solo uso: ante cualquier error, generar uno nuevo.
4. `GET /cliente/medios-pago` lista las tarjetas activas, con la predeterminada primero. `PATCH /cliente/medios-pago/{id}/predeterminado` y `DELETE /cliente/medios-pago/{id}` gestionan las propias; una tarjeta ajena devuelve **404**.

### Pagar con una tarjeta guardada (CIT)

1. Con el viaje `finalizado`, el pasajero elige la tarjeta e ingresa el **CVV** en el campo seguro de MercadoPago.js (`securityCode`). El frontend genera un token nuevo con `mp.fields.createCardToken({ cardId })`, usando el `card_id` que devuelve `GET /cliente/medios-pago`. El `card_id` no es secreto: sin el CVV y sin el access token del backend no permite cobrar.
2. `POST /cliente/viajes/{viaje}/pago/tarjeta` con `{medio_pago_id, token}`. Solo el pasajero del viaje puede hacerlo.
3. La respuesta trae el pago y `mp_status` / `mp_status_detail`:
   - `estado: "confirmado"`: aprobado. Llega `comprobante_url` y también `pago.actualizado`.
   - `estado: "pendiente"` con `mp_status: "rejected"`: rechazado. Mostrar el motivo (`mp_status_detail`, por ejemplo `cc_rejected_insufficient_amount`) y ofrecer otra tarjeta, el enlace de Mercado Pago o efectivo.
   - **409**: viaje no finalizado, ya cobrado u **otro cobro en curso** (doble toque). No reintentar en bucle: consultar `GET /viajes/{id}`.
   - **422**: token rechazado. Pedir el CVV de nuevo.
   - **503**: Mercado Pago no respondió. **Consultar el estado del pago antes de reintentar**: el cobro pudo haberse hecho.
4. Si había un enlace de Checkout Pro vigente, el backend lo vence. Un pago doble se detecta y se reintegra (ver abajo).

### Cobro automático al finalizar (solo con habilitación de Mercado Pago)

- Requiere que el viaje se haya pedido con `metodo_pago` `tarjetaCredito` o `tarjetaDebito` y que el pasajero tenga una tarjeta predeterminada con `cobro_automatico: true`.
- Al finalizar el viaje, el backend intenta el cobro **una sola vez**. El resultado llega por `pago.actualizado`:
  - `estado: "confirmado"`: listo.
  - `estado: "pendiente"`: el cobro fue rechazado o quedó en proceso. La app debe ofrecer otra forma de pago (tarjeta con CVV, enlace o efectivo).

### Reintegros

- Si el pasajero paga dos veces, o paga el enlace después de pagar en efectivo, el cobro sobrante **no se aplica** y queda como operación para reintegrar.
- Panel: `POST /admin/pagos/{pago}/reintegrar` con `{mp_operacion_id}` (el `mp_payment_id` de la entrada `mercadopago_aprobado_sin_aplicar` del historial del pago). Responde **202**: el resultado definitivo llega por webhook. Repetirlo no duplica nada. Nunca se puede reintegrar la operación que confirmó el pago (**409**).
- Con `MERCADOPAGO_REINTEGRO_AUTOMATICO=true` el backend lo hace solo. Por defecto está desactivado.

### Eventos

| Evento | Cambio |
|---|---|
| `ubicacion.actualizada` | `registrado_en` ahora incluye **milisegundos** (`2026-11-15T13:20:05.123-03:00`). Sigue siendo ISO 8601 y cualquier parser lo acepta. |
| `pago.actualizado` | También se emite cuando un cobro automático falla, aunque el estado siga `pendiente`, para que la app ofrezca otro medio. |

### Errores y reintentos (resumen)

| Código | Significado | Reintento |
|---|---|---|
| 409 en pagos | Estado incompatible o cobro en curso | No. Consultar el viaje. |
| 422 en tarjetas | Token o datos inválidos | Sí, con un token nuevo. |
| 429 | Límite de frecuencia (usuario o NGINX) | Sí, con espera exponencial. |
| 503 en Mercado Pago | El proveedor no respondió | Sí, después de consultar el estado. |

## Campos nuevos en el viaje

`metodo_pago`, `canal`, `monto_base_cotizado`, `recargo_dinamico_pct`, `recargo_dinamico_detalle`, `destino_localidad`/`destino_calle`/`destino_numero`, `precio_base_aplicado`, `precio_por_km_aplicado`, `distancia_estimada_km`, `duracion_estimada_min`, `metodo_cotizacion`, `despachado_en`, `busqueda_agotada_en`, `iniciado_en`, `distancia_recorrida_metros`, `finalizado_en`, `distancia_final_km`, `duracion_final_min`, `costo_final`, `costo_final_origen` y `distancia_al_destino_metros`. El estado admite el nuevo valor `reservado`.

## Eventos WebSocket nuevos o modificados

| Evento | Canal | Payload |
|---|---|---|
| `viaje.estado` (nuevo) | `viaje.{id}` | `{viaje_id, estado, conductor_id}` |
| `pago.actualizado` (nuevo) | `viaje.{id}` | `{viaje_id, pago_id, estado}`: el pago se creó o cambió de estado (por ejemplo, Mercado Pago lo confirmó). |
| `asignacion.ofrecida` (nuevo) | `conductor.{id}` | `{asignacion_id, viaje_id, expira_en, distancia_al_origen_metros, origen, destino, origen_direccion, destino_direccion, costo_estimado, metodo_pago}` |
| `asignacion.retirada` (nuevo) | `conductor.{id}` | `{asignacion_id, viaje_id, motivo}` (`expirada` o `cancelada`) |
| `busqueda.agotada` (nuevo) | `admin.despacho` | `{viaje_id, motivo}` |
| `ubicacion.actualizada` | también en `admin.despacho` | Agrega `registrado_en`: descartar posiciones más viejas que la última mostrada. |

Ante una reconexión del WebSocket, resincronizar el estado con `GET /viajes/{id}` (o `GET /conductor/viajes/pendientes`): los eventos perdidos no se reenvían.

## Auditoría de seguridad (2026-11-15): cambios de contrato

| Qué cambia | Antes | Ahora | Acción en el frontend |
|---|---|---|---|
| Canal `private-conductor.{id}` | Lo podía escuchar el pasajero con un viaje activo con ese conductor | **Solo el conductor y el admin** (403 para el pasajero). Al terminar el viaje, el pasajero seguía recibiendo la posición del conductor y sus ofertas, con direcciones de otros pasajeros | La app del pasajero escucha `ubicacion.actualizada` en **`private-viaje.{id}`**, que ya la recibía solo mientras el viaje está aceptado o en curso. Dejar de suscribirse a `private-conductor.{id}`. |
| `pago` dentro del viaje y en las respuestas de pago para pasajero y conductor | El pago completo, con `historial`, `observacion`, `registrado_por_persona_id`, `conciliado_por_persona_id`, `preferencia_id` y `mp_orden_id` | Vista acotada: `id, viaje_id, metodo_pago, monto, estado, referencia_externa, proveedor, enlace_pago, preferencia_expira_en, fecha_pago, created_at, updated_at` (más `comprobante_url` en las respuestas de pago). El admin sigue viendo todo | No usar campos internos en las apps. |
| `POST /auth/login` | Solo límite por IP (6 por minuto) | Además, **10 intentos fallidos por cuenta cada 15 minutos**: 429 con `Retry-After`, también para emails inexistentes | Mostrar "Demasiados intentos fallidos" y respetar `Retry-After`. |
| `POST /cliente/medios-pago` | 20 por minuto | Además, **5 intentos por hora por cuenta** (exitosos o no): 429 con `Retry-After` | Informar el límite; no reintentar automáticamente. |
| Cambio de contraseña o email de una cuenta | Los tokens seguían válidos | Se revocan **todos** los tokens de la cuenta | Ante un 401, volver al login. |
| Request a la API sin token y sin `Accept: application/json` | 500 | 401 JSON | Enviar siempre `Accept: application/json`. |
| Eventos `client-*` por WebSocket | Aceptados | Rechazados (error 4301) | No usar *whisper*. |
| `/api/documentation` en producción | Pública | 404 (`API_DOCS_PUBLICA=false`) | Usar la documentación de staging o local. |
