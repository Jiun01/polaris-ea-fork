-- E2E seed: two tenants (A = e2e-portal, B = tenant-b) for isolation testing

SET @A_UUID = 'aaaaaaaa-0000-0000-0000-000000000001';
SET @B_UUID = 'bbbbbbbb-0000-0000-0000-000000000001';

-- Slug registry
INSERT INTO ea_polaris_tenants (tenant_id, slug, status) VALUES
  (@A_UUID, 'e2e-portal', 'active'),
  (@B_UUID, 'tenant-b',   'active')
ON DUPLICATE KEY UPDATE slug=VALUES(slug), status=VALUES(status);

-- EA settings per tenant (required by GlobalVariables / Booking page JS init).
-- Source: the installer seeds a row with tenant_id='' (empty string, not NULL).
-- INSERT IGNORE: safe to run multiple times.
INSERT IGNORE INTO ea_settings (tenant_id, name, value)
  SELECT @A_UUID, name, value FROM ea_settings WHERE tenant_id = '';

INSERT IGNORE INTO ea_settings (tenant_id, name, value)
  SELECT @B_UUID, name, value FROM ea_settings WHERE tenant_id = '';

-- Roles (global, already seeded by EA installer)
SET @PROVIDER_ROLE = (SELECT id FROM ea_roles WHERE slug='provider' LIMIT 1);
SET @CUSTOMER_ROLE = (SELECT id FROM ea_roles WHERE slug='customer' LIMIT 1);
SET @ADMIN_ROLE    = (SELECT id FROM ea_roles WHERE slug='admin' LIMIT 1);

-- Provider for Tenant A
INSERT INTO ea_users (tenant_id, first_name, last_name, email, phone_number, address, city, zip_code, notes, is_private, id_roles, language, mobile_number, state, timezone, ldap_dn, create_datetime, update_datetime)
VALUES (@A_UUID, 'Alice', 'Provider', 'alice@e2e-portal.test', '+60111111111', '', '', '', '', 0, @PROVIDER_ROLE, 'english', '', '', 'Asia/Kuala_Lumpur', '', NOW(), NOW())
ON DUPLICATE KEY UPDATE first_name=VALUES(first_name);
SET @A_PROVIDER_ID = LAST_INSERT_ID();

-- Provider for Tenant B
INSERT INTO ea_users (tenant_id, first_name, last_name, email, phone_number, address, city, zip_code, notes, is_private, id_roles, language, mobile_number, state, timezone, ldap_dn, create_datetime, update_datetime)
VALUES (@B_UUID, 'Bob', 'Provider', 'bob@tenant-b.test', '+60222222222', '', '', '', '', 0, @PROVIDER_ROLE, 'english', '', '', 'Asia/Kuala_Lumpur', '', NOW(), NOW())
ON DUPLICATE KEY UPDATE first_name=VALUES(first_name);
SET @B_PROVIDER_ID = LAST_INSERT_ID();

-- Service for Tenant A
INSERT INTO ea_services (tenant_id, name, duration, price, currency, description, location, slot_interval, attendants_number, is_private)
VALUES (@A_UUID, 'E2E-Service-A', 60, 0, 'MYR', 'Test service A', '', 15, 1, 0)
ON DUPLICATE KEY UPDATE name=VALUES(name);
SET @A_SERVICE_ID = LAST_INSERT_ID();

-- Service for Tenant B
INSERT INTO ea_services (tenant_id, name, duration, price, currency, description, location, slot_interval, attendants_number, is_private)
VALUES (@B_UUID, 'B-Only-Service', 60, 0, 'MYR', 'Should never leak to A', '', 15, 1, 0)
ON DUPLICATE KEY UPDATE name=VALUES(name);
SET @B_SERVICE_ID = LAST_INSERT_ID();

-- Working plan: Mon-Fri 09:00-18:00, lunch break 13:00-14:00, weekends off.
-- Required by Availability library; NULL working_plan = no available hours shown.
SET @WORKING_PLAN = '{"sunday":null,"monday":{"start":"09:00","end":"18:00","breaks":[{"start":"13:00","end":"14:00"}]},"tuesday":{"start":"09:00","end":"18:00","breaks":[{"start":"13:00","end":"14:00"}]},"wednesday":{"start":"09:00","end":"18:00","breaks":[{"start":"13:00","end":"14:00"}]},"thursday":{"start":"09:00","end":"18:00","breaks":[{"start":"13:00","end":"14:00"}]},"friday":{"start":"09:00","end":"18:00","breaks":[{"start":"13:00","end":"14:00"}]},"saturday":null}';

-- user_settings for providers (required by Providers_model::get_settings)
INSERT INTO ea_user_settings (id_users, tenant_id, username, working_plan)
VALUES (@A_PROVIDER_ID, @A_UUID, 'alice', @WORKING_PLAN),
       (@B_PROVIDER_ID, @B_UUID, 'bob',   @WORKING_PLAN)
ON DUPLICATE KEY UPDATE username=VALUES(username), working_plan=VALUES(working_plan);

-- Link provider to service
INSERT IGNORE INTO ea_services_providers (id_users, id_services) VALUES (@A_PROVIDER_ID, @A_SERVICE_ID);
INSERT IGNORE INTO ea_services_providers (id_users, id_services) VALUES (@B_PROVIDER_ID, @B_SERVICE_ID);

-- Customer for Tenant A (for appointment)
INSERT INTO ea_users (tenant_id, first_name, last_name, email, phone_number, address, city, zip_code, notes, is_private, id_roles, language, mobile_number, state, timezone, ldap_dn, create_datetime, update_datetime)
VALUES (@A_UUID, 'Customer', 'A', 'customer@e2e-portal.test', '+60133333333', '', '', '', '', 0, @CUSTOMER_ROLE, 'english', '', '', 'Asia/Kuala_Lumpur', '', NOW(), NOW())
ON DUPLICATE KEY UPDATE first_name=VALUES(first_name);
SET @A_CUSTOMER_ID = LAST_INSERT_ID();

-- user_settings for Customer A
INSERT INTO ea_user_settings (id_users, tenant_id, username)
VALUES (@A_CUSTOMER_ID, @A_UUID, 'customer_a')
ON DUPLICATE KEY UPDATE username=VALUES(username);

-- Appointment for Tenant A (future date)
INSERT INTO ea_appointments (tenant_id, book_datetime, start_datetime, end_datetime, location, hash, notes, is_unavailability, id_services, id_users_provider, id_users_customer, create_datetime, update_datetime)
VALUES (@A_UUID, NOW(), DATE_ADD(NOW(), INTERVAL 2 DAY), DATE_ADD(NOW(), INTERVAL 2 DAY) + INTERVAL 1 HOUR, '', MD5(RAND()), '', 0, @A_SERVICE_ID, @A_PROVIDER_ID, @A_CUSTOMER_ID, NOW(), NOW());
SET @A_APPT_ID = LAST_INSERT_ID();

-- Appointment for Tenant B
INSERT INTO ea_users (tenant_id, first_name, last_name, email, phone_number, address, city, zip_code, notes, is_private, id_roles, language, mobile_number, state, timezone, ldap_dn, create_datetime, update_datetime)
VALUES (@B_UUID, 'Customer', 'B', 'customer@tenant-b.test', '+60144444444', '', '', '', '', 0, @CUSTOMER_ROLE, 'english', '', '', 'Asia/Kuala_Lumpur', '', NOW(), NOW())
ON DUPLICATE KEY UPDATE first_name=VALUES(first_name);
SET @B_CUSTOMER_ID = LAST_INSERT_ID();

-- user_settings for Customer B
INSERT INTO ea_user_settings (id_users, tenant_id, username)
VALUES (@B_CUSTOMER_ID, @B_UUID, 'customer_b')
ON DUPLICATE KEY UPDATE username=VALUES(username);

INSERT INTO ea_appointments (tenant_id, book_datetime, start_datetime, end_datetime, location, hash, notes, is_unavailability, id_services, id_users_provider, id_users_customer, create_datetime, update_datetime)
VALUES (@B_UUID, NOW(), DATE_ADD(NOW(), INTERVAL 3 DAY), DATE_ADD(NOW(), INTERVAL 3 DAY) + INTERVAL 1 HOUR, '', MD5(RAND()), '', 0, @B_SERVICE_ID, @B_PROVIDER_ID, @B_CUSTOMER_ID, NOW(), NOW());
SET @B_APPT_ID = LAST_INSERT_ID();

-- Summary
SELECT
  'Tenant A' AS tenant, @A_UUID AS uuid, @A_SERVICE_ID AS service_id, @A_PROVIDER_ID AS provider_id, @A_APPT_ID AS appt_id
UNION ALL
SELECT
  'Tenant B', @B_UUID, @B_SERVICE_ID, @B_PROVIDER_ID, @B_APPT_ID;
