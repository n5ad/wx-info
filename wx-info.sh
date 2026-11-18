#!/bin/bash
#
# wx-info.sh
#
# Derived from Supermon2 ast_var_update.sh (renamed/adapted as wx-info.sh)
#
#  (C) WA3DSP 12/15/2020
#  Original concept
#
#  Updated 12/19/2020
#   Name changed to ast_var_update.sh
#   Added Weather, Alerts, and Log file size
#
#  Update 1/2/2021
#   Added Registrations to node info
#
#  Update 1/9/2021
#     Name changed to ast_var_update.sh
#     Added node_info.ini file
#
#  Update 1/16/2021
#     Added check for Hamvoip updates
#
#  Update 3 - 2/6/2021
#     Corrected log size wording
#     added clickable URL to WX Alert
#     Fixed CPU temperature for later kernels
#
# Copyright (c) 2025 W5GLE
# ASL3 port (included in supermon-ng as ast_node_status_update)
#
# Copyright (c) 2026 N5AD
# Adapted/renamed as wx-info.sh for this branch by N5AD, October 2026
#
SCRIPT_DIR="$(cd "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")" && pwd)"
CONFIG_FILE="$SCRIPT_DIR/wx-info.ini"
ASTERISK=/usr/sbin/asterisk

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
                sub(/[[:space:]]+#.*$/, "", v)
                gsub(/^[[:space:]]+|[[:space:]]+$/, "", v)
                print v
                exit
            }
        }' "$1")
    if [ -n "$val" ]; then
        printf '%s' "$val"
    else
        printf '%s' "$4"
    fi
}

# True when value is yes/true/1 (case-insensitive)
is_enabled() {
    case "${1,,}" in
        yes|true|1) return 0 ;;
        *) return 1 ;;
    esac
}

get_last_update() {  # get_last_update FORMAT
    local fmt="${1:-%a %b %d %Y %I:%M %p %Z}"
    printf '"Last Update: %s"' "$(date +"$fmt")"
}

get_weather() {  # get_weather CODE LOCATION
    local raw=""

    if [ -z "$1" ] || [ -z "$2" ]; then
        printf '" "'
        return
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
    local url a_start="" a_end="" content

    if ! is_enabled "$master"; then
        printf '"%s"' "$disabled"
        return
    fi

    if [ -z "$warnings_file" ] || [ ! -e "$warnings_file" ]; then
        if [ -n "$warnings_file" ]; then
            echo "Warning: warnings_file '$warnings_file' not found. Create it as root: touch $warnings_file" >&2
        fi
        printf '" "'
        return
    fi

    url=$(grep '^OFILE=' "$alert_ini" 2>/dev/null | sed 's/OFILE=//; s/&y=0/&y=1/; s/"//g')
    if [ -n "$url" ]; then
        a_start="<a target='WX ALERT' href='${url}' style='color: inherit;'>"
        a_end="</a>"
    fi

    content=$(tr -d '\r' < "$warnings_file" 2>/dev/null \
        | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//' \
        | tr -d '[]')

    if [ -z "$content" ]; then
        printf '"%s<br>%s"' "$enabled" "$no_alerts"
    else
        printf '"%s<br><span style='"'"'color: #FF0000;'"'"'>%s<b>%s</b>%s</span>"' \
            "$enabled" "$a_start" "$content" "$a_end"
    fi
}

# Asterisk -rx uses netconsole inbuf[512] and prefixes "cli quit after " + NUL.
AST_RX_MAX_CMD=$((512 - 15 - 1))

# True when cmd fits in a single Asterisk -rx netconsole write.
rx_cmd_fits() {
    [ "${#1}" -le "$AST_RX_MAX_CMD" ]
}

# Truncate a quoted rpt value so "rpt set variable NODE KEY=<value>" fits -rx.
fit_rx_value() {
    local prefix="$1" value="$2"
    local max=$((AST_RX_MAX_CMD - ${#prefix}))

    if [ "$max" -lt 2 ]; then
        return 1
    fi
    if [ "${#value}" -le "$max" ]; then
        printf '%s' "$value"
        return 0
    fi
    if [ "${value:0:1}" = '"' ]; then
        printf '%s"' "${value:0:$((max - 1))}"
    else
        printf '%s' "${value:0:$max}"
    fi
}

# set_node_var NODE KEY=VALUE LABEL
# Sends one rpt set variable assignment via -rx. Returns 1 on failure / oversize.
set_node_var() {
    local node="$1" assignment="$2" label="$3"
    local cmd="rpt set variable $node $assignment"
    local out key value fitted prefix

    if ! rx_cmd_fits "$cmd"; then
        key="${assignment%%=*}"
        value="${assignment#*=}"
        prefix="rpt set variable $node ${key}="
        if ! fitted=$(fit_rx_value "$prefix" "$value"); then
            echo "Error: $label for node $node exceeds Asterisk -rx ${AST_RX_MAX_CMD}-byte limit" >&2
            return 1
        fi
        if [ "$fitted" = "$value" ]; then
            echo "Error: $label for node $node exceeds Asterisk -rx ${AST_RX_MAX_CMD}-byte limit" >&2
            return 1
        fi
        echo "Warning: truncated $label for node $node to fit Asterisk -rx limit" >&2
        cmd="rpt set variable $node ${key}=${fitted}"
        if ! rx_cmd_fits "$cmd"; then
            echo "Error: $label for node $node still exceeds Asterisk -rx limit after truncate" >&2
            return 1
        fi
    fi

    if out=$("$ASTERISK" -rx "$cmd" 2>&1); then
        echo "Updated $label Node $node using rpt set variable"
        return 0
    fi
    echo "Error setting $label for node $node: $out"
    return 1
}

update_node_variables() {  # node wx alert time
    local node="$1" wx="$2" alert="$3" time_val="$4"
    local status=0

    if ! grep -q "[[:blank:]]*\[$node\]" /etc/asterisk/rpt.conf 2>/dev/null; then
        echo "Invalid Node $node this server"
        return 1
    fi

    # One assignment per -rx call so each stays under the 512-byte netconsole limit.
    set_node_var "$node" "WX=$wx" "WX" || status=1
    set_node_var "$node" "LASTUPDATE=$time_val" "LASTUPDATE" || status=1
    set_node_var "$node" "ALERT=$alert" "ALERT" || status=1
    return "$status"
}

if [ ! -f "$CONFIG_FILE" ]; then
    echo "Error: Configuration file '$CONFIG_FILE' not found."
    exit 1
fi

NODES=$(ini_get "$CONFIG_FILE" general NODE "")
WX_CODE=$(ini_get "$CONFIG_FILE" general WX_CODE "")
WX_LOCATION=$(ini_get "$CONFIG_FILE" general WX_LOCATION "")
TIME_FORMAT=$(ini_get "$CONFIG_FILE" general TIME_FORMAT "%a %b %d %Y %I:%M %p %Z")
MASTER_ENABLE=$(ini_get "$CONFIG_FILE" autosky MASTER_ENABLE "no")
ALERT_INI=$(ini_get "$CONFIG_FILE" autosky ALERT_INI "/usr/local/bin/AUTOSKY/AutoSky.ini")
WARNINGS_FILE=$(ini_get "$CONFIG_FILE" autosky WARNINGS_FILE "/var/www/html/AUTOSKY/warnings.txt")

WX=$(get_weather "$WX_CODE" "$WX_LOCATION")
TIME_VAL=$(get_last_update "$TIME_FORMAT")
ALERT=$(get_autosky_alerts "$ALERT_INI" "$WARNINGS_FILE" "$MASTER_ENABLE")

if [ -z "$NODES" ]; then
    echo "No nodes specified in the configuration file."
    exit 0
fi

status=0
for node in $NODES; do
    update_node_variables "$node" "$WX" "$ALERT" "$TIME_VAL" || status=1
done

exit "$status"
