# Appointment Assistant

Sistema conversacional para la gestión de citas en negocios de servicios.

Appointment Assistant permite a los clientes consultar información del negocio, comprobar disponibilidad y gestionar citas mediante conversaciones en lenguaje natural.

## V1

La V1 está diseñada para cubrir:

- consultas sobre el negocio, los servicios y los profesionales;
- búsqueda de disponibilidad;
- identificación mínima y persistente del cliente antes de crear una cita;
- creación de citas;
- consulta de citas existentes;
- reprogramación de citas;
- cancelación de citas;
- conversaciones coherentes de varios turnos;
- notificaciones a los profesionales;
- recordatorios de citas a los clientes;
- sincronización con la agenda operativa del negocio;
- historial de auditoría de cambios sobre las citas.

La interacción real con clientes se realiza mediante WhatsApp.

La V1 también incluye una experiencia web pública y aislada de los clientes reales del negocio, con:

- presentación de Appointment Assistant;
- chat conversacional conectado al backend real;
- calendario visual de solo lectura;
- actualización visible del calendario cuando una cita se crea, reprograma o cancela desde la conversación.

## Arquitectura

Laravel actúa como núcleo autoritativo de la aplicación.

Las reglas de negocio, la validación de disponibilidad y el estado autoritativo de las citas pertenecen a la aplicación y no al LLM, los canales de comunicación ni las integraciones externas.

La V1 sigue una arquitectura de monolito modular pragmático.

```text
WhatsApp ──► n8n ──┐
                   │
Public Web ─────────┼──► Laravel ──► PostgreSQL
                   │       │
                   │       ├──► Redis / Queues
                   │       └──► OpenAI
                   │
Laravel ─────────► n8n ──► WhatsApp
                        └──► Google Calendar
```

n8n se utiliza de forma selectiva para flujos de integración con proveedores externos. No contiene las reglas de negocio ni mantiene el estado autoritativo de las citas.

## Estructura del repositorio

```text
appointment-assistant/
├── .github/
│   └── workflows/
├── backend/
├── public-web/
├── docs/
├── AGENTS.md
├── docker-compose.yml
└── README.md
```

### `backend/`

Aplicación Laravel que contiene el núcleo del sistema.

Es responsable de:

- reglas de negocio;
- operaciones sobre citas;
- persistencia;
- API;
- procesamiento asíncrono;
- integración con IA;
- límites con integraciones externas.

### `public-web/`

Aplicación independiente desarrollada con React, TypeScript y Vite.

Contiene la web pública y la experiencia de demostración de la V1.

El frontend no implementa reglas de negocio de citas ni de disponibilidad.

## Tecnologías

### Backend

- PHP 8.3+
- Laravel 13
- PostgreSQL
- Redis
- Laravel Queues
- Laravel AI SDK
- OpenAI

### Frontend

- React
- TypeScript
- Vite
- pnpm

### Integraciones

- n8n
- WhatsApp Business
- Google Calendar

### Calidad y entrega

- PHPUnit
- Playwright
- GitHub Actions
- Docker Compose

## Desarrollo local

### Requisitos

Es necesario disponer de:

- PHP 8.3+
- Composer
- Node.js
- pnpm
- Docker Engine con Docker Compose

### Infraestructura

Desde la raíz del repositorio:

```bash
docker compose up -d
```

Esto inicia PostgreSQL y Redis para el entorno local.

El estado de los servicios puede comprobarse con:

```bash
docker compose ps
```

### Backend

Consulta [`backend/README.md`](backend/README.md) para la configuración del entorno, instalación y ejecución de tests del backend.

### Public Web

Consulta [`public-web/README.md`](public-web/README.md) para la instalación y los comandos de desarrollo del frontend.

## Validación

Backend:

```bash
cd backend
composer test
```

Public Web:

```bash
cd public-web
pnpm lint
pnpm build
```

GitHub Actions ejecuta la validación del backend y de `public-web` en cada `push` y `pull request`.

## Coding agents

Las instrucciones generales para coding agents se definen en [`AGENTS.md`](AGENTS.md).

Las instrucciones específicas del backend Laravel y las reglas propias del proyecto se mantienen en:

```text
backend/AGENTS.md
backend/.ai/rules/
```

Laravel Boost proporciona herramientas MCP específicas de Laravel para los coding agents compatibles.

El plan de la slice activa constituye la especificación de implementación para el coding agent.

## Documentación

La documentación del producto, la arquitectura y la planificación de implementación se mantienen como fuentes de verdad separadas.

- [`docs/product.md`](docs/product.md) — requisitos del producto y reglas de negocio.
- [`docs/architecture.md`](docs/architecture.md) — arquitectura técnica de la V1 y límites del sistema.
- [`docs/reference-business.md`](docs/reference-business.md) — configuración y datos del negocio de referencia.
- [`docs/adr/`](docs/adr/) — Architecture Decision Records.
- [`docs/plans/v1/v1.md`](docs/plans/v1/v1.md) — roadmap de la V1.
- `docs/plans/v1/slices/` — planes de implementación aprobados para las slices de la V1.

## Estado del proyecto

Milestone 0 establece la base técnica del proyecto:

- backend Laravel;
- frontend público independiente con React;
- PostgreSQL;
- Redis;
- PHPUnit;
- validación de lint y build del frontend;
- integración continua con GitHub Actions;
- entorno local reproducible;
- configuración para coding agents y Laravel Boost.

El desarrollo funcional continúa de forma incremental mediante las vertical slices definidas en el roadmap de la V1.
