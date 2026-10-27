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
| Configuración | `config/remiseria.php`, `config/services.php` (`mapas`) | Reglas operativas parametrizables. |

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
4. Monto = tarifa base + km × precio por km, en centavos.
5. **Contingencia.** Si el proveedor falla, no responde o no está configurado, se usa el monto fijo de la **zona tarifaria** más chica que cubra la distancia en línea recta (`/admin/zonas-tarifarias`). Si ninguna la cubre, se aplica la fórmula sobre la distancia lineal × `DISTANCIA_FACTOR_CORRECCION`. El método usado queda en `metodo` / `metodo_cotizacion`.

El viaje guarda un *snapshot* de la cotización: `precio_base_aplicado`, `precio_por_km_aplicado`, `distancia_estimada_km`, `duracion_estimada_min`, `metodo_cotizacion` y `zona_tarifaria_id`. Las tarifas tienen versiones (cada cambio crea una fila nueva), así que un cambio posterior no altera viajes ya cotizados. Con `monto_confirmado`, el cliente puede exigir que el monto no haya cambiado desde que lo vio.

## Solicitudes y reservas (CU 01, CU 09, CU 10)

- La cotización se calcula fuera de la transacción, para no retenerla mientras se espera al proveedor de mapas. Después se crea el viaje.
- **Solicitud inmediata:** queda en estado `solicitado` y se emite `ViajeListoParaDespacho` (*after commit*). El listener `IniciarBusquedaDeConductor` encola `AsignarConductorJob`.
- **Reserva:** queda en estado `reservado`. Se encola `DespacharReservaJob` (*after commit*) con demora hasta `fecha_viaje - VENTANA_DESPACHO_PROGRAMADOS_MINUTOS`. El comando `viajes:despachar-programados` es la red de seguridad. La transición `reservado → solicitado` es idempotente.
- **Registro telefónico:** si el DNI no existe, se crea Persona + Cliente sin cuenta. Si existe, se reutiliza siempre que el nombre coincida; si no coincide, responde 422, para no asociar el viaje a otra persona.

## Asignación automática de conductores (CU 03, CU 05)

1. **Candidatos:** conductores activos, en servicio, con vehículo, sin viaje aceptado o en curso y sin otra oferta pendiente, a los que todavía no se les ofreció este viaje, con su último ping GPS hace menos de `ASIGNACION_UBICACION_VIGENCIA_SEGUNDOS` y dentro de `ASIGNACION_RADIO_BUSQUEDA_METROS` del origen (`ST_Distance_Sphere`).
2. **Puntaje** (menor es mejor): `km × peso_distancia − calificación × peso_calificación − min(antigüedad, 10 años) × peso_antigüedad + viajes de hoy × peso_carga`.
3. **Oferta:** se bloquea el viaje y luego el conductor elegido, y se revalida su disponibilidad. Se crea `asignaciones_viaje` en estado `pendiente` con `expira_en` y se emite `asignacion.ofrecida` en `conductor.{id}`. Además se encola `ExpirarAsignacionJob`.
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
- **Precio final** (`costo_final`), según `costo_final_origen`:
  - `recorrido`: tarifa del snapshot × distancia seguida por GPS, si hay al menos `FINALIZACION_MINIMO_PUNTOS_SEGUIMIENTO` tramos.
  - `estimado`: se cobra lo cotizado cuando el seguimiento fue insuficiente.
  - `zona`: el viaje se cotizó con monto fijo de zona y se respeta ese monto.

## Pagos (CU 07) y comprobantes (CU 17)

```
registrar ──► efectivo ───────────────────────────────► confirmado
          └─► qr / tarjetaDebito / tarjetaCredito ─────► pendiente ──conciliar──► confirmado
                                                              │                └► rechazado ──registrar de nuevo──► …
```

- El monto debe coincidir al centavo con `costo_final` (o con `costo` en viajes finalizados antes de existir ese cálculo).
- **No hay integración con un proveedor de pagos.** Un cobro digital nunca se da por confirmado automáticamente: queda pendiente con su `referencia_externa` (única por método) hasta que un administrador lo verifica y lo concilia.
- Idempotencia: `UNIQUE(viaje_id)` más el bloqueo del viaje. Un reintento idéntico devuelve el pago existente; uno distinto devuelve 409.
- `historial` registra cada transición (registro, conciliación, reintento) con persona y fecha.
- **Cuenta corriente:** fuera del dominio por decisión del commit `94682ac`, aunque `Hacer.txt` y el documento de análisis la mencionen. Ver [estado-backend.md](estado-backend.md).
- **Comprobante PDF:** se genera al vuelo con los datos históricos, sin DNI, teléfono ni domicilio y con la referencia enmascarada. Es un comprobante interno, no una factura electrónica de AFIP.

## Reportes (CU 15, CU 16)

Agregados SQL sobre los viajes filtrados por `fecha_viaje`. La facturación suma solo pagos confirmados. Sin fechas se toman los últimos 30 días; el rango máximo es `REPORTES_RANGO_MAXIMO_DIAS`. La exportación PDF o Excel tiene un tope de `REPORTES_MAX_FILAS_EXPORTACION` filas.

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
