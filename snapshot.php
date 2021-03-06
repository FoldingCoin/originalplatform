<?php
//snapshot.php - Store snapshot of Folding@home public stats
//Copyright © 2015 FoldingCoin Inc., All Rights Reserved

$projBase='/home/fldcPlatform/mergedFolding/';
$projBin='bin';

include($projBase.$projBin.'/includes/functions.php');
include($projBase.$projBin.'/includes/classes.php');
include($projBase.'/config/db.php');

$runStart=time();
echo "Start ".date("c",$runStart)."\n";

$platformAssets=populateAssets();

$statsUrls[]='http://fah-web.stanford.edu/daily_team_summary.txt.bz2';
$statsUrls[]='http://fah-web.stanford.edu/daily_user_summary.txt.bz2';

$todayYYYYMMDD=date("Ymd");
if(preg_match("/test/",$mode)){
	//$todayYYYYMMDD='20141231';
}

///date debug
//$todayYYYYMMDD=20150216;
///date debug

echo "run $todayYYYYMMDD\n";

//Download the files from FAH
foreach($statsUrls as $statsUrl){
	list($discard,$localFile)=explode('edu/',$statsUrl);
	$localFile=$projBase.'FAHdata/'.$todayYYYYMMDD.$localFile;
	echo "$localFile\n";

	if(!file_exists($localFile)){
		echo "Downloading $localFile...\n";
		if (!copy($statsUrl, $localFile)) {
			echo "failed to copy $statsUrl...\n";
		}else{
			echo "Copied $statsUrl to $localFile.\n";
		}
	}else{
		echo "$localFile exists, not downloading ...\n";
	}
}

//Open the bzipped file
$bz = bzopen($localFile, "r") or die("Couldn't open $localFile");
$lines='';
$lastFileChunk='';
$fileChunk='';
$insertTimestamp='notfound';

//go through the bz file
while (!feof($bz)) {
	//take 4096 bytes at a time from the bz
	//now here's the tricky part, if this is the 2nd,3rd,4th chunk and so on...
	//  ...we got a "lastFileChunk" which is the final line, which will be a partial line
	//  becuase it's partial, we add it at the beginning of the next 4096 bytes
	//  this maintains continuity of the lines
	//  neat trick! I'm proud of myself! <grin>
	$fileChunk = $lastFileChunk.bzread($bz, 4096);
	//spit the lines from the 4096 bytes on carriage returns \n
	$lines=explode("\n",$fileChunk);
	//count the lines, and store the last line for the next pass of 4096 bytes
	//  count the lines and subtract one, as the array starts counting at 0
	$lastFileChunk=$lines[count($lines)-1];
	foreach($lines as $line){
		$normalizedFolders='';
		//echo "processing $line\n";
		//don't process the lastFileChunk
		if($line!=$lastFileChunk){
			if(!preg_match("/ /",$line)){
				//echo "insertTimestamp $insertTimestamp ".date("c",$insertTimestamp)."$line\n";
				$normalizedFolder=new normalizedFolder();
				//if we don't have a valid bitcoin address, see if it's an ownTeam folder
				if(!checkAddress($normalizedFolder->address)){
					$normalizedFolder->ownTeamFinder($line,$platformAssets);
					if(checkAddress($normalizedFolder->address)){
						$normalizedFolders[]=$normalizedFolder;
					}
				}
				//if we don't have a valid bitcoin address, it wasn't an ownTeam folder so try for an anyTeam folder
				if(!checkAddress($normalizedFolder->address)){
					$normalizedFolder->anyTeamFinder($line,$platformAssets);
					if(checkAddress($normalizedFolder->address)){
						$normalizedFolders[]=$normalizedFolder;
					}
				}
				
				//if we still don't have a valid bitcoin address, it could be an _ALL_ folder
				if(!checkAddress($normalizedFolder->address) AND preg_match("/\_ALL\_/",$line)){
					foreach($platformAssets as $platformAsset){
						$normalizedFolder=new normalizedFolder();
						$normalizedFolder->allAssetsFinder($line,$platformAsset);
						if(checkAddress($normalizedFolder->address)){
							$normalizedFolders[]=$normalizedFolder;
						}
					}
				}

				if($normalizedFolders!=''){
					foreach($normalizedFolders as $normalizedFolder){
						//if we have a valid bitcoin address, prepare a snapshot and insert it
						if(checkAddress($normalizedFolder->address)){
							$folderRecords=buildSnapshotRecords($normalizedFolder,$insertTimestamp,$mode);
							//echo "Found a good folder\n";
							//var_dump($normalizedFolder);
							foreach($folderRecords as $folderRecord){
								var_dump($folderRecord);
								$insertRecords[]=$folderRecord;
							}
						}
					}
				}
				
				
			//builds the UNIX timestamp from the first line of the FAH file
			}elseif(preg_match("/ PDT /",$line) OR preg_match("/ PST /",$line)){
				$insertTimestamp=strtotime($line);
			}
		}
	}
}

foreach($insertRecords as $insertRecord){
	$insertResult=insertRecord($insertRecord);
}



$runEnd=time();
echo "End ".date("c",$runEnd)."\n";
$runTime=$runEnd-$runStart;
echo "script ran in $runTime secs.\n";

//end main code






function buildSnapshotRecords($normalizedFolder,$snapshotTimestamp,$mode){
	$snapshotRecords='';
	$recordAsset=$normalizedFolder->assetName;
	$thisSnapshotRecord=new snapshotRecord($recordAsset,$normalizedFolder,$snapshotTimestamp,$mode);
	$snapshotRecords[]=$thisSnapshotRecord;
	
	return($snapshotRecords);
}

function insertRecord($insertRecord){
	$insertResult='start';
	//$insertQuery="INSERT INTO fldcPlatform.platformCredits (snapshotTimestamp,assetName,snaptype,address,friendlyName,fahName,fahTeam,cumulativeCredits,mode) VALUES (".$insertRecord->snapshotTimestamp.",'".$insertRecord->assetName."','".$insertRecord->snaptype."','".$insertRecord->address."','".$insertRecord->friendlyName."','".$insertRecord->fahName."',".$insertRecord->fahTeam.",".$insertRecord->cumulativeCredits.",'".$insertRecord->mode."')";
	//echo "$insertQuery;\n";
	$db=dbConnect();
	if ($stmt = $db->prepare("INSERT INTO fldcPlatform.platformCredits (snapshotTimestamp,assetName,snaptype,address,friendlyName,fahName,fahTeam,fahSHA256,cumulativeCredits,mode) VALUES (?,?,?,?,?,?,?,?,?,?)")) {
		//var_dump($insertRecord);

		/* bind parameters for markers */
		$stmt->bind_param("isssssisis", $insertRecord->snapshotTimestamp,$insertRecord->assetName,$insertRecord->snaptype,$insertRecord->address,$insertRecord->friendlyName,$insertRecord->fahName,$insertRecord->fahTeam,$insertRecord->fahSHA256,$insertRecord->cumulativeCredits,$insertRecord->mode);
		/* execute query */
		$stmt->execute();
		//var_dump($stmt);
		/* close statement */
		$stmt->close();
	}
	return($insertResult);
}












?>