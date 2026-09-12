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
- CEMADEN river levels: `CemadenHidroStationLink` and `CemadenHidroObservation`.

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

`CemadenHidroStationLink` belongs to a `Partner`, and each `CemadenHidroObservation` retains both the station-link and partner foreign keys. The application must set the observation partner from `CemadenHidroStationLink.partner`.

---

## CEMADEN Hydro Entities

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

## CEMADEN Hydro Rules

1. Configure one `CemadenHidroStationLink` for each hydrological station monitored by a partner.
2. For every active link, fetch `GET {baseUrl}?est={cemadenTransactionId}&pag={recordsToFetch}`.
3. For each returned item, refresh optional station metadata on the link and map the item to one `CemadenHidroObservation`.
4. Parse `datahora` as the source observation timestamp, preserving the source timezone convention in the collector configuration.
5. Persist the raw item in `sourcePayload` and assign the partner from the station link.
6. Skip or safely handle an item already stored for the same station link and `observedAt`.
7. The collector is append-only: it must not overwrite previously stored river-level observations.

---

## CEMADEN Rainfall

- `CemadenStationLink.partner` → `Partner.id`: non-nullable Many-to-One.
- `CemadenPluviometricObservation.partner` → `Partner.id` and `CemadenPluviometricObservation.cemadenStationLink` → `CemadenStationLink.id`: non-nullable Many-to-One relationships.
- Rainfall observations are append-only and unique by `(cemaden_station_link_id, observed_at)`.

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

- **2026-09-12**: Added `CemadenHidroStationLink` and `CemadenHidroObservation` for partner-scoped CEMADEN river-level monitoring.
- **2026-09-12**: Added `CemadenStationLink` and `CemadenPluviometricObservation` for partner-scoped CEMADEN hourly rainfall monitoring.
- **2026-09-12**: Added `WeatherLocation` and `WeatherObservation` for partner-scoped Open-Meteo monitoring.
- **2026-09-12**: Added Waze TVT historical monitoring entities.

---

## Documentation Updates

When adding or changing entities, update the relevant diagram, entity details, persistence rules, and dated Change Log.