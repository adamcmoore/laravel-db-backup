<?php namespace Adamcmoore\LaravelDbBackup\Commands;

use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Illuminate\Support\Facades\Event;
use AWS;
use Config;
use Guzzle\Http;
use Carbon\Carbon;

class BackupCommand extends BaseCommand
{

    protected $name = 'db:backup';
    protected $description = 'Backup the default database to `app/storage/dumps`';
    protected $filePath;
    protected $fileName;

    public function fire()
    {
        $databaseDriver = Config::get('database.default', false);

        $databaseOption = $this->option('database');

        if ( ! empty($databaseOption)) {
            $databaseDriver = $databaseOption;
        }

        $database = $this->getDatabase($databaseDriver);
        $dbConnectionConfig = Config::get('database.connections.' . $databaseDriver);

        $this->checkDumpFolder();

        $customFilename = $this->argument('filename');
        $filenameTime = Carbon::now()->format('Y-m-d-H-i-s');

        if ($customFilename) {
            // Is it an absolute path?
            if (substr($customFilename, 0, 1) == '/') {
                $this->filePath = $customFilename;
                $this->fileName = basename($this->filePath);
            } // It's relative path?
            else {
                $this->filePath = getcwd() . '/' . $customFilename;
                $this->fileName = basename($this->filePath) . '_' . $filenameTime;
            }
        } else {
            $this->fileName = $dbConnectionConfig['database'] . '_' . $filenameTime . '.' . $database->getFileExtension();
            $this->filePath = rtrim($this->getDumpsPath(), '/') . '/' . $this->fileName;
        }

        $dumpOptions = $this->option('dump-options');

        $status = $database->dump($this->filePath, $dumpOptions);

        if ($status === true) {

            // create zip archive
            if ($this->option('archive')) {
                $zip = new \ZipArchive();
                $zipFileName = $dbConnectionConfig['database'] . '_' . $filenameTime . '.zip';
                $zipFilePath = dirname($this->filePath) . '/' . $zipFileName;

                try {
                    if ($zip->open($zipFilePath, \ZipArchive::CREATE) === true) {
                        $zip->addFile($this->filePath, basename($this->filePath));
                        $zip->close();
                    } else {
                        throw new \Exception("Error opening zip archive for writing");                        
                    }                    
                } catch (Exception $e) {
                    $this->line(sprintf($this->colors->getColoredString("\n" . 'Archiving failed: %s' . "\n", 'red'), $e->getMessage()));                    
                }
                

                // Verify the zip file was created correctly
                $verifyZip = $zip->open($zipFilePath, \ZipArchive::CHECKCONS);
                if ($verifyZip === TRUE) {
                    // delete .sql files
                    unlink($this->filePath);

                    // change filename and filepath to zip
                    $this->filePath = $zipFilePath;
                    $this->fileName = $zipFileName;

                    $this->line($this->colors->getColoredString("\n" . 'Archiving successful' . "\n", 'green'));

                } else {
                    switch($verifyZip) {
                        case \ZipArchive::ER_NOZIP:
                            $zipError = 'not a zip archive';
                        case \ZipArchive::ER_INCONS :
                            $zipError = 'consistency check failed';
                        case \ZipArchive::ER_CRC :
                            $zipError = 'checksum failed';
                        default:
                           $zipError = 'error ' . $verifyZip;
                    }

                    $this->line(sprintf($this->colors->getColoredString("\n" . 'Archiving failed: %s' . "\n", 'red'), $zipError));
                }
            }


            // display success message
            if ($customFilename) {
                $this->line(sprintf($this->colors->getColoredString("\n" . 'Database backup was successful. Saved to %s' . "\n", 'green'), $this->filePath));
            } else {
                $this->line(sprintf($this->colors->getColoredString("\n" . 'Database backup was successful. %s was saved in the dumps folder.' . "\n", 'green'), $this->fileName));
            }

            // upload to s3
            if ($this->option('upload-s3')) {
                $this->uploadS3();
                $this->line($this->colors->getColoredString("\n" . 'Upload to S3 successful.' . "\n", 'green'));
                
                if ($this->option('data-retention-s3')) {
                    $this->dataRetentionS3();
                }

                // remove local archive if desired
                if ($this->option('s3-only')) {
                    unlink($this->filePath);
                }
            }


            if ($this->option('data-retention')) {
                $this->dataRetention();
            }
        
            if ( ! empty($dbConnectionConfig['slackWebhookPath'])) {
                $disableSlackOption = $this->option('disable-slack');
                if ( ! $disableSlackOption) $this->notifySlack($dbConnectionConfig);
            }

        } else {
            // todo
            $this->line(sprintf($this->colors->getColoredString("\n" . 'Database backup failed. %s' . "\n", 'red'), $status));
        }


        // Raise event to notify success (used to setup custom alerts such as email)
        Event::fire('laravel-db-backup.complete', [
            'status'    => $status,
            'filepath'  => $this->filePath,
            'filename'  => $this->fileName,
            'config'    => $dbConnectionConfig,
        ]);
    }

    /**
     * Get the console command arguments.
     *
     * @return array
     */
    protected function getArguments()
    {
        return array(
            array('filename', InputArgument::OPTIONAL, 'Filename or -path for the dump.'),
        );
    }

    protected function getOptions()
    {
        return array(
            array('database', null, InputOption::VALUE_REQUIRED, 'The database connection to backup'),
            array('upload-s3', 'u', InputOption::VALUE_OPTIONAL, 'Upload the dump to your S3 bucket'),
            array('path-s3', null, InputOption::VALUE_OPTIONAL, 'The folder in which to save the backup'),
            array('data-retention', null, InputOption::VALUE_OPTIONAL, 'Number of days to retain backups'),
            array('data-retention-s3', null, InputOption::VALUE_OPTIONAL, 'Number of days to retain S3 backups'),
            array('disable-slack', null, InputOption::VALUE_NONE, 'Manually disable Slack notifications'),
            array('archive', null, InputOption::VALUE_OPTIONAL, 'Create zip archive'),
            array('s3-only', null, InputOption::VALUE_OPTIONAL, 'Delete local archive after S3 upload'),
            array('dump-options', null, InputOption::VALUE_OPTIONAL, 'Database dump additional options'),
        );
    }

    protected function checkDumpFolder()
    {
        $dumpsPath = $this->getDumpsPath();

        if ( ! is_dir($dumpsPath)) {
            mkdir($dumpsPath);
        }
    }

    protected function uploadS3()
    {
        $bucket = $this->option('upload-s3');
        $s3 = AWS::get('s3');

        $s3->putObject(array(
            'Bucket'     => $bucket,
            'Key'        => $this->getS3DumpsPath() . '/' . $this->fileName,
            'SourceFile' => $this->filePath,
        ));
    }


    private function dataRetention()
    {
        if ( ! $this->option('data-retention')) 
        {
            return;
        }

        $dataRetention = (int) $this->option('data-retention');

        if ($dataRetention <= 0) 
        {
            $this->error("Data retention should be a number");
            return;
        }


        $timestampForRetention = strtotime('-' . $dataRetention . ' days');
        $this->info('Retaining data where date is greater than ' . date('Y-m-d', $timestampForRetention));
        
        $files = array_merge(
            glob($this->getDumpsPath()."*.sql"), 
            glob($this->getDumpsPath()."*.zip")
        );
        $deleteCount = 0;
        foreach ($files as $file) 
        {
            if (!is_file($file)) continue;
            
            if ($timestampForRetention > filemtime($file)) 
            {
                try 
                {
                    unlink($file);
                    $this->info("The following file is beyond data retention and was deleted: {$file}");
                } 
                catch (Exception $e) {}
                
                $deleteCount++;
            }
        }


        if ($deleteCount > 0) {
            $this->info($deleteCount . ' file(s) were deleted.');
        }

        $this->info("");
    }


    private function dataRetentionS3()
    {
        if ( ! $this->option('data-retention-s3')) {
            return;
        }

        $dataRetention = (int) $this->option('data-retention-s3');

        if ($dataRetention <= 0) {
            $this->error("S3 Data retention should be a number");
            return;
        }

        $bucket = $this->option('upload-s3');
        $s3 = AWS::get('s3');

        $list = $s3->listObjects(array(
            'Bucket' => $bucket,
            'Marker' => $this->getS3DumpsPath(),
        ));

        $timestampForRetention = strtotime('-' . $dataRetention . ' days');
        $this->info('Retaining S3 data where date is greater than ' . date('Y-m-d', $timestampForRetention));

        $contents = $list['Contents'];

        $deleteCount = 0;
        foreach ($contents as $fileArray) {
            $fileTimestamp = Carbon::parse($fileArray['LastModified']);

            if ($timestampForRetention > $fileTimestamp->timestamp) {
                $this->info("The following S3 file is beyond data retention and was deleted: {$fileArray['Key']}");
                // delete
                $s3->deleteObject(array(
                    'Bucket' => $bucket,
                    'Key'    => $fileArray['Key']
                ));
                $deleteCount++;
            }
        }

        if ($deleteCount > 0) {
            $this->info($deleteCount . ' S3 file(s) were deleted.');
        }

        $this->info("");
    }

    private function notifySlack($databaseConfig)
    {
        $this->info('Sending slack notification..');
        $data['text'] = "A backup of the {$databaseConfig['database']} database at {$databaseConfig['host']} has been created.";
        $data['username'] = "Database Backup";
        $data['icon_url'] = "https://s3-ap-northeast-1.amazonaws.com/Adamcmoore/images/icon_database.png";

        $content = json_encode($data);

        $command = "curl -X POST --data-urlencode 'payload={$content}' https://hooks.slack.com/services/{$databaseConfig['slackWebhookPath']}";

        shell_exec($command);
        $this->info('Slack notification sent!');
    }

}
