-- Seed admin user for tenant A (e2e-portal) — password: admin123
SET @A_UUID = 'aaaaaaaa-0000-0000-0000-000000000001';
SET @ADMIN_ROLE = (SELECT id FROM ea_roles WHERE slug='admin' LIMIT 1);

INSERT INTO ea_users (tenant_id, first_name, last_name, email, phone_number, address, city, zip_code, notes, is_private, id_roles, language, mobile_number, state, timezone, ldap_dn, create_datetime, update_datetime)
VALUES (@A_UUID, 'Admin', 'E2E', 'admin@e2e-portal.test', '', '', '', '', '', 0, @ADMIN_ROLE, 'english', '', '', 'Asia/Kuala_Lumpur', '', NOW(), NOW())
ON DUPLICATE KEY UPDATE first_name=VALUES(first_name);

SET @ADMIN_ID = LAST_INSERT_ID();

INSERT INTO ea_user_settings (id_users, tenant_id, username, password, salt, working_plan, notifications, google_sync, google_token, google_calendar, sync_future_days, sync_past_days)
VALUES (@ADMIN_ID, @A_UUID, 'admin_e2e', '5b020fc2257a4275d3c452a6ae9146a2402e827ca901cc936c98f97109fa0538', '0ba8854b094b212e28e1639ae0bb39c8', NULL, 1, 0, NULL, NULL, 30, 30)
ON DUPLICATE KEY UPDATE username=VALUES(username), password=VALUES(password), salt=VALUES(salt);

SELECT @ADMIN_ID AS admin_id;
