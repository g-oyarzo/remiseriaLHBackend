# Pruebas de carga (RNF01 y RNF02)

- **RNF01:** al menos 200 conductores conectados a la vez sin degradación.
- **RNF02:** la ubicación se actualiza en los mapas con una latencia menor a 4 s.

## Qué se simula (`rnf01_rnf02.js`, k6)

| Escenario | Detalle |
|---|---|
| `conductores` | N VU, cada uno es un conductor real (token de Sanctum). Abre un **WebSocket a Reverb** (protocolo Pusher), se autoriza en `/api/v1/broadcasting/auth`, se suscribe a `private-conductor.{id}` y envía `POST /api/v1/conductor/ubicacion` cada `INTERVALO_GPS_MS`, moviéndose unos 25 m por ping. |
| `observador` | Un administrador suscripto a `private-admin.despacho`, que recibe **todas** las ubicaciones. Mide la **latencia de entrega**: hora de recepción menos `registrado_en` (hora del backend, con milisegundos). |
| `clientes` | M VU que cotizan (`POST /viajes/cotizar`, con `MAPAS_DRIVER=ninguno`, sin consumir cuota de mapas) y consultan `GET /viajes` cada 10 s. |

La latencia de punta a punta de RNF02 se estima como la suma de dos p95: el `POST` del ping y la entrega por WebSocket. Para medir la entrega se necesita el mismo reloj (o relojes sincronizados por NTP) en k6 y en el backend.

Se juntan además:

- CPU y RAM por contenedor (`docker stats` cada 5 s);
- Redis: comandos, memoria, clientes y largo de la cola;
- MySQL: conexiones, consultas, consultas lentas y esperas por bloqueo;
- `failed_jobs`.

Consumo de cuota de mapas: 0 (driver `ninguno`). Ver `php artisan mapas:estado`.

## Cómo correrla

**Nunca contra producción**: crea usuarios sintéticos (`@carga.invalid`) y genera tráfico real. El script se niega a correr con `APP_ENV=production`.

```bash
cp deploy/env/carga-local.env.example .env.carga
docker compose -p rlh-carga -f compose.prod.yaml --env-file .env.carga up -d --build
docker compose -p rlh-carga -f compose.prod.yaml --env-file .env.carga exec app php artisan migrate --force
RLH_ENV_FILE=.env.carga CONDUCTORES=200 CLIENTES=20 DURACION_S=300 INTERVALO_GPS_MS=3000 deploy/carga/ejecutar.sh
```

Los resultados quedan en `deploy/carga/resultados/<fecha>/`, ignorado por git: `RESUMEN.md`, `resumen-k6.json`, `docker-stats.csv`, `antes.txt`, `despues.txt`, `entorno.txt` y `k6.log`.

Para medir la VM de Oracle sin comprometerla: correr k6 desde **otra máquina** contra un *staging* en una VM equivalente, nunca contra la de producción en horario de operación, y empezar con `CONDUCTORES=50` para subir de a poco.

## Resultados medidos (2026-11-15)

**Entorno:** máquina de desarrollo con Windows 11, Docker Desktop 28.3, **x86_64, 16 CPU lógicas y 7,6 GB asignados a Docker**. `compose.prod.yaml` completo:

- NGINX y PHP-FPM con 24 procesos;
- Reverb y 1 worker de colas sobre Redis (BLPOP);
- MySQL 8.4 y Redis 7.4.

k6 1.0.0 en la misma red de Docker.

> **No es la VM de Oracle (2 OCPU Ampere arm64).** Estos números muestran que el software soporta la carga de RNF01/RNF02 sin errores y con qué consumo. **No** prueban que la VM gratuita la soporte: eso queda pendiente hasta tener la VM.

### Corrida 1 — RNF01/RNF02 nominal: 200 conductores, ping cada 3 s, 20 clientes, 300 s

| Métrica | Valor |
|---|---|
| Pings enviados / aceptados | 19.788 / **100 %** |
| `POST /conductor/ubicacion` p50 / p95 / p99 | 12 / 26 / 54 ms |
| Suscripciones WebSocket correctas | **100 %** (200 conductores más el observador) |
| Eventos de ubicación entregados al observador | 19.788 de 19.788 |
| Entrega por WebSocket p50 / p95 / p99 | 4 / **8** / 16 ms |
| RNF02 de punta a punta, p95 estimado | **≈ 34 ms** (límite: 4.000 ms) |
| Cotizar p50 / p95 · `GET /viajes` p50 / p95 | 16 / 120 ms · 7 / 50 ms |
| Respuestas 429 / 5xx · `failed_jobs` | 0 / 0 · 0 |
| CPU promedio (suma de contenedores) | ≈ 0,8 núcleos (app 44 %, mysql 14 %, queue 10 %, redis 7 %, reverb 5 %) |
| CPU pico de app | 96 % de un núcleo |
| RAM | MySQL ~606 MiB (buffer pool de 1 GB), resto menos de 60 MiB por contenedor |

### Corrida 2 — estrés: 200 conductores, ping cada **1 s** (triple de carga), 120 s

| Métrica | Valor |
|---|---|
| Pings enviados / aceptados | 23.646 / 100 % (~200 por segundo) |
| `POST /conductor/ubicacion` p95 | 27 ms |
| Entrega por WebSocket p95 / p99 | 25 / 43 ms |
| Errores | 0 |
| CPU promedio | ≈ 2 núcleos (app 124 %, mysql 28 %, queue 22 %, redis 16 %, reverb 11 %); pico de app: 310 % |

Interpretación:

- Con un ping cada 3 s, el stack usa ~0,8 núcleos x86. La VM gratuita tiene 2 OCPU arm64: **es probable** que alcance para RNF01 con margen, pero hay que **medirlo** en la VM.
- A un ping por segundo el consumo ronda 2 núcleos. En la VM gratuita no quedaría margen: no subir la frecuencia de GPS por encima de 1 cada 3 s sin escalar.
- El primer cuello de botella es **PHP-FPM** (la app); después vienen MySQL y el worker. Ver la tabla de separación en `docs/ARQUITECTURA_Y_COSTOS.md`.

### Problemas que encontró la prueba (corregidos)

| Problema | Efecto | Corrección |
|---|---|---|
| La caché guardaba objetos (`Tarifa`, `Ruta`) y Laravel 13 no los deserializa (`cache.serializable_classes=false`) | **Toda cotización devolvía 500 con Redis o la base de datos como caché**, es decir, en producción. Los tests usaban el store `array` y no lo veían | Se guardan arrays; claves nuevas para ignorar las entradas del formato viejo; test de regresión con un store que serializa |
| Keepalive de FastCGI entre NGINX y PHP-FPM | Procesos de FPM atados a conexiones ociosas; con 200 conexiones simultáneas, **504 en `broadcasting/auth`** (solo el 7 % de los WebSocket se suscribía) | Sin keepalive hacia FPM |
| Worker con polling (`block_for: null`) | ~0,6 s extra en cada entrega | `REDIS_QUEUE_BLOCK_FOR=5` (BLPOP). p50 de entrega: 633 ms con polling (prueba corta de 10 conductores) contra 4 ms con BLPOP (corrida 1, 200 conductores) |
