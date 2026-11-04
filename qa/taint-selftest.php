<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

// Compare a Psalm JSON report with the "// expect" markers in probes.php.
// Usage: php qa/taint-selftest.php < report.json (run by qa/taint-selftest.sh)

$probes = 'qa/taint-selftest/probes.php';
$report = json_decode((string) stream_get_contents(STDIN), true);
if (!is_array($report)) {
	fwrite(STDERR, "taint self-test: no Psalm report (did Psalm crash?)\n");
	exit(1);
}

// line => expected issue type, or 'clean'
$expected = [];
foreach (file($probes) ?: [] as $i => $line) {
	if (preg_match('~// expect (\w+)$~D', rtrim($line), $m)) {
		$expected[$i + 1] = $m[1];
	}
}

// "file:line" => issue types reported there
$reported = [];
foreach ($report as $issue) {
	if (is_array($issue) && str_starts_with((string) $issue['type'], 'Tainted')) {
		$reported[$issue['file_name'] . ':' . $issue['line_from']][] = $issue['type'];
	}
}

$failures = [];
foreach ($expected as $line => $type) {
	$types = $reported["$probes:$line"] ?? [];
	unset($reported["$probes:$line"]);
	if ($type === 'clean' && $types !== []) {
		$failures[] = "$probes:$line: expected clean, got " . implode(', ', $types) . ' (an escape stopped working)';
	} elseif ($type !== 'clean' && !in_array($type, $types, true)) {
		$failures[] = "$probes:$line: expected $type, not reported (a source or flow stopped working)";
	}
}
foreach ($reported as $where => $types) {
	$failures[] = "$where: unexpected " . implode(', ', $types);
}

if ($failures !== []) {
	fwrite(STDERR, "taint self-test failed:\n  " . implode("\n  ", $failures) . "\n");
	exit(1);
}
echo 'taint self-test: ' . count($expected) . " probes as expected\n";
