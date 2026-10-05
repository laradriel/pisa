# Context for task planning: Pisa PIM

You are planning the implementation backlog for **Pisa**, a self-hosted Product Information Management (PIM) system built with **Laravel 11, Filament 3, Livewire 3, PHP 8.3, MySQL 8**. The repo is `/home/hussam/Code/Laravel/Lyon`, branch `main`. The `README.md` was just rewritten to describe the **target state** as if the product were fully implemented. Your job is to turn the gap between the current code and that README into concrete, well-sized tasks (suitable as GitHub issues with acceptance criteria), grouped into epics, ordered by dependency.

Read `README.md` first. It is the specification. Everything below tells you what already exists, what is missing, what decisions were taken, and what constraints apply.

---

## 1. What exists today (do not re-plan this, but tasks may need to extend it)

**Infrastructure**
- Laravel Sail via `docker-compose.yml` (service `pim`, MySQL 8), Traefik labels for `https://pim.docker.localhost`, external docker network `proxy`, `traefik/certs` folder.
- `Makefile` wraps Sail: `up`, `down`, `shell`, `migrate*`, `seed`, `test`, `pint`, `phpstan`, `npm-*`, `queue-work`, `cache-clear`, `init`.
- `phpstan.neon` at **level 7** with Larastan. Laravel Pint. PHPUnit 10. IDE helper.
- `php artisan app:setup` (`App\Console\Commands\SetupCommand`) dispatches `CreatePermissionsJob`, `CreateRolesJob`, `CreateAdminJob`. Roles and admin are read from `config('setup.roles')` and `config('setup.admin')`, **but `config/setup.php` is not committed**.

**Three Filament panels**, one login, user menu links between them:
- `PimPanelProvider` → `/pim`, default panel, amber, top navigation. Resources in `app/Filament/Pim/Resources`.
- `SettingsPanelProvider` → `/settings`, red, redirects login to PIM login. Resources in `app/Filament/Settings/Resources`.
- `AdminPanelProvider` → `/admin`. Resources: `UserResource`, `RoleResource`, `PermissionResource`.
- `User::canAccessPanel()` delegates to `canAccessPimPanel()`, `canAccessSettingsPanel()`, `canAccessAdminPanel()`.

**Auth model (custom, not Spatie, not Shield)**
- Tables: `permissions(key)`, `roles(key, name, description, soft deletes)`, pivots `roles_users`, `roles_permissions`, `users_permissions`.
- `App\Enums\PermissionEnum` currently only has admin user/role/permission keys (`admin_user_view` … `admin_permission_view`). No PIM or Settings permissions exist yet.
- `App\Concerns\HasPermission` trait: `hasPermission()`, `hasAnyPermission()` across roles and direct permissions.
- Policies exist for User, Role, Permission, Family, Product, ProductAttribute(Value), AttributeOption.

**Domain model (EAV)**
- `families(id, name, timestamps)`
- `attributes(id, name char144, code char144 unique, description, type, format, settings json)` — no timestamps. `settings` holds `{is_distributable: bool, is_territorial: bool}` via `AttributeSettings` value object + `AttributeSettingsCast`. In the UI these are labelled "Value per Channel" and "Value per Territory".
- `family_attributes(family_id, attribute_id, order)` pivot, unique per pair. **No `required` column yet.**
- `attribute_options(id, attribute_id, value, order, timestamps, soft deletes)` with `AttributeOptionObserver`.
- `products(id, sku char64, family_id, name, timestamps)` — **SKU has no unique index, no status column, no parent_id**.
- `product_attribute_values(id, product_id, attribute_id, attribute_value json, timestamps)` — **no channel/territory/locale columns, no unique index**.
- Enums: `AttributeTypeEnum` (short_text, long_text, single_select, multi_select, checkbox, radio, toggle, date, datetime) with `allowedFormats()`; `AttributeFormatEnum` (text, integer, decimal, boolean, currency, measurement, date, time, datetime, percentage, number, color) with `setup(Field)`.
- `App\Mappings\FieldTypeMapping\*Mapping` classes implement `FormFieldMapping::mapAsComponent(Attribute): Field` (one per type). `App\Mappings\FieldFormatMapping\{Default,Integer,Currency}FieldSetup` decorate fields per format. `Attribute::toFilamentField()` dispatches on type.
- `App\Helpers\Mapper::mapProductAttributes(Product)` builds the dynamic attribute fields for the product edit form.
- `EditProduct` page injects dynamic fields named `attributes-{attributeId}-attribute_value`, fills them in `mutateFormDataBeforeFill`, and writes them with `ProductAttributeValue::updateOrInsert` in `mutateFormDataBeforeSave`. `CreateProduct` does **not** handle attribute values at all.
- `FamilyResource` has `AttributesRelationManager`; `AttributeResource` has `OptionsRelationManager`.
- Tests: only `tests/Integration/FamilyRelationTest.php` plus the two example tests.
- Routes: `routes/web.php` has only the welcome route. **No `routes/api.php`, no Sanctum installed, no API at all.**
- `lang/en/*` only; no locale handling for product data.

---

## 2. Known defects to fix first (each is its own small task)

1. `app/Filament/Pim/Resources/ProductResource.php:31` and `app/Filament/Settings/Resources/AttributeResource.php:32`: `->regex(\`[A-Z0-9-][A-Z0-9-]+\`)` uses PHP backticks = shell-exec operator. Must become a string regex like `'/^[A-Z0-9-]+$/'` and `'/^[a-z][a-z0-9-]*$/'`.
2. `app/Rules/ValidFieldInputCombination.php:37`: `validate()` calls itself recursively. Should use `passes()` and call `$fail()`. Also the rule is not actually wired into `AttributeResource` form validation (format select should be constrained by the chosen type).
3. `app/Enums/AttributeFormatEnum.php` `setup()`: matches `self::WEIGHT`, `LENGTH`, `AREA`, `VOLUME`, which do not exist (case is `MEASUREMENT`). Fatal at runtime.
4. `app/Models/Product.php` `valueForAttribute()`: filters `attributeValues` by `id` instead of `attribute_id`.
5. `Makefile` `init` target calls `shield:super-admin` and `shield:generate` but Filament Shield is not a dependency, and calls `db:seed --class=ProductionSeeder` which does not exist. Remove or implement.
6. `config/setup.php` missing → `app:setup` fails. Must define admin (from env `SETUP_ADMIN_NAME/EMAIL/PASSWORD`) and the four default roles.
7. `AttributeSettingsCast::get()` returns an array while the model docblock and the `settings()` accessor say `AttributeSettings`; there are two competing casts on the same attribute. Consolidate to one.
8. `Product::attributes()` BelongsToMany through `family_attributes` keyed on `family_id` is semantically odd; decide whether to keep it or route through `family->attributes`.

---

## 3. Product decisions already taken (stakeholder confirmed, do not reopen)

- **Translations** = attribute values per locale. A third attribute flag `is_localizable` next to `is_distributable` (channel) and `is_territorial` (territory). Flags combine freely.
- **Channel** = sales channel (web, print, b2b). **Territory** = market/country (DE, FR, US). **Locale** = language (de_DE, en_US) with a configurable fallback locale. All three are configurable entities in the Settings panel with `code`, `label`, `is_active` (+ `fallback_locale_id` for locales). Defaults per scope are configurable.
- **SKU is the public, unique, immutable key.** API routes use `/products/{sku}`. Internal ids never leave the system.
- **Product status**: `draft` | `published`. **Completeness** = % of required family attributes filled, computed per (channel, territory, locale) context and **stored** (recomputed by listener on change). Publishing is refused while any required attribute is empty in any active context.
- **Required attributes** are a `required` boolean on the `family_attributes` pivot.
- **Categories**: tree of arbitrary depth (code, translatable label, position), many-to-many with products. API filter by category including descendants (`filter[category]`) or exact (`filter[category_exact]`).
- **Media**: new attribute types `image` and `file` with formats `image` / `file`. Stored on a Laravel filesystem disk, public URLs in API, thumbnails in backoffice. Media values follow the same scoping rules as any attribute.
- **Variants**: family declares variant axes (subset of its attributes). Parent product + children with `parent_id`; children hold own SKU + axis values, inherit all other values unless overridden.
- **Associations**: typed product-to-product links; built-in types `related`, `upsell`, `cross_sell`, `replacement`; additional types configurable in Settings.
- **Import/Export**: CSV and XLSX from the PIM panel. Export uses current list filters; scoped attributes become columns like `description-web-de_DE`; options exported by code, media by URL. Import matches by SKU (update or create, family from `family` column), validates per row with the same rules as the form, runs on the queue, shows rejected rows with reasons. Per-family templates downloadable.
- **Roles** (shipped by `app:setup`): Administrator (all panels), Catalog Manager (PIM + Settings), Editor (PIM only), API Client (no panel access, tokens only). `PermissionEnum` must be extended with PIM and Settings permissions; every resource guarded by a policy; API abilities go through the same permission system.
- **API auth**: Laravel Sanctum personal access tokens, created per user in the Admin panel (`API Tokens` resource), abilities `products:read` and `products:write`. Rate limit 60 req/min per token, configurable.
- **API style**: `/api/v1`, JSON, query-string filters JSON:API-like. **No API for configuration** (families, attributes, options, scopes, categories, users are read-only or absent over HTTP). Exact contract, payload shapes, filter operators, sort fields, pagination (page + cursor, default 25, max 250), sparse fieldsets, error shapes and status codes are all specified in `README.md` → section "REST API". Treat that section as the contract to test against.
- **Third-party integrations, webhooks, value history, bulk edit, config transfer** are explicitly **out of scope** (roadmap). Do not create tasks for them.

---

## 4. Constraints and conventions for every task

- PHPStan level 7 must pass (`make phpstan`), Pint must pass (`make pint`), tests must pass (`make test`).
- Follow the existing extension pattern: a new attribute type = one enum case + one `FieldTypeMapping` class; a new format = one enum case + one `FieldFormatMapping` class + `allowedFormats()` update.
- New models get a policy, a factory, and docblocks compatible with IDE helper.
- Every API endpoint gets feature/contract tests covering auth, abilities, filtering, sorting, pagination, scoping and validation errors.
- Migrations must be additive; the project has no production data yet, so altering existing migrations is acceptable only in a dedicated "schema consolidation" task, otherwise add new migrations.
- Prefer Filament-native constructs (resources, relation managers, actions, import/export via `filament/actions` Importer/Exporter) over custom Livewire.
- Tasks should be small enough for one PR each, with a clear title, description, acceptance criteria, dependencies, and the epic they belong to. Mark which tasks can run in parallel.

---

## 5. Suggested epic structure (refine, split, reorder as needed)

1. **Stabilise** — the 8 defects in section 2, `config/setup.php`, seeders, a green baseline test run.
2. **Schema foundation** — unique immutable SKU, product `status`, `parent_id`, `family_attributes.required`, `is_localizable` flag, `channels` / `territories` / `locales` tables, scope columns + unique index on `product_attribute_values`, generated columns for numeric/date filtering, `categories` + pivot, `product_associations` + `association_types`, completeness table.
3. **Settings panel** — CRUD for channels, territories, locales (with fallback + defaults), association types, categories tree UI, `required` toggle and variant-axis selection in the family attributes relation manager, type/format combination validation, `image`/`file` attribute types.
4. **Product editing** — attribute values on create, context bar (channel/territory/locale) in the product form, scoped value storage via a `ScopeResolver`, status + publish guard, completeness listener and display, categories assignment, variants (parent/child UI, inheritance), associations relation manager, media upload fields, list filters/bulk actions.
5. **Permissions & roles** — extend `PermissionEnum` for PIM and Settings, four default roles, policies on every resource, panel access derived from permissions.
6. **REST API** — Sanctum install, API Tokens resource in Admin, `routes/api.php` v1, `ProductQueryBuilder` (filters, operators, sorts, pagination, sparse fieldsets), JSON resources, context resolution with locale fallback, `families`, `categories`, `context` read endpoints, product create/PATCH/PUT/DELETE with validation against family schema, variants endpoint, media upload endpoint, rate limiting, error format, contract tests.
7. **Import/Export** — exporter honoring list filters and scoped column naming, importer with SKU matching, per-row validation, queued execution with notification, per-family templates.
8. **Docs & DX** — keep README in sync, `make init` working end-to-end on a clean machine, demo seeder matching the README "first catalog" walkthrough, CI running pint/phpstan/tests.

Produce the task list now. For each task include: epic, title, description, acceptance criteria (testable), dependencies (task ids), parallelisable (yes/no), and a rough size (S/M/L).
