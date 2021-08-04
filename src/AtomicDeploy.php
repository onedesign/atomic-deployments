<?php


namespace Onedesign\AtomicDeploy;


class AtomicDeploy
{
	public function process($argv)
	{
		$this->setUseAnsi($argv);

		if (in_array('--help', $argv)) {
			$this->displayHelp();
			exit(0);
		}

		$help               = in_array('--help', $argv);
		$quiet              = in_array('--quiet', $argv);

		$deployDir          = $this->getOptValue('--deploy-dir', $argv, getcwd());
		$deployCacheDir     = $this->getOptValue('--deploy-dir', $argv, 'deploy-cache');
		$revision           = $this->getOptValue('--revision', $argv, false);
		$revisionsToKeep    = $this->getOptValue('--revisions-to-keep', $argv, 5);
		$symLinks           = $this->getOptValue('--symlinks', $argv, '{}');

		if ($this->checkParams($deployDir, $deployCacheDir, $revision, $revisionsToKeep, $symLinks)) {
			exit(1);
		}

		$deployer = new Deployer($quiet);

		if ($deployer->run($deployDir, $deployCacheDir, $revision, $revisionsToKeep, json_decode($symLinks))) {
			exit(0);
		}

		exit(1);
	}

	/**
	 * Returns the value of a command-line option
	 *
	 * @param string $opt The command-line option to check
	 * @param array $argv Command-line arguments
	 * @param mixed $default Default value to be returned
	 *
	 * @return mixed The command-line value or the default
	 */
	public function getOptValue($opt, $argv, $default)
	{
		$optLength = strlen($opt);

		foreach ($argv as $key => $value) {
			$next = $key + 1;
			if (0 === strpos($value, $opt)) {
				if ($optLength === strlen($value) && isset($argv[$next])) {
					return trim($argv[$next]);
				} else {
					return trim(substr($value, $optLength + 1));
				}
			}
		}

		return $default;
	}


	/**
	 * Checks that user-supplied params are valid
	 *
	 * @param mixed $deployDir The required deployment directory
	 * @param mixed $deployCacheDir The required deployment cache directory
	 * @param mixed $revision A unique ID for this revision
	 * @param mixed $revisionsToKeep The number of revisions to keep after deploying
	 *
	 * @return bool True if the supplied params are okay
	 */
	public function checkParams($deployDir, $deployCacheDir, $revision, $revisionsToKeep, $symLinks)
	{
		$result = true;

		if (false !== $deployDir && !is_dir($deployDir)) {
			out("The defined deploy dir ({$deployDir}) does not exist.", 'info');
			$result = false;
		}

		if (false !== $deployCacheDir && !is_dir($deployCacheDir)) {
			out("The defined deploy cache dir ({$deployCacheDir}) does not exist.", 'info');
			$result = false;
		}

		if (false === $revision || empty($revision)) {
			out("A revision must be specified.", 'info');
			$result = false;
		}

		if (false !== $revisionsToKeep && (!is_int((integer)$revisionsToKeep) || $revisionsToKeep <= 0)) {
			out("Number of revisions to keep must be a number greater than zero.", 'info');
			$result = false;
		}

		if (false !== $symLinks && null === json_decode($symLinks)) {
			out("Symlinks parameter is not valid JSON.", 'info');
			$result = false;
		}

		return $result;
	}

	/**
	 * Sets the USE_ANSI define for colorizing output
	 *
	 * @param array $argv Command-line arguments
	 */
	public function setUseAnsi($argv)
	{
		// --no-ansi wins over --ansi
		if (in_array('--no-ansi', $argv)) {
			define('USE_ANSI', false);
		} elseif (in_array('--ansi', $argv)) {
			define('USE_ANSI', true);
		} else {
			// On Windows, default to no ANSI, except in ANSICON and ConEmu.
			// Everywhere else, default to ANSI if stdout is a terminal.
			define(
				'USE_ANSI',
				(DIRECTORY_SEPARATOR == '\\')
					? (false !== getenv('ANSICON') || 'ON' === getenv('ConEmuANSI'))
					: (function_exists('posix_isatty') && posix_isatty(1))
			);
		}
	}

	/**
	 * displays the help
	 */
	function displayHelp()
	{
		echo <<<EOF
Craft CMS Buddy Atomic Deploy
------------------
Options
--help                      this help
--ansi                      force ANSI color output
--no-ansi                   disable ANSI color output
--quiet                     do not output unimportant messages
--deploy-dir="..."          accepts a base directory for deployments
--deploy-cache-dir="..."    accepts a target cache directory
--revision                  a unique id for this revision
--revisions-to-keep         number of old revisions to keep (default 20)
--symlinks                  a JSON hash of symlinks to be created in the revision (format: {"target/":"linkname"})
                            e.g. --symlinks='{"shared/config/.env.php":".env.php","shared/storage":"craft/storage"}'

EOF;
	}

}