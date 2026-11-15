# Ejecución de tests — Remisería LH (Backend)

## Requisitos

La suite **requiere MySQL 8** (columnas `POINT` SRID 4326, `ST_Distance_Sphere`, columnas generadas e índices espaciales). SQLite no sirve.

`phpunit.xml` fija todo lo demás para que los tests sean reproducibles y no dependan del `.env` local: `APP_KEY` y credenciales de Reverb de prueba, cache en memoria, cola `sync`, difusiones descartadas (`DIFUSION_CONEXION_COLA=descartar`), servicio de mapas desactivado (`MAPAS_DRIVER=ninguno`) y último ping GPS en memoria (`UBICACION_CACHE_STORE=array`). **Ningún test llama a servicios externos** (mapas o Reverb): se usan `Http::fake` y dobles.

La base es `remiserialh_testing`, que el script de Sail crea al levantar el contenedor de MySQL.

## Cómo ejecutarlos

### Con Docker (Sail)

```bash
docker compose up -d
docker exec remiserialhbackend-laravel.test-1 php artisan test    # Windows sin WSL
./vendor/bin/sail test                                            # Linux, macOS o WSL2
```

### Nativo, contra cualquier MySQL de pruebas

```bash
DB_HOST=127.0.0.1 DB_PORT=3306 vendor/bin/phpunit
```

`vendor/bin/phpunit` corre aun sin `.env`. `php artisan test` arranca la aplicación antes de leer `phpunit.xml`, por lo que necesita un `.env` válido. **Nunca apuntes `DB_*` a una base con datos reales**: los tests la vacían.

Para un MySQL efímero:

```bash
docker run -d --name rlh-test-mysql -e MYSQL_ROOT_PASSWORD=password -e MYSQL_DATABASE=remiserialh_testing -p 127.0.0.1:33306:3306 mysql:8.4
DB_HOST=127.0.0.1 DB_PORT=33306 vendor/bin/phpunit
```

## Cobertura

El PHP local no suele traer Xdebug ni PCOV. Con Docker:

```dockerfile
# Dockerfile (fuera del repo)
FROM php:8.5-cli
RUN apt-get update && apt-get install -y --no-install-recommends libzip-dev unzip \
 && docker-php-ext-install pdo_mysql zip bcmath \
 && pecl install pcov && docker-php-ext-enable pcov
```

```bash
docker build -t rlh-coverage .
docker run --rm --network container:rlh-test-mysql -v "$PWD:/app" -w /app \
  -e DB_HOST=127.0.0.1 -e DB_PORT=3306 rlh-coverage \
  php -d pcov.enabled=1 -d pcov.directory=/app/app vendor/bin/phpunit --coverage-text
```

Medición del 2026-11-04 (363 tests): **`app/Services` 96,39 %** de líneas; `app/Services/Pagos` 94,22 %; `app/` completo 87,63 %.

## Suites

| Carpeta | Qué cubre |
|---|---|
| `tests/Unit` | Dinero en centavos, generador PDF, generador XLSX. |
| `tests/Feature/Auth` | Login, registro (anti-enumeración), roles. |
| `tests/Feature/Seguridad` | Exposición de datos personales, permisos del chat, revocación de acceso, límites de frecuencia y paginación. |
| `tests/Feature/Api` | Tarifas, cotización y cotizaciones emitidas (precio cerrado), solicitudes y reservas, registro telefónico, estados, finalización con geocerca, pagos, Mercado Pago (con `Http::fake`: firma, verificación contra la API, idempotencia, anomalías, reintegros, conciliación), comprobantes y su registro, reportes, conductores y vehículos. |
| `tests/Feature/Cotizacion` | Fórmula, redondeo, contingencia por zonas, recargo dinámico por demanda y lluvia, proveedores OSRM, Google y openrouteservice (respuestas válidas, errores, timeouts, reintentos, caché), cuota local, circuit breaker con recuperación y rechazo de trayectos sin recorrido por calles. |
| `tests/Feature/Asignacion` | Selección de candidatos, ofertas, aceptar, rechazar, vencimiento, agotamiento y restricciones de la base. |
| `tests/Feature/Geolocalizacion` | Pings, Redis con respaldo en MySQL, saltos imposibles, seguimiento del recorrido. |
| `tests/Feature/Concurrencia` | Bloqueos de fila con dos conexiones MySQL reales (pagos, ofertas, cancelación, tarifas). |
| `tests/Feature/Console` | Despacho de reservas, importación legacy, `mapas:estado`, `carga:preparar`. |
| `tests/Feature/Broadcasting` | Autorización de canales y eventos. |
| `tests/Feature/Database` | Tablas esenciales, orden de ejes de las coordenadas y caché serializada (regresión: objetos en caché con `serializable_classes=false`). |

`tests/Feature/Api/MercadoPagoTarjetasYSeguridadTest.php` cubre la ventana temporal y las repeticiones del webhook, el reintegro de cobros no aplicados, las tarjetas guardadas (vincular, consentimiento, límite, predeterminada, desvincular, IDOR), el pago con tarjeta guardada (aprobado, rechazado, token inválido, cobro simultáneo, vencimiento del enlace), el cobro automático por órdenes y la conciliación de órdenes. **Todas las respuestas de Mercado Pago son simuladas** (`Http::fake`): validan la lógica del backend, no la integración real (ver la validación en sandbox en [seguridad-y-despliegue.md](seguridad-y-despliegue.md#validación-de-mercado-pago-en-sandbox)).

## Consejos

```bash
vendor/bin/phpunit --filter AsignacionAutomaticaTest
php artisan test --profile
```
