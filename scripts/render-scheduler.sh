#!/usr/bin/env sh
set -e

while true; do
  php artisan schedule:run
  sleep 1
done
