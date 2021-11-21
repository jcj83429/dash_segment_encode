# dash_segment_encode
Scripts to
* Generate a DASH MPD manifest for a video file (getmpd.php)
* Generate the webm segments when they are requested (getsegment.php)

The general idea is to encode the webm segments then patch their timestamps to be the value they'd be at if the segments were generated all at once in a normal encoding process.

For audio, the opus codec is a great fit because of its 960 sample frame size and fixed 48000Hz sample rate. This means there are exactly 50 opus frames per second, making the cut time calculations very simple. The audio segments are encoded with 0.5s of lead in and lead out. The lead in and lead out are then cut from the segments with direct stream copy. This ensures that the MDCT frames from different segments overlap correctly. The audio segments join perfectly with no glitches.

For video, VP9 is used as it is much faster than AV1. x264 would be ideal because of its speed but it doesn't fit in webm. The segments are currently hardcoded to 5s. This doesn't work well with fractional frame rates like 23.976 (24000/1001) so there can be frame duplication or frame drops at segment boundaries. For these frame rates, using a segment length of 5.005 would probably fix the problem.