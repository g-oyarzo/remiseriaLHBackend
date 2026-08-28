# Remisería LH — Backend

API REST en Laravel para el sistema de gestión de Remisería LH.

## Stack
Laravel 13 (PHP 8.3) + MySQL + Laravel Sanctum (autenticación).

## Instalación y Ejecución Local

Para garantizar la compatibilidad total con las funciones geoespaciales (SRID 4326 y `ST_GeomFromText`) usadas en la base de datos, se recomienda fuertemente el uso de **Laravel Sail** (Docker).

### Prerrequisitos
- Docker Desktop o Docker Engine instalado.
- PHP 8.3 (solo si deseas instalar las dependencias fuera del contenedor).

### Pasos
1. Clona el repositorio e instala las dependencias (si no tienes PHP localmente, usa un contenedor temporal, o instala via Composer):
```bash
composer install
```

2. Configura las variables de entorno:
```bash
cp .env.example .env
php artisan key:generate
```

3. Levanta los contenedores (MySQL y la aplicación):
```bash
./vendor/bin/sail up -d
```
*(En Windows puedes ejecutar `docker compose up -d` si no utilizas WSL).*

4. Ejecuta las migraciones:
```bash
./vendor/bin/sail artisan migrate
```

5. (Opcional) Simula un año de operación con carga de datos masiva:
```bash
./vendor/bin/sail artisan simular:anual
```

### Ejecutar Tests
El proyecto requiere **MySQL** para las pruebas debido al soporte de **Índices Espaciales (Spatial Indexes)**. Se ha configurado el `phpunit.xml` para utilizar la conexión del contenedor local.

```bash
./vendor/bin/sail test
```
O de forma nativa si tu MySQL local expone el puerto 3306:
```bash
php artisan test
```

---
Repo general del proyecto: [remiseriaLH-docs](https://github.com/MarianoSanchez16/remiseriaLH-docs.git)