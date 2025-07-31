<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2025 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
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
