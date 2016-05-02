<?php namespace Adamcmoore\LaravelDbBackup\Commands;

use Illuminate\Console\Command;
use Config;
use Adamcmoore\LaravelDbBackup\DatabaseBuilder;
use Adamcmoore\LaravelDbBackup\ConsoleColors;

class BaseCommand extends Command
{
    protected $databaseBuilder;
    protected $colors;

    public function __construct(DatabaseBuilder $databaseBuilder)
    {
        parent::__construct();

        $this->databaseBuilder = $databaseBuilder;
        $this->colors          = new ConsoleColors();
    }

    public function getDatabase($databaseDriver)
    {
        $realConfig = Config::get('database.connections.' . $databaseDriver);

        return $this->databaseBuilder->getDatabase($realConfig);
    }

    protected function getDumpsPath()
    {
        return Config::get('laravel-db-backup::path');
    }
    
    protected function getS3DumpsPath()
    {
        if ($this->option('path-s3')) {
            $path = $this->option('path-s3');
        } else {
            $path = Config::get('laravel-db-backup::s3.path', 'databases');
        }

        return $path;
    }

}
