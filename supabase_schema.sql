-- ============================================================
--  mywms — Supabase database  (THE ONE MASTER FILE — always the full, current schema)
--
--  HOW TO USE
--   • New database:      Supabase → SQL Editor → paste this whole file → Run.
--   • Existing database: also safe to run again — everything is "if not exists" /
--                        idempotent, nothing is dropped and no data is touched.
--
--  HOW UPDATES ARE DONE (rule for this project)
--   Every schema change is written TWICE:
--     1) a new file in  sql_updates/YYYY-MM-DD_short-name.sql  — only that change,
--        so an existing database can be upgraded by running just that file;
--     2) the same change merged into THIS file, so a fresh install is always complete.
--   Then add a line to the change log below.
--
--  CHANGE LOG  (newest first — matching files are in sql_updates/)
--   2026-10-24  quantities saved as +/- (wms_apply_delta)     → sql_updates/2026-10-24_stock_delta.sql
--   2026-10-24  POS withdrawal documents table "withdrawals"  → sql_updates/2026-10-24_pos_withdrawals.sql
--   2026-10-07  marketplace orders table "mpOrders"         → sql_updates/2026-10-07_marketplace_orders.sql
--   2026-09-20  RLS lock-down, userMaster, PO/SO tables      → sql_updates/2026-09-20_security_users_po_so.sql
--   2026-09-20  initial schema
--
--  Table layout: company + rk (merge key) + readable columns generated from the JSON
--  + a final "data" jsonb column holding the full record. PostgREST reloads automatically.
-- ============================================================

create table if not exists public.kv (name text primary key, data jsonb);
create table if not exists public.settings (company text primary key, data jsonb);

create table if not exists public."itemMaster" (
  company text not null,
  rk      text not null,
  "code" text generated always as (data->>'code') stored,
  "name" text generated always as (data->>'name') stored,
  "unit" text generated always as (data->>'unit') stored,
  "cat" text generated always as (data->>'cat') stored,
  "cost" text generated always as (data->>'cost') stored,
  "minQty" text generated always as (data->>'minQty') stored,
  "maxQty" text generated always as (data->>'maxQty') stored,
  "barcode" text generated always as (data->>'barcode') stored,
  "sup" text generated always as (data->>'sup') stored,
  "img" text generated always as (data->>'img') stored,
  data    jsonb,
  primary key (company, rk)
);
create index if not exists "itemMaster_company" on public."itemMaster" (company);

create table if not exists public."stock" (
  company text not null,
  rk      text not null,
  "code" text generated always as (data->>'code') stored,
  "loc" text generated always as (data->>'loc') stored,
  "qty" text generated always as (data->>'qty') stored,
  "lotNo" text generated always as (data->>'lotNo') stored,
  "lotSup" text generated always as (data->>'lotSup') stored,
  "mfd" text generated always as (data->>'mfd') stored,
  "exp" text generated always as (data->>'exp') stored,
  "sn" text generated always as (data->>'sn') stored,
  "cost" text generated always as (data->>'cost') stored,
  data    jsonb,
  primary key (company, rk)
);
create index if not exists "stock_company" on public."stock" (company);

create table if not exists public."locationMaster" (
  company text not null,
  rk      text not null,
  "code" text generated always as (data->>'code') stored,
  "warehouse" text generated always as (data->>'warehouse') stored,
  "zone" text generated always as (data->>'zone') stored,
  "capacity" text generated always as (data->>'capacity') stored,
  data    jsonb,
  primary key (company, rk)
);
create index if not exists "locationMaster_company" on public."locationMaster" (company);

create table if not exists public."labelMaster" (
  company text not null,
  rk      text not null,
  "code" text generated always as (data->>'code') stored,
  "name" text generated always as (data->>'name') stored,
  data    jsonb,
  primary key (company, rk)
);
create index if not exists "labelMaster_company" on public."labelMaster" (company);

create table if not exists public."history" (
  company text not null,
  rk      text not null,
  "id" text generated always as (data->>'id') stored,
  "type" text generated always as (data->>'type') stored,
  "date" text generated always as (data->>'date') stored,
  "time" text generated always as (data->>'time') stored,
  "code" text generated always as (data->>'code') stored,
  "name" text generated always as (data->>'name') stored,
  "qty" text generated always as (data->>'qty') stored,
  "loc" text generated always as (data->>'loc') stored,
  "toLoc" text generated always as (data->>'toLoc') stored,
  "orderNo" text generated always as (data->>'orderNo') stored,
  "lotNo" text generated always as (data->>'lotNo') stored,
  "lotSup" text generated always as (data->>'lotSup') stored,
  "mfd" text generated always as (data->>'mfd') stored,
  "exp" text generated always as (data->>'exp') stored,
  "sn" text generated always as (data->>'sn') stored,
  "status" text generated always as (data->>'status') stored,
  "by" text generated always as (data->>'by') stored,
  "supplier" text generated always as (data->>'supplier') stored,
  "dept" text generated always as (data->>'dept') stored,
  "value" text generated always as (data->>'value') stored,
  data    jsonb,
  primary key (company, rk)
);
create index if not exists "history_company" on public."history" (company);

create table if not exists public."schedule" (
  company text not null,
  rk      text not null,
  "id" text generated always as (data->>'id') stored,
  "type" text generated always as (data->>'type') stored,
  "ref" text generated always as (data->>'ref') stored,
  "supplier" text generated always as (data->>'supplier') stored,
  "customer" text generated always as (data->>'customer') stored,
  "due" text generated always as (data->>'due') stored,
  "dept" text generated always as (data->>'dept') stored,
  "status" text generated always as (data->>'status') stored,
  data    jsonb,
  primary key (company, rk)
);
create index if not exists "schedule_company" on public."schedule" (company);

create table if not exists public."bookings" (
  company text not null,
  rk      text not null,
  "id" text generated always as (data->>'id') stored,
  "customer" text generated always as (data->>'customer') stored,
  "date" text generated always as (data->>'date') stored,
  "status" text generated always as (data->>'status') stored,
  data    jsonb,
  primary key (company, rk)
);
create index if not exists "bookings_company" on public."bookings" (company);

create table if not exists public."logisticMaster" (
  company text not null,
  rk      text not null,
  "name" text generated always as (data->>'name') stored,
  "phone" text generated always as (data->>'phone') stored,
  "contact" text generated always as (data->>'contact') stored,
  data    jsonb,
  primary key (company, rk)
);
create index if not exists "logisticMaster_company" on public."logisticMaster" (company);

create table if not exists public."customerMaster" (
  company text not null,
  rk      text not null,
  "code" text generated always as (data->>'code') stored,
  "name" text generated always as (data->>'name') stored,
  "phone" text generated always as (data->>'phone') stored,
  "address" text generated always as (data->>'address') stored,
  "taxId" text generated always as (data->>'taxId') stored,
  data    jsonb,
  primary key (company, rk)
);
create index if not exists "customerMaster_company" on public."customerMaster" (company);

create table if not exists public."supplierMaster" (
  company text not null,
  rk      text not null,
  "code" text generated always as (data->>'code') stored,
  "name" text generated always as (data->>'name') stored,
  "phone" text generated always as (data->>'phone') stored,
  "address" text generated always as (data->>'address') stored,
  data    jsonb,
  primary key (company, rk)
);
create index if not exists "supplierMaster_company" on public."supplierMaster" (company);

create table if not exists public."ngMaster" (
  company text not null,
  rk      text not null,
  "code" text generated always as (data->>'code') stored,
  "reason" text generated always as (data->>'reason') stored,
  data    jsonb,
  primary key (company, rk)
);
create index if not exists "ngMaster_company" on public."ngMaster" (company);

create table if not exists public."bomMaster" (
  company text not null,
  rk      text not null,
  "id" text generated always as (data->>'id') stored,
  "code" text generated always as (data->>'code') stored,
  "name" text generated always as (data->>'name') stored,
  data    jsonb,
  primary key (company, rk)
);
create index if not exists "bomMaster_company" on public."bomMaster" (company);

create table if not exists public."stocktake" (
  company text not null,
  rk      text not null,
  "id" text generated always as (data->>'id') stored,
  "date" text generated always as (data->>'date') stored,
  "status" text generated always as (data->>'status') stored,
  data    jsonb,
  primary key (company, rk)
);
create index if not exists "stocktake_company" on public."stocktake" (company);

create table if not exists public."returns" (
  company text not null,
  rk      text not null,
  "id" text generated always as (data->>'id') stored,
  "date" text generated always as (data->>'date') stored,
  "customer" text generated always as (data->>'customer') stored,
  "status" text generated always as (data->>'status') stored,
  data    jsonb,
  primary key (company, rk)
);
create index if not exists "returns_company" on public."returns" (company);

create table if not exists public."sales" (
  company text not null,
  rk      text not null,
  "id" text generated always as (data->>'id') stored,
  "date" text generated always as (data->>'date') stored,
  "time" text generated always as (data->>'time') stored,
  "total" text generated always as (data->>'total') stored,
  "status" text generated always as (data->>'status') stored,
  "cashier" text generated always as (data->>'cashier') stored,
  data    jsonb,
  primary key (company, rk)
);
create index if not exists "sales_company" on public."sales" (company);

create table if not exists public."shifts" (
  company text not null,
  rk      text not null,
  "id" text generated always as (data->>'id') stored,
  "date" text generated always as (data->>'date') stored,
  "user" text generated always as (data->>'user') stored,
  data    jsonb,
  primary key (company, rk)
);
create index if not exists "shifts_company" on public."shifts" (company);

create table if not exists public."promotions" (
  company text not null,
  rk      text not null,
  "id" text generated always as (data->>'id') stored,
  "name" text generated always as (data->>'name') stored,
  "type" text generated always as (data->>'type') stored,
  data    jsonb,
  primary key (company, rk)
);
create index if not exists "promotions_company" on public."promotions" (company);

create table if not exists public."giftcards" (
  company text not null,
  rk      text not null,
  "code" text generated always as (data->>'code') stored,
  "balance" text generated always as (data->>'balance') stored,
  "status" text generated always as (data->>'status') stored,
  data    jsonb,
  primary key (company, rk)
);
create index if not exists "giftcards_company" on public."giftcards" (company);

create table if not exists public."purchaseOrders" (
  company text not null,
  rk      text not null,
  "id" text generated always as (data->>'id') stored,
  "no" text generated always as (data->>'no') stored,
  "supplier" text generated always as (data->>'supplier') stored,
  "date" text generated always as (data->>'date') stored,
  "due" text generated always as (data->>'due') stored,
  "status" text generated always as (data->>'status') stored,
  "total" text generated always as (data->>'total') stored,
  data    jsonb,
  primary key (company, rk)
);
create index if not exists "purchaseOrders_company" on public."purchaseOrders" (company);

create table if not exists public."salesOrders" (
  company text not null,
  rk      text not null,
  "id" text generated always as (data->>'id') stored,
  "no" text generated always as (data->>'no') stored,
  "customer" text generated always as (data->>'customer') stored,
  "date" text generated always as (data->>'date') stored,
  "due" text generated always as (data->>'due') stored,
  "status" text generated always as (data->>'status') stored,
  "total" text generated always as (data->>'total') stored,
  data    jsonb,
  primary key (company, rk)
);
create index if not exists "salesOrders_company" on public."salesOrders" (company);

create table if not exists public."mpOrders" (            -- Shopee / Lazada orders (added 2026-10-07)
  company    text not null,
  rk         text not null,                                        -- "<platform>:<orderNo>"
  "id"       text generated always as (data->>'id') stored,
  "platform" text generated always as (data->>'platform') stored,
  "orderNo"  text generated always as (data->>'orderNo') stored,
  "date"     text generated always as (data->>'date') stored,
  "status"   text generated always as (data->>'status') stored,
  "buyer"    text generated always as (data->>'buyer') stored,
  "total"    text generated always as (data->>'total') stored,
  data       jsonb,
  primary key (company, rk)
);
create index if not exists "mpOrders_company" on public."mpOrders" (company);
create index if not exists "mpOrders_date"    on public."mpOrders" (company, "date");
-- Shop credentials (partner_key / app_secret) live in kv under "mp:<company>"; ?action=load never returns them.

create table if not exists public."withdrawals" (         -- POS withdrawal documents (added 2026-10-24)
  company text not null,
  rk      text not null,                                           -- the document id
  "id"    text generated always as (data->>'id') stored,
  "date"  text generated always as (data->>'date') stored,
  "by"    text generated always as (data->>'by') stored,
  data    jsonb,
  primary key (company, rk)
);
create index if not exists "withdrawals_company" on public."withdrawals" (company);
create index if not exists "withdrawals_date"    on public."withdrawals" (company, "date");

-- ---- User accounts: one row per user (replaces the old kv 'users' JSON) ----
-- Secrets are ONLY bcrypt/argon hashes from PHP password_hash(); never plaintext.
create table if not exists public."userMaster" (
  username    text primary key,
  company     text not null default '',          -- company id, or '*' for Super Admin
  position    text not null default '',          -- Super Admin | Admin | Manager | Worker | ...
  full_name   text not null default '',
  department  text not null default '',
  pass_hash   text,
  pin_hash    text,
  data        jsonb not null default '{}'::jsonb, -- other fields (menus, ...)
  created_at  timestamptz not null default now(),
  updated_at  timestamptz not null default now()
);
create unique index if not exists "userMaster_username_lower" on public."userMaster" (lower(username));
create index if not exists "userMaster_company" on public."userMaster" (company);

-- ---- Security: Row Level Security ON, no policies ----
-- Only the PHP backend (service role / secret key, which bypasses RLS) may read or
-- write. The public "anon" key gets nothing. Do NOT add anon/authenticated policies.
do $$
declare t text;
begin
  foreach t in array array['kv','settings','userMaster','itemMaster','stock','locationMaster','labelMaster','history','schedule',
    'bookings','logisticMaster','customerMaster','supplierMaster','ngMaster','bomMaster','stocktake','returns',
    'sales','shifts','promotions','giftcards','purchaseOrders','salesOrders','mpOrders','withdrawals']
  loop
    if to_regclass(format('public.%I', t)) is not null then
      execute format('alter table public.%I enable row level security', t);
      execute format('revoke all on public.%I from anon, authenticated', t);
    end if;
  end loop;
end $$;

-- ---- Quantities saved as +/- changes (added 2026-10-24) ----
-- change sets already applied: a retried request (lost response, app reopened) is never added twice
create table if not exists public.wms_applied (
  id  text primary key,
  at  timestamptz not null default now()
);
alter table public.wms_applied enable row level security;
revoke all on public.wms_applied from anon, authenticated;

-- p_rows: [{rk, d:{field:+/-n}, set:{other changed fields}, data:{full row}, del:bool}]
create or replace function public.wms_apply_delta(p_id text, p_table text, p_company text, p_rows jsonb)
returns integer
language plpgsql
as $$
declare
  r jsonb; k text; d jsonb; s jsonb; f text; cur jsonb; nd jsonb; n integer; done integer := 0;
begin
  if p_table not in ('stock','giftcards','customerMaster') then
    raise exception 'wms_apply_delta: table % not allowed', p_table;
  end if;
  if coalesce(p_id,'') <> '' then
    insert into public.wms_applied(id) values (p_id) on conflict do nothing;
    get diagnostics n = row_count;
    if n = 0 then return 0; end if;                       -- this change set was applied already (a retry)
  end if;

  for r in select value from jsonb_array_elements(p_rows) loop
    k := r->>'rk'; d := coalesce(r->'d','{}'::jsonb); s := coalesce(r->'set','{}'::jsonb);
    if coalesce(k,'') = '' then continue; end if;
    <<one_row>>
    loop
      execute format('select data from public.%I where company = $1 and rk = $2 for update', p_table)
        into cur using p_company, k;
      if cur is null then
        exit one_row when coalesce((r->>'del')::boolean, false);   -- gone already: nothing to take from
        execute format('insert into public.%I (company, rk, data) values ($1, $2, $3) on conflict (company, rk) do nothing', p_table)
          using p_company, k, r->'data';                  -- a new row: its numbers ARE the change
        get diagnostics n = row_count;
        exit one_row when n = 1;                          -- else another device created it a moment ago: add to theirs
      else
        nd := cur || s;
        for f in select jsonb_object_keys(d) loop
          nd := jsonb_set(nd, array[f],
                  to_jsonb(round(coalesce(nullif(cur->>f, '')::numeric, 0) + (d->>f)::numeric, 2)), true);
        end loop;
        if coalesce((r->>'del')::boolean, false) and p_table = 'stock'
           and coalesce(nullif(nd->>'qty', '')::numeric, 0) = 0 then
          execute format('delete from public.%I where company = $1 and rk = $2', p_table) using p_company, k;
        else
          execute format('update public.%I set data = $3 where company = $1 and rk = $2', p_table) using p_company, k, nd;
        end if;
        exit one_row;
      end if;
    end loop;
    done := done + 1;
  end loop;

  if random() < 0.01 then
    delete from public.wms_applied where at < now() - interval '90 days';   -- keep the log small
  end if;
  return done;
end
$$;
revoke all on function public.wms_apply_delta(text, text, text, jsonb) from public, anon, authenticated;


-- ---- Storage bucket for product images ----
-- Public READ via public URLs only. No anon insert/update/delete policies:
-- uploads and deletes go through api_supabase.php with the service key.
insert into storage.buckets (id, name, public) values ('mywms','mywms', true)
on conflict (id) do update set public = true;
drop policy if exists "mywms_read"   on storage.objects;
drop policy if exists "mywms_insert" on storage.objects;
drop policy if exists "mywms_update" on storage.objects;
drop policy if exists "mywms_delete" on storage.objects;

-- NOTE: connect with the SERVICE ROLE key (or a secret key). Keep it server-side only.
-- The generated columns are read-only (edit the "data" JSON to change values).

-- ---- Checks (optional) ----
--   every table should show rowsecurity = true:
--     select tablename, rowsecurity from pg_tables where schemaname = 'public' order by tablename;
--   user accounts (old kv 'users' rows are moved here automatically by api_supabase.php on first use):
--     select username, company, position, pass_hash is not null as has_pw, pin_hash is not null as has_pin from public."userMaster";
