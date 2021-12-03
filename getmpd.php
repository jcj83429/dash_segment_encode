<?php
setlocale(LC_ALL, 'en_US.utf-8'); //for php
putenv('LC_ALL=en_US.utf-8'); //for shell_exec

const MAX_WIDTH = 1280;
const MAX_HEIGHT = 720;

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

function formatTime($timeSec){
	return 'PT'.floor($timeSec / 3600).'H'.(floor($timeSec/60)%60).'M'.($timeSec%60).'S';
}

if(!isset($_GET['file']) || !file_exists($_GET['file'])){
	header($_SERVER["SERVER_PROTOCOL"]." 404 Not Found");
	return;
}

$videofile = $_GET['file'];
$videoLengthMs = shell_exec('mediainfo --Output="General;%Duration%" ' . escapeshellarg($videofile) . ' 2>&1');
$videoLength = intval($videoLengthMs) / 1000;
$videoAspectStr = shell_exec('mediainfo --Output="Video;%DisplayAspectRatio/String%" ' . escapeshellarg($videofile));
$videoAspectArr = explode(':', $videoAspectStr);
if(!array_key_exists(1,$videoAspectArr)){
	$videoAspectArr[1] = "1";
}
$newResolution = targetRes(intval($videoAspectArr[0]), intval($videoAspectArr[1]));
$w = $newResolution[0];
$h = $newResolution[1];

header('Content-type: application/dash+xml');
if($_SERVER['REQUEST_METHOD'] == 'HEAD'){
	return;
}
echo '<?xml version="1.0"?>' . PHP_EOL;
echo '<MPD xmlns="urn:mpeg:dash:schema:mpd:2011" minBufferTime="PT1S" type="static" mediaPresentationDuration="'.formatTime($videoLength).'" profiles="urn:mpeg:dash:profile:full:2011">' . PHP_EOL;
echo ' <Period id="0" start="PT0.0S">' . PHP_EOL;
// VIDEO
echo '  <AdaptationSet id="0" contentType="video" segmentAlignment="true" bitstreamSwitching="true" maxWidth="' . $w . '" maxHeight="' . $h . '">' . PHP_EOL;
echo '   <Representation id="0" mimeType="video/webm" codecs="vp09.00.30.08" bandwidth="1000000" width="' . $w . '" height="' . $h . '">' . PHP_EOL;
echo '    <SegmentTemplate duration="5" initialization="getsegment.php?file=' . rawurlencode($videofile) . '&amp;type=video&amp;w=' . $w . '&amp;h=' . $h . '&amp;init=1" media="getsegment.php?file=' . rawurlencode($videofile) . '&amp;w=' . $w . '&amp;h=' . $h . '&amp;type=video&amp;n=$Number%05d$" startNumber="0"/>' . PHP_EOL;
echo '   </Representation>' . PHP_EOL;
echo '  </AdaptationSet>' . PHP_EOL;
// AUDIO
echo '  <AdaptationSet id="1" contentType="audio" segmentAlignment="true" bitstreamSwitching="true">' . PHP_EOL;
echo '   <Representation id="1" mimeType="audio/webm" codecs="opus" bandwidth="100000" audioSamplingRate="48000">' . PHP_EOL;
echo '    <AudioChannelConfiguration schemeIdUri="urn:mpeg:dash:23003:3:audio_channel_configuration:2011" value="2" />' . PHP_EOL;
echo '    <SegmentTemplate duration="5" initialization="getsegment.php?file=' . rawurlencode($videofile) . '&amp;type=audio&amp;init=1" media="getsegment.php?file=' . rawurlencode($videofile) . '&amp;type=audio&amp;n=$Number%05d$" startNumber="0"/>' . PHP_EOL;
echo '   </Representation>' . PHP_EOL;
echo '  </AdaptationSet>' . PHP_EOL;

echo ' </Period>' . PHP_EOL;
echo '</MPD>' . PHP_EOL;
?>
