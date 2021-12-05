<?php
setlocale(LC_ALL, 'en_US.utf-8'); //for php
putenv('LC_ALL=en_US.utf-8'); //for shell_exec

function calaculateResolutions($w, $h, $arX, $arY) {
	$stdRes = array(array(432,240), array(640, 360), array(854, 480), array(1280, 720), array(1920, 1080));
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

if(!isset($_GET['file']) || !file_exists($_GET['file'])){
	header($_SERVER["SERVER_PROTOCOL"]." 404 Not Found");
	return;
}

$videofile = $_GET['file'];
$videoLengthMs = shell_exec('mediainfo --Output="General;%Duration%" ' . escapeshellarg($videofile) . ' 2>&1');
$videoLength = intval($videoLengthMs) / 1000;
$mediainfoOut = shell_exec('mediainfo --Output="Video;%Width%\n%Height%\n%DisplayAspectRatio/String%" ' . escapeshellarg($videofile));
$mediainfoOut =  preg_split('/$\R?^/m', $mediainfoOut);
$videoWidth = intval($mediainfoOut[0]);
$videoHeight = intval($mediainfoOut[1]);
$videoAspectStr = $mediainfoOut[2];
$videoAspectArr = explode(':', $videoAspectStr);
if(!array_key_exists(1,$videoAspectArr)){
	$videoAspectArr[1] = "1";
}

$resolutions = calaculateResolutions($videoWidth, $videoHeight, floatval($videoAspectArr[0]), floatval($videoAspectArr[1]));
$maxRes = $resolutions[count($resolutions)-1];

header('Content-type: application/dash+xml');
if($_SERVER['REQUEST_METHOD'] == 'HEAD'){
	return;
}
echo '<?xml version="1.0"?>' . PHP_EOL;
echo '<MPD xmlns="urn:mpeg:dash:schema:mpd:2011" minBufferTime="PT1S" type="static" mediaPresentationDuration="'.formatTime($videoLength).'" profiles="urn:mpeg:dash:profile:full:2011">' . PHP_EOL;
echo ' <Period id="0" start="PT0.0S">' . PHP_EOL;
// VIDEO
echo '  <AdaptationSet id="0" contentType="video" segmentAlignment="true" bitstreamSwitching="true" maxWidth="' . $maxRes[0] . '" maxHeight="' . $maxRes[1] . '">' . PHP_EOL;
for($id = 0; $id < count($resolutions); $id++){
	$bandwidth = 1000000 << $id; // put some bogus value
	$w = $resolutions[$id][0];
	$h = $resolutions[$id][1];
	echo '   <Representation id="' . $id . '" mimeType="video/webm" codecs="vp09.00.30.08" bandwidth="' . $bandwidth . '" width="' . $w . '" height="' . $h . '">' . PHP_EOL;
	echo '    <SegmentTemplate duration="5" initialization="getsegment.php?file=' . rawurlencode($videofile) . '&amp;type=video&amp;w=' . $w . '&amp;h=' . $h . '&amp;init=1" media="getsegment.php?file=' . rawurlencode($videofile) . '&amp;w=' . $w . '&amp;h=' . $h . '&amp;type=video&amp;n=$Number%05d$" startNumber="0"/>' . PHP_EOL;
	echo '   </Representation>' . PHP_EOL;
}
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
