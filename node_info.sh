#!/bin/bash
#
# ast_node_status_update.sh
#
# Updates AllstarLink node variables on the local server using the
# `rpt set variable` Asterisk command: uptime, CPU load, CPU temperature,
# weather, log disk usage, and AutoSky/SkywarnPlus alerts.
#
# Reads node_info.ini from the same directory as this script.
#
# Usage:
#   chmod +x ast_node_status_update.sh
#   ./ast_node_status_update.sh
#
# node_info.ini example:
#   [general]
#   NODE = node_number(s)
#   WX_CODE = zip/airport
#   WX_LOCATION = City, State
#   TEMP_UNIT = F
#
#   [autosky]
#   MASTER_ENABLE = yes
#   ALERT_INI = /usr/local/bin/AUTOSKY/AutoSky.ini
#   WARNINGS_FILE = /var/www/html/AUTOSKY/warnings.txt
SCRIPT_DIR="$(cd "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")" && pwd)"
CONFIG_FILE="$SCRIPT_DIR/node_info.ini"
ASTERISK=/usr/sbin/asterisk
# ---------------------------------------------------------------- ini reader
# ini_get FILE SECTION KEY DEFAULT
ini_get() {
    local val
    val=$(awk -v sec="$2" -v key="$3" '
        /^[[:space:]]*\[/ {
            line = $0
            gsub(/^[[:space:]]*\[|\][[:space:]]*$/, "", line)
            cur = line
            next
        }
        cur == sec && index($0, "=") {
            k = substr($0, 1, index($0, "=") - 1)
            gsub(/^[[:space:]]+|[[:space:]]+$/, "", k)
            if (tolower(k) == tolower(key)) {
                v = substr($0, index($0, "=") + 1)
                gsub(/^[[:space:]]+|[[:space:]]+$/, "", v)
                print v
                exit
            }
        }' "$1")
    if [ -n "$val" ]; then printf '%s' "$val"; else printf '%s' "$4"; fi
}
# ------------------------------------------------------------------ getters

get_last_update() {  # get_last_update FORMAT
    local fmt="${1:-%a %b %d %Y %I:%M %p %Z}"
    printf '"Last Update: %s"' "$(date +"$fmt")"
}

temp_span() {  # temp_span BGCOLOR TEXT
    printf "\"<span style='background-color:%s;'><b><span style='color: black; font-weight: bold;'>%s</span></b></span>\"" "$1" "$2"
}

get_weather() {  # get_weather CODE LOCATION
    local raw
    if [ -z "$1" ] || [ -z "$2" ]; then
        printf '" "'; return
    fi
    if [ -x /usr/local/sbin/supermon/weather.pl ]; then
        raw=$(/usr/local/sbin/supermon/weather.pl "$1" v 2>/dev/null)
    elif [ -x /usr/local/sbin/supermon/weather.sh ]; then
        raw=$(/usr/local/sbin/supermon/weather.sh "$1" v 2>/dev/null)
    fi
    if [ -n "$raw" ]; then
        printf '"<b>%s &nbsp; (%s)</b>"' "$2" "$raw"
    else
        printf '" "'
    fi
}

get_autosky_alerts() {  # get_autosky_alerts ALERT_INI WARNINGS_FILE MASTER_ENABLE
    local alert_ini="$1" warnings_file="$2" master="$3"
    local link="<a href='https://github.com/mason10198/SkywarnPlus' style='color: inherit; text-decoration: none;'>SkywarnPlus</a>"
    local enabled="<span style='color: green;'><b><u>${link} Enabled</u></b></span>"
    local disabled="<span style='color: darkorange;'><b><u>${link} Disabled</u></b></span>"
    local no_alerts="<span style='color: #FF0000;'>No Alerts</span>"
    if [ "${master,,}" != "yes" ]; then
        printf '"%s"' "$disabled"; return
    fi
    if [ -n "$warnings_file" ] && [ ! -e "$warnings_file" ]; then
        echo "Warning: warnings_file '$warnings_file' not found. Create it as root: touch $warnings_file" >&2
        printf '" "'; return
    fi
    if [ ! -e "$warnings_file" ]; then
        printf '" "'; return
    fi
    local url a_start="" a_end="" content
    url=$(grep '^OFILE=' "$alert_ini" 2>/dev/null | sed 's/OFILE=//; s/&y=0/&y=1/; s/"//g')
    if [ -n "$url" ]; then
        a_start="<a target='WX ALERT' href='${url}' style='color: inherit;'>"
        a_end="</a>"
    fi
    content=$(tr -d '\r' < "$warnings_file" 2>/dev/null | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//')
    content=$(printf '%s' "$content" | tr -d '[]')
    if [ -z "$content" ]; then
        printf '"%s<br>%s"' "$enabled" "$no_alerts"
    else
        printf '"%s<br><span style='"'"'color: #FF0000;'"'"'>%s<b>%s</b>%s</span>"' \
            "$enabled" "$a_start" "$content" "$a_end"
    fi
}
# ------------------------------------------------------------------ updater
update_node_variables() {  # node up load temp wx logs alert time
    local node="$1" cpu_up="$2" cpu_load="$3" cpu_temp="$4" wx="$5" logs="$6" alert="$7" time_val="$8"
    local out
    if grep -q "[[:blank:]]*\[$node\]" /etc/asterisk/rpt.conf 2>/dev/null; then
        if out=$("$ASTERISK" -rx "rpt set variable $node  WX=$wx LASTUPDATE=$time_val" 2>&1); then
            echo "Updated Variables Node $node using rpt set variable"
        else
            echo "Error setting variables for node $node: $out"
        fi
        if out=$("$ASTERISK" -rx "rpt set variable $node ALERT=$alert" 2>&1); then
            echo "Updated ALERT Node $node using rpt set variable"
        else
            echo "Error setting ALERT for node $node: $out"
        fi
    else
        echo "Invalid Node $node this server"
    fi
}
# --------------------------------------------------------------------- main
if [ ! -f "$CONFIG_FILE" ]; then
    echo "Error: Configuration file '$CONFIG_FILE' not found."
    exit 1
fi
NODES=$(ini_get "$CONFIG_FILE" general NODE "")
WX_CODE=$(ini_get "$CONFIG_FILE" general WX_CODE "")
WX_LOCATION=$(ini_get "$CONFIG_FILE" general WX_LOCATION "")
TEMP_UNIT=$(ini_get "$CONFIG_FILE" general TEMP_UNIT "F")
TIME_FORMAT=$(ini_get "$CONFIG_FILE" general TIME_FORMAT "%a %b %d %Y %I:%M %p %Z")
MASTER_ENABLE=$(ini_get "$CONFIG_FILE" autosky MASTER_ENABLE "no")
ALERT_INI=$(ini_get "$CONFIG_FILE" autosky ALERT_INI "/usr/local/bin/AUTOSKY/AutoSky.ini")
WARNINGS_FILE=$(ini_get "$CONFIG_FILE" autosky WARNINGS_FILE "/var/www/html/AUTOSKY/warnings.txt")

WX=$(get_weather "$WX_CODE" "$WX_LOCATION")

TIME_VAL=$(get_last_update "$TIME_FORMAT")
ALERT=$(get_autosky_alerts "$ALERT_INI" "$WARNINGS_FILE" "$MASTER_ENABLE")
if [ -n "$NODES" ]; then
    for node in $NODES; do
        update_node_variables "$node" "$WX" "$ALERT" "$TIME_VAL"
    done
else
    echo "No nodes specified in the configuration file."
fi
exit 0