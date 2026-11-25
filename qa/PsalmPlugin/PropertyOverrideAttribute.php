<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 Drops MissingOverrideAttribute for a property when analysing for PHP 8.5+.
 PHP 8.5 allows #[\Override] on a property and Psalm asks for it there, but
 on PHP 8.3 and 8.4 the attribute targets methods only and a class with it
 on a property does not compile ("Attribute "Override" cannot target
 property"). The check for methods is kept on every version.

 When PHP 8.3 and 8.4 support ends: remove this handler and add the
 attributes with
 vendor/bin/psalm -c qa/psalm.xml --alter --issues=MissingOverrideAttribute --php-version=8.5
*/

namespace Qa\PsalmPlugin;

use Psalm\Issue\MissingOverrideAttribute;
use Psalm\Plugin\EventHandler\BeforeAddIssueInterface;
use Psalm\Plugin\EventHandler\Event\BeforeAddIssueEvent;

final class PropertyOverrideAttribute implements BeforeAddIssueInterface
{
	// false drops the issue; null leaves it to the next handler
	#[\Override]
	public static function beforeAddIssue(BeforeAddIssueEvent $event): ?bool {
		$issue = $event->getIssue();
		if ($issue instanceof MissingOverrideAttribute
			&& $event->getCodebase()->analysis_php_version_id >= 8_05_00
			&& str_starts_with($issue->message, 'Property ')
		) {
			return false;
		}
		return null;
	}
}
