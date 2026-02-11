#!/bin/bash
echo "========================================="
echo "  Laravel Startup Script"
echo "========================================="
echo "PORT: ${PORT:-8000}"
echo "APP_ENV: ${APP_ENV:-not set}"
echo "DB_HOST: ${DB_HOST:-not set}"
echo "DB_DATABASE: ${DB_DATABASE:-not set}"
echo "========================================="

# Clear any stale caches from build phase
echo "[1/6] Clearing stale caches..."
php artisan config:clear 2>/dev/null || true
php artisan route:clear 2>/dev/null || true
php artisan cache:clear 2>/dev/null || true

# Cache config with runtime env vars
echo "[2/6] Caching config..."
php artisan config:cache || echo "WARNING: config:cache failed, using uncached config"

# Cache routes
echo "[3/6] Caching routes..."
php artisan route:cache || echo "WARNING: route:cache failed, using uncached routes"

# Cache views
echo "[4/6] Caching views..."
php artisan view:cache || echo "WARNING: view:cache failed"

# Run migrations
echo "[5/6] Running migrations..."
php artisan migrate --force || echo "WARNING: Migration failed - check database connection"

# Start server - MUST use $PORT from Railway
echo "[6/6] Starting server on port ${PORT:-8000}..."
echo "========================================="
exec php artisan serve --host=0.0.0.0 --port=${PORT:-8000}
