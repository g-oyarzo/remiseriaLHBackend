# Arquitectura y flujos de negocio

## Organización del código

| Capa | Ubicación | Responsabilidad |
|---|---|---|
| Rutas | `routes/api.php`, `routes/channels.php`, `routes/console.php` | Endpoints con middleware de rol y de límite de frecuencia; canales privados; scheduler. |
| Controladores | `app/Http/Controllers/Api/V1` | Autorización de entrada, delegación a servicios y armado de respuestas. |
| Validación | `app/Http/Requests` | Form Requests: coordenadas, montos y fechas. |
| Serialización | `app/Http/Resources` | Lista blanca de datos personales según quién consulta. |
| Autorización | `app/Policies/ViajePolicy`, `app/Http/Middleware/CheckRole` | Rol y propiedad del recurso (IDOR/BOLA). |
| Dominio | `app/Services/*` | Cotización, asignación, geolocalización, finalización, pagos, comprobantes, reportes y migración legacy. |
| Asincronía | `app/Jobs`, `app/Listeners`, `app/Events`, `app/Console/Commands` | Asignación, vencimientos, despacho de reservas y difusión. |
| Configuración | `config/remiseria.php`, `config/services.php` (`mapas`, `clima`, `mercadopago`) | Reglas operativas parametrizables e integraciones externas. |

Los montos se calculan en centavos enteros (`App\Support\Dinero`) y se guardan en `DECIMAL(10,2)`. Las coordenadas usan `POINT SRID 4326`: el WKT se escribe siempre con `axis-order=long-lat` (ver `App\Casts\PointCast::SQL_PUNTO_DESDE_WKT`).

## Estados del viaje

```
                 reservar                 ventana de despacho
 (cliente/admin) ───────► reservado ─────────────────────────┐
                              │ cancelar                     ▼
 solicitar ──────────────────────────────────────────► solicitado ──(asignación automática)──┐
                              ▼                              │ cancelar                     │ aceptar
                          cancelado ◄────────────────────────┘                              ▼
                              ▲                                                         aceptado
                              │ cancelar (cliente o admin)                                  │ iniciar
                              ├─────────────────────────────────────────────────────────────┤
                              │ cancelar (solo admin)                                       ▼
                              └──────────────────────────────────────────────────────── en_curso
                                                                                            │ finalizar (geocerca 100 m)
                                                                                            ▼
                                                                                       finalizado
```

Cada transición se hace dentro de una transacción con `SELECT … FOR UPDATE` sobre el viaje, y se vuelve a chequear el estado ya bloqueado. `ViajeCambioEstado` se difunde en `viaje.{id}` después del commit.

## Cotización (CU 02)

1. `CotizadorService` valida el trayecto: origen distinto del destino (más de 50 m) y menos de 300 km.
2. Toma la tarifa vigente. Si no hay ninguna, responde 409.
3. Pide la distancia de manejo al proveedor (`MAPAS_DRIVER`): OSRM (instancia propia o pública) o Google Routes API. El timeout es corto (`MAPAS_TIMEOUT_SEGUNDOS`), hay un reintento ante fallas de conexión, una caché por par de coordenadas y validación de la respuesta.
4. Monto base = tarifa base + km × precio por km, en centavos.
5. **Recargo dinámico** (`RecargoDinamico`), configurado en cada versión de la tarifa:
   - *Demanda:* solicitudes sin conductor cerca del origen en los últimos 30 minutos ÷ conductores disponibles con GPS vigente en el radio de búsqueda. Si el cociente alcanza `demanda_umbral_ratio`, se suma `demanda_recargo_pct`.
   - *Clima:* si la precipitación actual en el origen alcanza `lluvia_umbral_mm`, se suma `lluvia_recargo_pct`. La consulta usa Open-Meteo (`CLIMA_DRIVER=openmeteo`), con caché por zona y timeout corto; si falla, no hay recargo por lluvia.
   - Ambos se combinan de forma multiplicativa y se acotan con `recargo_maximo_pct`. Las reservas no llevan recargo: la demanda y el clima de hoy no son los del día del viaje.
   - La respuesta informa `monto_base` y `recargo` (porcentaje y motivos). Con los porcentajes en 0 (valor por defecto) no hay recargo.
6. **Contingencia.** Si el proveedor falla, no responde o no está configurado, se usa el monto fijo de la **zona tarifaria** más chica que cubra la distancia en línea recta (`/admin/zonas-tarifarias`). Si ninguna la cubre, se aplica la fórmula sobre la distancia lineal × `DISTANCIA_FACTOR_CORRECCION`. El método usado queda en `metodo` / `metodo_cotizacion`.

**Precio cerrado.** Cada cotización se guarda en `cotizaciones` (UUID) con una vigencia de `COTIZACION_VIGENCIA_MINUTOS` (5) y se devuelve como `cotizacion_id` + `vigente_hasta`. Al solicitar o reservar con ese id, el viaje se crea exactamente con el monto cotizado, sin volver a calcular. La cotización es de un solo uso, pertenece a la cuenta que la pidió, debe coincidir en tipo (inmediato o reserva) y en origen y destino (tolerancia de 15 m), y vencida o usada responde 409. Las cotizaciones vencidas se eliminan a los 7 días (`model:prune`, diario). Sin `cotizacion_id` se cotiza en el momento, y `monto_confirmado` sigue disponible como alternativa.

El viaje guarda un *snapshot* de la cotización: `precio_base_aplicado`, `precio_por_km_aplicado`, `monto_base_cotizado`, `recargo_dinamico_pct`, `recargo_dinamico_detalle`, `distancia_estimada_km`, `duracion_estimada_min`, `metodo_cotizacion` y `zona_tarifaria_id`. Las tarifas tienen versiones (cada cambio crea una fila nueva), así que un cambio posterior no altera viajes ya cotizados.

## Solicitudes y reservas (CU 01, CU 09, CU 10)

- La cotización se calcula fuera de la transacción, para no retenerla mientras se espera al proveedor de mapas. Después se crea el viaje.
- **Solicitud inmediata:** queda en estado `solicitado` y se emite `ViajeListoParaDespacho` (*after commit*). El listener `IniciarBusquedaDeConductor` encola `AsignarConductorJob`.
- **Reserva:** entre `RESERVA_ANTICIPACION_MINIMA_MINUTOS` (60) y `RESERVA_ANTICIPACION_MAXIMA_DIAS` (30) de anticipación. Queda en estado `reservado`. Se encola `DespacharReservaJob` (*after commit*) con demora hasta `fecha_viaje - VENTANA_DESPACHO_PROGRAMADOS_MINUTOS`. El comando `viajes:despachar-programados` es la red de seguridad. La transición `reservado → solicitado` es idempotente.
- **Registro telefónico:** si el DNI no existe, se crea Persona + Cliente sin cuenta. Si existe, se reutiliza siempre que el nombre coincida; si no coincide, responde 422, para no asociar el viaje a otra persona.

## Asignación automática de conductores (CU 03, CU 05)

1. **Candidatos:** conductores activos, en servicio, con vehículo, sin viaje aceptado o en curso y sin otra oferta pendiente, a los que todavía no se les ofreció este viaje, con su último ping GPS hace menos de `ASIGNACION_UBICACION_VIGENCIA_SEGUNDOS` y dentro del radio de búsqueda del origen (`ST_Distance_Sphere`). El radio arranca en `ASIGNACION_RADIO_BUSQUEDA_METROS` (10 km) y, cada vez que una búsqueda no encuentra candidatos, se amplía `ASIGNACION_RADIO_EXPANSION_PASO_METROS` (2,5 km) hasta `ASIGNACION_RADIO_MAXIMO_METROS` (20 km).
2. **Puntaje** (menor es mejor): `km × peso_distancia − calificación × peso_calificación − min(antigüedad, 10 años) × peso_antigüedad + viajes de hoy × peso_carga`.
3. **Oferta:** dura `ASIGNACION_TIEMPO_ACEPTACION_SEGUNDOS` (30 s). Se bloquea el viaje y luego el conductor elegido, y se revalida su disponibilidad. Se crea `asignaciones_viaje` en estado `pendiente` con `expira_en` y se emite `asignacion.ofrecida` en `conductor.{id}`. Además se encola `ExpirarAsignacionJob`.
4. **Respuesta:** `aceptar` asigna el conductor y el vehículo y pasa el viaje a `aceptado`. `rechazar` o el vencimiento marcan la oferta y reencolan la búsqueda.
5. **Límites:** nunca se ofrece dos veces el mismo viaje al mismo conductor. Después de `ASIGNACION_MAX_OFERTAS` ofertas, o de `ASIGNACION_MAX_REINTENTOS` reintentos sin candidatos (cada `ASIGNACION_REINTENTO_SEGUNDOS`), la búsqueda queda **agotada**: el viaje sigue `solicitado`, se avisa en `admin.despacho` y un admin puede reiniciarla con `/admin/viajes/{id}/reasignar`.
6. **Red de seguridad:** `viajes:supervisar-despacho` vence las ofertas cuyo job se perdió y reactiva las búsquedas detenidas.

Garantías en la base de datos: `UNIQUE(viaje_id, conductor_id)` y columnas generadas con `UNIQUE` que permiten una sola oferta pendiente por viaje y por conductor. Orden de bloqueo: viaje → oferta → conductor.

**Contingencia sin GPS:** un conductor sin ubicación reciente no es candidato, porque no hay forma de saber si sigue cerca. Si ningún conductor tiene GPS vigente, la búsqueda reintenta y termina agotada, y el despacho manual queda en manos del administrador.

## Geolocalización (RNF02)

- `POST /conductor/ubicacion` guarda el último punto en Redis (`UBICACION_CACHE_STORE`) y lo persiste en MySQL como máximo cada `UBICACION_PERSISTIR_CADA_SEGUNDOS`, para la búsqueda espacial. Si Redis falla, se usa MySQL directamente.
- Rechaza con 422 los saltos imposibles (velocidad implícita mayor que `UBICACION_VELOCIDAD_MAXIMA_KMH`).
- Durante un viaje en curso, suma la distancia entre pings a `distancia_recorrida_metros`, descartando el ruido (menos de `UBICACION_SALTO_MINIMO_METROS`) y los pings imprecisos.
- Difunde `ubicacion.actualizada` con `registrado_en`, para que los clientes descarten posiciones viejas.

**Límite de confianza:** las coordenadas las informa el dispositivo del conductor. Las validaciones descartan errores groseros, pero no pueden detectar una falsificación consistente del GPS (por ejemplo, una app de *mock location* que simule un recorrido verosímil).

## Finalización (CU 06)

- Solo el conductor asignado, con el viaje en curso. Si envía `lat`/`lng`, se registran antes como un ping.
- La última posición registrada debe tener menos de `FINALIZACION_UBICACION_VIGENCIA_SEGUNDOS` y estar a no más de `FINALIZACION_RADIO_TOLERANCIA_METROS` (100 m) del destino, medido con `ST_Distance_Sphere` en MySQL.
- **Precio final: siempre lo cotizado** (decisión del negocio: precio cerrado). `costo_final = costo` con `costo_final_origen = cotizado`. La distancia recorrida por GPS y la duración se registran igual (`distancia_final_km`, `duracion_final_min`) para reportes y auditoría, pero no cambian el importe.
- Los orígenes `recorrido`, `estimado` y `zona` quedan solo para viajes finalizados antes de este cambio.

## Pagos (CU 07) y comprobantes (CU 17)

```
registrar ──► efectivo ───────────────────────────────────────────────► confirmado
          └─► qr / tarjeta (cobrado fuera de Mercado Pago) ──► pendiente ──conciliar (admin)──► confirmado
                                                                     └───────────────────────► rechazado ──registrar de nuevo──► …
mercadopago ─► pendiente (enlace Checkout Pro) ──webhook o conciliación automática──► confirmado ──reintegro/contracargo──► reintegrado
```

- El monto debe coincidir al centavo con `costo_final` (o con `costo` en viajes finalizados antes de existir ese cálculo).
- **Todo se cobra al momento del viaje.** No hay cuenta corriente (decisión del negocio, ver [estado-backend.md](estado-backend.md)).
- **Mercado Pago (Checkout Pro).** `POST /viajes/{id}/pago/mercadopago` (cliente, conductor asignado o admin) crea un pago `pendiente` con `proveedor = mercadopago` y una preferencia por el importe exacto, con `external_reference = viaje-{id}-pago-{id}`, `binary_mode` y vencimiento (`MERCADOPAGO_PREFERENCIA_VIGENCIA_MINUTOS`). El enlace se muestra al pasajero como link o QR; si sigue vigente se reutiliza.
- **Confirmación automática.** `POST /webhooks/mercadopago` valida la firma `x-signature` y **no confía en el cuerpo**: consulta el pago en la API de Mercado Pago y confirma solo si la referencia, la moneda (ARS) y el importe coinciden exactamente. El medio real (`tarjetaCredito`, `tarjetaDebito` o `qr` para dinero en cuenta) y el id de la operación quedan en `metodo_pago` y `referencia_externa`. Cada (operación, estado) se aplica una sola vez. El comando `pagos:conciliar-mercadopago` (cada 10 minutos) busca las operaciones de los cobros pendientes por si un webhook se perdió.
- **Anomalías.** Un importe o moneda distintos, o un pago aprobado para un viaje que ya se cobró por otro medio, **no modifican el pago**: quedan en el `historial` y en un log `critical` para que la administración reintegre o revise. Un pago rechazado por Mercado Pago deja el cobro pendiente para reintentar con el mismo enlace. Un reintegro o contracargo pasa el pago a `reintegrado`.
- Un cobro de Mercado Pago pendiente puede reemplazarse con un registro manual (por ejemplo, el pasajero pagó en efectivo). Los cobros de Mercado Pago no se concilian a mano.
- QR y tarjetas cobrados fuera de Mercado Pago siguen el circuito anterior: quedan pendientes con su `referencia_externa` (única por método) hasta que un administrador los concilia.
- Idempotencia: `UNIQUE(viaje_id)` más el bloqueo del viaje. Un reintento idéntico devuelve el pago existente; uno distinto devuelve 409.
- `historial` registra cada transición (registro, conciliación, reintento, novedades de Mercado Pago) con persona o id de operación y fecha. Cada cambio de estado se difunde como `pago.actualizado` en `viaje.{id}`.
- **Comprobantes internos (registro inmutable).** Al confirmarse un pago, por cualquier vía, se emite el comprobante (`PagoConfirmado` → `EmitirComprobante`, en cola). El PDF se guarda en el disco `comprobantes` con número correlativo (`%08d` del pago) y su SHA-256, y no se puede modificar ni borrar (el modelo lo impide y las claves foráneas son `restrict`). Cada descarga verifica el hash. La administración consulta y descarga el registro en `/admin/comprobantes` (solo lectura). No incluye DNI, teléfono ni domicilio, y no es una factura electrónica de AFIP.

## Reportes (CU 15, CU 16)

Agregados SQL sobre los viajes filtrados por `fecha_viaje`. La facturación suma solo pagos confirmados; los reintegrados se informan aparte (`reintegros`). Sin fechas se toman los últimos 30 días; el rango máximo es `REPORTES_RANGO_MAXIMO_DIAS`. La exportación PDF o Excel tiene un tope de `REPORTES_MAX_FILAS_EXPORTACION` filas.

## Tiempo real

Las difusiones implementan `App\Events\Contracts\DifusionEnTiempoReal`:

- Se emiten **después del commit**.
- Son **rescatables**: si Reverb o la cola fallan, se registra el error y la operación no se revierte.
- Se encolan, así que necesitan un worker. Con `DIFUSION_CONEXION_COLA` se las puede enviar a una conexión dedicada.

Para canales y eventos, ver [api.md](api.md#canales-y-eventos-websocket-reverb).

## Migración de datos legacy

```bash
php artisan legacy:importar clientes.csv --tipo=clientes --dry-run   # validar primero
php artisan legacy:importar clientes.csv --tipo=clientes
php artisan legacy:importar choferes.csv --tipo=conductores --delimitador=";" --codificacion=Windows-1252
```

| Tipo | Columnas obligatorias | Opcionales |
|---|---|---|
| clientes | `dni`, `nombre`, `apellido` | `telefono` |
| conductores | `dni`, `nombre`, `apellido`, `cuil`, `fecha_nacimiento`, `domicilio_localidad`, `domicilio_calle`, `domicilio_numero` | `telefono` |

- Normaliza y valida: DNI de 7 u 8 dígitos; CUIL con dígito verificador que corresponda al DNI; mayoría de edad; fechas en `d/m/Y`, `Y-m-d`, `d-m-Y` o `d/m/y`.
- Nunca sobrescribe datos: las filas con DNI o CUIL ya existentes se omiten, así que se puede repetir o reanudar (`--desde-fila`).
- Los errores se registran por fila en `storage/logs/importacion-legacy-AAAA-MM-DD.log`, con el DNI enmascarado.
- No se crean cuentas de acceso. Los conductores importados quedan fuera de servicio y sin vehículo, hasta que el administrador complete el alta (`PUT /admin/conductores/{id}` con `password` y vehículo).
- Si el sistema anterior entrega un volcado SQL, hay que exportar cada tabla a CSV antes de importar.
