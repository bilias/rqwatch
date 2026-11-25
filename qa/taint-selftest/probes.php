<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 Taint self-test probes (qa/taint-selftest.sh). Never loaded or run: only
 analysed, with qa/taint-selftest/psalm.xml. Each probe line ends with
 "// expect <IssueType>" (must be reported) or "// expect clean" (must not);
 the script fails on a missing or an extra taint issue. A template probe is
 reported on its render line here, not in the .twig file.
 Add a probe with every new source, flow rule, filter or escape.
*/

use App\Models\MailLog;
use App\Utils\Helper;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

function taintSelfTest(MailLog $log, Environment $twig): void {
	// MailTaintSources: property nodes, column reads, whole-model reads, collection flow
	echo $log->subject; // expect TaintedHtml
	echo $log->recipients->pluck('recipient_email')->all()[0]; // expect TaintedHtml
	echo $log->toArray()['subject'] ?? ''; // expect TaintedHtml
	echo MailLog::query()->value('subject'); // expect TaintedHtml
	echo MailLog::query()->value('qid'); // expect clean
	echo $log->recipients->count(); // expect clean

	// .env: Helper::env_string() reads the operator's settings; $_ENV itself is a source (Psalm 7.19)
	echo Helper::env_string('PROBE') ?? ''; // expect clean
	$env = $_ENV['PROBE'] ?? '';
	if (is_string($env)) {
		echo $env; // expect TaintedHtml
	}

	// Twig: attribute to property node, autoescape, request input, is_safe filters' escapes
	new Response($twig->render('raw_subject.twig', ['log' => $log])); // expect TaintedHtml
	new Response($twig->render('escaped_subject.twig', ['log' => $log])); // expect clean
	new Response($twig->render('raw_input.twig', ['x' => $_GET['x']])); // expect TaintedHtml
	// control: reported only if the analyzer has RqwatchExtension (a template with an
	// unknown filter or function is skipped, which would leave the escape probes below
	// trivially clean); truncate() returns text, so the taint goes through (nf() returns
	// a number, which Psalm 7.19 lets carry no html taint)
	new Response($twig->render('extension_loaded.twig', ['x' => $_GET['x']])); // expect TaintedHtml
	new Response($twig->render('linkify.twig', ['x' => $_GET['x']])); // expect clean
	// control for linkify's url_scheme escape: an href escaped for HTML only
	$href = $_GET['x'] ?? '';
	if (is_string($href)) {
		echo '<a href="' . htmlspecialchars($href, ENT_QUOTES) . '">x</a>'; // expect TaintedUrlScheme
	}
	new Response($twig->render('js_json.twig', ['x' => $_GET['x']])); // expect clean
	new Response($twig->render('mark_homoglyphs.twig', ['x' => $_GET['x']])); // expect clean
}
