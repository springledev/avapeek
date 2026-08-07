docker compose -f docker-compose.prod.yml up -d --build

# First run: key generate
#docker compose -f docker-compose.prod.yml exec backend php artisan key:generate

docker compose -f docker-compose.prod.yml exec backend php artisan migrate

# Re-cache after env changes
docker compose -f docker-compose.prod.yml exec backend php artisan config:cache
docker compose -f docker-compose.prod.yml exec backend php artisan route:cache
