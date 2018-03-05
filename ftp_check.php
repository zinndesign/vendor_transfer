<?php
error_reporting(E_ERROR | E_WARNING | E_PARSE);
date_default_timezone_set('America/New_York');

$homedir = getcwd();
$log = $homedir . '/results.log';
unlink($log);
`touch $log`;

// database file should be one level up
$db_path = 'sqlite:' . $homedir . '/pwxvendor.sqlite';
write2log($log, "PATH TO DATABASE: " . $db_path);

$db  = new PDO($db_path) or die("Cannot open the data source - check that pwxvendor.sqlite is one level up from this directory.");

// query db to get all files (naming conventions) for the brand
$query = "SELECT vendorName, URL, username, passwd, dir, portnum, protocol FROM ftp f, vendors v, brands b, brand2vendor bv WHERE f.vendorID = v.vendorID GROUP BY username ORDER BY vendorName";
$result = dbQuery($db, $query);

foreach($result as $ftp) {
	//print_r($ftp);
	$line = $ftp['vendorName'] . "\t" . $ftp['URL'] . "\t" . $ftp['username'] . "\t" . $ftp['passwd'] . "\t" . $ftp['dir'] . "\t" . $ftp['portnum'] . "\t" . $ftp['protocol'] . "\t";
	if(strpos($ftp['username'], 'blergh')>-1) {
		$line .= "INFO MISSING\tFAILED";
	} elseif($ftp['protocol'] == 'FTP') {
		//continue;
		$result = FTPtransfer($ftp['URL'], $ftp['username'], $ftp['passwd'], $ftp['dir'], $ftp['portnum']);
		if($result == 'bad URL' || $result == 'bad credentials') {
			$line .= "$result\tFAILED";
		} else {
			$line .= "$result\tVALID";
		}
	} else {
		$result = SFTPtransfer($ftp['URL'], $ftp['username'], $ftp['passwd'], $ftp['dir'], $ftp['portnum']);
		if($result == 'bad URL' || $result == 'bad credentials' || $result == 'unknown error') {
			$line .= "$result\tFAILED";
		} else {
			$line .= "$result\tVALID";
		}
	}
	write2log($log, $line);
	echo $line . "\n";
}


function FTPtransfer($url, $user, $pw, $dir, $port) {
	global $log;
	
	$conn_id = ftp_connect($url, $port, 30);
	if(!$conn_id) {
		$result = 'bad URL';
	} else {
		$login_result = ftp_login($conn_id, $user, $pw);
	
		// check connection
		if (!$login_result) {
			$result = 'bad credentials';
		} else {
			$result = 'success';
		}
	}
	
	if($conn_id) {
		ftp_close($conn_id); // close the connection	
	}
	return $result;
}

function SFTPtransfer($url, $user, $pw, $dir, $port) {
   global $log;
   
	sleep(5); // delay a bit to avoid too many connections
	
	// create a temp file to write the batch SFTP commands
   $tmpfname = tempnam("/tmp", "PWX");
   
   $handle = fopen($tmpfname, "w");
   fwrite($handle, "exit\n");
   fclose($handle);
	
   $command = "sshpass -p \"$pw\" sftp -oBatchMode=no " . "-b $tmpfname $user@$url"; // ($port==22?'':"-P $port ")
	echo $command . "\n";
   
   exec($command .' 2>&1', $result, $return_value);
   
   $str_result = implode("\n", $result);
   
	//unlink($tmpfname);
	
   // see if return from command indicates failure
   if(stripos($str_result, 'timed out')>-1) {
      return 'bad URL';
   } elseif(stripos($str_result, 'refused')>-1) {
      return 'bad credentials';
   } elseif($return_value == 255) {
		return 'unknown error';
	} else {
		return 'success';
	}
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
    //file_put_contents($logfile, $now . " - " .$message . "\n", FILE_APPEND | LOCK_EX);
	 file_put_contents($logfile, $message . "\n", FILE_APPEND | LOCK_EX);
}
?>