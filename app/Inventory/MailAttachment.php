<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2025 Giannis Kapetanakis

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU Affero General Public License version 3
as published by the Free Software Foundation.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU Affero General Public License for more details.

You should have received a copy of the GNU Affero General Public License
along with this program.  If not, see <https://www.gnu.org/licenses/>.
*/

namespace App\Inventory;

use PhpMimeMailParser\Attachment;

class MailAttachment
{
	private string $filename;
	private string $filetype;
	private string $content;

	public function __construct(Attachment $attachment) {
		$this->filename = $attachment->getFilename();
		$this->filetype = $attachment->getContentType();
		$this->content = $attachment->getContent();
	}

	public function getFilename(): string {
		return $this->filename;
	}

	public function getFileType(): string {
		return $this->filetype;
	}

	public function getContent(): string {
		return $this->content;
	}

	public function getSize(): int {
		return strlen($this->content);
	}
}
