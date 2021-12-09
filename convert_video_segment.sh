#!/bin/bash
infile="$1"
ss="$2"
t="$3"
w="$4"
h="$5"
outdir="$6"
basesegname="$7"
baseinitpath="$8"
basesegpath="$outdir/$basesegname"

lockfile="$basesegpath.lock"

(
if flock -xn 200; then
	echo got lock
	if [[ ! -f "$basesegpath".webm ]]; then
		seekflags=""
		if [[ $ss -gt 0 ]]; then
			seekflags="-ss $ss"
		fi

		# for vp9
		curtime=$(date '+%F %H-%M-%S')
		vf="yadif=mode=send_frame:deint=interlaced,scale=$w:$h,drawtext=text=$curtime:fontcolor=white:box=1:boxcolor=black@0.5:boxborderw=5:x=5:y=5"
		codecflags="-c:v libvpx-vp9 -crf 25 -b:v 16M -cpu-used 8 -deadline realtime -row-mt 1 -tile-columns 2 -tile-rows 2 -frame-parallel 1 -aq-mode variance -tune-content film"
		# for av1
		#vf='yadif=mode=send_frame:deint=interlaced,scale='$w':'$h
		#codecflags="-c:v libsvtav1 -qp 20 -preset 12"

		# copyts, vsync and enc_time_base are needed to handle videos with fractional framerate or small errors in timestamps better and avoid dropping or duping frames on segment boundaries.
		timeout -k 9 60 ffmpeg $seekflags -i "$infile" -vf "$vf" -t "$t" -copyts -start_at_zero -vsync passthrough -enc_time_base -1 -an -sn -map_metadata -1 $codecflags -g 1000 -keyint_min 1000 -dash 1 -dash_segment_type webm -init_seg_name "$basesegname"_init.webm -media_seg_name "$basesegname".webm "$basesegpath".mpd
		rm "$basesegpath".mpd
		mv "$basesegpath"_init.webm "$baseinitpath".webm
		# no need to patch the timestamp. copyts generates segments with the correct timestamp.
	fi
	rm "$lockfile"
else
	echo wait for other instance to finish
	n=0
	while [[ -f "$lockfile" ]] && [[ $n -lt 100 ]]; do
		sleep 0.5
		((n=$n+1))
	done
fi

) 200>"$lockfile"
