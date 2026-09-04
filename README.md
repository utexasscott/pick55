# Pick55

NFL / NCAA football confidence-pick pool. Players pick spreads and over/unders each week and rank picks by confidence.

## Requirements

- PHP 7.3+
- MySQL 5.6+
- Apache 2 (mod_rewrite)
- Composer

PHP extensions: mysqli, PDO (pdo_mysql).

## Develop

1. Clone the repo:

   ```
   git clone https://github.com/utexasscott/pick55.git
   cd pick55
   composer install
   ```

2. Copy the database structure and/or data from live or a backup. (The schema is not in this repo.)

3. Copy `inc/_config.example.php` to `inc/_config.php` and edit for your environment (base URL, DB credentials, SendGrid API key).

4. Serve the repo root from Apache (e.g. `http://127.0.0.1/pick55/`).

See [CLAUDE.md](CLAUDE.md) for an overview of the code layout and conventions.
