# DatabaseArchitect - Workflow de uso

Guía práctica para cuándo usar cada archivo del skill.

---

## Flujo de Trabajo por Caso de Uso

### Caso 1: Revisar una BD heredada

**Contexto:** Recibiste una BD antigua, sin documentación.

**Workflow:**

1. **README.md** (orientación)
   → "Eres un Arquitecto de BD, especialista en ingeniería inversa"

2. **SKILL.md** - Sección "Ingeniería inversa"
   → Reconstruye modelo, identifica relaciones, documenta dominio

3. **CHECKLIST.md** - Sección "Modelo"
   → Verifica PK, FK, índices, normalización, relaciones

4. **CONVENTIONS.md**
   → Compara convenciones actuales vs recomendadas

5. **REVIEW.md** - Sección "Resumen Ejecutivo"
   → Documenta qué encontraste

6. **DOCUMENTATION.md**
   → Genera diccionario de datos, modelo ER, dependencias

7. **SKILL.md** - Sección "Diseño" / Detecta
   → Identifica redundancias, campos innecesarios, tipos incorrectos

**Output esperado:** Reporte de revisión (REVIEW.md completo) + Documentación automática

---

### Caso 2: Migrar de Access (ares.mdb) a MySQL

**Contexto:** Tienes ares.mdb (Access) y necesitas migrar a MySQL.

**Workflow:**

1. **SKILL.md** - Sección "Migraciones"
   → Entiende las 8 fases

2. **MIGRATION.md** - Fase 1: Analizar
   → Inspecciona ares.mdb, extrae modelo

3. **CHECKLIST.md** - Sección "Modelo"
   → Verifica PK, FK, índices en Access (probablemente incorrectos)

4. **DATAQUALITY.md**
   → Busca NULL, duplicados, espacios, encoding (Access usa latin1)

5. **MIGRATION.md** - Fases 2-5
   → Compara estructuras, mapea columnas, transforma datos, valida

6. **SKILL.md** - Sección "Migraciones"
   → Genera script idempotente, valida FK, compara conteos

7. **MIGRATION.md** - Fases 6-8
   → Compara conteos, valida integridad, genera reporte

8. **REVIEW.md** - Sección "Prioridad"
   → Documenta cambios que tuviste que hacer en estructura

**Output esperado:** 
- Script de migración (SQL)
- Reporte de validación
- Documentación de cambios estructurales
- Rollback strategy

---

### Caso 3: Optimizar performance

**Contexto:** Las queries de reportes son lentas.

**Workflow:**

1. **SKILL.md** - Sección "Performance"
   → Entiende qué revisar

2. **CHECKLIST.md** - Sección "Performance"
   → Corre checklist: índices faltantes, duplicados, EXPLAIN

3. **PERFORMANCE.md**
   → Full table scan, varchar grandes, funciones en WHERE

4. **EXAMPLES.md** - Sección "Índices justificados"
   → Entiende cómo crear índices correctamente

5. **REVIEW.md** - Sección "SQL Sugerido" / Fase 1
   → Genera queries de validación, identifica problemas

6. **REVIEW.md** - Sección "Recomendaciones"
   → Prioriza por impacto

7. **REVIEW.md** - Sección "SQL Sugerido" / Fases 2-5
   → Ejecuta cambios, valida, monitorea

**Output esperado:** 
- Lista de índices a crear
- Queries optimizadas
- Reporte de impacto

---

### Caso 4: Diseñar una BD nueva desde cero

**Contexto:** Estás diseñando la BD para una nueva aplicación.

**Workflow:**

1. **SKILL.md** - Sección "Diseño"
   → Aprende qué analizar (tablas, relaciones, PK, FK, cardinalidad)

2. **CONVENTIONS.md**
   → Aplica convenciones desde el inicio

3. **EXAMPLES.md** - Secciones "BIEN"
   → Usa patrones buenos como referencia

4. **CHECKLIST.md** - Sección "Modelo"
   → Verifica tu diseño: PK, FK, índices, normalización

5. **SKILL.md** - Sección "Diseño" / Detecta
   → Auto-cuestiona: ¿redundancias? ¿campos innecesarios? ¿tipos correctos?

6. **DOCUMENTATION.md**
   → Genera documentación automática desde SQL

**Output esperado:** 
- Script CREATE TABLE comentado
- Diccionario de datos
- Modelo ER
- Documentación de convenciones aplicadas

---

### Caso 5: Auditar calidad de datos

**Contexto:** Sospechas que hay datos inconsistentes.

**Workflow:**

1. **CHECKLIST.md** - Sección "Calidad"
   → Corre checklist: NULL, duplicados, espacios, charset, collation, longitud

2. **DATAQUALITY.md**
   → Busca: NULL, duplicados, mayúsculas, minúsculas, espacios, tildes, UTF8, latin1

3. **EXAMPLES.md** - Sección "NULL vs cadena vacía"
   → Entiende cómo estructurar validaciones

4. **REVIEW.md** - Sección "SQL Sugerido" / Fase 1
   → Corre queries de validación

5. **REVIEW.md** - Sección "Recomendaciones"
   → Propone limpieza de datos

**Output esperado:** 
- Reporte de calidad
- Queries de limpieza
- Plan de validación post-limpieza

---

### Caso 6: Documentar una BD existente

**Contexto:** La BD está operativa pero sin documentación.

**Workflow:**

1. **DOCUMENTATION.md**
   → Define qué documentar

2. **CHECKLIST.md** - Sección "Modelo"
   → Extrae información: tablas, relaciones, índices

3. **CONVENTIONS.md**
   → Documenta convenciones aplicadas

4. **SKILL.md** - Sección "Ingeniería inversa"
   → Reconstruye modelo en Markdown

5. **REVIEW.md** - Sección "Resumen Ejecutivo"
   → Escribe descripción general

6. **EXAMPLES.md**
   → Usa como referencia para documentación clara

**Output esperado:** 
- Diccionario de datos (tabla, columna, tipo, descripción)
- Modelo ER (diagrama ASCII o Mermaid)
- Relaciones documentadas
- Catálogos documentados
- Archivo README.md de la BD

---

## Matriz de Archivos por Responsabilidad

| Responsabilidad | Archivo Primario | Archivo Secundario |
|-----------------|------------------|-------------------|
| Ingeniería Inversa | SKILL.md | CONVENTIONS.md |
| Migraciones | MIGRATION.md | CHECKLIST.md |
| Calidad de Datos | DATAQUALITY.md | CHECKLIST.md |
| Performance | PERFORMANCE.md | SKILL.md |
| Documentación | DOCUMENTATION.md | CONVENTIONS.md |
| Revisión | REVIEW.md | SKILL.md |
| Ejemplos/Referencia | EXAMPLES.md | Todos |

---

## Checklist Pre-Revisión

Antes de analizar cualquier BD, verificar:

- [ ] ¿Tengo credenciales de acceso?
- [ ] ¿Puedo hacer backup?
- [ ] ¿Hay restricciones de acceso (datos sensibles)?
- [ ] ¿Cuál es el DBMS? (MySQL, PostgreSQL, SQL Server, etc.)
- [ ] ¿Cuál es la versión?
- [ ] ¿Hay documentación previa?
- [ ] ¿Hay changelog de cambios?
- [ ] ¿Hay aplicaciones dependientes?
- [ ] ¿Cuál es el nivel de criticidad?

---

## Orden de Lectura Recomendado

### Primera vez usando DatabaseArchitect:

1. **README.md** (qué eres)
2. **SKILL.md** (responsabilidades)
3. **CONVENTIONS.md** (estándares)
4. **EXAMPLES.md** (patrones buenos/malos)
5. **CHECKLIST.md** (qué revisar)
6. **REVIEW.md** (cómo documentar)
7. **Caso específico:** MIGRATION.md o PERFORMANCE.md o DATAQUALITY.md

### Uso repetido:

- Ir directo a archivo específico del caso de uso
- Usar **CHECKLIST.md** como referencia rápida
- Usar **EXAMPLES.md** para patrones

---

## Comandos útiles (para incluir en sesión)

Estos comandos deben estar disponibles cuando se use el skill:

```sql
-- Verificar estructura
SHOW TABLES;
DESCRIBE tabla_name;
SHOW CREATE TABLE tabla_name;

-- Verificar índices
SHOW INDEXES FROM tabla_name;

-- Verificar FK
SELECT * FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS 
WHERE CONSTRAINT_SCHEMA = 'database_name';

-- Verificar size
SELECT table_name, ROUND(((data_length + index_length) / 1024 / 1024), 2) AS size_mb
FROM information_schema.tables 
WHERE table_schema = 'database_name'
ORDER BY (data_length + index_length) DESC;

-- Verificar charset/collation
SELECT table_schema, table_name, table_collation
FROM information_schema.tables
WHERE table_schema = 'database_name';

-- Verificar conteos
SELECT table_name, TABLE_ROWS
FROM information_schema.tables
WHERE table_schema = 'database_name'
ORDER BY TABLE_ROWS DESC;
```

---

## Integraciones sugeridas

Para máxima efectividad, el skill puede usar:

1. **Mermaid** (para diagramas ER)
2. **Markdown tables** (para documentación)
3. **SQL scripts** (en bloques de código)
4. **Bash scripts** (para migración con rollback)
5. **JSON** (para documentación estructurada)

---

## Limitaciones conocidas

- **Access (MDB):** Requiere herramientas externas (mdb-tools) para lectura
- **Encrypted DBs:** Requiere contraseña antes de acceder
- **Sharded databases:** Requiere análisis de cada shard
- **NoSQL:** Skill está optimizado para SQL relacional
