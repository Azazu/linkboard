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
SELECT line FROM (
    SELECT format(
               'column %s.%s %s %s %s',
               table_name, column_name, data_type, is_nullable,
               coalesce(column_default, '-')
           ) AS line
      FROM information_schema.columns
     WHERE table_schema = current_schema()
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
