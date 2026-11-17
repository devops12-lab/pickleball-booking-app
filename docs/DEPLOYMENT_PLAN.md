# CourtFlex — Production Deployment Plan

This document covers the complete tech stack, infrastructure requirements, deployment steps, and scaling strategy for running CourtFlex in production.

---

## 1. Tech Stack

| Layer | Technology | Version |
| --- | --- | --- |
| **Language** | PHP | 8.5+ |
| **Framework** | Laravel | 13.x |
| **Authentication** | Laravel Sanctum | 4.x (bearer tokens + stateful sessions) |
| **Social Auth** | Laravel Socialite | 5.x (Google OAuth) |
| **Database** | MySQL or PostgreSQL | 8.x / 16.x |
| **Cache / Queue / Sessions** | Redis | 7.x |
| **File Storage** | AWS S3 or Cloudflare R2 | — |
| **Frontend Build** | Vite | 8.x |
| **CSS** | Tailwind CSS | 4.x |
| **Testing** | Pest PHP | 5.x |
| **Code Style** | Laravel Pint | 1.x |
| **Process Manager** | Supervisor or Laravel Horizon | — |
| **Web Server** | Nginx + PHP-FPM | — |
| **SSL/TLS** | Cloudflare or Let's Encrypt | — |

---

## 2. Infrastructure Architecture

```
                    ┌──────────────────┐
                    │  Cloudflare CDN  │
                    │  SSL Termination │
                    └────────┬─────────┘
                             │
                    ┌────────▼─────────┐
                    │  Load Balancer   │
                    │  (Nginx/ALB)     │
                    └────────┬─────────┘
                             │
              ┌──────────────┼──────────────┐
              │              │              │
        ┌─────▼─────┐ ┌─────▼─────┐ ┌─────▼─────┐
        │  PHP-FPM  │ │  PHP-FPM  │ │  PHP-FPM  │
        │  Worker 1 │ │  Worker 2 │ │  Worker N │
        └─────┬─────┘ └─────┬─────┘ └─────┬─────┘
              │              │              │
              └──────────────┼──────────────┘
                             │
         ┌───────────┬───────┼───────┬───────────┐
         │           │       │       │           │
    ┌────▼───┐  ┌────▼──┐ ┌──▼──┐ ┌──▼──┐  ┌────▼────┐
    │ MySQL/ │  │ Redis │ │ S3/ │ │Queue│  │  Mail   │
    │ Postgre│  │ Cache │ │ R2  │ │Workers│ │ (SES/   │
    │ SQL    │  │       │ │     │ │     │  │ Mailgun)│
    └────────┘  └───────┘ └─────┘ └─────┘  └─────────┘
```

### Component Responsibilities

| Component | Purpose |
| --- | --- |
| **Cloudflare** | CDN for static assets, DDoS protection, SSL termination |
| **Load Balancer** | Distributes traffic across PHP-FPM instances |
| **PHP-FPM Workers** | Stateless Laravel application instances |
| **MySQL/PostgreSQL** | Primary relational database |
| **Redis** | Cache store, queue backend, session store, rate limiting |
| **S3/R2** | User uploads (avatars, receipts, court images) |
| **Queue Workers** | Background jobs (backups, emails, subscription processing) |
| **Mail Service** | Transactional email (SES, Mailgun, or SMTP) |

---

## 3. Environment Configuration

### Application

```env
APP_NAME=CourtFlex
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:GENERATE_WITH_php_artisan_key:generate
APP_URL=https://api.courtflex.com
APP_TIMEZONE=UTC
BCRYPT_ROUNDS=12
```

### Database

```env
DB_CONNECTION=mysql
DB_HOST=your-db-host.rds.amazonaws.com
DB_PORT=3306
DB_DATABASE=courtflex
DB_USERNAME=courtflex_app
DB_PASSWORD=your-secure-password
DB_CHARSET=utf8mb4
DB_COLLATION=utf8mb4_unicode_ci
```

For PostgreSQL:

```env
DB_CONNECTION=pgsql
DB_HOST=your-db-host.rds.amazonaws.com
DB_PORT=5432
DB_DATABASE=courtflex
DB_USERNAME=courtflex_app
DB_PASSWORD=your-secure-password
DB_SSLMODE=require
```

### Redis (Cache, Queue, Sessions)

```env
REDIS_HOST=your-redis-host.cache.amazonaws.com
REDIS_PORT=6379
REDIS_PASSWORD=your-redis-password

CACHE_STORE=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis
```

### File Storage

**AWS S3:**

```env
UPLOADS_DISK=s3
AWS_ACCESS_KEY_ID=your-access-key
AWS_SECRET_ACCESS_KEY=your-secret-key
AWS_DEFAULT_REGION=ap-southeast-1
AWS_BUCKET=courtflex-uploads
AWS_URL=https://courtflex-uploads.s3.ap-southeast-1.amazonaws.com
```

**Cloudflare R2:**

```env
UPLOADS_DISK=r2
CLOUDFLARE_R2_ACCESS_KEY_ID=your-r2-key
CLOUDFLARE_R2_SECRET_ACCESS_KEY=your-r2-secret
CLOUDFLARE_R2_DEFAULT_REGION=auto
CLOUDFLARE_R2_BUCKET=courtflex-uploads
CLOUDFLARE_R2_ENDPOINT=https://ACCOUNT_ID.r2.cloudflarestorage.com
CLOUDFLARE_R2_URL=https://uploads.courtflex.com
```

### Mail

```env
MAIL_MAILER=ses
MAIL_FROM_ADDRESS=noreply@courtflex.com
MAIL_FROM_NAME=CourtFlex
```

Or SMTP:

```env
MAIL_MAILER=smtp
MAIL_HOST=smtp.mailgun.org
MAIL_PORT=587
MAIL_USERNAME=postmaster@courtflex.com
MAIL_PASSWORD=your-smtp-password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=noreply@courtflex.com
MAIL_FROM_NAME=CourtFlex
```

### Logging

```env
LOG_CHANNEL=stack
LOG_STACK=daily,slack
LOG_LEVEL=warning
LOG_DAILY_DAYS=14
LOG_SLACK_WEBHOOK_URL=https://hooks.slack.com/services/YOUR/WEBHOOK/URL
```

### Frontend & CORS

```env
FRONTEND_URL=https://app.courtflex.com
FRONTEND_ALLOWED_ORIGINS=https://app.courtflex.com,https://www.courtflex.com
SANCTUM_STATEFUL_DOMAINS=app.courtflex.com,www.courtflex.com
CORS_SUPPORTS_CREDENTIALS=true
```

### Session Security

```env
SESSION_DRIVER=redis
SESSION_LIFETIME=120
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax
```

### Documentation Portals

```env
API_DOCS_ENABLED=false
DATA_MODEL_DOCS_ENABLED=false
WORKFLOW_DOCS_ENABLED=false
```

> [!NOTE]
> Disable documentation portals in production. If needed for staging, protect them with middleware (`'middleware' => ['auth']` in their config files).

### Database Backups

```env
DB_BACKUP_ENABLED=true
DB_BACKUP_DISK=s3
DB_BACKUP_PATH=database-backups
DB_BACKUP_QUEUE=database-backups
DB_BACKUP_DAILY_ENABLED=true
DB_BACKUP_DAILY_TIME=01:00
DB_BACKUP_DAILY_RETENTION=7
DB_BACKUP_WEEKLY_ENABLED=true
DB_BACKUP_WEEKLY_TIME=02:00
DB_BACKUP_WEEKLY_RETENTION=4
DB_BACKUP_MONTHLY_ENABLED=true
DB_BACKUP_MONTHLY_TIME=03:00
DB_BACKUP_MONTHLY_RETENTION=12
DB_BACKUP_TIMEZONE=UTC
DB_BACKUP_NOTIFICATION_EMAIL=ops@courtflex.com
```

---

## 4. Deployment Steps

### First-time deployment

```bash
# 1. Install PHP dependencies (no dev packages)
composer install --no-dev --optimize-autoloader

# 2. Install and build frontend assets
npm ci
npm run build

# 3. Generate application key (first time only)
php artisan key:generate

# 4. Run all migrations
php artisan migrate --force

# 5. Seed structural data (roles, permissions, plans, geo data)
php artisan db:seed --force

# 6. Create the first system administrator
php artisan admin:create

# 7. (Optional) Create initial accounts for other roles
# php artisan owner:create
# php artisan player:create
# php artisan staff:create --tenant=<slug>
# php artisan organizer:create --email=<email> --club=<slug>

# 7b. (Staging only) Seed demo accounts for all roles
# php artisan db:seed --class=DemoAccountSeeder
# ⚠ NEVER run DemoAccountSeeder in production — it creates accounts
#   with known passwords.

# 8. Cache configuration, routes, and views
php artisan optimize

# 9. Link storage directory
php artisan storage:link

# 10. Start queue workers (via Supervisor — see below)
# 11. Start the scheduler (via cron — see below)
```

### Subsequent deployments

```bash
# 1. Pull latest code
git pull origin main

# 2. Install dependencies
composer install --no-dev --optimize-autoloader
npm ci && npm run build

# 3. Run new migrations
php artisan migrate --force

# 4. Clear and rebuild caches
php artisan optimize:clear
php artisan optimize

# 5. Restart queue workers (graceful)
php artisan queue:restart
```

### Zero-downtime deployment

For zero-downtime deployments, use a tool like [Laravel Envoy](https://laravel.com/docs/envoy), [Deployer](https://deployer.org/), or CI/CD pipelines that:

1. Deploy to a new directory (atomic symlink swap)
2. Run `composer install` and `npm run build` in the new directory
3. Run migrations
4. Swap the symlink
5. Restart PHP-FPM and queue workers

---

## 5. Deployment Stacks

CourtFlex can be deployed on any stack that supports PHP 8.5+, MySQL/PostgreSQL, and Redis. Below are production-ready configurations for the most common deployment targets. Pick the stack that matches your team's expertise and infrastructure budget.

### Stack comparison

| Criteria | LAMP / LEMP (VPS) | Docker Compose | Kubernetes | Laravel Cloud | AWS ECS / Fargate |
| --- | --- | --- | --- | --- | --- |
| **Setup complexity** | Low | Medium | High | Very Low | Medium–High |
| **Cost at low traffic** | $20–60/mo | $20–60/mo | $100+/mo | Usage-based | $80+/mo |
| **Horizontal scaling** | Manual (add servers) | Manual (add replicas) | Automatic (HPA) | Automatic | Automatic (service autoscaling) |
| **Zero-downtime deploys** | Needs Envoy/Deployer | `docker compose up -d` | Rolling update | Built-in | Rolling update |
| **SSL management** | Certbot / Cloudflare | Certbot / Traefik | cert-manager | Built-in | ACM |
| **Best for** | Single-server MVP | Small–medium teams | Large-scale production | Fastest time-to-production | Enterprise AWS shops |

---

### 5A. LAMP / LEMP Stack (Traditional VPS)

> Ubuntu 24.04 LTS + Nginx + PHP-FPM + MySQL 8 + Redis 7

#### Prerequisites

```bash
# Update system
sudo apt update && sudo apt upgrade -y

# Add PHP 8.5 repository
sudo add-apt-repository ppa:ondrej/php -y
sudo apt update

# Install all required packages
sudo apt install -y \
    php8.5-fpm php8.5-cli php8.5-mysql php8.5-pgsql php8.5-redis \
    php8.5-mbstring php8.5-xml php8.5-curl php8.5-zip php8.5-gd \
    php8.5-bcmath php8.5-intl php8.5-readline \
    nginx mysql-server redis-server \
    supervisor certbot python3-certbot-nginx \
    git unzip curl

# Install Composer
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer

# Install Node.js 22 LTS (for Vite build)
curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash -
sudo apt install -y nodejs
```

#### PHP-FPM pool configuration

Create `/etc/php/8.5/fpm/pool.d/courtflex.conf`:

```ini
[courtflex]
user = www-data
group = www-data
listen = /run/php/courtflex.sock
listen.owner = www-data
listen.group = www-data

; Process management — tune based on server RAM
; Formula: max_children ≈ (Total RAM - OS overhead) / 50MB per process
pm = dynamic
pm.max_children = 20
pm.start_servers = 5
pm.min_spare_servers = 3
pm.max_spare_servers = 10
pm.max_requests = 1000

; Slow log for debugging
slowlog = /var/log/php/courtflex-slow.log
request_slowlog_timeout = 5s

; Security
php_admin_value[open_basedir] = /var/www/courtflex:/tmp
php_admin_value[disable_functions] = exec,passthru,shell_exec,system,proc_open,popen
php_admin_value[expose_php] = Off

; Performance
php_admin_value[opcache.enable] = 1
php_admin_value[opcache.memory_consumption] = 256
php_admin_value[opcache.max_accelerated_files] = 20000
php_admin_value[opcache.validate_timestamps] = 0
php_admin_value[realpath_cache_size] = 4096K
php_admin_value[realpath_cache_ttl] = 600
```

> [!WARNING]
> `opcache.validate_timestamps = 0` means PHP will never check if files changed on disk. You **must** run `sudo systemctl reload php8.5-fpm` after every deployment. If you skip this, the old code will continue to run.

#### Nginx server block

Create `/etc/nginx/sites-available/courtflex`:

```nginx
server {
    listen 80;
    server_name api.courtflex.com;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    server_name api.courtflex.com;

    root /var/www/courtflex/public;
    index index.php;

    # SSL — managed by Certbot or Cloudflare Origin cert
    ssl_certificate     /etc/letsencrypt/live/api.courtflex.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/api.courtflex.com/privkey.pem;
    ssl_protocols       TLSv1.2 TLSv1.3;
    ssl_ciphers         HIGH:!aNULL:!MD5;

    # Security headers
    add_header X-Frame-Options       "SAMEORIGIN"       always;
    add_header X-Content-Type-Options "nosniff"          always;
    add_header X-XSS-Protection      "1; mode=block"    always;
    add_header Referrer-Policy        "strict-origin-when-cross-origin" always;

    # Gzip compression
    gzip on;
    gzip_types text/plain text/css application/json application/javascript text/xml;
    gzip_min_length 1000;

    # Max upload size (court images, receipts)
    client_max_body_size 20M;

    # Static assets — long cache, served directly by Nginx
    location /build/ {
        expires 1y;
        add_header Cache-Control "public, immutable";
        try_files $uri =404;
    }

    location /storage/ {
        expires 7d;
        add_header Cache-Control "public";
        try_files $uri =404;
    }

    # Laravel front controller
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    # PHP-FPM
    location ~ \.php$ {
        fastcgi_pass unix:/run/php/courtflex.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;

        # Timeouts
        fastcgi_connect_timeout 10s;
        fastcgi_send_timeout    60s;
        fastcgi_read_timeout    60s;
    }

    # Block dotfiles (except .well-known for ACME challenges)
    location ~ /\.(?!well-known) {
        deny all;
    }

    access_log /var/log/nginx/courtflex-access.log;
    error_log  /var/log/nginx/courtflex-error.log;
}
```

```bash
sudo ln -s /etc/nginx/sites-available/courtflex /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

#### MySQL hardening

```bash
# Run the security script
sudo mysql_secure_installation

# Create database and user
sudo mysql -e "
    CREATE DATABASE courtflex CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    CREATE USER 'courtflex_app'@'localhost' IDENTIFIED BY 'your-secure-password';
    GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, REFERENCES
        ON courtflex.* TO 'courtflex_app'@'localhost';
    FLUSH PRIVILEGES;
"
```

> [!IMPORTANT]
> Only grant the minimum required privileges. Never grant `ALL PRIVILEGES` or `SUPER` to the application user. The grants above cover migrations (`CREATE`, `ALTER`, `DROP`, `INDEX`) and normal CRUD operations.

#### Redis hardening

Edit `/etc/redis/redis.conf`:

```conf
# Bind to localhost only — never expose Redis to the network
bind 127.0.0.1 -::1

# Require a password
requirepass your-redis-password

# Limit memory and set eviction policy
maxmemory 512mb
maxmemory-policy allkeys-lru

# Disable dangerous commands
rename-command FLUSHDB ""
rename-command FLUSHALL ""
rename-command CONFIG  "COURTFLEX_CONFIG_a9f3c1"
```

```bash
sudo systemctl restart redis-server
```

#### File permissions

```bash
# Set ownership
sudo chown -R www-data:www-data /var/www/courtflex

# Directories: readable + executable
sudo find /var/www/courtflex -type d -exec chmod 755 {} \;

# Files: readable only
sudo find /var/www/courtflex -type f -exec chmod 644 {} \;

# Storage and cache must be writable
sudo chmod -R 775 /var/www/courtflex/storage
sudo chmod -R 775 /var/www/courtflex/bootstrap/cache

# .env must be readable only by the web user
sudo chmod 600 /var/www/courtflex/.env
```

#### SSL with Certbot

```bash
sudo certbot --nginx -d api.courtflex.com

# Auto-renewal (Certbot adds a systemd timer, but verify)
sudo systemctl status certbot.timer
```

#### Deploy script

Create `/var/www/courtflex/deploy.sh`:

```bash
#!/bin/bash
set -euo pipefail

APP_DIR="/var/www/courtflex"
cd "$APP_DIR"

echo "==> Pulling latest code..."
git pull origin main

echo "==> Installing PHP dependencies..."
composer install --no-dev --optimize-autoloader --no-interaction

echo "==> Installing and building frontend..."
npm ci
npm run build

echo "==> Running migrations..."
php artisan migrate --force

echo "==> Clearing and rebuilding caches..."
php artisan optimize:clear
php artisan optimize

echo "==> Restarting PHP-FPM (clears OPcache)..."
sudo systemctl reload php8.5-fpm

echo "==> Restarting queue workers..."
php artisan queue:restart

echo "==> Deployment complete!"
```

```bash
chmod +x /var/www/courtflex/deploy.sh
```

#### LAMP common pitfalls

| Problem | Cause | Fix |
| --- | --- | --- |
| Changes not visible after deploy | OPcache serves stale bytecode | Reload PHP-FPM: `sudo systemctl reload php8.5-fpm` |
| 502 Bad Gateway | PHP-FPM socket path mismatch | Verify `fastcgi_pass` matches the `listen` directive in the pool config |
| Storage permission denied | Incorrect ownership after `git pull` | Re-run `chown -R www-data:www-data storage bootstrap/cache` |
| `php artisan migrate` hangs | MySQL `wait_timeout` too low | Set `wait_timeout = 28800` in `my.cnf` |
| Redis connection refused | Redis bound to wrong interface | Verify `bind 127.0.0.1` and `REDIS_HOST=127.0.0.1` in `.env` |
| Cron jobs not running | Missing crontab entry | Verify with `crontab -l -u www-data` |
| SSL certificate expired | Certbot timer not active | Run `sudo certbot renew --dry-run` to test auto-renewal |

#### LAMP verification checklist

```bash
# Verify all services are running
sudo systemctl status nginx php8.5-fpm mysql redis-server supervisor

# Verify Nginx config is valid
sudo nginx -t

# Verify PHP extensions are loaded
php -m | grep -E 'redis|mysql|mbstring|xml|curl|zip|gd|bcmath|intl|opcache'

# Verify Laravel responds
curl -s -o /dev/null -w "%{http_code}" https://api.courtflex.com/api/v1/health

# Verify queue workers are running
sudo supervisorctl status

# Verify cron is registered
crontab -l -u www-data | grep artisan

# Verify file permissions
ls -la /var/www/courtflex/.env         # should be -rw-------
ls -la /var/www/courtflex/storage/logs # should be drwxrwxr-x
```

---

### 5B. Docker Compose

> Single-server Docker deployment with Nginx, PHP-FPM, MySQL 8, and Redis 7.

#### Dockerfile

Create `Dockerfile` in the project root:

```dockerfile
# ---- Build stage: frontend assets ----
FROM node:22-alpine AS frontend
WORKDIR /build
COPY package.json package-lock.json ./
RUN npm ci
COPY vite.config.js tailwind.config.js postcss.config.js ./
COPY resources/ resources/
RUN npm run build

# ---- Build stage: PHP dependencies ----
FROM composer:2 AS vendor
WORKDIR /build
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist
COPY . .
RUN composer dump-autoload --optimize

# ---- Production image ----
FROM php:8.5-fpm-alpine

# Install system dependencies
RUN apk add --no-cache \
    nginx supervisor curl \
    libpng-dev libjpeg-turbo-dev freetype-dev \
    libzip-dev icu-dev oniguruma-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pdo_mysql pdo_pgsql mbstring xml curl zip gd bcmath intl opcache \
    && pecl install redis && docker-php-ext-enable redis

# PHP production configuration
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php.ini "$PHP_INI_DIR/conf.d/99-courtflex.ini"

# Copy application code
WORKDIR /var/www/courtflex
COPY --from=vendor /build .
COPY --from=frontend /build/public/build public/build/

# Copy configuration files
COPY docker/nginx.conf   /etc/nginx/http.d/default.conf
COPY docker/supervisor.conf /etc/supervisord.conf
COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

# Set permissions
RUN chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

EXPOSE 80

ENTRYPOINT ["/entrypoint.sh"]
CMD ["supervisord", "-c", "/etc/supervisord.conf"]
```

#### docker/php.ini

```ini
; Performance
opcache.enable = 1
opcache.memory_consumption = 256
opcache.max_accelerated_files = 20000
opcache.validate_timestamps = 0

realpath_cache_size = 4096K
realpath_cache_ttl = 600

; Uploads
upload_max_filesize = 20M
post_max_size = 25M

; Security
expose_php = Off
display_errors = Off
log_errors = On
```

#### docker/nginx.conf

```nginx
server {
    listen 80;
    server_name _;
    root /var/www/courtflex/public;
    index index.php;

    client_max_body_size 20M;

    add_header X-Frame-Options       "SAMEORIGIN"       always;
    add_header X-Content-Type-Options "nosniff"          always;

    location /build/ {
        expires 1y;
        add_header Cache-Control "public, immutable";
        try_files $uri =404;
    }

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass 127.0.0.1:9000;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 60s;
    }

    location ~ /\. {
        deny all;
    }
}
```

#### docker/supervisor.conf

```ini
[supervisord]
nodaemon=true
logfile=/dev/stdout
logfile_maxbytes=0
pidfile=/tmp/supervisord.pid

[program:nginx]
command=nginx -g "daemon off;"
autostart=true
autorestart=true
stdout_logfile=/dev/stdout
stdout_logfile_maxbytes=0
stderr_logfile=/dev/stderr
stderr_logfile_maxbytes=0

[program:php-fpm]
command=php-fpm --nodaemonize
autostart=true
autorestart=true
stdout_logfile=/dev/stdout
stdout_logfile_maxbytes=0
stderr_logfile=/dev/stderr
stderr_logfile_maxbytes=0

[program:queue-worker]
command=php /var/www/courtflex/artisan queue:work redis --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
numprocs=2
process_name=%(program_name)s_%(process_num)02d
stdout_logfile=/dev/stdout
stdout_logfile_maxbytes=0
stderr_logfile=/dev/stderr
stderr_logfile_maxbytes=0

[program:backup-worker]
command=php /var/www/courtflex/artisan queue:work redis --queue=database-backups --sleep=10 --tries=1 --max-time=7200
autostart=true
autorestart=true
numprocs=1
stdout_logfile=/dev/stdout
stdout_logfile_maxbytes=0
stderr_logfile=/dev/stderr
stderr_logfile_maxbytes=0

[program:scheduler]
command=sh -c "while true; do php /var/www/courtflex/artisan schedule:run --no-interaction; sleep 60; done"
autostart=true
autorestart=true
stdout_logfile=/dev/stdout
stdout_logfile_maxbytes=0
stderr_logfile=/dev/stderr
stderr_logfile_maxbytes=0
```

#### docker/entrypoint.sh

```bash
#!/bin/sh
set -e

echo "==> Running entrypoint..."

# Wait for MySQL to be ready
until php artisan db:monitor --databases=mysql 2>/dev/null; do
    echo "Waiting for database..."
    sleep 2
done

# Run migrations (safe in production — migrate --force skips confirmation)
php artisan migrate --force

# Cache configuration, routes, and views
php artisan optimize

# Link storage directory (idempotent)
php artisan storage:link 2>/dev/null || true

echo "==> Application ready."
exec "$@"
```

#### docker-compose.yml

```yaml
services:
  app:
    build:
      context: .
      dockerfile: Dockerfile
    ports:
      - "80:80"
    env_file: .env.docker
    volumes:
      - app-storage:/var/www/courtflex/storage/app
    depends_on:
      mysql:
        condition: service_healthy
      redis:
        condition: service_healthy
    restart: unless-stopped
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost/api/v1/health"]
      interval: 30s
      timeout: 5s
      retries: 3
      start_period: 30s

  mysql:
    image: mysql:8.4
    environment:
      MYSQL_ROOT_PASSWORD: ${DB_ROOT_PASSWORD}
      MYSQL_DATABASE: courtflex
      MYSQL_USER: courtflex_app
      MYSQL_PASSWORD: ${DB_PASSWORD}
    volumes:
      - mysql-data:/var/lib/mysql
      - ./docker/mysql.cnf:/etc/mysql/conf.d/courtflex.cnf:ro
    ports:
      - "127.0.0.1:3306:3306"
    restart: unless-stopped
    healthcheck:
      test: ["CMD", "mysqladmin", "ping", "-h", "localhost", "-u", "root", "-p${DB_ROOT_PASSWORD}"]
      interval: 10s
      timeout: 5s
      retries: 5

  redis:
    image: redis:7-alpine
    command: redis-server --requirepass ${REDIS_PASSWORD} --maxmemory 512mb --maxmemory-policy allkeys-lru
    volumes:
      - redis-data:/data
    ports:
      - "127.0.0.1:6379:6379"
    restart: unless-stopped
    healthcheck:
      test: ["CMD", "redis-cli", "-a", "${REDIS_PASSWORD}", "ping"]
      interval: 10s
      timeout: 3s
      retries: 5

volumes:
  app-storage:
  mysql-data:
  redis-data:
```

#### docker/mysql.cnf

```ini
[mysqld]
character-set-server = utf8mb4
collation-server     = utf8mb4_unicode_ci
default-authentication-plugin = caching_sha2_password

# Performance
innodb_buffer_pool_size = 512M
innodb_log_file_size    = 256M
max_connections         = 150
wait_timeout            = 28800

# Slow query log
slow_query_log      = 1
slow_query_log_file = /var/lib/mysql/slow.log
long_query_time     = 2
```

#### .env.docker

```env
APP_NAME=CourtFlex
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:GENERATE_AFTER_FIRST_BUILD
APP_URL=https://api.courtflex.com
APP_TIMEZONE=UTC
BCRYPT_ROUNDS=12

DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=courtflex
DB_USERNAME=courtflex_app
DB_PASSWORD=your-secure-password

REDIS_HOST=redis
REDIS_PORT=6379
REDIS_PASSWORD=your-redis-password

CACHE_STORE=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis

UPLOADS_DISK=s3
# ... (see Section 3 for full S3/R2 config)
```

#### .dockerignore

```
.git
node_modules
vendor
tests
storage/logs/*
storage/framework/cache/*
storage/framework/sessions/*
storage/framework/views/*
.env
.env.*
docker-compose*.yml
Dockerfile
*.md
```

#### Docker deployment commands

```bash
# First-time setup
docker compose up -d --build

# Generate application key (first time only)
docker compose exec app php artisan key:generate

# Seed structural data
docker compose exec app php artisan db:seed --force

# Create the first admin
docker compose exec app php artisan admin:create

# Subsequent deployments
docker compose build app
docker compose up -d app    # Recreates only the app container

# View logs
docker compose logs -f app

# Run a one-off migration
docker compose exec app php artisan migrate --force

# Scale queue workers (if separated into their own service)
docker compose up -d --scale queue-worker=4
```

#### Docker common pitfalls

| Problem | Cause | Fix |
| --- | --- | --- |
| Container restarts in a loop | Database not ready when app starts | Use `depends_on` with `condition: service_healthy` and wait logic in `entrypoint.sh` |
| File uploads lost on redeploy | Storage not mounted as a volume | Mount `app-storage` volume to `/var/www/courtflex/storage/app` |
| MySQL data lost on redeploy | Missing named volume | Always use a named volume (`mysql-data`) — never rely on anonymous volumes |
| `APP_KEY` missing after rebuild | Key not persisted | Generate once, then store in `.env.docker` — do not regenerate on every build |
| OPcache serves stale code | OPcache from previous layer cached | Multi-stage build ensures fresh code; `validate_timestamps=0` is safe because the entire image is rebuilt |
| Permission denied in storage | Container runs as root but Laravel writes as www-data | `chown -R www-data:www-data storage` in Dockerfile |
| Redis connection refused | Wrong hostname in `.env` | Use service name `redis` (not `localhost` or `127.0.0.1`) inside Docker network |

#### Docker verification checklist

```bash
# Verify all containers are healthy
docker compose ps

# Verify app responds
curl -s -o /dev/null -w "%{http_code}" http://localhost/api/v1/health

# Verify queue workers are processing
docker compose exec app php artisan queue:monitor redis

# Verify database connection
docker compose exec app php artisan db:monitor

# Verify Redis connection
docker compose exec app php artisan tinker --execute 'echo cache()->store("redis")->put("test", 1, 10) ? "OK" : "FAIL";'

# Check container resource usage
docker stats --no-stream
```

---

### 5C. Laravel Cloud (Managed PaaS)

> The fastest path to production. Laravel Cloud handles infrastructure, SSL, deployments, scaling, and monitoring out of the box.

#### Why Laravel Cloud

- **Zero infrastructure management** — no servers, containers, or Kubernetes to configure.
- **Automatic scaling** — scales PHP workers and queue workers based on traffic.
- **Built-in SSL** — automatic HTTPS on custom domains.
- **Integrated database and Redis** — managed MySQL and Redis provisioned alongside the app.
- **GitHub integration** — push-to-deploy with preview environments for pull requests.

#### Setup steps

```bash
# 1. Install the Cloud CLI
composer global require laravel/cloud-cli

# 2. Authenticate
cloud login

# 3. Link your project (from the project root)
cloud init

# 4. Configure environment variables
cloud env:set APP_ENV=production
cloud env:set APP_DEBUG=false
cloud env:set APP_KEY=base64:YOUR_APP_KEY
# ... set all variables from Section 3

# 5. Provision a database
cloud database:create --type=mysql --version=8.4

# 6. Provision Redis
cloud redis:create

# 7. Deploy
cloud deploy

# 8. Run first-time setup
cloud artisan migrate --force
cloud artisan db:seed --force
cloud artisan admin:create
```

#### cloud.yaml

Create `cloud.yaml` in the project root:

```yaml
name: courtflex

web:
  build:
    - composer install --no-dev --optimize-autoloader
    - npm ci && npm run build
    - php artisan optimize
  workers: 2
  memory: 512

queue:
  workers:
    default:
      connection: redis
      processes: 4
      tries: 3
      max-time: 3600
    database-backups:
      connection: redis
      queue: database-backups
      processes: 1
      tries: 1
      max-time: 7200

schedule: true

domains:
  - api.courtflex.com
```

#### Laravel Cloud deployment workflow

```
git push origin main
  │
  ├─► Cloud detects push → builds image → runs migrations
  │
  ├─► Health check passes → traffic shifts to new version
  │
  └─► Old version drains connections → removed
```

> [!NOTE]
> Laravel Cloud runs `php artisan migrate --force` automatically on every deploy. Add the `--isolated` flag in your migration commands if you run multiple instances to prevent concurrent migration race conditions.

---

### 5D. AWS Elastic Container Service (ECS / Fargate)

> For teams already invested in the AWS ecosystem. Uses Fargate (serverless containers) so there are no EC2 instances to manage.

#### Architecture overview

```
Route 53 (DNS)
  └── CloudFront (CDN + SSL)
        └── Application Load Balancer
              ├── ECS Service: web (Fargate, 2–10 tasks)
              │     └── PHP-FPM + Nginx container
              └── ECS Service: queue-worker (Fargate, 1–4 tasks)
                    └── PHP CLI container

RDS MySQL 8.4 (Multi-AZ)
ElastiCache Redis 7 (cluster mode)
S3 (uploads + backups)
SES (transactional email)
CloudWatch (logs + metrics)
Secrets Manager (env variables)
```

#### Task definition (web)

```json
{
  "family": "courtflex-web",
  "networkMode": "awsvpc",
  "requiresCompatibilities": ["FARGATE"],
  "cpu": "512",
  "memory": "1024",
  "executionRoleArn": "arn:aws:iam::ACCOUNT:role/ecsTaskExecutionRole",
  "taskRoleArn": "arn:aws:iam::ACCOUNT:role/courtflexTaskRole",
  "containerDefinitions": [
    {
      "name": "app",
      "image": "ACCOUNT.dkr.ecr.REGION.amazonaws.com/courtflex:latest",
      "essential": true,
      "portMappings": [{ "containerPort": 80, "protocol": "tcp" }],
      "secrets": [
        { "name": "APP_KEY", "valueFrom": "arn:aws:secretsmanager:REGION:ACCOUNT:secret:courtflex/app-key" },
        { "name": "DB_PASSWORD", "valueFrom": "arn:aws:secretsmanager:REGION:ACCOUNT:secret:courtflex/db-password" },
        { "name": "REDIS_PASSWORD", "valueFrom": "arn:aws:secretsmanager:REGION:ACCOUNT:secret:courtflex/redis-password" }
      ],
      "environment": [
        { "name": "APP_ENV", "value": "production" },
        { "name": "APP_DEBUG", "value": "false" },
        { "name": "DB_HOST", "value": "courtflex-db.cluster-xxxx.REGION.rds.amazonaws.com" },
        { "name": "REDIS_HOST", "value": "courtflex-redis.xxxx.REGION.cache.amazonaws.com" }
      ],
      "logConfiguration": {
        "logDriver": "awslogs",
        "options": {
          "awslogs-group": "/ecs/courtflex",
          "awslogs-region": "ap-southeast-1",
          "awslogs-stream-prefix": "web"
        }
      },
      "healthCheck": {
        "command": ["CMD-SHELL", "curl -f http://localhost/api/v1/health || exit 1"],
        "interval": 30,
        "timeout": 5,
        "retries": 3,
        "startPeriod": 60
      }
    }
  ]
}
```

#### CI/CD with GitHub Actions

Create `.github/workflows/deploy.yml`:

```yaml
name: Deploy to ECS

on:
  push:
    branches: [main]

env:
  AWS_REGION: ap-southeast-1
  ECR_REPOSITORY: courtflex
  ECS_CLUSTER: courtflex-production
  ECS_SERVICE_WEB: courtflex-web
  ECS_SERVICE_WORKER: courtflex-worker

jobs:
  deploy:
    runs-on: ubuntu-latest
    permissions:
      id-token: write
      contents: read

    steps:
      - uses: actions/checkout@v4

      - name: Configure AWS credentials
        uses: aws-actions/configure-aws-credentials@v4
        with:
          role-to-assume: arn:aws:iam::ACCOUNT:role/github-actions-deploy
          aws-region: ${{ env.AWS_REGION }}

      - name: Login to ECR
        id: ecr
        uses: aws-actions/amazon-ecr-login@v2

      - name: Build and push Docker image
        run: |
          docker build -t ${{ steps.ecr.outputs.registry }}/${{ env.ECR_REPOSITORY }}:${{ github.sha }} .
          docker push ${{ steps.ecr.outputs.registry }}/${{ env.ECR_REPOSITORY }}:${{ github.sha }}

      - name: Run migrations
        run: |
          aws ecs run-task \
            --cluster ${{ env.ECS_CLUSTER }} \
            --task-definition courtflex-migrate \
            --launch-type FARGATE \
            --network-configuration "awsvpcConfiguration={subnets=[subnet-xxx],securityGroups=[sg-xxx]}" \
            --overrides '{"containerOverrides":[{"name":"app","command":["php","artisan","migrate","--force"]}]}'

      - name: Update ECS services
        run: |
          # Update task definition with new image
          NEW_IMAGE="${{ steps.ecr.outputs.registry }}/${{ env.ECR_REPOSITORY }}:${{ github.sha }}"

          for SERVICE in ${{ env.ECS_SERVICE_WEB }} ${{ env.ECS_SERVICE_WORKER }}; do
            aws ecs update-service \
              --cluster ${{ env.ECS_CLUSTER }} \
              --service $SERVICE \
              --force-new-deployment
          done

      - name: Wait for deployment
        run: |
          aws ecs wait services-stable \
            --cluster ${{ env.ECS_CLUSTER }} \
            --services ${{ env.ECS_SERVICE_WEB }}
```

#### AWS common pitfalls

| Problem | Cause | Fix |
| --- | --- | --- |
| Task fails to start | Secrets Manager ARN wrong or IAM policy missing | Verify `ecsTaskExecutionRole` has `secretsmanager:GetSecretValue` for each secret ARN |
| Cannot connect to RDS | Security group misconfigured | ECS tasks and RDS must share a security group or have an inbound rule from the ECS security group |
| Cannot connect to ElastiCache | ElastiCache in different subnet or missing SG rule | ElastiCache must be in the same VPC; add inbound rule for port 6379 from the ECS security group |
| Deployment times out | Health check failing | Check CloudWatch logs; ensure `/api/v1/health` returns 200 after migrations run |
| Environment variables missing | Using `environment` instead of `secrets` for sensitive values | Always use `secrets` with Secrets Manager for passwords, keys, and tokens |
| High costs | Over-provisioned Fargate tasks | Start with 0.25 vCPU / 512 MB; scale up based on CloudWatch CPU/memory metrics |
| Migrations run twice | Multiple tasks start simultaneously | Use `php artisan migrate --force --isolated` or run migrations as a separate one-off task before updating the service |

#### AWS verification checklist

```bash
# Verify ECS services are stable
aws ecs describe-services --cluster courtflex-production \
    --services courtflex-web courtflex-worker \
    --query 'services[].{name:serviceName,desired:desiredCount,running:runningCount,status:status}'

# Verify ALB health checks pass
aws elbv2 describe-target-health --target-group-arn arn:aws:elasticloadbalancing:...

# Verify RDS connectivity
aws rds describe-db-instances --db-instance-identifier courtflex-db \
    --query 'DBInstances[0].DBInstanceStatus'

# Verify ElastiCache
aws elasticache describe-cache-clusters --cache-cluster-id courtflex-redis \
    --query 'CacheClusters[0].CacheClusterStatus'

# Check recent CloudWatch logs
aws logs tail /ecs/courtflex --since 10m

# Verify secrets are accessible
aws secretsmanager get-secret-value --secret-id courtflex/app-key --query 'Name'
```

---

### 5E. Choosing the Right Stack

Use this decision tree to pick your deployment stack:

```
Is this a quick MVP or staging environment?
├── YES → Laravel Cloud (Section 5C)
└── NO
    ├── Is your team already on AWS with Terraform/CDK?
    │   ├── YES → AWS ECS / Fargate (Section 5D)
    │   └── NO
    │       ├── Do you need container orchestration / microservices?
    │       │   ├── YES → Docker Compose for staging, then Kubernetes for production
    │       │   └── NO
    │       │       ├── Budget under $60/mo?
    │       │       │   ├── YES → LAMP / LEMP on a VPS (Section 5A)
    │       │       │   └── NO → Docker Compose on a dedicated server (Section 5B)
    │       │       └──
    │       └──
    └──
```

> [!IMPORTANT]
> Regardless of which stack you choose, the application-level steps are identical: install dependencies, run migrations, seed data, create admin, cache config, start queue workers, and register the cron scheduler. The stack only changes **where** and **how** those steps execute.

---

## 6. Queue Workers & Scheduled Tasks

### Supervisor configuration

Create `/etc/supervisor/conf.d/courtflex-worker.conf`:

```ini
[program:courtflex-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/courtflex/artisan queue:work redis --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=4
redirect_stderr=true
stdout_logfile=/var/log/courtflex/worker.log
stopwaitsecs=3600

[program:courtflex-backup-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/courtflex/artisan queue:work redis --queue=database-backups --sleep=10 --tries=1 --max-time=7200
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/var/log/courtflex/backup-worker.log
stopwaitsecs=7200
```

Then:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start courtflex-worker:*
sudo supervisorctl start courtflex-backup-worker:*
```

### Cron scheduler

Add to the server's crontab (`crontab -e`):

```cron
* * * * * cd /var/www/courtflex && php artisan schedule:run >> /dev/null 2>&1
```

### Existing scheduled commands

| Command | Schedule | Purpose |
| --- | --- | --- |
| `database:backup-schedule-run` | Per config (daily/weekly/monthly) | Automated database backups |
| `subscriptions:generate-renewals` | Daily | Create renewal invoices for expiring subscriptions |
| `subscriptions:process-downgrades` | Daily | Apply pending plan downgrades |
| `subscriptions:process-expiration` | Daily | Move past_due subscriptions to expired |

---

## 7. Scaling Strategy

### Horizontal scaling (stateless PHP)

The application is stateless by design:

- **Sessions** stored in Redis (not filesystem)
- **Cache** stored in Redis (not filesystem)
- **Uploads** stored in S3/R2 (not local disk)
- **Queue** backed by Redis (not database)

This means you can add or remove PHP-FPM instances behind a load balancer without session affinity or sticky sessions.

### Database scaling

| Strategy | When to Use |
| --- | --- |
| **Vertical scaling** | First step — increase CPU/RAM on the primary database |
| **Read replicas** | When read queries dominate (analytics, dashboards, reporting) |
| **Connection pooling** | When connection count exceeds database limits (use PgBouncer for PostgreSQL or ProxySQL for MySQL) |
| **Query optimization** | Ensure all production indexes are in place (see migration in this plan) |

### Redis scaling

| Strategy | When to Use |
| --- | --- |
| **Single instance** | Up to ~50K concurrent users |
| **Redis Cluster** | Beyond single-instance limits |
| **Separate instances** | Split cache, queue, and sessions across dedicated Redis instances to isolate workloads |

### CDN and static assets

- Serve Vite build output (`public/build/`) through Cloudflare or another CDN
- Set long cache headers on hashed asset filenames (Vite handles cache busting)
- Serve user uploads through S3/R2 with CDN in front

### Estimated capacity per instance

| Metric | Single Server | 3-Server Cluster |
| --- | --- | --- |
| Concurrent API requests | ~200-500/sec | ~600-1500/sec |
| Background jobs | ~100/min per worker | ~400/min (4 workers) |
| Database connections | ~50-100 | ~150-300 (with pooling) |

> [!NOTE]
> Actual capacity depends on query complexity, payload size, and hardware. Load test with realistic traffic before launch.

---

## 8. Monitoring & Logging

### Application monitoring

| Tool | Purpose |
| --- | --- |
| **Laravel Telescope** | Debug queries, jobs, requests in staging |
| **Sentry** or **Bugsnag** | Error tracking and alerting in production |
| **Laravel Pulse** | Real-time application performance dashboard |

### Infrastructure monitoring

| Tool | Purpose |
| --- | --- |
| **CloudWatch / Datadog** | Server metrics (CPU, memory, disk, network) |
| **Redis CLI `INFO`** | Cache hit rates, memory usage, connected clients |
| **Slow query log** | MySQL/PostgreSQL slow query identification |

### Health check endpoint

Add a simple health check for load balancer probes:

```
GET /api/v1/health → 200 OK
```

Checks: database connectivity, Redis connectivity, disk space.

### Alerting rules

| Condition | Severity | Action |
| --- | --- | --- |
| Error rate > 1% | Warning | Slack notification |
| Error rate > 5% | Critical | PagerDuty / SMS |
| Response time P95 > 2s | Warning | Investigate slow queries |
| Queue depth > 1000 | Warning | Scale queue workers |
| Disk space < 10% | Critical | Clean up or expand |
| Failed jobs > 10/hour | Warning | Review job error logs |

---

## 9. Security Checklist

### Application

- [ ] `APP_ENV=production` and `APP_DEBUG=false`
- [ ] `APP_KEY` generated and stored securely
- [ ] HTTPS enforced on all endpoints
- [ ] Secure session cookies (`SESSION_SECURE_COOKIE=true`)
- [ ] CORS restricted to production frontend domains
- [ ] Sanctum stateful domains set to production URLs only
- [ ] Rate limiting active on authentication endpoints (5/min per email+IP)
- [ ] Sanctum token expiration configured (`SANCTUM_EXPIRATION=1440`)
- [ ] Documentation portals disabled or behind auth middleware
- [ ] Database backup encryption enabled for sensitive data

### Infrastructure

- [ ] Database not publicly accessible (private subnet / VPC)
- [ ] Redis not publicly accessible (private subnet / VPC)
- [ ] SSH access restricted to known IPs
- [ ] Firewall allows only ports 80, 443
- [ ] Database credentials rotated regularly
- [ ] S3/R2 bucket permissions restricted (no public listing)
- [ ] Server OS and packages kept up to date
- [ ] PHP-FPM runs as a non-root user

### Backups

- [ ] Automated daily database backups enabled
- [ ] Backup retention policy configured (7 daily, 4 weekly, 12 monthly)
- [ ] Backup restore tested and verified
- [ ] Backup storage on a separate disk/service from the primary database

---

## 10. Database Performance Indexes

A migration (`2026_11_17_000001_add_production_performance_indexes.php`) adds 13 missing indexes identified during the production audit:

| Priority | Table | Column | Reason |
| --- | --- | --- | --- |
| High | `courts` | `status` | Active/maintenance filtering |
| High | `bookings` | `hold_expires_at` | Expired hold cleanup queries |
| High | `bookings` | `source` | Booking source filtering |
| High | `open_play_participants` | `status` | Registration state filtering |
| High | `open_play_participants` | `fee_status` | Payment status filtering |
| Medium | `player_profiles` | `skill_level` | Skill-based discovery |
| Medium | `player_profiles` | `visibility` | Public/private profile filtering |
| Medium | `venue_profiles` | `status` | Venue status filtering |
| Medium | `venue_profiles` | `barangay_id` | Location-based lookups |
| Medium | `app_notifications` | `category` | Notification category filtering |
| Medium | `notification_preferences` | `category` | Preference category lookups |
| Low | `user_permissions` | `permission_id` | FK optimization |
| Low | `booking_receipts` | `reviewed_by` | FK audit trail |

These indexes are added in a single migration and can be applied during deployment with `php artisan migrate --force`.
