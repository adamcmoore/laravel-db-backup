<?php namespace Adamcmoore\LaravelDbBackup\Commands;

use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Finder\Finder;
use Config;
use AWS;
use Carbon\Carbon;

class RestoreCommand extends BaseCommand
{
	protected $name = 'db:restore';

	protected $description = 'Restore a dump from `app/storage/dumps`';

	protected $database;

	public function fire()
	{
        //get default db driver from config unless we provide other db driver from cli command
        $this->database = $this->getDatabase(Config::get('database.default'));

        $databaseOption = $this->input->getOption('database');

        if ( ! empty($databaseOption)) {
            $this->database = $this->getDatabase($databaseOption);
        }

        $fileName = $this->argument('dump');

		if ($this->option('restore-s3')) 
		{
			if ($fileName)
			{
				$this->restoreS3Dump($fileName);
			}
			else
			{
				$this->listAllS3Dumps();
			}

		} 
		else 
		{
			if ($fileName)
			{
				$this->restoreDump($fileName);
			}
			else
			{
				$this->listAllDumps();
			}			
		}
	}

	protected function restoreDump($fileName)
	{
		$sourceFile = $this->getDumpsPath() . $fileName;
		$pathInfo = pathinfo($sourceFile);

		// extract zip archive
        if (strtolower($pathInfo['extension']) === 'zip') {
            $zip = new \ZipArchive();

            if ($zip->open($sourceFile) === true) {
            	$sourceFile = $pathInfo['dirname'].DIRECTORY_SEPARATOR.$pathInfo['filename'].'.sql';
                $zip->extractTo($pathInfo['dirname']);
                $zip->close();
            } else {
				$this->line($this->colors->getColoredString("\n".'Archive extraction failed.'."\n",'red'));
				die(); 	
            }
        }


		// Check if binlog should be restored
		if ($this->option('binlog'))
		{
			$binLogs = $this->database->getBinLogs($sourceFile);

			// Determine the time to restore the log up until
			try { 
				$restoreToTime = Carbon::createFromFormat(Carbon::DEFAULT_TO_STRING_FORMAT, $this->option('binlog')); 
			} catch(\InvalidArgumentException $x) { 
				$restoreToTime = Carbon::now();
			}
		}

		// Restore from the dump
		$status = $this->database->restore($sourceFile);


		// Restore from the binlogs
		if ($status === true && isset($binLogs)) {
			$status = $this->database->restoreBinLogs($binLogs['logs'], $restoreToTime, $binLogs['pos']);
			$this->line(sprintf($this->colors->getColoredString("\n".'Bin logs restored up until %s.'."\n",'green'), $restoreToTime->toDateTimeString()));
		}

		if ($status === true)
		{
			$this->line(sprintf($this->colors->getColoredString("\n".'%s was successfully restored.'."\n",'green'), $fileName));
		}
		else
		{
			$this->line($this->colors->getColoredString("\n".'Database restore failed: '.$status."\n",'red'));
		}
	}


	protected function restoreS3Dump($fileName)
	{
        $bucket = $this->option('restore-s3');
        $s3 = AWS::get('s3');

		try {
			$result = $s3->getObject(array(
			    'Bucket' => $bucket,
			    'Key'    => $fileName,
			    'SaveAs' => $this->getDumpsPath() . $fileName
			));
			
		} catch (Exception $e) {
			$this->line($this->colors->getColoredString("\n".'S3 Download failed: '.$e->getMessage()."\n",'red'));			
		}
		
		$this->line(sprintf($this->colors->getColoredString("\n".'%s downloaded from S3 bucket.'."\n",'green'), $fileName));

		$this->restoreDump($fileName);
	}


	protected function listAllDumps()
	{
		$finder = new Finder();
		$finder->files()->in($this->getDumpsPath());

		if ($finder->count() > 0)
		{
			$this->line($this->colors->getColoredString("\n".'Please select one of the following dumps:'."\n",'white'));

			$finder->sortByName();
			$count = count($finder);
			$i=0;
			foreach ($finder as $dump)
			{
				$i++;
				if($i!=$count){
					$this->line($this->colors->getColoredString($dump->getFilename(),'brown'));
				}else{
					$this->line($this->colors->getColoredString($dump->getFilename()."\n",'brown'));
				}
			}
		}
		else
		{
			$this->line($this->colors->getColoredString("\n".'You haven\'t saved any dumps.'."\n",'brown'));
		}
	}


	protected function listAllS3Dumps()
	{
        $bucket = $this->option('restore-s3');
        $s3 = AWS::get('s3');

		$dumps = $s3->getIterator('ListObjects', array(
			'Bucket'    => $bucket,
			'Prefix'    => $this->getS3DumpsPath()
		))->toArray();

		if (count($dumps) > 0) 
		{
			$this->line($this->colors->getColoredString("\n".'Please select one of the following dumps:'."\n",'white'));

			$count = count($dumps);
			$i=0;
			foreach ($dumps as $dump)
			{
				$i++;
				if($i!=$count){
					$this->line($this->colors->getColoredString(array_get($dump, 'Key'),'brown'));
				}else{
					$this->line($this->colors->getColoredString(array_get($dump, 'Key')."\n",'brown'));
				}
			}
		}
		else
		{
			$this->line($this->colors->getColoredString("\n".'There are no dumps in this S3 bucket folder'."\n",'brown'));
		}
	}


	protected function getArguments()
	{
		return array(
			array('dump', InputArgument::OPTIONAL, 'Filename of the dump')
		);
	}

	protected function getOptions()
	{
		return array(
			array('database', null, InputOption::VALUE_OPTIONAL, 'The database connection to restore to'),
            array('restore-s3', 'u', InputOption::VALUE_OPTIONAL, 'Restore the dump from this S3 bucket'),
            array('path-s3', null, InputOption::VALUE_OPTIONAL, 'The S3 folder in which to list your backups'),
            array('binlog', null, InputOption::VALUE_OPTIONAL, 'Restore from MySQL binlog, either to now or the specified date-time string'),
		);
	}

}
