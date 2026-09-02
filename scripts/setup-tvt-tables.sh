#!/bin/bash
# Setup script for TVT route tables
# This script will:
# 1. Create the 3 new TVT route tables
# 2. Create doctrine_migration_versions table
# 3. Mark existing migrations as executed
# 4. Run the data migration

echo "=================================="
echo "Setting up TVT Route Tables"
echo "=================================="
echo ""

# Step 1: Create the 3 new tables
echo "Step 1: Creating new TVT route tables..."
php bin/console dbal:run-sql <<'SQL'
CREATE TABLE IF NOT EXISTS waze_tvt_route_definition (
    id INT AUTO_INCREMENT NOT NULL,
    route_id VARCHAR(255) NOT NULL,
    name VARCHAR(255) DEFAULT NULL,
    bbox TEXT DEFAULT NULL,
    line TEXT DEFAULT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE INDEX UNIQ_route_id (route_id),
    PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;

CREATE TABLE IF NOT EXISTS waze_tvt_route_execution (
    id INT AUTO_INCREMENT NOT NULL,
    route_definition_id INT NOT NULL,
    timestamp DATETIME DEFAULT NULL,
    duration INT DEFAULT NULL,
    length INT DEFAULT NULL,
    irregularities INT DEFAULT 0 NOT NULL,
    traffic_jams INT DEFAULT 0 NOT NULL,
    avg_speed FLOAT DEFAULT NULL,
    coords TEXT DEFAULT NULL,
    created_at DATETIME NOT NULL,
    INDEX IDX_route_definition (route_definition_id),
    PRIMARY KEY(id),
    CONSTRAINT FK_route_definition FOREIGN KEY (route_definition_id) REFERENCES waze_tvt_route_definition(id) ON DELETE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;

CREATE TABLE IF NOT EXISTS waze_tvt_route_execution_coord (
    id INT AUTO_INCREMENT NOT NULL,
    execution_id INT NOT NULL,
    position INT NOT NULL,
    lat FLOAT NOT NULL,
    lng FLOAT NOT NULL,
    speed FLOAT DEFAULT NULL,
    level INT DEFAULT NULL,
    INDEX IDX_execution (execution_id),
    PRIMARY KEY(id),
    CONSTRAINT FK_execution FOREIGN KEY (execution_id) REFERENCES waze_tvt_route_execution(id) ON DELETE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;
SQL

echo "✓ Tables created successfully"
echo ""

# Step 2: Create doctrine_migration_versions table
echo "Step 2: Creating doctrine_migration_versions table..."
php bin/console dbal:run-sql <<'SQL'
CREATE TABLE IF NOT EXISTS doctrine_migration_versions (
    version VARCHAR(191) NOT NULL,
    executed_at DATETIME DEFAULT NULL,
    execution_time INT DEFAULT NULL,
    PRIMARY KEY(version)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;
SQL

echo "✓ Migration metadata table created"
echo ""

# Step 3: Mark existing migrations as executed
echo "Step 3: Marking existing migrations as executed..."
php bin/console dbal:run-sql <<'SQL'
INSERT INTO doctrine_migration_versions (version, executed_at, execution_time) VALUES
('DoctrineMigrations\\Version20260716_add_refresh_interval', '2026-07-16 20:11:47', 8),
('DoctrineMigrations\\Version20260716_roles', '2026-07-16 19:06:56', 18),
('DoctrineMigrations\\Version20260716181543', '2026-07-16 18:16:07', 245),
('DoctrineMigrations\\Version20260718_add_reset_token', '2026-07-20 03:26:32', 59),
('DoctrineMigrations\\Version20260720_add_cifs_schedule_lane_impact', '2026-07-20 03:39:45', 53),
('DoctrineMigrations\\Version20260720_create_waze_alert_type', '2026-07-22 01:05:59', 261),
('DoctrineMigrations\\Version20260721230000', '2026-07-22 01:06:00', 268),
('DoctrineMigrations\\Version20260721235900_add_wazers_levels', '2026-07-22 01:33:11', 37),
('DoctrineMigrations\\Version20260827204413', '2026-08-27 20:58:51', 17)
ON DUPLICATE KEY UPDATE executed_at=VALUES(executed_at);
SQL

echo "✓ Migrations marked as executed"
echo ""

# Step 4: Run data migration
echo "Step 4: Running TVT routes data migration..."
php bin/console migrate:tvt-routes

echo ""
echo "=================================="
echo "Setup completed successfully!"
echo "=================================="
echo ""
echo "Next steps:"
echo "1. Verify tables in phpMyAdmin"
echo "2. Run: php bin/console waze:collect-tvt"
echo "3. Check data: php bin/console dbal:run-sql 'SELECT COUNT(*) FROM waze_tvt_route_definition'"
