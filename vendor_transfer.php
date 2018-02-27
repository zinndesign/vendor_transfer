<?php
error_reporting(E_ERROR | E_WARNING | E_PARSE);
date_default_timezone_set('America/New_York');

// remove first argument
array_shift($argv);

// get and use remaining arguments
$input_file = trim( $argv[0] );
$input_file = str_replace('\\','', $input_file);

$homedir = getcwd();

// What brand? Grab code from current directory name
$split = explode('/', $homedir);
$code = array_pop($split);

// we'll write to a sub-folder so action doesn't try to modify new files as they're added
$outpath = $homedir . '/processed/';

if(!file_exists($outpath)) {
    mkdir($outpath); // watch for permissions issues
}

$log = $outpath . 'results.log';
write2log($log, "Input file: " . basename($input_file));

if( !file_exists($input_file) ) { // does it exist?
	write2log($log, 'ERROR: Input file not found');
    die();
}

// what brand? Read the current directory for brand code
$split = explode('/', $homedir);
$code = array_pop($split);

// determine all the files in the directory that match the transfer filetypes
// should pull this from filetypes table, hard-coded for now
$files_in_dir = glob($homedir.'/*.{pdf,xls,xlsx,zip}', GLOB_BRACE);
write2log($log, print_r($files_in_dir,true));

// if the new file is NOT a PDF, let's make sure that one is already available
// (in the event a missing file was added after the fact)
if( strtolower( substr($input_file, -3) ) != 'pdf' ) {
    // search the array
    $pdf_check = preg_grep("/.+\.pdf$/", $files_in_dir);
    if(count($pdf_check)==0) {
        write2log($log, 'ERROR: Transfers require a PDF file');
        die("no pdf found\n");
    }
}

// database file should be one level up
$db_path = 'sqlite:' . implode('/', $split) . '/pwxvendor.sqlite';
write2log($log, "PATH TO DATABASE: " . $db_path);

$db  = new PDO($db_path) or die("Cannot open the data source - check that pwxvendor.sqlite is one level up from this directory.");

// query db to get all files (naming conventions) for the brand
$query = "SELECT DISTINCT(bv.ncID_list) FROM brands b, brand2vendor bv where b.brandID = bv.brandID and b.brandCode =  '$code'";
$result = dbQuery($db, $query);

// in case the brand code is bad...
if($result === false) {
    die("No results found for brand code $code. Please check that the directory name matches an existing brand code.");
}

$ncIDs = array();

foreach($result as $row) {
   $id_list = explode(',',$row[0]);
   $ncIDs = array_merge($ncIDs, $id_list);
}

$ncID_list = implode(',', array_unique($ncIDs));

// get the expected file formats so we can verify all are present
// get the date regex so we can sub it in for the search
$query = "SELECT ncID, ncFormat, date_format, regex FROM naming_conventions nc, date_regex re WHERE nc.regexID = re.regexID AND ncID IN($ncID_list)";
$result = dbQuery($db, $query);

$missing_files = array();
$transfer_files = array();

foreach($result as $row) {
    $pattern = str_replace('XX', $code, $row['ncFormat']); // sub the brand code
    $regex_pattern = str_replace($row['date_format'], stripslashes($row['regex']), $pattern); // sub the date regex
    $regex_pattern = str_replace('.','\.',$regex_pattern); // escape the extension dot
    
    // search the files in the directory to ensure a match
    $matches = preg_grep("/$regex_pattern/", $files_in_dir);
    if(count($matches)==0) {
        $missing_files[] = $pattern;
    } else {
        $transfer_files[$row['ncID']] = array_shift($matches);
    }
    
}

if(count($missing_files)>0) {
    write2log($log, "Required files are missing. Please add files matching the following patterns:");
    write2log($log, print_r($missing_files,true));
    die("Missing files:\n" . print_r($missing_files, true));
} else {
    write2log($log, count($transfer_files) . " files for transfer:\n" . print_r($transfer_files, true));
}

// if we're still running at this point, all files are present, and we can grab FTP info and start transfers
// first, let's get the vendors for this brand

$query = "SELECT vendorName, ftpID, ncID_list, bundled FROM brands b, vendors v, brand2vendor bv WHERE b.brandID = bv.brandID AND v.vendorID =  bv.vendorID AND brandCode = '$code'";
$result = dbQuery($db, $query);

$transfers = array();
// create an array of transfer objects
foreach($result as $row) {
    $files2send = array();
    $ncIDs = explode(',',$row['ncID_list']);
    foreach($ncIDs as $val) {
        $files2send[] = $transfer_files[$val];
    }
    $transfers[$row['vendorName']] = new Transfer($row['vendorName'], $row['ftpID'], $files2send, $row['bundled']);
}

write2log($log, print_r($transfers,true));

// for each vendor, grab the FTP info and run the transfers
foreach($transfers as $key => $obj) {
    $transfer = $obj;
    $query = "SELECT URL, username, passwd, dir, portnum, protocol FROM ftp WHERE ftpID = " . $transfer->ftpID;
    $result = dbQuery($db, $query);
    foreach($result as $ftp) {
      print_r($ftp);
      if($ftp['protocol'] == 'FTP') {
         echo "\n\nFTP, move along\n\n";
         //$result = FTPtransfer($ftp['URL'], $ftp['username'], $ftp['passwd'], $ftp['dir'], $ftp['portnum'], $transfer->files);
         //$transfers[$key]->status = ($result === true ? 'complete' : 'failed');
      } else {
         SFTPtransferBatch($ftp['URL'], $ftp['username'], $ftp['passwd'], $ftp['dir'], $ftp['portnum'], $transfer->files);
      }
    }
}

/***** USER-DEFINED OBJECTS & FUNCTIONS *****/

class Transfer {
    // properties
    public $vendor;
    public $ftpID;
    public $files;
    public $bundled;
    public $status = 'pending';
    
    // constructor
    public function __construct($vendor, $ftpID, $files, $bundled) {
      $this->vendor = $vendor;
      $this->ftpID = $ftpID;
      $this->files = $files;
      $this->bundled = $bundled;
    }
}

function FTPtransfer($url, $user, $pw, $dir, $port, $files) {
    $conn_id = ftp_connect($url, $port, 30);
    if(!$conn_id) {
      write2log($log, "Couldn't connect to $url");
      return false;
    }
    
    $login_result = ftp_login($conn_id, $user, $pw);
    
    // check connection
    if (!$login_result) {
      echo "FTP connection has failed, check credentials for $url\n";
      $result = false;
    } else {
      echo "Connected to $url\n";
    }
    
    // if the directory is not null, switch to it
    if($dir != 'NULL') {
      // try to change the directory to somedir
      if (ftp_chdir($conn_id, $dir)) {
          write2log($log, "Current directory is now: " . ftp_pwd($conn_id));
      } else { 
          write2log($log, "Couldn't change directory - verify that it exists");
      }
    }
    
    // move the files
   foreach($files as $file) {
      $remote_file = basename($file);
      write2log($log, "Attempting to transfer $file");
      // temp
      write2log($log, "Successfully uploaded $file");
      $result = true;
      //if (ftp_put($conn_id, $remote_file, $file, FTP_BINARY)) {
      //   write2log($log, "Successfully uploaded $file");
      //   $result = true;
      //} else {
      //   write2log($log, "There was a problem while uploading $file");
      //   $result = false;
      //}
   }
    
   // close the connection
   ftp_close($conn_id);
   return $result;
}

function SFTPtransferBatch($url, $user, $pw, $dir, $port, $files) {
   global $log;
   
   // create a temp file to write the batch SFTP commands
   $tmpfname = tempnam("/tmp", "PWX");
   
   // create the content for the batch file
   $batchtext = '';
   
   // do we have to change directories?
   if($dir != 'NULL') {
      $batchtext .= "cd $dir\n";
   }
   
   // send the files
   foreach($files as $file) {
      $batchtext .= "put $file\n";
   }
   
   $handle = fopen($tmpfname, "w");
   fwrite($handle, $batchtext);
   fclose($handle);
   
   $command = "sshpass -p \"$pw\" sftp -oBatchMode=no " . ($port==22?'':"-P $port ") . "-b $tmpfname $user@$url";
   echo $command;
   
   $result = `$command`;
   
   unlink($tmpfname);
   
   // see if return from command indicates failure
   if(stripos($result, 'timed out')>-1 || stripos($result, 'not found')>-1 || stripos($result, 'refused')>-1) {
      write2log($log, "The transfer to $url failed. Error returned:\n$result");
      return false;
   } else {
      write2log($log, count($files) . " files uploaded via SFTP to $url");
      return true;
   }
   
   // Reference: https://stackoverflow.com/a/5646552/374689 (batchfile)
   // Reference: https://stackoverflow.com/a/21494235/374689 (using sshpass)
   // Reference: https://it.toolbox.com/question/putting-a-file-on-a-remote-server-via-sftp-using-expect-120211 (using expect)
   // https://stackoverflow.com/a/15682600/374689 (using expect)
   // https://stackoverflow.com/a/15098339/374689 (using expect)
}

function dbQuery($db_handle, $sql) {
    $result = $db_handle->query($sql);
    if ($result !== false) {
        return $result;
    } else {
        return false;
    }
}

function write2log($logfile, $message) {
    $now = date("Y-m-d H:i:s");
    file_put_contents($logfile, $now . " - " .$message . "\n", FILE_APPEND | LOCK_EX);
}
?>