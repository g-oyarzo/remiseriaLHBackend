# Estado del backend

Relevamiento hecho sobre `develop` (`6eccd2d`) y trabajo de la rama `feature/backend-completion-security-hardening`. Cada estado final está respaldado por tests automatizados que corren contra MySQL 8.4.

## Tareas de `Hacer.txt`

| Tarea | Estado inicial verificado | Estado final | Tests |
|---|---|---|---|
| GET `/admin/tarifas` | Parcial: devolvía el historial, no la vigente | Completa | `TarifaAdministracionTest` |
| PUT `/admin/tarifas` | Parcial: solo POST, aceptaba 0, sin auditoría | Completa: versionado transaccional, montos > 0, auditoría, caché versionada | `TarifaAdministracionTest`, `TarifaControllerTest`, `BloqueosDeFilaTest` |
| POST `/viajes/cotizar` | Pendiente | Completa: precio cerrado con `cotizacion_id` y recargo dinámico por demanda y lluvia | `CotizarViajeTest`, `CotizacionEmitidaTest`, `RecargoDinamicoTest` |
| Servicio de distancia con API de mapas | Pendiente (factor fijo sobre la línea recta) | Completa con OSRM / Google Routes. **Necesita credenciales o un servidor propio para operar** | `ProveedoresDeMapasTest` |
| Cotización con contingencia por zonas | Pendiente | Completa (zonas tarifarias más estimación lineal) | `CotizadorServiceTest` |
| POST `/cliente/viajes/solicitar` | Parcial: `POST /cliente/viajes` sin método de pago, sin snapshot ni despacho | Completa | `SolicitudViajeTest` |
| POST `/cliente/viajes/reservar` | Parcial: sin estado reservado; aviso masivo | Completa | `SolicitudViajeTest`, `DespacharViajesProgramadosCommandTest` |
| POST `/admin/viajes/registrar` | Pendiente | Completa | `SolicitudViajeTest` |
| Job de asignación automática | Pendiente: modelo "el primero que acepta" | Completa (ofertas de 30 s, radio de 10 km ampliable hasta 20 km, vencimiento, reasignación, agotamiento) | `AsignacionAutomaticaTest`, `BloqueosDeFilaTest` |
| POST `/conductor/viajes/{id}/aceptar` | Defectuosa: sin destinatario; filtraba SQL en 409 | Completa | `AsignacionAutomaticaTest`, `ViajeTest` |
| POST `/conductor/viajes/{id}/rechazar` | Pendiente | Completa | `AsignacionAutomaticaTest` |
| POST `/conductor/viajes/{id}/finalizar` | Defectuosa: sin geocerca, sin cálculo final | Completa (100 m con `ST_Distance_Sphere`; registra distancia y duración; cobra el precio cerrado cotizado) | `FinalizarViajeTest` |
| POST `/conductor/ubicacion` | Parcial: PATCH sin Redis, sin límite de frecuencia | Completa | `UbicacionConductorTest`, `BroadcastingTest` |
| Endpoints de chat | Implementados, con exposición de datos y envío por admin | Corregidos | `MensajeControllerTest`, `ChatSeguridadTest`, `ExposicionDeDatosTest` |
| POST `/conductor/viajes/{id}/pago` | Defectuosa: monto libre, todo confirmado | Completa (importe exacto, conciliación, idempotencia) más cobro con Mercado Pago confirmado por webhook | `PagoControllerTest`, `MercadoPagoTest`, `BloqueosDeFilaTest` |
| Comprobante PDF | Pendiente | Completa: comprobante interno emitido al confirmarse el pago, registro inmutable con SHA-256 y consulta de solo lectura para la administración (no es factura AFIP) | `ComprobanteTest`, `RegistroComprobantesTest`, `DocumentoPdfTest` |
| GET `/admin/reportes/estadisticas` | Pendiente | Completa | `ReporteTest` |
| GET `/admin/reportes/exportar` | Pendiente | Completa (PDF y XLSX) | `ReporteTest`, `LibroXlsxTest` |
| Comando de migración legacy | Pendiente | Completa (CSV); un volcado SQL requiere exportarlo antes a CSV | `ImportarLegacyCommandTest` |
| Tests y cobertura > 80 % en dominio | Parcial (122 tests, sin medición) | 363 tests; **cobertura de `app/Services`: 96,39 %** (`app/` completo: 87,63 %), medida con PCOV el 2026-11-04 | — |

## Tareas de `Hecho.txt` (verificación)

| Tarea | Resultado |
|---|---|
| Login, logout, refresh-token | Correctas. Se agregó el bloqueo de conductores dados de baja. |
| Middleware de roles | Correcto. |
| Contrato OpenAPI | **No se podía generar** (`MarcaSchema` declarada dentro de otro archivo). Corregido; los endpoints nuevos están documentados. |
| WebSockets (Reverb/Redis) | Correcto; las difusiones ahora son tolerantes a fallas de Reverb. |
| Rutas API y Sanctum | Correctas. |
| Migraciones del DER | **Defectuosas:** coordenadas guardadas con los ejes invertidos (corregido con migración de datos), y el *rollback* de `add_receptor_index_to_mensajes_table` fallaba (corregido). |
| CRUD de conductores (alta, listado, modificación, baja) | Correctos. Se agregó la revocación de tokens en la baja. |

## Hallazgos de la auditoría (corregidos)

| Severidad | Hallazgo |
|---|---|
| Alta | Coordenadas con ejes invertidos (SRID 4326 en orden lat-long): distancias erróneas en la búsqueda de conductores. |
| Alta | Exposición de DNI, teléfono, CUIL, domicilio, fecha de nacimiento y GPS de la contraparte en viajes y chat. |
| Alta | Pago con monto libre y cobros digitales dados por confirmados sin verificación. |
| Alta | `catch (\RuntimeException)` capturaba `QueryException` y devolvía el SQL en respuestas 409 (aceptar, pago, cancelar). |
| Alta | `symfony/http-foundation` con CVE-2026-108082 (ReDoS por header). |
| Media | Sin límites de frecuencia en GPS, chat y pagos; `per_page` sin tope. |
| Media | Tokens de conductores dados de baja seguían válidos. |
| Media | Carreras: `iniciar` y `cancelar` sin bloqueo (un viaje cancelado podía pasar a en curso); doble calificación; caché de tarifa que podía quedar vieja tras un cambio. |
| Media | El cliente podía cancelar un viaje en curso (viaje sin cobro). |
| Media | Un administrador podía escribir en el chat cliente-conductor. |
| Baja | `DELETE /admin/vehiculos/{id}` respondía 500; dinero calculado con `float`; la suite dependía de un `.env` local. |

## Decisiones del negocio

| # | Tema | Decisión | Implementación |
|---|---|---|---|
| 1 | Cuenta corriente corporativa | No existe: todo se cobra al momento del viaje. | Sin cambios: el commit `94682ac` ya la había eliminado. |
| 2 | Qué paga el pasajero | Siempre lo cotizado, como precio cerrado. El precio puede variar según la demanda y el clima. | `costo_final = costo` (`cotizado`); cotizaciones con `cotizacion_id` y vigencia de 5 min; recargo dinámico por demanda y lluvia configurable por tarifa (0 % por defecto). |
| 3 | Zonas de contingencia | Bandas de distancia en línea recta con monto fijo. | Sin cambios. |
| 4 | Proveedor de mapas | Se evalúa un OSRM propio; queda para el final. | El driver OSRM ya existe (`MAPAS_DRIVER=osrm`, `MAPAS_OSRM_URL`). Pendiente: definir dónde y cómo se aloja. |
| 5 | Pagos digitales | Integrar un proveedor (Mercado Pago) con confirmación automática por webhooks. | Checkout Pro + webhook firmado + conciliación automática cada 10 minutos. |
| 6 | Facturación electrónica | Por ahora, comprobante interno; la administración ve y descarga el registro, sin modificarlo ni borrarlo. | Tabla `comprobantes` inmutable, archivo con SHA-256 y endpoints de solo lectura. |
| 7 | Parámetros operativos | Oferta de 30 s; radio de 10 km con expansión si no hay conductores; reservas entre 60 min y 30 días; pesos: distancia, calificación, antigüedad y carga del día; si nadie acepta se avisa al admin y el viaje no se cancela solo. | Valores por defecto en `config/remiseria.php`; expansión de 2,5 km por reintento hasta 20 km. |

Otros criterios vigentes: un conductor sin GPS reciente no recibe ofertas automáticas.

## Pendiente o externo

- **Mercado Pago:** cargar credenciales de prueba y validar en el sandbox el flujo completo, en especial **la firma del webhook** (el formato se implementó según la documentación oficial, pero no se pudo probar contra notificaciones reales) y que las notificaciones lleguen con la `notification_url` de la preferencia. Después, credenciales productivas.
- **Clima:** la API pública de Open-Meteo es solo para uso no comercial. Para usar el recargo por lluvia en producción hay que contratar un plan (`CLIMA_OPENMETEO_URL` y `CLIMA_OPENMETEO_API_KEY`) o dejar `CLIMA_DRIVER=ninguno`.
- **Mapas (decisión 4):** servidor OSRM propio o credenciales de Google Routes para producción.
- Facturación electrónica (AFIP): fuera del alcance actual.
- Adaptación de los frontends ([cambios-para-frontend.md](cambios-para-frontend.md)).
- Pruebas de carga (RNF01: 200 conductores simultáneos) y pruebas con Reverb y Redis reales en un entorno de staging.
