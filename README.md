# FlexArena

**Sistema de Gestión Deportiva Modular**

Plataforma web para organizar, administrar y consultar torneos deportivos, mentales y electrónicos.

---

## Inicio rápido

### Requisitos

- Docker Desktop (Windows/Mac/Linux)

### Levantar el proyecto

```bash
# 1. Copiar variables de entorno
cp .env.example .env

# 2. Levantar todos los contenedores
docker compose up -d

# 3. Acceder a la aplicación
#    App:       http://localhost:8080
#    phpMyAdmin: http://localhost:8081
```

La base de datos se inicializa automáticamente con el schema y seed al primer inicio.

### Credenciales de prueba

| Rol           | Email                    | Contraseña  |
|---------------|--------------------------|-------------|
| Administrador | admin@flexarena.com      | Adm!n-Flex2026#Tech |
| Organizador   | org1@flexarena.com       | admin123    |
| Organizador   | org2@flexarena.com       | admin123    |
| Participante  | matias@example.com       | admin123    |

> **Nota:** Cambiá las contraseñas en producción desde Panel Admin → Usuarios → Editar.

---

## Estructura del proyecto

```
sgdm/
├── app/
│   ├── controllers/   — Reciben requests, validan, llaman servicios
│   ├── models/        — Acceso a datos con PDO
│   ├── services/      — Lógica de negocio (formatos de torneo)
│   └── views/         — Plantillas PHP con layouts
├── config/            — Configuración de la app y rutas
├── core/              — Router, Database, Session, Auth, View, CSRF
├── database/          — schema.sql, seed.sql, scripts SQL
├── docs/              — Documentación completa
├── public/            — Front controller y assets (CSS, JS, img)
├── scripts/           — Scripts de administración y backup
├── Dockerfile
├── docker-compose.yml
└── .env.example
```

---

## Formatos de torneo

| Formato | Descripción |
|---------|-------------|
| **Liga** | Round-robin todos contra todos. Tabla de posiciones con criterios de desempate. |
| **Eliminación Directa** | Bracket con avance de ganadores. Soporta byes para N no potencia de 2. |
| **Sistema Suizo** | Rondas por rendimiento acumulado. Emparejamiento sin repetición de rivales. |

---

## Roles del sistema

- **Administrador**: acceso total
- **Organizador**: gestiona torneos asignados
- **Participante**: consulta sus torneos y resultados
- **Público**: vista pública sin autenticación

---

## Documentación

Ver carpeta `/docs/`:
- `documentacion_funcional.md`
- `documentacion_tecnica.md`
- `documentacion_seguridad.md`
- `manual_usuario.md`
- `manual_administrador.md`
- `plan_testing.md`
- `owasp.md`
