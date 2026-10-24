# Appointment Assistant

Sistema conversacional para la gestión de citas en negocios de servicios.

Appointment Assistant permite a los clientes consultar información del negocio, comprobar disponibilidad y gestionar citas mediante conversaciones en lenguaje natural.

## V1

La V1 está diseñada para cubrir:

- consultas sobre el negocio, los servicios y los profesionales;
- búsqueda de disponibilidad;
- registro mínimo y persistente del cliente antes de crear una cita;
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

La V1 también incluye una demostración web pública y aislada de los clientes reales del negocio. La experiencia web presenta Appointment Assistant, explica su propósito y sus capacidades principales e incluye:

- un chat conectado al backend real;
- un calendario visual de solo lectura;
- actualización visible del calendario cuando una cita se crea, reprograma o cancela desde la conversación.

## Arquitectura

Laravel actúa como núcleo autoritativo de la aplicación.

Las reglas de negocio, la validación de disponibilidad y el estado de las citas pertenecen a la aplicación y no al LLM, los canales de comunicación ni las integraciones externas.

La V1 sigue una arquitectura de monolito modular pragmático.

```text
WhatsApp ──► n8n ──┐
                    │
React Web ──────────┼──► Laravel ──► PostgreSQL
                    │       │
                    │       ├──► Redis / Queues
                    │       └──► OpenAI
                    │
Laravel ─────────► n8n ──► WhatsApp
                         └──► Google Calendar
```

n8n se utiliza de forma selectiva para flujos de integración con proveedores externos. No contiene las reglas de negocio ni mantiene el estado autoritativo de las citas.

## Tecnologías

### Backend

- PHP
- Laravel
- PostgreSQL
- Redis
- Laravel Queues
- Laravel AI SDK
- OpenAI

### Frontend

- React
- TypeScript
- Vite

### Integraciones

- n8n
- WhatsApp Business
- Google Calendar

### Calidad y entrega

- PHPUnit
- Playwright
- GitHub Actions
- Docker Compose
- despliegue en VPS

## Documentación

La documentación del producto y las decisiones técnicas se mantienen separadas para evitar duplicar fuentes de verdad.

- [`docs/product.md`](docs/product.md) — alcance del producto, requisitos funcionales y reglas de negocio
- [`docs/architecture.md`](docs/architecture.md) — arquitectura técnica de la V1 y límites del sistema
- [`docs/reference-business.md`](docs/reference-business.md) — configuración y datos del negocio de referencia utilizado por la V1
- [`docs/adr/`](docs/adr/) — Architecture Decision Records de las decisiones arquitectónicas relevantes

## Estado del proyecto

La especificación funcional, la arquitectura, las decisiones arquitectónicas principales y el negocio de referencia de la V1 están definidos.

La siguiente fase es la planificación de implementación de la V1 y, posteriormente, su desarrollo.
