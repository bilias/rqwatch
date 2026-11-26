-- Map entries for tests/EndToEnd, one per map the tests use, owned by the admin
-- qa/e2e-tests.sh creates. cron:updatemapfiles writes them to web/maps/.
INSERT INTO maps_combined (map_name, user_id, ip, mail_from, rcpt_to, mime_from)
SELECT m.map_name, u.id, m.ip, m.mail_from, m.rcpt_to, m.mime_from
FROM users u, (
	SELECT 'ip_blacklist' AS map_name, '198.51.100.0/24' AS ip, NULL AS mail_from, NULL AS rcpt_to, NULL AS mime_from
	UNION ALL SELECT 'mail_from_blacklist', NULL, 'smtp-bl@sender.test', NULL, NULL
	UNION ALL SELECT 'mime_from_blacklist', NULL, NULL, NULL, 'mime-bl@sender.test'
	UNION ALL SELECT 'mail_from_whitelist', NULL, 'smtp-wl@sender.test', NULL, NULL
	UNION ALL SELECT 'mail_from_rcpt_to_blacklist', NULL, 'pair-bl@sender.test', 'bob@rqwatch.test', NULL
) m
WHERE u.username = 'admin';

-- A site's own url maps (custom maps, the rules in
-- tests/EndToEnd/rspamd/local.d/multimap-url.conf), each entry as the map form
-- stores it: MapInventory::canonicalUrlHostEntries() of what was typed.
-- tests/EndToEnd/Import/UrlMapsTest.php checks both.
INSERT INTO custom_map_config (map_name, map_description, field_name, field_label, symbols) VALUES
	('body_url_host', 'Body URL (host)', 'url', 'URL', 'RQWATCH_BODY_URL_HOST'),
	('body_url_full', 'Body URL (full)', 'url', 'URL', 'RQWATCH_BODY_URL_FULL');
INSERT INTO maps_custom (map_name, pattern) VALUES
	('body_url_host', '::ffff:217.60.237.114'),
	('body_url_host', '192.0.2.44'),
	('body_url_full', 'http://[::ffff:217.60.237.114]/q9x-oml?lang=el');
