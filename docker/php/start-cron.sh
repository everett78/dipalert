#!/bin/sh
set -e

# Start cron
/usr/bin/crontab /etc/cron.d/schedule-cron
/usr/sbin/cron -f -L 1
