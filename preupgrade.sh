#!/bin/bash
ARGV1=$1; ARGV3=$3; ARGV5=$5
PFOLDER="${ARGV3:-mgismart}"; BASE="${ARGV5:-$LBHOMEDIR}"
mkdir -p "$ARGV1" 2>/dev/null
cp -p "$BASE/config/plugins/$PFOLDER/mgismart.json" "$ARGV1/mgismart.json" 2>/dev/null
cp -p "$BASE/log/plugins/$PFOLDER/mgismart.log" "$ARGV1/mgismart.log" 2>/dev/null
exit 0
