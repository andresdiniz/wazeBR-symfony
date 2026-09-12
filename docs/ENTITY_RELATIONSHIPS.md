# Entity Relationships

**Repository:** `andresdiniz/wazeBR-symfony`  
**Last updated:** 2026-09-12  
**Scope:** Partner-scoped Waze, weather, and CEMADEN monitoring data.

---

## Overview

The application partitions operational integrations by `Partner`. Each external monitoring source keeps its configuration/link entity separate from its append-only historical observations.

- Waze TVT: routes, subroutes, route snapshots, users-on-jams, and irregularities.
- Weather: `WeatherLocation` and `WeatherObservation`.
- CEMADEN rainfall: `CemadenStationLink` and `CemadenPluviometricObservation`.

---

## CEMADEN Diagram

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

`CemadenStationLink` belongs to a `Partner`, and each `CemadenPluviometricObservation` retains both the station-link and partner foreign keys. The application must set the observation partner from `CemadenStationLink.partner` to preserve tenant consistency.

---

## CEMADEN Entities

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

## CEMADEN Persistence Rules

1. Configure one `CemadenStationLink` for each CEMADEN station monitored by a partner.
2. For every active link, fetch `GET {baseUrl}/{cemadenStationId}/{hoursToFetch}`.
3. Update the station metadata from the response's `estacao` object and set `lastFetchedAt` after a successful response.
4. Iterate `datas` and their matching `acumulados` arrays; pair each value with its matching `horarios` label.
5. Normalize `"0h"` through `"23h"` to `hourSlot` 0 through 23 and combine it with the corresponding date to set `observedAt`.
6. Insert one `CemadenPluviometricObservation` per date/hour. Null accumulated values remain valid historical observations; skip or safely handle rows already present for the same station link and timestamp.
7. Preserve partner consistency by assigning `CemadenPluviometricObservation.partner` from `CemadenStationLink.partner`.

---

## Weather Relationships

- `WeatherLocation.partner` → `Partner.id`: non-nullable Many-to-One relationship.
- `WeatherObservation.partner` → `Partner.id` and `WeatherObservation.weatherLocation` → `WeatherLocation.id`: non-nullable Many-to-One relationships.
- `WeatherObservation` is append-only and unique by `(weather_location_id, observed_at)`.

---

## TVT Relationships

- `WazeTvtRoute.partner` → `Partner.id`: non-nullable Many-to-One.
- `WazeTvtSubRoute.partner` → `Partner.id` and `WazeTvtSubRoute.route` → `WazeTvtRoute.id`: non-nullable Many-to-One relationships.
- `WazeTvtRouteSnapshot.partner` → `Partner.id` and `WazeTvtRouteSnapshot.route` → `WazeTvtRoute.id`: non-nullable Many-to-One relationships.
- `WazeTvtUserOnJam.partner` → `Partner.id`: non-nullable Many-to-One; its route relation is nullable.
- `WazeTvtIrregularity.partner` → `Partner.id` and `WazeTvtIrregularity.route` → `WazeTvtRoute.id`: non-nullable Many-to-One; `subRoute` is nullable.

---

## Change Log

- **2026-09-12**: Added `CemadenStationLink` and `CemadenPluviometricObservation` for partner-scoped CEMADEN hourly rainfall monitoring.
- **2026-09-12**: Added `WeatherLocation` and `WeatherObservation` for partner-scoped Open-Meteo monitoring.
- **2026-09-12**: Added Waze TVT historical monitoring entities.

---

## Documentation Updates

When adding or changing entities, update the relevant diagram, entity details, persistence rules, and dated Change Log.