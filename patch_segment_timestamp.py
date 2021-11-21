#!/usr/bin/python
# usage: patch_segment_timestamps.py infile outfile timestamp

from ebml.container import File
import sys

ebml_file = File(sys.argv[1])
ebml_file.read_all()
ebml_file.child_named('Cluster').child_named('Timecode').value = int(sys.argv[3])
outstream = open(sys.argv[2], 'wb')
ebml_file.force_dirty()
ebml_file.rearrange()
ebml_file._write(outstream)
outstream.flush()
outstream.close()
