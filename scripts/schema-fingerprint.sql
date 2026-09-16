-- One sorted line per schema object of the current schema: every column with
-- its type, nullability and default; every index definition; every constraint
-- definition. Two runs of `make migrations-roundtrip` compare these listings,
-- so a migration whose `down` leaves something behind — or removes something
-- its `up` created differently — shows up as a differing line naming the
-- object (change harden-gate-floor, design decision 4a).
--
-- Written against `current_schema()` so it can be pointed at a throwaway
-- schema: `tests/Integration/Db/SchemaFingerprintTest.php` does exactly that
-- to prove each of the three categories below is actually detected.
--
-- What it does NOT cover, stated here rather than discovered later: sequences,
-- triggers, functions, comments, grants, and row contents. A migration that
-- changes only one of those round-trips silently as far as this listing goes.
-- What it does cover, it covers completely: a column's full type including its
-- modifiers (length, precision and scale), its nullability and its default.
SELECT line FROM (
    -- `format_type` rather than `information_schema.columns.data_type`, which
    -- drops the type's modifiers: varchar(32) and varchar(64) both read as
    -- `character varying` there, so a length change round-tripped invisibly
    -- inside the coverage this listing promises (Gate 2 round 1, finding 3).
    -- It is what DBAL's own schema manager reads for the same reason.
    SELECT format(
               'column %s.%s %s %s %s',
               rel.relname, att.attname,
               format_type(att.atttypid, att.atttypmod),
               CASE WHEN att.attnotnull THEN 'NOT NULL' ELSE 'NULL' END,
               coalesce(pg_get_expr(def.adbin, def.adrelid), '-')
           ) AS line
      FROM pg_attribute att
      JOIN pg_class rel ON rel.oid = att.attrelid
      JOIN pg_namespace ns ON ns.oid = rel.relnamespace
      LEFT JOIN pg_attrdef def ON def.adrelid = att.attrelid AND def.adnum = att.attnum
     WHERE ns.nspname = current_schema()
       AND rel.relkind IN ('r', 'p')
       AND att.attnum > 0
       AND NOT att.attisdropped
    UNION ALL
    SELECT format('index %s', indexdef)
      FROM pg_indexes
     WHERE schemaname = current_schema()
    UNION ALL
    SELECT format('constraint %s.%s %s', rel.relname, con.conname, pg_get_constraintdef(con.oid))
      FROM pg_constraint con
      JOIN pg_class rel ON rel.oid = con.conrelid
      JOIN pg_namespace ns ON ns.oid = rel.relnamespace
     WHERE ns.nspname = current_schema()
) s
ORDER BY line
