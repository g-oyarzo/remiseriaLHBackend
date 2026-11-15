# Arquitectura y costos — Remisería LH

Estrategia **Free Tier First**: arrancar sin presupuesto, con servicios gratuitos y sin quedar atado a ninguno. Fecha de las fuentes: **15 de noviembre de 2026**. Los precios y cupos de terceros cambian: volver a verificarlos antes de cada decisión.

> Convención: **[oficial]** = dato tomado de la documentación del proveedor (enlace en la tabla de fuentes). **[estimación]** = cálculo propio con los supuestos indicados. **[no verificado]** = no se pudo confirmar en una fuente oficial; no usar para presupuestar sin revisarlo.

## 1. Arquitectura inicial (gratuita)

```
                    Internet (HTTPS 443 / HTTP 80 → redirección)
                                   │
        ┌──────────────── VM Oracle Cloud Ampere A1 (arm64) ───────────────┐
        │  ┌───────┐   FastCGI  ┌──────────┐                                │
        │  │ NGINX │──────────▶│ app      │ PHP-FPM (Laravel 13, PHP 8.5)   │
        │  │ TLS   │   WS       └──────────┘                                │
        │  │       │──────────▶┌──────────┐  ┌───────┐  ┌───────────┐      │
        │  └───────┘           │ reverb   │  │ queue │  │ scheduler │      │
        │      ▲               └────┬─────┘  └───┬───┘  └─────┬─────┘      │
        │      │ red interna 172.30.0.0/24 (sin puertos publicados)        │
        │      │               ┌────▼─────┐  ┌───▼───┐                     │
        │      │               │  redis   │  │ mysql │  volúmenes Docker   │
        │      │               └──────────┘  └───────┘                     │
        └──────┼──────────────────────────────────────────────────────────┘
               │                     │ salida HTTPS
   apps / panel web          openrouteservice (mapas) · Mercado Pago · Object Storage (backups)
```

- Un solo `compose.prod.yaml` con NGINX, PHP-FPM, Reverb, worker de colas, scheduler, MySQL 8.4 y Redis 7.4. Solo NGINX publica puertos.
- Todas las imágenes tienen variante **linux/arm64 nativa** (sección 4). No se usa emulación.
- **OSRM no se instala** en la VM gratuita. El ruteo se hace con openrouteservice (sección 3) y, si falla o se agota la cuota, con la contingencia por zonas.
- Cada servicio encuentra a los demás por variables de entorno (`DB_HOST`, `REDIS_HOST`, `REVERB_HOST`, `MAPAS_DRIVER`, …). Separar un componente es cambiar el `.env`, no el código (sección 7).

## 2. Proveedor de infraestructura

### 2.1 Oracle Cloud Infrastructure — Always Free (elegido)

| Recurso | Cupo Always Free [oficial] | Uso previsto |
|---|---|---|
| Cómputo Ampere A1 (arm64) | 1.500 OCPU-hora y 9.000 GB-hora por mes, equivalentes a **2 OCPU y 12 GB de RAM** (en 1 o 2 VM) | 1 VM con todo el stack |
| Almacenamiento en bloques | **200 GB** en total (incluye los volúmenes de arranque, de 47 a 50 GB cada uno) y 5 backups de volumen | Boot de 50 GB + volumen de datos |
| Object Storage | **20 GB** (cuenta solo Always Free) y 50.000 solicitudes de API por mes | Backups cifrados |
| Tráfico saliente | **10 TB por mes** | Sobra |

Condiciones y riesgos [oficial]:

- Los recursos Always Free deben crearse en la **región de origen** (*home region*) de la cuenta. Si se crean fuera, se cobran.
- **Capacidad:** el error *out of host capacity* es frecuente. Se puede probar con otro *availability domain* o reintentar más tarde. No hay garantía de conseguir la VM el día que se necesita.
- **Recuperación de instancias ociosas:** Oracle puede reclamar una VM Always Free si durante 7 días el percentil 95 de CPU, el uso de red y el de memoria (solo A1) quedan por debajo del 20 %. Un proyecto universitario con poco tráfico **puede** caer en ese criterio. Mitigaciones:
  - backups fuera de la VM (Object Storage) y despliegue reproducible con este repo, para recrear la VM en menos de una hora;
  - pasar la cuenta a *Pay As You Go* (sigue siendo gratis dentro de los cupos, pero exige un medio de pago: **requiere autorización**).
  - No se recomienda generar carga artificial para evitar la recuperación.
- **Créditos de prueba:** la cuenta nueva trae créditos por 30 días. **No depender de ellos**: todo lo de esta arquitectura entra en los cupos Always Free.
- La IP pública efímera puede cambiar si se recrea la instancia: reservar una IP pública (gratuita mientras esté asignada, según los cupos vigentes, [no verificado]) o usar DNS con TTL corto.

### 2.2 Alternativas evaluadas

| Opción | Por qué no es la primera elección |
|---|---|
| Google Cloud Free Tier (`e2-micro`) | 2 vCPU compartidas en ráfaga y **1 GB de RAM**, 30 GB de disco y 1 GB de egreso desde Norteamérica por mes; solo en `us-west1`, `us-central1` o `us-east1` ([fuente secundaria]; verificar en cloud.google.com/free). No alcanza para MySQL + Redis + Reverb + PHP. Exige cuenta de facturación. |
| PaaS con plan gratuito (Render, Railway, Fly.io, Koyeb, etc.) | Suspenden el servicio por inactividad o funcionan con créditos. Reverb (conexiones persistentes), los workers y el scheduler necesitan procesos siempre activos. No se evaluaron en detalle [no verificado]. |
| Créditos de prueba (AWS, Azure, GCP) | Vencen: no sirven para mantener un servicio. |

## 3. Proveedor de mapas

### 3.1 Comparación

| Criterio | openrouteservice (HeiGIT) | Google Routes API |
|---|---|---|
| Costo inicial | **0**, plan *Standard* sin medio de pago [oficial] | 0 dentro del cupo, pero exige **cuenta de facturación** habilitada [oficial] |
| Cuota gratuita | Directions: **2.000 por día y 40 por minuto** (Standard) [oficial, panel de HeiGIT] | *Compute Routes Essentials*: **10.000 eventos por mes**; Pro: 5.000; Enterprise: 1.000 [oficial] |
| Límite diario | 2.000 (Standard); 10.000 (*Collaborative*) | Sin límite propio: se factura. Se puede fijar una cuota diaria en la consola. |
| Límite por minuto | 40 (Standard); 60 (Collaborative) | Configurable en la consola (cuotas de API) |
| Precio fuera del cupo | No hay plan pago publicado: uso comercial a consultar con HeiGIT | Essentials: **USD 5 por cada 1.000** en el primer tramo pago; Pro: USD 10; Enterprise: USD 15 [oficial] |
| Cobertura argentina | Datos de OpenStreetMap: buena en ciudades, variable en calles nuevas o rurales | Datos propios de Google: muy buena |
| Precisión de rutas | Ruteo por calles con perfil `driving-car`, sin tráfico | Ruteo por calles; con `TRAFFIC_UNAWARE` (usado) es tarifa Essentials |
| Duración estimada | Sin tráfico en tiempo real | Sin tráfico en Essentials (con tráfico pasa a Pro, que cuesta el doble) |
| Condiciones académicas | Plan *Collaborative* para instituciones académicas, gubernamentales y sin fines de lucro, **sujeto a aprobación** y **sin uso comercial** | No hay programa académico relevante |
| Uso comercial | Standard: los resultados se licencian bajo **CC-BY 4.0** con atribución [oficial]. Collaborative: no comercial. HeiGIT dejó de dar soporte gratuito a usos comerciales. Para la operación real, **confirmar las condiciones por escrito con HeiGIT** | Permitido según los términos de Google Maps Platform |
| Caché | Los términos no regulan una caché técnica corta [no verificado]; atribución obligatoria | Los términos restringen el almacenamiento del contenido; históricamente se permite caché temporal de hasta 30 días para ciertos datos [no verificado para Routes]. La caché del backend es de **10 minutos** |
| Integración | REST, clave en el header `Authorization`. **Desde noviembre de 2026 solo responde `api.heigit.org`** (`api.openrouteservice.org` se dio de baja) | REST, clave en `X-Goog-Api-Key` y *field mask* obligatoria |
| Dependencia de facturación | Ninguna | Total: cada solicitud fuera del cupo se cobra |
| Escalabilidad | Cupo fijo y bajo; para escalar: Collaborative (no comercial), acuerdo con HeiGIT u **OSRM propio** | Escala sin límite pagando |

### 3.2 Cuántas consultas genera el flujo actual

Revisado en el código:

- Cada `POST /viajes/cotizar` hace **una** consulta de ruteo. La respuesta se guarda 10 minutos en caché por par de coordenadas, redondeadas a ~1 m.
- `POST /cliente/viajes/solicitar` o `reservar` **con `cotizacion_id`** no consulta (usa el precio cerrado). Sin `cotizacion_id` cotiza otra vez: 1 consulta, que muchas veces sale de la caché.
- Aceptar, iniciar o finalizar un viaje y los pings GPS **no** consultan mapas. La finalización usa el GPS propio y la geocerca en MySQL.
- Reintentos: solo ante fallas de conexión (`MAPAS_REINTENTOS=1`). Cada intento descuenta cuota local.

Supuestos de las proyecciones [estimación]:

- 2 consultas por viaje realizado: la cotización inicial, más recotizaciones porque la cotización vence a los 5 minutos o cambia el destino, más cotizaciones que no terminan en viaje.
- Caché casi nula en las apps (las coordenadas del GPS cambian entre consultas) y algo mayor en el panel.
- Hora pico: 10 % de los viajes del día.

| Escenario | Viajes por día | Consultas por día | Consultas por mes | Pico por minuto | openrouteservice Standard | Google Routes (Essentials) |
|---|---|---|---|---|---|---|
| A. Proyecto universitario | 10 a 30 | 20 a 60 | 600 a 1.800 | menos de 5 | 1 a 3 % del cupo diario: **gratis** | Dentro de los 10.000: **USD 0** (con facturación habilitada) |
| B. Pruebas de funcionamiento | 50 a 100 | 100 a 200 | 3.000 a 6.000 | ~10 | 5 a 10 % del cupo diario: **gratis** | Dentro de los 10.000: **USD 0** |
| C. Operación completa | 1.500 | ~3.000 | ~90.000 | 5 a 20 | **Supera** el cupo diario: con el tope local de 1.900, alrededor de **un tercio** de las cotizaciones del día sale por zonas | (90.000 − 10.000) × USD 5 / 1.000 ≈ **USD 400 por mes**. Con 1,3 consultas por viaje: ≈ **USD 240 por mes** |

Impacto de los reintentos: con una tasa de fallas de conexión del 2 % y 1 reintento, +2 % de consumo. Impacto de la caché: elimina las consultas repetidas del mismo trayecto en 10 minutos (doble toque, recotizar en el panel). En las apps el ahorro esperado es bajo.

### 3.3 Decisión

**openrouteservice, plan Standard** (`MAPAS_DRIVER=ors`) para los escenarios A y B:

1. Mantiene el proyecto en **costo cero sin medio de pago**: nunca puede generar un cargo.
2. Las cuotas locales del backend (`MAPAS_ORS_CUOTA_POR_DIA=1900`, `MAPAS_ORS_CUOTA_POR_MINUTO=35`) cortan **antes** del límite del proveedor. Al agotarse, el cotizador usa la contingencia por zonas: el despacho nunca se bloquea.
3. La cobertura de OSM en el área urbana de operación es adecuada para el cálculo por distancia de calles. Validar con trayectos reales con `php artisan mapas:estado --probar` y comparando contra viajes conocidos.
4. Cambiar de proveedor es cambiar `MAPAS_DRIVER`.

Condiciones:

- **Atribución:** los frontends deben mostrar "© openrouteservice.org by HeiGIT · Map data © OpenStreetMap contributors" donde se muestren distancias o rutas.
- Plan **Collaborative**: se puede pedir para la etapa universitaria (10.000 por día), pero **no está garantizado**, es **no comercial** y no sirve para la operación real de la remisería.
- **Uso comercial con openrouteservice:** antes de operar con la remisería real, confirmar por escrito con HeiGIT que el plan Standard admite el uso comercial, o acordar un plan.

**Para la operación completa (escenario C)** hay que elegir entre:

| Opción | Costo [estimación] | Comentario |
|---|---|---|
| Google Routes Essentials (`MAPAS_DRIVER=google`) | ≈ USD 240 a 400 por mes, con facturación habilitada y `MAPAS_GOOGLE_CUOTA_POR_MES` como tope | La más simple. Configurar además alertas de presupuesto y cuotas en la consola de Google. **No activar sin autorización.** |
| OSRM propio (`MAPAS_DRIVER=osrm`) | Costo de una VM aparte (sección 6) | Sin costo por consulta y sin límites. Requiere preparar y actualizar el mapa. |
| Acuerdo comercial con HeiGIT | A consultar | Mantiene el mismo proveedor. |

### 3.4 Arquitectura desacoplada implementada

- Interfaz `ProveedorDistancia` con adaptadores `OpenRouteServiceProveedorDistancia`, `GoogleRoutesProveedorDistancia`, `OsrmProveedorDistancia` y `SinProveedorDistancia` (`ninguno` / `zonas`).
- Base común `ProveedorHttp`: timeout de 3 s, un reintento solo ante fallas de conexión, caché de 10 minutos y validación de la respuesta. Los logs no incluyen URL, clave ni coordenadas.
- `GuardaDeProveedor`:
  - **cuota local** por minuto, día y mes, guardada en Redis;
  - **circuit breaker**: 3 fallas seguidas suspenden el proveedor 60 s; un HTTP 429 lo suspende 300 s; al vencer, una consulta de prueba lo reabre o lo cierra.
- `RutaInexistente`: cuando el proveedor confirma que no hay recorrido por calles, se **rechaza** la cotización (422) en lugar de inventar un precio (`MAPAS_RECHAZAR_SIN_RUTA`).
- Contingencia: zona tarifaria por distancia en línea recta y, si ninguna cubre el trayecto, la fórmula sobre la distancia lineal × 1,4.
- Diagnóstico: `php artisan mapas:estado [--probar]`.
- Las claves de mapas viven solo en el backend.

### 3.5 OSRM como alternativa futura

El adaptador OSRM sigue implementado y probado. Para activarlo más adelante, **en otra máquina**:

1. Descargar el extracto de Argentina, o solo de la provincia de Buenos Aires, de Geofabrik (`.osm.pbf`).
2. Preprocesar con el perfil `car`: `osrm-extract` → `osrm-partition` → `osrm-customize` (MLD). Necesita varios GB de RAM: medirlo con el extracto elegido antes de dimensionar la VM [estimación: 8 a 16 GB para el preprocesamiento de un país mediano].
3. Servir con `osrm-routed --algorithm mld`, accesible solo por red privada.
4. Configurar `MAPAS_DRIVER=osrm` y `MAPAS_OSRM_URL=http://<ip-privada>:5000`.
5. Actualizar el mapa una vez por mes, preprocesando en paralelo y cambiando de directorio para no cortar el servicio.

En `compose.prod.yaml` queda un servicio `osrm` comentado y en un *profile* aparte: **no se activa en la VM gratuita**. El servidor público `router.project-osrm.org` no es apto para producción, y `.env.example` ya no lo usa por defecto.

## 4. Compatibilidad ARM64

| Componente | Imagen | arm64 | Verificación |
|---|---|---|---|
| PHP-FPM, colas, scheduler, Reverb | `php:8.5-fpm-alpine` + extensiones compiladas en la imagen (`pdo_mysql`, `pcntl`, `bcmath`, `intl`, `zip`, `redis` 6.3.0; OPcache viene integrado en PHP 8.5) | Sí | Se compiló la imagen para `linux/arm64` con `docker buildx` (emulación QEMU **solo para verificar** que compila; en Oracle se compila nativa). Ver el informe final. |
| Dependencias de Composer | PHP puro (Laravel, Reverb sobre ReactPHP, Sanctum, l5-swagger) | Sí | Sin binarios nativos. |
| NGINX | `nginx:1.28-alpine` | Sí | Imagen oficial multiarquitectura. |
| MySQL | `mysql:8.4` | Sí | Imagen oficial con variante `arm64v8`. |
| Redis | `redis:7.4-alpine` | Sí | Imagen oficial multiarquitectura. |
| Certbot | `certbot/certbot:v4.1.1` | Sí | Imagen oficial multiarquitectura. |
| Pruebas de carga | `grafana/k6` | Sí | Corren fuera de la VM. |

## 5. Backups y almacenamiento

- `deploy/backup/backup.sh`:
  - `mysqldump --single-transaction` y comprobantes;
  - un archivo **cifrado AES-256** (PBKDF2) con MANIFIESTO de SHA-256;
  - subida con `rclone` a OCI Object Storage, vía la API compatible con S3, con verificación;
  - retención local de 7 días y remota de 30.
- `deploy/backup/restore.sh`: restaura en una **base nueva**, verifica cada PDF contra `comprobantes.sha256` y borra la base de prueba.
- Tamaño [medido]: un comprobante PDF ocupa unos 3 KB. En el escenario C son ~4,4 MB por día (~1,6 GB por año).
- **20 GB gratuitos** de Object Storage. Con backups completos diarios y 30 días de retención:
  - escenario A/B: menos de 1 GB en total;
  - escenario C, primer año: hasta ~30 × (1,6 GB de PDF + dump) ≈ 60 GB. **No entra** en el plan gratuito.
- Estrategia para el escenario C, sin contratar nada:
  - dump diario con 14 días de retención;
  - comprobantes con `rclone copy --immutable` **incremental**: son inmutables, así que cada archivo se sube una vez;
  - un backup completo semanal con 4 semanas de retención.
  - Con eso entra en 20 GB durante el primer año.
- Ampliación paga: Object Storage Standard ≈ USD 0,0255 por GB-mes [no verificado, fuente secundaria] o *Infrequent Access* / *Archive* para copias viejas.

## 6. Costos por escenario

Costos mensuales en USD, sin impuestos. Supuesto de tipo de cambio: no se convierte a ARS porque varía; usar el oficial del día.

| Rubro | 1. Universitario | 2. Crecimiento moderado (100 a 300 viajes por día, hasta 50 conductores) | 3. Operación completa (1.500 viajes por día, 200 conductores) |
|---|---|---|---|
| Cómputo | OCI A1 Always Free: **0** | OCI A1 Always Free: **0** (medir con las pruebas de carga) | Según las pruebas de carga: la VM gratuita puede quedar corta en horas pico. Opciones: más OCPU/RAM A1 pago por uso, o separar MySQL o Reverb (sección 7). Precio de A1 por OCPU-hora y GB-hora: consultar el estimador oficial de OCI ([no verificado]: la página de precios no se pudo descargar automáticamente) |
| Almacenamiento en bloques | 200 GB incluidos: **0** | **0** | 0 si entra en 200 GB |
| Backups | 20 GB de Object Storage: **0** | **0** | 0 con la estrategia incremental; si no, ~USD 1 a 2 por mes [no verificado] |
| Mapas | openrouteservice Standard: **0** | openrouteservice Standard: **0** (hasta ~900 viajes por día con 2 consultas por viaje) | Google: **≈ 240 a 400**; u OSRM: costo de su VM; o acuerdo con HeiGIT |
| Mercado Pago | Sin costo fijo. Comisión por cobro según el plazo de acreditación que elija la cuenta [no verificado: consultar las tarifas vigentes de Mercado Pago Argentina] | Ídem | Ídem |
| Clima | `CLIMA_DRIVER=ninguno`: **0** | **0** | 0, o un plan comercial si se quiere recargo por lluvia |
| Dominio y TLS | Let's Encrypt: 0. Dominio: costo del registrador (NIC Argentina u otro) [no verificado] | Ídem | Ídem |
| **Total fijo estimado** | **0** (más el dominio) | **0** (más el dominio) | **≈ 240 a 400 + cómputo adicional** (o 0 en mapas con OSRM y su VM) |

Riesgos económicos y protecciones:

| Proveedor | Riesgo | Protección implementada o recomendada |
|---|---|---|
| Google Routes | Cargos al pasar los 10.000 por mes | Cuota local `MAPAS_GOOGLE_CUOTA_POR_MES=9500` (corta a zonas antes de facturar), más cuotas por día en la consola y alertas de presupuesto. No usar tráfico (`TRAFFIC_AWARE` cobra la tarifa Pro). |
| openrouteservice | Bloqueo por cuota o cambios de plan | Cuotas locales, circuit breaker y contingencia por zonas. |
| Oracle | Pasar a pago por uso agrega riesgo de cargos | Mantener la cuenta Always Free mientras no haya presupuesto. Si se pasa a pago por uso, configurar *budgets* y alertas. |
| Mercado Pago | Comisiones y contracargos | Estado `reintegrado` y reportes de reintegros. |

## 7. Migración a producción y escalabilidad

| Separar | Cuándo conviene | Cómo (sin tocar el código) | Beneficio |
|---|---|---|---|
| MySQL | La CPU o la RAM de MySQL compiten con PHP en las pruebas de carga, o se necesitan backups gestionados | MySQL administrado (OCI MySQL HeatWave, RDS, etc.) o VM propia. Cambiar `DB_HOST` y quitar el servicio `mysql` del compose | Aislamiento, backups y alta disponibilidad gestionados |
| Redis | Mucha memoria por colas o pings | Redis administrado o VM. Cambiar `REDIS_HOST` y `REDIS_PASSWORD` | Aislamiento de la memoria |
| Reverb | Más conexiones WebSocket de las que maneja un proceso, o se necesita alta disponibilidad | Varias instancias con `REVERB_SCALING_ENABLED=true` (Redis pub/sub) detrás de NGINX | Escala horizontal del tiempo real |
| Workers | Cola atrasada (`LLEN queues:default` crece) | `docker compose up -d --scale queue=N`, o workers en otra VM con el mismo `.env` | Menor latencia de difusión (RNF02) |
| Backend (PHP) | CPU de PHP-FPM saturada | Más réplicas de `app` detrás de NGINX o de un balanceador. Sesiones y caché ya están en Redis | Escala horizontal |
| OSRM | Escenario C sin Google | VM dedicada (sección 3.5) | Mapas sin costo por consulta |
| Almacenamiento de comprobantes | Varias réplicas de `app` | Montar el mismo volumen (NFS / OCI File Storage) o mover el disco `comprobantes` a S3/Object Storage (driver `s3` de Laravel; requiere `league/flysystem-aws-s3-v3`) | Comprobantes compartidos |

Orden recomendado: primero los **workers** (lo más barato y lo que más afecta a RNF02), después **MySQL**, después **Reverb** con *scaling* y por último la réplica del backend.

## 8. Fuentes

| Tema | Fuente |
|---|---|
| Oracle Always Free | https://docs.oracle.com/en-us/iaas/Content/FreeTier/freetier_topic-Always_Free_Resources.htm · https://www.oracle.com/cloud/free/ |
| openrouteservice: planes | https://account.heigit.org/info/plans (Standard: Directions 2.000/40; Collaborative: 10.000/60) |
| openrouteservice: cambio de dominio | https://ask.openrouteservice.org/t/deprecating-api-openrouteservice-org-in-favour-of-api-heigit-org/7912 · https://heigit.org/changes-in-the-openrouteservice-universe/ |
| openrouteservice: uso comercial | https://ask.openrouteservice.org/t/commercial-support-limitations/791 |
| Google Maps Platform: precios | https://developers.google.com/maps/billing-and-pricing/pricing (Compute Routes Essentials USD 5 / 10.000 gratis; Pro USD 10 / 5.000; Enterprise USD 15 / 1.000) |
| Google Routes: SKU | https://developers.google.com/maps/documentation/routes/usage-and-billing (`TRAFFIC_AWARE` → Pro) |
| Google Cloud Free Tier | https://cloud.google.com/free (datos tomados de fuentes secundarias; verificar) |
| OCI Object Storage: precios | https://www.oracle.com/cloud/storage/pricing (no se pudo leer automáticamente; el valor citado es de fuentes secundarias) |
