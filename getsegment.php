<?php
setlocale(LC_ALL, 'en_US.utf-8'); //for php
putenv('LC_ALL=en_US.utf-8'); //for shell_exec

const MAX_WIDTH = 960;
const MAX_HEIGHT = 540;

const SEGMENT_CACHE = '/tmp/dash_segments';
const BUFFER_SIZE = 2097152;

function targetRes($arX, $arY){
	if($arX/$arY > MAX_WIDTH/MAX_HEIGHT){
		$newWidth = MAX_WIDTH;
		$newHeight = MAX_WIDTH * $arY / $arX;
		$newHeight = $newHeight & 0xFFFE;
	}else{
		$newHeight = MAX_HEIGHT;
		$newWidth = MAX_HEIGHT * $arX / $arY;
		$newWidth = $newWidth & 0xFFFE;
	}
	return array($newWidth,$newHeight);
}

function serveRange($filepath){
	$filesize = filesize($filepath);
	$range_start = 0;
	$range_end = $filesize - 1;
	$range_length = $filesize;
	if (isset($_SERVER['HTTP_RANGE'])) {
		preg_match('/bytes=(\d+)-(\d+)?/', $_SERVER['HTTP_RANGE'], $matches);
		$range_start = intval($matches[1]);
		$range_end = (array_key_exists(2, $matches) ? intval($matches[2]) : $filesize - 1);
		$range_length = $range_end + 1 - $range_start;
		header('HTTP/1.1 206 Partial Content');
		header("Content-Range: bytes $range_start-$range_end/$filesize");
	}
	$fp = fopen($filepath, 'r');
	fseek($fp, $range_start);
	while ($range_length >= BUFFER_SIZE){
		print(fread($fp, BUFFER_SIZE));
		$range_length -= BUFFER_SIZE;
	}
	if ($range_length) print(fread($fp, $range_length));
	fclose($fp);
}

if(!isset($_GET['file']) || !file_exists($_GET['file'])){
	header($_SERVER["SERVER_PROTOCOL"]." 404 Not Found");
	return;
}

if(!isset($_GET["type"]) || !in_array($_GET["type"], array("video", "audio"))){
	header($_SERVER["SERVER_PROTOCOL"]." 404 Not Found");
	return;
}

if((!isset($_GET["n"]) && !isset($_GET["init"])) || (isset($_GET["n"]) && isset($_GET["init"]))){
	header($_SERVER["SERVER_PROTOCOL"]." 404 Not Found");
	return;
}

if(isset($_GET["n"]) && (!is_numeric($_GET["n"]) || $_GET["n"] < 0)){
	header($_SERVER["SERVER_PROTOCOL"]." 404 Not Found");
	return;
}



if (!file_exists(SEGMENT_CACHE)) {
    mkdir(SEGMENT_CACHE, 0777, true);
}

$start = intval($_GET["n"]) * 5;
$videofile = $_GET["file"];
$basesegname = md5($videofile) . '_ss' . $start;
$basesegpath = SEGMENT_CACHE . '/' . $basesegname;
$baseinitpath = SEGMENT_CACHE . '/' . md5($videofile) . '_init';

$lockfile = $basesegpath . '.lock';
$lockfilefd = fopen($lockfile, 'a');
$locked = flock($lockfilefd, LOCK_EX);
if($locked){
	if(!file_exists($basesegpath . '.webm')){
		// ***** VIDEO *****
		$videoAspectStr = shell_exec('mediainfo --Output="Video;%DisplayAspectRatio/String%" ' . escapeshellarg($videofile));
		$videoAspectArr = explode(':', $videoAspectStr);
		$videoAspectArr = explode(':', $videoAspectStr);
		if(!array_key_exists(1,$videoAspectArr)){
			$videoAspectArr[1] = "1";
		}
		$newResolution = targetRes($videoAspectArr[0], $videoAspectArr[1]);
		$vf = ' -vf "yadif=mode=0:deint=1,scale=' .$newResolution[0] . ':' . $newResolution[1] . ',drawtext=text=\'' . date("Y-m-d H-i-s") . '\':fontcolor=white:box=1:boxcolor=black@0.5:boxborderw=5":x=5:y=5 ';
		
		shell_exec('ffmpeg -ss ' . $start . ' -i ' . escapeshellarg($videofile) . $vf . ' -t 5 -an -sn -map_metadata -1 -c:v libvpx-vp9 -crf 25 -b:v 4M -cpu-used 8 -deadline realtime -row-mt 1 -tile-columns 2 -tile-rows 2 -frame-parallel 1 -aq-mode variance -tune-content film -g 1000 -keyint_min 1000 -dash 1 -dash_segment_type webm -init_seg_name ' . escapeshellarg($basesegname . '_init.webm') . ' -media_seg_name ' . escapeshellarg($basesegname . '.webm') . ' ' . escapeshellarg($basesegpath . '.mpd') . ' 2>&1');

		// delete the ffmpeg-generated MPD
		unlink($basesegpath . '.mpd');
		// patch the timestamp
		shell_exec('python patch_segment_timestamp.py ' . escapeshellarg($basesegpath . '.webm') . ' ' . escapeshellarg($basesegpath . '.webm') . ' ' . $start * 1000);
		// The init segment doesn't contain any timestamp or duration so it doesn't matter if we always overwrite it
		rename($basesegpath . '_init.webm', $baseinitpath . '.webm');
		
		// ***** AUDIO *****
		$audioEncStart = $start ? $start - 0.5 : 0;
		$audioEncLen = $start ? 6 : 5.5;
		$audioCutStart = $start ? 0.5 : 0;
		shell_exec('ffmpeg -ss ' . $audioEncStart . ' -i ' . escapeshellarg($videofile) . ' -t ' . $audioEncLen . ' -ac 2 ' . escapeshellarg($basesegpath . '.opus'));
		// I don't know why but when I pass -t 5 the file comes out one frame (20ms) short.
		shell_exec('ffmpeg -i ' . escapeshellarg($basesegpath . '.opus') . ' -ss ' . $audioCutStart . ' -t 5.02 -c copy -dash 1 -seg_duration 10 -frag_duration 10 -dash_segment_type webm -init_seg_name ' . escapeshellarg($basesegname . 'a_init.webm') . ' -media_seg_name ' . escapeshellarg($basesegname . 'a.webm') . ' ' . escapeshellarg($basesegpath . 'a.mpd'));
		unlink($basesegpath . '.opus');
		// delete the ffmpeg-generated MPD
		unlink($basesegpath . 'a.mpd');
		// patch the timestamp
		shell_exec('python patch_segment_timestamp.py ' . escapeshellarg($basesegpath . 'a.webm') . ' ' . escapeshellarg($basesegpath . 'a.webm') . ' ' . $start * 1000);
		// The init segment doesn't contain any timestamp or duration so it doesn't matter if we always overwrite it
		rename($basesegpath . 'a_init.webm', $baseinitpath . 'a.webm');
	}
	flock($lockfilefd, LOCK_UN);
	fclose($lockfilefd);
	unlink($lockfile);
}else{
	while(file_exists($lockfile)){
		sleep(0.5);
	}
}

header('Accept-Ranges: bytes');
header('Expires: '.gmdate('D, d M Y H:i:s \G\M\T', time() + 60)); //cache for 1 minutes
switch($_GET["type"]){
case "video":
	header('Content-type: video/webm');
	if(isset($_GET["init"])){
		serveRange($baseinitpath . '.webm');
	}else{
		serveRange($basesegpath . '.webm');
	}
	break;
case "audio":
	header('Content-type: audio/webm');
	if(isset($_GET["init"])){
		serveRange($baseinitpath . 'a.webm');
	}else{
		serveRange($basesegpath . 'a.webm');
	}
	break;
}

?>
