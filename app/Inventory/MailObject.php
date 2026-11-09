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

final class MailObject
{
	// image parts a cid: reference may inline as a data: URI (no SVG)
	private const EMBED_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];
	// raw bytes inlined per mail; past this, cid: references stay as they are
	private const EMBED_MAX_BYTES = 5 * 1024 * 1024;

	private ?string $virus_found;
	/** @var list<string> */
	private array $symbols;
	private bool $mail_stored;
	private ?string $mail_location;
	/** @psalm-suppress PropertyNotSetInConstructor set by setMessageBody() */
	private string $htmlBody;
	/** @psalm-suppress PropertyNotSetInConstructor set by setMessageBody() */
	private string $textBody;
	/** @var array<int, array{filename: string, filetype: string}> */
	private array $attached = [];
	private MailLog $maillog;
	private Parser $parser;

	/** @param array{log: MailLog, symbols: list<string>, virus_found: string|null, received: array<array-key, mixed>|null} $ar MailLogService::detail() */
	public function __construct(array $ar) {
		$this->virus_found = $ar['virus_found'];
		$this->symbols = $ar['symbols'];
		$this->maillog = $ar['log'];
		$this->mail_stored = $this->maillog->mail_stored;
		$this->mail_location = $this->maillog->mail_location;
		$this->parser = new Parser();
	}

	public function isMailStored(): bool {
		return $this->mail_stored;
	}

	public function getMailLocation(): ?string {
		return $this->mail_location;
	}

	public function setPath(string $file): void {
		$this->parser->setPath($file);
	}

	public function setText(string $data): void {
		$this->parser->setText($data);
	}

	public function setMessageBody(): void {
		$this->htmlBody = self::inertLinkMeta($this->embedImages($this->parser->getMessageBody('html')));
		$this->textBody = nl2br(htmlspecialchars($this->parser->getMessageBody('text')));
	}

	/*
	 cid: references (RFC 2392: the Content-ID without <>, URL-encoded) to
	 image parts of this mail become data: URIs, quoted or not, so embedded
	 images show in the preview, whose CSP allows data: images and nothing
	 remote. Unknown references and other part types are left as they are,
	 and the browser blocks them.
	*/
	/*
	 <link> and <meta> made inert for the preview, by renaming them to
	 elements no browser knows. CSP covers fetches but not resource hints:
	 a mail's <link rel="preconnect"> made Firefox resolve the sender's
	 hostname as soon as the preview opened (a DNS-level read receipt), and
	 a <meta http-equiv> can switch DNS prefetching on. Nothing else a mail's
	 <link> or <meta> does is wanted in the preview: remote stylesheets are
	 blocked anyway. A browser starts a tag name right after '<', matches it
	 case-insensitively and ends it at whitespace, '/' or '>', which is what
	 this matches; inside a comment or an attribute value it only changes text.
	*/
	public static function inertLinkMeta(string $html): string {
		return preg_replace('/<(link|meta)(?=[\s\/>])/i', '<x-rqwatch-$1', $html) ?? '';
	}

	private function embedImages(string $html): string {
		if (stripos($html, 'cid:') === false) {
			return $html;
		}
		$images = [];
		$budget = self::EMBED_MAX_BYTES;
		foreach ($this->parser->getAttachments() as $attachment) {
			// false, not '', when the part has no Content-ID
			$cid = (string) $attachment->getContentID();
			$type = strtolower(trim(explode(';', $attachment->getContentType())[0]));
			if ($cid === '' || isset($images[$cid]) || !in_array($type, self::EMBED_TYPES, true)) {
				continue;
			}
			$content = $attachment->getContent();
			if (strlen($content) > $budget) {
				continue;
			}
			$budget -= strlen($content);
			$images[$cid] = "data:{$type};base64," . base64_encode($content);
		}
		if ($images === []) {
			return $html;
		}
		return preg_replace_callback('/\bcid:([^\s"\'<>()]+)/i',
			fn(array $m): string => $images[rawurldecode($m[1])] ?? $m[0], $html) ?? $html;
	}

	public function setAttached(): void {
		/** @var list<\PhpMimeMailParser\Attachment> $attachments in order of appearance */
		$attachments = $this->parser->getAttachments();

		$attached = [];
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

	/** @return array<int, \PhpMimeMailParser\Attachment> */
	public function getAttachments(): array {
		/** @var list<\PhpMimeMailParser\Attachment> $attachments in order of appearance */
		$attachments = $this->parser->getAttachments();
		return $attachments;
	}

	public function getTextBody(): string {
		return $this->textBody;
	}

	public function getHtmlBody(): string {
		return $this->htmlBody;
	}

	/** @return array<int, array{filename: string, filetype: string}> */
	public function getAttached(): array {
		return $this->attached;
	}

	public function getMailLog(): MailLog {
		return $this->maillog;
	}

	public function getVirusFound(): ?string {
		return $this->virus_found;
	}

	/** @return list<string> */
	public function getSymbols(): array {
		return $this->symbols;
	}

}
