# dash_segment_encode
Scripts to
* Generate a DASH MPD manifest for a video file (getmpd.php)
* Generate the webm segments when they are requested (getsegment.php)

The general idea is to encode the webm segments then patch their timestamps to be the value they'd be at if the segments were generated all at once in a normal encoding process.

For audio, the opus codec is a great fit because of its 960 sample frame size and fixed 48000Hz sample rate. This means there are exactly 50 opus frames per second, making the cut time calculations very simple. The audio segments are encoded with 0.5s of lead in and lead out. The lead in and lead out are then cut from the segments with direct stream copy. This ensures that the MDCT frames from different segments overlap correctly. The audio segments join perfectly with no glitches.

For video, VP9 is used as it is much faster than any AV1 encoder. x264 would be ideal because of its speed but it doesn't fit in webm. When SVT-AV1 preset 12 comes out I will probably use it instead.

The -copyts, -vsync passthrough and -enc_time_base -1 options are used to handle fractional framerates. With these options, the input frame times are preserved, and for fractional framerates, the first frame of a segment may begin a few milliseconds after the whole second. This seems to be ok in Firefox and Chromium.

This script heavily depends on ffmpeg being able to seek accurately. For ffmpeg to be able to seek accurately, the source file must be indexed and seekable. If ffmpeg is not able to seek the source file accurately, expect the playback to skip, stall or completely fail.

Formats that are normally indexed and accurately seekable: MKV, MP4, AVI

Formats that are indexed but don't always seek accurately in ffmpeg: WMV

Formats that are not indexed and tend to work poorly: TS, M2TS, VOB, MPG
