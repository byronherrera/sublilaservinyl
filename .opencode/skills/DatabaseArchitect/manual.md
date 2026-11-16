# Manual de Uso - DatabaseArchitect Skill

**Versión:** 4.0.2  
**Fecha:** Julio 2026  
**Autor:** DatabaseArchitect Skill  Byron Herrera 

Este manual te explica cómo usar el skill DatabaseArchitect para trabajar con bases de datos de forma profesional, segura y escalable.

---

## 1. Introducción

El skill **DatabaseArchitect** transforma a Claude en un Arquitecto Senior de Bases de Datos con más de 25 años de experiencia.

**Uso principal:**
- Diseñar esquemas desde cero
- Migraciones seguras de producción
- Auditorías de calidad de datos
- Optimización de rendimiento
- Ingeniería inversa de bases heredadas
- Documentación automática (incluye diagramas ER en Mermaid)

---

## 2. Cómo Invocar el Skill

Usa cualquiera de estos comandos:
/database analyze [target]
/database migrate [target]
/database mermaid
/database examples
/database doctor
textO simplemente descríbemelo directamente al agente.

---

## 3. 15 Prompts de Ejemplo (Listos para Copiar)

### Prompt 1: Revisión de esquema heredado
Eres DatabaseArchitect. Analiza este esquema heredado:
[PEGA AQUÍ TU SQL]
Usa:

CHECKLIST.md para verificar PK, FK, índices, normalización y relaciones.
CONVENTIONS.md para comparar convenciones.
REVIEW.md para la estructura de reporte completo.
EXAMPLES.md para patrones buenos vs malos.
PERFORMANCE.md para buscar full table scan e índices faltantes.

Al final genera:

Reporte completo en formato REVIEW.md
Modelo ER en Mermaid
SQL de validación y corrección

### Prompt 2: Migración segura de Access a MySQL
Eres DatabaseArchitect. Ayúdame a migrar este esquema de Access a MySQL:
[PEGA AQUÍ TU SQL O ARCHIVO .MDB]
Sigue exactamente:

MIGRATION.md (las 8 fases)
CHECKLIST.md (sección Migración)
EXAMPLES.md (migración con rollback y SELECT explícito)
SCRIPTS.md (usa los scripts migrate-db.sh, validate-migration.sh, etc.)

Genera:

Script de migración completo (SQL + bash)
Backup y rollback strategy
Reporte de validación post-migración

### Prompt 3: Optimización de rendimiento
Eres DatabaseArchitect. Optimiza este query que está lento:
[PEGA AQUÍ TU QUERY]
Usa:

PERFORMANCE.md
CHECKLIST.md (sección Performance)
EXAMPLES.md (Índices justificados)

Da: EXPLAIN + índices recomendados + alternativa optimizada.

### Prompt 4: Diseño de BD nueva desde cero
Eres DatabaseArchitect. Diseña una base de datos para [descripción del dominio, ej: sistema de e-commerce o SaaS].
Sigue:

CONVENTIONS.md (tablas snake_case, id como PK, etc.)
CHECKLIST.md (Modelo)
EXAMPLES.md (BIEN vs MAL)
DOCUMENTATION.md (generar diccionario, ER, riesgos)
MERMAID_ER.md (crear el diagrama ER)

Genera:

Script CREATE TABLE completo
Diccionario de datos
Modelo ER en Mermaid
Riesgos identificados

### Prompt 5: Auditoría de calidad de datos
Eres DatabaseArchitect. Audita la calidad de datos de esta tabla:
[PEGA AQUÍ TU TABLA]
Busca:

NULL inconsistentes con cadena vacía
Duplicados
Espacios en blanco
Mayúsculas vs minúsculas
Fechas inválidas

Usa:

DATAQUALITY.md
CHECKLIST.md (sección Calidad)
EXAMPLES.md (NULL vs cadena vacía)

Genera: Reporte con queries de limpieza.
### Prompt 6: Ingeniería inversa de BD heredada
Eres DatabaseArchitect. Reconstruye el modelo de esta base de datos heredada:
[PEGA AQUÍ TU SQL]
Usa:

CONVENTIONS.md
CHECKLIST.md
REVIEW.md
MERMAID_ER.md

Genera:

Modelo ER completo en Mermaid
Diccionario de datos
Lista de problemas encontrados
Recomendaciones de normalización

### Prompt 7: Generación automática de documentación
Eres DatabaseArchitect. Genera documentación completa para esta BD:
[PEGA AQUÍ TU SQL O DESCRIPCIÓN]
Usa:

DOCUMENTATION.md
MERMAID_ER.md para el diagrama ER
CHECKLIST.md para modelo
CONVENTIONS.md para convenciones aplicadas
EXAMPLES.md para ejemplo de cómo documentar

Incluye siempre:

Diccionario de datos
Modelo ER
Relaciones
Catálogos
Riesgos

### Prompt 8: Estrategia de migración
Eres DatabaseArchitect. Crea una estrategia de migración segura para:
[PEGA AQUÍ TU ESCENARIO]
Usa:

MIGRATION.md (fases 1-8)
CHECKLIST.md (Migración)
EXAMPLES.md (migración con rollback)
SCRIPTS.md

Genera:

Script de migración completo
Plan de rollback
Reporte de validación

### Prompt 9: Verificación de integridad referencial
Eres DatabaseArchitect. Verifica la integridad referencial de esta base de datos:
[PEGA AQUÍ TU SQL]
Busca:

FK sin índices
Duplicados
Orfandades

Usa:

CHECKLIST.md
REVIEW.md
PERFORMANCE.md

Genera: Reporte con queries de validación.


### Prompt 10: Plan de indexación
Eres DatabaseArchitect. Crea un plan de indexación para este dominio:
[PEGA AQUÍ TU DOMINIO O QUERIES]
Usa:

PERFORMANCE.md
EXAMPLES.md (Índices justificados)
CHECKLIST.md

Da: Lista de índices recomendados con EXPLAIN.


### Prompt 11: Diseño con auditoría y soft delete
Eres DatabaseArchitect. Diseña una base de datos con auditoría y soft delete:
[PEGA AQUÍ TU DOMINIO]
Sigue:

CONVENTIONS.md (auditoría: created_at, updated_at, deleted_at)
CHECKLIST.md
EXAMPLES.md (BIEN: auditoría incluida)

Genera: Script CREATE TABLE completo.

### Prompt 12: Validación post-migración
Eres DatabaseArchitect. Valida esta migración:
[PEGA AQUÍ TU SCRIPT DE MIGRACIÓN]
Usa:

SCRIPTS.md (validate-migration.sh)
MIGRATION.md (fases 6-8)
CHECKLIST.md

Genera: Reporte de validación completo.

### Prompt 13: Generación de diagrama ER
Eres DatabaseArchitect. Genera un diagrama ER profesional para:
[PEGA AQUÍ TU DOMINIO]
Usa:

MERMAID_ER.md
DOCUMENTATION.md

Genera: Código Mermaid completo listo para mermaid.live o GitHub.

### Prompt 14: Revisión técnica de rendimiento
Eres DatabaseArchitect. Realiza una revisión técnica de rendimiento:
[PEGA AQUÍ TU BD O QUERIES]
Usa:

PERFORMANCE.md
CHECKLIST.md (Performance)
REVIEW.md

Genera: Reporte con recomendaciones priorizadas.

### Prompt 15: Diseño de catálogo con normalización
Eres DatabaseArchitect. Diseña un catálogo de productos con normalización:
[PEGA AQUÍ TU DESCRIPCIÓN]
Sigue:

CONVENTIONS.md
EXAMPLES.md (normalización 3NF)
CHECKLIST.md (Modelo)
MERMAID_ER.md

Genera: Tablas + diagrama ER.
text---

## 4. Recomendaciones Finales

- Siempre usa **EXAMPLES.md** como referencia para patrones buenos y malos.
- Siempre usa **MERMAID_ER.md** para diagramas profesionales.
- Nunca olvides el rollback strategy antes de tocar producción.
- Ejecuta siempre en **staging** primero.
- Valida conteos exactos después de cada migración.

---

**Fin del Manual**