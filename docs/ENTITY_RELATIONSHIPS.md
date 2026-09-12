# Entity Relationships

**Repository:** `andresdiniz/wazeBR-symfony`  
**Last updated:** 2026-09-12  
**Scope:** Partner-scoped Waze, weather, and CEMADEN monitoring data.

---

## Overview

The application partitions operational integrations by `Partner`. Each external monitoring source keeps its configuration/link entity separate from its append-only historical observations.

- **Core:** `Partner`, `User`, `PartnerApiLink`, `WazeAlert`, `WazeJam`.
- **Waze TVT:** `WazeTvtRoute`, `WazeTvtSubRoute`, `WazeTvtRouteSnapshot`, `WazeTvtUserOnJam`, `WazeTvtIrregularity`.
- **Weather:** `WeatherLocation`, `WeatherObservation`.
- **CEMADEN rainfall:** `CemadenStationLink`, `CemadenPluviometricObservation`.
- **CEMADEN river levels:** `CemadenHidroStationLink`, `CemadenHidroObservation`.

---

## Core Diagram

```text
+------------------+
|     partner      |
+------------------+
| id               |
| name, code, slug |
+------------------+
       | 1
       |
       +------------------+
       | N                | N
       v                  v
+----------------+  +------------------+
|      user      |  |  partner_api_link|
+----------------+  +------------------+
| id             |  | id               |
| partner_id(FK) |  | partner_id (FK)  |
+----------------+  +------------------+
```

### `Partner`

**Table:** `partner`  
**Purpose:** Tenant entity representing an organization or client.

- Fields: `name`, `code`, `slug` and other business attributes.
- All operational data is scoped to a partner via foreign keys.

### `User`

**Table:** `user`  
**Purpose:** Application users, optionally associated with a partner.

- `partner` is optional Many-to-One; global admins may have no partner.
- Partner-scoped roles (admin/operator) require a partner.

### `PartnerApiLink`

**Table:** `partner_api_link`  
**Purpose:** Stores API credentials or links for external providers per partner.

- Many-to-One to `Partner`.
- Used to keep tokens, base URLs, or connection metadata.

### `WazeAlert` and `WazeJam`

**Tables:** `waze_alert`, `waze_jam`  
**Purpose:** Normalized Waze alerts and jams associated with a partner.

- Non-nullable `partner` foreign key.
- Used for Waze feed processing outside TVT.

---

## Waze TVT Diagram

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

### `WazeTvtRoute`

**Table:** `waze_tvt_route`  
**Purpose:** Master record for a TVT route and its complete metadata/geometry.

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

## Weather Diagram

```text
+------------------+
|     partner      |
+------------------+
| id               |
| name, code, slug |
+------------------+
       | 1
       |
       | N
       v
+---------------------------+
|      weather_location     |
+---------------------------+
| id                        |
| partner_id (FK)           |
| name, city, state         |
| country_code              |
| latitude, longitude       |
| timezone                  |
| provider, api_token       |
| active                    |
+---------------------------+
       | 1
       |
       | N
       v
+---------------------------+
|    weather_observation    |
+---------------------------+
| id                        |
| partner_id (FK)           |
| weather_location_id (FK)  |
| temperature, humidity     |
| precipitation, rain       |
| weather_code, cloud_cover |
| pressure, wind, visibility|
| payload (JSON)            |
| observed_at, created_at   |
+---------------------------+
```

### `WeatherLocation`

**Table:** `weather_location`  
**Purpose:** Stores a city or exact coordinates to monitor for a partner using Open-Meteo.

- Required: `partner`, `name`, `latitude`, `longitude`, `provider`.
- Optional: `city`, `state`, ISO `countryCode`, IANA `timezone`, and `apiToken`.
- Default provider: `open-meteo`; the public Open-Meteo current-weather endpoint does not require a token.
- `active` controls whether the scheduled collector should query the location.
- Unique constraint: `(partner_id, latitude, longitude)`.

### `WeatherObservation`

**Table:** `weather_observation`  
**Purpose:** Append-only historical record of a provider response for a monitored location.

- Required: `partner`, `weatherLocation`, `observedAt`, `payload`.
- Standard fields: `temperature`, `apparentTemperature`, `relativeHumidity`, `precipitation`, `rain`, `showers`, `snowfall`, `weatherCode`, `cloudCover`, `surfacePressure`, `windSpeed`, `windDirection`, `windGusts`, `visibility`, and `isDay`.
- `payload` preserves the raw normalized provider response for future fields and auditing.
- Unique constraint: `(weather_location_id, observed_at)`.
- Indexes: `(partner_id, observed_at)` and `(weather_location_id, observed_at)`.

---

## CEMADEN Rainfall Diagram

```text
+------------------+
|     partner      |
+------------------+
| id               |
| name, code, slug |
+------------------+
       | 1
       |
       | N
       v
+--------------------------------+
|      cemaden_station_link      |
+--------------------------------+
| id                             |
| partner_id (FK)                |
| cemaden_station_id             |
| station_code, station_name     |
| latitude, longitude, status    |
| station_type                   |
| municipality / city / state    |
| ibge_code                      |
| network metadata               |
| base_url, hours_to_fetch       |
| active, last_fetched_at        |
+--------------------------------+
       | 1
       |
       | N
       v
+----------------------------------------+
| cemaden_pluviometric_observation      |
+----------------------------------------+
| id                                     |
| partner_id (FK)                        |
| cemaden_station_link_id (FK)           |
| reference_date, hour_slot               |
| accumulated_rainfall                    |
| observed_at                             |
| source_payload (JSON)                   |
| created_at                              |
+----------------------------------------+
```

### `CemadenStationLink`

**Table:** `cemaden_station_link`  
**Purpose:** Stores a partner-specific CEMADEN station and its request configuration.

- Required: `partner`, `cemadenStationId`, `stationName`, `latitude`, `longitude`, and `baseUrl`.
- Station metadata: `stationCode`, `status`, `stationType`, municipality/city/state/IBGE code, and network metadata.
- Default endpoint root: `https://mapservices.cemaden.gov.br/MapaInterativoWS/resources/horario`.
- `getRequestUrl()` produces `{baseUrl}/{cemadenStationId}/{hoursToFetch}`; for station 4142 and 23 hours, it produces `/horario/4142/23`.
- `hoursToFetch` defaults to 24 and is constrained to 1-168 hours; `active` controls scheduled collection.
- Unique constraint: `(partner_id, cemaden_station_id)`.

### `CemadenPluviometricObservation`

**Table:** `cemaden_pluviometric_observation`  
**Purpose:** Append-only rainfall history, with one row for each CEMADEN date/hour cell in `datas`, `horarios`, and `acumulados`.

- Required: `partner`, `cemadenStationLink`, `referenceDate`, `hourSlot`, and `observedAt`.
- `accumulatedRainfall` holds the numeric accumulated value in millimetres and may be null when CEMADEN returns no measurement for a date/hour.
- `sourcePayload` preserves the raw source context for auditability, including original labels and station metadata as needed.
- `hourSlot` normalizes labels such as `"16h"` to integer `16`; `observedAt` combines `referenceDate` and `hourSlot`.
- Unique constraint: `(cemaden_station_link_id, observed_at)`.
- Indexes: `(partner_id, observed_at)` and `(cemaden_station_link_id, observed_at)`.

---

## CEMADEN Hydro Diagram

```text
+------------------+
|     partner      |
+------------------+
| id               |
| name, code, slug |
+------------------+
       | 1
       |
       | N
       v
+--------------------------------+
| cemaden_hidro_station_link     |
+--------------------------------+
| id                             |
| partner_id (FK)                |
| cemaden_transaction_id         |
| station_code, station_name     |
| city, state                    |
| base_url, records_to_fetch     |
| active, last_fetched_at        |
+--------------------------------+
       | 1
       |
       | N
       v
+--------------------------------+
|   cemaden_hidro_observation    |
+--------------------------------+
| id                             |
| partner_id (FK)                |
| cemaden_hidro_station_link_id  |
| station_code, station_name     |
| city, state                    |
| river_level, offset            |
| observed_at                    |
| source_payload (JSON)          |
| created_at                     |
+--------------------------------+
```

### `CemadenHidroStationLink`

**Table:** `cemaden_hidro_station_link`  
**Purpose:** Stores one partner-specific CEMADEN hydrological station query.

- Required: `partner`, `cemadenTransactionId`, and `baseUrl`.
- Optional cached metadata: `stationCode`, `stationName`, `city`, and `state`.
- Default endpoint: `https://resources.cemaden.gov.br/graficos/cemaden/hidro/resources/json/AcumuladoResource.php`.
- `getRequestUrl()` generates `{baseUrl}?est={cemadenTransactionId}&pag={recordsToFetch}`; for transaction 6622 and 24 records, it produces `?est=6622&pag=24`.
- `recordsToFetch` defaults to 24 and supports 1 to 720 records; `active` controls scheduled collection.
- Unique constraint: `(partner_id, cemaden_transaction_id)`.

### `CemadenHidroObservation`

**Table:** `cemaden_hidro_observation`  
**Purpose:** Append-only river-level history, with one row for every item returned by the hydrological endpoint.

- Required: `partner`, `cemadenHidroStationLink`, `observedAt`, and `sourcePayload`.
- Source mapping: `codigo` → `stationCode`, `estacao` → `stationName`, `cidade` → `city`, `uf` → `state`, `valor` → `riverLevel`, `offset` → `offset`, `datahora` → `observedAt`.
- `riverLevel` and `offset` retain decimal precision and may be null when the source has no value.
- Unique constraint: `(cemaden_hidro_station_link_id, observed_at)`.
- Indexes: `(partner_id, observed_at)` and `(cemaden_hidro_station_link_id, observed_at)`.

---

## Relationships Summary

- `User.partner` → `Partner.id`: optional Many-to-One; global admins may have no partner, while partner admins and operators require one.
- `PartnerApiLink.partner` → `Partner.id`: Many-to-One; each link belongs to a partner.
- `WazeAlert.partner` and `WazeJam.partner` → `Partner.id`: non-nullable Many-to-One relationships.
- `WazeTvtRoute.partner` → `Partner.id`: non-nullable Many-to-One; `(partner_id, route_id)` identifies one master route.
- `WazeTvtSubRoute.partner` → `Partner.id` and `WazeTvtSubRoute.route` → `WazeTvtRoute.id`: non-nullable Many-to-One relationships; each Waze subroute is unique per partner/route/subroute identifier.
- `WazeTvtRouteSnapshot.partner` → `Partner.id` and `WazeTvtRouteSnapshot.route` → `WazeTvtRoute.id`: non-nullable Many-to-One relationships for historical route metrics.
- `WazeTvtUserOnJam.partner` → `Partner.id`: non-nullable Many-to-One. Its route relationship is nullable because a users-on-jams value can be recorded even when route matching is unavailable.
- `WazeTvtIrregularity.partner` → `Partner.id` and `WazeTvtIrregularity.route` → `WazeTvtRoute.id`: non-nullable Many-to-One. `subRoute` is nullable, because an irregularity can apply to a whole route or cannot always be matched to an imported subroute.
- `WeatherLocation.partner` → `Partner.id`: non-nullable Many-to-One relationship.
- `WeatherObservation.partner` → `Partner.id` and `WeatherObservation.weatherLocation` → `WeatherLocation.id`: non-nullable Many-to-One relationships.
- `CemadenStationLink.partner` → `Partner.id`: non-nullable Many-to-One.
- `CemadenPluviometricObservation.partner` → `Partner.id` and `CemadenPluviometricObservation.cemadenStationLink` → `CemadenStationLink.id`: non-nullable Many-to-One relationships.
- `CemadenHidroStationLink.partner` → `Partner.id`: non-nullable Many-to-One.
- `CemadenHidroObservation.partner` → `Partner.id` and `CemadenHidroObservation.cemadenHidroStationLink` → `CemadenHidroStationLink.id`: non-nullable Many-to-One relationships.

---

## Persistence Rules

1. Every operational entity must reference a valid `Partner`.
2. Historical observations (`WazeTvtRouteSnapshot`, `WazeTvtUserOnJam`, `WazeTvtIrregularity`, `WeatherObservation`, `CemadenPluviometricObservation`, `CemadenHidroObservation`) are append-only and must not overwrite prior history.
3. Unique constraints on `(link, observed_at)` or `(location, observed_at)` enforce idempotency for repeated collections.
4. Collectors must assign the observation's `partner` from the corresponding link entity to preserve tenant consistency.
5. Station/link metadata may be refreshed on each successful API response.

---

## Logical Database Map

| Table                          | Purpose                                                                 |
|-------------------------------|-------------------------------------------------------------------------|
| `partner`                     | Tenant/organization entity.                                             |
| `user`                        | Application users, optionally linked to a partner.                      |
| `partner_api_link`            | API credentials/links per partner.                                      |
| `waze_alert` / `waze_jam`     | Normalized Waze alerts and jams per partner.                          |
| `waze_tvt_route`              | Master TVT route metadata per partner.                                  |
| `waze_tvt_sub_route`          | Normalized subroutes of a TVT route.                                    |
| `waze_tvt_route_snapshot`     | Append-only travel metrics per route read.                              |
| `waze_tvt_user_on_jam`        | Append-only users-on-jams observations.                                 |
| `waze_tvt_irregularity`       | One row per irregularity item in a subroute/route.                      |
| `weather_location`            | Monitored city/coordinates per partner (Open-Meteo).                    |
| `weather_observation`         | Append-only weather readings per location.                              |
| `cemaden_station_link`        | CEMADEN pluviometric station configuration per partner.                 |
| `cemaden_pluviometric_observation` | Append-only hourly rainfall per station.                         |
| `cemaden_hidro_station_link`  | CEMADEN hydrological station query configuration per partner.           |
| `cemaden_hidro_observation`   | Append-only river-level readings per station.                           |

---

## Change Log

- **2026-09-12**: Expanded `ENTITY_RELATIONSHIPS.md` with full entity explanations, relationship summary, persistence rules, and logical database map.
- **2026-09-12**: Added `CemadenHidroStationLink` and `CemadenHidroObservation` for partner-scoped CEMADEN river-level monitoring.
- **2026-09-12**: Added `CemadenStationLink` and `CemadenPluviometricObservation` for partner-scoped CEMADEN hourly rainfall monitoring.
- **2026-09-12**: Added `WeatherLocation` and `WeatherObservation` for partner-scoped Open-Meteo monitoring.
- **2026-09-12**: Added Waze TVT historical monitoring entities.

---

## Documentation Updates

When adding or changing entities, update the relevant diagram, entity details, persistence rules, and dated Change Log.
