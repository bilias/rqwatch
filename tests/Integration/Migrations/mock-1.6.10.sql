# MigrationsTest's mock data, in the v1.6.10 schema: rows each migration
# has to move, derive, keep or remove

# disable_notifications 0 becomes NULL (follow the default), 1 stays
INSERT INTO users (id, username, email, disable_notifications, is_admin, password) VALUES
(1, 'admin', 'admin@example.org', 0, 1, 'x'),
(2, 'alice', 'alice@example.org', 1, 0, 'x'),
(3, 'bob', 'bob@example.org', 0, 0, 'x');

# a duplicate alias, removed before the unique key
INSERT INTO mail_aliases (user_id, alias) VALUES
(2, 'alias-alice@example.org'),
(2, 'alias-alice@example.org'),
(3, 'alias-bob@example.org');

# entry 2 belongs to no user, removed before the foreign key
INSERT INTO maps_combined (id, map_name, user_id, mail_from, rcpt_to) VALUES
(1, 'mail_from_rcpt_to_whitelist', 2, 'friend@example.net', 'alice@example.org'),
(2, 'mail_from_rcpt_to_whitelist', 99, 'ghost@example.net', 'gone@example.org');

INSERT INTO custom_map_config (id, map_name, map_description, field_name, field_label) VALUES
(1, 'my_subject_regex', 'Subject regex', 'pattern', 'Pattern');
INSERT INTO maps_custom (id, map_name, pattern) VALUES
(1, 'my_subject_regex', '/^win big/i');

# 1: a virus (one mail_log_viruses row), mixed-case recipients, fuzzy hashes
# 2: rcpt_to unknown (no recipient row), never stored
# 3: a repeated recipient and unknown, notified
# 4: learned mail 1's hash after it was learned (one fuzzy hit link);
#    stored once, now expired (ever_stored from mail_location)
INSERT INTO mail_logs (id, qid, server, subject, score, action, symbols, has_virus, fuzzy_hashes, ip,
	mail_from, mime_from, rcpt_to, mime_to, size, mail_stored, mail_location, notified, headers, message_id, created_at) VALUES
(1, 'QID1', 'mx1', 'one', 20, 'reject',
	'[{"name":"CLAM_VIRUS","group":"antivirus","score":20,"options":["Eicar-Signature"]},{"name":"BAYES_SPAM","group":"bayes","score":3,"options":["99%"]}]',
	1, '["1:aaaa", "2:bbbb"]', '192.0.2.1', 'a@example.net', 'a@example.net',
	'Alice@Example.org, bob@example.org', 'alice@example.org', 1000, 1, '/q/1.eml', 0, 'Subject: one', '<1@example.net>', '2026-01-05 10:00:00'),
(2, 'QID2', 'mx1', 'two', 6, 'add header',
	'[{"name":"BAYES_SPAM","group":"bayes","score":3,"options":["80%"]}]',
	0, NULL, '192.0.2.2', 'b@example.net', 'b@example.net', 'unknown', 'x@example.org', 500, 0, NULL, 0, 'Subject: two', '<2@example.net>', '2026-02-01 08:15:00'),
(3, 'QID3', 'mx2', 'three', 15, 'reject', '[]', 0, NULL, '192.0.2.3', 'c@example.net', 'c@example.net',
	'bob@example.org, unknown, Bob@Example.org', 'bob@example.org', 700, 1, '/q/3.eml', 1, 'Subject: three', '<3@example.net>', '2025-12-31 23:30:00'),
(4, 'QID4', 'mx1', 'four', 12, 'reject', '[]', 0, '["1:aaaa"]', '192.0.2.4', 'd@example.net', 'd@example.net',
	'alice@example.org', 'alice@example.org', 800, 0, '/q/4.eml', 0, 'Subject: four', '<4@example.net>', '2026-01-10 09:00:00');
