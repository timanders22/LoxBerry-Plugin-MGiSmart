#!/bin/bash
ARGV1=$1; ARGV3=$3; ARGV5=$5
PFOLDER="${ARGV3:-mgismart}"; BASE="${ARGV5:-$LBHOMEDIR}"
mkdir -p "$BASE/config/plugins/$PFOLDER" "$BASE/log/plugins/$PFOLDER" "$BASE/data/plugins/$PFOLDER" 2>/dev/null
[ -f "$ARGV1/mgismart.json" ] && cp -p "$ARGV1/mgismart.json" "$BASE/config/plugins/$PFOLDER/mgismart.json"
[ -f "$ARGV1/mgismart.log" ] && cp -p "$ARGV1/mgismart.log" "$BASE/log/plugins/$PFOLDER/mgismart.log"
BK="$BASE/config/plugins/$PFOLDER.backup.json"; CF="$BASE/config/plugins/$PFOLDER/mgismart.json"
if [ -f "$BK" ]; then
    if [ ! -s "$CF" ] || [ "$(cat "$CF" 2>/dev/null)" = "{}" ]; then cp -p "$BK" "$CF"; fi
fi
exit 0
