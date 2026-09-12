# Entity Relationships

**Repository:** `andresdiniz/wazeBR-symfony`  
**Last updated:** 2026-09-12  
**Scope:** Partners, users, Waze feed master data, and append-only Waze historical metrics.

---

## Overview

The Waze TVT import model separates stable route metadata from changing observations:

- `WazeTvtRoute`: master record for a TVT route and its complete metadata/geometry.
- `WazeTvtSubRoute`: normalized child record for each item of a route's `subRoutes` array.
- `WazeTvtRouteSnapshot`: append-only history for `time`, `historicTime`, and `jamLevel` on each feed read.
- `WazeTvtUserOnJam`: append-only history for `usersOnJams` / `wazersCount` values.
- `WazeTvtIrregularity`: one persisted record for each `irregularities` array item.

Other core entities are `Partner`, `User`, `PartnerApiLink`, `WazeAlert`, and `WazeJam`.

---

## TVT Relationship Diagram

```text
+------------------+
|     partner      |
+------------------+
| id               |
| name, code, slug |
+------------------+
       | 1
       |
       +------------------------------+-------------------------------+------------------------+
       | N                            | N                             | N
       v                              v                               v
+------------------------+  +--------------------------+  +------------------------+
|    waze_tvt_route      |  | waze_tvt_user_on_jam    |  | waze_tvt_route_snapshot|
+------------------------+  +--------------------------+  +------------------------+
| id                     |  | id                       |  | id                     |
| partner_id (FK)        |  | partner_id (FK)          |  | partner_id (FK)        |
| route_id (Waze ID)     |  | route_id (FK, nullable) |  | route_id (FK)          |
| name, from_name, ...   |  | waze_route_id            |  | waze_route_id          |
| geometry / subRoutes   |  | wazers_count             |  | time, historic_time    |
+------------------------+  | jam_level                |  | jam_level, recorded_at |
       | 1                  | recorded_at              |  +------------------------+
       |                    +--------------------------+
       | N
       +----------------------------------------------+
       |                                              |
       v                                              v
+------------------------+                  +------------------------+
|  waze_tvt_sub_route    |                  | waze_tvt_irregularity  |
+------------------------+                  +------------------------+
| id                     |                  | id                     |
| partner_id (FK)        |                  | partner_id (FK)        |
| route_id (FK)          |<-----------------| route_id (FK)          |
| waze_route_id          |                  | sub_route_id (FK,NULL) |
| sub_route_id (Waze ID) |                  | waze_route_id          |
| line, bbox             |                  | waze_sub_route_id      |
| irregularities (JSON)  |                  | type, subtype          |
+------------------------+                  | description, payload    |
                                            | content_hash            |
                                            | recorded_at             |
                                            +------------------------+
```

`route_id` used as a foreign key always points to the local primary key of `waze_tvt_route`; `waze_route_id` stores the identifier received from Waze. The two columns are intentionally distinct.

---

## Relationships

- `User.partner` → `Partner.id`: optional Many-to-One; global admins may have no partner, while partner admins and operators require one.
- `PartnerApiLink.partner` → `Partner.id`: Many-to-One; each link belongs to a partner.
- `WazeAlert.partner` and `WazeJam.partner` → `Partner.id`: non-nullable Many-to-One relationships.
- `WazeTvtRoute.partner` → `Partner.id`: non-nullable Many-to-One; `(partner_id, route_id)` identifies one master route.
- `WazeTvtSubRoute.partner` → `Partner.id` and `WazeTvtSubRoute.route` → `WazeTvtRoute.id`: non-nullable Many-to-One relationships; each Waze subroute is unique per partner/route/subroute identifier.
- `WazeTvtRouteSnapshot.partner` → `Partner.id` and `WazeTvtRouteSnapshot.route` → `WazeTvtRoute.id`: non-nullable Many-to-One relationships for historical route metrics.
- `WazeTvtUserOnJam.partner` → `Partner.id`: non-nullable Many-to-One. Its route relationship is nullable because a users-on-jams value can be recorded even when route matching is unavailable.
- `WazeTvtIrregularity.partner` → `Partner.id` and `WazeTvtIrregularity.route` → `WazeTvtRoute.id`: non-nullable Many-to-One. `subRoute` is nullable, because an irregularity can apply to a whole route or cannot always be matched to an imported subroute.

---

## Entity Details

### `WazeTvtRoute`

**Table:** `waze_tvt_route`  
**Purpose:** Stores a master copy of full TVT route metadata: Waze route ID, names, length, route type, city/country, bounding box, geometry (`line`) and raw `subRoutes` JSON when needed.

- Required: `partner`, `routeId`.
- Unique constraint: `(partner_id, route_id)`.
- Import behavior: insert on first observation; avoid duplicating unchanged metadata during later reads.

### `WazeTvtSubRoute`

**Table:** `waze_tvt_sub_route`  
**Purpose:** Persists each object in the Waze `subRoutes` array as an entity related to its master route.

- Required: `partner`, `route`, `wazeRouteId`, `subRouteId`.
- Fields: names, length, current time, historic time, jam level, bbox, geometry (`line`) and `irregularities` JSON.
- Unique constraint: `(partner_id, route_id, sub_route_id)`.
- `irregularities` remains available as JSON on the subroute for source fidelity; individual items are also represented by `WazeTvtIrregularity`.

### `WazeTvtRouteSnapshot`

**Table:** `waze_tvt_route_snapshot`  
**Purpose:** Append-only historical metric per route reading.

- Required: `partner`, `route`, `recordedAt`.
- Fields: `wazeRouteId`, `time`, `historicTime`, `jamLevel`, `recordedAt`, `createdAt`.
- A successful feed read inserts a new row rather than updating an existing one.

### `WazeTvtUserOnJam`

**Table:** `waze_tvt_user_on_jam`  
**Purpose:** Append-only history of the users-on-jams observation from the TVT payload.

- Required: `partner`, `recordedAt`.
- Optional: `route`, `wazeRouteId`.
- Fields: `wazersCount`, `jamLevel`, `recordedAt`, `createdAt`.
- Every successful feed reading inserts a new row; it is suitable for trend analysis by partner, route, and timestamp.
- Indexes: `(partner_id, recorded_at)` and `(route_id, recorded_at)`.

### `WazeTvtIrregularity`

**Table:** `waze_tvt_irregularity`  
**Purpose:** Persists one entity for each item found in a route or subroute `irregularities` array.

- Required: `partner`, `route`, `wazeRouteId`, `contentHash`, `payload`, `recordedAt`.
- Optional: `subRoute`, `wazeSubRouteId`, `type`, `subtype`, `description`.
- `payload` stores the complete irregularity object as JSON because its Waze schema can vary by item/type.
- `contentHash` is a SHA-256 hash of canonicalized payload data and, together with partner/route/subroute, prevents duplicate identical irregularities.
- `recordedAt` tracks the feed observation; `updatedAt` may be used when an existing irregularity is observed again.

---

## Persistence Rules

1. Create or locate `WazeTvtRoute` by `(partner, routeId)`.
2. Create or update `WazeTvtSubRoute` by `(partner, route, subRouteId)`.
3. Insert a new `WazeTvtRouteSnapshot` on every successful route observation, storing only changing travel metrics.
4. Insert a new `WazeTvtUserOnJam` on every successful `usersOnJams` observation.
5. For every `irregularities` item, create or update `WazeTvtIrregularity` by its association identifiers and `contentHash`; preserve its full source item in `payload` JSON.

---

## Change Log

- **2026-09-12**: Added `WazeTvtSubRoute`, `WazeTvtUserOnJam`, and `WazeTvtIrregularity` model documentation.
- **2026-09-12**: Added append-only `WazeTvtRouteSnapshot` history for route travel time and jam level.
- **2026-09-12**: Added `WazeTvtRoute` master route entity for Waze feeds-tvt data.
- **2026-09-12**: Added normalized Waze alert and jam entities.

---

## How to Update This File

When modifying entities:

1. Update entity fields and relations in **Entity Details**.
2. Adjust the diagram when adding a table or relation.
3. Record persistence-rule changes.
4. Add a dated entry to **Change Log**.
