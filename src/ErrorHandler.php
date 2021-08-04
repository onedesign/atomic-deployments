<?php


namespace Onedesign\AtomicDeploy;


class ErrorHandler
{
	public $message;
	protected $active;

	/**
	 * Handle php errors
	 *
	 * @param mixed $code The error code
	 * @param mixed $msg The error message
	 */
	public function handleError($code, $msg)
	{
		if ($this->message) {
			$this->message .= PHP_EOL;
		}
		$this->message .= preg_replace('{^file_get_contents\(.*?\): }', '', $msg);
	}

	/**
	 * Starts error-handling if not already active
	 *
	 * Any message is cleared
	 */
	public function start()
	{
		if (!$this->active) {
			set_error_handler(array($this, 'handleError'));
			$this->active = true;
		}
		$this->message = '';
	}

	/**
	 * Stops error-handling if active
	 *
	 * Any message is preserved until the next call to start()
	 */
	public function stop()
	{
		if ($this->active) {
			restore_error_handler();
			$this->active = false;
		}
	}
}