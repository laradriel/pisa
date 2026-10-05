<p align="center"><strong>Pisa</strong></p>
<p align="center">A self-hosted Product Information Management system built with Laravel 11 and Filament 3.</p>

---

Pisa is the single source of truth for your product data. Catalog managers model the data they need with a **family configurator**, editors enrich **products** in a backoffice that adapts to that model, and downstream systems consume the result through a **read/write REST API** with filtering, sorting and pagination across custom attributes.

Pisa deliberately ships without third-party integrations. It owns the data model and exposes it over a clean API; connecting shops, marketplaces or DAMs is the job of the consuming system.

## Table of contents

- [Features](#features)
- [Concepts](#concepts)
  - [Families](#families)
  - [Attributes](#attributes)
  - [Scoping: channels, territories and locales](#scoping-channels-territories-and-locales)
  - [Products](#products)
  - [Categories](#categories)
  - [Media](#media)
- [Backoffice](#backoffice)
  - [Panels](#panels)
  - [Roles and permissions](#roles-and-permissions)
  - [Import and export](#import-and-export)
- [REST API](#rest-api)
  - [Authentication](#authentication)
  - [Endpoints](#endpoints)
  - [Reading products](#reading-products)
  - [Writing products](#writing-products)
  - [Errors](#errors)
  - [What the API does not do](#what-the-api-does-not-do)
- [Getting started](#getting-started)
- [Development](#development)
- [Architecture](#architecture)
- [Roadmap](#roadmap)
- [License](#license)

## Features

- **Family configurator.** Define product families, attach any number of custom attributes in a chosen order, mark attributes as required.
- **Rich attribute model.** Nine input types combined with twelve data formats, validated combinations, select options with ordering and soft deletes.
- **Scoped values.** Any attribute can hold a distinct value per sales channel, per territory (market) and per locale, in any combination.
- **Translations.** Attribute values marked as localizable are stored per locale. Editors switch locale in the product form; the API resolves the requested locale with fallback.
- **Products.** SKU-keyed products with a draft/published status, a computed completeness score, variants with variant axes and typed associations.
- **Categories.** A hierarchical category tree; products belong to many categories.
- **Media.** Image and file attributes with uploads in the backoffice and public URLs in the API.
- **Import and export.** CSV and XLSX in both directions, driven by the family configuration.
- **Three backoffice panels** with role-based access: PIM, Settings and Admin.
- **REST API** for reading, creating, updating and deleting products. Configuration is not writable over the API by design.
- **Quality gates.** PHPStan level 7 via Larastan, Laravel Pint, feature and integration tests.

## Concepts

### Families

A family is a product template. It defines which attributes a product of that family has, in what order they appear in the form and the API, and which of them are required for the product to count as complete.

Every product belongs to exactly one family. Changing a product's family re-maps the form; values for attributes that are not part of the new family are kept in storage but hidden until the attribute is attached again.

Families are managed in the **Settings** panel under *Family Configurations*. The *Attributes* relation manager on a family lets you attach, detach, reorder and mark attributes as required.

### Attributes

An attribute describes one piece of product information. It has a globally unique `code` (lowercase, dashes), a display `name`, an optional description, a **type** and a **format**.

The **type** decides which input widget the editor sees. The **format** decides how the value is validated, stored and rendered. Only meaningful combinations are allowed and the form rejects the rest.

| Type            | Widget                    | Allowed formats                              |
|-----------------|---------------------------|----------------------------------------------|
| `short_text`    | Single-line text input    | text, integer, decimal, currency, color      |
| `long_text`     | Multi-line textarea       | text                                         |
| `single_select` | Dropdown, one option      | text, boolean, integer                       |
| `multi_select`  | Dropdown, many options    | text, integer                                |
| `checkbox`      | Checkbox list             | boolean                                      |
| `radio`         | Radio group               | boolean, text                                |
| `toggle`        | On/off switch             | boolean, percentage                          |
| `date`          | Date picker               | date                                         |
| `datetime`      | Date and time picker      | datetime                                     |
| `image`         | Image upload with preview | image                                        |
| `file`          | File upload               | file                                         |

Formats: `text`, `integer`, `decimal`, `number`, `boolean`, `currency`, `measurement`, `percentage`, `date`, `time`, `datetime`, `color`, `image`, `file`. Formats such as `currency` and `integer` add their own input behaviour (prefix, step, numeric keyboard) and their own validation rules.

Select-like types (`single_select`, `multi_select`, `checkbox`, `radio`) take their choices from **attribute options**. Options are ordered, can be reordered by drag and drop, and are soft-deleted so historical product values keep resolving.

Attributes are managed in the **Settings** panel under *Attributes*.

### Scoping: channels, territories and locales

Real catalogs do not have one value per attribute. A product description differs between the web shop and the printed catalog, a price differs between Germany and the US, and a name differs between German and English. Pisa models this with three independent scopes on each attribute:

| Flag                 | Scope      | Example values          | Meaning                                                   |
|----------------------|------------|-------------------------|-----------------------------------------------------------|
| Value per channel    | channel    | `web`, `print`, `b2b`   | One value per sales channel                               |
| Value per territory  | territory  | `DE`, `FR`, `US`        | One value per market                                      |
| Localizable          | locale     | `de_DE`, `en_US`        | One value per language; this is how translations work     |

Flags combine. An attribute that is per channel *and* localizable holds one value for every channel and locale pair. An attribute with no flags holds exactly one value.

Channels, territories and locales themselves are configured in the **Settings** panel. Each has a code, a label and an active flag. Locales can declare a fallback locale; when a value is missing for the requested locale the API and the backoffice resolve the fallback chain before returning `null`.

In the product form the editor picks the current channel, territory and locale from a context bar. The form re-renders with the values for that context; unscoped attributes are shown once and stay the same regardless of the context.

### Products

A product is identified by its **SKU**. The SKU is unique, immutable after creation and is the public key used in API routes, imports and exports. The internal numeric id is never exposed.

Each product has:

- a `name` and a `family`
- a **status**: `draft` or `published`. Only published products are returned by the API unless a client with write access explicitly asks for drafts.
- a **completeness** percentage per channel, territory and locale, computed from the required attributes of the family. A product cannot be published while a required attribute is empty in any active context.
- **attribute values**, stored per attribute and per scope
- **categories**, any number
- optional **variants**: a product can be a *variant parent*. The family defines the *variant axes* (for example `color` and `size`). Each child product holds its own SKU and values for the axis attributes; every other attribute is inherited from the parent unless overridden.
- **associations**: typed links to other products. Built-in types are `related`, `upsell`, `cross_sell` and `replacement`; additional types can be added in Settings.

Products are managed in the **PIM** panel. The list view supports searching, filtering by family, status, category and any attribute, and bulk actions (publish, unpublish, assign category, delete).

### Categories

Categories form a tree of arbitrary depth. Each category has a code, a translatable label and a position among its siblings. Products can be assigned to many categories. The API exposes the tree and accepts category filters that include or exclude descendants.

### Media

Attributes with the `image` or `file` format accept uploads in the product form. Files are stored on the configured Laravel filesystem disk (`local` by default, any S3-compatible disk in production) and exposed through public URLs in the API. Image attributes render a thumbnail in the form and in the product list. Media follow the same scoping rules as any other attribute, so a packshot can differ per channel or territory.

## Backoffice

### Panels

Pisa runs three Filament panels behind a single login.

| Panel    | Path        | Audience                 | Content                                                                |
|----------|-------------|--------------------------|------------------------------------------------------------------------|
| PIM      | `/pim`      | Editors, catalog managers| Products, categories, import and export                                |
| Settings | `/settings` | Catalog managers         | Families, attributes, options, channels, territories, locales, association types |
| Admin    | `/admin`    | Administrators           | Users, roles, permissions, API tokens                                  |

The user menu links to every panel the current user may access. Unauthenticated visits to `/settings` or `/admin` redirect to the PIM login.

### Roles and permissions

Access is permission based. Permissions are fixed keys defined in `App\Enums\PermissionEnum` and created by the setup command. Roles bundle permissions and are editable in the Admin panel; individual permissions can also be granted directly to a user.

Four roles ship by default:

| Role            | Panels                | Can                                                                      |
|-----------------|-----------------------|--------------------------------------------------------------------------|
| Administrator   | PIM, Settings, Admin  | Everything, including users, roles and API tokens                        |
| Catalog Manager | PIM, Settings         | Configure families, attributes, scopes and categories; manage products   |
| Editor          | PIM                   | Create, edit, publish and delete products; import and export             |
| API Client      | none                  | Hold API tokens only. Cannot log in to any panel                         |

Every resource is guarded by a policy, so the same permission checks apply in the panels and in the API.

### Import and export

The PIM panel offers import and export of products as CSV or XLSX.

- **Export** takes the current list filters and writes one row per product. Scoped attributes become one column per context, for example `description-web-de_DE`. Options are exported by code, media by URL.
- **Import** reads the same layout. Rows are matched by SKU: existing products are updated, unknown SKUs are created in the family given by the `family` column. Validation runs per row with the same rules as the form; the result screen lists rejected rows with the reason. Imports run on the queue and notify the user when finished.

Templates for each family can be downloaded from the import screen.

## REST API

Base URL: `https://<host>/api/v1`. All responses are JSON.

### Authentication

The API uses Laravel Sanctum personal access tokens. Tokens are created per user in the Admin panel under *API Tokens* and carry abilities:

| Ability          | Grants                                              |
|------------------|-----------------------------------------------------|
| `products:read`  | Read published products, categories and the family schema |
| `products:write` | Create, update and delete products; read drafts     |

Send the token as a bearer token:

```http
GET /api/v1/products HTTP/1.1
Authorization: Bearer 1|xxxxxxxxxxxxxxxxxxxxxxxx
Accept: application/json
```

Tokens can be revoked at any time in the Admin panel. Use a user with the *API Client* role for machine integrations so the token is not tied to a person's account.

### Endpoints

| Method   | Path                                  | Ability          | Purpose                                            |
|----------|---------------------------------------|------------------|----------------------------------------------------|
| `GET`    | `/products`                           | `products:read`  | List products with filtering, sorting, pagination  |
| `GET`    | `/products/{sku}`                     | `products:read`  | Retrieve one product                               |
| `POST`   | `/products`                           | `products:write` | Create a product                                   |
| `PATCH`  | `/products/{sku}`                     | `products:write` | Partially update a product                         |
| `PUT`    | `/products/{sku}`                     | `products:write` | Replace all attribute values of a product          |
| `DELETE` | `/products/{sku}`                     | `products:write` | Delete a product                                   |
| `GET`    | `/products/{sku}/variants`            | `products:read`  | List the variant children of a parent              |
| `GET`    | `/categories`                         | `products:read`  | Category tree                                      |
| `GET`    | `/families`                           | `products:read`  | Families and their attribute schema (read only)    |
| `GET`    | `/families/{code}`                    | `products:read`  | One family with attributes, types, formats, options|
| `GET`    | `/context`                            | `products:read`  | Active channels, territories and locales           |

### Reading products

#### Resolving scoped values

Pass the context you want values resolved for. Missing parameters fall back to the defaults configured in Settings.

```http
GET /api/v1/products/SHOE-001?channel=web&territory=DE&locale=de_DE
```

```json
{
  "data": {
    "sku": "SHOE-001",
    "name": "Trail Runner",
    "family": "shoes",
    "status": "published",
    "completeness": 100,
    "parent": null,
    "categories": ["footwear", "footwear/running"],
    "attributes": {
      "color": "red",
      "size": ["42", "43"],
      "price": { "amount": "129.00", "currency": "EUR" },
      "description": "Leichter Trailschuh für lange Distanzen.",
      "weight": { "value": 280, "unit": "g" },
      "packshot": "https://pim.example.com/storage/products/SHOE-001/packshot.jpg"
    },
    "associations": {
      "upsell": ["SHOE-002"],
      "cross_sell": ["SOCK-010"]
    },
    "created_at": "2026-04-02T09:14:31+00:00",
    "updated_at": "2026-09-21T16:02:10+00:00"
  }
}
```

Add `?scopes=all` to receive every stored value keyed by context instead of a resolved value. This is what export tools and synchronisation jobs use.

#### Filtering

Filters are query-string parameters. Multiple filters are combined with AND.

| Parameter                                    | Meaning                                                   |
|----------------------------------------------|-----------------------------------------------------------|
| `filter[sku]=A,B,C`                          | Exact SKU match, comma-separated for many                 |
| `filter[family]=shoes`                       | Family code                                               |
| `filter[status]=draft`                       | `draft` or `published` (requires `products:write`)        |
| `filter[category]=footwear`                  | Products in the category or any descendant                |
| `filter[category_exact]=footwear`            | Products directly in the category                         |
| `filter[parent]=SHOE-001`                    | Variant children of a parent                              |
| `filter[updated_after]=2026-09-01T00:00:00Z` | Changed since a timestamp, for incremental syncs          |
| `filter[search]=trail`                       | Full-text search across name, SKU and text attributes     |
| `filter[attributes][<code>][<op>]=<value>`   | Custom attribute filter, repeatable for many attributes   |

Attribute operators:

| Operator   | Applies to                        | Example                                                  |
|------------|-----------------------------------|----------------------------------------------------------|
| `eq`       | any                               | `filter[attributes][color][eq]=red`                      |
| `neq`      | any                               | `filter[attributes][color][neq]=red`                     |
| `in`       | any                               | `filter[attributes][color][in]=red,blue`                 |
| `nin`      | any                               | `filter[attributes][size][nin]=36,37`                    |
| `gt` `gte` | integer, decimal, number, currency, percentage, measurement, date, datetime | `filter[attributes][price][gte]=100` |
| `lt` `lte` | same as above                     | `filter[attributes][price][lt]=200`                      |
| `like`     | text                              | `filter[attributes][description][like]=trail`            |
| `empty`    | any                               | `filter[attributes][packshot][empty]=true`               |
| `notempty` | any                               | `filter[attributes][packshot][notempty]=true`            |

Attribute filters are evaluated in the requested context, so `filter[attributes][price][gte]=100&territory=US` compares the US price.

```http
GET /api/v1/products
  ?filter[family]=shoes
  &filter[attributes][color][in]=red,blue
  &filter[attributes][price][gte]=100
  &filter[category]=footwear/running
  &channel=web&territory=DE&locale=de_DE
```

#### Sorting

`sort` takes a comma-separated list. Prefix a field with `-` for descending order. Built-in fields are `sku`, `name`, `family`, `status`, `completeness`, `created_at` and `updated_at`. Attributes are sorted with the `attributes.` prefix and are compared according to their format.

```http
GET /api/v1/products?sort=-updated_at,attributes.price
```

#### Pagination

Page-based by default, cursor-based on request.

```http
GET /api/v1/products?page[size]=50&page[number]=3
GET /api/v1/products?page[size]=50&page[cursor]=eyJpZCI6MTIz
```

`page[size]` defaults to 25 and is capped at 250. Responses carry `meta.total`, `meta.per_page`, `meta.current_page` and `meta.last_page` for page mode, `meta.next_cursor` for cursor mode, and matching `links`.

#### Sparse fieldsets

Limit the payload with `fields=sku,name,attributes.price,attributes.packshot`. Everything not listed is omitted.

### Writing products

#### Create

```http
POST /api/v1/products
Content-Type: application/json
```

```json
{
  "sku": "SHOE-003",
  "name": "Trail Runner Lite",
  "family": "shoes",
  "status": "draft",
  "categories": ["footwear/running"],
  "attributes": {
    "color": "blue",
    "size": ["41", "42"],
    "price": {
      "DE": { "amount": "119.00", "currency": "EUR" },
      "US": { "amount": "129.00", "currency": "USD" }
    },
    "description": {
      "web": {
        "de_DE": "Leichter Trailschuh.",
        "en_US": "Lightweight trail shoe."
      }
    }
  },
  "associations": {
    "related": ["SHOE-001"]
  }
}
```

Scoped values are nested by scope in the order **channel → territory → locale**, omitting scopes the attribute does not have. Unscoped attributes take a plain value. The `GET /families/{code}` endpoint tells clients which shape each attribute expects.

Values are validated against the attribute's type and format and the family's required attributes. Option values must match existing option codes. Publishing (`"status": "published"`) is refused while required attributes are missing.

The response is `201 Created` with the product resolved in the default context.

#### Update

`PATCH` merges: only the attributes and scopes present in the body are changed. Set an attribute to `null` to clear it. `PUT` replaces the entire attribute set; any attribute not in the body is cleared.

```http
PATCH /api/v1/products/SHOE-003
```

```json
{
  "status": "published",
  "attributes": {
    "price": { "DE": { "amount": "109.00", "currency": "EUR" } }
  }
}
```

The SKU cannot be changed. The family can be changed with `"family": "<code>"`; values for attributes that are not part of the new family are retained but no longer returned.

#### Variants

Create a variant parent by giving it a family that declares variant axes, then create children with `"parent": "<parent sku>"` and values for the axis attributes. Children inherit everything else; any attribute you set on a child overrides the inherited value.

#### Media

Image and file attributes accept either a `multipart/form-data` upload on `POST /products/{sku}/media/{attribute}` or a JSON body with a publicly reachable URL that Pisa downloads and stores.

#### Delete

`DELETE /api/v1/products/{sku}` removes the product, its values, media and associations. Deleting a variant parent deletes its children. The response is `204 No Content`.

### Errors

Errors follow one shape:

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "attributes.price.DE.amount": ["The amount must be a decimal with two places."],
    "attributes.color": ["The selected option does not exist."]
  }
}
```

| Status | When                                                        |
|--------|-------------------------------------------------------------|
| `401`  | Missing or invalid token                                    |
| `403`  | Token lacks the required ability                            |
| `404`  | Unknown SKU, family, category or context code               |
| `409`  | SKU already exists on create                                |
| `422`  | Validation failed; see `errors`                             |
| `429`  | Rate limit exceeded (60 requests per minute per token by default, configurable) |

### What the API does not do

By design the API cannot change configuration. There are no endpoints to create, update or delete families, attributes, options, channels, territories, locales, categories or users. Configuration is read-only over the API and is managed exclusively in the Settings and Admin panels, so the shape of the data cannot be altered by an integration.

## Getting started

### Requirements

- Docker with Docker Compose
- PHP 8.3 and Composer on the host for the initial bootstrap
- A running [Traefik](https://traefik.io) instance attached to an external Docker network named `proxy` (the compose file publishes the app as `https://pim.docker.localhost`). If you do not use Traefik, remove the `labels` and the `proxy` network from `docker-compose.yml` and expose port 80 directly.

### Bootstrap

```bash
git clone <repository-url> pisa
cd pisa
make init
```

`make init` copies `.env.example` to `.env`, installs Composer and npm dependencies, builds the Sail containers, generates the app key, runs the migrations, builds the frontend assets and finally runs `php artisan app:setup`, which creates the permissions, the default roles and the first administrator.

The administrator credentials are read from `.env`:

```dotenv
SETUP_ADMIN_NAME="Admin"
SETUP_ADMIN_EMAIL=admin@example.com
SETUP_ADMIN_PASSWORD=change-me
```

Then open:

| URL                                         | Panel    |
|---------------------------------------------|----------|
| `https://pim.docker.localhost/pim`          | PIM      |
| `https://pim.docker.localhost/settings`     | Settings |
| `https://pim.docker.localhost/admin`        | Admin    |

### First catalog in five minutes

1. In **Settings → Channels / Territories / Locales**, activate the contexts you need (for example channel `web`, territory `DE`, locale `de_DE`).
2. In **Settings → Attributes**, create attributes such as `color` (single select, text), `price` (short text, currency, per territory) and `description` (long text, text, localizable, per channel). Add options to `color`.
3. In **Settings → Family Configurations**, create a family `shoes`, attach the attributes, order them and mark `price` as required.
4. In **PIM → Products**, create a product with SKU `SHOE-001` in the `shoes` family and fill in the values for each context.
5. In **Admin → API Tokens**, create a token with `products:read` and call `GET /api/v1/products?channel=web&territory=DE&locale=de_DE`.

## Development

All day-to-day commands are wrapped in the `Makefile`; run `make help` for the full list.

| Command             | Does                                                    |
|---------------------|---------------------------------------------------------|
| `make up` / `down`  | Start or stop the Sail containers                       |
| `make shell`        | Shell into the app container                            |
| `make migrate`      | Run migrations                                          |
| `make migrate-fresh`| Drop everything and migrate again                       |
| `make seed`         | Seed demo data (families, attributes, products)         |
| `make test`         | Run the test suite                                      |
| `make pint`         | Format code with Laravel Pint                           |
| `make phpstan`      | Static analysis at level 7                              |
| `make npm-dev`      | Vite dev server with hot reload                         |
| `make queue-work`   | Process the queue (imports, media processing)           |
| `make cache-clear`  | Clear every Laravel and Filament cache                  |

### Tests

```bash
make test
```

Tests live in `tests/Feature` (HTTP and Filament behaviour, API contract), `tests/Integration` (model relations against a real database) and `tests/Unit`. Every API endpoint has contract tests for filtering, sorting, pagination, scoping and validation. The CI pipeline runs Pint, PHPStan and the test suite on every push.

### Project layout

```
app/
├── Casts/            Eloquent casts (attribute settings)
├── Concerns/         Shared model traits (HasPermission, HasAuthor, ...)
├── Enums/            AttributeTypeEnum, AttributeFormatEnum, PermissionEnum, ProductStatusEnum
├── Filament/
│   ├── Admin/        Users, roles, permissions, API tokens
│   ├── Pim/          Products, categories, import/export
│   └── Settings/     Families, attributes, options, channels, territories, locales
├── Http/
│   ├── Controllers/Api/V1/   Product, category, family and context controllers
│   ├── Requests/Api/         Form requests for create and update payloads
│   └── Resources/Api/        JSON resources
├── Jobs/             Setup jobs, import jobs, media processing
├── Mappings/
│   ├── FieldTypeMapping/     Attribute type → Filament form component
│   └── FieldFormatMapping/   Attribute format → input behaviour and validation
├── Models/           Family, Attribute, AttributeOption, Product, ProductAttributeValue, Category, Channel, Territory, Locale, ...
├── Policies/         One policy per model, used by panels and API alike
├── Queries/          ProductQueryBuilder: translates API filters and sorts to SQL
├── Rules/            Validation rules such as ValidFieldInputCombination
└── ValueObjects/     AttributeSettings and scoped value containers
```

## Architecture

Pisa is a **flexible EAV** (entity, attribute, value) system with a strongly typed edge.

- **Configuration is relational.** Families, attributes, options and scopes are ordinary tables with foreign keys. Family ↔ attribute is a pivot with `order` and `required`.
- **Values are rows, not columns.** `product_attribute_values` holds one row per product, attribute, channel, territory and locale, with the value stored as JSON so every format fits the same column. A unique index on that tuple keeps writes idempotent, and generated columns for numeric and date formats make range filters and sorts index-friendly.
- **The form is generated.** `Attribute::toFilamentField()` picks a `FieldTypeMapping` for the type and lets the `FieldFormatMapping` for the format decorate it. Adding an input type means adding one mapping class and one enum case; the product form, the import template and the API validation follow automatically.
- **Resolution is one place.** A `ScopeResolver` turns a requested context into an ordered list of candidate rows (exact match, then locale fallback, then unscoped) and is used by the API resources, the backoffice and the exporter alike.
- **Completeness is stored, not computed on read.** A listener recalculates the completeness of a product per active context after every change and stores it, so list filters and sorts on completeness stay cheap.
- **Panels share one auth model.** Filament policies and API abilities both go through the permission system, so a user's rights are identical in the UI and over HTTP.

## Roadmap

Out of scope for this release, planned afterwards:

- Outbound webhooks on product changes
- Attribute value history with diff and restore
- Connectors for shops, marketplaces and DAM systems
- Rule-based automatic categorisation and value defaults
- Bulk edit across products in the backoffice
- Configuration import/export between environments

## License

Pisa is proprietary software of [byte5](https://byte5.de). All rights reserved.
