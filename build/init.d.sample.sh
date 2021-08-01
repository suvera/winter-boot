#!/bin/bash

### BEGIN INIT INFO
# Provides:          <<serviceName>>
# Required-Start:    $local_fs $network $named $time $syslog
# Required-Stop:     $local_fs $network $named $time $syslog
# Default-Start:     2 3 4 5
# Default-Stop:      0 1 6
# chkconfig:         2345 86 36
# Description:       <<serviceName>>
### END INIT INFO


# To avoid systemd completely
SYSTEMCTL_SKIP_REDIRECT=1

USER=<<username>>
SERVICE_NAME=<<serviceName>>
SERVICE="<<appBinary>>"
CONFIG_DIR="<<configDir>>"
ADMIN_PORT="<<adminPort>>"
ADMIN_TOKEN_FILE="<<adminTokenFile>>"
LOG_FILE="<<logFile>>"
PID_FILE="<<pidFile>>"
PHP_BINARY="<<phpBinary>>"
CMD_OPTIONS=" $SERVICE -c $CONFIG_DIR "
WAIT_COUNTDOWN=30

if [ -f "/etc/init.d/functions" ]; then
    . /etc/init.d/functions
fi

if [ -x "/sbin/runuser" ]; then
    SU="/sbin/runuser"
else
    SU="/bin/su"
fi

NORMAL=$(tput sgr0)
GREEN=$(tput setaf 2; tput bold)
YELLOW=$(tput setaf 3)
RED=$(tput setaf 1)

PID=-1
RET_VAL=0

function red() {
    echo -e "$RED$*$NORMAL"
}

function green() {
    echo -e "$GREEN$*$NORMAL"
}

function yellow() {
    echo -e "$YELLOW$*$NORMAL"
}

function assurePhp() {
    which $PHP_BINARY &>/dev/null

    if [ $? -ne 0 ]; then
        red "PHP was not found at $PHP_BINARY.";
        exit 1
    fi
}

function touchFile {
    local filename="$1"
    touch $filename || return 1
    chown $USER. $filename || return 1
    chmod g+w $filename || return 1
    return 0;
}


# See if PID exists and running
function getRunningPID {
    if [ ! -f $PID_FILE ]; then
        return 1;
    fi
    local pid="$(<$PID_FILE)"

    if [ ! `kill -0 $pid > /dev/null 2>&1 && echo 1` ]; then
        return 1;
    fi

    if [ "$(ps -p $pid --no-headers -o comm)" != "php" ]; then
        return 1;
    fi

    grep -q --binary -F "$SERVICE" /proc/$pid/cmdline
    if [ $? -ne 0 ]; then
        return 1;
    fi

    PID=$pid
    return 0;
}

function beginService {
    rm -f $PID_FILE

    touchFile $PID_FILE || return 1
    touchFile $LOG_FILE || return 1

    cmd="nohup $PHP_BINARY $CMD_OPTIONS >>$LOG_FILE 2>&1 & echo \$! >$PID_FILE"
    $SU -s /bin/bash -m $USER -c "$cmd" || return 1

    sleep 2
    local pid="$(<$PID_FILE)"

    if [ ! `kill -0 $pid > /dev/null 2>&1 && echo 1` ]; then
        return 1
    fi

    PID=$pid
    return 0;
}

start() {
    assurePhp
    getRunningPID
    if [ $PID -ne -1 ]; then
        yellow "$SERVICE_NAME is already running with PID: $PID";
        RET_VAL=0;
        return 0;
    fi

    TIMESTAMP=$(date "+%Y-%m-%d %H:%M:%S")
    echo "[$TIMESTAMP] Starting $SERVICE_NAME" | tee -a $LOG_FILE

    beginService
    if [ $? -ne 0 ]; then
        RET_VAL=1;
        red "Service start failed. See log $LOG_FILE";
        return 1;
    fi

    TIMESTAMP=$(date "+%Y-%m-%d %H:%M:%S")
    green "[$TIMESTAMP] Started PID=$PID" | tee -a $LOG_FILE
    echo "[$TIMESTAMP] Monitoring for $WAIT_COUNTDOWN seconds " | tee -a $LOG_FILE

    counter=1
    while [ `kill -0 $PID > /dev/null 2>&1 && echo 1` ]
    do
        if [ $counter -eq $WAIT_COUNTDOWN ]; then
            break
        fi
        sleep 1
        echo -e ".\c"

        (( counter++ ))
    done

    TIMESTAMP=$(date "+%Y-%m-%d %H:%M:%S")
    echo
    if [ $counter -eq $WAIT_COUNTDOWN ]; then
        green "[$TIMESTAMP] '${SERVICE_NAME}' started!" | tee -a $LOG_FILE
    else
        tail "$LOG_FILE"
        echo
        red "[$TIMESTAMP] ERROR: '${SERVICE_NAME}' failed to start, check error log $LOG_FILE" | tee -a $LOG_FILE
        RET_VAL=1
        return 1;
    fi

    RET_VAL=0
    return 0;
}

stop() {
    if [ -f "${PID_FILE}" ]; then

        TIMESTAMP=$(date "+%Y-%m-%d %H:%M:%S")
        echo $"[$TIMESTAMP] Shutting down $SERVICE_NAME: " | tee -a $LOG_FILE
        local pid="$(<$PID_FILE)"

        token=""
        if [ ! -z "$ADMIN_TOKEN_FILE" ]; then
            token="$(<$ADMIN_TOKEN_FILE)"
        fi

        if [ ! -z "$ADMIN_PORT" ]; then
            curl "http://localhost:$ADMIN_PORT/" -d "action=shutdown&token=$token"
        else
            killall -9 php
        fi

        success=1
        sleep 2

        if [ `kill -0 $pid > /dev/null 2>&1 && echo 1` ]; then
            counter=1
            echo ""
            yellow "[$TIMESTAMP] waiting up to ${WAIT_COUNTDOWN} seconds for process $pid to stop" | tee -a $LOG_FILE
            while [ `kill -0 $pid > /dev/null 2>&1 && echo 1` ]
            do
                if [ $counter -eq $WAIT_COUNTDOWN ]; then
                    success=0
                    break
                fi
                sleep 1
                echo "${counter}..."

                (( counter++ ))
            done
        fi

        if [ "$success" -eq "1" ]; then
            green "[$TIMESTAMP] ${SERVICE_NAME} shutdown cleanly" | tee -a $LOG_FILE
        else
            red "[$TIMESTAMP] ERROR. ${SERVICE_NAME} with PID ${pid} could not be killed!" | tee -a $LOG_FILE
            return 1
        fi

        if [ -e $PID_FILE ]; then
            rm -f $PID_FILE
        fi
  fi
  return 0
}

function help() {
    cat << EOF
Usage: $0 <command>

Options:
    start    - Start service $SERVICE_NAME
    stop     - Stop service $SERVICE_NAME
    restart  - Stop, then Start service $SERVICE_NAME
    status   - Check if service $SERVICE_NAME is running
EOF
    exit 2
}

function status() {
    echo -n "$SERVICE_NAME:   "
    if getRunningPID; then
        echo " Service Running PID=$PID"
        RET_VAL=0
    else
        echo "Service Stopped"
        RET_VAL=3
    fi
    return $RET_VAL;
}

case "$1" in
  start)
        start
        ;;
  stop)
        stop
        ;;
  status)
        status
        ;;
  restart)
        stop
        start
        ;;
   *)
        help
        ;;
esac

exit $RET_VAL