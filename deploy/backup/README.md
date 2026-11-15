# Backups de Remisería LH

Respaldan **la base MySQL** y **los comprobantes** (`storage/app/private/comprobantes`). Sin los PDF, el registro inmutable de comprobantes no se puede descargar: siempre se respaldan juntos.

## Qué hace `backup.sh`

1. `mysqldump --single-transaction --quick --routines --triggers --events`: copia consistente sin bloquear la operación (InnoDB). Verifica que el dump terminó (`Dump completed`).
2. Empaqueta los comprobantes y compara la cantidad de PDF con la de filas en `comprobantes`.
3. Escribe un `MANIFIESTO` con el SHA-256 de cada parte, la cantidad de comprobantes y la última migración.
4. Cifra todo en un único archivo, `remiseria-<fecha>.tar.enc`:
   - AES-256-CBC con PBKDF2 de 200.000 iteraciones y sal;
   - la clave sale de `BACKUP_CLAVE_ARCHIVO`, que **no** está en el `.env`.
5. Genera `.sha256` y **`.hmac`**: HMAC-SHA256 del archivo cifrado (encrypt-then-MAC), con una clave derivada de la misma frase. Lo calcula `hmac.py` con Python 3, que lee la clave del archivo, así que no aparece en la lista de procesos. También prueba en el momento que el archivo se puede descifrar y listar. Exige que la clave tenga permisos 600 y que no esté dentro de `BACKUP_DIR`.
6. Lo sube con `rclone` (`BACKUP_RCLONE_DESTINO`) y comprueba la copia remota con `rclone check`. Si no hay destino, avisa que la copia quedó **solo** en el servidor.
7. Retención: local de `BACKUP_RETENCION_LOCAL_DIAS` (7) y remota de `BACKUP_RETENCION_REMOTA_DIAS` (30).

## Qué hace `restore.sh`

- Verifica el **HMAC antes de descifrar**: un archivo alterado en el destino remoto se rechaza sin procesarlo. Los backups anteriores a esta versión, que no tienen `.hmac`, requieren `--permitir-sin-hmac`.
- Exige que el contenido tenga exactamente `MANIFIESTO`, `db.sql.gz` y `comprobantes.tar.gz`, y que los comprobantes estén bajo `comprobantes/AAAA/MM/*.pdf`. Extrae sin conservar dueño ni permisos.
- Por defecto restaura en una **base nueva**, `<base>_restaurada_<sello>`, y no toca la base en uso. `--base` se niega si la base ya existe o si es una base del sistema (`mysql`, `sys`, `information_schema`, `performance_schema`).
- Verifica: el SHA-256 del archivo, el descifrado, el MANIFIESTO, la cantidad de comprobantes y el **SHA-256 de cada PDF** contra la columna `comprobantes.sha256` de la base restaurada.
- Si todo está bien, borra la base de prueba. Si algo falla, también la borra y termina con error.
- Para recuperar de verdad: `--reemplazar-base-actual --restaurar-comprobantes`, con la app detenida. Pide escribir el nombre de la base para confirmar.

## Uso

```bash
# Backup (en el servidor, desde la raíz del repo)
RLH_ENV_FILE=.env.production BACKUP_CLAVE_ARCHIVO=/etc/remiseria/backup.key deploy/backup/backup.sh

# Prueba de restauración (mensual)
rclone copy oci:remiseria-backups/remiseria-20261115-031500.tar.enc /tmp/r/
rclone copy oci:remiseria-backups/remiseria-20261115-031500.tar.enc.sha256 /tmp/r/
RLH_ENV_FILE=.env.production BACKUP_CLAVE_ARCHIVO=/etc/remiseria/backup.key deploy/backup/restore.sh /tmp/r/remiseria-20261115-031500.tar.enc
```

## Destino gratuito: OCI Object Storage

Configuración en [docs/DESPLIEGUE_ORACLE_CLOUD.md](../../docs/DESPLIEGUE_ORACLE_CLOUD.md#8-backups): bucket privado, *Customer Secret Key* y remoto S3 en rclone.

Recomendaciones:

- Usuario de OCI con permiso **solo** sobre ese bucket.
- **Retention rules** del bucket para que una credencial robada no pueda borrar las copias.

Capacidad (20 GB Always Free):

- Proyecto universitario o crecimiento moderado: menos de 1 GB.
- Operación completa (~4,4 MB de PDF por día): con copias completas diarias y 30 días de retención no entra en el primer año. Estrategia:
  - dump diario con 14 días de retención (`BACKUP_RETENCION_REMOTA_DIAS=14`);
  - **comprobantes incrementales**: son inmutables, así que alcanza con copiar los nuevos:
    ```bash
    docker compose -f compose.prod.yaml --env-file .env.production cp app:/var/www/html/storage/app/private/comprobantes /var/backups/comprobantes
    rclone copy --immutable /var/backups/comprobantes oci:remiseria-comprobantes
    ```
  - una copia completa con `backup.sh` por semana.

## Prueba realizada (2026-11-15, entorno local con `compose.prod.yaml`)

| Prueba | Resultado |
|---|---|
| Backup con 5 viajes, 5 pagos y 5 comprobantes; subida con rclone 1.69.1 a un "remoto" local | Correcto; `rclone check`: 0 diferencias |
| Restauración desde la copia remota en una base nueva | 25 tablas, 5 viajes, 5 pagos, 5 comprobantes; los 5 PDF verificados; base de prueba borrada |
| Clave incorrecta | Rechazada ("no se pudo descifrar") |
| Archivo cifrado alterado (1 byte) | Rechazado (MANIFIESTO no coincide) |
| PDF modificado en el servidor antes del backup | Detectado al restaurar ("hash distinto"); base de prueba borrada |
| `BACKUP_RCLONE_DESTINO` vacío | Copia local con advertencia explícita |

No se probó contra OCI Object Storage real porque no hay cuenta: la subida se probó con rclone apuntando a un directorio local.

### Prueba de la auditoría de seguridad (2026-11-15)

| Prueba | Resultado |
|---|---|
| Backup con HMAC y restauración válida | 5 viajes, 5 pagos y 5 PDF verificados |
| Archivo cifrado alterado (1 byte), con sus `.sha256` y `.hmac` originales | Rechazado por HMAC **antes de descifrar** |
| Backup sin `.hmac` | Rechazado (requiere `--permitir-sin-hmac`) |
| `--base mysql` | Rechazado (base del sistema) |
| `--base` sobre una base existente | Rechazado |
