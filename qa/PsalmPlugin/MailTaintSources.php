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

 A mail model's data also leaves it as plain values, which no property
 node feeds, so these calls are sources too (afterMethodCallAnalysis()):
 - a column read naming a listed attribute, or one named only at run
   time: pluck(), value(), getAttribute(), getOriginal(), getRawOriginal()
 - a whole model as an array or JSON: toArray(), attributesToArray(),
   getAttributes(), getOriginal(), getRawOriginal(), jsonSerialize(),
   toJson()
 on a mail model, or on a builder, relation, collection or paginator of
 them. And since vendor code is not analysed for taint, what a method of
 a collection or paginator returns carries the taint of the collection
 (a plucked collection's ->all(), ->implode(), ...), for any taint.

 FIELDS is the security decision. Left out: what rqwatch, the MTA or
 rspamd set (ids, qid, server, action, score, flags, dates, sizes, paths,
 hashes, virus names from the AV, learned_by).
*/

namespace Qa\PsalmPlugin;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Pagination\AbstractCursorPaginator;
use Illuminate\Pagination\AbstractPaginator;
use Illuminate\Support\Enumerable;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Scalar\String_;
use Psalm\CodeLocation;
use Psalm\Codebase;
use Psalm\Internal\DataFlow\DataFlowNode;
use Psalm\Plugin\EventHandler\AfterCodebasePopulatedInterface;
use Psalm\Plugin\EventHandler\AfterMethodCallAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterCodebasePopulatedEvent;
use Psalm\Plugin\EventHandler\Event\AfterMethodCallAnalysisEvent;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;
use RuntimeException;

final class MailTaintSources implements AfterCodebasePopulatedInterface, AfterMethodCallAnalysisInterface
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

	// methods reading the column named by their first argument
	private const array COLUMN_READS = ['pluck', 'value', 'getattribute', 'getoriginal', 'getraworiginal'];

	// methods giving all of a model's attributes (getOriginal() and getRawOriginal() without a column)
	private const array MODEL_READS = ['toarray', 'attributestoarray', 'getattributes', 'jsonserialize', 'tojson'];

	// classes whose methods give what the collection holds
	private const array COLLECTIONS = [Enumerable::class, AbstractPaginator::class, AbstractCursorPaginator::class];

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

	#[\Override]
	public static function afterMethodCallAnalysis(AfterMethodCallAnalysisEvent $event): void {
		$codebase = $event->getCodebase();
		$expr = $event->getExpr();
		$type = $event->getReturnTypeCandidate();
		if ($codebase->taint_flow_graph === null || $type === null
			|| !$expr instanceof MethodCall || !$expr->name instanceof Identifier
		) {
			return;
		}
		$receiver = $event->getStatementsSource()->getNodeTypeProvider()->getType($expr->var);
		if ($receiver === null) {
			return;
		}

		// what a collection's method gives comes from the collection
		if ($receiver->parent_nodes !== [] && self::isCollection($codebase, $receiver)) {
			$type = $type->addParentNodes($receiver->parent_nodes);
		}

		$model = self::mailModel($codebase, $receiver);
		if ($model !== null && self::readsMailFields($model, strtolower($expr->name->name), $expr)) {
			$location = new CodeLocation($event->getStatementsSource(), $expr);
			$type = $codebase->addTaintSource($type, "mail $model->{$expr->name->name}()", $location);
		}

		$event->setReturnTypeCandidate($type);
	}

	// whether $method of $model gives a listed attribute: see the header
	private static function readsMailFields(string $model, string $method, MethodCall $call): bool {
		if (in_array($method, self::MODEL_READS, true)) {
			return true;
		}
		if (!in_array($method, self::COLUMN_READS, true)) {
			return false;
		}
		$column = $call->getArgs()[0]->value ?? null;
		if ($column === null) {
			// getOriginal() and getRawOriginal() without a column give them all
			return $method === 'getoriginal' || $method === 'getraworiginal';
		}
		// a column named at run time may be any of them
		return !$column instanceof String_ || in_array($column->value, self::FIELDS[$model], true);
	}

	// the mail model of FIELDS that $type is, or that the builder, relation, collection or paginator it is holds
	private static function mailModel(Codebase $codebase, Union $type): ?string {
		foreach ($type->getAtomicTypes() as $atomic) {
			if (!$atomic instanceof TNamedObject) {
				continue;
			}
			$params = $atomic instanceof TGenericObject ? $atomic->type_params : [];
			// a relation's first parameter is the related model, the others the declaring one
			if ($params !== [] && $codebase->classExtends($atomic->value, Relation::class)) {
				$params = [$params[0]];
			}
			$candidates = [$atomic];
			foreach ($params as $param) {
				$candidates = [...$candidates, ...array_values($param->getAtomicTypes())];
			}
			foreach ($candidates as $candidate) {
				if (!$candidate instanceof TNamedObject) {
					continue;
				}
				foreach (array_keys(self::FIELDS) as $model) {
					if (strtolower($candidate->value) === strtolower($model)) {
						return $model;
					}
				}
			}
		}
		return null;
	}

	private static function isCollection(Codebase $codebase, Union $type): bool {
		foreach ($type->getAtomicTypes() as $atomic) {
			if (!$atomic instanceof TNamedObject || !$codebase->classOrInterfaceExists($atomic->value)) {
				continue;
			}
			foreach (self::COLLECTIONS as $collection) {
				if ($codebase->classExtendsOrImplements($atomic->value, $collection)
					|| strtolower($atomic->value) === strtolower($collection)
				) {
					return true;
				}
			}
		}
		return false;
	}
}
