<?php

namespace OneDesign\AtomicTests;

use PHPUnit\Framework\TestCase;
use OneDesign\AtomicDeploy\Deployer;

class CreateDirectoriesTest extends TestCase
{
	private $deployDirectory = __DIR__ . "/tmp/deploy";
	private $sharedDirectory = __DIR__ . "/tmp/deploy/shared";
	private $configDirectory = __DIR__ . "/tmp/deploy/shared/config";
	private $revisionsDirectory = __DIR__ . "/tmp/deploy/revisions";

	/** @test */
	public function it_creates_the_deploy_file_structure()
	{
		$deployer = new Deployer(false, $this->deployDirectory);
		$deployer->initDirectories($this->deployDirectory);
		touch($this->configDirectory . "/.env");
		mkdir($this->sharedDirectory . "/storage");

		$this->assertDirectoryExists($this->sharedDirectory);
		$this->assertDirectoryExists($this->configDirectory);
		$this->assertDirectoryExists($this->revisionsDirectory);

		$oldRevision = intval(date('Ymdhis')) - 1000;
		$veryOldRevision = $oldRevision - 2000;
		mkdir($this->revisionsDirectory . "/$veryOldRevision");
		mkdir($this->revisionsDirectory . "/$oldRevision");

		$revision = date('Ymdhis');
		$currentRevisionDir = $this->revisionsDirectory . DIRECTORY_SEPARATOR . $revision;
		$deployer->createRevisionDir($revision);

		$this->assertDirectoryExists($currentRevisionDir);

		$deployer->copyCacheToRevision(__DIR__ . "/tmp/deploy/deploy-cache");
		$this->assertFileExists($currentRevisionDir . "/test-file.txt");

		$symLinks = json_decode('{"shared/config/.env":".env","shared/storage":"storage"}');
		$deployer->createSymLinks($symLinks);

		$this->assertTrue(is_link($this->revisionsDirectory . "/" . $revision . "/.env"));
		$this->assertTrue(is_link($this->revisionsDirectory . "/"  . $revision . "/storage"));

		$deployer->linkCurrentRevision();

		$this->assertTrue(is_link($this->deployDirectory . "/current"));

		$deployer->pruneOldRevisions(1);
		$this->assertDirectoryDoesNotExist($this->revisionsDirectory . DIRECTORY_SEPARATOR . $veryOldRevision);
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