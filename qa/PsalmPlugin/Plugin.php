<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 Psalm plugin for rqwatch's Eloquent use: illuminate/database through
 Capsule, without the Laravel framework (psalm/plugin-laravel needs a
 booted Laravel application). Registered in qa/psalm.xml.
*/

namespace Qa\PsalmPlugin;

use Psalm\Plugin\PluginEntryPointInterface;
use Psalm\Plugin\RegistrationInterface;
use SimpleXMLElement;

final class Plugin implements PluginEntryPointInterface
{
	#[\Override]
	public function __invoke(RegistrationInterface $registration, ?SimpleXMLElement $config = null): void {
		// registerHooksFromClass() does not autoload
		class_exists(EloquentForwarding::class);
		class_exists(EloquentProperties::class);
		$registration->registerHooksFromClass(EloquentForwarding::class);
		$registration->registerHooksFromClass(EloquentProperties::class);
		// the Twig extensions templates are parsed with in the taint analysis
		// (psalm/plugin-symfony gets them only from a Symfony container XML)
		\Psalm\SymfonyPsalmPlugin\Twig\TemplateFileAnalyzer::initExtensions([
			[\Symfony\Bridge\Twig\Extension\FormExtension::class, null],
		]);
	}
}
