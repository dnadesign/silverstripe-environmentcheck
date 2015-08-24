<?php

/**
 * Provides an interface for checking the given EnvironmentCheckSuite.
 */
class EnvironmentChecker extends RequestHandler {
	
	static $url_handlers = array(
		'' => 'index',
	);

	protected $checkSuiteName;
	
	protected $title;
	
	protected $errorCode = 500;

	public static $to_email_address = null;
	
	public static $from_email_address = null;
	
	public static $email_results = false;

	private static $template = 'EnvironmentChecker';
	
	function __construct($checkSuiteName, $title) {
		parent::__construct();
		
		$this->checkSuiteName = $checkSuiteName;
		$this->title = $title;
	}
	
	public function init($permission = 'ADMIN') {
		// if the environment supports it, provide a basic auth challenge and see if it matches configured credentials
		if(defined('ENVCHECK_BASICAUTH_USERNAME') && defined('ENVCHECK_BASICAUTH_PASSWORD')) {
			if(isset($_SERVER['PHP_AUTH_USER']) && isset($_SERVER['PHP_AUTH_PW'])) {
				// authenticate the input user/pass with the configured credentials
				if(
					!(
						$_SERVER['PHP_AUTH_USER'] == ENVCHECK_BASICAUTH_USERNAME
						&& $_SERVER['PHP_AUTH_PW'] == ENVCHECK_BASICAUTH_PASSWORD
					)
				) {
					$response = new SS_HTTPResponse(null, 401);
					$response->addHeader('WWW-Authenticate', "Basic realm=\"Environment check\"");
					// Exception is caught by RequestHandler->handleRequest() and will halt further execution
					$e = new SS_HTTPResponse_Exception(null, 401);
					$e->setResponse($response);
					throw $e;
				}
			} else {
				$response = new SS_HTTPResponse(null, 401);
				$response->addHeader('WWW-Authenticate', "Basic realm=\"Environment check\"");
				// Exception is caught by RequestHandler->handleRequest() and will halt further execution
				$e = new SS_HTTPResponse_Exception(null, 401);
				$e->setResponse($response);
				throw $e;
			}
		} else {
			if(!$this->canAccess(null, $permission)) return $this->httpError(403);
		}
	}

	function canAccess($member = null, $permission = "ADMIN") {
		if(!$member) {
			$member = Member::currentUser();
		}

		if(!$member && $permission) {
			$member = BasicAuth::requireLogin('Environment Checker', $permission, false);
		}

		// We allow access to this controller regardless of live-status or ADMIN permission only
		// if on CLI.  Access to this controller is always allowed in "dev-mode", or of the user is ADMIN.
		if(
			Director::isDev() 
			|| Director::is_cli()
			|| empty($permission)
			|| Permission::checkMember($member, $permission)
		) {
			return true;
		}

		// Extended access checks.
		// "Veto" style, return NULL to abstain vote.
		$canExtended = null;
		$results = $this->extend('canAccess', $member);
		if($results && is_array($results)) {
			if(!min($results)) return false;
			else return true;
		}

		return false;
	}
	
	function index() {
		$response = new SS_HTTPResponse();
		$result = EnvironmentCheckSuite::inst($this->checkSuiteName)->run();

		if(!$result->ShouldPass()) {
			$response->setStatusCode($this->errorCode);
		}

		$resultText = $result->customise(array(
			"URL" => Director::absoluteBaseURL(),
			"Title" => $this->title,
			"Name" => $this->checkSuiteName,
			"ErrorCode" => $this->errorCode,
		))->renderWith($this->config()->template);

		if (self::$email_results && !$result->ShouldPass()) {
			$email = new Email(
				self::$from_email_address, self::$to_email_address, $this->title, $resultText);
			$email->send();
		}

		// output the result as JSON if requested
		if(
			$this->getRequest()->getExtension() == 'json'
			|| strpos($this->getRequest()->getHeader('Accept'), 'application/json') !== false
		) {
			$response->setBody($result->toJSON());
			$response->addHeader('Content-Type', 'application/json');
			return $response;
		}

		$response->setBody($resultText);
		
		return $response;
	}

	/**
	 * Set the HTTP status code that should be returned when there's an error.
	 * Defaults to 500
	 */
	function setErrorCode($errorCode) {
		$this->errorCode = $errorCode;
	}

	public static function set_from_email_address($from) {
		self::$from_email_address = $from;
	}

	public static function get_from_email_address() {
		return self::$from_email_address;
	}

	public static function set_to_email_address($to) {
		self::$to_email_address = $to;
	}

	public static function get_to_email_address() {
		return self::$to_email_address;
	}

	public static function set_email_results($results) {
		self::$email_results = $results;
	}

	public static function get_email_results() {
		return self::$email_results;
	}
	
}
