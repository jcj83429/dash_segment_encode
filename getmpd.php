<?php
setlocale(LC_ALL, 'en_US.utf-8'); //for php
putenv('LC_ALL=en_US.utf-8'); //for shell_exec

function calaculateResolutions($w, $h, $arX, $arY) {
	$stdRes = array(array(216,120), array(432,240), array(640, 360), array(854, 480), array(1280, 720), array(1920, 1080));
	$outRes = array();
	// first adjust the w and h to make them square pixels
	// Tolerate up to 3% AR error
	if(abs(($w*$arY)/($h*$arX) - 1) > 0.03){
		if($w/$h > $arX/$arY){
			$h = $w * $arY / $arX;
		}else if($w/$h < $arX/$arY){
			$w = $h * $arX / $arY;
		}
	}
	foreach($stdRes as $res){
		$outW = $w;
		$outH = $h;
		$lastRes = false;
		if($w > $res[0] || $h > $res[1]){
			if($arX/$arY > $res[0]/$res[1]){
				$outW = $res[0];
				$outH = $res[0] * $arY / $arX;
			}else{
				$outW = $res[1] * $arX / $arY;
				$outH = $res[1];
			}
		}else{
			$lastRes = true;
		}
		$outW = $outW & ~1;
		$outH = $outH & ~1;
		$outRes[] = array($outW, $outH);
		if($lastRes){
			break;
		}
	}
	return $outRes;
}

function formatTime($timeSec){
	return 'PT'.floor($timeSec / 3600).'H'.(floor($timeSec/60)%60).'M'.($timeSec%60).'S';
}

if(!isset($_GET['file'])){
	header($_SERVER["SERVER_PROTOCOL"]." 404 Not Found");
	return;
}
$videofile=$_SERVER['DOCUMENT_ROOT'] . parse_url($_GET['file'])["path"];
if(preg_match("/[\\/]\.\.[\\/]/", $videofile) || !is_file($videofile)){
	header($_SERVER["SERVER_PROTOCOL"]." 404 Not Found");
	return;
}

$ffprobeOutput = shell_exec('ffprobe -print_format json -show_format -show_streams ' . escapeshellarg($videofile));
$ffprobeOutput = json_decode($ffprobeOutput, true);

$videoLength = $ffprobeOutput["format"]["duration"];

$hasVideo = false;
$hasAudio = false;
foreach($ffprobeOutput["streams"] as $stream){
	if($stream["codec_type"] == "video" && !$hasVideo){
		$hasVideo = true;
		$videoWidth = intval($stream["width"]);
		$videoHeight = intval($stream["height"]);
		if(array_key_exists("display_aspect_ratio", $stream)){
			$videoAspectStr = $stream["display_aspect_ratio"];
			$videoAspectArr = explode(':', $videoAspectStr);
			if(!array_key_exists(1,$videoAspectArr)){
				$videoAspectArr[1] = "1";
			}
		}else{
			$videoAspectArr = array($videoWidth, $videoHeight);
		}
		$resolutions = calaculateResolutions($videoWidth, $videoHeight, floatval($videoAspectArr[0]), floatval($videoAspectArr[1]));
		$maxRes = $resolutions[count($resolutions)-1];
	}else if($stream["codec_type"] == "audio" && !$hasAudio){
		$hasAudio = true;
	}
}

header('Content-type: application/dash+xml');
if($_SERVER['REQUEST_METHOD'] == 'HEAD'){
	return;
}
echo '<?xml version="1.0"?>' . PHP_EOL;
echo '<MPD xmlns="urn:mpeg:dash:schema:mpd:2011" minBufferTime="PT1S" type="static" mediaPresentationDuration="'.formatTime($videoLength).'" profiles="urn:mpeg:dash:profile:full:2011">' . PHP_EOL;
echo ' <Period id="0" start="PT0.0S">' . PHP_EOL;

// VIDEO
if($hasVideo){
	echo '  <AdaptationSet id="0" contentType="video" segmentAlignment="true" bitstreamSwitching="true" maxWidth="' . $maxRes[0] . '" maxHeight="' . $maxRes[1] . '">' . PHP_EOL;
	for($id = 0; $id < count($resolutions); $id++){
		$bandwidth = 500000 << $id; // put some bogus value
		$w = $resolutions[$id][0];
		$h = $resolutions[$id][1];
		echo '   <Representation id="' . $id . '" mimeType="video/webm" codecs="vp09.00.30.08" bandwidth="' . $bandwidth . '" width="' . $w . '" height="' . $h . '">' . PHP_EOL;
		echo '    <SegmentTemplate duration="5" initialization="getsegment.php?file=' . rawurlencode($_GET['file']) . '&amp;type=video&amp;w=' . $w . '&amp;h=' . $h . '&amp;init=1" media="getsegment.php?file=' . rawurlencode($_GET['file']) . '&amp;w=' . $w . '&amp;h=' . $h . '&amp;type=video&amp;n=$Number%05d$" startNumber="0"/>' . PHP_EOL;
		echo '   </Representation>' . PHP_EOL;
	}
	echo '  </AdaptationSet>' . PHP_EOL;
}

// AUDIO
if($hasAudio){
	$audioBitrates = array(128000, 64000, 32000);
	for($id = 0; $id < count($audioBitrates); $id++){
		$br = $audioBitrates[$id];
		// id 0 is used by video so audio starts at id 1
		echo '  <AdaptationSet id="' . (1 + $id) . '" contentType="audio" segmentAlignment="true" bitstreamSwitching="true" lang="stereo' . $br . '">' . PHP_EOL;
		echo '   <Representation id="0" mimeType="audio/webm" codecs="opus" bandwidth="' . $br . '" audioSamplingRate="48000">' . PHP_EOL;
		echo '    <AudioChannelConfiguration schemeIdUri="urn:mpeg:dash:23003:3:audio_channel_configuration:2011" value="2" />' . PHP_EOL;
		echo '    <SegmentTemplate duration="5" initialization="getsegment.php?file=' . rawurlencode($_GET['file']) . '&amp;type=audio&amp;br=' . $br . '&amp;init=1" media="getsegment.php?file=' . rawurlencode($_GET['file']) . '&amp;type=audio&amp;br=' . $br . '&amp;n=$Number%05d$" startNumber="0"/>' . PHP_EOL;
		echo '   </Representation>' . PHP_EOL;
		echo '  </AdaptationSet>' . PHP_EOL;
	}
}

echo ' </Period>' . PHP_EOL;
echo '</MPD>' . PHP_EOL;
?>
