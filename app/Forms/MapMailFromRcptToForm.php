<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2025 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App\Forms;

class MapMailFromRcptToForm extends MapWithTwoFieldsForm
{
	protected static string $firstFieldName = 'mail_from';
	protected static string $secondFieldName = 'rcpt_to';
}
