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
--   2026-11-13  add-ons (ISO / ERP): table "addonRecords"   → sql_updates/2026-11-13_addons.sql
--   2026-11-12  rental jobs (งานเช่า): table "rentals"        → sql_updates/2026-11-12_rentals.sql
--   2026-11-05  label history: table "labelLog"              → sql_updates/2026-11-05_label_log.sql
--   2026-11-02  data log: table audit_log + triggers on every table → sql_updates/2026-11-02_audit_log.sql
--   2026-11-01  requisitions (schedule type RQ): schedule.requester / requesterUser → sql_updates/2026-11-01_requisitions.sql
--   2026-10-31  pallet / tote master (huMaster.ptype, note), history.hu/pallet/tote → sql_updates/2026-10-31_pallet_tote_master.sql
--   2026-10-31  totes & pallets table "huMaster", stock."hu" → sql_updates/2026-10-31_handling_units.sql
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
  "hu" text generated always as (data->>'hu') stored,             -- the tote / pallet it sits in (added 2026-10-31)
  data    jsonb,
  primary key (company, rk)
);
create index if not exists "stock_company" on public."stock" (company);
alter table public."stock" add column if not exists "hu" text generated always as (data->>'hu') stored;   -- older databases

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
  "hu" text generated always as (data->>'hu') stored,             -- the container it was in (added 2026-10-31)
  "pallet" text generated always as (data->>'pallet') stored,
  "tote" text generated always as (data->>'tote') stored,
  data    jsonb,
  primary key (company, rk)
);
alter table public."history" add column if not exists "hu"     text generated always as (data->>'hu') stored;       -- older databases
alter table public."history" add column if not exists "pallet" text generated always as (data->>'pallet') stored;
alter table public."history" add column if not exists "tote"   text generated always as (data->>'tote') stored;
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
  "requester"     text generated always as (data->>'requester') stored,       -- requisitions (type RQ): who asked
  "requesterUser" text generated always as (data->>'requesterUser') stored,
  data    jsonb,
  primary key (company, rk)
);
alter table public."schedule" add column if not exists "requester"     text generated always as (data->>'requester') stored;      -- older databases
alter table public."schedule" add column if not exists "requesterUser" text generated always as (data->>'requesterUser') stored;
create index if not exists "schedule_company" on public."schedule" (company);
create index if not exists "schedule_type" on public."schedule" (company, "type");

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

-- Pallet master AND tote master: one row per pallet / tote, told apart by "type". Their type lists (palletTypes / toteTypes)
-- and the levels in use (huCtl) are company settings (settings.data), not tables.
create table if not exists public."huMaster" (            -- totes & pallets: item → tote → pallet → location (added 2026-10-31)
  company  text not null,
  rk       text not null,                                          -- the tote / pallet code
  "code"   text generated always as (data->>'code') stored,
  "type"   text generated always as (data->>'type') stored,        -- tote | pallet
  "parent" text generated always as (data->>'parent') stored,      -- the pallet a tote sits on
  "loc"    text generated always as (data->>'loc') stored,         -- the location (pallets, totes not on a pallet)
  "ptype"  text generated always as (data->>'ptype') stored,       -- pallet / tote type name
  "note"   text generated always as (data->>'note') stored,
  data     jsonb,
  primary key (company, rk)
);
alter table public."huMaster" add column if not exists "ptype" text generated always as (data->>'ptype') stored;   -- older databases
alter table public."huMaster" add column if not exists "note"  text generated always as (data->>'note') stored;
create index if not exists "huMaster_company" on public."huMaster" (company);
create index if not exists "huMaster_type"    on public."huMaster" (company, "type");
create index if not exists "huMaster_parent"  on public."huMaster" (company, "parent");
create index if not exists "huMaster_loc"     on public."huMaster" (company, "loc");

create table if not exists public."labelLog" (            -- label history: which numbers were made, reprints (added 2026-11-05)
  company  text not null,
  rk       text not null,
  "id"     text generated always as (data->>'id') stored,
  "date"   text generated always as (data->>'date') stored,
  "kind"   text generated always as (data->>'kind') stored,        -- item | stock | sched | loc
  "code"   text generated always as (data->>'code') stored,
  data     jsonb,
  primary key (company, rk)
);
create index if not exists "labelLog_company" on public."labelLog" (company);
create index if not exists "labelLog_code"    on public."labelLog" (company, "code");

-- ---- User accounts: one row per user (replaces the old kv 'users' JSON) ----
-- Secrets are ONLY bcrypt/argon hashes from PHP password_hash(); never plaintext.
create table if not exists public."rentals" (            -- rental jobs: plan, per-line step quantities, step log (added 2026-11-12)
  company    text not null,
  rk         text not null,
  "id"       text generated always as (data->>'id') stored,
  "no"       text generated always as (data->>'no') stored,
  "customer" text generated always as (data->>'customer') stored,
  "status"   text generated always as (data->>'status') stored,
  "parentId" text generated always as (data->>'parentId') stored,
  data       jsonb,
  primary key (company, rk)
);
create index if not exists "rentals_company" on public."rentals" (company);
create index if not exists "rentals_status"  on public."rentals" (company, "status");
create index if not exists "rentals_parent"  on public."rentals" (company, "parentId");

create table if not exists public."addonRecords" (       -- add-on records: ISO NCR/CAPA, documents, change log; ERP batch log (added 2026-11-13)
  company text not null,
  rk      text not null,
  "id"    text generated always as (data->>'id') stored,
  "kind"  text generated always as (data->>'kind') stored,
  "date"  text generated always as (data->>'date') stored,
  data    jsonb,
  primary key (company, rk)
);
create index if not exists "addonRecords_company" on public."addonRecords" (company);
create index if not exists "addonRecords_kind"    on public."addonRecords" (company, "kind");

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
    'sales','shifts','promotions','giftcards','purchaseOrders','salesOrders','mpOrders','withdrawals','huMaster','labelLog','rentals','addonRecords']
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


-- ---- Data log: who changed what, before / after (added 2026-11-02) ----
create table if not exists public.audit_log (
  id         bigint generated always as identity primary key,
  at         timestamptz not null default now(),
  company    text,
  actor      text,                  -- username; "db:<role>" = changed directly in the database, not through the app
  source     text,                  -- page + action, e.g. "mobile · savebatch"; "direct" = not through the app
  ip         text,
  req_id     text,                  -- rows written by the same request share it
  op         text not null,         -- INSERT | UPDATE | DELETE | LOGIN | LOGIN_FAIL | MANAGER_APPROVE | MANAGER_APPROVE_FAIL | IMAGE_UPLOAD | IMAGE_DELETE
  table_name text,
  row_key    text,
  changes    jsonb                  -- {"old":{…},"new":{…}}: an UPDATE keeps only the fields that changed
);
create index if not exists "audit_log_company_at" on public.audit_log (company, at desc);
create index if not exists "audit_log_actor_at"   on public.audit_log (actor, at desc);
create index if not exists "audit_log_row"        on public.audit_log (table_name, row_key, at desc);
alter table public.audit_log enable row level security;
revoke all on public.audit_log from anon, authenticated;

-- a very large value (a picture, a whole warehouse layout) is noted by size instead of being copied
create or replace function public.wms_audit_trim(v jsonb)
returns jsonb language plpgsql immutable set search_path = pg_catalog as $$
declare k text; x jsonb; o jsonb := '{}'::jsonb; big constant int := 8000;
begin
  if v is null then return null; end if;
  if jsonb_typeof(v) <> 'object' then
    if octet_length(v::text) > big then return jsonb_build_object('_omitted', true, 'bytes', octet_length(v::text)); end if;
    return v;
  end if;
  for k, x in select * from jsonb_each(v) loop
    if octet_length(x::text) > big then o := o || jsonb_build_object(k, jsonb_build_object('_omitted', true, 'bytes', octet_length(x::text)));
    else o := o || jsonb_build_object(k, x); end if;
  end loop;
  return o;
end $$;

-- one row per change. A logging problem never blocks the save itself: it is raised as a WARNING in the database log.
create or replace function public.wms_audit_row()
returns trigger language plpgsql security definer set search_path = public, pg_catalog as $$
declare
  j_old jsonb; j_new jsonb; ref jsonb; d_old jsonb; d_new jsonb; o jsonb := '{}'::jsonb; n jsonb := '{}'::jsonb;
  ch jsonb; k text; nm text; hdr text; b text; a jsonb;
begin
  begin
    if TG_OP <> 'INSERT' then j_old := to_jsonb(OLD); end if;
    if TG_OP <> 'DELETE' then j_new := to_jsonb(NEW); end if;
    ref := coalesce(j_new, j_old);
    if TG_TABLE_NAME = 'kv' then
      nm := ref->>'name';
      if nm = 'seq' or left(nm, 11) = 'mp_pending:' then return null; end if;                 -- running counters / short-lived OAuth state
      if TG_OP = 'UPDATE' and (j_old->'data') is not distinct from (j_new->'data') then return null; end if;
      if nm in ('companies', 'billing') then d_old := j_old->'data'; d_new := j_new->'data';
      else                                                                                    -- may hold marketplace tokens / old credentials: never copied
        if j_old is not null then d_old := '{"_redacted":"before"}'::jsonb; end if;
        if j_new is not null then d_new := '{"_redacted":"after"}'::jsonb; end if;
      end if;
    elsif TG_TABLE_NAME = 'userMaster' then                                                   -- password / PIN hashes never reach the log
      if j_old is not null then d_old := j_old - 'pass_hash' - 'pin_hash' - 'updated_at' - 'created_at'; end if;
      if j_new is not null then d_new := j_new - 'pass_hash' - 'pin_hash' - 'updated_at' - 'created_at'; end if;
      if TG_OP = 'UPDATE' then
        if (j_old->>'pass_hash') is distinct from (j_new->>'pass_hash') then d_new := d_new || '{"passwordChanged":true}'::jsonb; end if;
        if (j_old->>'pin_hash')  is distinct from (j_new->>'pin_hash')  then d_new := d_new || '{"pinChanged":true}'::jsonb; end if;
      end if;
    else
      d_old := j_old->'data'; d_new := j_new->'data';
    end if;

    if TG_OP = 'UPDATE' then
      if d_old is not distinct from d_new then return null; end if;                           -- saved again unchanged
      if jsonb_typeof(d_old) = 'object' and jsonb_typeof(d_new) = 'object' then
        for k in select jsonb_object_keys(d_old) union select jsonb_object_keys(d_new) loop
          if (d_old->k) is distinct from (d_new->k) then
            if d_old ? k then o := o || jsonb_build_object(k, d_old->k); end if;
            if d_new ? k then n := n || jsonb_build_object(k, d_new->k); end if;
          end if;
        end loop;
      else
        o := jsonb_build_object('_value', d_old); n := jsonb_build_object('_value', d_new);
      end if;
      ch := jsonb_build_object('old', public.wms_audit_trim(o), 'new', public.wms_audit_trim(n));
    elsif TG_OP = 'INSERT' then ch := jsonb_build_object('new', public.wms_audit_trim(d_new));
    else ch := jsonb_build_object('old', public.wms_audit_trim(d_old));
    end if;

    -- who: api_supabase.php sends it on every write; PostgREST exposes the request headers here
    begin
      hdr := current_setting('request.headers', true);
      if coalesce(hdr, '') <> '' then
        b := hdr::jsonb->>'x-wms-actor';
        if coalesce(b, '') <> '' then a := convert_from(decode(b, 'base64'), 'UTF8')::jsonb; end if;
      end if;
    exception when others then a := null;                                                     -- unreadable: the row is still kept, without a name
    end;
    insert into public.audit_log (company, actor, source, ip, req_id, op, table_name, row_key, changes)
    values (ref->>'company', coalesce(a->>'u', 'db:' || session_user), coalesce(a->>'src', 'direct'), a->>'ip', a->>'req',
            TG_OP, TG_TABLE_NAME, coalesce(ref->>'rk', ref->>'username', ref->>'name', ref->>'company'), ch);
  exception when others then
    raise warning 'wms_audit_row %.%: %', TG_TABLE_NAME, TG_OP, sqlerrm;
  end;
  return null;
end $$;
revoke all on function public.wms_audit_row() from public, anon, authenticated;
revoke all on function public.wms_audit_trim(jsonb) from public, anon, authenticated;

-- every table the app writes. A table added later must be added to this list too.
do $$
declare t text;
begin
  foreach t in array array['kv','settings','userMaster','itemMaster','stock','locationMaster','labelMaster','history','schedule',
    'bookings','logisticMaster','customerMaster','supplierMaster','ngMaster','bomMaster','stocktake','returns',
    'sales','shifts','promotions','giftcards','purchaseOrders','salesOrders','mpOrders','withdrawals','huMaster','labelLog','rentals','addonRecords']
  loop
    if to_regclass(format('public.%I', t)) is not null then
      execute format('drop trigger if exists wms_audit on public.%I', t);
      execute format('create trigger wms_audit after insert or update or delete on public.%I for each row execute function public.wms_audit_row()', t);
    end if;
  end loop;
end $$;

-- the log is append-only: rows can't be edited or removed, except through wms_audit_purge below
create or replace function public.wms_audit_guard()
returns trigger language plpgsql set search_path = pg_catalog as $$
begin
  if current_setting('wms.audit_purge', true) is distinct from 'on' then
    raise exception 'audit_log is append-only';
  end if;
  return old;
end $$;
drop trigger if exists wms_audit_lock on public.audit_log;
create trigger wms_audit_lock before update or delete on public.audit_log for each row execute function public.wms_audit_guard();
drop trigger if exists wms_audit_lock_truncate on public.audit_log;
create trigger wms_audit_lock_truncate before truncate on public.audit_log for each statement execute function public.wms_audit_guard();
revoke all on function public.wms_audit_guard() from public, anon, authenticated;

-- nothing is removed automatically. To trim old rows by hand (at least 90 days are always kept):
--   select public.wms_audit_purge(730);
create or replace function public.wms_audit_purge(p_days int)
returns bigint language plpgsql set search_path = public, pg_catalog as $$
declare gone bigint;
begin
  if p_days is null or p_days < 90 then raise exception 'keep at least 90 days'; end if;
  perform set_config('wms.audit_purge', 'on', true);
  delete from public.audit_log where at < now() - make_interval(days => p_days);
  get diagnostics gone = row_count;
  perform set_config('wms.audit_purge', 'off', true);
  return gone;
end $$;
revoke all on function public.wms_audit_purge(int) from public, anon, authenticated;

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
--   latest data log entries:
--     select at, actor, source, op, table_name, row_key, changes from public.audit_log order by id desc limit 20;
