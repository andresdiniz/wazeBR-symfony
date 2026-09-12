# Entity Relationships

**Repository:** `andresdiniz/wazeBR-symfony`  
**Last updated:** 2026-09-12  
**Scope:** Core domain entities for partners, users, API links, Waze alerts, Waze jams, and Waze TVT routes.

---

## Overview

The application is built around these main entities:

- `Partner`: represents an organization (e.g., city hall, traffic agency).
- `User`: represents system users, optionally scoped to a partner.
- `PartnerApiLink`: represents external API endpoints (alerts, traffic) owned by a partner.
- `WazeAlert`: normalized alert data fetched from Waze Partner API per partner.
- `WazeJam`: normalized traffic-jam data fetched from Waze Partner API per partner.
- `WazeTvtRoute`: normalized route/TVT data fetched from Waze feeds-tvt per partner.

All relationships are defined using Doctrine ORM annotations/attributes in PHP.

---

## Entity-Relationship Diagram (text)

```text
+------------------+
|      partner     |
+------------------+
| id               |
| name             |
| code             |
| slug             |
| email            |
| description      |
| bbox             |
| api_token        |
| active           |
| refresh_interval |
| created_at       |
| updated_at       |
+------------------+
       | 1
       |
       +------------------------------+------------------------------+------------------------------+------------------------------+
       |                              |                              |                              |                              |
       | N                            | N                            | N                            | N                            | N
       v                              v                              v                              v                              v
+----------------------+    +------------------------+    +------------------------+    +------------------------+    +------------------------+
|        user          |    |    partner_api_link    |    |      waze_alert        |    |       waze_jam         |    |    waze_tvt_route      |
+----------------------+    +------------------------+    +------------------------+    +------------------------+    +------------------------+
| id                   |    | id                     |    | id                     |    | id                     |    | id                     |
| partner_id (FK,NULL) |    | partner_id (FK)        |    | partner_id (FK)        |    | partner_id (FK)        |    | partner_id (FK)        |
| email                |    | type                   |    | uuid (unique)          |    | uuid (unique)          |    | route_id (unique/part) |
| password             |    | name                   |    | type, subtype          |    | level, length, delay   |    | name, fromName, toName |
| name                 |    | url                    |    | pub_millis             |    | speed, speed_kmh       |    | city, country          |
| phone                |    | active                 |    | location_x, location_y |    | street, city, country  |    | length, time           |
| active               |    | created_at             |    | street, city, country  |    | road_type, pub_millis  |    | historicTime, jamLevel |
| roles (JSON)         |    | updated_at             |    | road_type, ratings...  |    | turn_type              |    | routeType, wazersCount |
| created_at           |    +------------------------+    | created_at, updated_at |    | blocking_alert_uuid*   |    | bbox (minX/maxX/...)   |
| updated_at           |                                  +------------------------+    | line (JSON)            |    | line (JSON)            |
| last_login_at        |                                                                    | created_at, updated_at |    | subRoutes (JSON)       |
+----------------------+                                                                    +------------------------+    | created_at, updated_at |
                                                                                                      |                    +------------------------+
                                                                                                      | optional logical reference
                                                                                                      v
                                                                                         waze_alert.uuid
```

`* blocking_alert_uuid` is an optional value received from Waze. It may match `waze_alert.uuid`, but it is not a physical database foreign key at this stage.

### Relationships

- `User.partner` → `Partner.id`  
  - Type: **Many-to-One** (multiple users can belong to one partner).  
  - Currently: **nullable** (`JoinColumn(nullable: true)`).  
  - Inverse side: `Partner.users` (Collection).  
  - **Business rule:** Global admins (`ROLE_ADMIN` without partner) MAY have `partner === null`. Users with `ROLE_PARTNER_ADMIN` or `ROLE_OPERATOR` MUST have a partner. Enforced in `setPartner()`, `setRoles()`, and `addRole()`.  

- `PartnerApiLink.partner` → `Partner.id`  
  - Type: **Many-to-One** (multiple API links can belong to one partner).  
  - Currently: **non-nullable in practice** (every link must have a partner).  
  - Inverse side: `Partner.apiLinks` (Collection).  

- `WazeAlert.partner` → `Partner.id`  
  - Type: **Many-to-One** (multiple alerts belong to one partner).  
  - **Non-nullable**: every alert must have a partner.  
  - Alerts are fetched from `PartnerApiLink.url` (type = `alerts`) and stored normalized (no raw JSON column).  

- `WazeJam.partner` → `Partner.id`  
  - Type: **Many-to-One** (multiple traffic jams belong to one partner).  
  - **Non-nullable**: every jam must have a partner.  
  - Jams are fetched from `PartnerApiLink.url` (type = `traffic`) and stored normalized, except the route geometry stored as a JSON array in `line`.  

- `WazeJam.blockingAlertUuid` → `WazeAlert.uuid`  
  - Type: optional **logical reference** only; it is copied from the Waze response.  
  - No Doctrine association and no physical FK are defined yet, which avoids failures when the referenced alert is absent from a feed or has not been imported yet.  

- `WazeTvtRoute.partner` → `Partner.id`  
  - Type: **Many-to-One** (multiple TVT routes belong to one partner).  
  - **Non-nullable**: every route must have a partner.  
  - Routes are fetched from Waze feeds-tvt endpoints and stored normalized, with geometry (`line`) and `subRoutes` stored as JSON arrays.  
  - Uniqueness is enforced per partner via composite unique constraint on `(partner_id, route_id)`.  

There is no direct relationship between `User` and `PartnerApiLink`, `WazeAlert`, `WazeJam`, or `WazeTvtRoute`; all are associated independently to `Partner`.

---

## Entity Details

### `Partner`

**File:** `src/Entity/Partner.php`

**Main fields:**

- `id`, `name`, `code`, `slug`
- `email`, `description`, `bbox`
- `api_token`, `active`
- `refreshIntervalMinutes`
- `createdAt`, `updatedAt`

**Current collections:**

- `cemadenData`, `cities`, `links`, `users`, `alerts`
- `wazeCounts`, `routes`, `trafficJams`
- `wazeTvtRoutes`, `wazeFeeds`, `apiLinks`

Optional inverse collections may be added later:

- `wazeAlerts`: `Collection<WazeAlert>`
- `wazeJams`: `Collection<WazeJam>`
- `wazeTvtRoutes`: `Collection<WazeTvtRoute>`

---

### `User`

**File:** `src/Entity/User.php`

**Main fields:**

- `id`, `email`, `password`
- `name`, `phone`
- `roles` (JSON array)
- `active`
- `partner` (ManyToOne → `Partner`)
- `createdAt`, `updatedAt`, `lastLoginAt`

**Roles:**

- `ROLE_ADMIN`
- `ROLE_PARTNER_ADMIN`
- `ROLE_OPERATOR`
- `ROLE_VIEWER`

**Relationship:**

```php
#[ORM\ManyToOne(inversedBy: 'users')]
#[ORM\JoinColumn(nullable: true)]
private ?Partner $partner = null;
```

**Business rules (enforced in entity):**

- Global admins (`ROLE_ADMIN` with `partner === null`) are allowed.
- Users with `ROLE_PARTNER_ADMIN` or `ROLE_OPERATOR` must have a partner.
- `setPartner()` blocks removal of partner from a user with those roles.
- `setRoles()` and `addRole()` block assignment of those roles without a partner.

---

### `PartnerApiLink`

**File:** `src/Entity/PartnerApiLink.php`

**Main fields:**

- `id`
- `partner` (ManyToOne → `Partner`)
- `type` (`alerts` or `traffic`)
- `name`, `url`
- `active`
- `createdAt`, `updatedAt`

**Business rules:**

- `type` must be either `alerts` or `traffic`.
- Each API link belongs to one partner.
- Active links are the sources used by collection jobs.

---

### `WazeAlert`

**File:** `src/Entity/WazeAlert.php`

**Purpose:** Stores normalized alert data fetched from a partner's Waze API feed. No raw JSON column is stored.

**Main fields:**

- `id`, `partner` (ManyToOne → `Partner`, not null)
- `uuid` (unique Waze identifier)
- `type`, `subtype`, `pubMillis`
- `reportByMunicipalityUser`, `reportRating`, `confidence`, `reliability`
- `locationX` (longitude), `locationY` (latitude)
- `street`, `city`, `country`, `roadType`
- `nThumbsUp`, `magvar`
- `createdAt`, `updatedAt`

**Business rules:**

- Every alert must have a partner (`partner_id NOT NULL`).
- `uuid` is globally unique to prevent duplicate alert imports.
- Alert fields are stored in separate columns; no raw JSON payload is saved.

---

### `WazeJam`

**File:** `src/Entity/WazeJam.php`

**Purpose:** Stores normalized Waze traffic-jam data fetched from a partner's traffic feed. No raw JSON payload is stored.

**Main fields:**

- `id`, `partner` (ManyToOne → `Partner`, not null)
- `uuid` (unique Waze jam identifier)
- `level` (severity), `length` (meters), `delay` (seconds)
- `speed`, `speedKMH`
- `street`, `city`, `country`, `roadType`
- `pubMillis`, `turnType`
- `blockingAlertUuid` (optional Waze alert UUID reference)
- `line` (JSON geometry array of points `{x, y}`)
- `createdAt`, `updatedAt`

**Business rules:**

- Every jam must have a partner (`partner_id NOT NULL`).
- `uuid` is globally unique to prevent duplicate jam imports.
- The `line` field stores only the geometry array from the response; it is not a full raw payload.
- `blockingAlertUuid` is optional and remains a string reference, not a foreign key, at this stage.

---

### `WazeTvtRoute`

**File:** `src/Entity/WazeTvtRoute.php`

**Purpose:** Stores normalized route/TVT data fetched from Waze feeds-tvt per partner. No raw JSON payload is stored; only geometry (`line`) and `subRoutes` are kept as JSON arrays.

**Main fields:**

- `id`, `partner` (ManyToOne → `Partner`, not null)
- `routeId` (unique per partner via composite unique constraint on `partner_id, route_id`)
- `name`, `fromName`, `toName`
- `city`, `country`
- `length` (meters), `time` (seconds), `historicTime` (seconds)
- `jamLevel` (0–10), `routeType` (e.g. `STATIC`)
- `wazersCount` (integer)
- `bboxMinY`, `bboxMinX`, `bboxMaxY`, `bboxMaxX` (bounding box coordinates)
- `line` (JSON array of points `{x, y}`)
- `subRoutes` (JSON array of sub-route objects)
- `createdAt`, `updatedAt`

**Business rules:**

- Every route must have a partner (`partner_id NOT NULL`).
- `(partner_id, route_id)` is unique to prevent duplicate route imports for the same partner.
- Geometry (`line`) and `subRoutes` are stored as JSON; other fields are normalized into dedicated columns.
- Routes are expected to be inserted/updated by a background job or command that consumes Waze feeds-tvt URLs.

**Helper method:**

- `getLineLonLat(): array` – returns `[[lon, lat], ...]` for use with mapping libraries.

---

## Business Rules Summary

1. **User ↔ Partner**
   - A user can exist without a partner only if they are a global admin (`ROLE_ADMIN` without partner).
   - `ROLE_PARTNER_ADMIN` and `ROLE_OPERATOR` require a partner.

2. **PartnerApiLink ↔ Partner**
   - Every API link belongs to one partner.
   - `alerts` links feed `WazeAlert`; `traffic` links feed `WazeJam`.

3. **WazeAlert ↔ Partner**
   - Every alert is owned by one partner.
   - Data is normalized into dedicated columns, without raw JSON storage.

4. **WazeJam ↔ Partner**
   - Every jam is owned by one partner.
   - Data is normalized into dedicated columns; only its route line is stored in JSON.
   - A jam may carry an optional logical reference to an alert through `blockingAlertUuid`.

5. **WazeTvtRoute ↔ Partner**
   - Every TVT route is owned by one partner.
   - Data is normalized into dedicated columns; `line` and `subRoutes` are stored as JSON.
   - Routes are uniquely identified per partner by `(partner_id, route_id)`.

---

## Planned Evolutions

### Inverse collections on Partner

If navigation from `Partner` to imported data is needed, add:

```php
#[ORM\OneToMany(mappedBy: 'partner', targetEntity: WazeAlert::class)]
private Collection $wazeAlerts;

#[ORM\OneToMany(mappedBy: 'partner', targetEntity: WazeJam::class)]
private Collection $wazeJams;

#[ORM\OneToMany(mappedBy: 'partner', targetEntity: WazeTvtRoute::class)]
private Collection $wazeTvtRoutes;
```

Also add collection initialization and helper methods in `Partner.php`.

### Strong alert/jam link

If imports guarantee that a blocking alert always exists in the same partner, `blockingAlertUuid` can later become a nullable `ManyToOne` association to `WazeAlert`. Keep the UUID column or add a migration strategy before making that change.

### Sub-route entity (optional)

If you later need to query sub-routes individually, you can extract `subRoutes` into a separate `WazeTvtSubRoute` entity with `ManyToOne → WazeTvtRoute` and normalized fields (toName, historicTime, line, bbox, etc.).

---

## Change Log

- **2026-09-12** (WazeTvtRoute)
  - Added `WazeTvtRoute` entity for TVT route records from Waze feeds-tvt.
  - Added required `partner_id`, unique `routeId` per partner, names, city, country, length, times, jam level, type, wazers count, bbox, line (JSON), and subRoutes (JSON).
  - Stored geometry and sub-routes in JSON; did not add raw payload storage.
  - Enforced uniqueness via composite constraint `(partner_id, route_id)`.

- **2026-09-12** (WazeJam)
  - Added `WazeJam` entity for traffic-jam records from Waze feeds.
  - Added required `partner_id`, unique `uuid`, speed, severity, delay, address, publication, and geometry fields.
  - Stored route geometry in `line` JSON; did not add raw payload storage.
  - Documented `blockingAlertUuid` as an optional logical reference to `WazeAlert.uuid`.

- **2026-09-12** (WazeAlert)
  - Added `WazeAlert` entity with normalized fields (no raw JSON).
  - Each alert has `partner_id`, `uuid` (unique), type, subtype, location, and metadata.

- **2026-09-12** (User partner rule)
  - Strengthened `User` entity validation: global admin may have no partner; partner roles require one.

- **2026-09-12** (initial)
  - Initial documentation of `Partner`, `User`, and `PartnerApiLink` relationships.

---

## How to Update This File

When modifying entities:

1. Update the **Entity Details** section (fields, relationships, methods).
2. Adjust the **ER diagram** if new entities or relationships are added.
3. Record new or changed **business rules** in the corresponding section.
4. Add an entry in the **Change Log** with date and summary.
