BEGIN;

INSERT INTO callcenter_alarm_rules (
    dept_id,
    alarm_key,
    enabled,
    threshold_value,
    warning_value
)
SELECT
    tenant.dept_id,
    alarm.alarm_key,
    TRUE,
    NULL,
    NULL
FROM astera_tenants AS tenant
CROSS JOIN (
    VALUES ('trunk_error'::VARCHAR(80)), ('trunk_down'::VARCHAR(80))
) AS alarm(alarm_key)
ON CONFLICT (dept_id, alarm_key) DO UPDATE
SET enabled = TRUE,
    updated_at = now();

COMMIT;
