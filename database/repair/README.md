# Database Repair & Schema Integrity Guide (Hetzner Migration)

This guide documents the safe repair procedures for the production MySQL database on Hetzner, addressing tables where `ALTER TABLE` definitions (Primary Keys, `AUTO_INCREMENT`, Unique Keys, Indexes, and Foreign Keys) were missed during migration, resulting in rows with `id = 0`.

---

## ⚠️ Critical Deployment Warning

> **IMPORTANT:**
> **Do NOT deploy Phase 1 application code until the repair migration runs and `verify.sql` passes 100% of checks.**
> Any new records written by application code while `AUTO_INCREMENT` is inactive will receive `id = 0`, corrupting relational links and breaking API operations.

---

## 1. Execution Order via Laravel Migration

A dedicated idempotent Laravel migration has been created:
`database/migrations/2026_11_18_120000_repair_missing_primary_keys_and_foreign_keys.php`

### Step 1: Create Full Pre-Repair Backup
Run this command directly on the Hetzner server:
```bash
mysqldump -u <DB_USER> -p \
  --single-transaction \
  --quick \
  --routines \
  --triggers \
  --events \
  --hex-blob \
  <DB_NAME> > backup_pre_repair_$(date +%Y%m%d_%H%M%S).sql
```

### Step 2: Run Pre-Repair Schema Audit (Optional but Recommended)
```bash
mysql -u <DB_USER> -p <DB_NAME> < database/repair/audit_schema.sql
```

### Step 3: Run the Laravel Migration
```bash
php artisan migrate --force
```

This single command:
1. **Renumbers rows where `id = 0`**:
   - `module_setting_definitions`: 7 rows renumbered 1..7 preserving configuration ordering.
   - `attribution_event_logs`: 78 rows renumbered 1..78 chronologically.
2. **Handles Orphan Foreign Keys**:
   - `attribution_event_logs.user_id`: Any invalid IDs not in `users` are set to `NULL` (matching `ON DELETE SET NULL`).
3. **Safely handles `attribution_event_log_params`**:
   - If the table has rows and no primary key, the 184 rows with `log_id = 0` are copied to `attribution_event_log_params_backup` and the table is cleared with a logged warning.
4. **Applies Missing Keys, `AUTO_INCREMENT`, and Indexes**:
   - For all 8 tables: `attribution_event_logs`, `attribution_event_log_params`, `module_setting_definitions`, `module_setting_values`, `plugin_licenses`, `seo_slug_registry`, `taxonomy_slugs`, `country_location_indexability`.
   - Sets `AUTO_INCREMENT = MAX(id) + 1`.
5. **Applies `module_id` Index + Foreign Key on 9 Scoped Tables**:
   - `categories`, `custom_fields`, `packages`, `sliders`, `banner_ads`, `feature_sections`, `tips`, `notifications`, `blog_categories`.
   - Checks each table: if any row has an unknown `module_id` not present in `modules`, the foreign key is skipped with a logged warning.
6. **100% Idempotent**:
   - On any table or database that already has the primary key, index, or foreign key, the migration skips it (clean no-op).

### Step 4: Run Post-Fix Verification
```bash
mysql -u <DB_USER> -p <DB_NAME> < database/repair/verify.sql
```
Confirm all checks output **`PASS`**.

---

## 2. File Inventory

| File Path | Description |
| :--- | :--- |
| `database/migrations/2026_11_18_120000_repair_missing_primary_keys_and_foreign_keys.php` | The Laravel migration that applies the idempotent repair cleanly on the server via `php artisan migrate`. |
| `database/repair/fix_missing_keys.sql` | Alternative raw SQL repair script if the DBA prefers executing SQL directly in MySQL CLI / phpMyAdmin. |
| `database/repair/audit_schema.sql` | Diagnostic queries against `information_schema` identifying missing keys, constraints, and orphaned rows. |
| `database/repair/verify.sql` | Post-execution validation script including transactional rollback test. |
| `database/repair/README.md` | Run instructions, execution order, rollback guide, and deployment prerequisites. |
