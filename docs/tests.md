# Ejecución de Tests — Remisería LH (Backend)

La suite de pruebas automatizadas valida la seguridad, integridad de base de datos, emisiones en tiempo real y flujos de negocio del proyecto.

## ⚠️ Requisitos Previos (Muy Importante)

A diferencia de muchos proyectos Laravel que pueden usar **SQLite en memoria** para correr pruebas, este proyecto **requiere obligatoriamente un motor MySQL**. 

El motivo de esto es que el sistema requiere soporte para **Índices y Funciones Geoespaciales** (como `ST_GeomFromText`) para cumplir con los requerimientos no funcionales (RNF02) de rastreo GPS en tiempo real de los conductores y registro de viajes (coordenadas de origen y destino). SQLite estándar no soporta de forma nativa estas características espaciales sin extensiones (Spatialite).

El archivo `phpunit.xml` está pre-configurado para conectarse al servicio MySQL del contenedor Docker (`DB_HOST=mysql`) y a la base de datos `remiserialh_testing`, que es creada automáticamente por el script de inicialización de Sail al levantar los contenedores.

---

## 🚀 Cómo ejecutar los tests

Los contenedores Docker deben estar levantados antes de correr los tests:

```bash
docker compose up -d
```

### En Windows (sin WSL2) — Método recomendado

Ya que el hostname `mysql` solo es resolvible dentro de la red Docker, los tests deben ejecutarse **dentro del contenedor** de la aplicación Laravel:

```bash
docker exec remiserialhbackend-laravel.test-1 php artisan test
```

### En Linux / macOS / Windows con WSL2 — usando Laravel Sail

```bash
./vendor/bin/sail test
```

---

## 🗂️ Suites de Pruebas Implementadas

Las pruebas se dividen en diferentes características bajo el directorio `tests/Feature/`:

1. **`Database\MigrationTest`**: Verifica que las tablas requeridas por el diagrama relacional, así como las claves foráneas, existan y estén estructuradas sin problemas.
2. **`Auth\LoginTest` y `Auth\AuthorizationTest`**: Garantiza la generación de tokens vía Sanctum y comprueba rigurosamente que el middleware Role-Based Access Control (RBAC) impida a clientes acceder a rutas de administrador o conductor, y viceversa.
3. **`Broadcasting\BroadcastingTest`**: Emula y valida el servicio de WebSockets (Reverb). Verifica la correcta autorización de canales privados basados en la titularidad de los viajes, y que los eventos de dominio (`UbicacionConductorActualizada`, `NuevoMensajeViaje`) sean empujados a los canales designados tras las peticiones HTTP correspondientes.
4. **`Api\ViajeTest`**: Simula todo el ciclo de vida, cubriendo validaciones donde un cliente solicita un viaje con coordenadas espaciales, y un conductor acepta el servicio.

---

## 💡 Consejos Útiles

- **Ejecutar una prueba o archivo en específico:**
  Si solo querés correr los tests de WebSockets:
  ```bash
  php artisan test --filter BroadcastingTest
  ```
- **Ver reportes detallados:**
  Para ver la información de completitud y los tests que más tiempo demoran:
  ```bash
  php artisan test --profile
  ```