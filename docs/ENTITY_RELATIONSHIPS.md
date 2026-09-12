# Entity Relationships

**Repository:** `andresdiniz/wazeBR-symfony`  
**Last updated:** 2026-09-12  
**Scope:** Core domain entities for partners, users, API links, Waze alerts, Waze jams, TVT routes, and TVT route history.

---

## Overview

The application is built around these main entities:

- `Partner`: represents an organization (e.g., city hall, traffic agency).
- `User`: represents system users, optionally scoped to a partner.
- `PartnerApiLink`: represents external API endpoints (alerts, traffic) owned by a partner.
- `WazeAlert`: normalized alert data fetched from Waze Partner API per partner.
- `WazeJam`: normalized traffic-jam data fetched from Waze Partner API per partner.
- `WazeTvtRoute`: master record with complete normalized route/TVT data fetched from Waze feeds-tvt per partner.
- `WazeTvtRouteSnapshot`: append-only, lightweight history of the changing TVT route metrics at each feed reading.

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

+--------------------------+
| waze_tvt_route_snapshot  |
+--------------------------+
| id                       |
| partner_id (FK)          |
| route_id (FK)            |-----> waze_tvt_route.id
| waze_route_id            |
| time                     |
| historic_time            |
| jam_level                |
| recorded_at              |
| created_at               |
+--------------------------+
         ^
         |
         +-- N snapshots per partner and per master TVT route
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

- `WazeTvtRouteSnapshot.partner` → `Partner.id`  
  - Type: **Many-to-One** (a partner has many historical route snapshots).  
  - **Non-nullable**: every snapshot is scoped to a partner.  

- `WazeTvtRouteSnapshot.route` → `WazeTvtRoute.id`  
  - Type: **Many-to-One** (a master route has many snapshots).  
  - **Non-nullable**: every snapshot belongs to one master TVT route.  
  - Each feed reading creates a new snapshot; snapshots are append-only and are never used to duplicate complete route metadata.  

There is no direct relationship between `User` and `PartnerApiLink`, `WazeAlert`, `WazeJam`, `WazeTvtRoute`, or `WazeTvtRouteSnapshot`; all are associated independently to `Partner`.

---

## Entity Details

### `WazeTvtRoute`

**File:** `src/Entity/WazeTvtRoute.php`

**Purpose:** Master record for complete normalized route/TVT data from Waze feeds-tvt per partner. It stores metadata and geometry that do not need duplication on every feed reading.

**Main fields:**

- `id`, `partner` (ManyToOne → `Partner`, not null)
- `routeId` (unique per partner via `(partner_id, route_id)`)
- `name`, `fromName`, `toName`, `city`, `country`
- `length` (meters), `routeType`, `wazersCount`
- `bboxMinY`, `bboxMinX`, `bboxMaxY`, `bboxMaxX`
- `line` (JSON geometry), `subRoutes` (JSON)
- `createdAt`, `updatedAt`

**Business rules:**

- One master route exists for each `(partner_id, route_id)` pair.
- Complete feed metadata and geometry are saved only when the route is created, or when a metadata change is intentionally detected.
- Historical travel metrics are stored separately in `WazeTvtRouteSnapshot`.

---

### `WazeTvtRouteSnapshot`

**File:** `src/Entity/WazeTvtRouteSnapshot.php`

**Purpose:** Append-only, lightweight history for TVT route readings. A record is inserted for every successful feed reading and retains only fields that change during the day.

**Main fields:**

- `id`
- `partner` (ManyToOne → `Partner`, not null; database column `partner_id`)
- `route` (ManyToOne → `WazeTvtRoute`, not null; database column `route_id`)
- `wazeRouteId` (Waze feed route ID; database column `waze_route_id`)
- `time` (seconds, current travel time)
- `historicTime` (seconds, historical reference travel time)
- `jamLevel` (integer, 0–10)
- `recordedAt` (timestamp of the feed reading)
- `createdAt` (timestamp of snapshot creation)

**Business rules:**

- Every snapshot must have a partner and a master route.
- Each successful feed reading inserts a new snapshot; snapshots are never overwritten.
- Snapshot data contains only `partner_id`, `route_id` (FK), `waze_route_id`, `time`, `historicTime`, `jamLevel`, `recordedAt`, and `createdAt`.
- `route_id` is the foreign key to `waze_tvt_route.id`; `waze_route_id` is the route identifier received from Waze. These are distinct columns.

**Indexes:**

- `(partner_id, route_id)`
- `(partner_id, recorded_at)`
- `(route_id, recorded_at)`

---

## Business Rules Summary

1. **User ↔ Partner**
   - A user can exist without a partner only if they are a global admin (`ROLE_ADMIN` without partner).
   - `ROLE_PARTNER_ADMIN` and `ROLE_OPERATOR` require a partner.

2. **PartnerApiLink ↔ Partner**
   - Every API link belongs to one partner.
   - `alerts` links feed `WazeAlert`; `traffic` links feed `WazeJam`.

3. **Waze TVT master routes**
   - Each master route belongs to one partner and is unique by `(partner_id, route_id)`.
   - Its descriptive data and geometry are not copied for every feed read.

4. **Waze TVT history**
   - Every successful feeds-tvt read creates a new `WazeTvtRouteSnapshot`.
   - A snapshot stores `partner_id`, master-route `route_id`, `waze_route_id`, `time`, `historicTime`, `jamLevel`, `recordedAt`, and `createdAt`.
   - Snapshots preserve travel-time and congestion evolution without duplicating route geometry and metadata.

---

## Change Log

- **2026-09-12** (WazeTvtRouteSnapshot)
  - Added append-only TVT route history model.
  - Records partner, master route, Waze route ID, `time`, `historicTime`, `jamLevel`, and reading timestamps.
  - Added time-series indexes for partner/route and timestamp queries.

- **2026-09-12** (WazeTvtRoute)
  - Added master entity for complete feeds-tvt route data.
  - Enforced uniqueness by `(partner_id, route_id)`.

- **2026-09-12** (WazeJam)
  - Added Waze traffic-jam entity with normalized fields and geometry JSON.

- **2026-09-12** (WazeAlert)
  - Added Waze alert entity with normalized fields.

---

## How to Update This File

When modifying entities:

1. Update the **Entity Details** section.
2. Adjust the **ER diagram** for new entities or relationships.
3. Record business-rule changes.
4. Add a dated entry in the **Change Log**.
