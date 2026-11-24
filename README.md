# Biblioteca API (PHP)

API REST para gestionar el catálogo y los préstamos de una biblioteca, hecha con **PHP 8 puro** (sin frameworks): router propio, PDO + SQLite, autenticación con tokens Bearer, validación de entrada, paginación, errores JSON consistentes y una suite de **48 pruebas de integración** sin dependencias.

## Funcionalidades

- Registro, login y logout con tokens Bearer (expiran a las 24 h).
- Catálogo público de libros con búsqueda, filtro por autor, disponibilidad, orden y paginación.
- CRUD de libros protegido (ISBN único, ajuste automático de copias disponibles al cambiar el total).
- Préstamos y devoluciones por usuario, con fecha de vencimiento (14 días) e indicador `overdue`.
- Historial de préstamos del usuario autenticado, filtrable por `active` / `returned`.

## Decisiones de diseño

- **Tokens hasheados**: el token en claro se entrega una sola vez; en la base de datos solo se guarda su SHA-256.
- **Contraseñas** con `password_hash`; el login verifica siempre contra un hash para no filtrar por tiempo si el correo existe.
- **Concurrencia**: los préstamos y devoluciones corren en una transacción `BEGIN IMMEDIATE`, de modo que dos peticiones simultáneas no pueden llevarse la última copia.
- **Integridad en la BD**: un índice único parcial impide dos préstamos activos del mismo libro por usuario; `CHECK` mantiene `0 ≤ copias disponibles ≤ total`.
- **Consultas preparadas** en todo el acceso a datos; el orden (`sort`) usa una lista blanca y el `LIKE` escapa `%` y `_`.
- **Errores uniformes**: todas las respuestas de error siguen el mismo formato y usan el código HTTP adecuado (400, 401, 404, 405, 409, 422, 500).
- Los errores internos no exponen detalles al cliente (se registran en el log); con `API_DEBUG=1` se muestra el mensaje.

## Requisitos

- PHP 8.2 o superior con `pdo_sqlite` y `mbstring`.

## Cómo ejecutarla

```bash
cd biblioteca-api-php
php -S localhost:8081 -t public public/index.php
```

La base de datos SQLite se crea sola en `database/biblioteca.sqlite` con 6 libros y un usuario demo (`demo@example.com` / `demo12345`). Para reiniciarla, borra ese archivo.

### Pruebas

Con el servidor levantado, en otra terminal:

```bash
php tests/run.php http://localhost:8081
```

> Las pruebas crean usuarios y libros con datos aleatorios, así que se pueden ejecutar varias veces sobre la misma base de datos.

## Endpoints

| Método | Ruta | Auth | Descripción |
|---|---|:-:|---|
| GET | `/api/health` | | Estado del servicio |
| POST | `/api/register` | | Crear cuenta (`name`, `email`, `password` ≥ 8) |
| POST | `/api/login` | | Obtener token Bearer |
| GET | `/api/me` | ✔ | Usuario autenticado |
| POST | `/api/logout` | ✔ | Revocar el token actual |
| GET | `/api/books` | | Listar: `search`, `author`, `available=true`, `sort`, `page`, `per_page` |
| GET | `/api/books/{id}` | | Detalle de un libro |
| POST | `/api/books` | ✔ | Crear libro |
| PUT / PATCH | `/api/books/{id}` | ✔ | Actualizar (los campos omitidos se conservan) |
| DELETE | `/api/books/{id}` | ✔ | Eliminar (409 si tiene préstamos activos) |
| POST | `/api/books/{id}/loan` | ✔ | Pedir prestado |
| POST | `/api/books/{id}/return` | ✔ | Devolver |
| GET | `/api/loans` | ✔ | Mis préstamos: `status=active\|returned`, `page`, `per_page` |

`sort` acepta `title`, `author`, `year`, `created_at`; con prefijo `-` el orden es descendente (`sort=-year`). `per_page` admite hasta 100.

## Ejemplos

```bash
# Iniciar sesión y guardar el token
TOKEN=$(curl -s -X POST localhost:8081/api/login \
  -H 'Content-Type: application/json' \
  -d '{"email":"demo@example.com","password":"demo12345"}' | php -r 'echo json_decode(stream_get_contents(STDIN), true)["data"]["access_token"];')

# Buscar libros disponibles de un autor, más recientes primero
curl -s 'localhost:8081/api/books?author=garcia&available=true&sort=-year'

# Crear un libro
curl -s -X POST localhost:8081/api/books \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"title":"Libro de ejemplo","author":"Autora Ejemplo","isbn":"9780000000002","year":2020,"copies_total":2}'

# Pedir prestado y devolver
curl -s -X POST localhost:8081/api/books/1/loan   -H "Authorization: Bearer $TOKEN"
curl -s -X POST localhost:8081/api/books/1/return -H "Authorization: Bearer $TOKEN"
```

### Formato de respuestas

Éxito (listados con paginación):

```json
{
  "data": [{ "id": 1, "title": "Cien años de soledad", "author": "Gabriel García Márquez", "isbn": null, "year": 1967, "copies_total": 3, "copies_available": 3, "created_at": "2026-11-23T22:00:00Z", "updated_at": "2026-11-23T22:00:00Z" }],
  "meta": { "page": 1, "per_page": 10, "total": 6, "total_pages": 1 }
}
```

Error de validación (`422`):

```json
{
  "error": {
    "code": "validation_error",
    "message": "Los datos enviados no son válidos.",
    "details": { "title": ["El campo es obligatorio."], "year": ["Debe ser un número entero."] }
  }
}
```

## Estructura

```
biblioteca-api-php/
├── public/index.php          # Front controller: captura la petición y envía la respuesta
├── src/
│   ├── bootstrap.php         # Autoload PSR-4 y utilidades
│   ├── routes.php            # Definición de rutas (auth: true para las protegidas)
│   ├── Auth.php              # Emisión, verificación y revocación de tokens
│   ├── Http/                 # Request, Response, Router, HttpException
│   ├── Support/              # Database (+ transacciones), Validator, Paginator
│   └── Controllers/          # Auth, Book, Loan, Health
└── tests/run.php             # Pruebas de integración contra el servidor HTTP
```

## Ideas para ampliarla

- Límite de peticiones (rate limiting) en `/api/login`.
- Roles (bibliotecario / lector) para restringir el CRUD de libros.
- Documentación OpenAPI y pruebas unitarias con PHPUnit.
