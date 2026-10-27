# Estado del backend

Relevamiento hecho sobre `develop` (`6eccd2d`) y trabajo de la rama `feature/backend-completion-security-hardening`. Cada estado final está respaldado por tests automatizados que corren contra MySQL 8.4.

## Tareas de `Hacer.txt`

| Tarea | Estado inicial verificado | Estado final | Tests |
|---|---|---|---|
| GET `/admin/tarifas` | Parcial: devolvía el historial, no la vigente | Completa | `TarifaAdministracionTest` |
| PUT `/admin/tarifas` | Parcial: solo POST, aceptaba 0, sin auditoría | Completa: versionado transaccional, montos > 0, auditoría, caché versionada | `TarifaAdministracionTest`, `TarifaControllerTest`, `BloqueosDeFilaTest` |
| POST `/viajes/cotizar` | Pendiente | Completa | `CotizarViajeTest` |
| Servicio de distancia con API de mapas | Pendiente (factor fijo sobre la línea recta) | Completa con OSRM / Google Routes. **Necesita credenciales o un servidor propio para operar** | `ProveedoresDeMapasTest` |
| Cotización con contingencia por zonas | Pendiente | Completa (zonas tarifarias más estimación lineal) | `CotizadorServiceTest` |
| POST `/cliente/viajes/solicitar` | Parcial: `POST /cliente/viajes` sin método de pago, sin snapshot ni despacho | Completa | `SolicitudViajeTest` |
| POST `/cliente/viajes/reservar` | Parcial: sin estado reservado; aviso masivo | Completa | `SolicitudViajeTest`, `DespacharViajesProgramadosCommandTest` |
| POST `/admin/viajes/registrar` | Pendiente | Completa | `SolicitudViajeTest` |
| Job de asignación automática | Pendiente: modelo "el primero que acepta" | Completa (ofertas, vencimiento, reasignación, agotamiento) | `AsignacionAutomaticaTest`, `BloqueosDeFilaTest` |
| POST `/conductor/viajes/{id}/aceptar` | Defectuosa: sin destinatario; filtraba SQL en 409 | Completa | `AsignacionAutomaticaTest`, `ViajeTest` |
| POST `/conductor/viajes/{id}/rechazar` | Pendiente | Completa | `AsignacionAutomaticaTest` |
| POST `/conductor/viajes/{id}/finalizar` | Defectuosa: sin geocerca, sin cálculo final | Completa (100 m con `ST_Distance_Sphere`, distancia, duración y precio final) | `FinalizarViajeTest` |
| POST `/conductor/ubicacion` | Parcial: PATCH sin Redis, sin límite de frecuencia | Completa | `UbicacionConductorTest`, `BroadcastingTest` |
| Endpoints de chat | Implementados, con exposición de datos y envío por admin | Corregidos | `MensajeControllerTest`, `ChatSeguridadTest`, `ExposicionDeDatosTest` |
| POST `/conductor/viajes/{id}/pago` | Defectuosa: monto libre, todo confirmado | Completa (importe exacto, conciliación, idempotencia) | `PagoControllerTest`, `BloqueosDeFilaTest` |
| Comprobante PDF | Pendiente | Completa (comprobante interno, no factura AFIP) | `ComprobanteTest`, `DocumentoPdfTest` |
| GET `/admin/reportes/estadisticas` | Pendiente | Completa | `ReporteTest` |
| GET `/admin/reportes/exportar` | Pendiente | Completa (PDF y XLSX) | `ReporteTest`, `LibroXlsxTest` |
| Comando de migración legacy | Pendiente | Completa (CSV); un volcado SQL requiere exportarlo antes a CSV | `ImportarLegacyCommandTest` |
| Tests y cobertura > 80 % en dominio | Parcial (122 tests, sin medición) | 307 tests; **cobertura de `app/Services`: 96,33 %** (`app/` completo: 86,23 %), medida con PCOV | — |

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

## Decisiones tomadas y pendientes de confirmación del negocio

1. **Cuenta corriente:** `Hacer.txt` y el análisis la mencionan, pero el commit `94682ac` la eliminó porque "todos los pagos se resuelven al momento del viaje". Se mantuvo fuera del dominio. Reincorporarla requiere definir qué clientes la tienen habilitada y cómo se factura.
2. **Precio final:** se cobra según el recorrido GPS (fórmula con la tarifa cotizada); con seguimiento insuficiente, lo cotizado; con cotización por zona, el monto fijo. Hay que confirmar si el cliente debe pagar lo cotizado o lo recorrido.
3. **Zonas de contingencia:** se modelaron como bandas de distancia en línea recta con monto fijo. Hay que confirmar si la remisería usa zonas geográficas (polígonos o barrios).
4. **Pesos del algoritmo de asignación,** tiempos de aceptación, radios, anticipación de reservas y límites de frecuencia: son valores iniciales configurables, no reglas documentadas.
5. **Búsqueda agotada:** el viaje queda solicitado y se avisa al administrador; no se cancela automáticamente.
6. Un conductor sin GPS reciente no recibe ofertas automáticas.

## Pendiente o externo

- Credenciales y servidor de mapas para producción (OSRM propio o Google Routes).
- Integración con un proveedor de pagos y facturación electrónica (AFIP): fuera del alcance actual.
- Adaptación de los frontends ([cambios-para-frontend.md](cambios-para-frontend.md)).
- Pruebas de carga (RNF01: 200 conductores simultáneos) y pruebas con Reverb y Redis reales en un entorno de staging.
