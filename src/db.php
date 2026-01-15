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

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS calendar_events (
            id BIGSERIAL PRIMARY KEY,
            user_id BIGINT NOT NULL REFERENCES calendar_users(id) ON DELETE CASCADE,
            title TEXT NOT NULL,
            day INT NOT NULL,        
            start_min INT NOT NULL,  
            end_min INT NOT NULL,    
            color TEXT NOT NULL
        );"
    );

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

    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_calendar_events_user_day_start ON calendar_events(user_id, day, start_min);");
}
