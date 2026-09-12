# Entity Relationships

**Repository:** `andresdiniz/wazeBR-symfony`  
**Last updated:** 2026-09-12  
**Scope:** Core domain entities for partners, users, and API links.

---

## Overview

The application is built around three main entities:

- `Partner`: represents an organization (e.g., city hall, traffic agency).
- `User`: represents system users, optionally scoped to a partner.
- `PartnerApiLink`: represents external API endpoints (alerts, traffic) owned by a partner.

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
```

### Relationships

- `User.partner` → `Partner.id`  
  - Type: **Many-to-One** (multiple users can belong to one partner).  
  - Currently: **nullable** (`JoinColumn(nullable: true)`).  
  - Inverse side: `Partner.users` (Collection).  

- `PartnerApiLink.partner` → `Partner.id`  
  - Type: **Many-to-One** (multiple API links can belong to one partner).  
  - Currently: **non-nullable in practice** (every link must have a partner).  
  - Inverse side: `Partner.apiLinks` (Collection).  

- `Partner` has:
  - `users`: `Collection<User>`  
  - `apiLinks`: `Collection<PartnerApiLink>`  

There is **no direct relationship** between `User` and `PartnerApiLink`; both are associated independently to `Partner`.

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

Constant:

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

**Current business rules:**

- Roles `ROLE_PARTNER_ADMIN` and `ROLE_OPERATOR` **require** a partner.
- If `partner` is removed from a user with such roles, roles are downgraded to `[ROLE_VIEWER]`.
- A user **may** exist without a partner (global admin / system user).

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

## Business Rules Summary

### Current rules

1. **User ↔ Partner**
   - A user **can** exist without a partner (nullable FK).
   - Users with roles `ROLE_PARTNER_ADMIN` or `ROLE_OPERATOR` **must** have a partner.
   - Removing a partner from such a user automatically downgrades roles to `[ROLE_VIEWER]`.

2. **PartnerApiLink ↔ Partner**
   - Every API link must belong to a partner.
   - Type is restricted to `alerts` or `traffic`.

3. **Data scoping**
   - Users are scoped to a partner via `User.partner`.
   - API links are configuration for data collection per partner.
   - No direct link between a specific user and a specific API link.

---

## Planned / Possible Evolutions

Use this section to track changes as development progresses.

### 1. Enforce "every user must have a partner"

If the rule changes to **"all users must belong to a partner"**:

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

If needed, document additional constraints, e.g.:

- Only global admins can create partners.
- Partner admins can only manage users within their partner.

### 3. API links per user (if ever needed)

If in the future you need **per-user API links**:

- Introduce a new entity `UserApiLink` or add `user_id` to `partner_api_link`.
- Adjust relationships accordingly (ManyToOne or ManyToMany).

---

## Change Log

- **2026-09-12**
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
