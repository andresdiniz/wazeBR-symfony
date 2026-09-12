# Entity Relationships

**Repository:** `andresdiniz/wazeBR-symfony`  
**Last updated:** 2026-09-12  
**Scope:** Partners, users, Waze feed master data, append-only Waze metrics, and weather monitoring.

---

## Overview

The application keeps operational data partitioned by partner. Waze TVT imports separate stable route metadata from changing observations, while weather monitoring separates monitored locations from append-only weather observations.

- `WazeTvtRoute`: master record for a TVT route and its complete metadata/geometry.
- `WazeTvtSubRoute`: normalized child record for each item of a route's `subRoutes` array.
- `WazeTvtRouteSnapshot`: append-only history for route travel metrics on each feed read.
- `WazeTvtUserOnJam`: append-only history for `usersOnJams` / `wazersCount` values.
- `WazeTvtIrregularity`: one persisted record for each `irregularities` array item.
- `WeatherLocation`: a partner-owned city or coordinate pair monitored through a weather provider.
- `WeatherObservation`: append-only current-weather history for one monitored location.

Other core entities are `Partner`, `User`, `PartnerApiLink`, `WazeAlert`, and `WazeJam`.

---

## Weather Relationship Diagram

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

Every `WeatherLocation` belongs to one `Partner`, and every `WeatherObservation` retains both its location and partner foreign keys. The duplicated partner relation supports efficient tenant-scoped historical queries and must match `weatherLocation.partner` in application logic.

---

## Weather Entities

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
- Indexes: `(partner_id, observed_at)` e `(weather_location_id, observed_at)`.

---

## Weather Persistence Rules

1. A partner may have multiple active `WeatherLocation` records.
2. A scheduled process runs every 30 minutes and queries each active location through its configured provider.
3. The collector maps Open-Meteo current-weather values into the standard `WeatherObservation` fields and preserves the full response in `payload`.
4. Each successful collection inserts one observation using the provider's observation time in `observedAt`; it does not overwrite prior history.
5. The collector must assign `WeatherObservation.partner` from `WeatherLocation.partner` before persistence.
6. An existing observation with the same location and `observedAt` is skipped or safely handled as an idempotent duplicate.

---

## TVT Relationships

- `WazeTvtRoute.partner` → `Partner.id`: non-nullable Many-to-One; `(partner_id, route_id)` identifies one master route.
- `WazeTvtSubRoute.partner` → `Partner.id` and `WazeTvtSubRoute.route` → `WazeTvtRoute.id`: non-nullable Many-to-One relationships.
- `WazeTvtRouteSnapshot.partner` → `Partner.id` and `WazeTvtRouteSnapshot.route` → `WazeTvtRoute.id`: non-nullable Many-to-One relationships for historical route metrics.
- `WazeTvtUserOnJam.partner` → `Partner.id`: non-nullable Many-to-One; its route relation is nullable.
- `WazeTvtIrregularity.partner` → `Partner.id` and `WazeTvtIrregularity.route` → `WazeTvtRoute.id`: non-nullable Many-to-One; `subRoute` is nullable.

---

## Change Log

- **2026-09-12**: Added `WeatherLocation` and `WeatherObservation` for partner-scoped Open-Meteo monitoring and 30-minute historical collection.
- **2026-09-12**: Added `WazeTvtUserOnJam` and `WazeTvtIrregularity` model documentation.
- **2026-09-12**: Added append-only `WazeTvtRouteSnapshot` history for route travel time and jam level.
- **2026-09-12**: Added `WazeTvtRoute` master route entity for Waze feeds-tvt data.

---

## Documentation Updates

When adding or changing entities, update the relevant diagram, entity details, persistence rules, and the dated Change Log.