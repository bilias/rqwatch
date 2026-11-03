<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 Taint sources for the taint analysis: the attributes of the mail models
 that hold what a mail's sender wrote, as stored in the database. The raw
 mail is already a source (PhpMimeMailParser in qa/gen-taint-stubs.php);
 this is the same data read back from mail_logs and its tables.

 Each attribute's property node (App\Models\MailLog::$subject) gets input
 taint, which every read of it carries: a PHP fetch ($log->subject) and a
 Twig attribute (log.subject). psalm/plugin-symfony resolves an attribute
 to that node only for a public or @property property, so the attributes
 are also made pseudo properties, with the types EloquentProperties gives
 them (register this hook after it).

 FIELDS is the security decision. Left out: what rqwatch, the MTA or
 rspamd set (ids, qid, server, action, score, flags, dates, sizes, paths,
 hashes, virus names from the AV, learned_by).
*/

namespace Qa\PsalmPlugin;

use Psalm\Internal\DataFlow\DataFlowNode;
use Psalm\Plugin\EventHandler\AfterCodebasePopulatedInterface;
use Psalm\Plugin\EventHandler\Event\AfterCodebasePopulatedEvent;
use RuntimeException;

final class MailTaintSources implements AfterCodebasePopulatedInterface
{
	/** @var array<class-string, list<string>> model => attributes from the mail's sender */
	private const array FIELDS = [
		\App\Models\MailLog::class => [
			// envelope and header addresses, subject, Message-ID
			'mail_from', 'mime_from', 'rcpt_to', 'mime_to', 'subject', 'message_id',
			// accessors over them and over mail_log_data and mail_log_recipients
			'mime_from_decoded', 'mail_recipients', 'headers', 'symbols', 'virus_from_symbol',
		],
		// headers, and rspamd's symbols, whose options quote the mail (URLs, names)
		\App\Models\MailLogData::class => ['headers', 'symbols'],
		\App\Models\MailLogRecipient::class => ['recipient_email'],
	];

	#[\Override]
	public static function afterCodebasePopulated(AfterCodebasePopulatedEvent $event): void {
		$codebase = $event->getCodebase();
		$graph = $codebase->taint_flow_graph;
		$taints = $codebase->getInputTaints();

		foreach (self::FIELDS as $class => $fields) {
			$storage = $codebase->classlike_storage_provider->get($class);
			foreach ($fields as $field) {
				$types = EloquentProperties::property($class, $field)
					?? throw new RuntimeException("MailTaintSources: $class has no attribute $field");

				// a pseudo property, so that the Twig analysis resolves log.$field to it
				$storage->pseudo_property_get_types['$' . $field] = $types['read'];
				if (!$types['write']->isNever()) {
					$storage->pseudo_property_set_types['$' . $field] = $types['write'];
				}

				if ($graph === null || $storage->location === null) {
					continue;
				}
				$property = DataFlowNode::getForPropertyFetch($storage->name . '::$' . $field);
				$source = DataFlowNode::getForTaint("mail $storage->name::\$$field", $storage->location, $taints);
				$graph->addNode($property);
				$graph->addSource($source);
				$graph->addPath($source, $property, '=');
			}
		}
	}
}
