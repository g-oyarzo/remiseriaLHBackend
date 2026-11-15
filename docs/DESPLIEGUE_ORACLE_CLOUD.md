# Despliegue en Oracle Cloud (Always Free, Ampere A1 arm64)

Guía paso a paso para el **primer despliegue gratuito**. Supone una cuenta de OCI ya creada. Este repo **no** crea cuentas, recursos ni despliegues: todo lo que figura acá lo ejecuta una persona del equipo.

> Antes de empezar, leer [ARQUITECTURA_Y_COSTOS.md](ARQUITECTURA_Y_COSTOS.md): cupos Always Free, riesgo de recuperación de instancias ociosas y por qué no se instala OSRM.

## 0. Requisitos

- Cuenta OCI con la región de origen elegida (Always Free solo en esa región).
- Un dominio o subdominio para la API (por ejemplo `api.remiserialh.com.ar`) y otro para el panel.
- Clave de openrouteservice (https://account.heigit.org, plan Standard gratuito).
- Mercado Pago: por ahora **no** cargar credenciales productivas (ver sección 9).

## 1. Crear la VM

1. *Compute → Instances → Create instance*.
2. Imagen: **Canonical Ubuntu 24.04** (aarch64). Forma: **VM.Standard.A1.Flex**, con **2 OCPU y 12 GB**. Se puede empezar con menos, siempre dentro del cupo Always Free.
3. Red: VCN con subred pública y **IP pública asignada**. Conviene reservar la IP (*Reserved public IP*) para que no cambie al recrear la instancia.
4. Volumen de arranque: 50 GB. Más adelante, si hace falta, sumar un volumen en bloques para datos; el total no puede pasar los 200 GB.
5. Cargar la clave SSH pública del administrador.
6. Si aparece *Out of host capacity*: probar otro *availability domain* o reintentar más tarde. No pasar a una forma paga.

## 2. Red y firewall

En la **Security List** (o NSG) de la subred, abrir solo:

| Puerto | Origen | Para qué |
|---|---|---|
| 22/tcp | IP fija del administrador (no `0.0.0.0/0` si se puede evitar) | SSH |
| 80/tcp | 0.0.0.0/0 | Let's Encrypt y redirección a HTTPS |
| 443/tcp | 0.0.0.0/0 | API y WebSockets |

**No** abrir 3306 (MySQL), 6379 (Redis), 8080 (Reverb) ni 9000 (PHP-FPM): no se publican fuera de Docker.

En la VM (las imágenes de Ubuntu de OCI traen reglas de iptables propias):

```bash
sudo iptables -I INPUT 6 -m state --state NEW -p tcp --dport 80 -j ACCEPT
sudo iptables -I INPUT 6 -m state --state NEW -p tcp --dport 443 -j ACCEPT
sudo netfilter-persistent save
```

## 3. Preparar Ubuntu

```bash
sudo apt-get update && sudo apt-get -y upgrade
sudo apt-get install -y ca-certificates curl git unattended-upgrades rclone python3
sudo dpkg-reconfigure -plow unattended-upgrades       # parches de seguridad automáticos
sudo timedatectl set-timezone America/Argentina/Buenos_Aires

# Docker Engine (repositorio oficial; publica paquetes arm64)
sudo install -m 0755 -d /etc/apt/keyrings
sudo curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/ubuntu $(. /etc/os-release && echo $VERSION_CODENAME) stable" \
  | sudo tee /etc/apt/sources.list.d/docker.list
sudo apt-get update && sudo apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
sudo usermod -aG docker "$USER"   # cerrar sesión y volver a entrar

# Swap de 2 GB como red de seguridad ante picos de memoria
sudo fallocate -l 2G /swapfile && sudo chmod 600 /swapfile && sudo mkswap /swapfile && sudo swapon /swapfile
echo '/swapfile none swap sw 0 0' | sudo tee -a /etc/fstab
```

SSH: desactivar el login por contraseña (`PasswordAuthentication no` en `/etc/ssh/sshd_config`) y el login de root.

## 4. Código y variables

```bash
sudo mkdir -p /opt/remiseria && sudo chown "$USER" /opt/remiseria
git clone <url-del-repo> /opt/remiseria/backend && cd /opt/remiseria/backend
git checkout <rama o tag aprobado>

cp deploy/env/production.env.example .env.production
chmod 600 .env.production
nano .env.production        # completar todos los <...>
```

Generar los secretos:

```bash
openssl rand -base64 32     # DB_PASSWORD, DB_ROOT_PASSWORD, REDIS_PASSWORD, REVERB_APP_SECRET
docker compose -f compose.prod.yaml --env-file .env.production build app
docker compose -f compose.prod.yaml --env-file .env.production run --rm --no-deps app php artisan key:generate --show   # APP_KEY
```

Notas:

- `REVERB_ALLOWED_ORIGINS`: Reverb compara el encabezado `Origin`. Las apps de React Native no lo envían por defecto: configurarlas para que envíen uno fijo, por ejemplo `Origin: https://apps.<dominio>`, e incluir `apps.<dominio>` en la lista junto con el panel. Los canales privados igual exigen autorización, así que el origen no es la única barrera.
- `APP_LOCALE=en`: el proyecto todavía no tiene traducciones al español (`lang/es`).
- `APP_URL` debe ser exactamente `https://<dominio-api>`: Laravel rechaza cualquier otro encabezado Host (`trustHosts`). Si hace falta otro nombre, agregarlo en `TRUSTED_HOSTS`.
- `API_DOCS_PUBLICA=false` y `REVERB_ACCEPT_CLIENT_EVENTS_FROM=none` (valores de la plantilla).

## 5. Certificado TLS (primera vez)

Con el DNS del dominio apuntando a la IP de la VM y el puerto 80 libre:

```bash
mkdir -p deploy/certbot/conf deploy/certbot/www
docker run --rm -p 80:80 -v "$PWD/deploy/certbot/conf:/etc/letsencrypt" certbot/certbot:v4.1.1 \
  certonly --standalone -d <dominio-api> --email <correo-del-equipo> --agree-tos --no-eff-email
```

La renovación es automática: el servicio `certbot` (perfil `tls`) ejecuta `renew` cada 12 h y NGINX recarga su configuración cada 12 h.

## 6. Primer arranque

```bash
docker compose -f compose.prod.yaml --env-file .env.production up -d --build mysql redis
docker compose -f compose.prod.yaml --env-file .env.production run --rm app php artisan migrate --force
docker compose -f compose.prod.yaml --env-file .env.production run --rm app php artisan db:seed --class=AdminSeeder --force   # usa ADMIN_PASSWORD
docker compose -f compose.prod.yaml --env-file .env.production --profile tls up -d --build
docker compose -f compose.prod.yaml --env-file .env.production ps
curl -fsS https://<dominio-api>/up
docker compose -f compose.prod.yaml --env-file .env.production exec app php artisan mapas:estado --probar   # consume 1 consulta de ORS
```

Después, desde el panel: cargar la tarifa vigente y las **zonas tarifarias de contingencia**. Sin zonas, la contingencia usa la distancia en línea recta × 1,4.

## 7. Redis, Reverb, workers y scheduler

Ya quedan corriendo como servicios de `compose.prod.yaml`, con `restart: unless-stopped`:

| Servicio | Comando | Verificación |
|---|---|---|
| `queue` | `queue:work` sobre Redis (BLPOP, `REDIS_QUEUE_BLOCK_FOR=5`) | `docker compose ... exec redis redis-cli LLEN queues:default` debe tender a 0 |
| `scheduler` | `schedule:work` | `docker compose ... exec app php artisan schedule:list` |
| `reverb` | `reverb:start` en `:8080` (interno), publicado por NGINX en `wss://<dominio-api>/app/<REVERB_APP_KEY>` | Conectar desde el panel |

Para escalar los workers: `docker compose -f compose.prod.yaml --env-file .env.production up -d --scale queue=2`.

## 8. Backups

```bash
sudo mkdir -p /etc/remiseria && openssl rand -base64 48 | sudo tee /etc/remiseria/backup.key >/dev/null
sudo chmod 600 /etc/remiseria/backup.key   # backup.sh se niega a usarla con permisos más abiertos
# backup.sh y restore.sh usan python3 (instalado en la sección 3) para el HMAC del backup.
# Guardar una copia de esta clave FUERA del servidor (gestor de contraseñas del equipo):
# sin ella los backups no se pueden descifrar.
```

1. OCI: crear un bucket **privado** `remiseria-backups` en Object Storage. Las copias entran en los 20 GB Always Free.
2. Crear una *Customer Secret Key* (API compatible con S3) para un usuario con permiso solo sobre ese bucket.
3. `rclone config` → *New remote* `oci`:
   - tipo `s3`;
   - proveedor `Other`;
   - endpoint `https://<namespace>.compat.objectstorage.<region>.oraclecloud.com`;
   - `access_key_id` y `secret_access_key` de la clave anterior.
4. Probar: `RLH_ENV_FILE=.env.production BACKUP_CLAVE_ARCHIVO=/etc/remiseria/backup.key deploy/backup/backup.sh`.
5. Programar con cron, todos los días a las 03:15:

```cron
15 3 * * * cd /opt/remiseria/backend && RLH_ENV_FILE=.env.production BACKUP_CLAVE_ARCHIVO=/etc/remiseria/backup.key deploy/backup/backup.sh >> /var/log/remiseria-backup.log 2>&1
```

6. **Una vez por mes**, probar una restauración con `deploy/backup/restore.sh <archivo>`. Restaura en una base aparte, verifica cada comprobante y la borra. Detalle en [deploy/backup/README.md](../deploy/backup/README.md).

## 9. Mercado Pago

1. Validar todo en **sandbox** en un entorno *staging*, con `deploy/env/staging.env.example`. El procedimiento está en [seguridad-y-despliegue.md](seguridad-y-despliegue.md#validación-de-mercado-pago-en-sandbox).
2. Recién después, cargar en `.env.production` las credenciales productivas, la clave secreta de Webhooks y la `MERCADOPAGO_PUBLIC_KEY`, y configurar en el panel de Mercado Pago los Webhooks (eventos "Pagos" y, si se habilitan los cobros automáticos, "Órdenes") apuntando a `https://<dominio-api>/api/v1/webhooks/mercadopago`.
3. `MERCADOPAGO_PAGOS_AUTOMATICOS` queda en `false` hasta que Mercado Pago habilite el producto "Pagos automáticos" a la cuenta.

## 10. Actualizaciones

```bash
cd /opt/remiseria/backend
deploy/backup/backup.sh                         # siempre antes de migrar (con las variables de la sección 8)
git fetch && git checkout <tag nuevo>
docker compose -f compose.prod.yaml --env-file .env.production build app nginx
docker compose -f compose.prod.yaml --env-file .env.production run --rm app php artisan migrate --force
docker compose -f compose.prod.yaml --env-file .env.production up -d
docker compose -f compose.prod.yaml --env-file .env.production exec queue php artisan queue:restart
curl -fsS https://<dominio-api>/up
```

Rollback: `git checkout <tag anterior>`, `build` y `up -d`. Si la versión nueva tenía migraciones, restaurar el backup previo (sección 11) o hacer `migrate:rollback --step=N` solo si las migraciones lo permiten sin perder datos.

## 11. Recuperación ante fallas

| Falla | Qué hacer |
|---|---|
| Un contenedor se cae | Se reinicia solo (`restart: unless-stopped`). Revisar `docker compose ... logs <servicio>`. |
| Reverb o el worker caen | La operación sigue: los pings y pagos se registran, pero no se difunden en tiempo real. Reiniciar el servicio. Los jobs fallidos quedan en `failed_jobs` (`php artisan queue:retry all`). |
| openrouteservice no responde o se agotó la cuota | Automático: contingencia por zonas. Ver `php artisan mapas:estado`. |
| Mercado Pago no responde | Los cobros devuelven 503 y se cobra en efectivo. La conciliación de cada 10 minutos recupera los webhooks perdidos. |
| Disco lleno | `docker system df`; los logs están rotados (10 MB × 5 por servicio); `docker image prune`. |
| Se perdió la VM (incluida la recuperación por inactividad) | Crear una VM nueva (secciones 1 a 5) → `up -d mysql redis` → `RLH_ENV_FILE=.env.production deploy/backup/restore.sh <último backup> --reemplazar-base-actual --restaurar-comprobantes` (la base nueva está vacía; confirmar el nombre) → `up -d`. Objetivo: menos de 1 hora. |

## 12. Checklist antes de abrir al público

- [ ] `APP_DEBUG=false`, `APP_KEY` propio y secretos generados (sección 4).
- [ ] Solo 22 (restringido), 80 y 443 abiertos; `docker ps` muestra puertos publicados únicamente en NGINX.
- [ ] HTTPS válido y renovación probada (`docker compose ... run --rm certbot renew --dry-run`).
- [ ] Backup diario programado, copia remota verificada y **restauración probada**.
- [ ] `mapas:estado --probar` correcto; zonas de contingencia cargadas.
- [ ] Mercado Pago validado en sandbox (o deshabilitado, con el token vacío).
- [ ] Pruebas de carga ejecutadas **en la VM** o en una VM equivalente ([deploy/carga/README.md](../deploy/carga/README.md)), con los resultados anotados.
