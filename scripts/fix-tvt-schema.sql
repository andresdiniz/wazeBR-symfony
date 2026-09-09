-- Fix waze_tvt_route_history schema
ALTER TABLE waze_tvt_route_history ADD COLUMN IF NOT EXISTS observed_at DATETIME NOT NULL;
ALTER TABLE waze_tvt_route_history ADD COLUMN IF NOT EXISTS travel_time_seconds INT DEFAULT NULL;
ALTER TABLE waze_tvt_route_history ADD COLUMN IF NOT EXISTS speed_kmh DECIMAL(8, 2) DEFAULT NULL;
ALTER TABLE waze_tvt_route_history ADD COLUMN IF NOT EXISTS delay_seconds INT DEFAULT NULL;
ALTER TABLE waze_tvt_route_history ADD COLUMN IF NOT EXISTS length_meters INT DEFAULT NULL;
ALTER TABLE waze_tvt_route_history ADD COLUMN IF NOT EXISTS status VARCHAR(40) DEFAULT NULL;
ALTER TABLE waze_tvt_route_history ADD COLUMN IF NOT EXISTS raw_metrics JSON DEFAULT NULL;
