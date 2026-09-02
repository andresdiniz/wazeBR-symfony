-- Migration: Create TVT Route Definition, Execution and Coord tables
-- Date: 2026-09-01
-- Description: Creates new normalized structure for TVT routes to reduce database size

-- Drop old tables if they exist (optional - uncomment if needed)
-- DROP TABLE IF EXISTS waze_tvt_route_execution_coord;
-- DROP TABLE IF EXISTS waze_tvt_route_execution;
-- DROP TABLE IF EXISTS waze_tvt_route_definition;
-- DROP TABLE IF EXISTS waze_tvt_routes;
-- DROP TABLE IF EXISTS waze_tvt_route_history;
-- DROP TABLE IF EXISTS waze_tvt_route_history_coords;

-- 1. Create waze_tvt_route_definition table
-- Stores static route data (route_id, name, bbox, line) - one row per route
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

-- 2. Create waze_tvt_route_execution table
-- Stores historical execution data (one row per collection per route)
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

-- 3. Create waze_tvt_route_execution_coord table
-- Stores detailed coordinates for each execution (optional - for detailed analysis)
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

-- Insert a message in activity_log to track migration
-- INSERT INTO activity_log (action, description, created_at) VALUES ('migration', 'Created TVT route tables (definition, execution, coord)', NOW());
