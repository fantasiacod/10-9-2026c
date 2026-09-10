<?php
/**
 * Storage abstraction layer.
 * ------------------------------------------------------------
 * All site data (config, cards, stats, cardStats) is stored as a
 * single JSON document. This layer decides WHERE that document lives.
 *
 *   sqlite - data/site_data.db    (needs the pdo_sqlite extension)
 *            The only storage the control panel offers.
 *
 *   json   - data/site_data.json  (internal fallback + mirror)
 *            Not a choice the client makes: it is what keeps the
 *            public site alive when SQLite is unavailable, and it
 *            mirrors every SQLite write as a local backup.
 *
 * IMPORTANT: both files live inside data/. On hosting that does not
 * keep files permanently (Render free tier, Heroku, any ephemeral
 * filesystem) the folder is wiped on every restart and the settings
 * are lost with it. Use hosting with persistent storage.
 */

/** Drivers this build understands. Anything else is treated as json. */
function storage_known_drivers()
{
    return array('json', 'sqlite');
}

function storage_config_path()
{
    return __DIR__ . '/../data/db_config.json';
}

function storage_ensure_data_dir()
{
    $dir = __DIR__ . '/../data';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir;
}

function storage_settings()
{
    $defaults = array('driver' => 'json');
    $path = storage_config_path();
    if (!file_exists($path)) {
        return $defaults;
    }
    $cfg = json_decode((string) @file_get_contents($path), true);
    if (!is_array($cfg) || empty($cfg['driver'])) {
        return $defaults;
    }
    // A config file left behind by an older build (mysql / supabase /
    // firebase ...) must never break the site: fall back to json.
    if (!in_array($cfg['driver'], storage_known_drivers(), true)) {
        return $defaults;
    }
    return array('driver' => $cfg['driver']);
}

function storage_save_settings(array $settings)
{
    storage_ensure_data_dir();
    $driver = isset($settings['driver']) ? $settings['driver'] : 'json';
    if (!in_array($driver, storage_known_drivers(), true)) {
        $driver = 'json';
    }
    return @file_put_contents(
        storage_config_path(),
        json_encode(array('driver' => $driver), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    ) !== false;
}

// =================================================================
// SQLite
// =================================================================

function storage_db_path()
{
    return __DIR__ . '/../data/site_data.db';
}

function storage_pdo()
{
    $available = class_exists('PDO') ? PDO::getAvailableDrivers() : array();
    if (!in_array('sqlite', $available, true)) {
        throw new RuntimeException('استضافتك لا تدعم SQLite (إضافة pdo_sqlite غير مفعّلة).');
    }
    storage_ensure_data_dir();

    $pdo = new PDO('sqlite:' . storage_db_path());
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // WAL keeps readers working while a write is in progress, which
    // matters because visit counters write on every page view.
    @$pdo->exec('PRAGMA journal_mode = WAL');
    @$pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec('CREATE TABLE IF NOT EXISTS site_store (k TEXT PRIMARY KEY, v TEXT NOT NULL)');
    return $pdo;
}

// =================================================================
// Public read / write
// =================================================================

function storage_read()
{
    $s = storage_settings();

    if ($s['driver'] !== 'sqlite') {
        return storage_read_json_file();
    }

    try {
        $pdo = storage_pdo();
        $stmt = $pdo->prepare('SELECT v FROM site_store WHERE k = ?');
        $stmt->execute(array('site_data'));
        $row = $stmt->fetchColumn();

        if ($row === false || $row === null) {
            // First read after switching: seed the database from the
            // local mirror so the site never appears to be wiped.
            $seed = storage_read_json_file();
            storage_write($seed);
            return $seed;
        }

        $data = json_decode((string) $row, true);
        return is_array($data) ? $data : array();
    } catch (Exception $e) {
        error_log('[storage_read] ' . $e->getMessage());
        // Never let the public site go blank because the database blipped.
        return storage_read_json_file();
    }
}

function storage_write(array $data)
{
    $s = storage_settings();

    if ($s['driver'] !== 'sqlite') {
        return storage_write_json_file($data);
    }

    try {
        $pdo  = storage_pdo();
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $stmt = $pdo->prepare(
            'INSERT INTO site_store (k, v) VALUES (?, ?) ON CONFLICT (k) DO UPDATE SET v = excluded.v'
        );
        $stmt->execute(array('site_data', $json));

        // Local mirror: a plain-file backup of everything in the database.
        storage_write_json_file($data);
        return true;
    } catch (Exception $e) {
        error_log('[storage_write] ' . $e->getMessage());
        return storage_write_json_file($data);
    }
}

function storage_read_json_file()
{
    $file = __DIR__ . '/../data/site_data.json';
    if (!file_exists($file)) {
        return array();
    }
    $data = json_decode((string) @file_get_contents($file), true);
    return is_array($data) ? $data : array();
}

function storage_write_json_file(array $data)
{
    storage_ensure_data_dir();
    return @file_put_contents(
        __DIR__ . '/../data/site_data.json',
        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        LOCK_EX
    ) !== false;
}

/**
 * Which drivers can this particular host actually run?
 * The control panel uses this to warn when SQLite is missing.
 */
function storage_available_drivers()
{
    $pdo = class_exists('PDO') ? PDO::getAvailableDrivers() : array();
    return array(
        'sqlite' => in_array('sqlite', $pdo, true),
    );
}
