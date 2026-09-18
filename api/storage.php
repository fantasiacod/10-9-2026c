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

/**
 * السائق الافتراضي = sqlite.
 *
 * قبل هذا كان الافتراضي json، وملف db_config.json يعيش داخل data/ وهو
 * مجلد لا يُرفع مع الكود. فكل نشر جديد كان يبدأ بلا ملف إعدادات، أي
 * بالسائق json، فلا تُستخدم قاعدة البيانات أصلاً. الآن SQLite هي
 * الافتراضي، ولا نهبط إلى json إلا إذا كانت الاستضافة لا تدعم
 * pdo_sqlite — وهذا احتياط للطوارئ لا خيار دائم.
 */
function storage_default_driver()
{
    $available = storage_available_drivers();
    return !empty($available['sqlite']) ? 'sqlite' : 'json';
}

function storage_settings()
{
    $defaults = array('driver' => storage_default_driver());
    $path = storage_config_path();
    if (!file_exists($path)) {
        return $defaults;
    }
    $cfg = json_decode((string) @file_get_contents($path), true);
    if (!is_array($cfg) || empty($cfg['driver'])) {
        return $defaults;
    }
    // A config file left behind by an older build (mysql / supabase /
    // firebase ...) must never break the site: fall back to the default.
    if (!in_array($cfg['driver'], storage_known_drivers(), true)) {
        return $defaults;
    }
    // إعداد قديم مكتوب فيه json بينما الاستضافة تدعم SQLite: نرقّيه
    // تلقائياً حتى تصبح قاعدة البيانات هي المصدر كما هو مطلوب.
    if ($cfg['driver'] === 'json') {
        $available = storage_available_drivers();
        if (!empty($available['sqlite'])) {
            return array('driver' => 'sqlite');
        }
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
    // جدول الإعدادات: كل لون في صفّ مستقل، وهو المرجع النهائي للألوان.
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS settings ('
        . ' key TEXT PRIMARY KEY,'
        . ' value TEXT NOT NULL,'
        . ' updated_at INTEGER NOT NULL'
        . ')'
    );
    return $pdo;
}

// =================================================================
// جدول الألوان (settings) — المصدر الوحيد لإعدادات الثيم
// =================================================================

/**
 * مفاتيح الثيم التي تُحفظ في جدول settings كصفوف مستقلة.
 * القيم المرادفة لأسماء عامة:
 *   primary_color     => colorPrimary
 *   background_color  => colorBg
 *   foreground_color  => textColor
 *   secondary_color   => hfColor2  (اللون الثاني في تدرّج الهيدر والفوتر)
 */
function storage_theme_keys()
{
    return array(
        'colorPrimary', 'primaryColor', 'colorBg',
        'hfColor1', 'hfColor2',
        'textColor', 'cardTextColor', 'btnTextColor', 'adminCardBg',
        'decoration', 'decorationColor', 'decorationOpacity',
        'hfDecoration', 'hfDecorationColor', 'hfOpacity',
        'fontFamily',
    );
}

/** قراءة كل صفوف جدول settings كمصفوفة مفتاح => قيمة. */
function storage_settings_table_read(PDO $pdo)
{
    $out = array();
    try {
        $rows = $pdo->query('SELECT key, value FROM settings')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $out[$r['key']] = $r['value'];
        }
    } catch (Exception $e) {
        error_log('[settings_read] ' . $e->getMessage());
    }
    return $out;
}

/** كتابة مفاتيح الثيم الموجودة في $config إلى جدول settings. */
function storage_settings_table_write(PDO $pdo, array $config)
{
    $keys = storage_theme_keys();
    $now  = time();
    $stmt = $pdo->prepare(
        'INSERT INTO settings (key, value, updated_at) VALUES (?, ?, ?)'
        . ' ON CONFLICT (key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at'
    );
    foreach ($keys as $k) {
        if (array_key_exists($k, $config) && $config[$k] !== null && $config[$k] !== '') {
            $stmt->execute(array($k, (string) $config[$k], $now));
        }
    }
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
        if (!is_array($data)) {
            $data = array();
        }
        if (!isset($data['config']) || !is_array($data['config'])) {
            $data['config'] = array();
        }

        $theme = storage_settings_table_read($pdo);

        if (empty($theme)) {
            // ترحيل لمرة واحدة: قاعدة بيانات قديمة ألوانها ما زالت داخل
            // مستند JSON فقط. ننقلها إلى جدول settings كما هي، فلا يتغيّر
            // شكل الموقع ولا يُفقد أي لون اختاره صاحب الموقع.
            storage_settings_table_write($pdo, $data['config']);
            $theme = storage_settings_table_read($pdo);
        }

        // جدول settings هو المرجع: قيمه تعلو على ما في مستند JSON.
        foreach ($theme as $k => $v) {
            $data['config'][$k] = $v;
        }

        return $data;
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

        // الكتابتان معاً أو لا شيء: لا يجوز أن تُحفظ البطاقات ويفشل اللون.
        $pdo->beginTransaction();
        $stmt = $pdo->prepare(
            'INSERT INTO site_store (k, v) VALUES (?, ?) ON CONFLICT (k) DO UPDATE SET v = excluded.v'
        );
        $stmt->execute(array('site_data', $json));

        if (isset($data['config']) && is_array($data['config'])) {
            storage_settings_table_write($pdo, $data['config']);
        }
        $pdo->commit();

        // Local mirror: a plain-file backup of everything in the database.
        storage_write_json_file($data);
        return true;
    } catch (Exception $e) {
        if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
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
