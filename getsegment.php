<?php
setlocale(LC_ALL, 'en_US.utf-8'); //for php
putenv('LC_ALL=en_US.utf-8'); //for shell_exec

const SEGMENT_CACHE = '/tmp/dash_segments';
const BUFFER_SIZE = 2097152;

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

if(!isset($_GET['file'])){
	header($_SERVER["SERVER_PROTOCOL"]." 404 Not Found");
	return;
}
$urlParts = parse_url($_GET['file']);
$videofile = $_SERVER['DOCUMENT_ROOT'] . $urlParts["path"];
if(array_key_exists("query", $urlParts)){
	$videofile = $videofile . '?' . $urlParts["query"];
}
if(array_key_exists("fragment", $urlParts)){
	$videofile = $videofile . '#' . $urlParts["fragment"];
}
if(preg_match("/[\\/]\.\.[\\/]/", $videofile) || !is_file($videofile)){
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

if(($_GET["type"]) == "video"){
	if(!isset($_GET["w"]) || !is_numeric($_GET["w"]) || $_GET["w"] < 16 ||
	   !isset($_GET["h"]) || !is_numeric($_GET["h"]) || $_GET["h"] < 16){
		header($_SERVER["SERVER_PROTOCOL"]." 404 Not Found");
		return;
	}
}else{
	if(!isset($_GET["br"]) || !is_numeric($_GET["br"]) || $_GET["br"] < 10000 || $_GET["br"] > 500000){
		header($_SERVER["SERVER_PROTOCOL"]." 404 Not Found");
		return;
	}
}

if (!file_exists(SEGMENT_CACHE)) {
    mkdir(SEGMENT_CACHE, 0777, true);
}

$start = intval($_GET["n"]) * 5;
$basename = md5($videofile) . '_' . $_GET["type"];
if($_GET["type"] == "video"){
	$basename = $basename . "_" . $_GET["w"] . 'x' . $_GET["h"];
}else{
	$basename = $basename . "_" . $_GET["br"];
}
$basesegname = $basename . '_ss' . $start;
$basesegpath = SEGMENT_CACHE . '/' . $basesegname;
$baseinitpath = SEGMENT_CACHE . '/' . $basename . '_init';

if($_GET["type"] == "video") {
	// ***** VIDEO *****
	// encode this segment if needed
	shell_exec('./convert_video_segment.sh ' . escapeshellarg($videofile) . ' ' . $start . ' ' . ($start + 5) . ' ' . $_GET["w"] . ' ' . $_GET["h"] . ' ' . escapeshellarg(SEGMENT_CACHE) . ' ' . escapeshellarg($basesegname) . ' ' . escapeshellarg($baseinitpath) . ' 2>&1');
	// start preparing the next seegment in the background while we serve the first segment
	shell_exec('./convert_video_segment.sh ' . escapeshellarg($videofile) . ' ' . ($start + 5) . ' ' . ($start + 10) . ' ' . $_GET["w"] . ' ' . $_GET["h"] . ' ' . escapeshellarg(SEGMENT_CACHE) . ' ' . escapeshellarg($basename . '_ss' . ($start + 5)) . ' ' . escapeshellarg($baseinitpath) . ' >/dev/null 2>/dev/null &');
}else{
	// ***** AUDIO *****
	$lockfile = $basesegpath . '.lock';
	$lockfilefd = fopen($lockfile, 'a');
	$locked = flock($lockfilefd, LOCK_EX);
	if($locked){
		if(!file_exists($basesegpath . '.webm')){
			$audioEncStart = $start ? $start - 0.5 : 0;
			$audioEncLen = $start ? 6 : 5.5;
			$audioCutStart = $start ? 0.5 : 0;
			shell_exec('ffmpeg -ss ' . $audioEncStart . ' -i ' . escapeshellarg($videofile) . ' -map 0:a:0 -t ' . $audioEncLen . ' -b:a ' . $_GET["br"] . ' -ac 2 ' . escapeshellarg($basesegpath . '.opus') . ' 2>/dev/null');

			// I don't know why but when I pass -t 5 the file comes out one frame (20ms) short.
			shell_exec('ffmpeg -i ' . escapeshellarg($basesegpath . '.opus') . ' -ss ' . $audioCutStart . ' -t 5.02 -c copy -output_ts_offset ' . $start . ' -dash 1 -seg_duration 10 -frag_duration 10 -dash_segment_type webm -init_seg_name ' . escapeshellarg($basesegname . '_init.webm') . ' -media_seg_name ' . escapeshellarg($basesegname . '.webm') . ' ' . escapeshellarg($basesegpath . '.mpd') . ' 2>/dev/null');
			unlink($basesegpath . '.opus');

			// delete the ffmpeg-generated MPD
			unlink($basesegpath . '.mpd');

			// The init segment doesn't contain any timestamp or duration so it doesn't matter if we always overwrite it
			rename($basesegpath . '_init.webm', $baseinitpath . '.webm');

		}
		flock($lockfilefd, LOCK_UN);
		fclose($lockfilefd);
		unlink($lockfile);
	}else{
		while(file_exists($lockfile)){
			sleep(0.5);
		}
	}
}

header('Accept-Ranges: bytes');
header('Expires: '.gmdate('D, d M Y H:i:s \G\M\T', time() + 60)); //cache for 1 minutes
switch($_GET["type"]){
case "video":
	header('Content-type: video/webm');
	break;
case "audio":
	header('Content-type: audio/webm');
	break;
}
if(isset($_GET["init"])){
	serveRange($baseinitpath . '.webm');
}else{
	serveRange($basesegpath . '.webm');
}

?>
