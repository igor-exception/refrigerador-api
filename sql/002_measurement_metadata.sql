-- Incremental e reaplicável. Histórico permanece com metadados NULL, sem backfill fictício.
ALTER TABLE temperature_readings
    ADD COLUMN IF NOT EXISTS device_id VARCHAR(64),
    ADD COLUMN IF NOT EXISTS measurement_id VARCHAR(128),
    ADD COLUMN IF NOT EXISTS measured_at TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS time_quality VARCHAR(9),
    ADD COLUMN IF NOT EXISTS wifi_rssi SMALLINT,
    ADD COLUMN IF NOT EXISTS uptime_ms BIGINT,
    ADD COLUMN IF NOT EXISTS observation TEXT;

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint
                   WHERE conrelid = 'temperature_readings'::regclass AND conname = 'measurement_metadata_valid') THEN
        ALTER TABLE temperature_readings ADD CONSTRAINT measurement_metadata_valid CHECK (
            (device_id IS NULL AND measurement_id IS NULL AND measured_at IS NULL
             AND time_quality IS NULL AND wifi_rssi IS NULL AND uptime_ms IS NULL AND observation IS NULL)
            OR
            (device_id IS NOT NULL AND device_id ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,63}$'
             AND measurement_id IS NOT NULL AND measurement_id ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'
             AND time_quality IS NOT NULL AND time_quality IN ('synced', 'estimated', 'unknown')
             AND ((time_quality = 'unknown' AND measured_at IS NULL)
                  OR (time_quality IN ('synced', 'estimated') AND measured_at IS NOT NULL AND isfinite(measured_at)))
             AND (wifi_rssi IS NULL OR wifi_rssi BETWEEN -127 AND 0)
             AND uptime_ms IS NOT NULL AND uptime_ms BETWEEN 0 AND 9007199254740991
             AND (observation IS NULL OR char_length(observation) <= 1024))
        );
    END IF;
END $$;

-- NULLs distintos preservam o comportamento do contrato legado.
CREATE UNIQUE INDEX IF NOT EXISTS temperature_readings_device_measurement_uidx
    ON temperature_readings (device_id, measurement_id);

-- Sustenta o GET das últimas 50 linhas e a paginação estável pelo mesmo par.
CREATE INDEX IF NOT EXISTS temperature_readings_received_at_id_idx
    ON temperature_readings (received_at DESC, id DESC);
