<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 A test session storage that records the $destroy of each regenerate():
 MockArraySessionStorage ignores it, so whether the old session's data was
 destroyed (migrate(true)) cannot be seen otherwise.
*/

namespace Tests\Unit;

use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class RecordingSessionStorage extends MockArraySessionStorage
{
	/** @var list<bool> the $destroy of each regenerate() */
	public array $regenerated = [];

	#[\Override]
	public function regenerate(bool $destroy = false, ?int $lifetime = null): bool {
		$this->regenerated[] = $destroy;
		return parent::regenerate($destroy, $lifetime);
	}
}
