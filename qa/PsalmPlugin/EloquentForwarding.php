<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 Calls Eloquent forwards by magic, which Psalm cannot follow:

 - Model::__callStatic() and Model::__call() hand any method the model
   does not declare to a new Eloquent\Builder<static> and return what it
   returns: MailLog::where() is Builder<MailLog>::where(), a
   Builder<MailLog>. Such methods are claimed for every model, with the
   parameters they have on Builder<ThatModel> (models are final, so no
   static). A @mixin would not do: Psalm types a mixin method returning
   $this as returning the host, the model.
 - Eloquent\Builder hands what it does not declare to its Query\Builder
   (its @mixin). Relation's @mixin is the generic Builder<TRelatedModel>,
   and Psalm follows no mixin of a generic mixin, so HasMany::orderBy()
   is unknown. Those Query\Builder methods are claimed for relations; a
   relation returns itself when its builder does, as the mixin assumes.

 Each call is typed by analysing the same call on the Builder<Model>
 Eloquent makes it on, so the types come from illuminate/database's own
 signatures and templates.
*/

namespace Qa\PsalmPlugin;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use Psalm\Codebase;
use Psalm\IssueBuffer;
use Psalm\Internal\Analyzer\ClassLikeAnalyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Call\MethodCallAnalyzer;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\Internal\MethodIdentifier;
use Psalm\Internal\Type\TemplateInferredTypeReplacer;
use Psalm\Internal\Type\TemplateResult;
use Psalm\Internal\Type\TypeExpander;
use Psalm\Plugin\EventHandler\AfterCodebasePopulatedInterface;
use Psalm\Plugin\EventHandler\Event\AfterCodebasePopulatedEvent;
use Psalm\Plugin\EventHandler\Event\MethodExistenceProviderEvent;
use Psalm\Plugin\EventHandler\Event\MethodParamsProviderEvent;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\Event\MethodVisibilityProviderEvent;
use Psalm\Storage\FunctionLikeParameter;
use Psalm\Type;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;
use ReflectionException;
use ReflectionMethod;

final class EloquentForwarding implements AfterCodebasePopulatedInterface
{
	/** @var array<string, ?string> class::method => builder class it is forwarded to */
	private static array $forwarded = [];

	/** @var array<string, bool> class::method => uses func_get_args() */
	private static array $readsAllArgs = [];

	#[\Override]
	public static function afterCodebasePopulated(AfterCodebasePopulatedEvent $event): void {
		$codebase = $event->getCodebase();
		$model = strtolower(Model::class);
		$relation = strtolower(Relation::class);

		foreach ($codebase->classlike_storage_provider::getAll() as $storage) {
			if (!isset($storage->parent_classes[$model]) && !isset($storage->parent_classes[$relation])) {
				continue;
			}
			$class = $storage->name;
			$codebase->methods->existence_provider->registerClosure($class, self::doesMethodExist(...));
			$codebase->methods->visibility_provider->registerClosure($class, self::isMethodVisible(...));
			$codebase->methods->params_provider->registerClosure($class, self::getMethodParams(...));
			$codebase->methods->return_type_provider->registerClosure($class, self::getMethodReturnType(...));
		}
	}

	public static function doesMethodExist(MethodExistenceProviderEvent $event): ?bool {
		$source = $event->getSource();
		if ($source === null) {
			return null;
		}
		return self::forwardedTo($source->getCodebase(), $event->getFqClasslikeName(), $event->getMethodNameLowercase()) !== null
			? true : null;
	}

	// forwarded through __call/__callStatic, so public
	public static function isMethodVisible(MethodVisibilityProviderEvent $event): ?bool {
		return self::forwardedTo($event->getSource()->getCodebase(), $event->getFqClasslikeName(), $event->getMethodNameLowercase()) !== null
			? true : null;
	}

	/** @return list<FunctionLikeParameter>|null */
	public static function getMethodParams(MethodParamsProviderEvent $event): ?array {
		$source = $event->getStatementsSource();
		if ($source === null) {
			return null;
		}
		$codebase = $source->getCodebase();
		$class = $event->getFqClasslikeName();
		$method = $event->getMethodNameLowercase();
		$target = self::forwardedTo($codebase, $class, $method);
		if ($target === null) {
			return null;
		}

		$id = new MethodIdentifier($target, strtolower($method));
		$storage = $codebase->methods->getStorage($id);
		$model = self::isModel($codebase, $class) ? $class : null;

		// the parameters as on Builder<Model>: TModel is the model, static the
		// builder, and the method's own templates their bounds (the return type
		// comes from analysing the real call, templates and all)
		$bounds = [];
		foreach ($storage->template_types ?? [] as $name => $byDefiner) {
			foreach ($byDefiner as $definer => $as) {
				$bounds[$name][$definer] = $as;
			}
		}
		$self = new TNamedObject($target);
		if ($model !== null && $target === Builder::class) {
			$bounds['TModel'][Builder::class] = new Union([new TNamedObject($model)]);
			$self = new TGenericObject(Builder::class, [new Union([new TNamedObject($model)])]);
		}
		$templates = new TemplateResult([], $bounds);

		$params = [];
		foreach ($codebase->methods->getMethodParams($id) as $param) {
			if ($param->type !== null) {
				$type = TemplateInferredTypeReplacer::replace($param->type, $templates, $codebase);
				$param = $param->setType(TypeExpander::expandUnion($codebase, $type, $target, $self, null));
			}
			$params[] = $param;
		}
		// func_get_args() methods like select(): allow the extra arguments
		if ($storage->variadic || self::readsAllArgs($target, $method)) {
			$params[] = new FunctionLikeParameter('args', false, Type::getMixed(), is_variadic: true);
		}
		return $params;
	}

	public static function getMethodReturnType(MethodReturnTypeProviderEvent $event): ?Union {
		$source = $event->getSource();
		if (!$source instanceof StatementsAnalyzer) {
			return null;
		}
		$codebase = $source->getCodebase();
		$class = $event->getFqClasslikeName();
		$method = $event->getMethodNameLowercase();
		$called = $event->getCalledFqClasslikeName() ?? $class;

		if (self::isModel($codebase, $class)) {
			if ($method === '__callstatic' || $method === '__call') {
				$method = $event->getCalledMethodNameLowercase();
				if ($method === null || self::forwardedTo($codebase, $class, $method) === null) {
					return null;
				}
			} elseif (self::forwardedTo($codebase, $class, $method) === null) {
				return null;
			}
			$builder = new TGenericObject(Builder::class, [new Union([new TNamedObject($called)])]);
			return self::fakeCall($source, $event, $method, $builder);
		}

		if (self::forwardedTo($codebase, $class, $method) === null) {
			return null;
		}
		// relation: its builder is Eloquent\Builder<TRelatedModel>. Psalm's
		// magic-call path passes no template parameters, so take the
		// relation's type from the receiver.
		$relation = self::receiverType($source, $event, $called);
		if ($relation === null) {
			return null;
		}
		$params = $relation->type_params;
		$result = self::fakeCall($source, $event, $method, new TGenericObject(Builder::class, [$params[0]]));
		if ($result === null) {
			return null;
		}
		foreach ($result->getAtomicTypes() as $atomic) {
			if (!$atomic instanceof TNamedObject
				|| !in_array(strtolower($atomic->value), [strtolower(Builder::class), strtolower(QueryBuilder::class)], true)
			) {
				return $result;
			}
		}
		// the builder returned itself: Relation::forwardDecoratedCallTo() returns the relation
		return new Union([$relation]);
	}

	// the generic $class the call is made on
	private static function receiverType(StatementsAnalyzer $source, MethodReturnTypeProviderEvent $event, string $class): ?TGenericObject {
		$stmt = $event->getStmt();
		if (!$stmt instanceof MethodCall) {
			return null;
		}
		$type = $source->getNodeTypeProvider()->getType($stmt->var);
		foreach ($type?->getAtomicTypes() ?? [] as $atomic) {
			if ($atomic instanceof TGenericObject && strtolower($atomic->value) === strtolower($class)) {
				return $atomic;
			}
		}
		return null;
	}

	/*
	 The builder class whose $method a call of $method on $class runs, or
	 null when the class declares it itself (or nobody has it). Models
	 reach Eloquent\Builder, else its Query\Builder; relations only the
	 Query\Builder, as Psalm follows their mixin to Eloquent\Builder.
	*/
	private static function forwardedTo(Codebase $codebase, string $class, string $method): ?string {
		$key = strtolower($class) . '::' . $method;
		if (array_key_exists($key, self::$forwarded)) {
			return self::$forwarded[$key];
		}
		$target = null;
		if (!str_starts_with($method, '__')
			&& !isset($codebase->classlike_storage_provider->get($class)->declaring_method_ids[$method])
		) {
			$onBuilder = self::declares($codebase, Builder::class, $method);
			if ($onBuilder && self::isModel($codebase, $class)) {
				$target = Builder::class;
			} elseif (!$onBuilder && self::declares($codebase, QueryBuilder::class, $method)) {
				$target = QueryBuilder::class;
			}
		}
		return self::$forwarded[$key] = $target;
	}

	/*
	 Whether a vendor method takes its arguments with func_get_args() and
	 friends. Psalm notices that in vendor code only for a bare assignment,
	 and Query\Builder::select() calls it inside a ternary.
	*/
	private static function readsAllArgs(string $class, string $method): bool {
		$key = strtolower($class) . '::' . $method;
		if (!array_key_exists($key, self::$readsAllArgs)) {
			$found = false;
			try {
				$reflection = new ReflectionMethod($class, $method);
				$file = $reflection->getFileName();
				$start = $reflection->getStartLine();
				$end = $reflection->getEndLine();
				if ($file !== false && $start !== false && $end !== false) {
					$lines = file($file);
					if ($lines !== false) {
						$body = implode('', array_slice($lines, $start - 1, $end - $start + 1));
						$found = preg_match('/\bfunc_(get_args|get_arg|num_args)\s*\(/', $body) === 1;
					}
				}
			} catch (ReflectionException) {
			}
			self::$readsAllArgs[$key] = $found;
		}
		return self::$readsAllArgs[$key];
	}

	private static function declares(Codebase $codebase, string $class, string $method): bool {
		$declaring = $codebase->classlike_storage_provider->get($class)->declaring_method_ids[$method] ?? null;
		return $declaring !== null
			&& $codebase->methods->getStorage($declaring)->visibility === ClassLikeAnalyzer::VISIBILITY_PUBLIC;
	}

	private static function isModel(Codebase $codebase, string $class): bool {
		return isset($codebase->classlike_storage_provider->get($class)->parent_classes[strtolower(Model::class)]);
	}

	/*
	 Type $receiver->$method(...the call's arguments) for $receiver of
	 $type, by analysing that call in the caller's context.
	*/
	private static function fakeCall(StatementsAnalyzer $source, MethodReturnTypeProviderEvent $event, string $method, TNamedObject $type): ?Union {
		$call = new MethodCall(
			new Variable('rqwatchFakeReceiver'),
			new Identifier($method),
			$event->getCallArgs(),
		);

		$nodeData = $source->node_data;
		$source->node_data = clone $nodeData;
		$context = clone $event->getContext();
		$context->inside_call = true;
		$context->vars_in_scope['$rqwatchFakeReceiver'] = new Union([$type]);

		// the arguments were checked against the claimed parameters; this
		// analysis only types the call, so whatever it reports is dropped
		IssueBuffer::startRecording();
		try {
			if (MethodCallAnalyzer::analyze($source, $call, $context, false) === false) {
				return null;
			}
			return $source->node_data->getType($call);
		} finally {
			IssueBuffer::clearRecordingLevel();
			IssueBuffer::stopRecording();
			$source->node_data = $nodeData;
		}
	}
}
