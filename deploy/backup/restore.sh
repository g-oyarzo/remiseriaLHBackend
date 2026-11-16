#!/usr/bin/env bash
# =============================================================================
# Restauración de un backup de Remisería LH (deploy/backup/backup.sh).
#
# Por seguridad, restaura en una BASE NUEVA (<base>_restaurada_<sello>) y deja
# los comprobantes en un directorio local; nunca toca la base en uso salvo
# con --reemplazar-base-actual, que además pide confirmación escrita.
#
# Verifica, en este orden: HMAC-SHA256 del archivo cifrado (ANTES de
# descifrar; SEC-09), sha256, descifrado, que el contenido tenga solo las
# partes esperadas, sha256 de cada parte (MANIFIESTO), carga del dump,
# cantidad de comprobantes y el sha256 de CADA PDF contra la columna
# comprobantes.sha256 de la base restaurada.
#
# Uso:
#   BACKUP_CLAVE_ARCHIVO=/etc/remiseria/backup.key deploy/backup/restore.sh archivo.tar.enc [opciones]
#
# Opciones:
#   --base NOMBRE               base destino (default <DB_DATABASE>_restaurada_<sello>)
#   --reemplazar-base-actual    restaura SOBRE la base en uso (pide confirmación)
#   --restaurar-comprobantes    copia los PDF al volumen de la app (solo junto con
#                               --reemplazar-base-actual)
#   --conservar-base            no borra la base temporal al terminar la prueba
#   --permitir-sin-hmac         acepta backups anteriores al HMAC (sin .hmac)
# =============================================================================
set -Eeuo pipefail
umask 077

RAIZ="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$RAIZ"

RLH_ENV_FILE="${RLH_ENV_FILE:-.env.production}"
read -r -a COMPOSE <<< "${RLH_COMPOSE:-docker compose -f compose.prod.yaml --env-file $RLH_ENV_FILE}"

log() { printf '%s [restore] %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*"; }
fallar() { log "ERROR: $*"; exit 1; }

ARCHIVO="${1:-}"; shift || true
[ -n "$ARCHIVO" ] && [ -f "$ARCHIVO" ] || fallar "indicar el archivo .tar.enc a restaurar"
[ -n "${BACKUP_CLAVE_ARCHIVO:-}" ] && [ -s "$BACKUP_CLAVE_ARCHIVO" ] || fallar "BACKUP_CLAVE_ARCHIVO no definido o vacío"

BASE=""; REEMPLAZAR=0; COMPROBANTES=0; CONSERVAR=0; SIN_HMAC=0
PYTHON="${PYTHON:-python3}"
while [ $# -gt 0 ]; do
    case "$1" in
        --base) BASE="$2"; shift 2 ;;
        --reemplazar-base-actual) REEMPLAZAR=1; shift ;;
        --restaurar-comprobantes) COMPROBANTES=1; shift ;;
        --conservar-base) CONSERVAR=1; shift ;;
        --permitir-sin-hmac) SIN_HMAC=1; shift ;;
        *) fallar "opción desconocida: $1" ;;
    esac
done

BASE_ACTUAL="$("${COMPOSE[@]}" exec -T mysql sh -c 'printf %s "$MYSQL_DATABASE"')"
SELLO="$(basename "$ARCHIVO" | sed -n 's/^remiseria-\([0-9-]*\)\.tar\.enc$/\1/p' | tr -d '-')"
[ -n "$SELLO" ] || SELLO="$(date '+%Y%m%d%H%M%S')"

if [ "$REEMPLAZAR" = 1 ]; then
    BASE="$BASE_ACTUAL"
    log "ATENCIÓN: se va a REEMPLAZAR la base en uso '$BASE'. Detener la app antes (docker compose stop app queue scheduler reverb)."
    printf 'Escribí el nombre de la base para confirmar: '
    read -r CONFIRMACION
    [ "$CONFIRMACION" = "$BASE" ] || fallar "confirmación incorrecta; no se hizo nada"
else
    BASE="${BASE:-${BASE_ACTUAL}_restaurada_${SELLO}}"
    [ "$BASE" != "$BASE_ACTUAL" ] || fallar "para restaurar sobre la base en uso usar --reemplazar-base-actual"
    [ "$COMPROBANTES" = 0 ] || fallar "--restaurar-comprobantes solo junto con --reemplazar-base-actual"
fi
[[ "$BASE" =~ ^[A-Za-z0-9_]{1,64}$ ]] || fallar "nombre de base inválido: $BASE"
case "$(printf %s "$BASE" | tr '[:upper:]' '[:lower:]')" in
    mysql|sys|information_schema|performance_schema) fallar "no se restaura sobre una base del sistema: $BASE" ;;
esac
if [ "$REEMPLAZAR" = 0 ]; then
    # Una prueba de restauración siempre va a una base NUEVA: nunca se
    # mezcla con datos existentes ni se pisa otra base por error.
    EXISTE="$("${COMPOSE[@]}" exec -T mysql sh -c "mysql -N -uroot -p\"\$MYSQL_ROOT_PASSWORD\" -e \"SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name='$BASE'\"" 2>/dev/null | tr -d '\r')"
    [ "$EXISTE" = 0 ] || fallar "la base '$BASE' ya existe: elegir otro nombre con --base"
fi

TRABAJO="$(mktemp -d)"
BASE_CREADA=0
limpiar() {
    rm -rf "$TRABAJO"
    # Si la prueba falló a mitad de camino, no dejar la base temporal.
    if [ "$BASE_CREADA" = 1 ] && [ "$REEMPLAZAR" = 0 ] && [ "$CONSERVAR" = 0 ]; then
        "${COMPOSE[@]}" exec -T mysql sh -c "mysql -uroot -p\"\$MYSQL_ROOT_PASSWORD\" -e 'DROP DATABASE IF EXISTS $BASE'" 2>/dev/null || true
    fi
}
trap limpiar EXIT

# SEC-09: autenticidad del archivo cifrado ANTES de descifrarlo.
if [ -f "$ARCHIVO.hmac" ]; then
    log "verificando HMAC del archivo cifrado"
    "$PYTHON" -I "$RAIZ/deploy/backup/hmac.py" verificar "$BACKUP_CLAVE_ARCHIVO" "$ARCHIVO" "$ARCHIVO.hmac" \
        || fallar "el HMAC no coincide: el archivo fue alterado o la clave es otra"
elif [ "$SIN_HMAC" = 1 ]; then
    log "ADVERTENCIA: backup sin HMAC (anterior a SEC-09); se continúa por --permitir-sin-hmac"
else
    fallar "falta $ARCHIVO.hmac (usar --permitir-sin-hmac solo para backups anteriores a esta versión)"
fi

if [ -f "$ARCHIVO.sha256" ]; then
    log "verificando sha256 del archivo cifrado"
    ( cd "$(dirname "$ARCHIVO")" && sha256sum -c "$(basename "$ARCHIVO").sha256" >/dev/null ) || fallar "el sha256 del archivo no coincide"
else
    log "ADVERTENCIA: no hay $ARCHIVO.sha256; se confía en el MANIFIESTO interno"
fi

log "descifrando"
openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 -pass "file:$BACKUP_CLAVE_ARCHIVO" -in "$ARCHIVO" -out "$TRABAJO/backup.tar" \
    || fallar "no se pudo descifrar (¿clave incorrecta?)"
# Solo las tres partes esperadas, sin rutas ni enlaces (defensa en profundidad).
[ "$(tar -tf "$TRABAJO/backup.tar" | sort | tr '\n' ' ')" = "MANIFIESTO comprobantes.tar.gz db.sql.gz " ] \
    || fallar "el contenido del backup no es el esperado"
tar -C "$TRABAJO" --no-same-owner --no-same-permissions -xf "$TRABAJO/backup.tar" MANIFIESTO db.sql.gz comprobantes.tar.gz
rm -f "$TRABAJO/backup.tar"
# sha256sum escribe "hash  archivo" (Linux) o "hash *archivo" (modo binario):
# se aceptan ambos y se exige que estén las dos partes.
( cd "$TRABAJO" && [ "$(grep -cE '^[0-9a-f]{64} [ *]' MANIFIESTO)" = 2 ] && grep -E '^[0-9a-f]{64} [ *]' MANIFIESTO | sha256sum -c - >/dev/null ) \
    || fallar "el MANIFIESTO no coincide: archivo dañado"
grep -vE '^[0-9a-f]{64} ' "$TRABAJO/MANIFIESTO" | sed 's/^/    /'

log "cargando el dump en la base '$BASE'"
"${COMPOSE[@]}" exec -T mysql sh -c "mysql -uroot -p\"\$MYSQL_ROOT_PASSWORD\" -e 'CREATE DATABASE IF NOT EXISTS $BASE CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'" 2>/dev/null
BASE_CREADA=1
gunzip -c "$TRABAJO/db.sql.gz" | "${COMPOSE[@]}" exec -T mysql sh -c "mysql -uroot -p\"\$MYSQL_ROOT_PASSWORD\" '$BASE'" 2> "$TRABAJO/mysql.err" \
    || { grep -v 'Using a password' "$TRABAJO/mysql.err" >&2 || true; fallar "falló la carga del dump"; }

consulta() { "${COMPOSE[@]}" exec -T mysql sh -c "mysql -N -uroot -p\"\$MYSQL_ROOT_PASSWORD\" '$BASE' -e \"$1\"" 2>/dev/null | tr -d '\r'; }

ESPERADOS="$(sed -n 's/^comprobantes_en_base=//p' "$TRABAJO/MANIFIESTO")"
FILAS="$(consulta 'SELECT COUNT(*) FROM comprobantes')"
[ "$FILAS" = "$ESPERADOS" ] || fallar "comprobantes restaurados: $FILAS; esperados: $ESPERADOS"
log "tablas restauradas: $(consulta "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$BASE'"); viajes: $(consulta 'SELECT COUNT(*) FROM viajes'); pagos: $(consulta 'SELECT COUNT(*) FROM pagos'); comprobantes: $FILAS"

log "verificando el sha256 de cada comprobante contra la base"
mkdir -p "$TRABAJO/pdf"
# Solo PDF dentro de comprobantes/AAAA/MM/ (nada fuera de ese árbol).
tar -tzf "$TRABAJO/comprobantes.tar.gz" | grep -vE '^comprobantes/([0-9]{4}/([0-9]{2}/([A-Za-z0-9_-]+\.pdf)?)?)?$' \
    && fallar "el paquete de comprobantes contiene rutas no esperadas"
tar -C "$TRABAJO/pdf" --no-same-owner --no-same-permissions -xzf "$TRABAJO/comprobantes.tar.gz"
ERRORES=0
while IFS=$'\t' read -r RUTA HASH; do
    [ -n "$RUTA" ] || continue
    [[ "$RUTA" =~ ^[0-9]{4}/[0-9]{2}/[A-Za-z0-9_-]+\.pdf$ ]] || { log "  ruta inválida en la base: $RUTA"; ERRORES=$((ERRORES + 1)); continue; }
    ARCHIVO_PDF="$TRABAJO/pdf/comprobantes/$RUTA"
    if [ ! -f "$ARCHIVO_PDF" ]; then
        log "  falta: $RUTA"; ERRORES=$((ERRORES + 1)); continue
    fi
    [ "$(sha256sum "$ARCHIVO_PDF" | cut -d' ' -f1)" = "$HASH" ] || { log "  hash distinto: $RUTA"; ERRORES=$((ERRORES + 1)); }
done < <(consulta 'SELECT archivo, sha256 FROM comprobantes')
[ "$ERRORES" = 0 ] || fallar "$ERRORES comprobantes faltantes o alterados"
log "comprobantes íntegros: $FILAS"

if [ "$COMPROBANTES" = 1 ]; then
    log "copiando los comprobantes al volumen de la app"
    tar -C "$TRABAJO/pdf" -czf - comprobantes | "${COMPOSE[@]}" exec -T app sh -c 'cd storage/app/private && tar -xzf -'
fi

if [ "$REEMPLAZAR" = 0 ] && [ "$CONSERVAR" = 0 ]; then
    # $BASE ya se validó con ^[A-Za-z0-9_]+$; limpiar() la borra al salir.
    log "prueba de restauración correcta; se borra la base temporal '$BASE'"
fi

log "fin: restauración verificada"
