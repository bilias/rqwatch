<?php
// Rqwatch
// Copyright (C) 2026 Giannis Kapetanakis
// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at http://mozilla.org/MPL/2.0/.

declare(strict_types=1);

// Fuzz target: Helper::extract_mail_relays() on a header block (qa/fuzz.sh).
// Beyond what the fuzzer catches itself (an Error, a notice or warning, a
// run over the timeout), every relay must carry a valid IP, once.

use App\Utils\Helper;

require __DIR__ . '/bootstrap.php';

/** @var \PhpFuzzer\Config $config */
$config->setTarget(static function (string $input): void {
	$seen = [];
	foreach (Helper::extract_mail_relays($input) as $relay) {
		if (filter_var($relay['ip'], FILTER_VALIDATE_IP) === false) {
			throw new Error('relay IP is not an IP: ' . var_export($relay['ip'], true));
		}
		if (isset($seen[$relay['ip']])) {
			throw new Error('relay IP listed twice: ' . $relay['ip']);
		}
		$seen[$relay['ip']] = true;
	}
});
$config->setMaxLen(4096);
$config->addDictionary(__DIR__ . '/extract-mail-relays.dict');
