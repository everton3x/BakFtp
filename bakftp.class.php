<?php

/* 
 * Copyright (C) 2014 Everton
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

/**
 * This class makes it easy to backup files to FTP server.
 * 
 * It implements methods for compressing and sending files to an FTP server, display log on screen and log record on file and test the success of the backup by comparing hash of local file and remote file.
 * 
 * It works as follows: 
 * First, you create an instance of the class and configures it.
 * Second, you call the method backup () which will create a temporary directory, compress the files configured for backup, sends the data to a file on the FTP server tests whether the sending was successful (you can not do that if you want) deletes the temporary directory.
 * 
 * During the submission phase and test, initially the data is sent to a temporary file on the FTP server. If any delivery failure or the test occurs, the system retries sending the test and a pre-determined amount of time (default is three attempts). In the event of success in sending and testing, the temporary file is converted at the end of the backup file.
 */
class BakFtp{
    /**
     *
     * @var string Path to log directory. Default is a temporary directory.
     */
    protected $logDir;
    /**
     *
     * @var integer Maximum number of sending attempts and test compressed file.
     */
    protected $maxAttempt = 3;
    /**
     *
     * @var boolean Sets whether the backup process should be tested or not. Default is true.
     */
    protected $test = true;
    /**
     *
     * @var integer Warning counter.
     */
    protected $warnings = 0;
    /**
     *
     * @var integer error counter.
     */
    protected $errors = 0;
    /**
     *
     * @var array An array with files to backup.
     */
    protected $files = array();
    /**
     *
     * @var string The FTP wrapper. See {@link http://au1.php.net/manual/en/wrappers.ftp.php ftp:// on PHP Manual} for details.
     */
    protected $ftpWrapper = '';
    /**
     *
     * @var string The temporary directory of backup.
     */
    protected $tempDir = NULL;
    /**
     *
     * @var resource The file log resource opened with {@link http://au1.php.net/manual/en/function.fopen.php fopen PHP function}
     */
    protected $logFile = NULL;
    /**
     *
     * @var string The backup identifier
     */
    protected $bakId;
    
    /**
     * Constant for information type log.
     */
    const LT_INFO = 'INFO';
    /**
     * Constant for warning type log.
     */
    const LT_WARN = 'WARN';
    /**
     * Constant for error type log.
     */
    const LT_ERRO = 'ERRO';
    /**
     * Cosntant for unknow error.
     */
    const E_UNKNOW = 254;
    /**
     * Constant for normal finish. This does not necessarily indicate an error.
     */
    const E_NORMAL_FINISH = 0;
    /**
     * Constant for temporary directory errors.
     */
    const E_TEMPDIR = 1;
    /**
     * Constant to log errors.
     */
    const E_LOG = 2;
    /**
     * Constant to compress file errors.
     */
    const E_COMPRESS = 3;
    /**
     * Constant to ftp errors.
     */
    const E_FTP = 4;
    /**
     * Constant to test errors.
     */
    const E_TEST = 5;

    /**
     * Class constructor
     * @param string $bakid Optional. A backup identifier. If no value is set, assumes YYYYMMDDHHMMSS.
     * @param string $logDir Optinal. A path to directory for save log file. If no value is set, assumes temporary directory.
     */
    public function __construct($bakId = NULL, $logDir = NULL) {
        $this->writeLog('Booting up backup', true);
        //Setup backup identifier
        $this->writeLog('Setup backup identifier', true);
        if($bakId == NULL){
            $bakId = date('Ymdhis');
        }
        $this->bakId = $bakId;
        
        //Building temporary directory
        $this->writeLog('Building temporary directory', true);
        try{
            $tempDir = sys_get_temp_dir().DIRECTORY_SEPARATOR;
            if(file_exists("$tempDir{$this->bakId}".DIRECTORY_SEPARATOR)){
                $this->writeLog("$tempDir{$this->bakId} already exists an is deleted.", true);
                self::delTree("$tempDir{$this->bakId}".DIRECTORY_SEPARATOR);
                $this->writeLog("$tempDir{$this->bakId} was deleted.", true);
            }
            mkdir("$tempDir{$this->bakId}".DIRECTORY_SEPARATOR, '0777');
            $this->tempDir = "$tempDir{$this->bakId}".DIRECTORY_SEPARATOR;
            $this->writeLog("The temporary directory is {$this->tempDir}", true);
        } catch (Exception $ex) {
            $this->writeLog($ex->getMessage(), true, self::LT_ERRO);
            exit(self::E_TEMPDIR);
        }
        
        //Booting up log system
        $this->writeLog('Booting up log system', true);
        try{
            if($logDir == null){
                $this->logDir = $this->tempDir;
            }else{
                $this->logDir = $logDir;
            }
            $this->logFile = fopen("{$this->logDir}{$this->bakId}.log", 'w');
            $this->writeLog("Log file created in {$this->logDir}{$this->bakId}.log", true);
        } catch (Exception $ex) {
            $this->writeLog($ex->getMessage(), true, self::LT_ERRO);
            exit(self::E_LOG);
        }
        
        //writing initial log
        try {
            $this->writeLog("Backup started.");
            $this->writeLog("The backup identifier is {$this->bakId}");
            $this->writeLog("The temporary directory is {$this->tempDir}");
            $this->writeLog("The log file is {$this->tempDir}backup.log");
            $this->writeLog("Backup system ready.");
        } catch (Exception $ex) {
            $this->writeLog($ex->getMessage(), true, self::LT_ERRO);
        }
        
    }
    
    /**
     * Class destructor.
     */
    public function __destruct() {
        $this->writeLog("Starting the process of finalizing the class.", TRUE);
        fclose($this->logFile);
        $this->delTree($this->tempDir);
    }

        public function backup(){
        //initial log
        $count = count($this->files);
        $this->writeLog("Start backup id {$this->bakId} for $count into {$this->ftpWrapper}");
        $this->writeLog('The files included into backup are:');
        foreach($this->files as $f){
            $this->writeLog($f);
        }
        
        //compress
        try{
            $fail = 0;
            $success = 0;
            $zpname = $this->tempDir.$this->getBackupFileName();
            
            if(file_exists($zpname)){
                $flag = NULL;
            }else{
                $flag = ZipArchive::CREATE;
            }
            
            $zp = new ZipArchive();
            if($zp->open($zpname, $flag)){
                $this->writeLog("$zpname open.", true);
            }else{
                $this->writeLog("Failed trying to open $zpname.", FALSE, self::LT_ERRO);
                exit(self::E_COMPRESS);
            }
            
            foreach ($this->files as $f){
                if($zp->addFile($f)){
                    $this->writeLog("$f successfully added.");
                    $success++;
                }else{
                    $this->writeLog("Failed to add $f.", false, self::LT_WARN);
                    $fail++;
                }
            }
            
            if($zp->close()){
                $this->writeLog("$zpname closed.", TRUE);
            }else{
                $this->writeLog("Failed trying to colse $zpname.", FALSE, self::LT_WARN);
            }
            
            $this->writeLog("Compression finished with $success successes and $fail failures.");
            if($success == 0){//exit on 100% fail
                $this->writeLog("No success on compression. Aborting...", false, self::LT_WARN);
                exit(self::E_COMPRESS);
            }
        } catch (Exception $ex) {
            $this->writeLog($ex->getMessage(), false, self::LT_ERRO);
            exit(self::E_COMPRESS);
        }
        
        //md5
        $this->writeLog('Calculating md5 for compressed file.');
        $md5 = md5_file($this->tempDir.$this->getBackupFileName());
        $this->writeLog("$md5 is a md5 for compressed file");
        
        //get data
        try{
            if($data = file_get_contents($this->tempDir.$this->getBackupFileName())){
                $bytes = strlen($data);
                $this->writeLog("Get $bytes bytes from compressed file.", TRUE);
            }else{
                $this->writeLog("Fail to get data from compressed file. Aborting.", FALSE, self::LT_ERRO);
                exit(self::E_FTP);
            }
        } catch (Exception $ex) {
            $this->writeLog($ex->getMessage(), false, self::LT_ERRO);
            exit(self::E_UNKNOW);
        }
        
        //loop tentatives
        for($attempt = 1; $attempt <= $this->maxAttempt; $attempt++){
            //send to ftp
            try{
                $this->writeLog("Attempt #$attempt to sending {$this->getBackupFileName()} to FTP Server.", TRUE);
                $ftp = $this->ftpWrapper.$this->getBackupFileName().'.tmp';

                $opt = array('ftp' => array('overwrite' => true));
                $context = stream_context_create($opt);
                
                if($write = file_put_contents($ftp, $data, FILE_BINARY, $context)){
                    $this->writeLog("Writing $write bytes into remote file.", TRUE);
                }else{
                    $this->writeLog("Fail to writing data into remote file on attempt #$attempt.", FALSE, self::LT_ERRO);
                    $write = 0;
                    //exit(self::E_FTP);
                }

            } catch (Exception $ex) {
                $this->writeLog($ex->getMessage(), false, self::LT_ERRO);
                exit(self::E_FTP);
            }

            //test
            try{
                if($this->test == true){
                    if($write > 0){
                        $this->writeLog('Test mode is active.', TRUE);
                        //$ftp = $this->ftpWrapper.$this->getBackupFileName();

                        $remote_md5 = md5_file($ftp);

                        if($remote_md5 == $md5){
                            $this->writeLog("The md5 of remote file is equal the md5 local compressed file.");
                            $this->writeLog("Success of backup on attempt #$attempt.");
                            @unlink($this->ftpWrapper.$this->getBackupFileName());
                            if(rename($ftp, $this->ftpWrapper.$this->getBackupFileName()) == false){
                                $this->writeLog("Could not rename {$this->getBackupFileName()}.tmp to {$this->getBackupFileName()}. Do it manually");
                            }
                            break;
                        }else{
                            $this->writeLog("Remote md5 ($remote_md5) and local md5 ($md5) are differents.", FALSE, self::LT_WARN);
                            $this->writeLog("Fail of backup on attempt #$attempt.");
                        }
                    }else{
                        $this->writeLog("Skipping test because fail on write data into remote file on attempt #$attempt.", true);
                    }
                }else{
                    if($write > 0){
                        $this->writeLog('The test mode is off. Skipping.', false, self::LT_WARN);
                        @unlink($this->ftpWrapper.$this->getBackupFileName());
                        if(rename($ftp, $this->ftpWrapper.$this->getBackupFileName()) == false){
                            $this->writeLog("Could not rename {$this->getBackupFileName()}.tmp to {$this->getBackupFileName()}. Do it manually");
                        }
                        break;
                    }else{
                        $this->writeLog("Skipping test because fail on write data into remote file on attempt #$attempt.", true);
                    }
                }
            } catch (Exception $ex) {
                $this->writeLog($ex->getMessage(), false, self::LT_ERRO);
                exit(self::E_TEST);
            }
        }
        
        //end loop tentatives
        
        //finishing
        $this->writeLog("Backup finished with {$this->errors} errors and {$this->warnings} warnings.");
        exit(self::E_NORMAL_FINISH);
        
    }
    
    /**
     * Show messagens on screen and write into log file if $onlyScreen is FALSE.
     * @param string $msg A message to show.
     * @param boolean $onlyScreen TRUE to only show message on screen. Not a write into log file.
     * @param constant $type A class constant that represents the type of message.
     */
    protected function writeLog($msg, $onlyScreen = false, $type = self::LT_INFO){
        $print = sprintf("%s\t%s\t%s", date('Y-m-d H:i:s'), $type, $msg);
        switch ($type){
            case self::LT_ERRO:
                $this->errors++;
                break;
            case self::LT_WARN:
                $this->warnings++;
                break;
        }
        
        echo $print.PHP_EOL;
        
        if($onlyScreen == false){
            fwrite($this->logFile, $msg.PHP_EOL);
        }
    }
    
    /**
     * Delete the directory and your content.
     * @param string $dir A directory to delete.
     * @throws Exception
     */
    protected static function delTree($dir){
        try{
            if(is_file($dir)){
                unlink($dir);
            }else{
                foreach(glob($dir.'*') as $child){
                    self::delTree($child);
                }
                rmdir($dir);
            }
        } catch (Exception $ex) {
            throw $ex;
        }
    }
    
    /**
     * Set the FTP wrapper for {@link BakFtp::ftpWrapper}
     * @param string $ftpWrapper The FTP wrapper string. Use "ftp://user:password@ftp_server_url:ftp_port/path_to_backup_remote_dir/". Don't use file name. Always put "/" at the end of the string.
     */
    public function setFtpWrapper($ftpWrapper){
        $this->ftpWrapper = $ftpWrapper;
        $this->writeLog("Setup FTP Wrapper String $ftpWrapper", TRUE);
        return true;
    }
    
    /**
     * Get the FTP wrapper string.
     * @return string
     */
    public function getFtpWrapper(){
        return $this->ftpWrapper;
    }
    
    /**
     * Get the backup file name.
     * @return string
     */
    public function getBackupFileName(){
        return "{$this->bakId}.zip";
    }
    
    /**
     * Set the array with files to backup.
     * @param array $files
     * @return boolean
     */
    public function setFilesToBackup(array $files){
        $this->files = $files;
        $count = count($files);
        $this->writeLog("Setup of $count files to backup.", TRUE);
        return TRUE;
    }
    
    /**
     * Get the files configured for backup.
     * @return array
     */
    public function getFilesToBackup(){
        return $this->files;
    }
    
    /**
     * Get number of the errors on process.
     * @return integer
     */
    public function countErrors(){
        return $this->errors;
    }
    
    /**
     * Get number of the warnings on process.
     * @return integer
     */
    public function countWarnings(){
        return $this->warnings;
    }
    
    /**
     * Toogle test mode. Use true for activate test for backup, or false for deactivate.
     * @param boolean $bool
     * @return boolean Return test mode status.
     */
    public function toogleTestMode($bool){
        $this->test = $bool;
        return $this->test;
    }
    
    /**
     * Set the maximum number of sending attempts and test compressed file.
     * @param integer $attempt Maximum number of sending attempts and test compressed file.
     * @return boolean
     */
    public function setMaxAttempt($attempt){
        $this->maxAttempt = $attempt;
        return true;
    }
    
    /**
     * Get the maximum number of sending attempts and test compressed file.
     * @return integer
     */
    public function getMaxAttempt(){
        return $this->maxAttempt;
    }
    
    /**
     * Get the path to log file.
     * @return string
     */
    public function getLogDir(){
        return $this->logDir;
    }
}