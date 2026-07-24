<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App\Charts;

enum MailCategory
{
	case TOTAL;
	case STORED;
	case VIRUS;
	case CLEAN;
	case HEADER;
	case SUBJECT;
	case REJECT;
	case DISCARD;

	public function label(): string {
		return match ($this) {
			self::TOTAL   => 'Total',
			self::STORED  => 'Stored',
			self::VIRUS   => 'Virus',
			self::CLEAN   => 'No action',
			self::HEADER  => 'Add header',
			self::SUBJECT => 'Rewrite subject',
			self::REJECT  => 'Reject',
			self::DISCARD => 'Discard',
		};
	}

	public function color(): string {
		return match ($this) {
			self::TOTAL   => '#4e79a7',
			self::STORED  => '#f28e2b',
			self::VIRUS   => '#9F91FF',
			self::CLEAN   => '#d9d9d9',
			self::HEADER  => '#EE6262',
			self::SUBJECT => '#F5BBBB',
			self::REJECT  => '#000000',
			self::DISCARD => '#0010FF',
		};
	}
}
