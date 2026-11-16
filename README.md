# Remisería LH — Backend

API REST en Laravel para el sistema de gestión de Remisería LH: solicitudes y reservas de viajes, cotización, asignación automática de conductores, seguimiento GPS en tiempo real, chat, pagos, comprobantes y reportes.

## Stack

- Laravel 13 (PHP ≥ 8.3; la imagen de producción y la de Sail usan PHP 8.5) con Sanctum (tokens Bearer).
- MySQL 8.4 (obligatorio: se usan columnas `POINT` SRID 4326 y `ST_Distance_Sphere`).
- Redis (último ping GPS de cada conductor; opcional para colas y cache).
- Laravel Reverb (WebSockets) y colas de Laravel (worker + scheduler).
- Documentación OpenAPI con l5-swagger (`/api/documentation`).

## Documentación

| Documento | Contenido |
|---|---|
| [docs/api.md](docs/api.md) | Endpoints, roles, formatos de error y contratos. |
| [docs/arquitectura-y-flujos.md](docs/arquitectura-y-flujos.md) | Estados del viaje y del pago, asignación, cotización, geolocalización, tiempo real, migración legacy. |
| [docs/seguridad-y-despliegue.md](docs/seguridad-y-despliegue.md) | Controles de seguridad, límites de confianza y checklist de producción. |
| [docs/cambios-para-frontend.md](docs/cambios-para-frontend.md) | Cambios de contrato que deben adoptar las apps y el panel. |
| [docs/estado-backend.md](docs/estado-backend.md) | Estado de las tareas planificadas y hallazgos de la auditoría. |
| [docs/tests.md](docs/tests.md) | Cómo correr la suite y medir cobertura. |
| [docs/ARQUITECTURA_Y_COSTOS.md](docs/ARQUITECTURA_Y_COSTOS.md) | Arquitectura gratuita inicial (Oracle Always Free), comparación de mapas (openrouteservice vs Google Routes), proyecciones de consumo, costos y camino de escalado. |
| [docs/DESPLIEGUE_ORACLE_CLOUD.md](docs/DESPLIEGUE_ORACLE_CLOUD.md) | Despliegue paso a paso en una VM Ampere A1 (arm64) con `compose.prod.yaml`. |
| [deploy/backup/README.md](deploy/backup/README.md) | Backups cifrados de MySQL y comprobantes, restauración verificada. |
| [deploy/carga/README.md](deploy/carga/README.md) | Pruebas de carga RNF01/RNF02 (k6) y resultados. |

## Producción

`compose.prod.yaml` levanta NGINX, PHP-FPM, Reverb, worker, scheduler, MySQL y Redis, con imágenes compatibles con arm64. Plantillas de variables en `deploy/env/` (producción, staging y carga local). Guía: [docs/DESPLIEGUE_ORACLE_CLOUD.md](docs/DESPLIEGUE_ORACLE_CLOUD.md).

## Instalación local con Docker (recomendado)

```bash
composer install                  # o con un contenedor de PHP si no lo tenés local
cp .env.example .env
php artisan key:generate
./vendor/bin/sail up -d           # en Windows sin WSL: docker compose up -d
./vendor/bin/sail artisan migrate
./vendor/bin/sail artisan db:seed # marcas, tarifa inicial, administrador y datos demo
```

`compose.yaml` levanta la aplicación, MySQL, Redis, un **worker de colas** (`queue:work`) y el **scheduler** (`schedule:work`). Sin el worker no se asignan conductores ni se difunden eventos; sin el scheduler no se despachan reservas ni se vencen ofertas perdidas.

El servidor de WebSockets se inicia con:

```bash
./vendor/bin/sail artisan reverb:start --host=0.0.0.0 --port=8080
```

### Instalación nativa (XAMPP, Laragon, Herd)

Configurar en `.env` `DB_HOST=127.0.0.1` y `REDIS_HOST=127.0.0.1`, y correr en terminales separadas:

```bash
php artisan serve
php artisan queue:work --tries=3 --backoff=5
php artisan schedule:work
php artisan reverb:start
```

Si Redis no está disponible, la API sigue funcionando: el último ping GPS se guarda directamente en MySQL (ver `UBICACION_CACHE_STORE`).

## Variables de entorno

`.env.example` documenta todas las variables con sus valores por defecto. Las que hay que revisar sí o sí:

| Variable | Para qué |
|---|---|
| `APP_KEY`, `APP_DEBUG=false`, `APP_URL` | Base de Laravel. Nunca `APP_DEBUG=true` en producción. |
| `DB_*` | MySQL 8. |
| `REDIS_*`, `UBICACION_CACHE_STORE=redis` | Último ping GPS. |
| `QUEUE_CONNECTION` | `database` o `redis`; nunca `sync` en producción. |
| `REVERB_APP_ID/KEY/SECRET`, `REVERB_ALLOWED_ORIGINS` | WebSockets. Credenciales propias y orígenes reales en producción. |
| `MAPAS_DRIVER` (`ors`, `google`, `osrm` o `ninguno`), `ORS_API_KEY`, `GOOGLE_MAPS_API_KEY`, `MAPAS_OSRM_URL`, `MAPAS_*_CUOTA_*`, `MAPAS_CACHE_STORE` | Servicio de ruteo del cotizador, con cuotas locales y circuit breaker. Con `ninguno` se usa la contingencia por zonas. |
| `MERCADOPAGO_ACCESS_TOKEN`, `MERCADOPAGO_PUBLIC_KEY`, `MERCADOPAGO_WEBHOOK_SECRET`, `MERCADOPAGO_NOTIFICATION_URL`, `MERCADOPAGO_SANDBOX`, `MERCADOPAGO_WEBHOOK_TOLERANCIA_SEGUNDOS`, `MERCADOPAGO_PAGOS_AUTOMATICOS`, `MERCADOPAGO_REINTEGRO_AUTOMATICO` | Cobros con Mercado Pago (enlace, tarjetas guardadas, cobro automático). Sin access token, el cobro digital queda deshabilitado (503). |
| `TRUSTED_PROXIES` | Subred del proxy (NGINX) en producción. |
| `REDIS_QUEUE_BLOCK_FOR` | Espera bloqueante del worker sobre Redis (5 en producción): entrega inmediata de los eventos. |
| `CLIMA_DRIVER` (`ninguno` u `openmeteo`) | Recargo dinámico por lluvia. La API pública de Open-Meteo es solo para uso no comercial. |
| `ADMIN_PASSWORD` | Contraseña del administrador inicial (AdminSeeder). |
| `CORS_ALLOWED_ORIGINS`, `SANCTUM_STATEFUL_DOMAINS` | Dominios del panel web. |
| `SANCTUM_TOKEN_EXPIRATION` | Vida de los tokens (minutos). |

Las reglas operativas (oferta de 30 s, radio de 10 km ampliable hasta 20 km, geocerca de 100 m, reservas entre 60 minutos y 30 días, vigencia de cotizaciones, límites de frecuencia, etc.) están en `config/remiseria.php` y se pueden ajustar por entorno.

## Comandos Artisan propios

| Comando | Uso |
|---|---|
| `viajes:despachar-programados` | Pasa a despacho las reservas que entraron en la ventana previa (scheduler, cada minuto). |
| `viajes:supervisar-despacho` | Vence ofertas sin respuesta y reactiva búsquedas detenidas (scheduler, cada minuto). |
| `pagos:conciliar-mercadopago [--dias=3]` | Concilia contra la API de Mercado Pago los cobros pendientes cuyo webhook se perdió (scheduler, cada 10 minutos). |
| `legacy:importar {archivo} --tipo=clientes\|conductores [--dry-run]` | Migración de datos del sistema anterior desde CSV. Ver [docs/arquitectura-y-flujos.md](docs/arquitectura-y-flujos.md#migración-de-datos-legacy). |
| `simular:anual {dias} {viajes_por_dia}` | Genera datos de volumen para pruebas de carga (solo entornos de prueba). |
| `broadcast:test` | Evento de humo para verificar Reverb. |
| `mapas:estado [--probar]` | Proveedor de mapas activo, consumo de cuota y estado del circuit breaker. `--probar` hace una consulta real. |
| `carga:preparar [--conductores=200] [--clientes=20] [--limpiar]` | Datos sintéticos para las pruebas de carga (nunca en producción). |

## Tests

La suite requiere MySQL (funciones espaciales). Detalle en [docs/tests.md](docs/tests.md).

```bash
./vendor/bin/sail test            # dentro de Docker
vendor/bin/phpunit                # nativo, con DB_HOST/DB_PORT apuntando a un MySQL de pruebas
```

---
Repo general del proyecto: [remiseriaLH-docs](https://github.com/MarianoSanchez16/remiseriaLH-docs.git)
