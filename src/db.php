<?php
declare(strict_types=1);

function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;

    if (!extension_loaded('pdo_pgsql')) {
        throw new RuntimeException("Missing Postgres driver. Enable 'pdo_pgsql' (and usually 'pgsql') in php.ini.");
    }

    $host = getenv('PGHOST') ?: '127.0.0.1';
    $port = getenv('PGPORT') ?: '5432';
    $name = getenv('PGDATABASE') ?: 'WorkSchedule';
    $user = getenv('PGUSER') ?: 'postgres';
    $pass = getenv('PGPASSWORD') ?: '123456';

    $dsn = "pgsql:host=$host;port=$port;dbname=$name";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    ensureSchema($pdo);
    return $pdo;
}

function ensureSchema(PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS calendar_users (
            id BIGSERIAL PRIMARY KEY,
            username TEXT NOT NULL,
            password_hash TEXT NOT NULL
        );"
    );

    $pdo->exec("ALTER TABLE calendar_users ADD COLUMN IF NOT EXISTS is_admin BOOLEAN NOT NULL DEFAULT FALSE;");

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS calendar_categories (
            id BIGSERIAL PRIMARY KEY,
            user_id BIGINT REFERENCES calendar_users(id) ON DELETE CASCADE,
            name TEXT NOT NULL,
            color TEXT NOT NULL DEFAULT '#64748b'
        );"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS calendar_events (
            id BIGSERIAL PRIMARY KEY,
            user_id BIGINT NOT NULL REFERENCES calendar_users(id) ON DELETE CASCADE,
            title TEXT NOT NULL,
            description TEXT NOT NULL DEFAULT '',
            event_date DATE NOT NULL,
            category_id BIGINT,
            day INT,
            start_min INT NOT NULL,  
            end_min INT NOT NULL,    
            color TEXT NOT NULL
        );"
    );

    $pdo->exec("ALTER TABLE calendar_events ADD COLUMN IF NOT EXISTS description TEXT NOT NULL DEFAULT '';");
    $pdo->exec("ALTER TABLE calendar_events ADD COLUMN IF NOT EXISTS event_date DATE;");
    $pdo->exec("ALTER TABLE calendar_events ADD COLUMN IF NOT EXISTS category_id BIGINT;");

    $pdo->exec("ALTER TABLE calendar_categories ALTER COLUMN user_id DROP NOT NULL;");

    $pdo->exec("ALTER TABLE calendar_events ALTER COLUMN day DROP NOT NULL;");

    $pdo->exec(
        "DO $$
        BEGIN
            IF NOT EXISTS (
                SELECT 1
                FROM pg_constraint
                WHERE conname = 'calendar_events_category_id_fk'
            ) THEN
                ALTER TABLE calendar_events
                ADD CONSTRAINT calendar_events_category_id_fk
                FOREIGN KEY (category_id)
                REFERENCES calendar_categories(id)
                ON DELETE SET NULL;
            END IF;
        END $$;"
    );

    $pdo->exec(
        "UPDATE calendar_events
         SET event_date = (date_trunc('week', CURRENT_DATE)::date + COALESCE(day, 0))
         WHERE event_date IS NULL;"
    );

    $pdo->exec("ALTER TABLE calendar_events ALTER COLUMN event_date SET NOT NULL;");

    $pdo->exec(
        "WITH d AS (
            SELECT username, MIN(id) AS keep_id, ARRAY_AGG(id) AS ids
            FROM calendar_users
            GROUP BY username
            HAVING COUNT(*) > 1
        )
        UPDATE calendar_events e
        SET user_id = d.keep_id
        FROM d
        WHERE e.user_id = ANY(d.ids) AND e.user_id <> d.keep_id;"
    );
    $pdo->exec(
        "WITH d AS (
            SELECT username, MIN(id) AS keep_id
            FROM calendar_users
            GROUP BY username
            HAVING COUNT(*) > 1
        )
        DELETE FROM calendar_users u
        USING d
        WHERE u.username = d.username AND u.id <> d.keep_id;"
    );

    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS calendar_users_username_uq ON calendar_users(username);");

    // Back-compat: if an 'admin' user already exists, mark it as admin.
    // (We no longer infer admin purely from username in PHP.)
    $pdo->exec("UPDATE calendar_users SET is_admin = TRUE WHERE LOWER(username) = 'admin';");

    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS calendar_categories_global_name_uq ON calendar_categories(LOWER(name)) WHERE user_id IS NULL;");
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS calendar_categories_global_name_uq_name ON calendar_categories(name) WHERE user_id IS NULL;");

    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_calendar_events_user_date_start ON calendar_events(user_id, event_date, start_min);");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_calendar_events_user_category ON calendar_events(user_id, category_id);");
}
