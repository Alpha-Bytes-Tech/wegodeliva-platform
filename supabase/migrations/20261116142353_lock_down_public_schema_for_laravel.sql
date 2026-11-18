-- Applied to the WeGoDeliva Supabase project (ref ydoczmolxngbrlkervbs) on 2026-11-16.
--
-- Laravel (connecting as postgres) owns the tables in public. The apps never read them through
-- the Data API; anything the apps need from Supabase is granted explicitly, with RLS, later.

-- 1. New tables, sequences and functions in public are no longer granted to anon/authenticated.
alter default privileges for role postgres in schema public revoke all on tables from anon, authenticated;
alter default privileges for role postgres in schema public revoke all on sequences from anon, authenticated;
alter default privileges for role postgres in schema public revoke all on functions from anon, authenticated, public;

-- Same for anything that already exists (the schema is empty today; this keeps the migration re-runnable).
revoke all on all tables in schema public from anon, authenticated;
revoke all on all sequences in schema public from anon, authenticated;
revoke all on all functions in schema public from anon, authenticated;

-- 2. Defence in depth: every table created in public gets RLS switched on automatically.
create or replace function extensions.enable_rls_on_new_public_tables()
returns event_trigger
language plpgsql
set search_path = ''
as $$
declare
  obj record;
begin
  for obj in
    select * from pg_event_trigger_ddl_commands()
    where command_tag in ('CREATE TABLE', 'CREATE TABLE AS', 'SELECT INTO')
      and object_type = 'table'
      and schema_name = 'public'
  loop
    execute format('alter table %s enable row level security', obj.object_identity);
  end loop;
end;
$$;

drop event trigger if exists enable_rls_on_new_public_tables;
create event trigger enable_rls_on_new_public_tables
  on ddl_command_end
  when tag in ('CREATE TABLE', 'CREATE TABLE AS', 'SELECT INTO')
  execute function extensions.enable_rls_on_new_public_tables();

-- 3. PostGIS for zones.polygon (spec 03 §3.2), in the extensions schema as Supabase recommends.
--    Laravel's search_path is "public,extensions" (backend/config/database.php) so it finds it.
create extension if not exists postgis with schema extensions;
