<?php

namespace Onedesign\AtomicTests;

use Onedesign\AtomicDeploy\Deployer;
use PHPUnit\Framework\TestCase;

class CreateDirectoriesTest extends TestCase
{
	private $deployDirectory = __DIR__ . "/tmp/deploy";
	private $sharedDirectory = __DIR__ . "/tmp/deploy/shared";
	private $configDirectory = __DIR__ . "/tmp/deploy/shared/config";
	private $revisionsDirectory = __DIR__ . "/tmp/deploy/revisions";
	private $currentRevisionDir;
	public $deployer;

	public function __construct()
	{
		$this->deployer = new Deployer(false, true, $this->deployDirectory);
		parent::__construct();
	}

	/** @test */
	public function it_creates_the_deploy_file_structure()
	{
		$deployer = new Deployer(false, true, $this->deployDirectory);

		$deployer->initDirectories($this->deployDirectory);

		touch($this->configDirectory . "/.env");
		mkdir($this->sharedDirectory . "/storage");

		$this->assertDirectoryExists($this->sharedDirectory);
		$this->assertDirectoryExists($this->configDirectory);
		$this->assertDirectoryExists($this->revisionsDirectory);

		$revision = date('Ymdhis');
		$this->currentRevisionDir = __DIR__ . "/tmp/deploy/revisions/" . $revision;
		$deployer->createRevisionDir($revision);

		$this->assertDirectoryExists($this->revisionsDirectory . '/' . $revision);


		$deployer->copyCacheToRevision(__DIR__ . "/tmp/deploy/deploy-cache");
		$this->assertFileExists($this->currentRevisionDir . "/test-file.txt");

		$symLinks = json_decode('{"shared/config/.env":".env","shared/storage":"storage"}');
		$deployer->createSymLinks($symLinks);

		$this->assertTrue(is_link($this->revisionsDirectory . '/'  . $revision . "/.env"));
		$this->assertTrue(is_link($this->revisionsDirectory . "/" . $revision . "/storage"));

		$deployer->linkCurrentRevision();
		$this->assertTrue(is_link($this->deployDirectory . "/current"));


	}

	public static function setUpBeforeClass(): void
	{
		exec('rm -rf ' . __DIR__ . '/tmp');
		mkdir(__DIR__ . "/tmp");
		mkdir(__DIR__ . "/tmp/deploy");
		mkdir(__DIR__ . "/tmp/deploy/deploy-cache");
		mkdir(__DIR__ . "/tmp/deploy/current");
		touch(__DIR__ . "/tmp/deploy/deploy-cache/test-file.txt");
	}
}