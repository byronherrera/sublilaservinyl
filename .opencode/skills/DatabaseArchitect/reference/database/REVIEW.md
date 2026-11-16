# Revisión de Base de Datos - Estructura Estandarizada

Cuando analices una BD, debes responder esta estructura en orden.

---

## Sección 1: Resumen Ejecutivo

**Formato:** 2-3 líneas
- Descripción general de la BD
- Propósito
- Volumen de datos (aproximado)
- Última actualización/análisis

Ejemplo:
> BD de e-commerce. Almacena órdenes, productos, usuarios. ~2M órdenes, ~50K productos. Última revisión: hace 6 meses.

---

## Sección 2: Problemas Encontrados

**Formato:** Tabla con columnas: Problema | Ubicación | Severidad | Causa

| Problema | Ubicación | Severidad | Causa |
|----------|-----------|-----------|-------|
| FK sin índice | order.user_id | **ALTA** | Se ejecuta JOIN sin índice, full table scan |
| NULL vs '' | user.phone | **MEDIA** | Inconsistencia de datos, ambigüedad en consultas |
| Índice duplicado | product (idx_name + idx_name_id) | **BAJA** | Redundancia histórica, sin limpieza |

---

## Sección 3: Riesgos

**Formato:** Tabla con columnas: Riesgo | Impacto | Probabilidad | Mitigación

| Riesgo | Impacto | Probabilidad | Mitigación |
|--------|--------|--------------|-----------|
| Pérdida de integridad referencial | Datos huérfanos, reportes inconsistentes | MEDIA | Crear índices en FK, validar antes de eliminar |
| Performance degradada en queries | Reportes tardan >5s | ALTA | Optimizar JOIN, agregar índices faltantes |
| Migración incompleta en historia | Auditoría rechazada | BAJA | Ejecutar validación post-migración |

---

## Sección 4: Recomendaciones

**Formato:** Priorizado por impacto (ALTA, MEDIA, BAJA)

### Prioridad ALTA

1. **Crear índice en order.user_id**
   - SQL: `CREATE INDEX idx_order_user_id ON order(user_id);`
   - Impacto: -70% tiempo de JOIN
   - Esfuerzo: 5 minutos

2. **Revisar duplicados en user.email**
   - SQL: `SELECT email, COUNT(*) FROM user GROUP BY email HAVING COUNT(*) > 1;`
   - Impacto: Evita violaciones de UNIQUE
   - Esfuerzo: 15 minutos

### Prioridad MEDIA

3. **Standarizar NULL vs '' en campos opcionales**
   - Afecta: user.phone, user.address
   - Decidir: ¿NULL = no proporcionado o = no aplicable?
   - Esfuerzo: 30 minutos + validación

### Prioridad BAJA

4. **Limpiar índices duplicados**
   - SQL: `DROP INDEX idx_name_id ON product;`
   - Impacto: -5% tiempo de INSERT
   - Esfuerzo: 10 minutos

---

## Sección 5: SQL Sugerido

**Formato:** Scripts listos para copiar-pegar, separados por fase

### Fase 1: Validación (Sin cambios)

```sql
-- Verificar FK sin índice
SELECT 
    kcu.TABLE_NAME,
    kcu.COLUMN_NAME,
    COUNT(*) as references_count
FROM information_schema.KEY_COLUMN_USAGE kcu
LEFT JOIN information_schema.STATISTICS s 
    ON kcu.TABLE_SCHEMA = s.TABLE_SCHEMA
    AND kcu.TABLE_NAME = s.TABLE_NAME
    AND kcu.COLUMN_NAME = s.COLUMN_NAME
WHERE kcu.REFERENCED_TABLE_NAME IS NOT NULL
    AND s.SEQ_IN_INDEX IS NULL
    AND kcu.TABLE_SCHEMA = 'production_db'
GROUP BY kcu.TABLE_NAME, kcu.COLUMN_NAME;

-- Verificar duplicados en email
SELECT email, COUNT(*) as count
FROM user
GROUP BY email
HAVING COUNT(*) > 1;

-- Ver índices no usados
SELECT * FROM performance_schema.table_io_waits_summary_by_index_usage
WHERE OBJECT_SCHEMA = 'production_db'
AND INDEX_NAME != 'PRIMARY'
AND COUNT_READ = 0
AND COUNT_WRITE > 0;
```

### Fase 2: Backup (Obligatorio antes de cambios)

```sql
-- Generar backup (ejecutar en bash, no en MySQL)
-- mysqldump -u user -p production_db > backup_20240127.sql

-- O dentro de MySQL:
-- 1. Crear tabla de respaldo
CREATE TABLE user_backup AS SELECT * FROM user;
CREATE TABLE order_backup AS SELECT * FROM `order`;

-- 2. Timestamp de respaldo
SELECT NOW() AS backup_timestamp;
```

### Fase 3: Cambios DDL (Índices, FK)

```sql
-- Crear índices faltantes
CREATE INDEX idx_order_user_id ON `order`(user_id);
CREATE INDEX idx_order_created_at ON `order`(created_at);

-- Limpiar índices duplicados
DROP INDEX idx_name_id ON product;
```

### Fase 4: Validación Post-Cambio

```sql
-- Verificar que los índices existen
SHOW INDEXES FROM `order` WHERE Column_name IN ('user_id', 'created_at');

-- Verificar que el índice se usa
EXPLAIN SELECT * FROM `order` WHERE user_id = 123;
-- Debe mostrar: using index

-- Comparar conteos (si cambió datos)
SELECT COUNT(*) FROM user_backup;
SELECT COUNT(*) FROM user;
-- Deben ser iguales
```

### Fase 5: Rollback (En caso de error)

```sql
-- Si algo salió mal, volver atrás
DROP INDEX idx_order_user_id ON `order`;
DROP INDEX idx_order_created_at ON `order`;

-- Restaurar desde backup si fue necesario
-- RESTORE FROM backup_20240127.sql

-- O en memoria:
DROP TABLE user;
RENAME TABLE user_backup TO user;
```

---

## Sección 6: Impacto

**Formato:** Matriz de impacto

| Área | Cambio | Impacto Estimado |
|------|--------|------------------|
| Performance | Crear índices | Query time: -60%, INSERT: +5ms |
| Storage | Limpiar índices | -150 MB |
| Aplicación | Cambio en FK | Ninguno (compatible backwards) |
| Data | Limpieza de duplicados | 0 filas eliminadas (si no hay) |
| Auditoría | Documentar cambios | Trazabilidad +100% |

---

## Sección 7: Cronograma de Implementación

| Fase | Tarea | Duración | Riesgo | Ventana |
|------|-------|----------|--------|---------|
| 1 | Validación y análisis | 1 hora | BAJO | Cualquier hora |
| 2 | Backup automático | 15 min | BAJO | Horas pico |
| 3 | Aplicar cambios en staging | 30 min | BAJO | Fuera de horario |
| 4 | Testing y validación | 1 hora | MEDIO | Horario de QA |
| 5 | Backup en producción | 15 min | BAJO | Horas pico |
| 6 | Aplicar en producción | 30 min | MEDIO | Ventana mantenimiento |
| 7 | Monitoreo post-cambio | 2 horas | BAJO | Después de cambio |

**Ventana recomendada:** Domingo 02:00 AM - 04:00 AM (bajo tráfico)

---

## Sección 8: Prioridad General

### Escala de Severidad

| Nivel | Descripción | Acción | Plazo |
|-------|-------------|--------|-------|
| **CRÍTICA** | Pérdida de datos inminente, falla de aplicación | Ejecutar inmediatamente | < 1 hora |
| **ALTA** | Performance crítica afectada, riesgo de integridad | Ejecutar este sprint | < 1 semana |
| **MEDIA** | Performance degradada, calidad de datos | Ejecutar próximo sprint | < 2 semanas |
| **BAJA** | Optimización, deuda técnica | Ejecutar cuando sea posible | Sin plazo |

### Prioridad de este análisis

**GENERAL: MEDIA-ALTA**

Motivo:
- Performance tiene impacto (ALTA)
- Integridad de datos sin riesgo inmediato (MEDIA)
- Técnica deuda visible (BAJA pero acumulativa)

Recomendación: Implementar ALTA en 1 semana, MEDIA en 2 semanas.

---

## Sección 9: Checklist Post-Análisis

- [ ] ¿Se revisaron todas las tablas principales?
- [ ] ¿Se analizó EXPLAIN PLAN en queries lentas?
- [ ] ¿Se verificaron FK y sus índices?
- [ ] ¿Se checó calidad de datos (NULL, espacios, duplicados)?
- [ ] ¿Se documentaron convenciones actuales vs recomendadas?
- [ ] ¿Se preparó rollback strategy?
- [ ] ¿Se validó impacto en aplicación?
- [ ] ¿Se comunicó a stakeholders?
- [ ] ¿Se asignó propietario de implementación?
- [ ] ¿Se generó reporte (este documento)?
