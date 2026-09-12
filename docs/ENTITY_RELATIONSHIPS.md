# Entity Relationships

**Repository:** `andresdiniz/wazeBR-symfony`  
**Last updated:** 2026-09-12  
**Scope:** Core domain entities for partners, users, API links, and Waze alerts.

---

## Overview

The application is built around these main entities:

- `Partner`: represents an organization (e.g., city hall, traffic agency).
- `User`: represents system users, optionally scoped to a partner.
- `PartnerApiLink`: represents external API endpoints (alerts, traffic) owned by a partner.
- `WazeAlert`: normalized alert data fetched from Waze Partner API per partner.

All relationships are defined using Doctrine ORM annotations/attributes in PHP.

---

## Entity-Relationship Diagram (text)

```text
+------------------+       +----------------------+       +------------------------+
|      partner     |       |        user          |       |    partner_api_link    |
+------------------+       +----------------------+       +------------------------+
| id               |<------| partner_id (FK, null)|       | id                     |
| name             |       | id                   |       | partner_id (FK)        |
| code             |       | email                |       | type                   |
| slug             |       | password             |       | name                   |
| email            |       | name                 |       | url                    |
| description      |       | phone                |       | active                 |
| bbox             |       | active               |       | created_at             |
| api_token        |       | roles (JSON)         |       | updated_at             |
| active           |       | created_at           |       +------------------------+
| refresh_interval |       | updated_at           |                ^
| created_at       |       | last_login_at        |                |
| updated_at       |       +----------------------+                |
+------------------+                |                              |
        |                           |                              |
        | 1                         | N                            | 1
        |                           |                              |
        +---------------------------+                              |
        | users (Collection)                                      |
        |                                                          |
        +----------------------------------------------------------+
                          | apiLinks (Collection)
                          | N
                          |
        +-----------------+
        | 1
        |
        +------------------------+
        |      waze_alert        |
        +------------------------+
        | id                     |
        | partner_id (FK)        |
        | uuid (unique)          |
        | type, subtype          |
        | pub_millis             |
        | location_x, location_y |
        | street, city, country  |
        | road_type, confidence, |
        | reliability, ...       |
        | created_at, updated_at |
        +------------------------+
```

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
  - Inverse side: `Partner.wazeAlerts` (to be added as Collection if needed).  
  - Alerts are fetched from `PartnerApiLink.url` (type = 'alerts') and stored normalized (no raw JSON column).  

- `Partner` has:
  - `users`: `Collection<User>`  
  - `apiLinks`: `Collection<PartnerApiLink>`  
  - (optionally) `wazeAlerts`: `Collection<WazeAlert>` (can be added as inverse side).  

There is **no direct relationship** between `User` and `PartnerApiLink` or `WazeAlert`; all are associated independently to `Partner`.

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

**Collections:**

- `cemadenData`, `cities`, `links`, `users`, `alerts`
- `wazeCounts`, `routes`, `trafficJams`
- `wazeTvtRoutes`, `wazeFeeds`, `apiLinks`
- (optionally) `wazeAlerts` (if inverse side is added).

**Key methods:**

- `getUsers(): Collection`
- `getApiLinks(): Collection`
- `addApiLink(PartnerApiLink $apiLink): static`
- `removeApiLink(PartnerApiLink $apiLink): static`

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

Constants:

```php
private const ROLES_REQUIRING_PARTNER = [self::ROLE_PARTNER_ADMIN, self::ROLE_OPERATOR];
```

**Relationship:**

```php
#[ORM\ManyToOne(inversedBy: 'users')]
#[ORM\JoinColumn(nullable: true)]
private ?Partner $partner = null;
```

**Key methods:**

- `getPartner(): ?Partner`
- `setPartner(?Partner $partner): static`
- `getRoles(): array`
- `setRoles(array $roles): static`
- `addRole(string $role): static`
- `removeRole(string $role): static`
- `isGlobalAdmin(): bool` (admin without partner)
- `isPartnerScoped(): bool` (has a partner)

**Current business rules (enforced in entity):**

- **Global admins** (`ROLE_ADMIN` with `partner === null`) are allowed.
- Users with roles `ROLE_PARTNER_ADMIN` or `ROLE_OPERATOR` **must** have a partner.
- `setPartner()` throws `LogicException` if trying to remove partner from a user with those roles.
- `setRoles()` and `addRole()` throw `InvalidArgumentException` if assigning such roles without a partner.
- If partner is removed from a user with those roles, the operation is blocked (exception).

---

### `PartnerApiLink`

**File:** `src/Entity/PartnerApiLink.php`

**Main fields:**

- `id`
- `partner` (ManyToOne → `Partner`)
- `type` (`'alerts'` or `'traffic'`)
- `name`, `url`
- `active`
- `createdAt`, `updatedAt`

**Constants:**

```php
public const TYPE_ALERTS = 'alerts';
public const TYPE_TRAFFIC = 'traffic';
```

**Relationship:**

```php
#[ORM\ManyToOne(inversedBy: 'apiLinks')]
private ?Partner $partner = null;
```

**Key methods:**

- `getPartner(): ?Partner`
- `setPartner(?Partner $partner): static`
- `getType(): ?string`
- `setType(string $type): static` (validates `alerts` / `traffic`)
- `isAlerts(): bool`
- `isTraffic(): bool`

**Business rules:**

- `type` must be either `alerts` or `traffic`.
- Each link belongs to exactly one partner.

---

### `WazeAlert`

**File:** `src/Entity/WazeAlert.php`

**Purpose:** Store normalized Waze alert data fetched from `PartnerApiLink` (type `alerts`). No raw JSON column; all relevant fields are stored in dedicated columns.

**Main fields:**

- `id`
- `partner` (ManyToOne → `Partner`, **not null**)
- `uuid` (unique, from Waze API)
- `type` (e.g. `ROAD_CLOSED`, `HAZARD`, `ACCIDENT`, `WEATHERHAZARD`, `JAM`)
- `subtype` (e.g. `HAZARD_ON_ROAD_POT_HOLE`, etc.)
- `pubMillis` (publication time in milliseconds since epoch, stored as BIGINT string)
- `reportByMunicipalityUser` (string "true"/"false")
- `reportRating`, `confidence`, `reliability` (integers)
- `locationX`, `locationY` (longitude/latitude as DECIMAL)
- `street`, `city`, `country` (strings)
- `roadType` (integer code from Waze)
- `nThumbsUp`, `magvar` (integers)
- `createdAt`, `updatedAt` (local timestamps)

**Relationship:**

```php
#[ORM\ManyToOne]
#[ORM\JoinColumn(nullable: false)]
private ?Partner $partner = null;
```

**Key methods:**

- `getPartner(): ?Partner`
- `setPartner(?Partner $partner): static`
- `getUuid(): ?string`
- `getType(): ?string`
- `getSubtype(): ?string`
- `getPubMillis(): ?string`
- `getLocationX(): ?string`, `getLocationY(): ?string`
- `getLatitude(): ?float`, `getLongitude(): ?float` (convenience methods)
- `getPubDateTime(): ?\DateTimeImmutable` (converts pubMillis to DateTime)
- Getters/setters for all fields.

**Business rules:**

- Every alert must belong to a partner (`partner_id NOT NULL`).
- `uuid` is unique across all alerts (prevents duplicates from same feed).
- Data is stored normalized; no raw JSON column.
- Alerts are expected to be inserted/updated by a background job or command that consumes `PartnerApiLink` URLs (type `alerts`).

---

## Business Rules Summary

### Current rules

1. **User ↔ Partner**
   - A user **can** exist without a partner only if they are a global admin (`ROLE_ADMIN` without partner).
   - Users with roles `ROLE_PARTNER_ADMIN` or `ROLE_OPERATOR` **must** have a partner.
   - Removing a partner from such a user is blocked (throws `LogicException`).
   - Assigning those roles to a user without a partner is blocked (throws `InvalidArgumentException`).

2. **PartnerApiLink ↔ Partner**
   - Every API link must belong to a partner.
   - Type is restricted to `alerts` or `traffic`.

3. **WazeAlert ↔ Partner**
   - Every alert must belong to a partner.
   - Alerts are fetched from partner's `PartnerApiLink` (type `alerts`).
   - Each alert has a unique `uuid` from Waze.
   - Data is stored normalized (no raw JSON).

4. **Data scoping**
   - Users are scoped to a partner via `User.partner` (except global admins).
   - API links and alerts are configuration and data per partner.
   - No direct link between a specific user and a specific API link or alert.

---

## Planned / Possible Evolutions

Use this section to track changes as development progresses.

### 1. Enforce "every user must have a partner" (if rules change)

If the rule changes to **"all users must belong to a partner"** (no global admins):

- **Database:**
  - Change `user.partner_id` to `NOT NULL` via migration.
- **Entity:**
  - Update `User.php`:
    ```php
    #[ORM\JoinColumn(nullable: false)]
    private ?Partner $partner = null;
    ```
  - Optionally require `Partner` in the constructor:
    ```php
    public function __construct(Partner $partner) { ... }
    ```
  - Throw exception when trying to set `null`:
    ```php
    public function setPartner(?Partner $partner): static
    {
        if ($partner === null && $this->partner !== null) {
            throw new \LogicException('Cannot remove partner from user; every user must have a partner.');
        }
        // ...
    }
    ```

### 2. Global admin vs partner-scoped users

Current design supports:

- **Global admins**: `ROLE_ADMIN` with `partner === null`.
- **Partner-scoped users**: any role with `partner !== null`.

Documented constraints:

- Only global admins can exist without a partner.
- Partner admins can only manage users within their partner.

### 3. Waze jams (traffic) entity

If you later want to store `jams` from the same Waze feed:

- Create a `WazeJam` entity with fields like `uuid`, `level`, `length`, `delay`, `speed`, `line` (maybe JSON or separate table), `street`, `city`, `pubMillis`, `partner_id`.
- Add relationship `WazeJam.partner` → `Partner`.
- Update this document accordingly.

### 4. Inverse side for WazeAlert on Partner

If you need to navigate from `Partner` to its alerts:

- Add to `Partner.php`:
  ```php
  #[ORM\OneToMany(mappedBy: 'partner', targetEntity: WazeAlert::class)]
  private Collection $wazeAlerts;
  ```
- Add `getWazeAlerts(): Collection` and helper methods.

---

## Change Log

- **2026-09-12** (WazeAlert)
  - Added `WazeAlert` entity with normalized fields (no raw JSON).
  - Each alert has `partner_id`, `uuid` (unique), type, subtype, location, and metadata.
  - Updated ER diagram and business rules.

- **2026-09-12** (update)
  - Strengthened `User` entity validation:
    - `setPartner()` now throws `LogicException` when removing partner from a user with `ROLE_PARTNER_ADMIN` or `ROLE_OPERATOR`.
    - `setRoles()` and `addRole()` validate that roles requiring partner are not assigned when partner is null.
    - Clarified business rule: global admin may have no partner; other roles require partner.

- **2026-09-12** (initial)
  - Initial documentation of `Partner`, `User`, and `PartnerApiLink` relationships.
  - Added ER diagram in text format.
  - Described current business rules and possible evolutions.

---

## How to Update This File

When modifying entities:

1. Update the **Entity Details** section (fields, relationships, methods).
2. Adjust the **ER diagram** if new entities or relationships are added.
3. Record new or changed **business rules** in the corresponding section.
4. Add an entry in the **Change Log** with date and summary.
