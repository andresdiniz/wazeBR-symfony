-- Migration: Create waze_tvt_route and waze_tvt_route_history tables
-- Generated: 2026-09-02 23:00:00

-- Create waze_tvt_route table
CREATE TABLE IF NOT EXISTS waze_tvt_route (
    id INT AUTO_INCREMENT PRIMARY KEY,
    partner_id INT NOT NULL,
    waze_route_id VARCHAR(36) UNIQUE NOT NULL,
    name VARCHAR(255) DEFAULT NULL,
    from VARCHAR(50) DEFAULT NULL,
    to VARCHAR(50) DEFAULT NULL,
    start_lat DECIMAL(10, 6) DEFAULT NULL,
    start_lng DECIMAL(10, 6) DEFAULT NULL,
    end_lat DECIMAL(10, 6) DEFAULT NULL,
    end_lng DECIMAL(10, 6) DEFAULT NULL,
    direction VARCHAR(20) DEFAULT NULL,
    INDEX idx_partner (partner_id),
    INDEX idx_waze_route_id (waze_route_id),
    CONSTRAINT fk_waze_tvt_route_partner FOREIGN KEY (partner_id) REFERENCES partners(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create waze_tvt_route_history table
CREATE TABLE IF NOT EXISTS waze_tvt_route_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    route_id INT NOT NULL,
    captured_at DATETIME NOT NULL,
    bbox_min_lat DECIMAL(10, 6) DEFAULT NULL,
    bbox_min_lng DECIMAL(10, 6) DEFAULT NULL,
    bbox_max_lat DECIMAL(10, 6) DEFAULT NULL,
    bbox_max_lng DECIMAL(10, 6) DEFAULT NULL,
    coord_start_lat DECIMAL(10, 6) DEFAULT NULL,
    coord_start_lng DECIMAL(10, 6) DEFAULT NULL,
    coord_mid_lat DECIMAL(10, 6) DEFAULT NULL,
    coord_mid_lng DECIMAL(10, 6) DEFAULT NULL,
    coord_end_lat DECIMAL(10, 6) DEFAULT NULL,
    coord_end_lng DECIMAL(10, 6) DEFAULT NULL,
    original_point_count INT DEFAULT NULL,
    INDEX idx_route (route_id),
    INDEX idx_captured_at (captured_at),
    CONSTRAINT fk_waze_tvt_route_history_route FOREIGN KEY (route_id) REFERENCES waze_tvt_route(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add tvt_route_id column to waze_tvt_route_execution if not exists
ALTER TABLE waze_tvt_route_execution 
ADD COLUMN IF NOT EXISTS tvt_route_id INT DEFAULT NULL AFTER id,
ADD INDEX IF NOT EXISTS idx_tvt_route (tvt_route_id),
ADD CONSTRAINT IF NOT EXISTS fk_waze_tvt_route_execution_tvt_route 
    FOREIGN KEY (tvt_route_id) REFERENCES waze_tvt_route(id) ON DELETE SET NULL;
