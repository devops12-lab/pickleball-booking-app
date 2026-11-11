# CourtFlex — Development & Testing Setup

## Prerequisites
- **PHP 8.5+** with required extensions: `pdo`, `mbstring`, `openssl`, `tokenizer`, `xml`, `ctype`, `json`, `sqlite3` or `pdo_mysql`, `fileinfo`
- **Composer 2.x**
- **Node.js 18+** and **npm**
- **SQLite 3** (Default for local development and automated testing) or **MySQL 8.x**

---

## Installation Steps

1. **Clone & Install Dependencies**:
   ```bash
   composer install
   npm install
   ```

2. **Environment Configuration**:
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

   **Default Local Development Setup (SQLite)**:
   ```env
   DB_CONNECTION=sqlite
   # database/database.sqlite will be auto-created if absent
   ```

   **Alternative Setup (MySQL)**:
   ```env
   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=courtflex
   DB_USERNAME=root
   DB_PASSWORD=your_password
   ```

   **Storage & Uploads Disk Configuration**:
   Uploads (avatars, payment receipts) are dynamically configured via `UPLOADS_DISK`:
   ```env
   # Option A: Local Public Disk (Default)
   UPLOADS_DISK=public

   # Option B: AWS S3 Bucket
   UPLOADS_DISK=s3
   AWS_ACCESS_KEY_ID=your-key
   AWS_SECRET_ACCESS_KEY=your-secret
   AWS_DEFAULT_REGION=us-east-1
   AWS_BUCKET=your-bucket-name
   AWS_URL=https://your-bucket-name.s3.amazonaws.com

   # Option C: Cloudflare R2 (S3-Compatible)
   UPLOADS_DISK=r2
   CLOUDFLARE_R2_ACCESS_KEY_ID=your-r2-access-key-id
   CLOUDFLARE_R2_SECRET_ACCESS_KEY=your-r2-secret-access-key
   CLOUDFLARE_R2_DEFAULT_REGION=auto
   CLOUDFLARE_R2_BUCKET=your-r2-bucket-name
   CLOUDFLARE_R2_ENDPOINT=https://<account-id>.r2.cloudflarestorage.com
   CLOUDFLARE_R2_URL=https://<custom-domain-or-r2-dev-url>
   ```

3. **Run Migrations & Seeders**:
   ```bash
   php artisan migrate:fresh --seed
   ```

   The seeder populates:
   - 32 granular permissions
   - 5 primary roles (`system_admin`, `owner`, `staff`, `player`, `club_manager`)
   - Platform System Admin: `admin@courtflex.test` (password: `Password123!`)
   - Facility Owner: `owner@courtflex.test` (password: `Password123!`)
   - Facility Staff: `staff@courtflex.test` (password: `Password123!`)
   - Global Player: `player@courtflex.test` (password: `Password123!`)
   - Club Organizer: `organizer@courtflex.test` (password: `Password123!`)
   - Demo facilities, courts, operating hours, and subscription tiers

4. **Serve the Application**:
   ```bash
   php artisan serve
   ```
   The API will listen on `http://localhost:8000`.

   > [!NOTE]
   > - Web root `http://localhost:8000/` explicitly returns `404 Not Found`.
   > - The interactive endpoint documentation is available at `http://localhost:8000/docs-api-endpoints`.

---

## Automated Testing

CourtFlex uses **Pest PHP** for comprehensive automated testing.

### Run All Tests:
```bash
php artisan test --compact
# or directly with pest
vendor/bin/pest
```

### Run Specific Test Suites:
```bash
# Test Authentication across all 5 roles
php artisan test tests/Feature/RoleSpecificAuthTest.php --compact

# Test Rate Limiting & Abuse Protection
php artisan test tests/Feature/RateLimitingTest.php --compact

# Test Tenant Boundary Isolation
php artisan test tests/Feature/TenantIsolationTest.php --compact

# Test File Upload Security & MIME validations
php artisan test tests/Feature/FileUploadSecurityTest.php --compact

# Test Policy Boundaries & CRUD
php artisan test tests/Feature/CrudAndPoliciesTest.php --compact
```

---

## Code Style & Formatting (Laravel Pint)

All PHP code adheres to PSR-12 and Laravel Boost conventions. Run Pint before committing any changes:

```bash
# Format dirty files according to agent rules
vendor/bin/pint --dirty --format agent

# Or format all project files
vendor/bin/pint --format agent
```

---

## Postman API Client Setup

Ready-to-use Postman files are stored in [`postman/`](file:///C:/Users/ec.40021031/Documents/edscodes/myprojects/pickleball-booking-app/postman):
1. Import `postman/CourtFlex_API_v1.postman_collection.json`.
2. Import `postman/CourtFlex_Local.postman_environment.json`.
3. Select **"CourtFlex — Local Environment"** as the active environment.
4. Review [`docs/POSTMAN_TESTING_GUIDE.md`](file:///C:/Users/ec.40021031/Documents/edscodes/myprojects/pickleball-booking-app/docs/POSTMAN_TESTING_GUIDE.md) for detailed walkthroughs.
