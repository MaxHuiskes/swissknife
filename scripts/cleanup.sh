#!/usr/bin/env bash
set -euo pipefail

# Remove any files in storage directories older than 30 minutes
find /var/www/swissknife/storage/uploads/ -type f -mmin +30 -delete
find /var/www/swissknife/storage/outputs/ -type f -mmin +30 -delete