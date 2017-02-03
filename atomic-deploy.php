<?php

/*
 */

process(is_array($argv) ? $argv : array());

/**
 * processes the installer
 */
function process($argv)
{
    // Determine ANSI output from --ansi and --no-ansi flags
    setUseAnsi($argv);

    if (in_array('--help', $argv)) {
        displayHelp();
        exit(0);
    }

    $help       = in_array('--help', $argv);
    $quiet      = in_array('--quiet', $argv);
    $deployDir = getOptValue('--deploy-dir', $argv, getcwd());
    $deployCacheDir = getOptValue('--deploy-cache-dir', $argv, 'deploy-cache');
    $revision = getOptValue('--revision', $argv, false);
    $revisionsToKeep = getOptValue('--revisions-to-keep', $argv, 20);
    $symLinks = getOptValue('--symlinks', $argv, '{}');

    if (!checkParams($deployDir, $deployCacheDir, $revision, $revisionsToKeep, $symLinks)) {
        exit(1);
    }

    $deployer = new Deployer($quiet);
    if ($deployer->run($deployDir, $deployCacheDir, $revision, $revisionsToKeep, json_decode($symLinks))) {
        exit(0);
    }

    exit(1);
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

/**
 * Sets the USE_ANSI define for colorizing output
 *
 * @param array $argv Command-line arguments
 */
function setUseAnsi($argv)
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
 * Returns the value of a command-line option
 *
 * @param string $opt The command-line option to check
 * @param array $argv Command-line arguments
 * @param mixed $default Default value to be returned
 *
 * @return mixed The command-line value or the default
 */
function getOptValue($opt, $argv, $default)
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
function checkParams($deployDir, $deployCacheDir, $revision, $revisionsToKeep, $symLinks)
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
 * colorize output
 */
function out($text, $color = null, $newLine = true)
{
    $styles = array(
        'success' => "\033[0;32m%s\033[0m",
        'error' => "\033[31;31m%s\033[0m",
        'info' => "\033[33;33m%s\033[0m"
    );

    $format = '%s';

    if (isset($styles[$color]) && USE_ANSI) {
        $format = $styles[$color];
    }

    if ($newLine) {
        $format .= PHP_EOL;
    }

    printf($format, $text);
}

class Deployer {

    private $quiet;
    private $deployPath;
    private $revisionPath;
    private $errHandler;

    private $directories = array(
        'revisions' => 'revisions',
        'shared' => 'shared',
        'config' => 'shared/config',
        );

    /**
     * Constructor - must not do anything that throws an exception
     *
     * @param bool $quiet Quiet mode
     */
    public function __construct($quiet)
    {
        if (($this->quiet = $quiet)) {
            ob_start();
        }
        $this->errHandler = new ErrorHandler();
    }

    /**
     * 
     */
    public function run($deployDir, $deployCacheDir, $revision, $revisionsToKeep, $symLinks) {
        try {
            out('Creating atomic deployment directories...');
            $this->initDirectories($deployDir);

            out('Creating new revision directory...');
            $this->createRevisionDir($revision);

            out('Copying deploy-cache to new revision directory...');
            $this->copyCacheToRevision($deployCacheDir);

            out('Creating symlinks within new revision directory...');
            $this->createSymLinks($symLinks);

            out('Switching over to latest revision...');
            $this->linkCurrentRevision();

            out('Pruning old revisions...');
            $this->pruneOldRevisions($revisionsToKeep);

            $result = true;
        } catch (Exception $e) {
            $result = false;
        }

        // Always clean up
        $this->cleanUp($result);

        if (isset($e)) {
            // Rethrow anything that is not a RuntimeException
            if (!$e instanceof RuntimeException) {
                throw $e;
            }
            out($e->getMessage(), 'error');
        }
        return $result;   
    }

    /**
     * [initDirectories description]
     * @param  string $deployDir Base deployment directory
     * @return void
     * @throws RuntimeException If the deploy directory is not writable or dirs can't be created
     */
    public function initDirectories($deployDir) {
        $this->deployPath = (is_dir($deployDir) ? rtrim($deployDir, '/') : '');

        if (!is_writeable($deployDir)) {
            throw new RuntimeException('The deploy directory "'.$deployDir.'" is not writable');
        }

        if (!is_dir($this->directories['revisions']) && !mkdir($this->directories['revisions'])) {
            throw new RuntimeException('Could not create revisions directory.');
        }

        if (!is_dir($this->directories['shared']) && !mkdir($this->directories['shared'])) {
            throw new RuntimeException('Could not create shared directory.');
        }

        if (!is_dir($this->directories['config']) && !mkdir($this->directories['config'])) {
            throw new RuntimeException('Could not create config directory.');
        }
    }

    /**
     * Creates a revision directory under the revisions/ directory
     * @throws RuntimeException If directories can't be created
     */
    public function createRevisionDir($revision) {
        $this->revisionPath = $this->deployPath . DIRECTORY_SEPARATOR . $this->directories['revisions']. DIRECTORY_SEPARATOR . $revision;
        $this->revisionPath = rtrim($this->revisionPath, DIRECTORY_SEPARATOR);

        // Check to see if this revision was already deployed
        if (is_dir(realpath($this->revisionPath))) {
            $this->revisionPath = $this->revisionPath . '-' . time();
        }

        if (!is_dir($this->revisionPath) && !mkdir($this->revisionPath)) {
            throw new RuntimeException('Could not create revision directory: ' . $this->revisionPath);
        }

        if (!is_writeable($this->revisionPath)) {
            throw new RuntimeException('The revision directory "'.$this->revisionPath.'" is not writable');
        }
    }

    /**
     * Copies the deploy-cache to the revision directory
     */
    public function copyCacheToRevision($deployCacheDir) {
        $this->errHandler->start();

        exec("cp -a $deployCacheDir/. $this->revisionPath", $output, $returnVar);

        if ($returnVar > 0) {
            throw new RuntimeException('Could not copy deploy cache to revision directory: ' . $output); 
        }

        $this->errHandler->stop();
    }

    /**
     * Creates defined symbolic links
     */
    public function createSymLinks($symLinks) {
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
     * Sets the deployed revision as `current`
     */
    public function linkCurrentRevision() {
        $this->errHandler->start();

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
    public function pruneOldRevisions($revisionsToKeep) {
        if ($revisionsToKeep > 0) {
            $revisionsDir = $this->deployPath . DIRECTORY_SEPARATOR . $this->directories['revisions'];

            exec("ls -tp $revisionsDir/ | grep '/$' | tail -n +$revisionsToKeep | tr " . '\'\n\' \'\0\'' ." | xargs -0 rm -rf --",
                $output, $returnVar);

            if ($returnVar > 0) {
                throw new RuntimeException('Could not prune old revisions' . $output);
            }
        }
    }

    /**
     * Uses the system method `ln` to create a symlink
     */
    protected function createSymLink($target, $linkName) {
        exec("rm -rf $linkName && ln -sfn $target $linkName", $output, $returnVar);

        if ($returnVar > 0) {
            throw new RuntimeException($output);
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
}

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
