<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2025 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App\Services;

use App\Configuration\AppConfig;
use App\Configuration\Config;

use App\Core\App;

use App\Utils\Helper;
use App\Utils\FormHelper;

use App\Models\MailLog;

use App\Core\Database\MigrationStatus;
use App\Inventory\Migrations;

use App\Inventory\MailObject;
use App\Inventory\MailAttachment;

use Psr\Log\LoggerInterface;

//use App\Services\MailerService;
//use App\Services\ApiClient;

use Twig\Environment;

use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpFoundation\Response;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

use Illuminate\Database\Capsule\Manager as DB;

use App\Core\Routing\RouteName;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use Symfony\Component\Console\Output\OutputInterface;

use PhpMimeMailParser\Parser;

use DateTime;
use DateInterval;

use Exception;
use InvalidArgumentException;

class MailLogService
{
	private LoggerInterface $logger;
	private MigrationStatus $migrationStatus;

	public const int CLEANDB_CHUNK = 1000;

	private ?bool $is_admin = null;
	private ?string $username = null;
	private ?string $email = null;
	private ?array $user_aliases = null;

	protected $items_per_page;
	protected $q_items_per_page;
	protected $max_items;

	public function __construct(?array $userContext = null) {
		$this->logger = App::fileLogger();
		$this->migrationStatus = App::migrationStatus();

		if (!empty($userContext)) {
			$this->is_admin = $userContext['is_admin'] ?? null;
			$this->username = $userContext['username'] ?? null;
			$this->email = $userContext['email'] ?? null;
			$this->user_aliases = $userContext['user_aliases'] ?? null;
		}

		$this->items_per_page = Config::get('items_per_page');
		$this->q_items_per_page = Config::get('q_items_per_page');
		$this->max_items = Config::get('max_items');
	}

	public static function getSqlFromQuery(Builder $query): string {
		return vsprintf(str_replace('?', '"%s"', $query->toSql()), $query->getBindings());
	}

	public function getQueryByFilters(Builder $query, array $filters): Builder {
		if (!empty($filters)) {
			$filters = FormHelper::getFilterByName($filters);
		}

		if (!empty($filters)) {
			foreach ($filters as $filter) {
				if (array_key_exists('filter', $filter) &&
				    array_key_exists('choice', $filter) &&
					 array_key_exists('value', $filter) &&
					 !empty($filter['filter'])
				) {
						$f = $filter['filter'];
						$c = $filter['choice'];
						$v = $filter['value'];

						if ($f === 'rcpt_to') {
							$this->filterByRecipient($query, $c, $v);

							continue; // don't run $query->where('rcpt_to', ...) on mail_logs
						}

						if ($f === 'headers' || $f === 'symbols') {
							$this->filterByMailLogData($query, $f, $c, $v);

							continue; // don't run $query->where('headers', ...) on mail_logs
						}

						if ($c === 'LIKE') {
							$v = "%{$v}%";
						}
						if ($c === 'NOT LIKE') {
							$v = "%{$v}%";
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

	/*
	 rcpt_to is denormalised into mail_log_recipients, so the filter has to
	 run against the relation. The caller must still skip its own
	 $query->where('rcpt_to', ...) for every choice, including the ones with
	 no branch here - the mail_logs rcpt_to column is the legacy compatibility layer.

	 MailRecipientsMigration is in Migrations::REQUIRED, so the relation is
	 guaranteed populated at boot.
	*/
	private function filterByRecipient(Builder $query, string $c, mixed $v): void {
		$negate = in_array($c, ['<>', '!=', 'NOT LIKE', 'NOT REGEXP'], true);

		$op = match ($c) {
			'<>', '!='   => '=',
			'NOT LIKE'   => 'LIKE',
			'NOT REGEXP' => 'REGEXP',
			default      => $c,
		};

		$email = strtolower(trim((string) $v));
		if ($op === 'LIKE') {
			$email = "%{$email}%";
		}

		if ($negate) {
			$query->whereDoesntHave('recipients', function ($q) use ($op, $email) {
				$q->where('recipient_email', $op, $email);
			});
		} else {
			$query->whereHas('recipients', function ($q) use ($op, $email) {
				$q->where('recipient_email', $op, $email);
			});
		}
	}

	/*
	 headers and symbols live only on mail_log_data.
	 MAIL_LOG_DATA is in Migrations::REQUIRED, so the relation is
	 guaranteed populated at boot and needs no status check.

	 Negation goes through whereDoesntHave with the positive operator rather
	 than passing NOT LIKE into whereHas: the two differ for a mail with no
	 mail_log_data row, and "does not contain" should include it.

	 symbols is declared JSON, which MariaDB stores as
	 longtext COLLATE utf8mb4_bin, so an uncollated compare is case-sensitive
	 and 'bayes_spam' would never match BAYES_SPAM. Comparing in the table's
	 own collation fixes that without touching the needle - symbol options
	 carry mixed-case URLs and hostnames, so upper-casing the value would
	 break searching on those. On headers, which already takes the table
	 default, the override is a no-op.
	*/
	private function filterByMailLogData(Builder $query, string $f, string $c, mixed $v): void {
		$negate = in_array($c, ['<>', '!=', 'NOT LIKE', 'NOT REGEXP'], true);

		$op = match ($c) {
			'=', '<>', '!=' => 'LIKE',
			'NOT LIKE'      => 'LIKE',
			'NOT REGEXP'    => 'REGEXP',
			default         => $c,
		};

		$val = (string) $v;
		if ($op === 'LIKE') {
			$val = "%{$val}%";
		}

		$col = DB::raw(
			'`' . AppConfig::MAIL_LOG_DATA_TABLE . '`.`' . $f . '`'
			. ' COLLATE ' . AppConfig::DB_COLLATION
		);

		if ($negate) {
			$query->whereDoesntHave('mailLogData', function ($q) use ($col, $op, $val) {
				$q->where($col, $op, $val);
			});
		} else {
			$query->whereHas('mailLogData', function ($q) use ($col, $op, $val) {
				$q->where($col, $op, $val);
			});
		}
	}

	public function cleanDb(Builder $query, int $batch): int {
		if ($batch < 1) {
			$batch = self::CLEANDB_CHUNK;
		}

		$deleted = 0;

		// Each pass deletes the rows it just selected, so the same query
		// yields the next set — no offset, no lastId. MailLog has no soft
		// deletes, so a deleted row cannot match again. Peak memory is
		// $batch ids however far behind the cleanup is.
		while (true) {
			$ids = (clone $query)->orderBy('id')->limit($batch)->pluck('id')->all();

			if (empty($ids)) {
				break;
			}

			$removed = MailLog::whereIn('id', $ids)->delete();

			if ($removed < 1) {
				// nothing went away; stop rather than spin
				break;
			}

			$deleted += $removed;
		}

		return $deleted;
	}

	public function getCleanUpDb(
		?OutputInterface $cli_output = null,
		?string $server = null
	): Builder {
		$fields = ['id', 'qid'];

		/*
		$query = MailLog::select($fields)
					->orderBy('id', 'DESC');
		*/

		$days = (int) ($_ENV['DATABASE_DAYS'] ?? 366);
		$cutoffDate = new DateTime();
		$cutoffDate->sub(new DateInterval("P{$days}D")); // Subtract days

		$query = MailLog::select($fields)
					->where('created_day', '<', $cutoffDate->format('Y-m-d'));

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

		return $query;
	}

	private function getMailLog(int $id): MailLog {
		$lf = "[MailLogService_getMailLog]";
		$fields = MailLog::SELECT_FIELDS;

		$query = MailLog::select($fields)
			->with($this->getMailLogSymbolsRelations())
			->where('id', $id);

		$query = $this->applyUserScope($query);

		if (Helper::env_bool('DEBUG_SEARCH_SQL')) {
			$this->logger->info(self::getSqlFromQuery($query));
		}

		$log = $query
			->first();

		if (!$log) {
			$err = "Mail with ID '{$id}' not found";
			Helper::debug_exception_err("{$lf} $err");
			throw new InvalidArgumentException($err);
		}

		return $log;
	}

	public function findMailLog(int $id): MailLog {
		$lf = "[MailLogService_findMailLog]";

		$query = MailLog::with($this->getMailLogSymbolsRelations());

		if (Helper::env_bool('DEBUG_SEARCH_SQL')) {
			$this->logger->info(self::getSqlFromQuery($query));
		}

		$log = $query
			->find($id);

		if (!$log) {
			$err = "Mail with ID '{$id}' not found";
			Helper::debug_exception_err("{$lf} $err");
			throw new InvalidArgumentException($err);
		}

		return $log;
	}

	public function getQuarantinedMailLog(int $id): MailLog {
		$lf = "[MailLogService_getQuarantinedMailLog]";
		$fields = MailLog::SELECT_FIELDS;

		$query = MailLog::select($fields)
			->with($this->getMailLogSymbolsRelations())
			->where('id', $id)
			->where('mail_stored', 1);

		$query = $this->applyUserScope($query);

		if (Helper::env_bool('DEBUG_SEARCH_SQL')) {
			$this->logger->info(self::getSqlFromQuery($query));
		}

		$log = $query->first();

		if (!$log) {
			$err = "Mail with ID '{$id}' not found";
			Helper::debug_exception_err("{$lf} {$err}");
			throw new InvalidArgumentException($err);
		}

		return $log;
	}

	public function getPaginatedAll(
		array $filters,
		string $url,
		int $page = 1
	): LengthAwarePaginator {

		$lf = "MailLogService_getPaginatedAll";

		$fields = MailLog::SELECT_FIELDS;

		// Resolve the matching ids first, capped at max_items.
		//
		// With a small LIMIT and `order by id desc` MariaDB walks the PRIMARY
		// key backwards and stops once it has enough rows, skipping the sort.
		// When fewer rows match than the LIMIT asks for, that walk never
		// terminates early and scans the whole table. At max_items the
		// estimated walk cost always loses to a range scan on the filtered
		// columns, so the plan stays stable regardless of match count.
		$idQuery = $this->applyUserScope(
			$this->getQueryByFilters(MailLog::select('id'), $filters)
		)
			->orderBy('id', 'DESC')
			->limit($this->max_items);

		if (Helper::env_bool('DEBUG_SEARCH_SQL')) {
			$this->logger->info(self::getSqlFromQuery($idQuery));
		}

		try {
			// pluck() goes through toBase(), so no eager loads fire here
			$ids = $idQuery->pluck('id')->all();

			// already capped by the limit on $idQuery
			$total = count($ids);

			$pageIds = array_slice(
				$ids,
				($page - 1) * $this->items_per_page,
				$this->items_per_page
			);

			if (empty($pageIds)) {
				$itemsForPage = new Collection();
			} else {
				$query = MailLog::select($fields)
					->with($this->getMailLogSymbolsRelations())
					->whereIn('id', $pageIds)
					->orderBy('id', 'DESC');

				if (Helper::env_bool('DEBUG_SEARCH_SQL')) {
					$this->logger->info(self::getSqlFromQuery($query));
				}

				$itemsForPage = $query->get();
			}

			// we need to enforce the upper limit,
			// that's why the manual LengthAwarePaginator paginator
			$paginator = new LengthAwarePaginator(
				$itemsForPage,
				$total,
				$this->items_per_page,
				$page
			);

			$logs = $paginator->withPath($url);
		} catch (Exception $e) {
			$this->logger->error("{$lf} Query error: " . $e->getMessage());
			Helper::failRequest("Query error");
		}

		return $logs;
	}

	public function getReports(
		array $filters,
		string $field,
		string $mode = 'count'
	): Collection {

		$lf = "MailLogService_getReports";

		/*
		 $field is interpolated into selectRaw() below, including the
		 default branch's "{$field} AS {$field}". The whitelist belongs here
		 and not only at the caller: this method is public, and a second
		 caller added without MailLogController's in_array() gate would be
		 an injection. Fail closed with no rows; the caller keeps its own
		 check because it owns the flash message and the redirect.
		*/
		if (!in_array($field, MailLog::REPORT_FIELDS, true)) {
			$this->logger->error("{$lf} rejected field: {$field}");
			return new Collection();
		}

		switch($field) {
			case 'mail_from_domain':
			case 'rcpt_to_domain':
			case 'mime_from_domain':
			case 'mime_to_domain':
				$baseField = str_replace('_domain', '', $field);
				$query = MailLog::selectRaw("LOWER(TRIM(TRAILING '>' FROM SUBSTRING_INDEX({$baseField}, '@', -1))) AS {$field}, COUNT(*) AS total, SUM(size) as total_size, SUM(mail_stored) AS total_stored")
				                ->where($baseField, 'LIKE', '%@%')
				                ->groupBy($field);
				break;
			case 'date':
				$query = MailLog::selectRaw("created_day AS date, COUNT(*) AS total, SUM(size) as total_size, SUM(mail_stored) AS total_stored")
					->groupBy('created_day');
				break;
			default:
				//$fields = [ $field, DB::raw('count(*) as total') ];
				//$query = MailLog::select($fields);
				$query = MailLog::selectRaw("{$field} AS {$field}, COUNT(*) AS total, SUM(size) as total_size, SUM(mail_stored) AS total_stored")
				                ->groupBy($field);
				break;
		}

		$query = $this->getQueryByFilters($query, $filters);
		$query = $this->applyUserScope($query);

		if ($mode === 'volume') {
			$query->orderBy('total_size', 'DESC');
		} elseif ($mode === 'stored') {
			$query->orderBy('total_stored', 'DESC');
		} elseif ($field == 'date' && $mode === 'day') {
			$query->orderByDesc('date');
		} else {
			$query->orderBy('total', 'DESC');
		}

		$query->limit(Config::get('top_reports'));

		if (Helper::env_bool('DEBUG_SEARCH_SQL')) {
			$this->logger->info(self::getSqlFromQuery($query));
		}

		try {
			$logs = $query->get();
		} catch (Exception $e) {
			$this->logger->error("{$lf} Query error: " . $e->getMessage());
			Helper::failRequest("Query error");
		}

		return $logs;
	}

	/*
	 SearchForm now rejects an invalid REGEXP before it can be stored, which
	 removes the one trigger reachable from the form. This is the backstop
	 for the rest: a lock wait or deadlock during the stats query, a lost
	 connection, or a pattern this PCRE2 build accepts and the server's
	 rejects.

	 The body is a separate method so the catch covers every statement it
	 issues without re-indenting them. Returning zeroed stats would render
	 a page reporting no matches, indistinguishable from a search that
	 legitimately found none, so failure is null and the caller drops the
	 whole statistics block.

	 Null rather than failRequest() because search() persists the filter to
	 the session before this runs, and the only Remove links live in
	 search.twig — killing the response leaves the page failing on every
	 later visit. The stats are the sole query on that route, so degrading
	 them renders the filter list and its Remove links instead.
	*/
	public function getStats(array $filters): ?array {
		$lf = "MailLogService_getStats";

		try {
			return $this->collectStats($filters);
		} catch (Exception $e) {
			$this->logger->error("{$lf} Query error: " . $e->getMessage());

			return null;
		}
	}

	private function collectStats(array $filters): array {
		$fields = ['id'];

		$query = MailLog::select($fields);
		$query = $this->getQueryByFilters($query, $filters);
		$query = $this->applyUserScope($query);

		if (Helper::env_bool('DEBUG_SEARCH_SQL')) {
			$this->logger->info(self::getSqlFromQuery($query));
		}

		$stats['count'] = $query->count();

		if (($stats['count']) > 0) {
			$stats['first'] = $this->getFirstMailDate($query)
				->first()?->created_at->toDateTimeString();
			$stats['last'] = $this->getLastMailDate($query)
				->first()?->created_at->toDateTimeString();
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

			$stats['action'] = array_merge([
				'no action'       => 0,
				'add header'      => 0,
				'rewrite subject' => 0,
				'reject'          => 0,
				'discard'         => 0,
			], $stats['action']);

			return $stats;
		}

		return array(
			'count' => 0,
			'last' => null,
			'first' => null,
			'stored' => 0,
			'notified' => 0,
			'released' => 0,
			'has_virus' => 0,
			'action' => [
				'no action' => 0,
				'add header' => 0,
				'rewrite subject' => 0,
				'reject' => 0,
				'discard' => 0,
			],
		);
	}

	public function getPaginatedDay(?string $date, string $url, int $page = 1): LengthAwarePaginator {
		$fields = MailLog::SELECT_FIELDS;

		if (!$date) {
			$date = Helper::get_today();
		}

		$query = MailLog::select($fields)
			->with($this->getMailLogSymbolsRelations())
			->select($fields);

		$query->where('created_day', $date);

		$query = $query
			->orderBy('id', 'DESC');

		$query = $this->applyUserScope($query);

		if (Helper::env_bool('DEBUG_SEARCH_SQL')) {
			$this->logger->info(self::getSqlFromQuery($query));
		}

		return $query
			->paginate($this->items_per_page, $fields, 'page', $page)
			->withPath($url);
	}

	public function getPaginatedQuarantineDay(?string $date, string $url, int $page = 1): LengthAwarePaginator {
		$fields = MailLog::SELECT_FIELDS;

		if (!$date) {
			$date = Helper::get_today();
		}

		$query = MailLog::select($fields)
			->with($this->getMailLogSymbolsRelations());

		$query->where('created_day', $date);

		$query = $query
			->where('mail_stored', 1)
			->orderBy('id', 'DESC');

		$query = $this->applyUserScope($query);

		if (Helper::env_bool('DEBUG_SEARCH_SQL')) {
			$this->logger->info(self::getSqlFromQuery($query));
		}

		return $query
			->paginate($this->items_per_page, $fields, 'page', $page)
			->withPath($url);
	}

	public function getPaginatedQuarantine(string $url, int $page = 1): LengthAwarePaginator {
		$query = MailLog::selectRaw('created_day as day, COUNT(*) as cnt');

		$query = $query
			->where('mail_stored', 1)
			->groupByRaw('day')
			->orderByDesc('day')
			->limit((int)$_ENV['QUARANTINE_DAYS']);

		$query = $this->applyUserScope($query);

		if (Helper::env_bool('DEBUG_SEARCH_SQL')) {
			$this->logger->info(self::getSqlFromQuery($query));
		}

		// days
		return $query
			->paginate($this->q_items_per_page, ['day', 'cnt'], 'page', $page)
			->withPath($url);
	}

	public function detailById(int $id): MailLog {
		$lf = "[MailLogService_detailById]";
		$query = MailLog::with($this->getMailLogRelations())
			->where('id', $id);

		$query = $this->applyUserScope($query);

		if (Helper::env_bool('DEBUG_SEARCH_SQL')) {
			$this->logger->info(self::getSqlFromQuery($query));
		}

		$log = $query->first();

		if (!$log) {
			$err = "Mail with ID '{$id}' not found";
			Helper::debug_exception_err("{$lf} {$err}");
			throw new InvalidArgumentException($err);
		}

		return $log;
	}

	public function detailByQid(string $qid): MailLog {
		$lf = "MailLogService_detailByQid";

		$query = MailLog::with($this->getMailLogRelations())
			->where('qid', $qid);

		$query = $this->applyUserScope($query);

		if (Helper::env_bool('DEBUG_SEARCH_SQL')) {
			$this->logger->info(self::getSqlFromQuery($query));
		}

		$log = $query->first();
		if (!$log) {
			$err = "Mail with QID '{$qid}' not found";
			Helper::debug_exception_err("{$lf} {$err} by user: '" . $this->username . "'");
			throw new InvalidArgumentException($err);
		}
		return $log;
	}

	public function detail(string $type, string|int $value): array {
		$lf = "MailLogService_detail";

		$check = Helper::check_id_qid($type, $value);

		if ($check['error']) {
			$err = "Error: {$check['error']}";
			Helper::debug_exception_err("{$lf} {$err}");
			throw new InvalidArgumentException($err);
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
			$err = "Unknown error";
			Helper::debug_exception_err("{$lf} {$err}");
			throw new InvalidArgumentException($err);
		}

		/*
		 Local, not $log->symbols = [] -- the accessor reads mail_log_data,
		 so a write to the model attribute is ignored on the next read
		*/
		$symbols = $log->symbols ?? [];

		// order symbols by score and show printable information only
		$ar = Helper::format_symbols($symbols, $log->score, $log->has_virus);

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

		return array(
			'log' => $log,
			'symbols' => $ar['symbols'],
			'virus_found' => $ar['virus_found'],
			'received' => $received,
		);
	}

	public function getMailObjectLocal(int $id): MailObject {
		$lf = "[MailLogService_getMailObjectLocal]";

		if (empty($id)) {
			$this->logger->error("{$lf} empty mail id");
			Helper::debug_exception_err("{$lf} empty mail id");
			throw new Exception("Error. Contact admin");
		}

		try {
			// has applyUserScope
			$ar = $this->detail('id', $id);
		} catch (InvalidArgumentException $e) {
			$err = "{$lf} Mail with ID '{$id}' not found";
			Helper::debug_exception_err($err);
			throw new Exception($err);
		}

		$mailobject = new MailObject($ar);

		if (!$mailobject->isMailStored()) {
			$err = "{$lf} Mail with ID '{$id}' not stored";
			Helper::debug_exception_err($err);
			throw new Exception($err);
		}

		$location = $mailobject->getMailLocation();
		if (!file_exists($location)) {
			$err = "{$lf} File '$location' not found";
			Helper::debug_exception_err($err);
			throw new Exception($err);
		}

		$mailobject->setParser(new Parser());
		$mailobject->setPath($location);
		$mailobject->setMessageBody();
		$mailobject->setAttached();

		return $mailobject;
	}

	public function getMailObjectViaApi(int $id, string $api_server): MailObject {
		$lf = "[MailLogService_getMailObjectViaApi]";

		if (empty($id)) {
			Helper::debug_exception_err("{$lf} empty mail id");
			throw new Exception("Error. Contact admin");
		}
		if (empty($api_server)) {
			Helper::debug_exception_err("{$lf} empty api server");
			throw new Exception("Error. Contact admin");
		}

		// get details from DB locally
		try {
			// has applyUserScope
			$ar = $this->detail('id', $id);
		} catch (InvalidArgumentException $e) {
			Helper::debug_exception_err("{$lf} InvalidArgumentException for id {$id}: " . $e->getMessage());
			throw new Exception($e->getMessage());
		}

		$mailobject = new MailObject($ar);

		if (!$mailobject->isMailStored()) {
			Helper::debug_exception_err("{$lf} Mail with id {$id} is not stored");
			throw new Exception("Mail with id {$id} is not stored");
		}

		// get raw mail from remote API server
		$api_servers = Config::get('API_SERVERS');

		if (!array_key_exists($api_server, $api_servers) or empty($api_servers[$api_server]['url'])) {
			Helper::debug_exception_err("{$lf} API server '{$api_server}' does not exist in API_SERVERS or has an empty url. Check config.local.php");
			throw new Exception("Error. Contact admin");
		}
		// XXX have not checked if it works with remote /subfolder in WEB_BASE
		$url = $api_servers[$api_server]['url'] . Config::get('GET_MAIL_API_PATH');

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
			$_ENV['MAIL_API_USER'],
			$_ENV['MAIL_API_PASS']
		);

		try {
			$statusCode = $response->getStatusCode();
			$mail_file = $response->getContent(false); // Don't throw on error status

			if ($statusCode === Response::HTTP_FORBIDDEN) {
				$this->logger->error("{$lf} wrong response code: {$statusCode} Forbidden, from API server '{$api_server}'. API server said: " . PHP_EOL . "'{$mail_file}'");
				// our API returns this
				if ($mail_file == 'Permission denied') {
					$this->logger->warning("{$lf} Check local and remote MAIL_API_USER, MAIL_API_PASS, MAIL_API_ACL");
				} else {
					// apache returns full http response
					$this->logger->warning("{$lf} Check remote web server access control as well as local and remote MAIL_API_USER, MAIL_API_PASS, MAIL_API_ACL");
				}
				throw new Exception("Error. Contact admin");
			} else if ($statusCode !== Response::HTTP_OK) {
				$this->logger->error("{$lf} wrong response code: {$statusCode} from API server '{$api_server}'. API server said: '{$mail_file}'");
				throw new Exception("Error. Contact admin");
			}
		// SSL/TLS problems
		} catch (TransportException $e) {
			Helper::debug_exception_err("{$lf} problem: " . $e->getMessage());
			throw new Exception("Error. Contact admin");
		} catch (Exception $e) {
			Helper::debug_exception_err("{$lf} problem: " . $e->getMessage());
			throw new Exception("Error. Contact admin");
		}

		$mailobject->setParser(new Parser());
		$mailobject->setText($mail_file);
		$mailobject->setMessageBody();
		$mailobject->setAttached();
		return $mailobject;
	}

	public function getMailObject(int $id): MailObject {
		$lf = "[MailLogService_getMailObject]";

		if (empty($id)) {
			Helper::debug_exception_err("{$lf} empty mail id");
			throw new Exception("Error. Contact admin");
		}

		try {
			// has applyUserScope
			// throws if mail not found
			$maillog = $this->getMailLog($id);
		} catch (InvalidArgumentException $e) {
			$this->logger->warning("{$lf} " . $e->getMessage() . ". Mail does not exist or user does not have access to it" , ['email' => $this->email, 'is_admin' => $this->is_admin]);
			$err = $e->getMessage();
			Helper::debug_exception_err("$lf " . $err);
			throw new Exception($err);
		}

		if (!$maillog->mail_stored) {
			$err = "Mail with ID '{$id}' not stored";
			Helper::debug_exception_err("$lf " . $err);
			throw new Exception($err);
		}

		// Mail stored locally
		if ($_ENV['MY_API_SERVER_ALIAS'] === $maillog->server) {
			try {
				// has applyUserScope
				$mailobject = $this->getMailObjectLocal($id);
			} catch (Exception $e) {
				$err = $e->getMessage();
				Helper::debug_exception_err("$lf " . $err);
				throw new Exception($err);
			}
		// Mail stored in remote server. Call their API
		} else {
			try {
				// has applyUserScope
				if (empty($_ENV['MAIL_API_USER']) || empty($_ENV['MAIL_API_PASS'])) {
					$this->logger->warning("{$lf} MAIL_API_USER or MAIL_API_PASS not set");
					throw new Exception("Error. Contact admin");
				}
				$mailobject = $this->getMailObjectViaApi($maillog->id, $maillog->server);
			} catch (Exception $e) {
				$err = $e->getMessage();
				Helper::debug_exception_err("{$lf} {$err}");
				throw new Exception($err);
			}
		}

		return $mailobject;
	}

	public function getAttachment(array $attached, int $id): MailAttachment {
		$lf = "MailLogService_getAttachment";

		if (!isset($attached[$id])) {
			$err = "Attachment not found";
			Helper::debug_exception_err("{$lf} {$err}");
			throw new Exception("Attachment not found");
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
		if($this->is_admin) {
			return $query;
		}

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

		/* old code. slow
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
		*/

		/* OLD code 2. Also slow for more than 200K mail_logs
		$query->where(function ($q) {
			$q->whereRaw("FIND_IN_SET('{$this->email}', rcpt_to)");

			// XXX if aliases change and user is logged in,
			//   old values remain in user's session.
			//	User has to logout/login to update values

			if (!empty($this->user_aliases)) {
				foreach ($this->user_aliases as $user_alias) {
					$q->orWhereRaw("FIND_IN_SET('{$user_alias}', rcpt_to)");
				}
			}
		});

		return $query;
		*/

		// Build list of allowed recipient emails: primary + aliases
		$emails = $this->getUserRecipientEmails();

		return $query->whereExists(function ($q) use ($emails) {
			$q->selectRaw('1')
				->from(AppConfig::MAIL_LOG_RECIPIENTS_TABLE . ' as r')
				->whereColumn('r.mail_log_id', 'mail_logs.id')   // IMPORTANT: table name here
				->whereIn('r.recipient_email', $emails);
			});
	}

	// twig can be null, it will be created
	public function releaseHtmlMail(array $release_to, MailLog $maillog, ?Environment $twig = null): bool {
		$mailer = new MailerService($this->logger, $twig);
		$from = $_ENV['MAILER_FROM'];
		$signature = Config::get('mail_signature');
		$subject = Config::get('release_mail_subject');

		$symbols = $maillog->symbols ?? [];

		$ar = Helper::format_symbols($symbols, $maillog->score, $maillog->has_virus);

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
			return false;
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
		// XXX have not checked if it works with remote /subfolder in WEB_BASE
		$url = $api_servers[$api_server]['url'] . Config::get('RELEASE_MAIL_API_PATH');

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
			$_ENV['MAIL_API_USER'],
			$_ENV['MAIL_API_PASS']
		);

		try {
			$statusCode = $response->getStatusCode();
			if ($statusCode === Response::HTTP_OK) {
				return true;
			} else if ($statusCode === Response::HTTP_FORBIDDEN) {
				$error_msg = $response->getContent(false); // Don't throw on error status
				$this->logger->error("{$lf} wrong response code: {$statusCode} Forbidden, from API server '{$api_server}'. API server said: " . PHP_EOL . "'{$error_msg}'");
				// our API returns this
				if ($error_msg == 'Permission denied') {
					$this->logger->warning("{$lf} Check local and remote MAIL_API_USER, MAIL_API_PASS, MAIL_API_ACL");
				} else {
					// apache returns full http response
					$this->logger->warning("{$lf} Check remote web server access control as well as local and remote MAIL_API_USER, MAIL_API_PASS, MAIL_API_ACL");
				}
				return false;
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

	public function notifyHtmlMail(
		MailLog $maillog,
		UrlGeneratorInterface $urlGenerator,
		?Environment $twig = null
	): bool {

		$mailer = new MailerService($this->logger, $twig);
		$from = $_ENV['MAILER_FROM'];
		$signature = Config::get('mail_signature');
		$subject = Config::get('notify_mail_subject');

		/*
		 One mail per recipient: each gets only their own address in the
		 body and (later) their own release link.

		 Prefer the normalized recipients table: token FKs require rows that
		 exist there. Fall back to rcpt_to when the migration has not run,
		 in which case no tokens are issued.
		*/
		if ($maillog->relationLoaded('recipients')) {
			$recipients = $maillog->recipients->pluck('recipient_email')->all();
		} else {
			$recipients = explode(',', (string) $maillog->rcpt_to);
		}

		$recipients = array_values(array_unique(array_filter(array_map(
			'trim',
			$recipients))));

		if (empty($recipients)) {
			$this->logger->warning(
				"[notifyHtmlMail] no recipients for mail id {$maillog->id}"
			);
			return false;
		}

		$sent = 0;
		$failed = [];

		$detailurl = $urlGenerator->generate(
			RouteName::DETAIL->value,
			[ 'type' => 'id', 'value' => $maillog->id ],
			UrlGeneratorInterface::ABSOLUTE_URL
		);

		// Password-less links need the recipients relation, because the token
		// foreign key references mail_log_recipients. Decided once per mail.
		$tokenService = null;

		if (MailTokenService::isEnabled()
			&& $this->migrationStatus->mailLogTokensCompleted()
			&& $maillog->relationLoaded('recipients')
		) {
			$tokenService = new MailTokenService();
		}

		foreach ($recipients as $recipient) {
			$url = $detailurl;

			if ($tokenService !== null) {
				$token = $tokenService->issueToken($maillog->id, $recipient);

				if ($token !== null) {
					$url = $urlGenerator->generate(
						RouteName::TOKEN_CONFIRM->value,
						[ 'token' => $token ],
						UrlGeneratorInterface::ABSOLUTE_URL
					);
				}
			}

			$vars = array(
				'created_at' => $maillog->created_at,
				'subject'    => $maillog->subject,
				'qid'        => $maillog->qid,
				'score'      => $maillog->score,
				'has_virus'  => $maillog->has_virus,
				'virus_name' => $maillog->virus_name,
				'mime_from'  => $maillog->mime_from,
				//'rcpt_to'    => $maillog->rcpt_to,
				// this recipient only, not the whole rcpt_to list
				'rcpt_to'    => $recipient,
				'action'     => $maillog->action,
				'detailurl'  => $url,
				'signature'  => $signature,
			);

			$text_part = Helper::getNotifyText($vars);

			// make array of recipients
			//$recipients = array_map('trim', explode(',', $maillog->rcpt_to));

			$send_mail = $mailer->sendTemplatedEmail(
				$from,
				//$recipients,
				[$recipient],
				$subject,
				'mail/notify.html.twig',  // twig template
				$text_part,                // Text part of mail
				$vars,                     // twig vars
			);

			if ($send_mail) {
				$sent++;
			} else {
				$failed[] = $recipient;
			}

		}

		if (!empty($failed)) {
			$this->logger->error(
				"[notifyHtmlMail] mail id {$maillog->id} notification failed for: "
				. implode(', ', $failed)
			);
		}

		if ($sent > 0) {
			/*
			 Deliberately the query builder, not $maillog->update().
			 update() is fill()->save() and save() writes the whole dirty
			 set. CronNotifications sets virus_name (not a column) and
			 filterDisabledRecipients() overwrites rcpt_to (a column) with
			 the enabled-only list -- so a model write persists a
			 truncated recipient list as a side effect of marking the mail
			 notified. Nothing downstream reads $maillog->notified, so
			 there is no reason to round-trip through the model.
			*/
			MailLog::whereKey($maillog->id)->update([
				'notified'    => 1,
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
					->with($this->getMailLogSymbolsRelations())
					->where('notification_pending', 1);
					/*
					->orderBy('id', 'ASC')
					->where('mail_stored', 1)
					->where('notified', 0)
					->whereIn('action', ['discard', 'reject']); // undelivered
					*/

		$notification_days = Config::get('notification_days');

		// Apply date filter only if > 0
		if (is_numeric($notification_days) && (int)$notification_days > 0) {
			$cutoffDate = (new \DateTimeImmutable())
				->sub(new \DateInterval("P{$notification_days}D"))
				->format('Y-m-d');

			$query->where('created_day', '>=', $cutoffDate);
		}

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

		// logs
		return $query->get();
	}

	public function filterDisabledRecipients(Collection $logs, UserService $userService): void {
	 $logs->each(function ($log) use ($userService) {

		// Prefer normalized recipients
		if ($log->relationLoaded('recipients')) {
			$emails = $log->recipients->pluck('recipient_email')->all();
		} else {
			$emails = explode(',', strtolower((string) $log->rcpt_to));
		}

		$emails = array_values(array_unique(array_filter(array_map(
			fn ($e) => strtolower(trim((string) $e)),
			$emails
		))));

		$enabled = [];
		$disabled = [];

		foreach ($emails as $email) {
			if ($userService->notificationsDisabledFor($email)) {
			    $disabled[] = $email;
			} else {
			    $enabled[] = $email;
			}
		}

		// Store disabled list for logging/debugging
		$log->disabled_rcpt_to = implode(', ', $disabled);

		// Overwrite rcpt_to in-memory with enabled recipients only
		$log->rcpt_to = implode(', ', $enabled);
		// ALSO update the recipients relation so accessor matches
		if ($log->relationLoaded('recipients')) {
			$log->setRelation(
				'recipients',
				$log->recipients->filter(function ($r) use ($enabled) {
					return in_array(strtolower(trim($r->recipient_email)), $enabled, true);
				})->values()
			);
		}
	 });
	}

	// returns mail_logs in quarantine before QUARANTINE_DAYS
	public function getQuarantine(
		?OutputInterface $cli_output = null,
		?string $server = null
	): Collection {

		$fields = MailLog::SELECT_FIELDS;

		$days = (int) ($_ENV['QUARANTINE_DAYS'] ?? 366);
		$cutoffDate = new DateTime();
		$cutoffDate->sub(new DateInterval("P{$days}D")); // Subtract days

		$query = MailLog::with($this->getMailLogRecipientsRelation())
					->select($fields)
					->where('mail_stored', 1)
					->where('created_day', '<', $cutoffDate->format('Y-m-d'));

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

		// logs
		return $query->get();
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
			$deleted = 0;
			if (MailTokenService::isEnabled() && $this->migrationStatus->mailLogTokensCompleted()) {
				// quarantine files are gone, so any notification link for these
				// mails is dead: drop the token rows rather than leaving them
				// until the mail_logs row is deleted months later.
				$deleted = (new MailTokenService())->deleteTokensForMails($ids);
			}
			if ($cli_output) {
				$cnt = count($ids);
				$cli_output->writeln("<info>Setting mail_stored to 0 on {$cnt} entries</info>",
					OutputInterface::VERBOSITY_VERBOSE);

				if ($deleted > 0) {
					$cli_output->writeln("<info>Deleted {$deleted} notification token(s)</info>",
						OutputInterface::VERBOSITY_VERBOSE);
				}
			}
		}
	}

	/*
	 Migrations are mandatory -- Kernel::verifyRequiredMigrations() refuses
	 to boot without them -- so both relations always exist and there is no
	 status conditional here.

	 Two tiers, because mail_log_data.headers is a longtext and only the
	 detail page renders it. Every other path constrains the eager load to
	 symbols, so a 50-row list page does not drag 50 header blobs. The
	 mail_log_id in the column list is the foreign key: omit it and Eloquent
	 cannot match the row back to its parent, and silently resolves the
	 relation to null on every row.
	*/
	private function getMailLogRecipientsRelation(): array {
		return ['recipients'];
	}

	public function getMailLogSymbolsRelations(): array {
		return ['recipients', 'mailLogData:mail_log_id,symbols'];
	}

	public function getMailLogRelations(): array {
		return ['recipients', 'mailLogData'];
	}

	private function getFirstMailDate($query) {
		return (clone $query)
			->from(DB::raw(AppConfig::MAIL_LOGS_TABLE . ' FORCE INDEX(created_day_index)'))
			->select('created_at')
			->orderBy('created_day', 'ASC');
	}

	private function getLastMailDate($query) {
		return (clone $query)
			->from(DB::raw(AppConfig::MAIL_LOGS_TABLE . ' FORCE INDEX(created_day_index)'))
			->select('created_at')
			->orderBy('created_day', 'DESC');
	}

	private function getUserRecipientEmails(): array {
			return array_filter(array_map(
				fn ($e) => strtolower(trim($e)),
				array_merge([$this->email], $this->user_aliases ?? [])
			));
	}

}
