<?php


namespace Onedesign\AtomicDeploy;


use Exception;
use RuntimeException;

class Deployer
{
	private $quiet;
	private $deployPath;
	private $revisionPath;
	private $errHandler;
	private $useAnsi;

	private $directories = [
		'revisions' => 'revisions',
		'shared' => 'shared',
		'config' => 'shared/config'
	];

	public function __construct($quiet, $useAnsi = true, $directoryBase = '')
	{
		if ($this->quiet = $quiet) {
			ob_start();
		}

		foreach ($this->directories as $key => $directory) {
			$this->directories[$key] = rtrim($directoryBase, '/') . '/' . $directory;
		}

		$this->useAnsi = $useAnsi;

		$this->errHandler = new ErrorHandler();
	}

	public function run($deployDir, $deployCacheDir, $revision, $revisionsToKeep, $symLinks)
	{
		try {
			$this->out("Creating atomic deployment directories ...");
			$this->initDirectories($deployDir);

			$this->out('Creating new revision directory ...');
			$this->createRevisionDir($revision);

			$this->out('Copying deploy-cache to new revision directory ...');
			$this->copyCacheToRevision($deployCacheDir);

			$this->out('Creating symlinks within new revision directory ...');
			$this->createSymLinks($symLinks);

			$this->out('Switching over to latest revision...');
			$this->linkCurrentRevision();

			$this->out('Pruning old revisions...');
			$this->pruneOldRevisions($revisionsToKeep);

		} catch (\Exception $e) {
			$result = false;
		}

		$this->cleanUp($result);

		if (isset($e)) {
			if (!$e instanceof RuntimeException) {
				throw $e;
			}
			$this->out($e->getMessage(), 'error');
		}

		return $result;
	}

	/**
	 * Ensures necessary directories exist and are writeable.
	 * @param string $deployDir Base deployment directory.
	 * @return void
	 * @throws RuntimeException If the deploy directories is not writeable or dirs cannot be created.
	 */
	public function initDirectories($deployDir)
	{
		$this->deployPath = (is_dir($deployDir) ? rtrim($deployDir, '/') : '');

		if (!is_writable($deployDir)) {
			throw new RuntimeException('The deploy directory "' . $deployDir.'" is not writeable.');
		}

		if (!is_dir($this->directories['revisions']) && !mkdir($this->directories['revisions'])) {
			throw new RuntimeException('Could not create the revisions directory.');
		}

		if (!is_dir($this->directories['shared']) && !mkdir($this->directories['shared'])) {
			throw new RuntimeException('Could not create the shared directory.');
		}

		if (!is_dir($this->directories['config']) && !mkdir($this->directories['config'])) {
			throw new RuntimeException('Could not create config directory.');
		}
	}

	/**
	 * Creates a revision directory under the revisions/ directory.
	 * @param $revision
	 * @return void
	 * @throws RuntimeException If directories cannot be created.
	 */
	public function createRevisionDir($revision)
	{
		$this->revisionPath = $this->directories['revisions'] . DIRECTORY_SEPARATOR . $revision;
		$this->revisionPath = rtrim($this->revisionPath, DIRECTORY_SEPARATOR);

		if (is_dir(realpath($this->revisionPath))) {
			$this->revisionPath = $this->revisionPath . '-' . time();
		}
		if (!is_dir($this->revisionPath) && !mkdir($this->revisionPath)) {
			throw new RuntimeException('Could not create the revision directory "' . $this->revisionPath. '".');
		}

		if (!is_writable($this->revisionPath)) {
			throw new RuntimeException('The revision directory "' . $this->revisionPath . '" is not writable.');
		}
	}

	public function copyCacheToRevision($deployCacheDir)
	{
		$this->errHandler->start();

		exec("cp -a $deployCacheDir/. $this->revisionPath", $output, $returnVar);

		if ($returnVar > 0) {
			throw new RuntimeException('Could not copy deploy cache to revision directory "' . $output . '".');
		}

		$this->errHandler->stop();
	}

	/**
	 * Creates defined symlinks
	 * @param $symLinks
	 * @throws RuntimeException If the symlinks cannot be created.
	 */
	public function createSymLinks($symLinks)
	{
		$this->errHandler->start();

        foreach($symLinks as $target => $linkName) {
            $t = $this->deployPath . DIRECTORY_SEPARATOR . $target;
            $l = $this->revisionPath . DIRECTORY_SEPARATOR . $linkName;

            try {
                $this->createSymLink($t, $l);
            } catch (Exception $e) {
                throw new RuntimeException("Could not create symlink $t -> $l: " . $e->getMessage());
            }
        }

        $this->errHandler->stop();
	}

	/**
	 * Uses the system method `ln` to create a symlink
	 * @param $target
	 * @param $linkName
	 * @throws RuntimeException If there is a problem creating the symlink.
	 * @return void
	 */
	protected function createSymLink($target, $linkName)
	{
		exec("rm -rf $linkName && ln -sfn $target $linkName", $output, $returnVar);

        if ($returnVar > 0) {
            throw new RuntimeException($output);
        }
	}

	/**
	 * Sets the deployed revision as `current`
	 */
	public function linkCurrentRevision()
	{
//		$this->errHandler->start();

		$revisionTarget = $this->revisionPath;
		$currentLink = $this->deployPath . DIRECTORY_SEPARATOR . 'current';

		try {
			$this->createSymLink($revisionTarget, $currentLink);
		} catch (Exception $e) {
			throw new RuntimeException("Could not create current symlink: " . $e->getMessage());
		}

		$this->errHandler->stop();
	}

	/**
	 * Removes old revision directories
	 */
	public function pruneOldRevisions($revisionsToKeep)
	{
		if ($revisionsToKeep > 0) {
			$revisionsDir = $this->deployPath . DIRECTORY_SEPARATOR . $this->directories['revisions'];

			// Never delete the most recent revision and start index after listing of the last revision we want to keep
			// e.g.
			//  revision-1/
			//  revision-2/
			//  revision-3/ <- --revisions-to-keep=1 will remove starting with this line
			//  revision-4/ <- --revisions-to-keep=2 will remove starting with this line
			$rmIndex = $revisionsToKeep + 2;

			// ls 1 directory by time modified | collect all dirs from ${revisionsToKeep} line of output | translate newlines and nulls | remove all those dirs
			exec("ls -1dtp ${revisionsDir}/** | tail -n +${rmIndex} | tr " . '\'\n\' \'\0\'' ." | xargs -0 rm -rf --",
				$output, $returnVar);

			if ($returnVar > 0) {
				throw new RuntimeException('Could not prune old revisions' . $output);
			}
		}
	}

	/**
	 * Cleans up resources at the end of the installation
	 *
	 * @param bool $result If the installation succeeded
	 */
	protected function cleanUp($result)
	{
		if (!$result) {
			// Output buffered errors
			if ($this->quiet) {
				$this->outputErrors();
			}
			// Clean up stuff we created
			$this->uninstall();
		}
	}

	/**
	 * Outputs unique errors when in quiet mode
	 *
	 */
	protected function outputErrors()
	{
		$errors = explode(PHP_EOL, ob_get_clean());
		$shown = array();

		foreach ($errors as $error) {
			if ($error && !in_array($error, $shown)) {
				out($error, 'error');
				$shown[] = $error;
			}
		}
	}

	/**
	 * Uninstalls newly-created files and directories on failure
	 *
	 */
	protected function uninstall()
	{
		if ($this->revisionPath && is_dir($this->revisionPath)) {
			unlink($this->revisionPath);
		}
	}

	/**
	 * Prints text to the console.
	 * @param String $text Text to be printed.
	 * @param null $color Optional: Color style to use.
	 * @param bool $newLine Optional: Whether to print a newline.
	 * @return void
	 */
	public function out($text, $color = null, $newLine = true)
	{
		$styles = [
			'success' => "\033[0;32m%s\033[0m",
			'error' => "\033[31;31m%s\033[0m",
			'info' => "\033[33;33m%s\033[0m"
		];

		$format = "%s";

		if (isset($styles[$color]) && $this->useAnsi) {
			$format = $styles[$color];
		}

		if ($newLine) {
			$format .= PHP_EOL;
		}

		printf($format, $text);
	}

}