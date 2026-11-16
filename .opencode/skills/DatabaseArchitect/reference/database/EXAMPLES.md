# Ejemplos de Diseño de Bases de Datos

Patrones buenos vs malos para aplicar el skill correctamente.

---

## ❌ MAL: PK sin convención clara

```sql
CREATE TABLE user (
    user_id INT PRIMARY KEY,
    name VARCHAR(100),
    email VARCHAR(100)
);
```

**Problemas:**
- Inconsistencia: `user_id` en lugar de `id`
- Todas las FK serían `user_user_id` (redundante)
- No sigue convención

---

## ✅ BIEN: PK con convención

```sql
CREATE TABLE user (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL
);
```

**Por qué:**
- `id` es estándar
- Auditoría incluida (created_at, updated_at, deleted_at)
- Soft delete preparado
- Unique index implícito en email
- Timestamps automáticos

---

## ❌ MAL: FK sin índice

```sql
CREATE TABLE order (
    id INT PRIMARY KEY,
    user_id INT,
    total DECIMAL(10,2),
    FOREIGN KEY (user_id) REFERENCES user(id)
);

-- Sin índice en user_id
-- Full table scan en cada JOIN
```

---

## ✅ BIEN: FK con índice

```sql
CREATE TABLE `order` (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    total DECIMAL(10,2) DEFAULT 0.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY fk_order_user (user_id) REFERENCES user(id) ON DELETE RESTRICT,
    INDEX idx_order_user_id (user_id),
    INDEX idx_order_created_at (created_at)
);
```

**Por qué:**
- FK tiene índice (evita full table scan)
- ON DELETE RESTRICT (evita orfandad accidental)
- Índice en created_at (para reportes time-series)
- Nombre explícito de FK

---

## ❌ MAL: Denormalización sin justificación

```sql
CREATE TABLE product (
    id INT PRIMARY KEY,
    name VARCHAR(100),
    category_id INT,
    category_name VARCHAR(100),  -- Redundante
    supplier_id INT,
    supplier_name VARCHAR(100),  -- Redundante
    supplier_email VARCHAR(100)  -- Redundante
);
```

**Problemas:**
- Redundancia data: category_name y supplier_name existen en otras tablas
- Inconsistencia: si category_name cambia, hay que actualizar 100 filas
- Performance: más bytes almacenados, más I/O

---

## ✅ BIEN: Normalizado correctamente

```sql
CREATE TABLE category (
    id INT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    codigo VARCHAR(20) NOT NULL UNIQUE,
    activo BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE supplier (
    id INT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL,
    codigo VARCHAR(20) UNIQUE,
    activo BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_supplier_activo (activo)
);

CREATE TABLE product (
    id INT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    category_id INT NOT NULL,
    supplier_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY fk_product_category (category_id) REFERENCES category(id),
    FOREIGN KEY fk_product_supplier (supplier_id) REFERENCES supplier(id),
    INDEX idx_product_category (category_id),
    INDEX idx_product_supplier (supplier_id)
);
```

**Por qué:**
- Normalizado a 3NF
- Sin redundancia
- Cambiar nombre de categoría = 1 UPDATE en category
- Catálogos explícitos (category, supplier)
- Auditoría en tablas críticas

---

## ❌ MAL: NULL vs cadena vacía

```sql
CREATE TABLE profile (
    id INT PRIMARY KEY,
    user_id INT,
    phone VARCHAR(20),  -- ¿NULL o ''?
    address VARCHAR(255),  -- Inconsistente
    document VARCHAR(20)  -- Puede tener espacios
);

-- Datos inconsistentes:
-- INSERT INTO profile VALUES (1, 1, NULL, '', '12345678  ');
```

**Problemas:**
- Ambigüedad: IS NULL vs = ''
- Consultas lógicamente incorrectas
- Duplicados por espacios invisibles

---

## ✅ BIEN: NULL con propósito claro

```sql
CREATE TABLE profile (
    id INT PRIMARY KEY,
    user_id INT NOT NULL,
    phone VARCHAR(20),  -- Aquí NULL = no proporcionado
    address VARCHAR(255),  -- Aquí NULL = no aplicable
    document VARCHAR(20) NOT NULL,  -- Aquí siempre debe existir
    document_verified_at TIMESTAMP NULL,  -- Auditoría: cuándo se verificó
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY fk_profile_user (user_id) REFERENCES user(id),
    UNIQUE KEY uk_profile_document (document),
    CONSTRAINT chk_phone_format CHECK (phone IS NULL OR phone REGEXP '^[0-9+-]+$'),
    CONSTRAINT chk_document_nowhitespace CHECK (document = TRIM(document))
);
```

**Por qué:**
- NULL tiene significado explícito (documentado)
- CONSTRAINT evita espacios en document
- UNIQUE + NOT NULL en document (clave natural alternativa)
- Auditoría en document_verified_at

---

## ❌ MAL: SELECT * en migración

```sql
SELECT * FROM users;  -- ¿Qué columnas?
SELECT * FROM orders;  -- ¿Incluye datos sensibles?

-- Migración sin validación
INSERT INTO user_staging 
SELECT * FROM source_db.user;
```

**Problemas:**
- No sabes qué migraste
- Puede incluir columnas innecesarias
- Difícil auditar qué cambió

---

## ✅ BIEN: SELECT explícito con validación

```sql
-- Paso 1: Verificar origen
SELECT COUNT(*) AS source_count FROM source_db.user
WHERE deleted_at IS NULL;

-- Paso 2: Mapear explícitamente
INSERT INTO user (id, name, email, created_at, deleted_at)
SELECT 
    user_id,
    TRIM(user_name),  -- Limpiar espacios
    LOWER(user_email),  -- Normalizar
    STR_TO_DATE(created_date, '%Y-%m-%d'),  -- Convertir tipo
    CASE WHEN archived = 1 THEN NOW() ELSE NULL END
FROM source_db.user
WHERE deleted_at IS NULL;

-- Paso 3: Validar destino
SELECT COUNT(*) AS dest_count FROM user WHERE deleted_at IS NULL;

-- Paso 4: Comparar
-- source_count DEBE = dest_count

-- Paso 5: Validar FK
SELECT COUNT(*) AS orphaned_users 
FROM staging_user u
LEFT JOIN user u2 ON u.id = u2.id
WHERE u2.id IS NULL;
```

**Por qué:**
- Cada columna es explícita
- Se limpian datos (TRIM, LOWER)
- Se convierten tipos
- Se validan conteos
- Se detectan orfandades

---

## ❌ MAL: Índices innecesarios

```sql
CREATE TABLE product (
    id INT PRIMARY KEY,
    name VARCHAR(100),
    INDEX idx_id (id),  -- PK ya tiene índice
    INDEX idx_name (name),  -- Nunca usado en WHERE
    INDEX idx_name_id (name, id),  -- Duplica idx_name
    INDEX idx_created_name (created_at, name),
    INDEX idx_name_created (name, created_at)  -- Duplica anterior
);

-- Resultado: 5 índices para 1 tabla pequeña
-- INSERT es lento (mantener 5 índices)
-- Bloat de storage
```

---

## ✅ BIEN: Índices justificados

```sql
CREATE TABLE product (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    category_id INT NOT NULL,
    price DECIMAL(10,2),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    -- Solo índices que responden queries reales
    FOREIGN KEY fk_product_category (category_id) REFERENCES category(id),
    INDEX idx_product_category (category_id),  -- Para JOIN con category
    INDEX idx_product_created_at (created_at),  -- Para reportes time-series
    -- Nota: No indexar name (baja selectividad en búsqueda de productos)
);

-- EXPLAIN SELECT * FROM product WHERE category_id = 1;
-- → Uses: idx_product_category ✅

-- EXPLAIN SELECT * FROM product WHERE created_at > '2024-01-01';
-- → Uses: idx_product_created_at ✅

-- EXPLAIN SELECT * FROM product WHERE name LIKE 'Pro%';
-- → Full scan (aceptable: búsqueda LIKE es cara de indexar)
```

**Por qué:**
- Solo índices para queries reales
- UNIQUE en PK + FK implícitos
- created_at indexado (reportes)
- Sin duplicados
- Storage minimizado

---

## ❌ MAL: Migración sin rollback

```bash
#!/bin/bash
# migration.sh
mysql -u user -p db_name < migration.sql

echo "Migration complete!"
```

**Problemas:**
- ¿Qué pasa si falla?
- No hay forma de volver atrás
- Sin validación
- Sin reporte

---

## ✅ BIEN: Migración con rollback

```bash
#!/bin/bash
# migration.sh

set -e  # Exit on error

DB_NAME="production_db"
BACKUP_DIR="/backups"
TIMESTAMP=$(date +%Y%m%d_%H%M%S)
BACKUP_FILE="$BACKUP_DIR/${DB_NAME}_${TIMESTAMP}.sql"

echo "[1/6] Validando conexión..."
mysql -u user -p"${DB_PASSWORD}" -e "SELECT 1" || exit 1

echo "[2/6] Generando backup..."
mysqldump -u user -p"${DB_PASSWORD}" "$DB_NAME" > "$BACKUP_FILE"
echo "✓ Backup: $BACKUP_FILE"

echo "[3/6] Contando registros antes..."
BEFORE=$(mysql -u user -p"${DB_PASSWORD}" -N -e "SELECT COUNT(*) FROM user;" "$DB_NAME")
echo "✓ Registros antes: $BEFORE"

echo "[4/6] Ejecutando migración..."
mysql -u user -p"${DB_PASSWORD}" "$DB_NAME" < migration.sql || {
    echo "✗ Error en migración. Revirtiendo..."
    mysql -u user -p"${DB_PASSWORD}" "$DB_NAME" < rollback.sql
    exit 1
}

echo "[5/6] Validando integridad..."
AFTER=$(mysql -u user -p"${DB_PASSWORD}" -N -e "SELECT COUNT(*) FROM user;" "$DB_NAME")
echo "✓ Registros después: $AFTER"

if [ "$BEFORE" != "$AFTER" ]; then
    echo "✗ Conteos no coinciden. Revirtiendo..."
    mysql -u user -p"${DB_PASSWORD}" "$DB_NAME" < rollback.sql
    exit 1
fi

echo "[6/6] Reporte generado"
echo "==== REPORTE DE MIGRACIÓN ===="
echo "Timestamp: $TIMESTAMP"
echo "Backup: $BACKUP_FILE"
echo "Registros: $BEFORE → $AFTER"
echo "Status: ✅ EXITOSO"
```

**Por qué:**
- Backup automático
- Validación de conteos
- Rollback automático si falla
- Reporte generado
- `set -e` para error handling
- Reversible

---

## Resumen: Checklist para cada cambio

1. ¿Existe índice en FK?
2. ¿Hay NULL inconsistente con cadena vacía?
3. ¿SELECT es explícito (no SELECT *)?
4. ¿Convenciones se respetan (snake_case, nombres claros)?
5. ¿Tiene rollback strategy?
6. ¿Se validaron conteos?
7. ¿Se documentó la decisión?
8. ¿Se ejecutó en staging primero?
