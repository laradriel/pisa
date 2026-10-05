# Pisa: Product Outline

> This document describes Pisa as a finished product. It covers what Pisa is, how its data model works, how people use it, and how other systems connect to it. Implementation details are in `README.md` and the ADRs in `docs/adr/`.

---

## 1. What Pisa is

Pisa is a self-hosted **Product Information Management (PIM)** system. It is the single source of truth for everything a company knows about its products: names, descriptions, technical specifications, prices, media, categorisation and relations between products.

Pisa does three things:

1. **Models the catalog.** Catalog managers decide what information each kind of product carries. They do this with **product families** and **custom attributes**, without a developer and without schema migrations.
2. **Enriches the catalog.** Editors fill in product data in a backoffice whose forms are generated from the family model. They write per language, per sales channel and per market, and they can see how complete each product is.
3. **Serves the catalog.** Shops, marketplaces, ERPs, print tools and apps read and write product data through a versioned **REST API**. The API understands the family model, so consumers can filter, sort and validate on custom attributes as if they were real columns.

Pisa ships without built-in connectors to third-party systems. It owns the data model and exposes it through a clean, predictable API. Connecting a specific shop or marketplace is the consumer's job, or the job of a thin connector built on the API.

### 1.1 Who uses it

| Persona             | Goal                                                                 | Where they work                  |
|---------------------|----------------------------------------------------------------------|----------------------------------|
| Catalog Manager     | Design the data model: families, attributes, options, scopes         | Settings panel                   |
| Editor              | Enrich, translate, categorise and publish products                   | PIM panel                        |
| Translator          | Fill in localisable values for their own locales                     | PIM panel (locale-restricted)    |
| Administrator       | Manage users, roles, permissions and API tokens                      | Admin panel                      |
| Integration / App   | Sync products into or out of Pisa                                    | REST API                         |

### 1.2 Guiding principles

- **The family is the contract.** Every product belongs to a family, and the family defines exactly which data the product carries. The backoffice, the importer and the API all follow the family.
- **Flexible model, strict values.** Catalog managers can add any attribute at runtime. Every value is still validated against the attribute's type and format.
- **One rulebook.** Validation, permissions, completeness and scope resolution each live in one place. The backoffice, imports and the API all go through them.
- **Configuration is curated, content is integrated.** Product content flows freely through the API. Changes to the shape of the catalog are deliberate and permissioned (see section 7).

---

## 2. Core concepts at a glance

```
                ┌──────────────────────────────┐
                │           Family             │  "shoes", "t-shirts", "laptops"
                │  ordered attribute list      │
                │  required flags, variant axes│
                └──────────────┬───────────────┘
                               │ 1 family : n attributes (pivot: order, required)
                               ▼
┌──────────────┐      ┌──────────────────────────────┐      ┌──────────────────┐
│ Attribute    │◄─────│   Attribute (custom field)   │─────►│ Attribute Option │
│ Group        │      │ code, type, format, scopes   │      │ code, labels,    │
└──────────────┘      └──────────────┬───────────────┘      │ position         │
                                     │                      └──────────────────┘
                                     │ 1 attribute : n values
                                     ▼
┌──────────────┐      ┌──────────────────────────────┐
│   Product    │─────►│ Product Attribute Value (EAV)│  one row per
│ SKU, family, │      │ product × attribute ×        │  product, attribute,
│ status       │      │ channel × territory × locale │  channel, territory, locale
└──────────────┘      └──────────────────────────────┘
```

| Concept               | One-line definition                                                                 |
|-----------------------|-------------------------------------------------------------------------------------|
| **Family**            | A product template: which attributes a product has, in which order, which are required |
| **Attribute**         | A custom field definition: code, labels, input type, data format, scoping flags      |
| **Attribute Option**  | A selectable value of a select-like attribute                                       |
| **Product**           | A SKU-identified item that belongs to one family and holds values for its attributes |
| **Value**             | One stored datum for one product, attribute and context (channel/territory/locale)  |
| **Channel**           | A sales or publication channel, e.g. `web`, `print`, `b2b`                           |
| **Territory**         | A market, e.g. `DE`, `FR`, `US`                                                      |
| **Locale**            | A language and region, e.g. `de_DE`, `en_US`, with an optional fallback locale     |
| **Category**          | A node in a hierarchical classification tree                                        |
| **Association**       | A typed link from one product to others (`related`, `upsell`, ...)                  |

---

## 3. Product Families: the central component

The family is the heart of Pisa. Everything else hangs off it: the product form, completeness, import templates, API validation and the API schema endpoint.

### 3.1 What a family defines

A family has:

- a **code** (unique, immutable, lowercase with dashes or underscores), for example `running-shoes`
- a **translatable label** and an optional description
- an **ordered list of attributes**. The order decides the field order in the product form, the column order in import and export templates, and the key order in API responses.
- a **required flag per attribute**, optionally narrowed to particular channels (for example, `print_description` is required only in the `print` channel)
- an **attribute used as label**, the attribute whose value names the product in lists and search results (defaults to `name`)
- an **attribute used as main image**, used for thumbnails in lists and the API summary
- optional **variant axes**, the attributes along which products of this family vary (for example `color` and `size`)
- optional **attribute groups for display**: tabs or sections that structure the product form, such as *Marketing*, *Technical*, *Logistics*, *Media*

A **required** attribute is not a property of the attribute itself. It belongs to the family–attribute relationship. `weight` can be required for `shipping-boxes` and optional for `gift-cards`, while remaining the same attribute with the same values.

### 3.2 Why families and not one big product table

Different products need different data. A laptop has `cpu`, `ram` and `screen_size`. A T-shirt has `fabric`, `fit` and `size`. A single wide table would be mostly empty and would need a developer for every new field. Fixed per-type tables would freeze the catalog structure in code.

Families let catalog managers describe each kind of product precisely, at runtime, while sharing attributes across families. `color` is defined once and reused by shoes, T-shirts and phone cases, so filtering "all red products" works across the whole catalog.

### 3.3 Family lifecycle

| Action                       | Effect                                                                                   |
|------------------------------|------------------------------------------------------------------------------------------|
| Create family                | Becomes available when creating products, importing and in the API schema               |
| Attach attribute             | The attribute appears in the form of every product in the family; completeness is recalculated |
| Detach attribute             | The attribute is hidden for products in the family. **Values are kept**, not deleted, so re-attaching restores them |
| Reorder attributes           | Form, export columns and API key order follow immediately                                |
| Mark/unmark required         | Completeness recalculates for all products in the family (queued for large families)     |
| Change product's family      | The form is re-mapped. Values for attributes outside the new family are kept but hidden  |
| Delete family                | Allowed only when no product belongs to it                                               |

Large changes (attaching a required attribute to a family with 200,000 products, say) run as queued jobs. The family shows a "recalculating completeness" banner until the job finishes.

### 3.4 Variants

A family can declare **variant axes**. Products in such a family can be **variant parents**: a parent holds the shared data (description, brand, materials) and each **variant child** has its own SKU plus values for the axis attributes (`color = red`, `size = 42`).

- Children **inherit** every non-axis value from the parent.
- A child can **override** an inherited value, such as a colour-specific packshot.
- The axis combination must be unique among siblings.
- Completeness is computed for each child using inherited plus own values.

### 3.5 The family as a published schema

Each family is exposed read-only at `GET /api/v1/families/{code}`, as a machine-readable schema: attributes, their types and formats, scoping flags, options, required flags and variant axes. Integrations use it to build mappings, generate validation and render forms. The family is the contract between Pisa and every system connected to it.

---

## 4. Custom Fields through EAV

### 4.1 Attributes

An **attribute** is a custom field definition. Catalog managers create attributes in the Settings panel. No developer, deployment or database migration is involved.

Every attribute has:

| Property          | Description                                                                                  | Mutable after creation? |
|-------------------|----------------------------------------------------------------------------------------------|-------------------------|
| `code`            | Globally unique identifier used in the API, imports and exports, e.g. `screen-size`          | No                      |
| `name`            | Translatable display label                                                                   | Yes                     |
| `description`     | Translatable help text shown under the field                                                 | Yes                     |
| `type`            | The input widget (see 4.2)                                                                   | No                      |
| `format`          | How the value is validated, stored, rendered and compared (see 4.2)                         | No                      |
| `localizable`     | One value per locale (translations, see section 5)                                           | No (once values exist)  |
| `per_channel`     | One value per channel                                                                        | No (once values exist)  |
| `per_territory`   | One value per territory                                                                      | No (once values exist)  |
| `settings`        | Format-specific settings, e.g. min/max, decimal places, unit family, allowed file extensions | Yes, within limits      |
| `group`           | Attribute group for organisation and permissions                                             | Yes                     |
| `filterable` / `sortable` | Whether the attribute is indexed for API filtering and sorting                      | Yes                     |

Code, type, format and scoping flags are locked because existing values depend on them. Changing `price` from `currency` to `text`, or turning a localizable `description` into a global one, would silently corrupt or orphan data. To change one of these, create a new attribute and migrate the values with an export and re-import.

### 4.2 Types and formats

Pisa separates **how a value is entered** (type) from **what the value is** (format). Only meaningful combinations are allowed.

| Type            | Widget                    | Allowed formats                                     |
|-----------------|---------------------------|-----------------------------------------------------|
| `short_text`    | Single-line text input    | text, integer, decimal, currency, color             |
| `long_text`     | Multi-line / rich text    | text                                                |
| `single_select` | Dropdown, one option      | text, boolean, integer                              |
| `multi_select`  | Dropdown, many options    | text, integer                                       |
| `checkbox`      | Checkbox list             | boolean                                             |
| `radio`         | Radio group               | boolean, text                                       |
| `toggle`        | On/off switch             | boolean, percentage                                 |
| `date`          | Date picker               | date                                                |
| `datetime`      | Date and time picker      | datetime                                            |
| `image`         | Image upload              | image                                               |
| `file`          | File upload               | file                                                |

Formats: `text`, `integer`, `decimal`, `number`, `boolean`, `currency`, `measurement`, `percentage`, `date`, `time`, `datetime`, `color`, `image`, `file`.

A format brings its own behaviour:

- **currency**: amount plus ISO currency code, two-decimal precision, currency prefix in the form
- **measurement**: value plus unit from a unit family (weight, length, volume, area), with conversion for filtering and sorting (`filter[attributes][weight][lt]=1kg` matches `800 g`)
- **integer / decimal / number / percentage**: numeric keyboard, step, min/max
- **color**: hex value with a colour picker and swatch in lists
- **image / file**: upload, storage on the configured disk, public URL in the API

### 4.3 Attribute options

Select-like attributes (`single_select`, `multi_select`, `checkbox`, `radio`) draw their choices from **attribute options**.

- Each option has an immutable **code** (`red`, `navy-blue`) and a **translatable label** ("Rot", "Red", "Rouge").
- Options are ordered and can be reordered by drag and drop.
- Options are **soft-deleted**: a removed option stays readable on existing products, cannot be chosen for new values, and is flagged in the product form.
- The API reads and writes options **by code**, never by label or internal id.

### 4.4 How EAV is stored

Pisa uses an **Entity–Attribute–Value** model with a strongly typed edge.

- **Configuration is relational.** Families, attributes, options, channels, territories and locales are ordinary tables with foreign keys. The family–attribute relationship is a pivot table carrying `order` and `required`.
- **Values are rows.** `product_attribute_values` holds one row per *product × attribute × channel × territory × locale*. Unused scope dimensions are `NULL`. A unique index on the tuple makes writes idempotent.
- **One value column fits all formats.** The value is stored as JSON, so currency (`{"amount":"129.00","currency":"EUR"}`), measurement (`{"value":280,"unit":"g"}`), multi-select (`["s","m"]`) and plain strings share a column.
- **Typed shadow columns for speed.** Generated or denormalised columns (`value_numeric`, `value_date`, `value_text`) are filled according to the format and indexed, so range filters, sorts and full-text search on custom attributes run on indexes rather than JSON scans.
- **Search index (optional).** For large catalogs, products can be mirrored to a search engine (Meilisearch or OpenSearch). The API's filter and sort syntax stays the same whichever backend answers it.

### 4.5 Generated forms

The product form is **built from the family**:

1. Load the product's family and its ordered attributes.
2. For each attribute, the **type mapping** chooses the form component (text input, select, toggle, ...).
3. The **format mapping** decorates it with input behaviour and validation (currency prefix, numeric step, min/max, ...).
4. Required flags from the family add required validation.
5. Scoping flags decide whether the field follows the context bar (channel/territory/locale) or stays global.

Adding a new input type to Pisa means adding one enum case and one mapping class. The form, the import template, the export layout and the API validation follow automatically.

---

## 5. Translatable Values

### 5.1 Localizable attributes

Translations in Pisa are not a separate subsystem. They are **attribute values scoped by locale**. An attribute marked **localizable** stores one value per active locale.

| Attribute      | Localizable | Stored values                                                |
|----------------|-------------|--------------------------------------------------------------|
| `name`         | yes         | `de_DE`: "Trailschuh", `en_US`: "Trail shoe", `fr_FR`: "Chaussure de trail" |
| `description`  | yes         | one long text per locale                                     |
| `ean`          | no          | one value for all languages                                  |
| `weight`       | no          | one value for all languages                                  |

Localization combines with the other scopes. A `description` that is localizable *and* per channel holds a value for every *(channel, locale)* pair, such as a short web description and a long print description in German and in English.

### 5.2 What else is translatable

Besides product values, labels throughout the configuration are translatable, so the backoffice and API consumers can show the catalog structure in any language:

- family labels and descriptions
- attribute names and help texts
- attribute option labels
- attribute group labels
- category labels
- channel, territory and association type labels

These **configuration labels** are not product data and are not scoped by channel or territory. They are a simple `locale → label` map.

### 5.3 Locales and fallbacks

- Locales are activated in Settings. Only active locales show up in forms, completeness and the API.
- Each locale may declare a **fallback locale** (`de_AT → de_DE`, `en_GB → en_US`). Fallbacks chain.
- When a value is requested for a locale and is empty, Pisa walks the fallback chain. If nothing is found it returns `null`. It never silently picks a random language.
- Channels can declare which locales they publish (`web` → `de_DE`, `en_US`; `print` → `de_DE` only). Completeness is only computed for those combinations.

The API reports fallbacks transparently. With `?explain=resolution` each resolved value states which locale (and channel/territory) it actually came from.

### 5.4 Translation workflow in the backoffice

- A **context bar** in the product form switches channel, territory and locale. Localizable fields re-render with the values for that locale. Global fields stay the same and are marked as "shared across languages".
- A **side-by-side view** shows a source locale next to the target locale for each localizable field.
- **Translation completeness** is shown per locale, so translators can work through a list like "products missing `fr_FR` values".
- Users can be **restricted to locales**: a French translator can edit `fr_FR` values but only read `de_DE`.
- **Export for translation**: export only localizable attributes for chosen locales as XLSX or XLIFF, then re-import the translated file. Only the target locale columns are written.
- **Machine translation (optional)**: a configured provider can prefill empty target-locale values. Prefilled values are marked "machine translated" until an editor confirms them.

### 5.5 Translations over the API

- **Reading** resolves one locale (`?locale=fr_FR`), several (`?locales=de_DE,fr_FR`), or returns all stored values (`?scopes=all`).
- **Writing** nests localizable values by locale. A `PATCH` that only includes `fr_FR` touches only French values and leaves every other locale alone. This lets translation agencies and services write their language safely.

---

## 6. Products

### 6.1 Anatomy of a product

| Field              | Notes                                                                                  |
|--------------------|----------------------------------------------------------------------------------------|
| `sku`              | Unique, immutable public key. Used in every API route, import and export. Internal ids are never exposed |
| `family`           | Exactly one. Decides which attributes the product has                                  |
| `status`           | `draft` or `published`. Only published products are visible to read-only API clients  |
| `enabled per channel` | Optional: publish to `web` but not yet to `print`                                  |
| `attributes`       | Values for the family's attributes, scoped as each attribute defines                   |
| `categories`       | Any number of category nodes                                                           |
| `parent`           | For variant children: the parent SKU                                                   |
| `associations`     | Typed links to other products (`related`, `upsell`, `cross_sell`, `replacement`, custom types) |
| `completeness`     | Percentage per channel × territory × locale, stored and kept up to date               |
| `created_at` / `updated_at` | Timestamps; `updated_at` drives incremental syncs                             |

### 6.2 Completeness

Completeness answers the question "is this product ready for this channel, market and language?"

- It is computed from the family's **required** attributes for each active *channel × territory × locale* context.
- It is **stored**, not computed on read, so lists and API filters on completeness stay fast.
- A product **cannot be published** while a required attribute is empty in a context it is published to.
- The PIM dashboard shows completeness per family, channel and locale, with drill-down lists of incomplete products and the missing attributes.

### 6.3 Categories

Categories form a tree of any depth. Each category has a code, a translatable label and a position among its siblings. Products can belong to many categories. Several trees can exist side by side, for example a *web navigation* tree and a *ERP classification* tree.

### 6.4 History and audit

Every change to a product records who changed which value, in which context, from what to what, and through which channel (backoffice, import, API token). The product page shows a timeline. Single values or whole versions can be restored.

---

## 7. The API: Pisa as a PIM backbone

The REST API is how Pisa serves as the PIM for the rest of the company. It is versioned (`/api/v1`), JSON-only, documented with OpenAPI, and covered by contract tests.

### 7.1 Design goals

1. **Consumers think in SKUs and codes.** Products are addressed by SKU, attributes, options, families and categories by code. Internal ids never leak.
2. **Custom attributes are first-class.** Filtering, sorting and sparse fieldsets work on any custom attribute just like built-in fields.
3. **The family schema is discoverable.** A consumer can learn the full data model at runtime.
4. **Scoped values are explicit.** Consumers choose between resolved values for one context, or every stored value.
5. **Writes are safe.** The same validation as the backoffice runs on every write, and partial updates only touch what was sent.
6. **Syncs are cheap.** Incremental reads, cursor pagination, bulk endpoints and webhooks make keeping another system in sync inexpensive.

### 7.2 Authentication and authorisation

- **Personal access tokens** (Laravel Sanctum) are issued in the Admin panel and held by users with the *API Client* role, so tokens are not tied to a person.
- Tokens carry **abilities** that narrow what the user's role allows:

| Ability             | Grants                                                                                 |
|---------------------|----------------------------------------------------------------------------------------|
| `products:read`     | Read published products, categories, families (schema) and contexts                   |
| `products:write`    | Create, update and delete products; read drafts                                        |
| `media:write`       | Upload and replace media on products                                                   |
| `catalog:write`     | **Opt-in.** Manage the catalog structure: attributes, options, families, categories (see 7.8) |

- Tokens can additionally be **restricted** to families, channels, locales or attribute groups. A translation agency token might be limited to writing `fr_FR` values of localizable attributes only.
- **Rate limits** are per token (60 requests/minute by default, configurable), with `X-RateLimit-*` headers.

### 7.3 Endpoints

| Method   | Path                                   | Ability           | Purpose                                              |
|----------|----------------------------------------|-------------------|------------------------------------------------------|
| `GET`    | `/products`                            | `products:read`   | List with filtering, sorting, pagination             |
| `GET`    | `/products/{sku}`                      | `products:read`   | One product                                          |
| `POST`   | `/products`                            | `products:write`  | Create a product                                     |
| `PATCH`  | `/products/{sku}`                      | `products:write`  | Partial update (merge)                               |
| `PUT`    | `/products/{sku}`                      | `products:write`  | Replace all attribute values                         |
| `DELETE` | `/products/{sku}`                      | `products:write`  | Delete a product                                     |
| `PATCH`  | `/products`                            | `products:write`  | **Bulk upsert** up to 100 products, NDJSON per-line results |
| `GET`    | `/products/{sku}/variants`             | `products:read`   | Variant children of a parent                         |
| `POST`   | `/products/{sku}/media/{attribute}`    | `media:write`     | Upload media for an image/file attribute             |
| `GET`    | `/families`, `/families/{code}`        | `products:read`   | Family schema                                        |
| `GET`    | `/attributes`, `/attributes/{code}`    | `products:read`   | Attribute definitions, including options             |
| `GET`    | `/categories`                          | `products:read`   | Category trees                                       |
| `GET`    | `/context`                             | `products:read`   | Active channels, territories, locales, defaults      |
| `*`      | `/catalog/...`                         | `catalog:write`   | Structure management (see 7.8)                       |

### 7.4 Reading products

**Resolved context.** The default response resolves each attribute to a single value for the requested context:

```http
GET /api/v1/products/SHOE-001?channel=web&territory=DE&locale=de_DE
```

```json
{
  "data": {
    "sku": "SHOE-001",
    "family": "shoes",
    "status": "published",
    "completeness": 100,
    "categories": ["footwear", "footwear/running"],
    "attributes": {
      "name": "Trailschuh",
      "color": "red",
      "size": ["42", "43"],
      "price": { "amount": "129.00", "currency": "EUR" },
      "description": "Leichter Trailschuh für lange Distanzen.",
      "weight": { "value": 280, "unit": "g" },
      "packshot": "https://pim.example.com/storage/products/SHOE-001/packshot.jpg"
    },
    "associations": { "upsell": ["SHOE-002"] },
    "updated_at": "2026-09-21T16:02:10+00:00"
  }
}
```

**All scopes.** `?scopes=all` returns every stored value, nested by **channel → territory → locale** and omitting dimensions an attribute does not use. Sync jobs and exports use this.

**Filtering** on built-in fields and custom attributes, evaluated in the requested context:

```http
GET /api/v1/products
  ?filter[family]=shoes
  &filter[attributes][color][in]=red,blue
  &filter[attributes][price][gte]=100
  &filter[category]=footwear/running
  &filter[updated_after]=2026-09-01T00:00:00Z
  &territory=DE&locale=de_DE
```

Operators: `eq`, `neq`, `in`, `nin`, `gt`, `gte`, `lt`, `lte`, `like`, `empty`, `notempty`. They are applied according to the attribute's format (numeric, date, measurement with unit conversion, text).

**Sorting**: `sort=-updated_at,attributes.price`, with comparisons that respect the format.

**Pagination**: page-based (`page[number]`, `page[size]` up to 250) or cursor-based (`page[cursor]`), which is the recommended mode for full syncs.

**Sparse fieldsets**: `fields=sku,attributes.name,attributes.price`.

**Labels**: `?with=labels` adds translated attribute and option labels for the requested locale, so a frontend can render `color: "red"` as "Farbe: Rot" without a second request.

### 7.5 Writing products

```http
POST /api/v1/products
```

```json
{
  "sku": "SHOE-003",
  "family": "shoes",
  "status": "draft",
  "categories": ["footwear/running"],
  "attributes": {
    "name": { "de_DE": "Trailschuh Lite", "en_US": "Trail Runner Lite" },
    "color": "blue",
    "price": {
      "DE": { "amount": "119.00", "currency": "EUR" },
      "US": { "amount": "129.00", "currency": "USD" }
    },
    "description": {
      "web": { "de_DE": "Leichter Trailschuh.", "en_US": "Lightweight trail shoe." }
    }
  }
}
```

Rules:

- Values are validated against the attribute's **type, format, settings and options**, and against the **family's** required attributes when publishing.
- Only attributes **attached to the product's family** are accepted. Unknown codes return `422` with the offending path.
- `PATCH` merges at the deepest level sent. Sending only `description.web.fr_FR` touches nothing else. `null` clears a value.
- `PUT` replaces the full attribute set.
- The SKU is immutable. The family can be changed, which keeps values for attributes outside the new family in storage.
- Errors are field-addressed: `"attributes.price.DE.amount": ["..."]`.
- `Idempotency-Key` headers make retries safe for `POST` and bulk requests.
- Optimistic locking with `If-Match` on an `ETag` prevents overwriting concurrent edits.

### 7.6 Events and webhooks

Pisa emits `product.created`, `product.updated`, `product.published`, `product.deleted` and `family.updated` events. Webhook subscriptions (managed in Admin) receive signed payloads with the SKU, the changed attribute codes and contexts, and the actor. Delivery uses exponential back-off and a dead-letter view. Consumers react to changes instead of polling.

### 7.7 Errors

| Status | When                                                           |
|--------|----------------------------------------------------------------|
| `401`  | Missing or invalid token                                       |
| `403`  | Token lacks the ability or is restricted from the resource     |
| `404`  | Unknown SKU, family, attribute, category or context code       |
| `409`  | SKU already exists, or `If-Match` precondition failed          |
| `422`  | Validation failed; see `errors`                                |
| `429`  | Rate limit exceeded                                            |

### 7.8 Custom fields over the API: read/write for products, controlled access to structure

This is the most important design decision in the API.

#### The question

Product **values** are clearly read/write over the API. That is the point of a PIM. But should integrations also be allowed to change the **custom fields themselves**, meaning creating attributes, adding options, attaching attributes to families or marking them required?

#### The case for keeping structure read-only

- **Integrity.** The family model is the contract every consumer relies on. An integration that renames an option, detaches an attribute or adds a required field changes the data for *every* other consumer and can drop completeness across thousands of products at once.
- **Accountability.** Structural decisions belong to catalog managers, who understand the effect on editors, completeness and downstream channels.
- **Simplicity.** A read-only schema is easy to cache and easy to reason about.

#### The case for allowing it

- **Onboarding and migration.** Moving from another PIM, a spreadsheet or an ERP means creating hundreds of attributes and thousands of options. Doing that by hand in a UI does not scale.
- **Options that follow master data.** Select options such as `brand`, `supplier` or `collection` are often owned by an ERP. New brands must appear in Pisa when the ERP creates them, or product imports fail.
- **Environment promotion.** Teams want to model in staging and promote the structure to production with a script, keeping it under version control.
- **Ecosystem.** Connectors for shops and marketplaces often need to create mapping attributes on installation.

#### How Akeneo does it

Akeneo PIM, the most widely used open-source PIM, **allows structure changes through its REST API**:

- Its API has create/update (`POST`, `PATCH`, and bulk `PATCH` with line-delimited JSON) endpoints for **attributes, attribute options, attribute groups, families, family variants, categories, association types, channels and measurement families**.
- Some reference data is **read-only** through the API. Locales and currencies can only be listed, because they are activated through channel configuration in the UI.
- Structure endpoints mostly **do not support `DELETE`**. Deletion is reserved for products and product models (and assets), so integrations can add and update structure but cannot destroy it.
- Certain properties are **immutable after creation**: an attribute's code and type, its localizable/scopable flags and a family variant's axes cannot be changed through the API (or the UI).
- Access is controlled by **role-based ACLs**. Each API connection is tied to a user and role, and the role's Web API permissions decide separately whether the connection may list or edit attributes, families, categories, channels and so on. A typical product-sync connection is granted product permissions only.
- Product values are sent as `values: { "<attribute_code>": [{ "locale": ..., "scope": ..., "data": ... }] }`, so values and structure are cleanly separated in the payload.

In short, Akeneo treats structure as **writable by default for trusted connections, with immutable core properties, no destructive deletes, and a per-connection permission model**.

#### Pisa's decision

Pisa follows the same idea with a stricter default. Structure is **read-only by default and writable on explicit opt-in**.

1. **Product values: always read/write** with `products:write`. This covers the vast majority of integrations.
2. **Structure: read-only for every standard token.** `GET /families`, `GET /attributes` and `GET /categories` expose the full schema.
3. **Structure: writable with `catalog:write`.** The ability is **off by default**, can only be granted by an Administrator, and is flagged in the token list. It unlocks:

| Resource                  | Create | Update                                      | Delete                                       |
|---------------------------|--------|---------------------------------------------|----------------------------------------------|
| Attribute                 | yes    | labels, description, settings, group, filterable/sortable | no                                |
| Attribute option          | yes    | labels, position                            | soft-delete only, if unused or forced        |
| Family                    | yes    | labels, attribute list, order, required flags, variant axes (while the family has no products) | only if the family has no products |
| Category                  | yes    | labels, parent, position                    | only if empty                                |
| Channel / territory / locale | no  | no                                          | no                                           |

4. **Guardrails on every structure write**, following Akeneo's protections and going further:
   - **Immutable core properties**: `code`, `type`, `format` and scoping flags cannot change through the API (or the UI, once values exist).
   - **No destructive deletes** of attributes or families with data.
   - **Impact preview**: `?dry_run=true` returns what would change, for example "attaching `energy_label` as required to `fridges` makes 4,812 products incomplete in `web/DE/de_DE`", without applying it.
   - **Narrow tokens**: a `catalog:write` token can be restricted to **options only** (`catalog:write:options`) or to specific attribute groups. This covers the most common real-world case, ERP-owned brand and supplier lists, without granting full schema control.
   - **Audit trail**: every structure change records the token, the actor and a before/after diff, visible to catalog managers.
   - **Notifications**: catalog managers are notified when structure changes through the API.
5. **Configuration as code**: `pisa:catalog:export` and `pisa:catalog:import` (Artisan and API) serialise the whole structure to YAML/JSON, so environments can be promoted and versioned without giving integrations day-to-day write access.

Most integrations get exactly what a PIM consumer needs: full read/write on product content and a stable, discoverable schema. The few legitimate cases for structural automation (migration, ERP-owned options, environment promotion) are covered deliberately, with an explicit, auditable and narrowly scoped permission.

---

## 8. Backoffice

Pisa runs three panels behind one login:

| Panel    | Path        | Audience                  | Content                                                              |
|----------|-------------|---------------------------|----------------------------------------------------------------------|
| PIM      | `/pim`      | Editors, translators      | Dashboard, products, categories, import/export, completeness reports |
| Settings | `/settings` | Catalog managers          | Families, attributes, options, attribute groups, channels, territories, locales, association types |
| Admin    | `/admin`    | Administrators            | Users, roles, permissions, API tokens, webhooks, audit log           |

Highlights:

- **Product grid** with columns, filters and saved views on any attribute, plus bulk actions (publish, assign category, change family, bulk edit values).
- **Product form** generated from the family, with a context bar, completeness indicator, inherited-value markers for variants and a history timeline.
- **Family configurator** with drag-and-drop attribute ordering, required toggles per channel, variant axis selection and an impact preview before saving.
- **Import/export** of CSV and XLSX, driven by the family layout, with downloadable templates per family, per-row validation reports and queued processing.

### 8.1 Roles and permissions

Permissions are fixed keys. Roles bundle them and are editable. Defaults:

| Role            | Panels               | Can                                                               |
|-----------------|----------------------|-------------------------------------------------------------------|
| Administrator   | PIM, Settings, Admin | Everything                                                        |
| Catalog Manager | PIM, Settings        | Model the catalog; manage products                                |
| Editor          | PIM                  | Create, edit, publish and delete products; import/export          |
| Translator      | PIM                  | Edit localizable values in assigned locales                       |
| API Client      | none                 | Hold API tokens only                                              |

The same policies guard the panels and the API, so a user's rights are identical in the UI and over HTTP.

---

## 9. Architecture summary

- **Stack**: Laravel, Filament for the backoffice, Sanctum for API tokens, a queue for imports, completeness recalculation and webhooks, and any Laravel filesystem disk (local or S3-compatible) for media.
- **EAV core** with relational configuration, JSON value rows and typed, indexed shadow columns.
- **Mappings** turn attribute type and format into form components and validation rules, in one place.
- **ScopeResolver** turns a requested context into candidate rows (exact match, then locale fallback, then unscoped) for the backoffice, exports and the API alike.
- **ProductQueryBuilder** translates API filter and sort syntax into SQL or search-engine queries.
- **Stored completeness**, recalculated by listeners and queued jobs.
- **Quality**: static analysis (PHPStan), formatting (Pint), and feature, integration and API contract tests.

---

## 10. Glossary

| Term             | Meaning                                                                                  |
|------------------|------------------------------------------------------------------------------------------|
| Attribute        | A custom field definition                                                                |
| Context          | A combination of channel, territory and locale                                           |
| EAV              | Entity–Attribute–Value; storing values as rows keyed by entity and attribute              |
| Family           | A product template defining attributes, order, required flags and variant axes           |
| Format           | What a value is (currency, date, measurement, ...): validation, storage, comparison      |
| Localizable      | An attribute that stores one value per locale                                            |
| Scope            | A dimension along which an attribute can vary: channel, territory, locale                |
| SKU              | Stock keeping unit; the public, immutable product identifier                             |
| Structure        | The catalog configuration: families, attributes, options, categories, contexts           |
| Type             | How a value is entered (input widget)                                                     |
| Variant axis     | An attribute whose value distinguishes variant children of one parent                    |
