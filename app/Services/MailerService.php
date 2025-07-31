<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2025 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App\Services;

use App\Core\Config;
use App\Controllers\ViewController;

use Psr\Log\LoggerInterface;

use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\File;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;

use Twig\Environment;
use Symfony\Bridge\Twig\Mime\BodyRenderer;

class MailerService
{
	private Mailer $mailer;
	private ?Environment $twig = null;
	private LoggerInterface $logger;

	public function __construct(LoggerInterface $logger, ?Environment $twig = null) {
		$this->logger = $logger;

		$dsn = $_ENV['MAILER_DSN'] ?? null;

		if (!$dsn) {
			throw new \RuntimeException('MAILER_DSN is not set in .env');
		}

		$transport = Transport::fromDsn($dsn);
		$this->mailer = new Mailer($transport);
		if (!$twig) {
			// create a new twig view
			$service = new ViewController();
			$twig = $service->twigView();
		}
		$this->twig = $twig;
	}

	/**
	 * Send an email to multiple recipients with optional attachment.
	 *
	 * @param string   $from        Sender email address
	 * @param array    $recipients  Array of recipient email addresses
	 * @param string   $subject     Subject line
	 * @param string   $body        Plain text body
	 * @param string|null $attachmentPath Optional file path to attach
	 * @param string|null $attachmentName Optional name for attachement
	 *
	 * @return bool True if sent successfully, false on failure
	*/
	public function sendEmail(
		string $from,
		array $recipients,
		string $subject,
		string $body,
		?string $attachmentPath = null,
		?string $attachmentName = null
	): bool {

		try {
			$email = (new Email())
				->from($from)
				->to(...$recipients)
				->subject($subject)
				->text($body);

			if ($attachmentPath && file_exists($attachmentPath)) {
				$email->addPart(
					new DataPart(
						// fopen($attachmentPath, 'r'),
						new File($attachmentPath),
						$attachmentName, 
						'message/rfc822', 
						'8bit',
					)
				);
			}

			$this->mailer->send($email);
			return true;
		} catch (\Throwable $e) {
			$this->logger->error('[MailerService] Email failed: ' . $e->getMessage());
			return false;
		}
   }

	/**
	 * Send an email to multiple recipients with optional attachment.
	 *
	 * @param string   $from        Sender email address
	 * @param array    $recipients  Array of recipient email addresses
	 * @param string   $subject     Subject line
	 * @param string   $template    HTML Twig Template
	 * @param string   $text        Text Part
	 * @param array    $context     pass variables (name => value) to the template
	 * @param string|null $attachmentPath Optional file path to attach
	 * @param string|null $attachmentName Optional name for attachement
	 *
	 * @return bool True if sent successfully, false on failure
	*/
	public function sendTemplatedEmail(
		string  $from,
		array   $recipients,
		string  $subject,
		string  $template,
		?string  $text = null,
		array   $context,
		?string $attachmentPath = null,
		?string $attachmentName = null
	): bool {

		try {
			$email = (new TemplatedEmail())
				->from($from)
				->to(...$recipients)
				->subject($subject)
				->text($text)
				->htmlTemplate($template)
				->context($context);

			if ($attachmentPath && file_exists($attachmentPath)) {
				$email->addPart(
					new DataPart(
						// fopen($attachmentPath, 'r'),
						new File($attachmentPath),
						$attachmentName, 
						'message/rfc822', 
						'8bit',
					)
				);
			}
			$renderer = new BodyRenderer($this->twig);
			$renderer->render($email);

			$this->mailer->send($email);
			return true;
		} catch (\Throwable $e) {
			$this->logger->error('[MailerService] Email failed: ' . $e->getMessage());
			return false;
		}
   }

}
