#!/usr/bin/env bash
# =============================================================================
# Backup de Remisería LH: base MySQL + comprobantes (storage/app/private/comprobantes).
#
# Genera UN archivo cifrado por corrida:
#   remiseria-<AAAAMMDD-HHMMSS>.tar.enc   (AES-256-CBC, PBKDF2 200.000 iteraciones)
#   remiseria-<AAAAMMDD-HHMMSS>.tar.enc.sha256
# Dentro: db.sql.gz (mysqldump --single-transaction: consistente sin
# bloquear la operación), comprobantes.tar.gz y MANIFIESTO (sha256 de cada
# parte, cantidad de comprobantes y versión de migraciones).
#
# Luego lo sube con rclone (Oracle Object Storage u otro destino) y aplica la
# retención local y remota. Las ÚNICAS copias nunca quedan solo en el servidor.
#
# Uso (en el servidor, desde la raíz del repo):
#   BACKUP_CLAVE_ARCHIVO=/etc/remiseria/backup.key deploy/backup/backup.sh
#
# Variables:
#   RLH_ENV_FILE                 .env del despliegue (default .env.production)
#   RLH_COMPOSE                  comando compose (default: docker compose -f compose.prod.yaml --env-file $RLH_ENV_FILE)
#   BACKUP_CLAVE_ARCHIVO         (obligatoria) archivo con la frase de cifrado, chmod 600
#   BACKUP_DIR                   destino local (default deploy/backup/archivos.local)
#   BACKUP_RCLONE_DESTINO        remoto de rclone, p. ej. oci:remiseria-backups (vacío = no sube)
#   BACKUP_RETENCION_LOCAL_DIAS  default 7
#   BACKUP_RETENCION_REMOTA_DIAS default 30
# =============================================================================
set -Eeuo pipefail
umask 077

RAIZ="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$RAIZ"

RLH_ENV_FILE="${RLH_ENV_FILE:-.env.production}"
leer_env() { sed -n "s/^$1=//p" "$RLH_ENV_FILE" | tail -1 | tr -d '"'; }

# Sin ":" a propósito: definida vacía = no subir; sin definir = valor del .env.
BACKUP_RCLONE_DESTINO="${BACKUP_RCLONE_DESTINO-$(leer_env BACKUP_RCLONE_DESTINO)}"
BACKUP_RETENCION_LOCAL_DIAS="${BACKUP_RETENCION_LOCAL_DIAS:-$(leer_env BACKUP_RETENCION_LOCAL_DIAS)}"
BACKUP_RETENCION_REMOTA_DIAS="${BACKUP_RETENCION_REMOTA_DIAS:-$(leer_env BACKUP_RETENCION_REMOTA_DIAS)}"
BACKUP_RETENCION_LOCAL_DIAS="${BACKUP_RETENCION_LOCAL_DIAS:-7}"
BACKUP_RETENCION_REMOTA_DIAS="${BACKUP_RETENCION_REMOTA_DIAS:-30}"
BACKUP_DIR="${BACKUP_DIR:-$RAIZ/deploy/backup/archivos.local}"
read -r -a COMPOSE <<< "${RLH_COMPOSE:-docker compose -f compose.prod.yaml --env-file $RLH_ENV_FILE}"

log() { printf '%s [backup] %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*"; }
fallar() { log "ERROR: $*"; exit 1; }

[ -f "$RLH_ENV_FILE" ] || fallar "no existe $RLH_ENV_FILE"
[ -n "${BACKUP_CLAVE_ARCHIVO:-}" ] && [ -s "$BACKUP_CLAVE_ARCHIVO" ] || fallar "BACKUP_CLAVE_ARCHIVO no definido o vacío"
command -v openssl >/dev/null || fallar "falta openssl"
PYTHON="${PYTHON:-python3}"
"$PYTHON" -I -c 'import hmac' 2>/dev/null || fallar "falta python3 (necesario para el HMAC del backup)"
# La clave debe ser legible solo por el dueño (no 0644).
if [ "$(uname -s)" = "Linux" ] && [ -n "$(find "$BACKUP_CLAVE_ARCHIVO" -perm /077 2>/dev/null)" ]; then
    fallar "BACKUP_CLAVE_ARCHIVO tiene permisos demasiado abiertos (usar chmod 600)"
fi

mkdir -p "$BACKUP_DIR"
case "$(cd "$(dirname "$BACKUP_CLAVE_ARCHIVO")" && pwd)/" in
    "$(cd "$BACKUP_DIR" && pwd)/"*) fallar "la clave no puede guardarse dentro de BACKUP_DIR (viajaría junto con los backups)" ;;
esac

SELLO="$(date '+%Y%m%d-%H%M%S')"
TRABAJO="$(mktemp -d)"
trap 'rm -rf "$TRABAJO"' EXIT
mkdir -p "$BACKUP_DIR"

log "volcando MySQL (single-transaction)"
"${COMPOSE[@]}" exec -T mysql sh -c 'exec mysqldump --single-transaction --quick --routines --triggers --events --hex-blob --set-gtid-purged=OFF --no-tablespaces -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' \
    2> "$TRABAJO/mysqldump.err" | gzip -9 > "$TRABAJO/db.sql.gz" || { grep -v 'Using a password' "$TRABAJO/mysqldump.err" >&2 || true; fallar "mysqldump falló"; }
gzip -t "$TRABAJO/db.sql.gz" || fallar "el dump comprimido está dañado"
zcat "$TRABAJO/db.sql.gz" | tail -1 | grep -q 'Dump completed' || fallar "el dump no terminó correctamente"

log "empaquetando comprobantes"
"${COMPOSE[@]}" exec -T app sh -c 'cd storage/app/private && mkdir -p comprobantes && tar -czf - comprobantes' > "$TRABAJO/comprobantes.tar.gz" \
    || fallar "no se pudieron copiar los comprobantes"
tar -tzf "$TRABAJO/comprobantes.tar.gz" >/dev/null || fallar "el paquete de comprobantes está dañado"

CANTIDAD_ARCHIVOS="$(tar -tzf "$TRABAJO/comprobantes.tar.gz" | grep -c '\.pdf$' || true)"
CANTIDAD_FILAS="$("${COMPOSE[@]}" exec -T mysql sh -c 'mysql -N -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE" -e "SELECT COUNT(*) FROM comprobantes"' 2>/dev/null | tr -d '\r')"
ULTIMA_MIGRACION="$("${COMPOSE[@]}" exec -T mysql sh -c 'mysql -N -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE" -e "SELECT migration FROM migrations ORDER BY id DESC LIMIT 1"' 2>/dev/null | tr -d '\r')"

if [ "${CANTIDAD_ARCHIVOS}" != "${CANTIDAD_FILAS}" ]; then
    log "ADVERTENCIA: $CANTIDAD_FILAS comprobantes en la base y $CANTIDAD_ARCHIVOS PDF en disco"
fi

( cd "$TRABAJO" && {
    echo "sello=$SELLO"
    echo "comprobantes_en_base=$CANTIDAD_FILAS"
    echo "comprobantes_en_disco=$CANTIDAD_ARCHIVOS"
    echo "ultima_migracion=$ULTIMA_MIGRACION"
    sha256sum db.sql.gz comprobantes.tar.gz
} > MANIFIESTO )

NOMBRE="remiseria-$SELLO.tar.enc"
log "cifrando $NOMBRE"
tar -C "$TRABAJO" -cf - MANIFIESTO db.sql.gz comprobantes.tar.gz \
    | openssl enc -aes-256-cbc -pbkdf2 -iter 200000 -salt -pass "file:$BACKUP_CLAVE_ARCHIVO" -out "$BACKUP_DIR/$NOMBRE"
( cd "$BACKUP_DIR" && sha256sum "$NOMBRE" > "$NOMBRE.sha256" )
# Autenticación del archivo cifrado (SEC-09): restore.sh la verifica antes de descifrar.
"$PYTHON" -I "$RAIZ/deploy/backup/hmac.py" firmar "$BACKUP_CLAVE_ARCHIVO" "$BACKUP_DIR/$NOMBRE" > "$BACKUP_DIR/$NOMBRE.hmac" \
    || fallar "no se pudo calcular el HMAC"

# Prueba de lectura inmediata: el archivo se puede descifrar y listar.
openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 -pass "file:$BACKUP_CLAVE_ARCHIVO" -in "$BACKUP_DIR/$NOMBRE" | tar -tf - >/dev/null \
    || fallar "el archivo cifrado no se pudo verificar"

log "backup local listo: $BACKUP_DIR/$NOMBRE ($(du -h "$BACKUP_DIR/$NOMBRE" | cut -f1))"

if [ -n "$BACKUP_RCLONE_DESTINO" ]; then
    command -v rclone >/dev/null || fallar "falta rclone para subir a $BACKUP_RCLONE_DESTINO"
    log "subiendo a $BACKUP_RCLONE_DESTINO"
    rclone copy --immutable "$BACKUP_DIR/$NOMBRE" "$BACKUP_RCLONE_DESTINO/"
    rclone copy --immutable "$BACKUP_DIR/$NOMBRE.sha256" "$BACKUP_RCLONE_DESTINO/"
    rclone copy --immutable "$BACKUP_DIR/$NOMBRE.hmac" "$BACKUP_RCLONE_DESTINO/"
    rclone check --one-way --include "$NOMBRE" "$BACKUP_DIR" "$BACKUP_RCLONE_DESTINO" || fallar "la copia remota no coincide"
    log "retención remota: borrando copias de más de $BACKUP_RETENCION_REMOTA_DIAS días"
    rclone delete --min-age "${BACKUP_RETENCION_REMOTA_DIAS}d" --include 'remiseria-*.tar.enc*' "$BACKUP_RCLONE_DESTINO/"
else
    log "ADVERTENCIA: BACKUP_RCLONE_DESTINO vacío; la copia quedó SOLO en este servidor"
fi

log "retención local: borrando copias de más de $BACKUP_RETENCION_LOCAL_DIAS días"
find "$BACKUP_DIR" -maxdepth 1 -name 'remiseria-*.tar.enc*' -mtime "+$BACKUP_RETENCION_LOCAL_DIAS" -print -delete

log "fin"
