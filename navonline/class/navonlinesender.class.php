<?php
/* Copyright (C) 2026
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    htdocs/custom/navonline/class/navonlinesender.class.php
 * \ingroup navonline
 * \brief   Process the llx_navonline_invoice queue: send (manageInvoice) and check results (queryTransactionStatus).
 *
 * Queue status:
 *   0 pending  -> build XML, XSD-validate, manageInvoice  -> 1 sent (transactionId stored)
 *   1 sent     -> queryTransactionStatus                  -> 2 done (NAV DONE) | 3 error (NAV ABORTED) | stays 1
 *   3 error    -> only re-queued manually (retry())
 *   2 done     -> requestAnnulment() (manageAnnulment)    -> 4 annulment requested
 *   4 annul    -> checkAnnulments()                       -> 5 annulled (VERIFICATION_DONE)
 *                                                          | 2 done again (request ABORTED or VERIFICATION_REJECTED)
 *                                                          | stays 4 (VERIFICATION_PENDING: approve it on the NAV web UI)
 *   5 annulled -> retry() re-queues the invoice (CREATE again with corrected data)
 * Network / HTTP errors keep the row pending (retried on next run); data, XSD and NAV request errors set status 3.
 */

require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once __DIR__.'/navonlineclient.class.php';
require_once __DIR__.'/navonlinexmlbuilder.class.php';
require_once __DIR__.'/navonlinexsdvalidator.class.php';

/**
 * Queue processor
 */
class NavOnlineSender
{
	const STATUS_PENDING = 0;
	const STATUS_SENT = 1;
	const STATUS_DONE = 2;
	const STATUS_ERROR = 3;
	const STATUS_ANNUL_REQUESTED = 4;
	const STATUS_ANNULLED = 5;
	const STATUS_CANCELLED = 9;

	const NS_ANNUL = 'http://schemas.nav.gov.hu/OSA/3.0/annul';

	/** @var string[] NAV AnnulmentCodeType values */
	const ANNULMENT_CODES = array('ERRATIC_DATA', 'ERRATIC_INVOICE_NUMBER', 'ERRATIC_INVOICE_ISSUE_DATE', 'ERRATIC_ELECTRONIC_HASH_VALUE');

	/** @var DoliDB */
	private $db;

	/** @var string[] */
	public $errors = array();

	/** @var string Output for the cron module */
	public $output = '';

	/** @var string[] Log of the last run (no secrets) */
	public $log = array();

	/** @var \NavOnlineInvoice\Reporter|null */
	private $reporter = null;

	/** @var NavOnlineClient|null */
	private $client = null;

	/** @var array<int,array{rowid:int,ref:string,message:string}> Alerts collected during this run */
	private $alerts = array();

	/** @var string[] Configuration problems collected during this run (alerted, throttled) */
	private $configAlerts = array();

	/** @var callable|null Mail sender override (tests): function(string[] $to, string $subject, string $body): bool */
	public $mailer = null;

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Entry point for the Dolibarr cron module.
	 *
	 * @return int 0 if OK, <>0 if KO
	 */
	public function doScheduledJob()
	{
		$this->log[] = 'Environment: '.navonlineEnvironmentLabel(navonlineGetEnvironment()).(navonlineIsDryRun() ? ' - DRY-RUN (nothing is sent to NAV)' : '');
		$sent = $this->sendPending();
		$checked = 0;
		if (!navonlineIsDryRun()) {
			// Status queries call NAV: not in dry-run
			$checked = $this->checkSent();
			$annul = $this->checkAnnulments();
			if ($annul < 0) {
				$checked = -1;
			}
		}
		$this->collectStaleAlerts();
		$this->sendAlerts();

		$this->output = implode("\n", $this->log);
		if ($sent < 0 || $checked < 0) {
			$this->output .= "\n".implode("\n", $this->errors);
			return 1;
		}
		return 0;
	}

	/**
	 * Test the connection and the technical user (tokenExchange).
	 *
	 * @return bool
	 */
	public function testConnection()
	{
		$reporter = $this->getReporter();
		if (!$reporter) {
			return false;
		}
		try {
			$reporter->tokenExchange();
			$this->log[] = 'tokenExchange OK';
			return true;
		} catch (Exception $e) {
			$this->errors[] = NavOnlineClient::describeException($e);
			return false;
		}
	}

	/**
	 * Send pending invoices.
	 *
	 * @param int $limit      Max invoices per run
	 * @param int $onlyRowid  Process only this queue row
	 * @return int Number of invoices sent, <0 on configuration error
	 */
	public function sendPending($limit = 20, $onlyRowid = 0)
	{
		// One sender at a time (cron, immediate send on validation, "send now" button): no double submission
		if (!$this->acquireSendLock()) {
			$this->log[] = 'Another NAV send is in progress, skipped (the queue is processed by that run or the next one)';
			return 0;
		}
		try {
			return $this->sendPendingLocked($limit, $onlyRowid);
		} finally {
			$this->releaseSendLock();
		}
	}

	/** @var string|null Name of the database lock held by this sender */
	private $sendLock = null;

	/**
	 * Database-level lock shared by every process sending to NAV (per database, entity and environment).
	 * Not waiting: if another process holds it, that process sends the queue.
	 *
	 * @return bool
	 */
	private function acquireSendLock()
	{
		global $conf;

		if (!in_array($this->db->type, array('mysql', 'mysqli'), true)) {
			return true; // GET_LOCK is MySQL/MariaDB only
		}
		$name = substr('navonline_send_'.$this->db->database_name.'_'.((int) $conf->entity).'_'.navonlineGetEnvironment(), 0, 64);
		$resql = $this->db->query("SELECT GET_LOCK('".$this->db->escape($name)."', 0) AS l");
		$obj = $resql ? $this->db->fetch_object($resql) : null;
		if ($obj && (int) $obj->l === 1) {
			$this->sendLock = $name;
			return true;
		}
		return false;
	}

	/**
	 * @return void
	 */
	private function releaseSendLock()
	{
		if ($this->sendLock !== null) {
			$this->db->query("SELECT RELEASE_LOCK('".$this->db->escape($this->sendLock)."')");
			$this->sendLock = null;
		}
	}

	/**
	 * Immediate send of invoices validated in this request, run after the request (after the validation is committed).
	 * Registered by the trigger; a failure leaves the row pending for the cron, it never affects the invoice itself.
	 *
	 * @param int[] $invoiceIds Invoice ids, in validation order
	 * @return void
	 */
	public static function sendValidatedNow(array $invoiceIds)
	{
		global $conf, $dolibarr_main_db_pass;

		if (!$invoiceIds || !getDolGlobalInt('NAVONLINE_SEND_ON_VALIDATE', 1)) {
			return;
		}
		ignore_user_abort(true);

		// The request's own connection may already be closed (pages end with $db->close()): use a new one.
		// A new connection also sees only committed data, so a rolled back validation is never sent.
		// master.inc.php unsets $conf->db->pass; the decoded password stays in $dolibarr_main_db_pass (used by core pages too).
		$db = getDoliDBInstance($conf->db->type, $conf->db->host, $conf->db->user, (string) $dolibarr_main_db_pass, $conf->db->name, (int) $conf->db->port);
		if (!$db || !$db->connected || !$db->database_selected) {
			dol_syslog(__METHOD__.' no database connection, left to the cron', LOG_WARNING);
			return;
		}
		// Dolibarr code that uses the global handler must not hit the closed request connection
		$previousDb = $GLOBALS['db'] ?? null;
		$GLOBALS['db'] = $db;
		// Do not keep the user waiting for long in a NAV outage: the cron retries with the normal timeout
		$conf->global->NAVONLINE_CURL_TIMEOUT = min(getDolGlobalInt('NAVONLINE_CURL_TIMEOUT', 60), 20);

		$sender = new self($db);
		try {
			foreach (array_unique(array_map('intval', $invoiceIds)) as $fk) {
				$invoice = new Facture($db);
				if ($invoice->fetch($fk) <= 0 || (int) $invoice->status === Facture::STATUS_DRAFT) {
					continue; // not committed (or reverted): nothing to send
				}
				$row = $sender->fetchRowByInvoice($fk);
				if ($row && (int) $row->status === self::STATUS_PENDING) {
					$sender->sendPending(1, (int) $row->rowid);
				}
			}
			$sender->sendAlerts();
		} catch (Throwable $e) {
			// Never surfaces to the user; the row stays pending and the cron retries
			$sender->errors[] = 'Immediate send failed: '.get_class($e).': '.$e->getMessage();
		} finally {
			foreach ($sender->log as $line) {
				dol_syslog(__METHOD__.' '.$line, LOG_INFO);
			}
			foreach ($sender->errors as $line) {
				dol_syslog(__METHOD__.' '.$line, LOG_WARNING);
			}
			$db->close();
			$GLOBALS['db'] = $previousDb;
		}
	}

	/**
	 * Send pending invoices (caller holds the send lock).
	 *
	 * @param int $limit      Max invoices per run
	 * @param int $onlyRowid  Process only this queue row
	 * @return int Number of invoices sent, <0 on configuration error
	 */
	private function sendPendingLocked($limit, $onlyRowid)
	{
		$rows = $this->fetchRows(self::STATUS_PENDING, $limit, $onlyRowid);
		if ($rows === null) {
			return -1;
		}
		if (!$rows) {
			return 0;
		}

		$dryRun = navonlineIsDryRun();
		$reporter = null;
		if (!$dryRun) {
			$reporter = $this->getReporter();
			if (!$reporter) {
				return -1;
			}
		}

		$sent = 0;
		foreach ($rows as $row) {
			if ($dryRun && $row->nav_status === 'DRYRUN') {
				continue; // already checked in this dry-run, sent normally once dry-run is switched off
			}
			$invoice = new Facture($this->db);
			if ($invoice->fetch((int) $row->fk_facture) <= 0) {
				$this->setError($row, 'Invoice not found');
				continue;
			}
			if ((int) $invoice->status === Facture::STATUS_DRAFT) {
				$this->setError($row, 'Invoice is a draft');
				continue;
			}
			// Production: only invoices issued on or after the go-live date are reported by this module
			if (!navonlineIsReportableDate($invoice->date)) {
				$this->setCancelled($row, 'Issued before the go-live date '.navonlineGetGoLiveDate().' of production reporting: not sent by this module');
				continue;
			}
			$invoice->fetch_thirdparty();

			// A credit note referencing an original that is still being processed must wait (not in dry-run: nothing is sent)
			if (!$dryRun && (int) $invoice->type === Facture::TYPE_CREDIT_NOTE && $this->isSourceInProgress($invoice)) {
				$this->log[] = $invoice->ref.': waiting for the original invoice to be processed by NAV';
				continue;
			}
			// A final invoice deducting a deposit must wait until the deposit invoice is processed by NAV
			$waitingFor = $dryRun ? array() : $this->getDepositsInProgress($invoice);
			if ($waitingFor) {
				$this->log[] = $invoice->ref.': waiting for deposit invoice(s) '.implode(', ', $waitingFor).' to be processed by NAV';
				continue;
			}

			$builder = new NavOnlineXmlBuilder($this->db);
			$xml = $builder->build($invoice);
			if (!$xml) {
				$this->setError($row, 'Data error: '.implode('; ', $builder->errors));
				continue;
			}
			if (!$dryRun && $builder->getSupplierTaxpayerId() !== $this->client->getUserTaxpayerId()) {
				$this->setError($row, 'Supplier tax number does not match the tax number of the NAV technical user');
				continue;
			}

			$dom = dom_import_simplexml($xml)->ownerDocument;
			$dom->formatOutput = true;
			$xmlString = $dom->saveXML();

			$validator = new NavOnlineXsdValidator();
			if (!$validator->validateInvoiceData($xmlString)) {
				$this->setError($row, 'XSD: '.implode('; ', $validator->errors));
				continue;
			}

			if ($dryRun) {
				$this->recordDryRun($row, $invoice, $builder, $xml, $xmlString);
				$sent++;
				continue;
			}

			$xmlFile = $this->saveXml($invoice->ref, $xmlString);

			try {
				$transactionId = $reporter->manageInvoice($xml, $builder->operation);
			} catch (\NavOnlineInvoice\CurlError $e) {
				$this->keepPending($row, NavOnlineClient::describeException($e));
				continue;
			} catch (\NavOnlineInvoice\HttpResponseError $e) {
				$this->keepPending($row, NavOnlineClient::describeException($e));
				continue;
			} catch (Exception $e) {
				$this->setError($row, NavOnlineClient::describeException($e));
				continue;
			}

			$requestData = $reporter->getLastRequestData();
			$requestId = isset($requestData['requestId']) ? (string) $requestData['requestId'] : '';

			$sql = 'UPDATE '.$this->db->prefix().'navonline_invoice SET';
			$sql .= ' status = '.self::STATUS_SENT;
			$sql .= ", nav_status = 'RECEIVED'";
			$sql .= ", operation = '".$this->db->escape($builder->operation)."'";
			$sql .= ", transaction_id = '".$this->db->escape($transactionId)."'";
			$sql .= ", request_id = '".$this->db->escape($requestId)."'";
			$sql .= ", invoice_ref = '".$this->db->escape($invoice->ref)."'";
			$localWarnings = implode("\n", $builder->warnings);
			$sql .= ', error_message = NULL';
			$sql .= ', nav_messages = '.($localWarnings !== '' ? "'".$this->db->escape($localWarnings)."'" : 'NULL');
			$sql .= ", xml_file = ".($xmlFile !== '' ? "'".$this->db->escape($xmlFile)."'" : 'NULL');
			$sql .= ", date_sent = '".$this->db->idate(dol_now())."'";
			$sql .= ' WHERE rowid = '.((int) $row->rowid);
			$this->db->query($sql);

			$this->log[] = $invoice->ref.': sent to NAV '.navonlineEnvironmentLabel($this->client->environment).' ('.$builder->operation.'), transactionId '.$transactionId;
			foreach ($builder->warnings as $w) {
				$this->log[] = $invoice->ref.': '.$w;
			}
			dol_syslog(__METHOD__.' '.$invoice->ref.' sent to NAV '.$this->client->environment.', transactionId='.$transactionId.' requestId='.$requestId, LOG_INFO);
			$sent++;
		}

		return $sent;
	}

	/**
	 * Query the result of sent invoices.
	 *
	 * @param int $limit     Max transactions per run
	 * @param int $onlyRowid Process only this queue row
	 * @return int Number of rows finalized (done or error), <0 on configuration error
	 */
	public function checkSent($limit = 50, $onlyRowid = 0)
	{
		$rows = $this->fetchRows(self::STATUS_SENT, $limit, $onlyRowid);
		if ($rows === null) {
			return -1;
		}
		if (!$rows) {
			return 0;
		}

		$reporter = $this->getReporter();
		if (!$reporter) {
			return -1;
		}

		$finalized = 0;
		foreach ($rows as $row) {
			try {
				$response = $reporter->queryTransactionStatus($row->transaction_id);
			} catch (Exception $e) {
				$this->log[] = $row->invoice_ref.': status query failed: '.NavOnlineClient::describeException($e);
				continue;
			}

			$result = isset($response->processingResults->processingResult) ? $response->processingResults->processingResult[0] : null;
			if (!$result) {
				$this->log[] = $row->invoice_ref.': no processing result yet';
				continue;
			}

			$navStatus = (string) $result->invoiceStatus;
			$messages = self::mergeLocalMessages(isset($row->nav_messages) ? (string) $row->nav_messages : '', self::collectMessages($result));
			$status = self::STATUS_SENT;
			if ($navStatus === 'DONE') {
				$status = self::STATUS_DONE;
				$finalized++;
			} elseif ($navStatus === 'ABORTED') {
				$status = self::STATUS_ERROR;
				$finalized++;
				$this->addAlert($row, 'NAV ABORTED'.($messages !== '' ? ': '.str_replace("\n", ' | ', $messages) : ''));
			}

			$sql = 'UPDATE '.$this->db->prefix().'navonline_invoice SET';
			$sql .= ' status = '.((int) $status);
			$sql .= ", nav_status = '".$this->db->escape($navStatus)."'";
			$sql .= ', nav_messages = '.($messages !== '' ? "'".$this->db->escape($messages)."'" : 'NULL');
			$sql .= ', error_message = '.($status === self::STATUS_ERROR ? "'".$this->db->escape('NAV ABORTED')."'" : 'NULL');
			$sql .= ", date_checked = '".$this->db->idate(dol_now())."'";
			$sql .= ' WHERE rowid = '.((int) $row->rowid);
			$this->db->query($sql);

			$this->log[] = $row->invoice_ref.': '.$navStatus.($messages !== '' ? ' - '.str_replace("\n", ' | ', $messages) : '');
		}

		return $finalized;
	}

	/**
	 * Dry-run of one queue row: the XML is already built and XSD-valid; check it with the pzs package as well,
	 * store it (DRYRUN_ prefix) and mark the row. The row stays pending, NAV is not called.
	 *
	 * @param object             $row       Queue row
	 * @param Facture            $invoice   Invoice
	 * @param NavOnlineXmlBuilder $builder  Builder used
	 * @param SimpleXMLElement   $xml       Built XML
	 * @param string             $xmlString Formatted XML
	 * @return void
	 */
	private function recordDryRun($row, $invoice, $builder, $xml, $xmlString)
	{
		try {
			$ops = new \NavOnlineInvoice\InvoiceOperations();
			$ops->useDataSchemaValidation();
			$ops->add($xml, $builder->operation);
		} catch (Exception $e) {
			$this->setError($row, 'pzs InvoiceOperations: '.trim($e->getMessage()));
			return;
		}

		$xmlFile = $this->saveXml($invoice->ref, $xmlString, 'DRYRUN_');
		$messages = $builder->warnings;
		$messages[] = 'INFO LOCAL_DRY_RUN: XML built and valid ('.$builder->operation.'), NOT sent to NAV '.navonlineEnvironmentLabel(navonlineGetEnvironment()).' (dry-run)';

		$sql = 'UPDATE '.$this->db->prefix().'navonline_invoice SET';
		$sql .= " nav_status = 'DRYRUN'";
		$sql .= ", operation = '".$this->db->escape($builder->operation)."'";
		$sql .= ", invoice_ref = '".$this->db->escape($invoice->ref)."'";
		$sql .= ', error_message = NULL';
		$sql .= ", nav_messages = '".$this->db->escape(implode("\n", $messages))."'";
		$sql .= ', xml_file = '.($xmlFile !== '' ? "'".$this->db->escape($xmlFile)."'" : 'NULL');
		$sql .= ", date_checked = '".$this->db->idate(dol_now())."'";
		$sql .= ' WHERE rowid = '.((int) $row->rowid);
		$this->db->query($sql);

		$this->log[] = $invoice->ref.': DRY-RUN OK ('.$builder->operation.'), XML '.($xmlFile !== '' ? basename($xmlFile) : 'not saved').', not sent';
		foreach ($builder->warnings as $w) {
			$this->log[] = $invoice->ref.': '.$w;
		}
	}

	/**
	 * Remember an alert for the e-mail summary of this run.
	 *
	 * @param object $row     Queue row (rowid, invoice_ref)
	 * @param string $message Message (no secrets)
	 * @return void
	 */
	private function addAlert($row, $message)
	{
		$this->alerts[(int) $row->rowid] = array('rowid' => (int) $row->rowid, 'ref' => (string) $row->invoice_ref, 'message' => $message);
	}

	/**
	 * Alert rows that stay unfinished (pending, sent, annulment requested) for too long.
	 *
	 * @return void
	 */
	public function collectStaleAlerts()
	{
		global $conf;

		$limit = dol_now() - navonlineGetAlertStaleHours() * 3600;
		$labels = array(self::STATUS_PENDING => 'waiting to be sent', self::STATUS_SENT => 'sent, no final NAV result', self::STATUS_ANNUL_REQUESTED => 'technical annulment not approved/finished');

		$sql = 'SELECT rowid, invoice_ref, status, nav_status, datec, date_sent, annul_date_sent FROM '.$this->db->prefix().'navonline_invoice';
		$sql .= ' WHERE entity = '.((int) $conf->entity).navonlineEnvSql();
		$sql .= ' AND status IN ('.self::STATUS_PENDING.', '.self::STATUS_SENT.', '.self::STATUS_ANNUL_REQUESTED.')';
		$resql = $this->db->query($sql);
		if (!$resql) {
			return;
		}
		while ($obj = $this->db->fetch_object($resql)) {
			$since = (int) $obj->status === self::STATUS_PENDING ? $obj->datec : ((int) $obj->status === self::STATUS_SENT ? $obj->date_sent : $obj->annul_date_sent);
			$since = $this->db->jdate($since);
			if (navonlineIsDryRun() && (int) $obj->status === self::STATUS_PENDING) {
				continue; // waiting is expected in dry-run
			}
			if ($since && $since < $limit && !isset($this->alerts[(int) $obj->rowid])) {
				$this->addAlert($obj, 'Stale: '.$labels[(int) $obj->status].' since '.dol_print_date($since, 'dayhour').' ('.navonlineGetAlertStaleHours().' h limit)');
			}
		}
	}

	/**
	 * Send one summary e-mail with the alerts of this run (per row at most every 24 h;
	 * configuration problems at most every 6 h). Nothing is sent without recipients.
	 *
	 * @return int Number of alerts sent, 0 if none, <0 on mail error
	 */
	public function sendAlerts()
	{
		global $conf;

		$to = navonlineGetAlertRecipients();
		if (!$to || (!$this->alerts && !$this->configAlerts)) {
			return 0;
		}

		// Throttle per row
		$items = array();
		if ($this->alerts) {
			$sql = 'SELECT rowid, date_alerted FROM '.$this->db->prefix().'navonline_invoice WHERE rowid IN ('.implode(',', array_map('intval', array_keys($this->alerts))).')';
			$resql = $this->db->query($sql);
			$last = array();
			while ($resql && ($obj = $this->db->fetch_object($resql))) {
				$last[(int) $obj->rowid] = $this->db->jdate($obj->date_alerted);
			}
			foreach ($this->alerts as $rowid => $a) {
				if (empty($last[$rowid]) || $last[$rowid] < dol_now() - 24 * 3600) {
					$items[$rowid] = $a;
				}
			}
		}
		// Throttle configuration alerts
		$config = array();
		if ($this->configAlerts && getDolGlobalInt('NAVONLINE_ALERT_CONFIG_LAST') < dol_now() - 6 * 3600) {
			$config = array_values(array_unique($this->configAlerts));
		}
		if (!$items && !$config) {
			return 0;
		}

		$env = navonlineEnvironmentLabel(navonlineGetEnvironment());
		$subject = '[NAV Online Számla]['.$env.'] '.(count($items) + count($config)).' figyelmeztetés / alert(s)';
		$body = 'NAV Online Számla - '.$env.(navonlineIsDryRun() ? ' - DRY-RUN' : '')."\n";
		$body .= dol_print_date(dol_now(), 'dayhour')."\n\n";
		foreach ($config as $m) {
			$body .= '- Configuration: '.$m."\n";
		}
		foreach ($items as $a) {
			$body .= '- '.$a['ref'].': '.$a['message']."\n";
		}
		$body .= "\n".dol_buildpath('/navonline/admin/setup.php', 2)."\n";

		$ok = $this->deliverMail($to, $subject, $body);
		if (!$ok) {
			dol_syslog(__METHOD__.' alert e-mail could not be sent', LOG_ERR);
			$this->log[] = 'Alert e-mail could not be sent';
			return -1;
		}

		if ($items) {
			$this->db->query('UPDATE '.$this->db->prefix()."navonline_invoice SET date_alerted = '".$this->db->idate(dol_now())."' WHERE rowid IN (".implode(',', array_map('intval', array_keys($items))).')');
		}
		if ($config) {
			require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
			dolibarr_set_const($this->db, 'NAVONLINE_ALERT_CONFIG_LAST', (string) dol_now(), 'chaine', 0, '', $conf->entity);
		}
		$this->log[] = 'Alert e-mail sent to '.count($to).' recipient(s): '.(count($items) + count($config)).' alert(s)';
		return count($items) + count($config);
	}

	/**
	 * Send a test alert to the configured recipients (setup page / CLI).
	 *
	 * @return bool
	 */
	public function sendTestAlert()
	{
		$to = navonlineGetAlertRecipients();
		if (!$to) {
			$this->errors[] = 'No valid alert e-mail address configured';
			return false;
		}
		$env = navonlineEnvironmentLabel(navonlineGetEnvironment());
		$ok = $this->deliverMail($to, '[NAV Online Számla]['.$env.'] Teszt riasztás / test alert', "Ez egy teszt riasztás a NAV Online Számla modulból.\nThis is a test alert from the NAV Online Invoice module.\n\nEnvironment: ".$env.(navonlineIsDryRun() ? ' (dry-run)' : '')."\n");
		if (!$ok) {
			$this->errors[] = 'The e-mail could not be sent (check Setup > E-mails)';
		}
		return $ok;
	}

	/**
	 * @param string[] $to      Recipients
	 * @param string   $subject Subject
	 * @param string   $body    Plain text body
	 * @return bool
	 */
	private function deliverMail($to, $subject, $body)
	{
		if (is_callable($this->mailer)) {
			return (bool) call_user_func($this->mailer, $to, $subject, $body);
		}
		require_once DOL_DOCUMENT_ROOT.'/core/class/CMailFile.class.php';
		$from = getDolGlobalString('MAIN_MAIL_EMAIL_FROM', 'dolibarr@localhost');
		$mail = new CMailFile($subject, implode(',', $to), $from, $body, array(), array(), array(), '', '', 0, 0, '', '', '', '', 'standard');
		if (!empty($mail->error)) {
			return false;
		}
		return (bool) $mail->sendfile();
	}

	/**
	 * Build the InvoiceAnnulment XML (technical annulment of an accepted data report).
	 *
	 * @param string   $invoiceRef Invoice number as reported to NAV
	 * @param string   $code       One of ANNULMENT_CODES
	 * @param string   $reason     Free text (max 1024)
	 * @param int|null $now        Timestamp (UTC) for tests
	 * @return SimpleXMLElement
	 */
	public static function buildAnnulmentXml($invoiceRef, $code, $reason, $now = null)
	{
		$now = $now === null ? time() : (int) $now;
		$xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><InvoiceAnnulment xmlns="'.self::NS_ANNUL.'"></InvoiceAnnulment>');
		foreach (array(
			'annulmentReference' => $invoiceRef,
			'annulmentTimestamp' => gmdate('Y-m-d\TH:i:s', $now).'.000Z',
			'annulmentCode' => $code,
			'annulmentReason' => dol_trunc(trim(preg_replace('/\s+/u', ' ', (string) $reason)), 1024, 'right', 'UTF-8', 1),
		) as $name => $value) {
			$child = $xml->addChild($name);
			$child[0] = $value;
		}
		return $xml;
	}

	/**
	 * Send a technical annulment for an invoice accepted by NAV (status done).
	 *
	 * @param int    $rowid  Queue row id
	 * @param string $code   Annulment code (ANNULMENT_CODES)
	 * @param string $reason Reason
	 * @return bool
	 */
	public function requestAnnulment($rowid, $code, $reason)
	{
		$row = $this->fetchRowById($rowid);
		if (!$row) {
			$this->errors[] = 'Queue row not found';
			return false;
		}
		if (navonlineIsDryRun()) {
			$this->errors[] = 'Dry-run is active: nothing is sent to NAV (switch dry-run off to send a technical annulment)';
			return false;
		}
		if ((int) $row->status !== self::STATUS_DONE) {
			$this->errors[] = 'Only invoices accepted by NAV (DONE) can be technically annulled';
			return false;
		}
		if (!in_array($code, self::ANNULMENT_CODES, true)) {
			$this->errors[] = 'Invalid annulment code';
			return false;
		}
		if (trim((string) $reason) === '') {
			$this->errors[] = 'The annulment reason is mandatory';
			return false;
		}

		$xml = self::buildAnnulmentXml($row->invoice_ref, $code, $reason);
		$validator = new NavOnlineXsdValidator();
		if (!$validator->validateAnnulment($xml->asXML())) {
			$this->errors[] = 'XSD: '.implode('; ', $validator->errors);
			return false;
		}

		$reporter = $this->getReporter();
		if (!$reporter) {
			return false;
		}

		try {
			$transactionId = $reporter->manageAnnulment($xml);
		} catch (Exception $e) {
			$this->errors[] = NavOnlineClient::describeException($e);
			dol_syslog(__METHOD__.' '.$row->invoice_ref.': '.NavOnlineClient::describeException($e), LOG_WARNING);
			return false;
		}

		$sql = 'UPDATE '.$this->db->prefix().'navonline_invoice SET';
		$sql .= ' status = '.self::STATUS_ANNUL_REQUESTED;
		$sql .= ", annul_code = '".$this->db->escape($code)."'";
		$sql .= ", annul_reason = '".$this->db->escape((string) $reason)."'";
		$sql .= ", annul_transaction_id = '".$this->db->escape($transactionId)."'";
		$sql .= ", annul_status = 'RECEIVED', annul_messages = NULL, annul_date_checked = NULL";
		$sql .= ", annul_date_sent = '".$this->db->idate(dol_now())."'";
		$sql .= ' WHERE rowid = '.((int) $row->rowid);
		$this->db->query($sql);

		$this->log[] = $row->invoice_ref.': technical annulment sent, transactionId '.$transactionId.' - approve it on the NAV online ('.navonlineEnvironmentLabel($this->client->environment).') web UI';
		dol_syslog(__METHOD__.' '.$row->invoice_ref.' annulment sent to NAV '.$this->client->environment.', transactionId='.$transactionId, LOG_INFO);
		return true;
	}

	/**
	 * Query the state of requested technical annulments.
	 *
	 * @param int $limit     Max rows
	 * @param int $onlyRowid Only this row
	 * @return int Number of finalized rows, <0 on configuration error
	 */
	public function checkAnnulments($limit = 50, $onlyRowid = 0)
	{
		$rows = $this->fetchRows(self::STATUS_ANNUL_REQUESTED, $limit, $onlyRowid);
		if ($rows === null) {
			return -1;
		}
		if (!$rows) {
			return 0;
		}
		$reporter = $this->getReporter();
		if (!$reporter) {
			return -1;
		}

		$finalized = 0;
		foreach ($rows as $row) {
			try {
				$response = $reporter->queryTransactionStatus($row->annul_transaction_id);
			} catch (Exception $e) {
				$this->log[] = $row->invoice_ref.': annulment status query failed: '.NavOnlineClient::describeException($e);
				continue;
			}

			$results = isset($response->processingResults) ? $response->processingResults : null;
			$result = ($results && isset($results->processingResult)) ? $results->processingResult[0] : null;
			if (!$result) {
				$this->log[] = $row->invoice_ref.': no annulment processing result yet';
				continue;
			}

			$requestStatus = (string) $result->invoiceStatus;
			$verification = isset($results->annulmentData->annulmentVerificationStatus) ? (string) $results->annulmentData->annulmentVerificationStatus : '';
			$messages = self::collectMessages($result);

			$status = self::STATUS_ANNUL_REQUESTED;
			$annulStatus = $verification !== '' ? $verification : $requestStatus;
			if ($requestStatus === 'ABORTED') {
				$status = self::STATUS_DONE; // the original report stays valid
				$annulStatus = 'ABORTED';
				$finalized++;
				$this->addAlert($row, 'Technical annulment ABORTED by NAV'.($messages !== '' ? ': '.str_replace("\n", ' | ', $messages) : ''));
			} elseif ($verification === 'VERIFICATION_DONE') {
				$status = self::STATUS_ANNULLED;
				$finalized++;
			} elseif ($verification === 'VERIFICATION_REJECTED') {
				$status = self::STATUS_DONE;
				$finalized++;
				$this->addAlert($row, 'Technical annulment REJECTED on the NAV web interface');
			}

			$sql = 'UPDATE '.$this->db->prefix().'navonline_invoice SET';
			$sql .= ' status = '.((int) $status);
			$sql .= ", annul_status = '".$this->db->escape($annulStatus)."'";
			$sql .= ', annul_messages = '.($messages !== '' ? "'".$this->db->escape($messages)."'" : 'NULL');
			$sql .= ", annul_date_checked = '".$this->db->idate(dol_now())."'";
			$sql .= ' WHERE rowid = '.((int) $row->rowid);
			$this->db->query($sql);

			$this->log[] = $row->invoice_ref.': annulment '.$requestStatus.($verification !== '' ? ' / '.$verification : '').($messages !== '' ? ' - '.str_replace("\n", ' | ', $messages) : '');
		}

		return $finalized;
	}

	/**
	 * @param int $rowid Queue row id
	 * @return object|null
	 */
	private function fetchRowById($rowid)
	{
		global $conf;

		$sql = 'SELECT * FROM '.$this->db->prefix().'navonline_invoice WHERE rowid = '.((int) $rowid).' AND entity = '.((int) $conf->entity).navonlineEnvSql();
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->errors[] = $this->db->lasterror();
			return null;
		}
		$obj = $this->db->fetch_object($resql);
		return $obj ? $obj : null;
	}

	/**
	 * Put an error row back into the queue.
	 *
	 * @param int $rowid Queue row id
	 * @return bool
	 */
	public function retry($rowid)
	{
		// From error, or after a technical annulment approved by NAV (the invoice is reported again with CREATE)
		$sql = 'UPDATE '.$this->db->prefix().'navonline_invoice SET status = '.self::STATUS_PENDING.', error_message = NULL';
		$sql .= ' WHERE rowid = '.((int) $rowid).' AND status IN ('.self::STATUS_ERROR.', '.self::STATUS_ANNULLED.')';
		$resql = $this->db->query($sql);
		return $resql && $this->db->affected_rows($resql) > 0;
	}

	/**
	 * Queue row of an invoice.
	 *
	 * @param int $fkFacture Invoice id
	 * @return object|null
	 */
	public function fetchRowByInvoice($fkFacture)
	{
		global $conf;

		$sql = 'SELECT * FROM '.$this->db->prefix().'navonline_invoice';
		$sql .= ' WHERE fk_facture = '.((int) $fkFacture).' AND entity = '.((int) $conf->entity).navonlineEnvSql();
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->errors[] = $this->db->lasterror();
			return null;
		}
		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);
		return $obj ? $obj : null;
	}

	/**
	 * Put a validated invoice into the queue (e.g. invoices validated before the module was enabled).
	 *
	 * @param Facture $invoice Invoice
	 * @param User    $user    User
	 * @return int Queue rowid, <0 on error
	 */
	public function enqueue($invoice, $user)
	{
		global $conf;

		if ((int) $invoice->status === Facture::STATUS_DRAFT) {
			$this->errors[] = 'Draft invoices cannot be queued';
			return -1;
		}
		// OSS invoices are not reported to NAV (navonlineOssLineCounts()): they stay out of the queue
		require_once __DIR__.'/../lib/navonline.lib.php';
		list($n, $noss) = navonlineOssLineCounts($this->db, 'facture', (int) $invoice->id);
		if ($noss > 0) {
			global $langs;
			$langs->load('navonline@navonline');
			$this->errors[] = $langs->trans($noss === $n ? 'NavOnlineErrOssEnqueue' : 'NavOnlineErrOssMixed');
			return -1;
		}
		$existing = $this->fetchRowByInvoice((int) $invoice->id);
		if ($existing) {
			if ((int) $existing->status !== self::STATUS_CANCELLED) {
				return (int) $existing->rowid;
			}
			$sql = 'UPDATE '.$this->db->prefix().'navonline_invoice SET status = '.self::STATUS_PENDING.', error_message = NULL WHERE rowid = '.((int) $existing->rowid);
			return $this->db->query($sql) ? (int) $existing->rowid : -1;
		}

		$sql = 'INSERT INTO '.$this->db->prefix().'navonline_invoice (entity, environment, fk_facture, invoice_ref, invoice_type, status, datec, fk_user_creat) VALUES (';
		$sql .= ((int) $conf->entity).", '".navonlineGetEnvironment()."', ".((int) $invoice->id).", '".$this->db->escape($invoice->ref)."', ".((int) $invoice->type).', '.self::STATUS_PENDING;
		$sql .= ", '".$this->db->idate(dol_now())."', ".((int) $user->id).')';
		if (!$this->db->query($sql)) {
			$this->errors[] = $this->db->lasterror();
			return -1;
		}
		return (int) $this->db->last_insert_id($this->db->prefix().'navonline_invoice');
	}

	/**
	 * Keep the module's own pre-send warnings ('WARN LOCAL_...') in front of the NAV messages.
	 *
	 * @param string $previous Previously stored messages
	 * @param string $nav      Messages returned by NAV
	 * @return string
	 */
	public static function mergeLocalMessages($previous, $nav)
	{
		$local = array_filter(explode("\n", (string) $previous), function ($l) {
			return strpos($l, 'WARN LOCAL_') === 0;
		});
		$lines = array_merge($local, $nav !== '' ? explode("\n", $nav) : array());
		return implode("\n", array_unique($lines));
	}

	/**
	 * Validation messages of a processingResult, one per line.
	 *
	 * @param SimpleXMLElement $result processingResult
	 * @return string
	 */
	public static function collectMessages($result)
	{
		$lines = array();
		foreach (array('technicalValidationMessages', 'businessValidationMessages') as $kind) {
			foreach ($result->$kind as $m) {
				$lines[] = trim((string) $m->validationResultCode.' '.(string) $m->validationErrorCode.': '.(string) $m->message
					.(isset($m->pointer->tag) ? ' ['.(string) $m->pointer->tag.(isset($m->pointer->line) ? ' line '.(string) $m->pointer->line : '').']' : ''));
			}
		}
		return implode("\n", $lines);
	}

	/**
	 * @return \NavOnlineInvoice\Reporter|null
	 */
	private function getReporter()
	{
		if ($this->reporter) {
			return $this->reporter;
		}
		$this->client = new NavOnlineClient();
		$this->reporter = $this->client->getReporter();
		if (!$this->reporter) {
			$this->errors = array_merge($this->errors, $this->client->errors);
			foreach ($this->client->errors as $err) {
				dol_syslog(__CLASS__.' configuration: '.$err, LOG_ERR);
				$this->configAlerts[] = $err;
			}
		}
		return $this->reporter;
	}

	/**
	 * @param int $status Queue status
	 * @param int $limit     Limit
	 * @param int $onlyRowid Only this row (0 = all)
	 * @return object[]|null
	 */
	private function fetchRows($status, $limit, $onlyRowid = 0)
	{
		global $conf;

		$sql = 'SELECT rowid, fk_facture, invoice_ref, transaction_id, annul_transaction_id, nav_status, nav_messages FROM '.$this->db->prefix().'navonline_invoice';
		$sql .= ' WHERE status = '.((int) $status).' AND entity = '.((int) $conf->entity).navonlineEnvSql();
		if ($onlyRowid > 0) {
			$sql .= ' AND rowid = '.((int) $onlyRowid);
		}
		$sql .= ' ORDER BY rowid ASC';
		$sql .= $this->db->plimit((int) $limit);

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->errors[] = $this->db->lasterror();
			return null;
		}
		$rows = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$rows[] = $obj;
		}
		$this->db->free($resql);
		return $rows;
	}

	/**
	 * @param Facture $invoice Credit note
	 * @return bool
	 */
	private function isSourceInProgress($invoice)
	{
		if (empty($invoice->fk_facture_source)) {
			return false;
		}
		$sql = 'SELECT status FROM '.$this->db->prefix().'navonline_invoice WHERE fk_facture = '.((int) $invoice->fk_facture_source).navonlineEnvSql();
		$resql = $this->db->query($sql);
		if ($resql && ($obj = $this->db->fetch_object($resql))) {
			return in_array((int) $obj->status, array(self::STATUS_PENDING, self::STATUS_SENT, self::STATUS_ANNUL_REQUESTED), true);
		}
		return false;
	}

	/**
	 * Deposit invoices deducted by this invoice (lines with fk_remise_except from a deposit)
	 * that are still queued, being sent or being annulled.
	 *
	 * @param Facture $invoice Invoice
	 * @return string[] Refs of the deposit invoices to wait for
	 */
	public function getDepositsInProgress($invoice)
	{
		$ids = array();
		foreach ((array) $invoice->lines as $line) {
			if (!empty($line->fk_remise_except)) {
				$ids[] = (int) $line->fk_remise_except;
			}
		}
		if (!$ids) {
			return array();
		}

		$sql = 'SELECT f.ref, q.status FROM '.$this->db->prefix().'societe_remise_except as r';
		$sql .= ' INNER JOIN '.$this->db->prefix().'facture as f ON f.rowid = r.fk_facture_source AND f.type = '.Facture::TYPE_DEPOSIT;
		$sql .= ' INNER JOIN '.$this->db->prefix().'navonline_invoice as q ON q.fk_facture = f.rowid'.navonlineEnvSql('q.');
		$sql .= ' WHERE r.rowid IN ('.implode(',', $ids).')';
		$sql .= ' AND q.status IN ('.self::STATUS_PENDING.', '.self::STATUS_SENT.', '.self::STATUS_ANNUL_REQUESTED.')';

		$refs = array();
		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$refs[] = $obj->ref;
			}
		}
		return array_values(array_unique($refs));
	}

	/**
	 * @param string $ref       Invoice ref
	 * @param string $xmlString XML
	 * @return string Saved path relative to DOL_DATA_ROOT, '' on failure
	 */
	private function saveXml($ref, $xmlString, $prefix = '')
	{
		$dir = DOL_DATA_ROOT.'/navonline/xml';
		if (!is_dir($dir)) {
			dol_mkdir($dir);
		}
		$name = $prefix.dol_sanitizeFileName($ref).'_'.dol_print_date(dol_now(), '%Y%m%d%H%M%S').'.xml';
		if (file_put_contents($dir.'/'.$name, $xmlString) === false) {
			return '';
		}
		return 'navonline/xml/'.$name;
	}

	/**
	 * @param object $row     Queue row
	 * @param string $message Message
	 * @return void
	 */
	private function setError($row, $message)
	{
		$sql = 'UPDATE '.$this->db->prefix().'navonline_invoice SET status = '.self::STATUS_ERROR;
		$sql .= ", error_message = '".$this->db->escape(dol_trunc($message, 4000))."'";
		$sql .= ' WHERE rowid = '.((int) $row->rowid);
		$this->db->query($sql);
		$this->log[] = $row->invoice_ref.': ERROR '.$message;
		$this->addAlert($row, 'ERROR: '.$message);
		dol_syslog(__CLASS__.' '.$row->invoice_ref.': '.$message, LOG_WARNING);
	}

	/**
	 * @param object $row     Queue row
	 * @param string $message Reason
	 * @return void
	 */
	private function setCancelled($row, $message)
	{
		$sql = 'UPDATE '.$this->db->prefix().'navonline_invoice SET status = '.self::STATUS_CANCELLED;
		$sql .= ", error_message = '".$this->db->escape($message)."'";
		$sql .= ' WHERE rowid = '.((int) $row->rowid);
		$this->db->query($sql);
		$this->log[] = $row->invoice_ref.': skipped - '.$message;
		dol_syslog(__CLASS__.' '.$row->invoice_ref.' skipped: '.$message, LOG_INFO);
	}

	/**
	 * @param object $row     Queue row
	 * @param string $message Message
	 * @return void
	 */
	private function keepPending($row, $message)
	{
		$sql = 'UPDATE '.$this->db->prefix().'navonline_invoice SET error_message = '."'".$this->db->escape(dol_trunc($message, 4000))."'";
		$sql .= ' WHERE rowid = '.((int) $row->rowid);
		$this->db->query($sql);
		$this->log[] = $row->invoice_ref.': will retry ('.$message.')';
		dol_syslog(__CLASS__.' '.$row->invoice_ref.' kept pending: '.$message, LOG_WARNING);
	}
}
