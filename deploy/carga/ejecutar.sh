#!/usr/bin/env bash
# =============================================================================
# Ejecuta la prueba de carga RNF01/RNF02 contra un despliegue de
# compose.prod.yaml y junta métricas de CPU, RAM, Redis, MySQL y colas.
#
# NO correr contra producción: crea usuarios sintéticos y genera tráfico
# real. Pensado para la máquina local o un staging aislado.
#
# Uso (desde la raíz del repo):
#   RLH_ENV_FILE=.env.carga RLH_PROYECTO=rlh-carga deploy/carga/ejecutar.sh
#
# Variables: CONDUCTORES (200), CLIENTES (20), DURACION_S (300),
# INTERVALO_GPS_MS (3000), K6_IMAGEN (grafana/k6:1.0.0).
# Resultados: deploy/carga/resultados/<sello>/ (ignorado por git).
# =============================================================================
set -Eeuo pipefail

RAIZ="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$RAIZ"

RLH_ENV_FILE="${RLH_ENV_FILE:-.env.carga}"
RLH_PROYECTO="${RLH_PROYECTO:-rlh-carga}"
CONDUCTORES="${CONDUCTORES:-200}"
CLIENTES="${CLIENTES:-20}"
DURACION_S="${DURACION_S:-300}"
INTERVALO_GPS_MS="${INTERVALO_GPS_MS:-3000}"
K6_IMAGEN="${K6_IMAGEN:-grafana/k6:1.0.0}"
COMPOSE=(docker compose -p "$RLH_PROYECTO" -f compose.prod.yaml --env-file "$RLH_ENV_FILE")

leer_env() { sed -n "s/^$1=//p" "$RLH_ENV_FILE" | tail -1 | tr -d '"'; }
[ "$(leer_env APP_ENV)" != "production" ] || { echo "Se niega a correr con APP_ENV=production." >&2; exit 1; }

SELLO="$(date '+%Y%m%d-%H%M%S')"
SALIDA="deploy/carga/resultados/$SELLO"
mkdir -p "$SALIDA"
# k6 lee las credenciales y escribe el resumen en resultados/.
CREDENCIALES="deploy/carga/resultados/credenciales.json"

log() { printf '%s [carga] %s\n' "$(date '+%H:%M:%S')" "$*"; }

instantanea() {
    local nombre="$1"
    {
        echo "== Redis"
        "${COMPOSE[@]}" exec -T redis sh -c 'redis-cli INFO stats | grep -E "total_commands_processed|instantaneous_ops_per_sec|rejected_connections"; redis-cli INFO memory | grep -E "^used_memory_human|maxmemory_human"; redis-cli INFO clients | grep connected_clients; echo "cola default: $(redis-cli LLEN queues:default)"'
        echo "== MySQL"
        "${COMPOSE[@]}" exec -T mysql sh -c 'mysql -N -uroot -p"$MYSQL_ROOT_PASSWORD" -e "SHOW GLOBAL STATUS WHERE Variable_name IN (\"Threads_connected\",\"Max_used_connections\",\"Questions\",\"Slow_queries\",\"Innodb_row_lock_waits\")"' 2>/dev/null
        echo "== Jobs fallidos"
        "${COMPOSE[@]}" exec -T mysql sh -c 'mysql -N -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE" -e "SELECT COUNT(*) FROM failed_jobs"' 2>/dev/null
    } > "$SALIDA/$nombre.txt" 2>&1 || true
}

log "preparando $CONDUCTORES conductores y $CLIENTES clientes sintéticos"
"${COMPOSE[@]}" exec -T app php artisan carga:preparar --conductores="$CONDUCTORES" --clientes="$CLIENTES" > "$CREDENCIALES"
chmod 600 "$CREDENCIALES"

{
    echo "fecha=$SELLO"
    echo "conductores=$CONDUCTORES clientes=$CLIENTES duracion_s=$DURACION_S intervalo_gps_ms=$INTERVALO_GPS_MS"
    echo "host: $(uname -srm)"
    docker info --format 'docker: {{.ServerVersion}} cpus={{.NCPU}} mem={{.MemTotal}} arch={{.Architecture}}'
    "${COMPOSE[@]}" ps --format '{{.Service}} {{.Image}} {{.Status}}'
} > "$SALIDA/entorno.txt"

instantanea antes

log "muestreando CPU/RAM de los contenedores cada 5 s"
echo "hora,contenedor,cpu,memoria" > "$SALIDA/docker-stats.csv"
(
    while :; do
        docker stats --no-stream --format "{{.Name}},{{.CPUPerc}},{{.MemUsage}}" \
            | grep "^${RLH_PROYECTO}-" | sed "s/^/$(date '+%H:%M:%S'),/" >> "$SALIDA/docker-stats.csv"
        sleep 5
    done
) &
MUESTREO=$!
trap 'kill $MUESTREO 2>/dev/null || true' EXIT

log "ejecutando k6 ($K6_IMAGEN) durante ${DURACION_S}s"
set +e
MSYS_NO_PATHCONV=1 docker run --rm --network "${RLH_PROYECTO}_interna" \
    -v "$RAIZ/deploy/carga:/scripts" \
    -e BASE_URL=http://nginx -e WS_URL=ws://nginx \
    -e REVERB_KEY="$(leer_env REVERB_APP_KEY)" \
    -e CONDUCTORES="$CONDUCTORES" -e CLIENTES="$CLIENTES" \
    -e DURACION_S="$DURACION_S" -e INTERVALO_GPS_MS="$INTERVALO_GPS_MS" \
    "$K6_IMAGEN" run --quiet /scripts/rnf01_rnf02.js > "$SALIDA/k6.log" 2>&1
K6_SALIDA=$?
set -e

kill $MUESTREO 2>/dev/null || true
instantanea despues
mv deploy/carga/resultados/resumen-k6.json "$SALIDA/" 2>/dev/null || true

log "limpiando datos sintéticos"
"${COMPOSE[@]}" exec -T app php artisan carga:preparar --limpiar >/dev/null
rm -f "$CREDENCIALES"

PYTHONIOENCODING=utf-8 "${PYTHON:-python3}" deploy/carga/resumir.py "$SALIDA" > "$SALIDA/RESUMEN.md"
log "resultados en $SALIDA (k6 terminó con código $K6_SALIDA; distinto de 0 = algún umbral no se cumplió)"
cat "$SALIDA/RESUMEN.md"
