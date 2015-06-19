<?php
use \Symfony\Component\Process\Process;

class CapistranoDeploymentBackend extends Object implements DeploymentBackend {

	protected $packageGenerator;

	public function getPackageGenerator() {
		return $this->packageGenerator;
	}

	public function setPackageGenerator(PackageGenerator $packageGenerator) {
		$this->packageGenerator = $packageGenerator;
	}

	/**
	 * Return information about the current build on the given environment.
	 * Returns a map with keys:
	 * - 'buildname' - the non-simplified name of the build deployed
	 * - 'datetime' - the datetime when the deployment occurred, in 'Y-m-d H:i:s' format
	 */
	public function currentBuild($environment) {
		$file = DEPLOYNAUT_LOG_PATH . '/' . $environment . ".deploy-history.txt";

		if(file_exists($file)) {
			$lines = file($file);
			$lastLine = array_pop($lines);
			return $this->convertLine($lastLine);
		}
	}

	/**
	 * Deploy the given build to the given environment.
	 */
	public function deploy(DNEnvironment $environment, $sha, DeploynautLogFile $log, DNProject $project, $leaveMaintenancePage = false) {
		$name = $environment->getFullName();
		$repository = $project->LocalCVSPath;

		$args = array(
			'branch' => $sha,
			'repository' => $repository,
		);

		$this->extend('deployStart', $environment, $sha, $log, $project);

		$log->write(sprintf('Deploying "%s" to "%s"', $sha, $name));

		$this->enableMaintenance($environment, $log, $project);

		// Use a package generator if specified, otherwise run a direct deploy, which is the default behaviour
		// if build_filename isn't specified
		if($this->packageGenerator) {
			$args['build_filename'] = $this->packageGenerator->getPackageFilename($project->Name, $sha, $repository, $log);
			if(empty($args['build_filename'])) {
				throw new RuntimeException('Failed to generate package.');
			}
		}

		$command = $this->getCommand('deploy', 'web', $environment, $args, $log);
		$command->run(function($type, $buffer) use($log) {
			$log->write($buffer);
		});

		// Deployment cleanup. We assume it is always safe to run this at the end, regardless of the outcome.
		$self = $this;
		$cleanupFn = function() use($self, $environment, $args, $log) {
			$command = $self->getCommand('deploy:cleanup', 'web', $environment, $args, $log);
			$command->run(function($type, $buffer) use($log) {
				$log->write($buffer);
			});

			if(!$command->isSuccessful()) {
				// Notify ops via email, if possible.
				$to = Config::inst()->get('DNRoot', 'alerts_to');
				if(!$to) {
					$log->write('Warning: cleanup has failed, but fine to continue. Needs manual cleanup sometime.');
					return;
				} else {
					$log->write('Warning: cleanup has failed, but fine to continue. Alert email sent.');
				}

				$subject = sprintf('[Deploynaut] Cleanup failure on %s', $name);
				$email = new Email(null, $to, $subject);
				$email->setTemplate('CapistranoDeploymentBackend_cleanup');

				// Grab the last 3k characters, starting on a linebreak.
				$breakpoint = strrpos($log->content(), "\n", -3000);
				if($breakpoint === false) {
					$content = $log->content();
				} else {
					$content = "[... log has been trimmed ...]\n" . substr($log->content(), $breakpoint+1);
				}

				$email->populateTemplate(array(
					'ProjectName' => $project->Name,
					'EnvironmentName' => $environment->Name,
					'Log' => $content
				));

				$email->send();
			}
		};

		// Once the deployment has run it's necessary to update the maintenance page status
		if($leaveMaintenancePage) {
			$this->enableMaintenance($environment, $log, $project);
		}

		if(!$command->isSuccessful()) {
			$cleanupFn();
			throw new RuntimeException($command->getErrorOutput());
		}

		// Check if maintenance page should be removed
		if(!$leaveMaintenancePage) {
			$this->disableMaintenance($environment, $log, $project);
		}

		$cleanupFn();

		$log->write(sprintf('Deploy of "%s" to "%s" finished', $sha, $name));

		$this->extend('deployEnd', $environment, $sha, $log, $project);
	}

	/**
	 * Enable a maintenance page for the given environment using the maintenance:enable Capistrano task.
	 */
	public function enableMaintenance(DNEnvironment $environment, DeploynautLogFile $log, DNProject $project) {
		$name = $environment->getFullName();
		$command = $this->getCommand('maintenance:enable', 'web', $environment, null, $log);
		$command->run(function($type, $buffer) use($log) {
			$log->write($buffer);
		});
		if(!$command->isSuccessful()) {
			throw new RuntimeException($command->getErrorOutput());
		}
		$log->write(sprintf('Maintenance page enabled on "%s"', $name));
	}

	/**
	 * Disable the maintenance page for the given environment using the maintenance:disable Capistrano task.
	 */
	public function disableMaintenance(DNEnvironment $environment, DeploynautLogFile $log, DNProject $project) {
		$name = $environment->getFullName();
		$command = $this->getCommand('maintenance:disable', 'web', $environment, null, $log);
		$command->run(function($type, $buffer) use($log) {
			$log->write($buffer);
		});
		if(!$command->isSuccessful()) {
			throw new RuntimeException($command->getErrorOutput());
		}
		$log->write(sprintf('Maintenance page disabled on "%s"', $name));
	}

	/**
	 * Check the status using the deploy:check capistrano method
	 */
	public function ping(DNEnvironment $environment, DeploynautLogFile $log, DNProject $project) {
		$command = $this->getCommand('deploy:check', 'web', $environment, null, $log);
		$command->run(function($type, $buffer) use($log) {
			$log->write($buffer);
			echo $buffer;
		});
	}

	/**
	 * @inheritdoc
	 */
	public function dataTransfer(DNDataTransfer $dataTransfer, DeploynautLogFile $log) {
		if($dataTransfer->Direction == 'get') {
			$this->dataTransferBackup($dataTransfer, $log);
		} else {
			$environment = $dataTransfer->Environment();
			$project = $environment->Project();

			$workingDir = TEMP_FOLDER . DIRECTORY_SEPARATOR . 'deploynaut-transfer-' . $dataTransfer->ID;

			// Validate and fix any problems with the archive before performing the transfer
			$valid = $dataTransfer->DataArchive()->validateAndFixArchiveFile($log, $workingDir, $dataTransfer->Mode);
			if(!$valid) {
				throw new RuntimeException('Invalid archive, transfer aborted.');
			}

			// Put up a maintenance page during a restore of db or assets.
			$this->enableMaintenance($environment, $log, $project);
			$this->dataTransferRestore($workingDir, $dataTransfer, $log);
			$this->disableMaintenance($environment, $log, $project);
		}
	}

	/**
	 * @param string $action Capistrano action to be executed
	 * @param string $roles Defining a server role is required to target only the required servers.
	 * @param DNEnvironment $environment
	 * @param array $args Additional arguments for process
	 * @param DeploynautLogFile $log
	 * @return \Symfony\Component\Process\Process
	 */
	public function getCommand($action, $roles, DNEnvironment $environment, $args = null, DeploynautLogFile $log) {
		$name = $environment->getFullName();
		$env = $environment->Project()->getProcessEnv();

		if(!$args) $args = array();
		$args['history_path'] = realpath(DEPLOYNAUT_LOG_PATH.'/');

		// Inject env string directly into the command.
		// Capistrano doesn't like the $process->setEnv($env) we'd normally do below.
		$envString = '';
		if(!empty($env)) {
			$envString .= 'env ';
			foreach($env as $key => $value) {
				$envString .= "$key=\"$value\" ";
			}
		}

		$data = DNData::inst();
		// Generate a capfile from a template
		$capTemplate = file_get_contents(BASE_PATH.'/deploynaut/Capfile.template');
		$cap = str_replace(
			array('<config root>', '<ssh key>', '<base path>'),
			array($data->getEnvironmentDir(), DEPLOYNAUT_SSH_KEY, BASE_PATH),
			$capTemplate
		);

		if(defined('DEPLOYNAUT_CAPFILE')) {
			$capFile = DEPLOYNAUT_CAPFILE;
		} else {
			$capFile = ASSETS_PATH.'/Capfile';
		}
		file_put_contents($capFile, $cap);

		$command = "{$envString}cap -f " . escapeshellarg($capFile) . " -vv $name $action ROLES=$roles";
		foreach($args as $argName => $argVal) {
			$command .= ' -s ' . escapeshellarg($argName) . '=' . escapeshellarg($argVal);
		}

		$log->write(sprintf('Running command: %s', $command));

		$process = new Process($command);
		// Capistrano doesn't like it - see comment above.
		//$process->setEnv($env);
		$process->setTimeout(3600);
		return $process;
	}

	/**
	 * Backs up database and/or assets to a designated folder,
	 * and packs up the files into a single sspak.
	 *
	 * @param  DNDataTransfer    $dataTransfer
	 * @param  DeploynautLogFile $log
	 */
	protected function dataTransferBackup(DNDataTransfer $dataTransfer, DeploynautLogFile $log) {
		$environment = $dataTransfer->Environment();
		$name = $environment->getFullName();

		// Associate a new archive with the transfer.
		// Doesn't retrieve a filepath just yet, need to generate the files first.
		$dataArchive = DNDataArchive::create();
		$dataArchive->Mode = $dataTransfer->Mode;
		$dataArchive->AuthorID = $dataTransfer->AuthorID;
		$dataArchive->OriginalEnvironmentID = $environment->ID;
		$dataArchive->EnvironmentID = $environment->ID;
		$dataArchive->IsBackup = $dataTransfer->IsBackupDataTransfer();

		// Generate directory structure with strict permissions (contains very sensitive data)
		$filepathBase = $dataArchive->generateFilepath($dataTransfer);
		mkdir($filepathBase, 0700, true);

		$databasePath = $filepathBase . DIRECTORY_SEPARATOR . 'database.sql';

		// Backup database
		if(in_array($dataTransfer->Mode, array('all', 'db'))) {
			$log->write(sprintf('Backup of database from "%s" started', $name));
			$command = $this->getCommand('data:getdb', 'db', $environment, array('data_path' => $databasePath), $log);
			$command->run(function($type, $buffer) use($log) {
				$log->write($buffer);
			});
			if(!$command->isSuccessful()) {
				throw new RuntimeException($command->getErrorOutput());
			}
			$log->write(sprintf('Backup of database from "%s" done', $name));
		}

		// Backup assets
		if(in_array($dataTransfer->Mode, array('all', 'assets'))) {
			$log->write(sprintf('Backup of assets from "%s" started', $name));
			$command = $this->getCommand('data:getassets', 'web', $environment, array('data_path' => $filepathBase), $log);
			$command->run(function($type, $buffer) use($log) {
				$log->write($buffer);
			});
			if(!$command->isSuccessful()) {
				throw new RuntimeException($command->getErrorOutput());
			}
			$log->write(sprintf('Backup of assets from "%s" done', $name));
		}

		$log->write('Creating *.sspak file');
		$sspakFilename = sprintf('%s.sspak', $dataArchive->generateFilename($dataTransfer));
		$sspakCmd = sprintf('cd %s && sspak saveexisting %s 2>&1', $filepathBase, $sspakFilename);
		if($dataTransfer->Mode == 'db') {
			$sspakCmd .= sprintf(' --db=%s', $databasePath);
		} elseif($dataTransfer->Mode == 'assets') {
			$sspakCmd .= sprintf(' --assets=%s/assets', $filepathBase);
		} else {
			$sspakCmd .= sprintf(' --db=%s --assets=%s/assets', $databasePath, $filepathBase);
		}

		$process = new Process($sspakCmd);
		$process->setTimeout(3600);
		$process->run();
		if(!$process->isSuccessful()) {
			$log->write('Could not package the backup via sspak');
			throw new RuntimeException($process->getErrorOutput());
		}

		// HACK: find_or_make() expects path relative to assets/
		$sspakFilepath = ltrim(
			str_replace(
				array(ASSETS_PATH, realpath(ASSETS_PATH)),
				'',
				$filepathBase . DIRECTORY_SEPARATOR . $sspakFilename
			),
			DIRECTORY_SEPARATOR
		);

		try {
			$folder = Folder::find_or_make(dirname($sspakFilepath));
			$file = new File();
			$file->Name = $sspakFilename;
			$file->Filename = $sspakFilepath;
			$file->ParentID = $folder->ID;
			$file->write();

			// "Status" will be updated by the job execution
			$dataTransfer->write();

			// Get file hash to ensure consistency.
			// Only do this when first associating the file since hashing large files is expensive.
			$dataArchive->ArchiveFileHash = md5_file($file->FullPath);
			$dataArchive->ArchiveFileID = $file->ID;
			$dataArchive->DataTransfers()->add($dataTransfer);
			$dataArchive->write();
		} catch(Exception $e) {
			$log->write(sprintf('Failed to add sspak file: %s', $e->getMessage()));
			throw new RuntimeException($e->getMessage());
		}

		// Remove any assets and db files lying around, they're not longer needed as they're now part
		// of the sspak file we just generated. Use --force to avoid errors when files don't exist,
		// e.g. when just an assets backup has been requested and no database.sql exists.
		$process = new Process(sprintf('rm -rf %s/assets && rm -f %s', $filepathBase, $databasePath));
		$process->run();
		if(!$process->isSuccessful()) {
			$log->write('Could not delete temporary files');
			throw new RuntimeException($process->getErrorOutput());
		}

		$log->write(sprintf('Creating *.sspak file done: %s', $file->getAbsoluteURL()));
	}

	/**
	 * Utility function for triggering the db rebuild and flush.
	 * Also cleans up and generates new error pages.
	 */
	public function rebuild(DNEnvironment $environment, $log) {
		$name = $environment->getFullName();
		$command = $this->getCommand('deploy:migrate', 'web', $environment, null, $log);
		$command->run(function($type, $buffer) use($log) {
			$log->write($buffer);
		});
		if(!$command->isSuccessful()) {
			$log->write(sprintf('Rebuild of "%s" failed: %s', $name, $command->getErrorOutput()));
			throw new RuntimeException($command->getErrorOutput());
		}
		$log->write(sprintf('Rebuild of "%s" done', $name));
	}

	/**
	 * Extracts a *.sspak file referenced through the passed in $dataTransfer
	 * and pushes it to the environment referenced in $dataTransfer.
	 *
	 * @param string $workingDir Directory for the unpacked files.
	 * @param  DNDataTransfer    $dataTransfer
	 * @param  DeploynautLogFile $log
	 */
	protected function dataTransferRestore($workingDir, DNDataTransfer $dataTransfer, DeploynautLogFile $log) {
		$environment = $dataTransfer->Environment();
		$name = $environment->getFullName();

		// Rollback cleanup.
		$self = $this;
		$cleanupFn = function() use($self, $workingDir, $environment, $log) {
			// Rebuild makes sense even if failed - maybe we can at least partly recover.
			$self->rebuild($environment, $log);
			$process = new Process('rm -rf ' . escapeshellarg($workingDir));
			$process->run();
		};

		// Restore database into target environment
		if(in_array($dataTransfer->Mode, array('all', 'db'))) {
			$log->write(sprintf('Restore of database to "%s" started', $name));
			$args = array('data_path' => $workingDir . DIRECTORY_SEPARATOR . 'database.sql.gz');
			$command = $this->getCommand('data:pushdb', 'db', $environment, $args, $log);
			$command->run(function($type, $buffer) use($log) {
				$log->write($buffer);
			});
			if(!$command->isSuccessful()) {
				$cleanupFn();
				$log->write(sprintf('Restore of database to "%s" failed: %s', $name, $command->getErrorOutput()));
				throw new RuntimeException($command->getErrorOutput());
			}
			$log->write(sprintf('Restore of database to "%s" done', $name));
		}

		// Restore assets into target environment
		if(in_array($dataTransfer->Mode, array('all', 'assets'))) {
			$log->write(sprintf('Restore of assets to "%s" started', $name));
			$args = array('data_path' => $workingDir . DIRECTORY_SEPARATOR . 'assets');
			$command = $this->getCommand('data:pushassets', 'web', $environment, $args, $log);
			$command->run(function($type, $buffer) use($log) {
				$log->write($buffer);
			});
			if(!$command->isSuccessful()) {
				$cleanupFn();
				$log->write(sprintf('Restore of assets to "%s" failed: %s', $name, $command->getErrorOutput()));
				throw new RuntimeException($command->getErrorOutput());
			}
			$log->write(sprintf('Restore of assets to "%s" done', $name));
		}

		$log->write('Rebuilding and cleaning up');
		$cleanupFn();
	}

}
