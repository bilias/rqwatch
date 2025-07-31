<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2025 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App\Inventory;

use App\Models\MailLog;

use PhpMimeMailParser\Parser;

class MailObject
{
	private ?string $virus_found = null;
	private array $symbols = [];
	private bool $mail_stored = false;
	private ?string $mail_location = null;
	private string $htmlBody;
	private string $textBody;
	private array $attached = [];
	private array $received = [];
	private MailLog $maillog;
	private Parser $parser;

	public function __construct(array $ar) {
		$this->virus_found = $ar['virus_found'];
		$this->symbols = $ar['symbols'];
		$this->maillog = $ar['log'];
		$this->mail_stored = $this->maillog->mail_stored;
		$this->mail_location = $this->maillog->mail_location;
	}

	public function isMailStored(): bool {
		return $this->mail_stored;
	}

	public function getMailLocation(): ?string {
		return $this->mail_location;
	}

	public function setParser(Parser $parser): void {
		$this->parser = $parser;
	}

	public function setPath(string $file): void {
		$this->parser->setPath($file);
	}

	public function setText(string $data): void {
		$this->parser->setText($data);
	}

	public function setMessageBody(): void {
		$this->htmlBody = $this->parser->getMessageBody('html');
		$this->textBody = nl2br(htmlspecialchars($this->parser->getMessageBody('text')));
	}

	public function setReceived(): void {
		$hdr_ar = $this->parser->getHeaders();
		$this->received = $hdr_ar['received'];
	}

	public function setAttached(): void {
		$attachments = $this->parser->getAttachments();

		if (count($attachments) > 0) {
			foreach($attachments as $key => $attachment) {
				$attached[$key]['filename'] = $attachment->getFilename();
				$attached[$key]['filetype'] = $attachment->getContentType();
				//$attached[$key]['mime'] = $attachment->getMimePartStr();
				//$attached[$key]['Headers'] = $attachment->getHeaders();
				//$attached[$key]['stream'] = $attachment->getStream();
				//$attached[$key]['content'] = $attachment->getContent();
         }
			$this->attached = $attached;
		}
	}

	public function getAttachments(): array {
		return $this->parser->getAttachments();
	}

	public function getTextBody(): string {
		return $this->textBody;
	}

	public function getHtmlBody(): string {
		return $this->htmlBody;
	}

	public function getAttached(): array {
		return $this->attached;
	}

	public function getMailLog(): MailLog {
		return $this->maillog;
	}

	public function getVirusFound(): ?string {
		return $this->virus_found;
	}

	public function getSymbols(): ?array {
		return $this->symbols;
	}

	public function getReceived(): ?array {
		return $this->received;
	}

}
