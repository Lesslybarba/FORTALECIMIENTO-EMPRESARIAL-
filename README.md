# Ada — Middleware inteligente para consulta automatizada de cursos en Moodle

Asistente conversacional embebido en Moodle que responde preguntas sobre los cursos
disponibles a partir de datos reales de la plataforma, sin depender de un servicio de
IA en la nube. Desarrollado como trabajo de grado (modalidad Fortalecimiento
Empresarial) para el proyecto piloto **Plataforma Escuela Rural**.

## Qué resuelve

Moodle almacena la información de los cursos, pero su buscador nativo es limitado:
los usuarios dependían de navegación manual o de orientación del personal
administrativo para encontrar cursos o entender su contenido. Ada resuelve esto
integrando un asistente conversacional directamente en la interfaz, sin modificar
el núcleo de Moodle.

## Arquitectura

Cuatro componentes desacoplados, comunicados por HTTP:

- **Widget Ada** (`widget/`) — chat flotante en HTML/CSS/JS, inyectado en Moodle vía
  el mecanismo nativo "HTML adicional" (sin tocar el tema). Visible solo con sesión
  iniciada.
- **Bridge REST** (`bridge/`) — script PHP alojado dentro de Moodle que expone
  cursos, unidades y contenidos en JSON, agregando varios endpoints nativos de la
  API de Moodle en una sola respuesta.
- **n8n** (`n8n/`) — orquesta la conversación: recibe el mensaje por Webhook,
  recupera el historial, consulta el bridge, arma el prompt y lo envía al modelo.
- **Ollama** — motor de inferencia local ejecutando Llama 3.1 (8B), sin enviar datos
  a servicios de terceros.

Para consultas que no requieren interpretación del modelo (saludos, preguntas
factuales sobre cursos), el sistema responde directamente desde el bridge, evitando
invocar al modelo de lenguaje y reduciendo el tiempo de respuesta a menos de un
segundo.

## Estructura del repositorio

| Carpeta | Contenido |
|---|---|
| `widget/` | Widget del chat, listo para pegar en Moodle (Administración del sitio → Apariencia → HTML adicional) |
| `bridge/` | Script PHP que expone los datos de cursos |
| `n8n/` | Workflow exportado (importar y activar en n8n) |
| `docker/` | `docker-compose.yml` + `Dockerfile` para levantar Moodle, MariaDB, n8n y Ollama |
| `anexos/` | Documentación técnica: manual de arquitectura, manual de instalación y migración, informe de pruebas funcionales y evidencias de las pruebas ejecutadas |

## Puesta en marcha (resumen)

1. Copiar `docker/.env.example` como `docker/.env` y completar las contraseñas.
2. `docker compose --env-file .env up -d` desde `docker/` — levanta los 4 servicios.
3. `docker exec -it ollama ollama pull llama3.1:8b` — descarga el modelo (~4.9 GB).
4. Copiar `bridge/moodle_api_bridge_v2.php` dentro de `/var/www/html/local/` en el
   contenedor de Moodle, y definir la variable de entorno `MOODLE_WS_TOKEN` con un
   token de Web Services generado desde el propio Moodle.
5. Importar `n8n/My_workflow.json` en n8n (`http://localhost:5678`) y activarlo.
   Verificar que el nodo de Ollama tenga `keep_alive` configurado (ver Anexo B,
   sección 4 — es crítico para el rendimiento).
6. Pegar el contenido de `widget/widget-ada-moodle.html` en Moodle → Administración
   del sitio → Apariencia → HTML adicional → "Antes de que se cierre `</BODY>`".

Instrucciones detalladas, solución de problemas conocidos y checklist de migración
en `anexos/2_Manual_Instalacion_Migracion_Ada.docx`.

## Validación

15 casos de prueba ejecutados en 5 categorías (saludos, listado de cursos, detalle
de unidades, preguntas fuera de alcance, preguntas abiertas), con 100 % de
precisión frente a los datos reales de Moodle. Detalle completo, capturas y
metodología en `anexos/3_Informe_Pruebas_Funcionales_Ada.docx` y
`anexos/4_Evidencias_Pruebas_Funcionales_Ada.docx`.

## Alcance

Este repositorio valida la arquitectura sobre una instancia piloto de Moodle
(Plataforma Escuela Rural), suministrada por SS&C como caso de prueba. La
implementación en los sistemas productivos de la empresa no forma parte de este
trabajo y queda planteada como transferencia tecnológica futura.

## Stack técnico

Moodle 4.5 · MariaDB 10.6 · n8n 1.28.0 · Ollama (Llama 3.1:8b, Q4_K_M) · Docker Compose
