-- Map entries for tests/Import, one per map the tests use, owned by the admin
-- qa/import-tests.sh creates. cron:updatemapfiles writes them to web/maps/.
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
