<?php namespace Adamcmoore\LaravelDbBackup\Databases;

use Adamcmoore\LaravelDbBackup\Console;
use Illuminate\Support\Facades\Config;

class MySQLDatabase implements DatabaseInterface
{

	protected $console;
	protected $database;
	protected $user;
	protected $password;
	protected $host;
	protected $port;

	public function __construct(Console $console, $database, $user, $password, $host, $port)
	{
		$this->console = $console;
		$this->database = $database;
		$this->user = $user;
		$this->password = $password;
		$this->host = $host;
		$this->port = $port;
	}

	public function dump($destinationFile, $options = null)
	{
		$command = sprintf('mysqldump --user=%s --password=%s --host=%s --port=%s %s %s > %s',
			escapeshellarg($this->user),
			escapeshellarg($this->password),
			escapeshellarg($this->host),
			escapeshellarg($this->port),
			$options,
			escapeshellarg($this->database),
			escapeshellarg($destinationFile)
		);
		
		return $this->console->run($command);
	}

	public function restore($sourceFile)
	{
		$command = sprintf('mysql --user=%s --password=%s --host=%s --port=%s %s < %s',
			escapeshellarg($this->user),
			escapeshellarg($this->password),
			escapeshellarg($this->host),
			escapeshellarg($this->port),
			escapeshellarg($this->database),
			escapeshellarg($sourceFile)
		);

		return $this->console->run($command);
	}


	public function restoreBinLogs($logs, $toTime, $fromPos)
	{
		$command = sprintf('mysqlbinlog --start-position=%d --stop-datetime=%s %s | mysql --user=%s --password=%s --host=%s --port=%s %s',
			intval($fromPos),
			escapeshellarg($toTime->toDateTimeString()),
			implode(' ', array_map('escapeshellarg', $logs)),
			escapeshellarg($this->user),
			escapeshellarg($this->password),
			escapeshellarg($this->host),
			escapeshellarg($this->port),
			escapeshellarg($this->database)
		);

		return $this->console->run($command);
	}


	public function getBinLogs($sourceFile) 
	{
        // Find the binlog_path config
        $logPath = Config::get('laravel-db-backup::mysql.binlog_path');;


		// Check for the master log file in the dump
		$logFile = false;
		$logPos  = 0;
		$pattern = "/CHANGE MASTER TO MASTER_LOG_FILE='(mysql-bin\.[0-9]+)'(?:, MASTER_LOG_POS=([0-9]+))?/";
		$matches = [];
		$fh = fopen($sourceFile, 'r');
		while (!feof($fh)) {
		    $line = fgets($fh, 4096);
		    if (preg_match($pattern, $line, $matches)) {
		    	$logFile = $matches[1];

		    	if (count($matches) === 3) {
		    		$logPos = $matches[2];
		    	}

		    	break;
		    }
		}
		fclose($fh);

		if (!$logFile) {
			throw new \Exception('Bin Log could not be determined from mysql dump.');
		}

		// Find subsequent log files
		$logs 	   = [];
		$logStart = intval(explode('.', $logFile)[1]);
		$logFiles = glob($logPath.'mysql-bin.*');

		foreach ($logFiles as $logFile) {
			$logNo = intval(explode('.', $logFile)[1]);
			if ($logNo >= $logStart) {
				$logs[] = $logFile;
			}
		}
		
		if (count($logs) == 0) {
			throw new \Exception('Bin Logs could not be found.');
		}


		// Disable Bin Logging by appending setting to start of the dump file
		$command = sprintf('echo "SET SESSION SQL_LOG_BIN=0;" | cat - %s > temp && mv temp %s',
			escapeshellarg($sourceFile),
			escapeshellarg($sourceFile)
		);
		
		if (!$this->console->run($command)) {			
			throw new \Exception('Setting SQL_LOG_BIN off in MySQL dump failed.');	
		}

		return [
			'logs' 	=> $logs,
			'pos'	=> $logPos,
		];
	}


	public function getFileExtension()
	{
		return 'sql';
	}

}
