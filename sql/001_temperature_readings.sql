CREATE TABLE IF NOT EXISTS temperature_readings (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    sensor_id TEXT NOT NULL CHECK (sensor_id IN ('freezer', 'refrigerator')),
    temperature DOUBLE PRECISION NOT NULL
        CHECK (temperature > '-Infinity'::DOUBLE PRECISION AND temperature < 'Infinity'::DOUBLE PRECISION),
    received_at TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp()
);
