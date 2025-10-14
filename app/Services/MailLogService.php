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
use App\Utils\Helper;
use App\Utils\FormHelper;
use App\Models\MailLog;
use App\Inventory\MailObject;
use App\Inventory\MailAttachment;

use Psr\Log\LoggerInterface;

use App\Services\MailerService;
use Twig\Environment;

use App\Services\ApiClient;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\Exception\ClientException;
use Symfony\Component\HttpFoundation\Response;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Database\Capsule\Manager as DB;

use Symfony\Component\HttpFoundation\Session\Session;

use Symfony\Component\Console\Output\OutputInterface;

use PhpMimeMailParser\Parser;

class MailLogService
{
	private LoggerInterface $logger;
	protected $items_per_page;
	protected $q_items_per_page;
	protected $max_items;
	private ?bool $is_admin = null;
	private ?string $email = null;
	private ?array $user_aliases = null;

	public function __construct(LoggerInterface $logger, ?Session $session = null) {
		$this->logger = $logger;

		if (!empty($session)) {
			$this->is_admin = $session->get('is_admin');
			$this->email = $session->get('email');
			$this->user_aliases = $session->get('user_aliases');
		}
		$this->items_per_page = Config::get('items_per_page');
		$this->q_items_per_page = Config::get('q_items_per_page');
		$this->max_items = Config::get('max_items');
	}

	public static function getSqlFromQuery(Builder $query): string {
		return vsprintf(str_replace('?', '"%s"', $query->toSql()), $query->getBindings());
	}

	public function getSearchQuery(array $filters, array $fields, int $limit=null): Builder {
		if ($limit) {
			$query = MailLog::select($fields)
				->orderBy('id', 'DESC')
				->limit($limit);
		} else {
			$query = MailLog::select($fields)
				->orderBy('id', 'DESC');
		}

		return $this->getQueryByFilters($query, $filters);
	}

	public function getQueryByFilters(Builder $query, array $filters): Builder {
		if (!empty($filters)) {
			$filters = FormHelper::getFilterByName($filters);
		}

		if (is_array($filters) and count($filters) > 0) {
			foreach ($filters as $filter) {
				if (array_key_exists('filter', $filter) &&
				    array_key_exists('choice', $filter) &&
					 array_key_exists('value', $filter) &&
					 !empty($filter['filter'])) {
							$f = $filter['filter'];
							$c = $filter['choice'];
							$v = $filter['value'];

							if ($c === 'LIKE') {
								$v = "%{$v}%";
							}
							if ($c === 'NOT LIKE') {
								$v = "%{$v}%";
							}
							if ($c === '=' and $f === 'created_at') {
								$c = 'LIKE';
								$v = "{$v}%";
							}
							$query->where($f, $c, $v);
				}
				/* support NULL/NOT NULL
				else {
					if ($c === 'is null') {
						$query->whereNull($f);
					}
					if ($c === 'is not null') {
						$query->whereNotNull($f);
					}
				}
				*/
			}
		}
		return $query;
	}

	public function showAll(): Collection {
		$fields = MailLog::SELECT_FIELDS;

		$query = MailLog::select($fields)
					->orderBy('id', 'DESC')
					->limit($this->max_items);

		$query = $this->applyUserScope($query);

		if (Helper::env_bool('DEBUG_SEARCH_SQL')) {
			$this->logger->info(self::getSqlFromQuery($query));
		}

		$logs = $query->get();

		return $logs;
	}

	public function showOne(int $id): MailLog {
		$fields = MailLog::SELECT_FIELDS;

		$query = MailLog::select($fields)
								->where('id', $id);

		$query = $this->applyUserScope($query);

		if (Helper::env_bool('DEBUG_SEARCH_SQL')) {
			$this->logger->info(self::getSqlFromQuery($query));
		}

		$log = $query->first();

		if (!$log) {
			throw new \InvalidArgumentException("Mail with ID '{$id}' not found");
		}

		return $log;
	}

	public function showQuarantinedMail(int $id): MailLog {
		$fields = MailLog::SELECT_FIELDS;

		$query = MailLog::select($fields)
								->where('id', $id)
								->where('mail_stored', 1);

		$query = $this->applyUserScope($query);

		if (Helper::env_bool('DEBUG_SEARCH_SQL')) {
			$this->logger->info(self::getSqlFromQuery($query));
		}

		$log = $query->first();

		if (!$log) {
			throw new \InvalidArgumentException("Mail with ID '{$id}' not found");
		}

		return $log;
	}

	public function showPaginatedAll(array $filters, int $page = 1, string $url): ?LengthAwarePaginator {
		$fields = MailLog::SELECT_FIELDS;

		$query = self::getSearchQuery($filters, $fields);

		$query = $this->applyUserScope($query);

		$query = $query->limit($this->max_items);

		if (Helper::env_bool('DEBUG_SEARCH_SQL')) {
			$this->logger->info(self::getSqlFromQuery($query));
		}

		try {
			/*
			$logs = $query
				->paginate($this->items_per_page, $fields, 'page', $page)
				->withPath($url);
			*/

			// Get limited dataset first
			$allItems = $query->get();

			// Manual pagination
			$offset = ($page - 1) * $this->items_per_page;
			$itemsForPage = $allItems->slice($offset, $this->items_per_page)->values();

			$paginator = new LengthAwarePaginator(
				$itemsForPage,
				$allItems->count(),
				$this->items_per_page,
				$page
			);
			$logs = $paginator->withPath($url);
		} catch (\Exception $e) {
			$this->logger->error("Query error: " . $e->getMessage());
			exit("Query error");
		}

		return $logs;
	}

	public function showPaginatedResults(array $filters, int $page = 1, string $url): ?LengthAwarePaginator {
		$fields = MailLog::SELECT_FIELDS;

		$query = self::getSearchQuery($filters, $fields);

		$query = $this->applyUserScope($query);

		if (Helper::env_bool('DEBUG_SEARCH_SQL')) {
			$this->logger->info(self::getSqlFromQuery($query));
		}

		try {
			$logs = $query
				->paginate($this->items_per_page, $fields, 'page', $page)
				->withPath($url);
		} catch (\Exception $e) {
			$this->logger->error("Query error: " . $e->getMessage());
			exit("Query error");
		}

		return $logs;
	}

	public function showResults(array $filters, array $fields = null): ?Collection {
		if (!$fields) {
			$fields = MailLog::SELECT_FIELDS;
			// remove unneeded keys
			$fields = Helper::removeArrFromArr($fields, ['symbols', 'subject']);
			// add needed keys
			$fields = Helper::addArrToArr($fields, ['server', 'mail_stored', 'released', 'notified']);
		}

		$query = self::getSearchQuery($filters, $fields);

		$query = $this->applyUserScope($query);

		if (Helper::env_bool('DEBUG_SEARCH_SQL')) {
			$this->logger->info(self::getSqlFromQuery($query));
		}

		try {
			$logs = $query
				->select($fields)
				->get();
				/*
				// if you map time here, change return to array
				->map(function ($log) {
					$arr = $log->toArray();
					$arr['created_at'] = $log->created_at->format('Y-m-d H:i:s');
					return $arr;
				})
				->toArray();
				*/
		} catch (\Exception $e) {
			$this->logger->error("Query error: " . $e->getMessage());
			exit("Query error");
		}

		return $logs;
	}

	public function showReports(array $filters, string $field): ?Collection {
		switch($field) {
			case 'mail_from_domain':
			case 'rcpt_to_domain':
			case 'mime_from_domain':
			case 'mime_to_domain':
				$baseField = str_replace('_domain', '', $field);
				$query = MailLog::selectRaw("LOWER(TRIM(TRAILING '>' FROM SUBSTRING_INDEX({$baseField}, '@', -1))) AS {$field}, COUNT(*) AS total")
				                ->where($baseField, 'LIKE', '%@%')
				                ->groupBy($field)
				                ->orderByDesc('total');
				break;
			case 'date':
				$query = MailLog::selectRaw('DATE(created_at) AS date, COUNT(*) AS total')
				                ->groupBy(DB::raw('DATE(created_at)'))
									 ->orderByDesc('date');
				break;
			default:
				//$fields = [ $field, DB::raw('count(*) as total') ];
				//$query = MailLog::select($fields);
				$query = MailLog::selectRaw("LOWER({$field}) AS {$field}, COUNT(*) AS total")
				                ->groupBy($field);
				break;
		}

		$query = $this->getQueryByFilters($query, $filters);
		$query = $this->applyUserScope($query);

		$query->orderBy('total', 'DESC')
		      ->limit(Config::get('top_reports'));

		if (Helper::env_bool('DEBUG_SEARCH_SQL')) {
			$this->logger->info(self::getSqlFromQuery($query));
		}

		try {
			$logs = $query->get();
		} catch (\Exception $e) {
			$this->logger->error("Query error: " . $e->getMessage());
			exit("Query error");
		}

		return $logs;
	}

	public function showStats(array $filters): array {
		$fields = ['id'];

		$query = MailLog::select($fields);
		$query = $this->getQueryByFilters($query, $filters);
		$query = $this->applyUserScope($query);

		if (Helper::env_bool('DEBUG_SEARCH_SQL')) {
			$this->logger->info(self::getSqlFromQuery($query));
		}

		$stats['count'] = $query->count();

		if (($stats['count']) > 0) {
			$stats['first'] = (clone $query)->select('created_at')->orderBy('id', 'ASC')->first()->created_at->toDateTimeString();
			$stats['last'] = (clone $query)->select('created_at')->orderBy('id', 'DESC')->first()->created_at->toDateTimeString();
			$stats['stored'] = (clone $query)->where('mail_stored', 1)->count();
			$stats['notified'] = (clone $query)->where('notified', 1)->count();
			$stats['released'] = (clone $query)->where('released', 1)->count();
			$stats['has_virus'] = (clone $query)->where('has_virus', 1)->count();
			$stats['action'] = collect((clone $query)
				->selectRaw('action, COUNT(*) as cnt')
			   ->groupBy('action')
				->orderBy('cnt', 'DESC')
				->orderBy('action')
				->get()
				)
				->mapWithKeys(fn($item) => [$item['action'] => $item['cnt']])
				->toArray();

			return $stats;
		}

		return array(
			'count' => 0,
			'last' => null,
			'first' => null,
			'stored' => 0,
			'notified' => 0,
			'released' => 0,
		);
	}

	public function showPaginatedDay(int $page = 1, string $date = null, string $url): LengthAwarePaginator {
		$fields = MailLog::SELECT_FIELDS;

		if (!$date) {
			$date = Helper::get_today();
		}

		$query = MailLog::select($fields)
			->where('created_at', 'LIKE', "{$date}%")
			->orderBy('id', 'DESC');

		$query = $this->applyUserScope($query);

		if (Helper::env_bool('DEBUG_SEARCH_SQL')) {
			$this->logger->info(self::getSqlFromQuery($query));
		}

		$logs = $query
			->paginate($this->items_per_page, $fields, 'page', $page)
			->withPath($url);
		
		return $logs;
	}

	public function showPaginatedQuarantineDay(int $page = 1, string $date = null, string $url): LengthAwarePaginator {
		$fields = MailLog::SELECT_FIELDS;

		if (!$date) {
			$date = Helper::get_today();
		}

		$query = MailLog::select($fields)
			->where('created_at', 'LIKE', "{$date}%")
			->where('mail_stored', 1)
			->orderBy('id', 'DESC');

		$query = $this->applyUserScope($query);

		if (Helper::env_bool('DEBUG_SEARCH_SQL')) {
			$this->logger->info(self::getSqlFromQuery($query));
		}

		$logs = $query
			->paginate($this->items_per_page, $fields, 'page', $page)
			->withPath($url);
		
		return $logs;
	}

	public function showQuarantine(): Collection {

		$query = MailLog::selectRaw('DATE(created_at) as day, COUNT(*) as cnt')
			->where('mail_stored', 1)
			->groupByRaw('day')
			->orderByDesc('day')
			->limit((int)$_ENV['QUARANTINE_DAYS']);

		$query = $this->applyUserScope($query);

		if (Helper::env_bool('DEBUG_SEARCH_SQL')) {
			$this->logger->info(self::getSqlFromQuery($query));
		}

		$days = $query
			->get();

		return $days;
	}

	public function showPaginatedQuarantine(int $page = 1, string $url): LengthAwarePaginator {

		$query = MailLog::selectRaw('DATE(created_at) as day, COUNT(*) as cnt')
			->where('mail_stored', 1)
			->groupByRaw('day')
			->orderByDesc('day')
			->limit((int)$_ENV['QUARANTINE_DAYS']);

		$query = $this->applyUserScope($query);

		if (Helper::env_bool('DEBUG_SEARCH_SQL')) {
			$this->logger->info(self::getSqlFromQuery($query));
		}

		$days = $query
			->paginate($this->q_items_per_page, ['day', 'cnt'], 'page', $page)
			->withPath($url);

		return $days;
	}

	public function detailById(int $id): MailLog {
		$query = MailLog::where('id', $id);

		$query = $this->applyUserScope($query);

		if (Helper::env_bool('DEBUG_SEARCH_SQL')) {
			$this->logger->info(self::getSqlFromQuery($query));
		}

		$log = $query->first();

		if (!$log) {
			throw new \InvalidArgumentException("Mail with ID '{$id}' not found");
		}

		return $log;
	}

	public function detailByQid(string $qid): MailLog {
		$query = MailLog::where('qid', $qid);

		$query = $this->applyUserScope($query);

		if (Helper::env_bool('DEBUG_SEARCH_SQL')) {
			$this->logger->info(self::getSqlFromQuery($query));
		}

		$log = $query->first();
		if (!$log) {
			throw new \InvalidArgumentException("Mail with QID '{$qid}' not found");
		}
		return $log;
	}

	public function detailByType(string $type, string|int $value): MailLog {
		$query = MailLog::where($type, $value);

		$query = $this->applyUserScope($query);

		if (Helper::env_bool('DEBUG_SEARCH_SQL')) {
			$this->logger->info(self::getSqlFromQuery($query));
		}

		$log = $query->first();
		if (!$log) {
			throw new \InvalidArgumentException("{$type} '{$value}' not found");
		}
		return $log;
	}

	public function detail(string $type, string|int $value): Array {
		$check = Helper::check_id_qid($type, $value);

		if ($check['error']) {
			throw new \InvalidArgumentException("Error: {$check['error']}");
		}

		if($check['id']) {
			// has applyUserScope
			// throws InvalidArgumentException if no mail found
			$log = $this->detailById($check['id']);
		}
		elseif ($check['qid']) {
			// has applyUserScope
			// throws InvalidArgumentException if no mail found
			$log = $this->detailByQid($check['qid']);
		}
		else {
			throw new \InvalidArgumentException("Unknown error");
		}

		// order symbols by score and show printable information only
		$ar = Helper::format_symbols($log->symbols, $log->score, $log->has_virus);

		/*
		$parser = new Parser();
		$parser->setText($log->headers);
		$hdr_ar = $parser->getHeaders();
		$received = $hdr_ar['received'];
		*/
		if ($log->headers) {
			$received = Helper::extract_mail_relays($log->headers);
		} else {
			$received = null;
		}

		$ret = array(
			'log' => $log,
			'symbols' => $ar['symbols'],
			'virus_found' => $ar['virus_found'],
			'received' => $received,
		);
		return $ret;
	}

	public function getMailObjectLocal(int $id): MailObject {
		$lf = "[getMailObjectLocal]";

		if (empty($id)) {
			$this->logger->error("{$lf} empty mail id");
			throw new \Exception("Error. Contact admin");
		}

		try {
			// has applyUserScope
			$ar = $this->detail('id', $id);
		} catch (\InvalidArgumentException $e) {
			throw new \Exception("Mail with ID '{$id}' not found");
		}

		$mailobject = new MailObject($ar);

		if (!$mailobject->isMailStored()) {
			throw new \Exception("Mail with ID '{$id}' not stored");
		}

		$location = $mailobject->getMailLocation();
		if (!file_exists($location)) {
			throw new \Exception("File '$location' not found");
		}

		$mailobject->setParser(new Parser());
		$mailobject->setPath($location);
		//$mailobject->setReceived();
		$mailobject->setMessageBody();
		$mailobject->setAttached();

		return $mailobject;
	}

	public function getMailObjectViaApi(int $id, string $api_server): MailObject {
		$lf = "[getMailObjectViaApi]";

		if (empty($id)) {
			$this->logger->error("{$lf} empty mail id");
			throw new \Exception("Error. Contact admin");
		}
		if (empty($api_server)) {
			$this->logger->error("{$lf} empty api server");
			throw new \Exception("Error. Contact admin");
		}

		// get details from DB locally
		try {
			// has applyUserScope
			$ar = $this->detail('id', $id);
		} catch (\InvalidArgumentException $e) {
			$this->logger->error($e->getMessage());
			throw new \Exception($e->getMessage());
		}

		$mailobject = new MailObject($ar);

		if (!$mailobject->isMailStored()) {
			$this->logger->error("{$lf} Mail with id {$id} is not stored");
			throw new \Exception("Mail with id {$id} is not stored");
		}

		// get raw mail from remote API server
		$api_servers = Config::get('API_SERVERS');

		if (!array_key_exists($api_server, $api_servers) or empty($api_servers[$api_server]['url'])) {
			$this->logger->error("{$lf} API server '{$api_server}' does not exist in API_SERVERS or has an empty url. Check config.local.php");
			throw new \Exception("Error. Contact admin");
		}
		// XXX have not checked if it works with /subfolder in WEB_BASE
		$url = $api_servers[$api_server]['url'] . $_ENV['WEB_BASE'] . Config::get('GM_WEB_API_PATH');

		if (array_key_exists('options', $api_servers[$api_server])) {
			$options = $api_servers[$api_server]['options'];
			$apiClient = new ApiClient($options);
		} else {
			$apiClient = new ApiClient();
		}

		$data = array(
			'id' => $id,
			'remote_user' => $this->email
		);

		$response = $apiClient->postWithAuth(
			$url,
			$data,
			$_ENV['WEB_API_USER'],
			$_ENV['WEB_API_PASS']
		);

		try {
			$statusCode = $response->getStatusCode();
			$mail_file = $response->getContent(false); // Don't throw on error status

			if ($statusCode !== Response::HTTP_OK) {
				$this->logger->error("{$lf} wrong response code: {$statusCode} from API server '{$api_server}'. API server said: '{$mail_file}'");
				throw new \Exception("Error. Contact admin");
			}
		// SSL/TLS problems
		} catch (TransportException $e) {
			$this->logger->error("{$lf} problem: " . $e->getMessage());
			throw new \Exception("Error. Contact admin");
		}

		$mailobject->setParser(new Parser());
		$mailobject->setText($mail_file);
		$mailobject->setMessageBody();
		$mailobject->setAttached();
		return $mailobject;
	}

	public function getMailObject(int $id): MailObject {
		$lf = "[getMailObject]";

		if (empty($id)) {
			$this->logger->error("{$lf} empty mail id");
			throw new \Exception("Error. Contact admin");
		}

		try {
			// has applyUserScope
			$maillog = $this->showOne($id);
		} catch (\InvalidArgumentException $e) {
			$this->logger->warning("{$lf} " . $e->getMessage() . ". Mail does not exist or user does not have access to it" , ['email' => $this->email, 'is_admin' => $this->is_admin]);
			throw new \Exception($e->getMessage());
		}

		if (!$maillog->mail_stored) {
			throw new \Exception("Mail with ID '{$id}' not stored");
		}

		// Mail stored locally
		if ($_ENV['MY_API_SERVER_ALIAS'] === $maillog->server) {
			try {
				// has applyUserScope
				$mailobject = $this->getMailObjectLocal($id);
			} catch (\Exception $e) {
				throw new \Exception($e->getMessage());
			}
		// Mail stored in remote server. Call their API
		} else {
			try {
				// has applyUserScope
				$mailobject = $this->getMailObjectViaApi($maillog->id, $maillog->server);
			} catch (\Exception $e) {
				throw new \Exception($e->getMessage());
			}
		}

		return $mailobject;
	}

	public function getAttachment(array $attached, int $id): MailAttachment {
		if (!isset($attached[$id])) {
			throw new \Exception("Attachment not found");
		}

		return new MailAttachment($attached[$id]);
	}

	protected function applyUserScope(Builder $query): Builder {
		if (defined('CLI_MODE') && CLI_MODE) {
			return $query;
		}
		if (empty($this->email)) {
			return $query->where('id', null);
		}
		if(!$this->is_admin) {
			/* Old code, works for single rcpt_to entries only.
			   We might have comma separeted values
			// user has no aliases
			if (empty($this->user_aliases)) {
				return $query->where('rcpt_to', $this->email);
			}
			// Combine primary email with mail aliases
			$emails = array_unique(array_filter(array_merge([$this->email], $this->user_aliases ?? [])));
			return $query->whereIn('rcpt_to', $emails);
			*/

			/* XXX if aliases change and user is logged in,
			   old values remain in user's session.
				User has to logout/login to update values
			*/
			$emails = array_unique(array_filter(array_merge([$this->email], $this->user_aliases ?? [])));
			return $query->where(function ($q) use ($emails) {
				foreach ($emails as $email) {
					$q->orWhere(function ($subQ) use ($email) {
						// Remove spaces in rcpt_to to normalize
						$subQ->whereRaw('REPLACE(rcpt_to, " ", "") = ?', [$email])
							  ->orWhereRaw('REPLACE(rcpt_to, " ", "") LIKE ?', [$email . ',%'])
							  ->orWhereRaw('REPLACE(rcpt_to, " ", "") LIKE ?', ['%,' . $email])
							  ->orWhereRaw('REPLACE(rcpt_to, " ", "") LIKE ?', ['%,' . $email . ',%']);
					});
				}
			});
		}
		return $query;
	}

	// twig can be null, it will be created
	public function releaseHtmlMail(array $release_to, MailLog $maillog, ?Environment $twig = null): bool {
		$mailer = new MailerService($this->logger, $twig);
		$from = $_ENV['MAILER_FROM'];
		$signature = Config::get('mail_signature');
		$subject = Config::get('release_mail_subject');

		$ar = Helper::format_symbols($maillog->symbols, $maillog->score, $maillog->has_virus);
		$vars = array(
			'created_at' => $maillog->created_at,
			'subject'    => $maillog->subject,
			'qid'        => $maillog->qid,
			'message_id' => $maillog->message_id,
			'score'      => $maillog->score,
			'has_virus'  => $maillog->has_virus,
			'virus_name' => $ar['virus_found'],
			'mime_from'  => $maillog->mime_from,
			'rcpt_to'    => $maillog->rcpt_to,
			'action'     => $maillog->action,
			'signature'  => $signature,
		);

		$text_part = Helper::getReleaseText($vars);

		// make array of recipients
		$recipients = array_map('trim', explode(',', $release_to[0]));

		$send_mail = $mailer->sendTemplatedEmail(
			$from,
			$recipients,
			$subject,
			'mail/release.html.twig',  // twig template
			$text_part,                // Text part of mail
			$vars,                     // twig vars
			$maillog->mail_location,   // path to raw mail
			'Original Message.eml',    // attachment name
		);

		if ($send_mail) {
			$maillog->released = 1;
			$maillog->release_date = date("Y-m-d H:i:s");
			$maillog->save();
			return true;
		}
		return false;
	}

	public function releaseMailViaApi(array $release_to, int $id, string $api_server, string $remote_user): bool {
		$lf = "[releaseMailViaApi]";

		if (empty($id)) {
			$this->logger->error("{$lf} empty mail id");
			return false;
		}
		if (empty($api_server)) {
			$this->logger->error("{$lf} empty api server");
			return null;
		}
		if (empty($release_to)) {
			$this->logger->error("{$lf} empty recipients");
			return false;
		}
		if (empty($remote_user)) {
			$this->logger->error("{$lf} empty local user email");
			return false;
		}

		$api_servers = Config::get('API_SERVERS');

		if (!array_key_exists($api_server, $api_servers) or empty($api_servers[$api_server]['url'])) {
			$this->logger->error("{$lf} API server '{$api_server}' does not exist in API_SERVERS or has an empty url. Check config.local.php");
			return false;
		}
		// XXX have not checked if it works with /subfolder in WEB_BASE
		$url = $api_servers[$api_server]['url'] . $_ENV['WEB_BASE'] . Config::get('RM_WEB_API_PATH');

		if (array_key_exists('options', $api_servers[$api_server])) {
			$options = $api_servers[$api_server]['options'];
			$apiClient = new ApiClient($options);
		} else {
			$apiClient = new ApiClient();
		}

		$data = array(
			'id' => $id,
			'email' => $release_to,
			'remote_user' => $remote_user,
		);

		$response = $apiClient->postWithAuth(
			$url,
			$data,
			$_ENV['WEB_API_USER'],
			$_ENV['WEB_API_PASS']
		);

		try {
			$statusCode = $response->getStatusCode();
			if ($statusCode === Response::HTTP_OK) {
				return true;
			} else {
				$error_msg = $response->getContent(false); // Don't throw on error status
				$this->logger->error("{$lf} wrong response code: {$statusCode} from API server '{$api_server}'. 'API server said: {$error_msg}'");
				return false;
			}
		// SSL/TLS problems
		} catch (TransportException $e) {
			$this->logger->error("{$lf} problem: " . $e->getMessage());
			return false;
		}

		return false;
	}

	public function notifyHtmlMail(MailLog $maillog, string $detailurl, ?Environment $twig = null): bool {

		$mailer = new MailerService($this->logger, $twig);
		$from = $_ENV['MAILER_FROM'];
		$signature = Config::get('mail_signature');
		$subject = Config::get('notify_mail_subject');

		$vars = array(
			'created_at' => $maillog->created_at,
			'subject'    => $maillog->subject,
			'qid'        => $maillog->qid,
			'score'      => $maillog->score,
			'has_virus'  => $maillog->has_virus,
			'virus_name' => $maillog->virus_name,
			'mime_from'  => $maillog->mime_from,
			'rcpt_to'    => $maillog->rcpt_to,
			'action'     => $maillog->action,
			'detailurl'  => $detailurl,
			'signature'  => $signature,
		);

		$text_part = Helper::getNotifyText($vars);

		// make array of recipients
		$recipients = array_map('trim', explode(',', $maillog->rcpt_to));

		$send_mail = $mailer->sendTemplatedEmail(
			$from,
			$recipients,
			$subject,
			'mail/notify.html.twig',  // twig template
			$text_part,                // Text part of mail
			$vars,                     // twig vars
		);

		if ($send_mail) {
			// these were modified just for producing the mail
			// don't push changed back to DB. Needed for both save() and update()
			unset($maillog->virus_name);
			unset($maillog->virus_found);
			unset($maillog->symbols);
			/*
			$maillog->notified = 1;
			$maillog->notify_date = date("Y-m-d H:i:s");
			$maillog->save();
			*/
			$maillog->update([
				'notified' => 1,
				'notify_date' => date("Y-m-d H:i:s"),
			]);
			return true;
		}
		return false;
	}

	// returns mail_logs with notification pending
	public function getUnnotified(
		?OutputInterface $cli_output = null,
		?string $server = null
	): Collection {

		$fields = MailLog::SELECT_FIELDS;

		$query = MailLog::select($fields)
					->where('notification_pending', 1);
					/*
					->orderBy('id', 'ASC')
					->where('mail_stored', 1)
					->where('notified', 0)
					->whereIn('action', ['discard', 'reject']); // undelivered
					*/

		if ($server) {
			$query = $query->where('server', $server);
		}

		$query = $this->applyUserScope($query);

		if (Helper::env_bool('DEBUG_SEARCH_SQL')) {
			$query_str = self::getSqlFromQuery($query);
			if ($cli_output) {
				$cli_output->writeln("<info>{$query_str}</info>",
					OutputInterface::VERBOSITY_VERBOSE);
			} else {
				$this->logger->info($query_str);
			}
		}

		$logs = $query->get();

		return $logs;
	}

	// returns mail_logs in quarantine before QUARANTINE_DAYS
	public function getQuarantine(
		?OutputInterface $cli_output = null,
		?string $server = null
	): Collection {

		$fields = MailLog::SELECT_FIELDS;

		$days = (int) ($_ENV['QUARANTINE_DAYS'] ?? 365);
		$cutoffDate = new \DateTime();
		$cutoffDate->sub(new \DateInterval("P{$days}D")); // Subtract days

		$query = MailLog::select($fields)
					->where('mail_stored', 1)
					->where('created_at', '<', $cutoffDate->format('Y-m-d H:i:s'));

		if ($server) {
			$query = $query->where('server', $server);
		}

		$query = $this->applyUserScope($query);

		if (Helper::env_bool('DEBUG_SEARCH_SQL')) {
			$query_str = self::getSqlFromQuery($query);
			if ($cli_output) {
				$cli_output->writeln("<info>{$query_str}</info>",
					OutputInterface::VERBOSITY_VERBOSE);
			} else {
				$this->logger->info($query_str);
			}
		}

		$logs = $query->get();

		return $logs;
	}

	// cleans quarantine
	public function cleanQuarantine(
		Collection $mailLogs,
		?OutputInterface $cli_output = null
	): void {

		$ids = [];
		foreach ($mailLogs as $mailLog) {
			if ($mailLog->mail_location) {
				$dirPath = dirname($mailLog->mail_location);
				if (Helper::deleteDirectory($dirPath)) {
					$ids[] = $mailLog->id;
					if ($cli_output) {
						$cli_output->writeln("<info>Deleted {$mailLog->id} {$dirPath}</info>",
							OutputInterface::VERBOSITY_VERBOSE);
					}
					$this->logger->info("[cleanQuarantine] Deleted mail {$mailLog->qid} from {$dirPath}");
				}
			}
		}

		// clear mail_stored in DB
		//$ids = $maillogs->pluck('id')->toArray();
		if ($ids) {
			MailLog::whereIn('id', $ids)->update(['mail_stored' => 0]);
			if ($cli_output) {
				$cnt = count($ids);
				$cli_output->writeln("<info>Setting mail_stored to 0 on {$cnt} entries</info>",
					OutputInterface::VERBOSITY_VERBOSE);
				}
		}
	}
}
