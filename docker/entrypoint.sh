#!/bin/sh
set -e

# Config/route/event caches bake in the environment, so they must be built here at
# container start (when the real env vars exist), not at image build time.
php artisan package:discover --ansi
php artisan config:cache
php artisan route:cache
php artisan event:cache

exec "$@"
