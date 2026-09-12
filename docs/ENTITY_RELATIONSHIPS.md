# Entity Relationships

**Repository:** `andresdiniz/wazeBR-symfony`  
**Last updated:** 2026-09-12  
**Scope:** Partner-scoped Waze, weather, CEMADEN, and camera monitoring data.

---

## Overview

The application partitions operational integrations by `Partner`. Each external monitoring source keeps its configuration/link entity separate from its append-only historical observations.

- **Core:** `Partner`, `User`, `PartnerApiLink`, `WazeAlert`, `WazeJam`.
- **Waze TVT:** `WazeTvtRoute`, `WazeTvtSubRoute`, `WazeTvtRouteSnapshot`, `WazeTvtUserOnJam`, `WazeTvtIrregularity`.
- **Weather:** `WeatherLocation`, `WeatherObservation`.
- **CEMADEN rainfall:** `CemadenStationLink`, `CemadenPluviometricObservation`.
- **CEMADEN river levels:** `CemadenHidroStationLink`, `CemadenHidroObservation`.
- **Cameras:** `PartnerCameraLink`.

---

## Partner Camera Diagram

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
|      partner_camera_link       |
+--------------------------------+
| id                             |
| partner_id (FK)                |
| name                           |
| url                            |
| url_type                       |
| latitude, longitude            |
| city, state, country_code      |
| provider                       |
| is_active                      |
| metadata (JSON)                |
| notes                          |
| created_at, updated_at         |
+--------------------------------+
```

### `PartnerCameraLink`

**Table:** `partner_camera_link`  
**Purpose:** Stores partner-owned camera links for monitoring, without storing media content.

- Required: `partner`, `name`, `url`, `latitude`, `longitude`.
- `url_type` indicates stream format (`http`, `hls`, `rtsp`, `youtube`, `vimeo`, `other`).
- Optional: `city`, `state`, `country_code`, `provider`, `metadata` (JSON for player options, headers, tokens), and `notes`.
- `is_active` controls whether the link is shown/used in dashboards.
- Indexes: `(partner_id, is_active)` and `(partner_id, latitude, longitude)` to support proximity searches with events.

---

## Relationships Summary

- `User.partner` → `Partner.id`: optional Many-to-One.
- `PartnerApiLink.partner` → `Partner.id`: Many-to-One.
- `WazeAlert.partner` and `WazeJam.partner` → `Partner.id`: non-nullable Many-to-One.
- `WazeTvtRoute.partner` → `Partner.id`: non-nullable Many-to-One.
- `WazeTvtSubRoute.partner` → `Partner.id` and `WazeTvtSubRoute.route` → `WazeTvtRoute.id`: non-nullable Many-to-One.
- `WazeTvtRouteSnapshot.partner` → `Partner.id` and `WazeTvtRouteSnapshot.route` → `WazeTvtRoute.id`: non-nullable Many-to-One.
- `WazeTvtUserOnJam.partner` → `Partner.id`: non-nullable Many-to-One; route is nullable.
- `WazeTvtIrregularity.partner` → `Partner.id` and `WazeTvtIrregularity.route` → `WazeTvtRoute.id`: non-nullable Many-to-One; `subRoute` is nullable.
- `WeatherLocation.partner` → `Partner.id`: non-nullable Many-to-One.
- `WeatherObservation.partner` → `Partner.id` and `WeatherObservation.weatherLocation` → `WeatherLocation.id`: non-nullable Many-to-One.
- `CemadenStationLink.partner` → `Partner.id`: non-nullable Many-to-One.
- `CemadenPluviometricObservation.partner` → `Partner.id` and `CemadenPluviometricObservation.cemadenStationLink` → `CemadenStationLink.id`: non-nullable Many-to-One.
- `CemadenHidroStationLink.partner` → `Partner.id`: non-nullable Many-to-One.
- `CemadenHidroObservation.partner` → `Partner.id` and `CemadenHidroObservation.cemadenHidroStationLink` → `CemadenHidroStationLink.id`: non-nullable Many-to-One.
- `PartnerCameraLink.partner` → `Partner.id`: non-nullable Many-to-One.

---

## Proximity Strategy for Cameras

- Use `PartnerCameraLink` coordinates to find cameras near events (alerts, jams, routes) via distance filters (e.g., Haversine or database geospatial functions).
- Prioritize active cameras (`is_active = true`) within a radius threshold per partner.
- Display nearby camera links alongside event details in dashboards; do not store media content in the database.

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
| `partner_camera_link`         | Partner-owned camera links with coordinates and metadata.               |

---

## Change Log

- **2026-09-12**: Added `PartnerCameraLink` for partner-owned camera monitoring and proximity to events.
- **2026-09-12**: Expanded `ENTITY_RELATIONSHIPS.md` with full entity explanations, relationship summary, persistence rules, and logical database map.
- **2026-09-12**: Added `CemadenHidroStationLink` and `CemadenHidroObservation` for partner-scoped CEMADEN river-level monitoring.
- **2026-09-12**: Added `CemadenStationLink` and `CemadenPluviometricObservation` for partner-scoped CEMADEN hourly rainfall monitoring.
- **2026-09-12**: Added `WeatherLocation` and `WeatherObservation` for partner-scoped Open-Meteo monitoring.
- **2026-09-12**: Added Waze TVT historical monitoring entities.

---

## Documentation Updates

When adding or changing entities, update the relevant diagram, entity details, persistence rules, and dated Change Log.
