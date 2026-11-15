#!/bin/sh
# Punto de entrada común de los contenedores app, queue, scheduler y reverb.
#
# - Cachea la configuración, las rutas y los eventos (lee las variables de
#   entorno del contenedor; nunca se copia un .env a la imagen).
# - Las migraciones NO se ejecutan solas: se corren a mano en cada despliegue
#   (docs/DESPLIEGUE_ORACLE_CLOUD.md), después de un backup.
set -eu

export PHP_FPM_MAX_CHILDREN="${PHP_FPM_MAX_CHILDREN:-24}"

if [ "${APP_ENV:-production}" != "local" ]; then
    php artisan config:cache >/dev/null
    php artisan route:cache >/dev/null
    php artisan event:cache >/dev/null
fi

exec "$@"
