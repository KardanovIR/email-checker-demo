<?php

use Aura\SqlQuery\QueryFactory;

class MailChecker
{
	private $emails; // current emails in process
	private $domains; // cache of dns checks
	private $query_factory; // instance of QueryFactory


	private $dbh; // PDO
	private $offset;
	private $length;

	const EMAILS_TABLE_NAME = 'emails';
	const VALIDATION_TABLE_NAME = 'validation';

	/**
	 * MailChecker constructor.
	 * @param PDO $dbh
	 * @param QueryFactory $query_factory
	 * @param int $batch_size defines the size of batch
	 */
	public function __construct(PDO $dbh, QueryFactory $query_factory, int $batch_size = 1000)
	{
		$this->dbh = $dbh;
		$this->offset = 0;
		$this->length = $batch_size;
		$this->query_factory = $query_factory;
	}


	/**
	 * Gets list of emails (page from offset to length). Works somehow like cursor
	 */
	private function getEmails()
	{
		$q_get_emails = $this->newSelect()
			->from(self::EMAILS_TABLE_NAME)
			->limit($this->length)
			->offset($this->offset)
			->orderBy(array('i_id'));

		$this->offset += $this->length;
		$statement = $this->dbh->prepare($q_get_emails);
		try {
			$statement->execute(); //assume that PDO::ERRMODE_EXCEPTION is enabled
		} catch (Exception $e) {
			//TODO: some logging stuff here
		}

		$this->emails = $statement->fetchAll();
	}

	/**
	 * Checks email address syntax validation by built-in function filter_var
	 *
	 * @param $email
	 * @return bool
	 */
	private function validateSyntax($email): bool
	{
		return filter_var($email, FILTER_VALIDATE_EMAIL);
	}


	/**
	 * Dumb function for splitting email by @ and getting domain name
	 *
	 * @param $email
	 * @return string
	 */
	private function getDomainName($email): string
	{
		return end(explode('@', $email));
	}

	/**
	 * Checks validation of domain name in address. Uses DNS for checking
	 *
	 * @param $email
	 * @return bool
	 */
	private function validateSMTPResponse($email): bool
	{
		//TODO: send an email to check SMTP server response
//		switch ($code) {
//			case '250': // 250 Requested mail action okay, completed
//			case '450': // 450 Requested action not taken
//			case '451': // 451 Requested action aborted
//			case '452': // 452 Requested action not taken
//				return true;
//			default:
//				return false;
//		}
		return true;
	}


	/**
	 * Checks existence of domain name
	 *
	 * @param $email
	 * @return bool
	 */
	private function validateDomain($email): bool
	{
		$domain = $this->getDomainName($email);
		if (!isset($this->domains[$domain])) { // check domain name in `cache`
			$this->domains[$domain] = checkdnsrr($domain);
		}
		return $this->domains[$domain];
	}

	/**
	 * Checks email with all existing filters
	 * @param $email
	 * @return bool
	 */
	private function validateEmail($email): bool
	{
		return $this->validateSyntax($email)
			&& $this->validateDomain($email)
			&& $this->validateSMTPResponse($email);
	}

	/**
	 *
	 */
	private function checkEmails()
	{
		foreach ($this->emails as &$email) {
			$email['is_valid'] = $this->validateEmail($email['m_mail']);
		}
	}

	/**
	 *
	 */
	private function updateEmails()
	{
		$q_ins_validation = $this->query_factory->newInsert()->table(self::VALIDATION_TABLE_NAME);
		foreach ($this->emails as $email) {
			$q_ins_validation->addRow();
			$q_ins_validation->cols(array(
				'is_valid' => $email['is_valid']
			));
		}
		$statement = $this->dbh->prepare($q_ins_validation);
		try {
			$statement->execute(); //assume that PDO::ERRMODE_EXCEPTION is enabled
		} catch (Exception $e) {
			//TODO: some logging stuff here
		}
	}


	/**
	 * Everything starts here
	 */
	public function processAll()
	{
		do {
			$this->getEmails();
			$this->checkEmails();
			$this->updateEmails();
		} while ($this->length != count($this->emails)); // got less, than length => done
	}

}