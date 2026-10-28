<?php
/*
 Generates qa/taint-stubs.phpstub: Psalm taint annotations for vendor
 classes. The list below is the security decision (which methods return
 attacker data, which arguments are executed as SQL or sent as HTML);
 signatures and docblocks are copied from the installed vendor code.

 Re-run after composer update/upgrade:
	php qa/gen-taint-stubs.php > qa/taint-stubs.phpstub
 It exits non-zero if a listed method or parameter no longer exists.
*/

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

const SOURCE = '@psalm-taint-source input';

// class => [method => annotation]; a sink names its dangerous parameter
const TAINTS = [
	// request input
	'Symfony\Component\HttpFoundation\ParameterBag' => [
		'all' => SOURCE, 'get' => SOURCE, 'getString' => SOURCE,
	],
	'Symfony\Component\HttpFoundation\InputBag' => [
		'get' => SOURCE, 'getString' => SOURCE,
	],
	'Symfony\Component\HttpFoundation\HeaderBag' => [
		'all' => SOURCE, 'get' => SOURCE,
	],
	// request line, raw body and Host; LoginController::oidcBaseUrl() escapes the Host
	'Symfony\Component\HttpFoundation\Request' => [
		'getContent' => SOURCE, 'getRequestUri' => SOURCE, 'getPathInfo' => SOURCE,
		'getUri' => SOURCE, 'getQueryString' => SOURCE,
		'getHost' => SOURCE, 'getSchemeAndHttpHost' => SOURCE,
	],
	// session values are stored request data (e.g. login_redirect)
	'Symfony\Component\HttpFoundation\Session\SessionInterface' => [
		'get' => SOURCE,
	],
	'Symfony\Component\HttpFoundation\Session\Session' => [
		'get' => SOURCE,
	],
	'Symfony\Component\Form\FormInterface' => [
		'getData' => SOURCE,
	],
	// mail content is attacker-controlled
	'PhpMimeMailParser\Parser' => [
		'getMessageBody' => SOURCE, 'getHeader' => SOURCE, 'getRawHeader' => SOURCE,
		'getHeaders' => SOURCE, 'getHeadersRaw' => SOURCE, 'getAddresses' => SOURCE,
	],
	// attachment metadata; the content itself is served as a download by design
	'PhpMimeMailParser\Attachment' => [
		'getFilename' => SOURCE, 'getContentType' => SOURCE, 'getContentDisposition' => SOURCE,
		'getContentID' => SOURCE, 'getHeaders' => SOURCE,
	],
	// raw SQL strings (bindings are safe and not marked)
	'Illuminate\Database\Query\Builder' => [
		'selectRaw'   => '@psalm-taint-sink sql $expression',
		'fromRaw'     => '@psalm-taint-sink sql $expression',
		'whereRaw'    => '@psalm-taint-sink sql $sql',
		'orWhereRaw'  => '@psalm-taint-sink sql $sql',
		'groupByRaw'  => '@psalm-taint-sink sql $sql',
		'havingRaw'   => '@psalm-taint-sink sql $sql',
		'orHavingRaw' => '@psalm-taint-sink sql $sql',
		'orderByRaw'  => '@psalm-taint-sink sql $sql',
		'raw'         => '@psalm-taint-sink sql $value',
	],
	'Illuminate\Database\Connection' => [
		'select'                    => '@psalm-taint-sink sql $query',
		'selectOne'                 => '@psalm-taint-sink sql $query',
		'scalar'                    => '@psalm-taint-sink sql $query',
		'selectFromWriteConnection' => '@psalm-taint-sink sql $query',
		'cursor'                    => '@psalm-taint-sink sql $query',
		'insert'                    => '@psalm-taint-sink sql $query',
		'update'                    => '@psalm-taint-sink sql $query',
		'delete'                    => '@psalm-taint-sink sql $query',
		'statement'                 => '@psalm-taint-sink sql $query',
		'affectingStatement'        => '@psalm-taint-sink sql $query',
		'unprepared'                => '@psalm-taint-sink sql $query',
		'raw'                       => '@psalm-taint-sink sql $value',
	],
	// response body
	'Symfony\Component\HttpFoundation\Response' => [
		'__construct' => '@psalm-taint-sink html $content',
		'setContent'  => '@psalm-taint-sink html $content',
	],
	// redirect target (open redirect)
	'Symfony\Component\HttpFoundation\RedirectResponse' => [
		'__construct'  => '@psalm-taint-sink header $url',
		'setTargetUrl' => '@psalm-taint-sink header $url',
	],
];

// Methods reached only through __call/__callStatic, which Psalm can't follow
const MAGIC = [
	'Illuminate\Database\Capsule\Manager' => [
		// DB::raw() -> Connection::raw()
		"\t\t/** @psalm-taint-sink sql \$value */\n\t\tpublic static function raw(\$value) {}",
	],
];

function fail(string $msg): never {
	fwrite(STDERR, "gen-taint-stubs: $msg\n");
	exit(1);
}

function defaultValue(ReflectionParameter $p): string {
	if ($p->isDefaultValueConstant()) {
		$c = (string) $p->getDefaultValueConstantName();
		return str_contains($c, '\\') || str_contains($c, '::') ? '\\' . ltrim($c, '\\') : $c;
	}
	$v = $p->getDefaultValue();
	return match (true) {
		$v === null  => 'null',
		$v === []    => '[]',
		is_bool($v)  => $v ? 'true' : 'false',
		default      => var_export($v, true),
	};
}

function typeString(?ReflectionType $t): string {
	if ($t === null) {
		return '';
	}
	// qualify class names; builtins and self/static stay as they are
	return preg_replace_callback('/[A-Za-z_\\\\][A-Za-z0-9_\\\\]*/', static function (array $m): string {
		$builtin = ['int', 'float', 'string', 'bool', 'array', 'callable', 'iterable', 'object',
			'mixed', 'void', 'null', 'never', 'false', 'true', 'self', 'static', 'parent'];
		return in_array(strtolower($m[0]), $builtin, true) ? $m[0] : '\\' . ltrim($m[0], '\\');
	}, (string) $t);
}

function signature(ReflectionMethod $m): string {
	$params = [];
	foreach ($m->getParameters() as $p) {
		$s = typeString($p->getType());
		$s .= ($s !== '' ? ' ' : '') . ($p->isPassedByReference() ? '&' : '') . ($p->isVariadic() ? '...' : '') . '$' . $p->getName();
		if ($p->isDefaultValueAvailable()) {
			$s .= ' = ' . defaultValue($p);
		}
		$params[] = $s;
	}
	$ret = typeString($m->getReturnType());
	return 'public ' . ($m->isStatic() ? 'static ' : '') . 'function ' . $m->getName()
		. '(' . implode(', ', $params) . ')' . ($ret !== '' ? ": $ret" : '');
}

// original docblock with the taint annotation added before its closing line
function docblock(ReflectionMethod $m, string $annotation): string {
	$doc = $m->getDocComment();
	if ($doc === false) {
		return "\t\t/** $annotation */";
	}
	$lines = array_map('trim', explode("\n", $doc));
	if (count($lines) === 1) {
		return "\t\t/** " . trim(substr($doc, 3, -2)) . "\n\t\t * $annotation\n\t\t */";
	}
	array_pop($lines);
	$out = [];
	foreach ($lines as $i => $l) {
		$out[] = "\t\t" . ($i === 0 ? $l : ' ' . $l);
	}
	$out[] = "\t\t * $annotation";
	$out[] = "\t\t */";
	return implode("\n", $out);
}

function classHeader(ReflectionClass $c): string {
	$name = $c->getShortName();
	$ifaces = array_map(static fn (string $i): string => '\\' . $i, $c->getInterfaceNames());
	if ($c->isInterface()) {
		return "interface $name" . ($ifaces ? ' extends ' . implode(', ', $ifaces) : '');
	}
	$parent = $c->getParentClass();
	return ($c->isFinal() ? 'final ' : '') . ($c->isAbstract() ? 'abstract ' : '') . "class $name"
		. ($parent ? ' extends \\' . $parent->getName() : '')
		. ($ifaces ? ' implements ' . implode(', ', $ifaces) : '');
}

// `use` imports of the file declaring $c, so copied docblocks resolve
function imports(ReflectionClass $c): array {
	$src = (string) file_get_contents((string) $c->getFileName());
	$head = preg_split('/^\s*(?:final\s+|abstract\s+)?(?:class|interface|trait)\s/m', $src, 2)[0];
	preg_match_all('/^use\s+([^;(]+);/m', $head, $m);
	return array_map('trim', $m[1]);
}

function indentDoc(string|false $doc, string $indent): string {
	if ($doc === false) {
		return '';
	}
	$lines = array_map('trim', explode("\n", $doc));
	$out = [];
	foreach ($lines as $i => $l) {
		$out[] = $indent . ($i === 0 ? $l : ' ' . $l);
	}
	return implode("\n", $out) . "\n";
}

$byNamespace = [];
$uses = [];

foreach (TAINTS as $class => $methods) {
	if (!class_exists($class) && !interface_exists($class)) {
		fail("class $class not found");
	}
	$rc = new ReflectionClass($class);
	foreach (imports($rc) as $u) {
		$uses[$rc->getNamespaceName()][$u] = true;
	}
	$body = [];
	foreach ($methods as $method => $annotation) {
		if (!$rc->hasMethod($method)) {
			fail("$class::$method() not found");
		}
		$rm = $rc->getMethod($method);
		if ($rm->getDeclaringClass()->getName() !== $class) {
			fail("$class::$method() is inherited from " . $rm->getDeclaringClass()->getName() . '; list it there');
		}
		if (preg_match('/^@psalm-taint-sink \w+ \$(\w+)$/', $annotation, $m)) {
			$names = array_map(static fn (ReflectionParameter $p): string => $p->getName(), $rm->getParameters());
			if (!in_array($m[1], $names, true)) {
				fail("$class::$method() has no parameter \$$m[1]");
			}
		}
		$body[] = docblock($rm, $annotation) . "\n\t\t" . signature($rm) . ($rc->isInterface() ? ';' : ' {}');
	}
	foreach (MAGIC[$class] ?? [] as $extra) {
		$body[] = $extra;
	}
	$byNamespace[$rc->getNamespaceName()][] = indentDoc($rc->getDocComment(), "\t") . "\t" . classHeader($rc) . " {\n" . implode("\n\n", $body) . "\n\t}";
}

foreach (MAGIC as $class => $extras) {
	if (isset(TAINTS[$class])) {
		continue;
	}
	if (!class_exists($class)) {
		fail("class $class not found");
	}
	$rc = new ReflectionClass($class);
	$byNamespace[$rc->getNamespaceName()][] = "\t" . classHeader($rc) . " {\n" . implode("\n\n", $extras) . "\n\t}";
}

echo "<?php\n// Generated by qa/gen-taint-stubs.php -- do not edit; edit the list there.\n";
foreach ($byNamespace as $ns => $classes) {
	$use = '';
	foreach (array_keys($uses[$ns] ?? []) as $u) {
		$use .= "\tuse $u;\n";
	}
	echo "\nnamespace $ns {\n" . ($use !== '' ? "$use\n" : '') . implode("\n\n", $classes) . "\n}\n";
}
