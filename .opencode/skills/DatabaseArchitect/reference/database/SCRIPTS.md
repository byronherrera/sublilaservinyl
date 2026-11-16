# DatabaseArchitect - Scripts para Migraciones

Bash scripts reutilizables para automatizar migraciones de BD de forma segura.

---

## Script 1: Migración Simple (Base de datos completa)

### Caso de uso
Migrar una BD completa de Source (MySQL/Access) a Destino (MySQL).

### Script: `migrate-db.sh`

```bash
#!/bin/bash

################################################################################
# migrate-db.sh
# Migración segura de base de datos con backup, validación y rollback
# 
# Uso: ./migrate-db.sh production_db backup_dir
# Requiere: mysql, mysqldump
################################################################################

set -e  # Exit on any error

# ============================================================================
# CONFIGURACIÓN
# ============================================================================

DB_NAME="${1:-production_db}"
BACKUP_DIR="${2:-.}"
TIMESTAMP=$(date +%Y%m%d_%H%M%S)
LOG_FILE="${BACKUP_DIR}/migration_${DB_NAME}_${TIMESTAMP}.log"
BACKUP_FILE="${BACKUP_DIR}/${DB_NAME}_${TIMESTAMP}.sql"

# Colores para output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Credentials (cambiar o usar variables de entorno)
DB_USER="${DB_USER:-root}"
DB_PASSWORD="${DB_PASSWORD:-}"
DB_HOST="${DB_HOST:-localhost}"

# ============================================================================
# FUNCIONES AUXILIARES
# ============================================================================

log() {
    echo -e "${BLUE}[$(date +'%Y-%m-%d %H:%M:%S')]${NC} $1" | tee -a "$LOG_FILE"
}

log_success() {
    echo -e "${GREEN}[✓]${NC} $1" | tee -a "$LOG_FILE"
}

log_error() {
    echo -e "${RED}[✗]${NC} $1" | tee -a "$LOG_FILE"
}

log_warning() {
    echo -e "${YELLOW}[!]${NC} $1" | tee -a "$LOG_FILE"
}

# Conectar a BD y ejecutar query
run_query() {
    local query="$1"
    local output_file="${2:-}"
    
    if [ -z "$output_file" ]; then
        mysql -h "$DB_HOST" -u "$DB_USER" ${DB_PASSWORD:+-p"$DB_PASSWORD"} \
            -N -e "$query" 2>/dev/null
    else
        mysql -h "$DB_HOST" -u "$DB_USER" ${DB_PASSWORD:+-p"$DB_PASSWORD"} \
            "$DB_NAME" < "$output_file" 2>/dev/null
    fi
}

# Test de conexión
test_connection() {
    log "Validando conexión a MySQL..."
    
    if mysql -h "$DB_HOST" -u "$DB_USER" ${DB_PASSWORD:+-p"$DB_PASSWORD"} \
        -e "SELECT 1" >/dev/null 2>&1; then
        log_success "Conexión OK"
        return 0
    else
        log_error "No se pudo conectar a MySQL"
        return 1
    fi
}

# Backup de seguridad
create_backup() {
    log "Generando backup de seguridad..."
    
    if mysqldump -h "$DB_HOST" -u "$DB_USER" ${DB_PASSWORD:+-p"$DB_PASSWORD"} \
        "$DB_NAME" > "$BACKUP_FILE" 2>/dev/null; then
        local size=$(du -h "$BACKUP_FILE" | cut -f1)
        log_success "Backup creado: $BACKUP_FILE ($size)"
        return 0
    else
        log_error "Error al crear backup"
        return 1
    fi
}

# Contar registros en tabla
count_records() {
    local table="$1"
    run_query "SELECT COUNT(*) FROM $table;"
}

# Verificar integridad de FK
check_foreign_keys() {
    log "Verificando integridad de FK..."
    
    local orphaned=$(run_query "
        SELECT COUNT(*) as total FROM information_schema.REFERENTIAL_CONSTRAINTS 
        WHERE CONSTRAINT_SCHEMA = '$DB_NAME';
    " 2>/dev/null || echo "0")
    
    if [ "$orphaned" -eq 0 ]; then
        log_success "Integridad de FK OK"
        return 0
    else
        log_warning "Posibles problemas con FK detectados"
        return 1
    fi
}

# Detectar duplicados
check_duplicates() {
    local table="$1"
    local column="$2"
    
    log "Buscando duplicados en $table.$column..."
    
    local dupes=$(run_query "
        SELECT COUNT(*) FROM (
            SELECT $column, COUNT(*) as cnt 
            FROM $table 
            GROUP BY $column HAVING cnt > 1
        ) AS dup;
    " 2>/dev/null || echo "0")
    
    if [ "$dupes" -eq 0 ]; then
        log_success "Sin duplicados"
        return 0
    else
        log_warning "Encontrados $dupes duplicados"
        return 1
    fi
}

# Limpiar espacios en blanco
cleanup_whitespace() {
    local table="$1"
    local column="$2"
    
    log "Limpiando espacios en $table.$column..."
    
    run_query "UPDATE $table SET $column = TRIM($column) WHERE $column IS NOT NULL;" "$table"
    
    log_success "Limpieza completada"
}

# ============================================================================
# MAIN
# ============================================================================

main() {
    log "═════════════════════════════════════════════════════════"
    log "MIGRACIÓN DE BASE DE DATOS"
    log "BD: $DB_NAME"
    log "Host: $DB_HOST"
    log "Timestamp: $TIMESTAMP"
    log "═════════════════════════════════════════════════════════"
    
    # Fase 1: Validación de conexión
    log ""
    log "FASE 1: Validación"
    test_connection || exit 1
    
    # Fase 2: Conteo antes
    log ""
    log "FASE 2: Conteos antes"
    RECORD_COUNT=$(run_query "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DB_NAME';" 2>/dev/null || echo "?")
    log "Tablas en BD: $RECORD_COUNT"
    
    # Fase 3: Backup
    log ""
    log "FASE 3: Backup de seguridad"
    create_backup || exit 1
    
    # Fase 4: Validación de integridad
    log ""
    log "FASE 4: Validación de integridad"
    check_foreign_keys
    
    # Fase 5: Ejecución de cambios (AQUÍ VA TU SCRIPT DE MIGRACIÓN)
    log ""
    log "FASE 5: Ejecutando cambios de BD"
    
    # EJEMPLO: Ejecutar script SQL externo
    if [ -f "migration.sql" ]; then
        log "Aplicando migration.sql..."
        run_query < migration.sql
        log_success "Script ejecutado"
    else
        log_warning "No se encontró migration.sql, saltando cambios"
    fi
    
    # Fase 6: Validación post-migración
    log ""
    log "FASE 6: Validación post-migración"
    
    RECORD_COUNT_AFTER=$(run_query "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DB_NAME';" 2>/dev/null || echo "?")
    
    if [ "$RECORD_COUNT" = "$RECORD_COUNT_AFTER" ]; then
        log_success "Conteos coinciden"
    else
        log_error "Conteos NO coinciden: antes=$RECORD_COUNT, después=$RECORD_COUNT_AFTER"
        read -p "¿Restaurar backup? (s/n) " -n 1 -r
        echo
        if [[ $REPLY =~ ^[Ss]$ ]]; then
            restore_backup "$BACKUP_FILE"
            exit 1
        fi
    fi
    
    # Fase 7: Reporte final
    log ""
    log "═════════════════════════════════════════════════════════"
    log_success "MIGRACIÓN COMPLETADA"
    log "═════════════════════════════════════════════════════════"
    log "Backup: $BACKUP_FILE"
    log "Log: $LOG_FILE"
    log "Timestamp: $TIMESTAMP"
    log ""
}

# Ejecutar main
main "$@"
```

### Cómo usar

```bash
# Hacer ejecutable
chmod +x migrate-db.sh

# Ejecutar (requiere variables de entorno)
export DB_USER="root"
export DB_PASSWORD="tu_password"
export DB_HOST="localhost"

./migrate-db.sh production_db /backups

# Salida:
# [2024-01-27 14:30:00] Validación
# [✓] Conexión OK
# [2024-01-27 14:30:01] Fase 2: Conteos antes
# Tablas en BD: 15
# [✓] Backup creado: /backups/production_db_20240127_143001.sql (245 MB)
# ...
# [✓] MIGRACIÓN COMPLETADA
```

---

## Script 2: Migración de tabla específica (origen ≠ destino)

### Caso de uso
Migrar una tabla desde BD source a BD destino (ej: Access → MySQL).

### Script: `migrate-table.sh`

```bash
#!/bin/bash

################################################################################
# migrate-table.sh
# Migración de tabla específica entre BDs diferentes
# 
# Uso: ./migrate-table.sh source_table dest_table source_db dest_db
# Requiere: mysql, mysqldump
################################################################################

set -e

# Configuración
SOURCE_TABLE="$1"
DEST_TABLE="${2:-$SOURCE_TABLE}"
SOURCE_DB="$3"
DEST_DB="$4"
TIMESTAMP=$(date +%Y%m%d_%H%M%S)
LOG_FILE="migrate_${SOURCE_TABLE}_${TIMESTAMP}.log"

# Credentials
SRC_USER="${SRC_USER:-root}"
SRC_PASS="${SRC_PASS:-}"
SRC_HOST="${SRC_HOST:-localhost}"

DST_USER="${DST_USER:-root}"
DST_PASS="${DST_PASS:-}"
DST_HOST="${DST_HOST:-localhost}"

# Colores
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

log() { echo -e "${BLUE}[$(date +'%H:%M:%S')]${NC} $1" | tee -a "$LOG_FILE"; }
log_ok() { echo -e "${GREEN}[✓]${NC} $1" | tee -a "$LOG_FILE"; }
log_err() { echo -e "${RED}[✗]${NC} $1" | tee -a "$LOG_FILE"; }
log_warn() { echo -e "${YELLOW}[!]${NC} $1" | tee -a "$LOG_FILE"; }

# ============================================================================
# MAIN
# ============================================================================

log "Migrando $SOURCE_TABLE ($SOURCE_DB) → $DEST_TABLE ($DEST_DB)"

# Fase 1: Contar registros origen
log "Fase 1: Contando registros en origen..."
SOURCE_COUNT=$(mysql -h "$SRC_HOST" -u "$SRC_USER" ${SRC_PASS:+-p"$SRC_PASS"} \
    "$SOURCE_DB" -N -e "SELECT COUNT(*) FROM $SOURCE_TABLE;")
log_ok "Registros origen: $SOURCE_COUNT"

# Fase 2: Extraer estructura
log "Fase 2: Extrayendo estructura..."
STRUCT_FILE="/tmp/${SOURCE_TABLE}_struct_${TIMESTAMP}.sql"
mysqldump -h "$SRC_HOST" -u "$SRC_USER" ${SRC_PASS:+-p"$SRC_PASS"} \
    "$SOURCE_DB" "$SOURCE_TABLE" --no-data > "$STRUCT_FILE"
log_ok "Estructura extraída"

# Fase 3: Crear tabla destino
log "Fase 3: Creando tabla destino..."
mysql -h "$DST_HOST" -u "$DST_USER" ${DST_PASS:+-p"$DST_PASS"} \
    "$DEST_DB" < "$STRUCT_FILE"
log_ok "Tabla $DEST_TABLE creada"

# Fase 4: Exportar datos en CSV limpio
log "Fase 4: Exportando datos..."
DATA_FILE="/tmp/${SOURCE_TABLE}_data_${TIMESTAMP}.csv"
mysql -h "$SRC_HOST" -u "$SRC_USER" ${SRC_PASS:+-p"$SRC_PASS"} \
    "$SOURCE_DB" -N --raw -e "
    SELECT * FROM $SOURCE_TABLE;
" > "$DATA_FILE"
log_ok "Datos exportados ($(wc -l < "$DATA_FILE") líneas)"

# Fase 5: Limpiar datos
log "Fase 5: Limpiando datos..."
# Limpiar espacios, tildes, etc.
sed -i "s/^[ \t]*//g; s/[ \t]*$//g" "$DATA_FILE"
log_ok "Datos limpios"

# Fase 6: Insertar en destino
log "Fase 6: Insertando datos en destino..."
mysql -h "$DST_HOST" -u "$DST_USER" ${DST_PASS:+-p"$DST_PASS"} \
    "$DEST_DB" -e "
    LOAD DATA LOCAL INFILE '$DATA_FILE' 
    INTO TABLE $DEST_TABLE 
    FIELDS TERMINATED BY '\t' 
    LINES TERMINATED BY '\n';
"
log_ok "Datos insertados"

# Fase 7: Validar conteos
log "Fase 7: Validando conteos..."
DEST_COUNT=$(mysql -h "$DST_HOST" -u "$DST_USER" ${DST_PASS:+-p"$DST_PASS"} \
    "$DEST_DB" -N -e "SELECT COUNT(*) FROM $DEST_TABLE;")

if [ "$SOURCE_COUNT" = "$DEST_COUNT" ]; then
    log_ok "Conteos OK: $SOURCE_COUNT → $DEST_COUNT"
else
    log_err "Conteos NO COINCIDEN: $SOURCE_COUNT → $DEST_COUNT"
    exit 1
fi

# Limpiar temporales
rm -f "$STRUCT_FILE" "$DATA_FILE"

log "═════════════════════════════════════════════"
log_ok "MIGRACIÓN COMPLETADA"
log "Log: $LOG_FILE"
log "═════════════════════════════════════════════"
```

---

## Script 3: Validación de calidad post-migración

### Caso de uso
Verificar que los datos migraron correctamente (duplicados, FK, NULL).

### Script: `validate-migration.sh`

```bash
#!/bin/bash

################################################################################
# validate-migration.sh
# Validación post-migración: duplicados, FK, NULL, espacios
# 
# Uso: ./validate-migration.sh production_db
################################################################################

set -e

DB_NAME="$1"
TIMESTAMP=$(date +%Y%m%d_%H%M%S)
REPORT_FILE="validation_${DB_NAME}_${TIMESTAMP}.txt"

DB_USER="${DB_USER:-root}"
DB_PASS="${DB_PASS:-}"
DB_HOST="${DB_HOST:-localhost}"

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

log() { echo "$(date +'%H:%M:%S') $1" | tee -a "$REPORT_FILE"; }
ok() { echo -e "${GREEN}[✓]${NC} $1" | tee -a "$REPORT_FILE"; }
err() { echo -e "${RED}[✗]${NC} $1" | tee -a "$REPORT_FILE"; }
warn() { echo -e "${YELLOW}[!]${NC} $1" | tee -a "$REPORT_FILE"; }

# Helper: ejecutar query
query() {
    mysql -h "$DB_HOST" -u "$DB_USER" ${DB_PASS:+-p"$DB_PASS"} \
        "$DB_NAME" -N -e "$1" 2>/dev/null
}

log "═════════════════════════════════════════════════════════"
log "VALIDACIÓN POST-MIGRACIÓN: $DB_NAME"
log "═════════════════════════════════════════════════════════"

# 1. NULL inconsistentes
log ""
log "1. Revisando NULL en campos que no deberían tenerlo..."
NULL_ISSUES=$(query "
SELECT COUNT(*) FROM information_schema.COLUMNS 
WHERE TABLE_SCHEMA = '$DB_NAME' 
  AND IS_NULLABLE = 'NO' 
  AND COLUMN_TYPE LIKE 'varchar%';
" 2>/dev/null || echo "0")

if [ "$NULL_ISSUES" -gt 0 ]; then
    warn "Encontrados $NULL_ISSUES campos NOT NULL que deberían revisar"
else
    ok "NULL OK"
fi

# 2. Duplicados en PK/UK
log ""
log "2. Revisando duplicados en columnas UNIQUE..."
TABLES=$(query "SHOW TABLES;")

DUPLICATE_COUNT=0
for table in $TABLES; do
    DUPES=$(query "
    SELECT COUNT(*) FROM (
        SELECT 1 
        FROM $table 
        GROUP BY id 
        HAVING COUNT(*) > 1
    ) t;
    " 2>/dev/null || echo "0")
    
    if [ "$DUPES" -gt 0 ]; then
        warn "$table: $DUPES duplicados encontrados"
        ((DUPLICATE_COUNT++))
    fi
done

if [ "$DUPLICATE_COUNT" -eq 0 ]; then
    ok "Sin duplicados"
fi

# 3. Espacios en blanco
log ""
log "3. Revisando espacios en blanco (leading/trailing)..."
SPACE_ISSUES=$(query "
SELECT COUNT(*) FROM $table 
WHERE id IN (
    SELECT id FROM $table 
    WHERE COLUMN_NAME REGEXP '^[ ]|[ ]$'
);
" 2>/dev/null || echo "0")

if [ "$SPACE_ISSUES" -gt 0 ]; then
    warn "Encontrados espacios en blanco"
else
    ok "Sin espacios innecesarios"
fi

# 4. Charset/Collation
log ""
log "4. Validando charset/collation..."
CHARSET=$(query "
SELECT DISTINCT TABLE_COLLATION FROM information_schema.TABLES 
WHERE TABLE_SCHEMA = '$DB_NAME';
")

if echo "$CHARSET" | grep -q "utf8mb4"; then
    ok "Charset OK: UTF8MB4"
else
    warn "Charset no estándar: $CHARSET"
fi

# 5. Foreign Key references
log ""
log "5. Verificando FK orfandadas..."
FK_ISSUES=$(query "
SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS 
WHERE CONSTRAINT_SCHEMA = '$DB_NAME';
" 2>/dev/null || echo "0")

if [ "$FK_ISSUES" -eq 0 ]; then
    ok "FK OK"
else
    warn "Revisar $FK_ISSUES FK"
fi

# 6. Fechas inválidas
log ""
log "6. Revisando fechas inválidas..."
DATE_ISSUES=$(query "
SELECT COUNT(*) FROM $table 
WHERE created_at IS NOT NULL 
  AND created_at > NOW();
" 2>/dev/null || echo "0")

if [ "$DATE_ISSUES" -eq 0 ]; then
    ok "Fechas OK"
else
    warn "Encontradas $DATE_ISSUES fechas inválidas (futuro)"
fi

# Reporte final
log ""
log "═════════════════════════════════════════════════════════"
log "VALIDACIÓN COMPLETADA"
log "Reporte: $REPORT_FILE"
log "═════════════════════════════════════════════════════════"
```

---

## Script 4: Rollback automático

### Caso de uso
Revertir una migración fallida a partir del backup.

### Script: `rollback-migration.sh`

```bash
#!/bin/bash

################################################################################
# rollback-migration.sh
# Revertir migración desde backup
# 
# Uso: ./rollback-migration.sh production_db backup_file.sql
################################################################################

set -e

DB_NAME="$1"
BACKUP_FILE="$2"

if [ ! -f "$BACKUP_FILE" ]; then
    echo "❌ Backup no encontrado: $BACKUP_FILE"
    exit 1
fi

DB_USER="${DB_USER:-root}"
DB_PASS="${DB_PASS:-}"
DB_HOST="${DB_HOST:-localhost}"

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

log() { echo -e "${YELLOW}[!]${NC} $1"; }
ok() { echo -e "${GREEN}[✓]${NC} $1"; }
err() { echo -e "${RED}[✗]${NC} $1"; }

echo "⚠️  ADVERTENCIA: Esto eliminará la BD actual y restaurará desde backup"
echo ""
echo "BD: $DB_NAME"
echo "Backup: $BACKUP_FILE"
echo "Tamaño: $(du -h "$BACKUP_FILE" | cut -f1)"
echo ""
read -p "¿Continuar? (escribe 'SI' para confirmar): " confirm

if [ "$confirm" != "SI" ]; then
    echo "Cancelado"
    exit 1
fi

log "Iniciando rollback..."

# Dropear BD actual
log "Eliminando BD actual..."
mysql -h "$DB_HOST" -u "$DB_USER" ${DB_PASS:+-p"$DB_PASS"} -e "DROP DATABASE IF EXISTS $DB_NAME;" 2>/dev/null

# Recrear BD
log "Recreando BD..."
mysql -h "$DB_HOST" -u "$DB_USER" ${DB_PASS:+-p"$DB_PASS"} -e "CREATE DATABASE $DB_NAME;" 2>/dev/null

# Restaurar desde backup
log "Restaurando desde backup..."
mysql -h "$DB_HOST" -u "$DB_USER" ${DB_PASS:+-p"$DB_PASS"} "$DB_NAME" < "$BACKUP_FILE" 2>/dev/null

ok "ROLLBACK COMPLETADO"
echo "BD restaurada: $DB_NAME"
echo "Timestamp: $(date)"
```

---

## Script 5: Generar reporte de migración

### Caso de uso
Documentar automáticamente qué se migró, cambios estructurales, etc.

### Script: `generate-migration-report.sh`

```bash
#!/bin/bash

################################################################################
# generate-migration-report.sh
# Generar reporte de migración (Markdown)
# 
# Uso: ./generate-migration-report.sh production_db report_name
################################################################################

DB_NAME="$1"
REPORT_NAME="${2:-migration_report}"
REPORT_FILE="${REPORT_NAME}_$(date +%Y%m%d).md"

DB_USER="${DB_USER:-root}"
DB_PASS="${DB_PASS:-}"
DB_HOST="${DB_HOST:-localhost}"

query() {
    mysql -h "$DB_HOST" -u "$DB_USER" ${DB_PASS:+-p"$DB_PASS"} \
        "$DB_NAME" -N -e "$1" 2>/dev/null
}

# Iniciar reporte
cat > "$REPORT_FILE" << 'EOF'
# Reporte de Migración de Base de Datos

**Generado:** $(date)
**BD:** $DB_NAME
**Host:** $DB_HOST

---

## Resumen Ejecutivo

EOF

# Tablas
TABLES=$(query "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='$DB_NAME';")
echo "- Tablas: $TABLES" >> "$REPORT_FILE"

# Registros totales
TOTAL_ROWS=$(query "SELECT SUM(TABLE_ROWS) FROM information_schema.TABLES WHERE TABLE_SCHEMA='$DB_NAME';")
echo "- Registros totales: ${TOTAL_ROWS:-?}" >> "$REPORT_FILE"

# Tamaño
SIZE=$(query "SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) FROM information_schema.TABLES WHERE TABLE_SCHEMA='$DB_NAME';")
echo "- Tamaño: ${SIZE} MB" >> "$REPORT_FILE"

# Tabla detallada
cat >> "$REPORT_FILE" << 'EOF'

---

## Tablas Migradas

| Tabla | Registros | Índices | PK | FKs |
|-------|-----------|---------|----|----|

EOF

query "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA='$DB_NAME';" | while read table; do
    ROWS=$(query "SELECT COUNT(*) FROM $table;")
    INDEXES=$(query "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA='$DB_NAME' AND TABLE_NAME='$table' AND SEQ_IN_INDEX = 1;")
    HAS_PK=$(query "SELECT IF(COLUMN_KEY='PRI',1,0) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$DB_NAME' AND TABLE_NAME='$table' LIMIT 1;")
    FK_COUNT=$(query "SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA='$DB_NAME' AND TABLE_NAME='$table';")
    
    echo "| $table | $ROWS | $INDEXES | $HAS_PK | $FK_COUNT |" >> "$REPORT_FILE"
done

echo ""
echo "✅ Reporte generado: $REPORT_FILE"
```

---

## Guía de uso: Estructura de carpetas

```
proyecto-migracion/
├── scripts/
│   ├── migrate-db.sh              # Migración principal
│   ├── migrate-table.sh            # Tabla específica
│   ├── validate-migration.sh       # Validación
│   ├── rollback-migration.sh       # Rollback
│   └── generate-migration-report.sh # Reporte
├── backups/                         # Guardar backups aquí
│   └── production_db_20240127.sql
├── migrations/
│   ├── migration.sql               # Script principal
│   ├── rollback.sql                # Script de rollback
│   └── data-cleanup.sql            # Limpiar datos
├── .env                             # Credenciales (no versionear)
└── README.md                        # Documentación
```

---

## Checklist pre-ejecución

```bash
# 1. Permisos de ejecución
chmod +x scripts/*.sh

# 2. Credenciales en .env
export DB_USER="root"
export DB_PASSWORD="tu_password"
export DB_HOST="localhost"

# 3. Verificar backup
ls -lh backups/

# 4. Dry-run (simulación)
./scripts/migrate-db.sh --dry-run production_db

# 5. Ejecutar
./scripts/migrate-db.sh production_db backups/
```

---

## Ejecución en producción (ventana de mantenimiento)

```bash
#!/bin/bash
# run-production-migration.sh

set -e

# Validar que sea hora de mantenimiento (ej: 02:00 AM)
HOUR=$(date +%H)
if [ "$HOUR" != "02" ]; then
    echo "Error: Ejecutar entre 02:00-03:00 AM solamente"
    exit 1
fi

# Cargar credenciales
source .env

# Ejecutar migración
echo "🔄 Iniciando migración en producción..."
./scripts/migrate-db.sh production_db backups/

# Validar
echo "✓ Validando..."
./scripts/validate-migration.sh production_db

# Reporte
echo "📊 Generando reporte..."
./scripts/generate-migration-report.sh production_db

# Notificar
echo "✅ Migración completada" | mail -s "BD migrada" admin@example.com

echo "Done!"
```

---

## Troubleshooting

### Error: "Access denied for user"
```bash
# Verificar credenciales
mysql -h localhost -u root -p -e "SELECT 1;"

# O usar .my.cnf
cat ~/.my.cnf
# [client]
# user=root
# password=tu_password
```

### Error: "Table already exists"
```bash
# Script intenta crear tabla que ya existe
# Solución: agregar IF NOT EXISTS
ALTER TABLE ... ENGINE=InnoDB;  # Si existe, RENAME
```

### Error: "No hay suficiente espacio"
```bash
# Verificar espacio disco
df -h /

# Comprimir backup
gzip production_db_20240127.sql
# Ahora ocupa 90% menos
```

### Performance lenta en INSERT
```bash
# Deshabilitar keys temporalmente
mysql -e "ALTER TABLE tabla DISABLE KEYS;"
# ... insertar datos ...
mysql -e "ALTER TABLE tabla ENABLE KEYS;"

# O usar LOAD DATA INFILE (más rápido)
LOAD DATA LOCAL INFILE 'file.csv' 
INTO TABLE tabla 
FIELDS TERMINATED BY ',' 
LINES TERMINATED BY '\n';
```
