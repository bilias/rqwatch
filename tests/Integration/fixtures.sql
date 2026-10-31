-- Rqwatch
-- Copyright (C) 2026 Giannis Kapetanakis
-- This Source Code Form is subject to the terms of the Mozilla Public
-- License, v. 2.0. If a copy of the MPL was not distributed with this
-- file, You can obtain one at http://mozilla.org/MPL/2.0/.
--
-- Integration test data: alice (with an alias) and bob each own mails and map
-- entries; the users themselves are created by qa/integration-tests.sh.
-- @Q@ is replaced with the quarantine directory.

SET @alice = (SELECT id FROM users WHERE username = 'alice');
SET @bob   = (SELECT id FROM users WHERE username = 'bob');
SET @admin = (SELECT id FROM users WHERE username = 'admin');

INSERT INTO mail_aliases (user_id, alias) VALUES (@alice, 'alias-alice@example.org');

INSERT INTO mail_logs (id, qid, server, subject, score, action, ip, mail_from, mime_from, rcpt_to, mime_to, size, mail_stored, mail_location) VALUES
(101, 'QIDALICE101', 'mx1', 'SUBJ-ALICE-101', 15, 'reject', '192.0.2.11', 'sender-alice@example.net', 'sender-alice@example.net', 'alice@example.org', 'alice@example.org', 1000, 1, '@Q@/101.eml'),
(102, 'QIDBOB102',   'mx1', 'SUBJ-BOB-102',   15, 'reject', '192.0.2.12', 'sender-bob@example.net',   'sender-bob@example.net',   'bob@example.org',   'bob@example.org',   1000, 1, '@Q@/102.eml'),
(103, 'QIDALIAS103', 'mx1', 'SUBJ-ALIAS-103', 15, 'reject', '192.0.2.13', 'sender-alias@example.net', 'sender-alias@example.net', 'alias-alice@example.org', 'alias-alice@example.org', 1000, 1, '@Q@/103.eml');

INSERT INTO mail_log_recipients (mail_log_id, recipient_email) VALUES
(101, 'alice@example.org'),
(102, 'bob@example.org'),
(103, 'alias-alice@example.org');

INSERT INTO mail_log_data (mail_log_id, headers, symbols) VALUES
(101, 'Subject: SUBJ-ALICE-101', '{"TEST_SYMBOL": {"name": "TEST_SYMBOL", "score": 15}}'),
(102, 'Subject: SUBJ-BOB-102',   '{"TEST_SYMBOL": {"name": "TEST_SYMBOL", "score": 15}}'),
(103, 'Subject: SUBJ-ALIAS-103', '{"TEST_SYMBOL": {"name": "TEST_SYMBOL", "score": 15}}');

INSERT INTO maps_combined (id, map_name, user_id, mail_from, rcpt_to) VALUES
(201, 'mail_from_rcpt_to_whitelist', @alice, 'map-alice@example.net', 'alice@example.org'),
(202, 'mail_from_rcpt_to_whitelist', @bob,   'map-bob@example.net',   'bob@example.org');
