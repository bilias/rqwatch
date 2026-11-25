<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace Tests;

/*
 The secrets .env-example and contrib/ shipped before the CHANGE_ME_
 placeholders (fd3b618), public in the git history, which
 Helper::isPublishedSecret() refuses by their SHA-256. Each is split in two
 so secret scanners do not report the tests as a new leak.
*/
final class PublishedSecrets
{
	/** what each value was shipped as => the value */
	public const array VALUES = [
		'DB_PASS' => '1vkUSBLfQlQcJFCmPw5TpkDOpmNpm5Lq' . 'dziZSMQB8sNJPXKgEcIgvLZ15IZSb7fB',
		'RSPAMD_CONTROLLER_PASS' => 'fnPsTu6p8PoVZAPmNK3JHPhT1NH5yuiR' . 'IzK03XUmJokwKZgxF2ZyvYlopj7rFJAK',
		'RSPAMD_API_PASS' => '1k5X73klYbd9ijRtXrhYCwaXBayZvIKI' . 'Oj5gpzbLS45i5ZOZGvO8q6mJCFubJVkY',
		'metadata_exporter.conf' => 'jPaEFfhPthVnsSn9RqPQSxeff4HSGBfW' . 'FIGCV8vJBCLKfCqsaVXVtxdTvU2npm7R',
		'MAIL_API_PASS' => 'BBfXqlWko0YhUOtQXGuQndc2NOLLKo87' . '88P7pYn5PhwoICqhbvpyjIRSCd7vA59B',
		'OPENIDC_CLIENT_SECRET' => 'wLwVOWBwjtzmFE2wn4' . 'eo8EH55EpVEozxrrv',
		'REDIS_DSN' => 'v47o7uz9ig7uwBMGF3DlgQsGCePCkQo3' . 'v6lOUYSznSMBqkRJXSkXIYcC75lBc6kg',
		'MAILER_DSN' => 'itzWMXZasd7UZH0byJIZcuhGwEMjuNkZ' . 'RaE6hXeaGP6RIfYKWCxOkHbfqdGcFSSU',
	];
}
