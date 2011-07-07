<?php

	if(!defined("__IN_SYMPHONY__")) die("<h2>Error</h2><p>You cannot directly access this file</p>");

	/*
	Copyight: Solutions Nitriques 2011
	License: MIT
	*/

	/**
	 *
	 * Symphony CMS leaverages the Decorator pattern with their <code>Extension</code> class.
	 * This class is a Facade that implements <code>Singleton</code> and the methods
	 * needed by the Decorator. It offers its methods via the <code>instance()</code> satic function
	 * @author nicolasbrassard
	 *
	 */
	class ABF implements Singleton {

		/**
		 * Short hand for the tables name
		 * @var string
		 */
		private $TBL_ABF = 'tbl_anti_brute_force';
		private $TBL_ABF_WL = 'tbl_anti_brute_force_wl';
		private $TBL_ABF_GL = 'tbl_anti_brute_force_gl';
		private $TBL_ABF_BL = 'tbl_anti_brute_force_bl';

		/**
		 *
		 * Holds the path to the "send me unband link" page
		 * @todo use a dynamic way to get the /symphony/extensionx/ part
		 * @var string
		 */
		const UNBAND_LINK =  '/extension/anti_brute_force/login/';

		/**
		 * Singleton implementation
		 */
		private static $I = null;

		/**
		 *
		 * Singleton method
		 * @return ABF
		 */
		public static function instance() {
			if (self::$I == null) {
				self::$I = new self();
			}
			return self::$I;
		}

		// do not allow external creation
		private function __construct(){}


		/**
		 * FAILURES (BANNED IP) Public methods
		 */

		/**
		 *
		 * Check to see if the current user IP address is banned,
		 * based on the parameters passed to the method
		 * @param int/string $length
		 * @param int/string $failedCount
		 */
		public function isCurrentlyBanned($length, $failedCount) {
			if (!isset($length) || !isset($failedCount)) {
				return false; // no preference, how can we know...
			}
			$results = $this->getFailureByIp(null, "
				AND UNIX_TIMESTAMP(LastAttempt) + (60 * $length) > UNIX_TIMESTAMP()
				AND FailedCount >= $failedCount");

			return count($results) > 0;
		}

		/**
		 *
		 * Register a failure - insert or update - for a IP
		 * @param string $username - the username input
		 * @param string $source - the source of the ban, normally the name of the extension
		 * @param string $ip @optional - will take current user's ip
		 */
		public function registerFailure($username, $source, $ip='') {
			$ip = $this->getIP($ip);
			$username = MySQL::cleanValue($username);
			$source = MySQL::cleanValue($source);
			$ua = MySQL::cleanValue($this->getUA());
			$results = $this->getFailureByIp($ip);
			$ret = false;

			if ($results != null && count($results) > 0) {
				// UPDATE
				$ret = Symphony::Database()->query("
					UPDATE $this->TBL_ABF
						SET `LastAttempt` = NOW(),
						    `FailedCount` = `FailedCount` + 1,
						    `Username` = '$username',
						    `UA` = '$ua',
						    `Source` = '$source',
						    `Hash` = UUID()
						WHERE IP = '$ip'
						LIMIT 1
				");

			} else {
				// INSERT
				$ret = Symphony::Database()->query("
					INSERT INTO $this->TBL_ABF
						(`IP`, `LastAttempt`, `Username`, `FailedCount`, `UA`, `Source`, `Hash`)
						VALUES
						('$ip', NOW(),        '$username', 1,            '$ua','$source', UUID())
				");
			}

			return $ret;
		}

		/**
		 *
		 * Utility function that throw a properly formatted SymphonyErrorPage Exception
		 * @param string $length - length of block in minutes
		 * @param boolean
		 * @throws SymphonyErrorPage
		 */
		public function throwBannedException($length, $useUnbanViaEmail = false) {
			$msg =
				__('Your IP address is currently banned, due to typing too many wrong usernames/passwords')
				. '<br/><br/>' .
				__('You can ask your administrator to unlock your account or wait %s minutes', array($length));

			if ($useUnbanViaEmail == true || $useUnbanViaEmail == 'Yes') {
				$msg .= ('<br/><br/>' . __('Alternatively, you can <a href="%s">un-ban your IP by email</a>.', array(SYMPHONY_URL . self::UNBAND_LINK)));
			}

			// banned - throw exception
			throw new SymphonyErrorPage($msg, __('Banned IP address'));
		}

		/**
		 *
		 * Unregister IP from the banned table - even if max failed count is not reach
		 * @param string $filter @optional will take current user's ip
		 * can be the IP address or the hash value
		 */
		public function unregisterFailure($filter='') {
			$filter = MySQL::cleanValue($this->getIP($ip));
			return Symphony::Database()->delete($this->TBL_ABF, "IP = '$filter' OR Hash = '$filter'");
		}

		/**
		 *
		 * Delete expired entries
		 * @param string/int $length
		 */
		public function removeExpiredEntries($length) {
			return Symphony::Database()->delete($this->TBL_ABF, "UNIX_TIMESTAMP(LastAttempt) + (60 * $length) < UNIX_TIMESTAMP()");
		}


		/**
		 * COLORED (B/G/W) Public methods
		 */
		public function registerToBlackList($ip='') {
			return $this->registerToList($this->TBL_ABF_BL, $ip);
		}
		public function registerToGreyList($ip='') {
			return $this->registerToList($this->TBL_ABF_GL, $ip);
		}
		public function registerToWhiteList($ip='') {
			return $this->registerToList($this->TBL_ABF_WL, $ip);
		}

		private function registerToList($tbl, $ip='') {
			$ip = $this->getIP($ip);
			$results = $this->isListed($btl, $ip);
			$isGrey = $tbl == $this->TBL_ABF_GL;
			$ret = false;

			// do not re-register existing entries
			if ($results != null && count($results) > 0) {
				if ($isGrey) {
					$this->incrementGreyList($ip);
				}

			} else {
				// INSERT -- grey list will get the default values for others columns
				$ret = Symphony::Database()->query("
					INSERT INTO $tbl
						(`IP`, `DateCreated`, `Source`)
						VALUES
						('$ip', NOW(),        '$source')
				");
			}

			return $ret;
		}

		private function incrementGreyList($ip) {
			// UPDATE -- only Grey list
			return Symphony::Database()->query("
				UPDATE $tbl
					SET `FailedCount` = `FailedCount` + 1,
					WHERE IP = '$ip'
					LIMIT 1
			");
		}

		public function isBlackListed($ip='') {
			return $this->isListed($this->TBL_ABF_BL, $ip);
		}

		public function isGreyListed($ip='') {
			return $this->isListed($this->TBL_ABF_GL, $ip);
		}

		public function isWhiteListed($ip='') {
			return $this->isListed($this->TBL_ABF_WL, $ip);
		}

		private function isListed($tbl, $ip='') {
			$ip = $this->getIP($ip);
			return count($this->getListEntriesByIp($tbl, $ip)) > 0;
		}



		private function unregisterToList($tbl, $ip='') {
			$filter = MySQL::cleanValue($this->getIP($ip));
			return Symphony::Database()->delete($this->TBL_ABF, "IP = '$filter'");
		}

		/**
		 *
		 * Utility function that throw a properly formatted SymphonyErrorPage Exception
		 * @param string $length - length of block in minutes
		 * @param boolean
		 * @throws SymphonyErrorPage
		 */
		public function throwBlackListedException() {
			$msg =
				__('Your IP address is currently <strong>black listed</strong>, due to too many bans.')
				. '<br/><br/>' .
				__('Ask your administrator to unlock your IP.');

			// banned - throw exception
			throw new SymphonyErrorPage($msg, __('Black listed IP address'));
		}



		/**
		 * Database Data queries
		 */

		/**
		 *
		 * Method that returns failures based on IP address and other filters
		 * @param string $ip the ip in the select query
		 * @param string $additionalWhere @optional additional SQL filters
		 */
		public function getFailureByIp($ip='', $additionalWhere='') {
			$ip = $this->getIP($ip);
			$where = "IP = '$ip'";
			if (strlen($additionalWhere) > 0) {
				$where .= $additionalWhere;
			}
			$sql ="
				SELECT * FROM $this->TBL_ABF WHERE $where LIMIT 1
			" ;

			$rets = array();

			if (Symphony::Database()->query($sql)) {
				$rets = Symphony::Database()->fetch();
			}

			return $rets;
		}

		/**
		 *
		 * Method that returns all failures, optionally ordered
		 * @param string $orderedBy @optional
		 */
		public function getFailures($orderedBy='') {
			$order = '';
			if (strlen($orderedBy) > 0) {
				$order .= (' ORDER BY ' . $orderedBy);
			}
			$sql ="
				SELECT * FROM $this->TBL_ABF $order
			" ;

			$rets = array();

			if (Symphony::Database()->query($sql)) {
				$rets = Symphony::Database()->fetch();
			}

			return $rets;
		}


		public function getListEntriesByIp($tbl, $ip='', $additionalWhere='') {
			$ip = $this->getIP($ip);
			$where = "IP = '$ip'";
			if (strlen($additionalWhere) > 0) {
				$where .= $additionalWhere;
			}
			$sql ="
				SELECT * FROM $tbl WHERE $where LIMIT 1
			" ;

			$rets = array();

			if (Symphony::Database()->query($sql)) {
				$rets = Symphony::Database()->fetch();
			}

			return $rets;
		}


		/**
		 * Utilities
		 */
		private function getIP($ip='') {
			// ip is at least 8 char
			// hash is 36 char
			return strlen($ip) < 8 ? $_SERVER["REMOTE_ADDR"]: $ip;
		}

		private function getUA() {
			return $_ENV["HTTP_USER_AGENT"];
		}




		/**
		 * Database Data Definition Queries
		 */

		/**
		 *
		 * This method will install the plugin
		 */
		public function install() {
			return $this->install_v1_0() && $this->install_v1_1();
		}

		private function install_v1_0() {
			$sql = "
				CREATE TABLE IF NOT EXISTS $this->TBL_ABF(
					`IP` VARCHAR( 16 ) NOT NULL ,
					`LastAttempt` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ,
					`FailedCount` INT( 5 ) NOT NULL DEFAULT  '1',
					`UA` VARCHAR( 1024 ) NULL,
					`Username` VARCHAR( 100 ) NULL,
					`Source` VARCHAR( 100 ) NULL,
					`Hash` CHAR( 36 ) NOT NULL,
					PRIMARY KEY (  `IP` )
				) ENGINE = MYISAM
			";

			return Symphony::Database()->query($sql);
		}

		private function install_v1_1() {
			// GREY
			$sql = "
				CREATE TABLE IF NOT EXISTS $this->TBL_ABF_GL (
					`IP` VARCHAR( 16 ) NOT NULL ,
					`DateCreated` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ,
					`FailedCount` INT( 5 ) NOT NULL DEFAULT  '1',
					`Source` VARCHAR( 100 ) NULL,
					PRIMARY KEY (  `IP` )
				) ENGINE = MYISAM
			";

			$retGL = Symphony::Database()->query($sql);

			//BLACK
			$sql = "
				CREATE TABLE IF NOT EXISTS $this->TBL_ABF_BL (
					`IP` VARCHAR( 16 ) NOT NULL ,
					`DateCreated` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ,
					`Source` VARCHAR( 100 ) NULL,
					PRIMARY KEY (  `IP` )
				) ENGINE = MYISAM
			";

			$retBL = Symphony::Database()->query($sql);

			// WHITE
			$sql = "
				CREATE TABLE IF NOT EXISTS $this->TBL_ABF_WL (
					`IP` VARCHAR( 16 ) NOT NULL ,
					`DateCreated` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ,
					`Source` VARCHAR( 100 ) NULL,
					PRIMARY KEY (  `IP` )
				) ENGINE = MYISAM
			";

			$retWL = Symphony::Database()->query($sql);

			return $retGL && $retBL && $retWL;
		}

		/**
		 *
		 * This methode will update the extension according to the
		 * previous and current version parameters.
		 * @param string $previousVersion
		 * @param string $currentVersion
		 */
		public function update($previousVersion, $currentVersion) {
			switch ($previousVersion) {
				case $currentVersion:
					break;
				case '1.1':
					break;
				case '1.0':
					$this->install_v1_1();
					break;
				default:
					return $this->install();
			}
			return false;
		}

		/**
		 *
		 * This method will uninstall the extension
		 */
		public function uninstall() {
			// Banned IPs
			$sql = "
				DROP TABLE IF EXISTS $this->TBL_ABF
			";

			$retABF = Symphony::Database()->query($sql);

			// Black
			$sql = "
				DROP TABLE IF EXISTS $this->TBL_ABF_BL
			";

			$retABF_BL = Symphony::Database()->query($sql);

			// Grey
			$sql = "
				DROP TABLE IF EXISTS $this->TBL_ABF_GL
			";

			$retABF_GL = Symphony::Database()->query($sql);

			// White
			$sql = "
				DROP TABLE IF EXISTS $this->TBL_ABF_WL
			";

			$retABF_WL = Symphony::Database()->query($sql);

			return $retABF && $retABF_BL && $retABF_GL && $retABF_WL;
		}

	}