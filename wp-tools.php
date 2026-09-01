#!/usr/bin/env php
<?php
/** WP Tools — WordPress admin toolkit (CLI / curl runner). */

if ( php_sapi_name() !== 'cli' ) {
    fwrite( STDERR, "Direct access not allowed.\n" );
    exit( 1 );
}

// Guard must stay the first executable statement: below PHP 7.2 the script
// would otherwise die with a parse error before it can explain itself.
if ( PHP_VERSION_ID < 70200 ) {
    fwrite( STDERR, 'WP Tools requires PHP 7.2 or newer (running ' . PHP_VERSION . ").\n" );
    exit( 1 );
}

// Minimal $_SERVER so WP doesn't emit notices under CLI.
$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST']   ?? 'localhost';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';

/* ---------------------------------------------------------------------------
 * Common helpers (wt_*)
 * ------------------------------------------------------------------------ */

function wt_log( string $msg ): void {
    echo $msg . "\n";
}

function wt_err( string $msg ): void {
    fwrite( STDERR, $msg . "\n" );
}
/** Seconds elapsed since $start, formatted. */
function wt_elapsed( float $start ): string {
    return number_format( microtime( true ) - $start, 1 ) . 's';
}

/** In-place progress line (overwrites via \r). Call with $done=true to clear it. */
function wt_progress( string $label, int $i, int $total, float $start, bool $done = false ): void {
    if ( $done ) {
        echo "\r\033[K";
        flush();
        return;
    }
    $pct = $total > 0 ? (int) round( $i / $total * 100 ) : 100;
    $el  = number_format( microtime( true ) - $start, 1 );
    echo "\r\033[K{$label} {$i}/{$total} ({$pct}%) — {$el}";
    flush();
}

/** Count files under $root, optionally filtered by $include(rel)->bool. */
function wt_count_tree( string $root, $include = null ): int {
    $n    = 0;
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ( $files as $file ) {
        if ( $file->isDir() ) {
            continue;
        }
        $rel = substr( $file->getRealPath(), strlen( $root ) + 1 );
        if ( $include === null || $include( $rel ) ) {
            $n++;
        }
    }
    return $n;
}

/** Should a site file (relative path under the WP root) be backed up? */
function wt_include_backup_file( string $rel ): bool {
    $first = strtok( $rel, '/' );
    if ( $first === '_backups' || $first === '_packages' ) {
        return false;
    }
    $parent = dirname( $rel );
    foreach ( explode( '/', $parent ) as $seg ) {
        if ( $seg !== '' && $seg !== '.' && $seg[0] === '.' ) {
            return false;
        }
    }
    return true;
}

/** Walk up from cwd to locate the WordPress root; '' when not found. */
function wt_find_wp_root(): string {
    $root = getcwd();
    while ( $root !== '/' && ! file_exists( $root . '/wp-load.php' ) ) {
        $root = dirname( $root );
    }
    if ( $root === '/' && ! file_exists( $root . '/wp-load.php' ) ) {
        return '';
    }
    return $root;
}

/** Boot WordPress; exits 1 when no WP root is found. Returns the WP root. */
function wt_boot(): string {
    $root = wt_find_wp_root();
    if ( $root === '' ) {
        wt_err( 'Error: WordPress root not found (no wp-load.php above cwd).' );
        exit( 1 );
    }
    require $root . '/wp-load.php';
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    require_once ABSPATH . 'wp-includes/theme.php';
    return $root;
}

/* ---------------------------------------------------------------------------
 * Tool registry (for help / list)
 * ------------------------------------------------------------------------ */

const WT_TOOLS = [
    'packager' => 'Package installed plugins & themes into ZIP archives',
    'backupper' => 'Backup site files & database into a ZIP archive',
    'wp-info'   => 'Show WordPress stack & configuration info',
    'api'       => 'Show REST API context & table schemas',
];

function wt_usage(): void {
    wt_log( 'WP Tools — WordPress admin toolkit' );
    wt_log( '' );
    wt_log( 'Usage: curl -sSL <url>/wp-tools.php | php -- <tool> [args]' );
    wt_log( '' );
    wt_log( 'Tools:' );
    foreach ( WT_TOOLS as $name => $desc ) {
        wt_log( sprintf( '  %-12s %s', $name, $desc ) );
    }
    wt_log( '' );
    wt_log( '  --list, -l   Show available tools' );
    wt_log( '  --help, -h   Show this help' );
}

/* ---------------------------------------------------------------------------
 * Tool: packager
 * ------------------------------------------------------------------------ */

/** List installed plugins: slug => [name, version, path]. */
function packager_list_plugins(): array {
    $out = [];
    foreach ( get_plugins() as $path => $data ) {
        $slug = dirname( $path );
        if ( $slug === '.' ) {
            $slug = basename( $path, '.php' );
        }
        $out[ $slug ] = [
            'name'    => $data['Name'],
            'version' => $data['Version'] ?: '0.0.0',
            'path'    => WP_PLUGIN_DIR . '/' . dirname( $path ),
        ];
    }
    return $out;
}

/** List installed themes: slug => [name, version, path]. */
function packager_list_themes(): array {
    $out = [];
    foreach ( wp_get_themes() as $slug => $theme ) {
        $out[ $slug ] = [
            'name'    => $theme->get( 'Name' ),
            'version' => $theme->get( 'Version' ) ?: '0.0.0',
            'path'    => $theme->get_stylesheet_directory(),
        ];
    }
    return $out;
}

function packager_print_lists( array $plugins, array $themes ): void {
    $p_slugs = array_keys( $plugins );
    $t_slugs = array_keys( $themes );
    $rows    = max( count( $p_slugs ), count( $t_slugs ), 1 );

    wt_log( '' );
    wt_log( '=== AVAILABLE PLUGINS AND THEMES ===' );
    wt_log( '' );
    printf( "%-42s | %-42s\n", 'PLUGINS (slug / version)', 'THEMES (slug / version)' );
    printf( "%-42s-+-%-42s\n", str_repeat( '-', 42 ), str_repeat( '-', 42 ) );

    for ( $i = 0; $i < $rows; $i++ ) {
        $p = isset( $p_slugs[ $i ] )
            ? $p_slugs[ $i ] . ' (' . $plugins[ $p_slugs[ $i ] ]['version'] . ')'
            : '';
        $t = isset( $t_slugs[ $i ] )
            ? $t_slugs[ $i ] . ' (' . $themes[ $t_slugs[ $i ] ]['version'] . ')'
            : '';
        printf( "%-42s | %-42s\n", $p, $t );
    }
}

function packager_usage(): void {
    wt_log( 'Usage:' );
    wt_log( '  curl -sSL <url>/wp-tools.php | php -- packager <slug1> [slug2 ...]' );
    wt_log( '  curl -sSL <url>/wp-tools.php | php -- packager --list' );
    wt_log( '  curl -sSL <url>/wp-tools.php | php -- packager --all' );
}

function tool_packager( array $args ): int {
    $requested = [];
    $all       = false;

    foreach ( $args as $arg ) {
        switch ( $arg ) {
            case '--help':
            case '-h':
            case '-?':
                packager_usage();
                return 0;
            case '--list':
            case '-l':
                wt_boot();
                packager_print_lists( packager_list_plugins(), packager_list_themes() );
                return 0;
            case '--all':
            case '-a':
                $all = true;
                break;
            default:
                $requested[] = $arg;
        }
    }

    $wp_root = wt_boot();

    if ( ! class_exists( 'ZipArchive' ) ) {
        wt_err( 'Error: PHP ZipArchive extension is not installed.' );
        return 1;
    }

    $plugins = packager_list_plugins();
    $themes  = packager_list_themes();

    if ( $all ) {
        $requested = array_merge( array_keys( $plugins ), array_keys( $themes ) );
    }

    if ( empty( $requested ) ) {
        packager_print_lists( $plugins, $themes );
        wt_log( '' );
        packager_usage();
        return 0;
    }

    /* Export directory */

    $folder_name = '_packages';
    $export_dir  = rtrim( $wp_root, '/' ) . '/' . $folder_name;

    if ( ! is_dir( $export_dir ) && ! mkdir( $export_dir, 0755, true ) ) {
        wt_err( "Error: cannot create export dir '{$export_dir}'." );
        return 1;
    }
    if ( ! is_writable( $export_dir ) ) {
        wt_err( "Error: export dir '{$export_dir}' is not writable." );
        return 1;
    }

    /* Packaging */

    $base_url = untrailingslashit( site_url() ) . '/' . $folder_name;

    wt_log( '' );
    wt_log( "=== GENERATING ARCHIVES ({$folder_name}/) ===" );
    wt_log( '' );

    $packed = 0;
    $total  = count( $requested );

    foreach ( $requested as $slug ) {
        $entry = null;
        $type  = '';

        if ( isset( $plugins[ $slug ] ) ) {
            $entry = $plugins[ $slug ];
            $type  = 'plugin';
        } elseif ( isset( $themes[ $slug ] ) ) {
            $entry = $themes[ $slug ];
            $type  = 'theme';
        }

        if ( ! $entry ) {
            wt_log( "[!] Not found: {$slug}" );
            continue;
        }

        $slug_clean   = preg_replace( '/[^A-Za-z0-9._-]/', '-', $slug );
        $archive_name = "{$slug_clean}-{$entry['version']}.zip";
        $archive_file = $export_dir . '/' . $archive_name;

        $zip = new ZipArchive();
        if ( $zip->open( $archive_file, ZipArchive::CREATE | ZipArchive::OVERWRITE ) !== true ) {
            wt_log( "[!] Cannot create archive: {$slug}" );
            continue;
        }

        $dir_basename = basename( $entry['path'] );
        $files        = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $entry['path'], FilesystemIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        wt_log( "  Packing {$slug}…" );
        $p_start = microtime( true );
        $p_total = wt_count_tree( $entry['path'] );
        $p_step  = max( 1, (int) ceil( $p_total / 100 ) );

        $count = 0;
        foreach ( $files as $file ) {
            if ( $file->isDir() ) {
                continue;
            }
            $file_path     = $file->getRealPath();
            $relative_path = $dir_basename . '/' . substr( $file_path, strlen( $entry['path'] ) + 1 );
            $zip->addFile( $file_path, $relative_path );
            $count++;
            if ( $count % $p_step === 0 ) {
                wt_progress( '  files', $count, $p_total, $p_start );
            }
        }
        wt_progress( '  files', 0, 0, $p_start, true );
        $zip->close();

        wt_log( "[+] [{$type}] {$slug} ({$entry['version']}, {$count} files, " . wt_elapsed( $p_start ) . ")" );
        wt_log( "      -> " . $base_url . '/' . $archive_name );
        $packed++;
    }

    wt_log( '' );
    wt_log( "Done: {$packed}/{$total} archived in '{$folder_name}/'." );
    wt_log( 'Tip: remove the ' . $folder_name . '/ folder after downloading.' );

    return 0;
}

/* ---------------------------------------------------------------------------
 * Tool: backupper
 * ------------------------------------------------------------------------ */

function backupper_usage(): void {
    wt_log( 'Usage:' );
    wt_log( '  curl -sSL <url>/wp-tools.php | php -- backupper [db|files|all]' );
    wt_log( '  curl -sSL <url>/wp-tools.php | php -- backupper --list' );
    wt_log( '' );
    wt_log( 'Options:' );
    wt_log( '  db          Backup database only' );
    wt_log( '  files       Backup site files only' );
    wt_log( '  all         Backup files and database (default)' );
    wt_log( '  --list, -l  List existing backups in _backups/' );
    wt_log( '  --help, -h  Show this help' );
    wt_log( '' );
    wt_log( 'Backs up site files plus a SQL dump of the database into a ZIP' );
    wt_log( 'archive inside _backups/, printing the download URL.' );
}

/** Stream a SQL dump of the WP database into $path. Returns false on error. */
function backupper_db_dump( $wpdb, string $path, float $start ): bool {
    $fh = fopen( $path, 'w' );
    if ( ! $fh ) {
        return false;
    }
    fwrite( $fh, "-- WP Tools database backup\n-- Generated: " . date( 'c' ) . "\n" );
    fwrite( $fh, "-- DB: {$wpdb->dbname}\n\n" );
    fwrite( $fh, "SET NAMES {$wpdb->charset};\n" );
    fwrite( $fh, "SET FOREIGN_KEY_CHECKS = 0;\n\n" );

    $tables = $wpdb->get_col( 'SHOW TABLES' );
    $total  = count( $tables );
    $t      = 0;
    foreach ( $tables as $table ) {
        $t++;
        wt_progress( '[db]', $t, $total, $start );
        $create = $wpdb->get_row( "SHOW CREATE TABLE `{$table}`", ARRAY_N );
        if ( ! $create ) {
            continue;
        }
        fwrite( $fh, "DROP TABLE IF EXISTS `{$table}`;\n" );
        fwrite( $fh, $create[1] . ";\n\n" );

        $rows = $wpdb->get_results( "SELECT * FROM `{$table}`", ARRAY_A );
        if ( ! $rows ) {
            continue;
        }
        $cols = array_keys( $rows[0] );
        fwrite( $fh, "INSERT INTO `{$table}` (`" . implode( '`, `', $cols ) . "`) VALUES\n" );
        $lines = [];
        foreach ( $rows as $row ) {
            $vals = [];
            foreach ( $cols as $col ) {
                $v = $row[ $col ];
                if ( $v === null ) {
                    $vals[] = 'NULL';
                } else {
                    $vals[] = "'" . esc_sql( $v ) . "'";
                }
            }
            $lines[] = '(' . implode( ', ', $vals ) . ')';
        }
        fwrite( $fh, implode( ",\n", $lines ) . ";\n\n" );
    }

    fwrite( $fh, "SET FOREIGN_KEY_CHECKS = 1;\n" );
    fclose( $fh );
    return true;
}

function tool_backupper( array $args ): int {
    global $wpdb;

    $list = false;
    $mode = 'all';

    foreach ( $args as $arg ) {
        switch ( $arg ) {
            case '--help':
            case '-h':
            case '-?':
                backupper_usage();
                return 0;
            case '--list':
            case '-l':
                $list = true;
                break;
            case 'db':
                $mode = 'db';
                break;
            case 'files':
                $mode = 'files';
                break;
            case 'all':
                $mode = 'all';
                break;
            default:
                wt_err( "Unknown option: {$arg}" );
                backupper_usage();
                return 2;
        }
    }

    $wp_root = wt_boot();

    if ( ! class_exists( 'ZipArchive' ) ) {
        wt_err( 'Error: PHP ZipArchive extension is not installed.' );
        return 1;
    }

    $folder_name = '_backups';
    $export_dir  = rtrim( $wp_root, '/' ) . '/' . $folder_name;
    $base_url    = untrailingslashit( site_url() ) . '/' . $folder_name;

    if ( $list ) {
        if ( ! is_dir( $export_dir ) ) {
            wt_log( 'No backups yet (' . $folder_name . '/ does not exist).' );
            return 0;
        }
        wt_log( '=== EXISTING BACKUPS ===' );
        wt_log( '' );
        $files = glob( $export_dir . '/*.zip' );
        if ( ! $files ) {
            wt_log( '  (none)' );
            return 0;
        }
        foreach ( $files as $file ) {
            $name = basename( $file );
            $size = number_format( filesize( $file ) / 1048576, 2, '.', '' );
            wt_log( sprintf( '  %-40s %8s MB', $name, $size ) );
            wt_log( '      -> ' . $base_url . '/' . $name );
        }
        return 0;
    }

    if ( ! is_dir( $export_dir ) && ! mkdir( $export_dir, 0755, true ) ) {
        wt_err( "Error: cannot create export dir '{$export_dir}'." );
        return 1;
    }
    if ( ! is_writable( $export_dir ) ) {
        wt_err( "Error: export dir '{$export_dir}' is not writable." );
        return 1;
    }

    $stamp        = date( 'Ymd-His' );
    $archive_name = 'backup-' . $stamp . '.zip';
    $archive_file = $export_dir . '/' . $archive_name;
    $db_sql       = $export_dir . '/db-' . $stamp . '.sql';

    $start    = microtime( true );
    $do_db    = ( $mode === 'all' || $mode === 'db' );
    $do_files = ( $mode === 'all' || $mode === 'files' );
    $stages   = ( $do_db && $do_files ) ? 2 : 1;
    $stage    = 0;

    wt_log( '' );
    wt_log( "=== BACKUP ({$folder_name}/) ===" );
    wt_log( '' );

    if ( $do_db ) {
        $stage++;
        wt_log( "[{$stage}/{$stages}] Database dump…" );
        if ( ! backupper_db_dump( $wpdb, $db_sql, $start ) ) {
            wt_progress( '[db]', 0, 0, $start, true );
            wt_err( "Error: cannot write database dump '{$db_sql}'." );
            return 1;
        }
        wt_progress( '[db]', 0, 0, $start, true );
        wt_log( '[+] [db] ' . basename( $db_sql ) . ' — ' . wt_elapsed( $start ) );
    }

    $zip = new ZipArchive();
    if ( $zip->open( $archive_file, ZipArchive::CREATE | ZipArchive::OVERWRITE ) !== true ) {
        wt_err( "Error: cannot create archive '{$archive_file}'." );
        if ( $do_db ) {
            @unlink( $db_sql );
        }
        return 1;
    }
    if ( $do_db ) {
        $zip->addFile( $db_sql, basename( $db_sql ) );
    }

    if ( $do_files ) {
        $stage++;
        wt_log( "[{$stage}/{$stages}] Adding site files…" );
        $total_files = wt_count_tree( $wp_root, 'wt_include_backup_file' );
        $step        = max( 1, (int) ceil( $total_files / 100 ) );
        $count       = 0;
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $wp_root, FilesystemIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ( $files as $file ) {
            if ( $file->isDir() ) {
                continue;
            }
            $file_path = $file->getRealPath();
            $rel       = substr( $file_path, strlen( $wp_root ) + 1 );
            if ( ! wt_include_backup_file( $rel ) ) {
                continue;
            }
            $zip->addFile( $file_path, $rel );
            $count++;
            if ( $count % $step === 0 ) {
                wt_progress( '[files]', $count, $total_files, $start );
            }
        }
        wt_progress( '[files]', 0, 0, $start, true );
    }
    $zip->close();
    if ( $do_db ) {
        @unlink( $db_sql );
    }

    $size = number_format( filesize( $archive_file ) / 1048576, 2, '.', '' );
    if ( $do_files ) {
        wt_log( '[+] [files] ' . $count . ' files' );
    }
    wt_log( '[+] ' . $archive_name . ' (' . $size . ' MB)' );
    wt_log( '      -> ' . $base_url . '/' . $archive_name );
    wt_log( '' );
    wt_log( 'Done in ' . wt_elapsed( $start ) . '. Tip: remove the ' . $folder_name . '/ folder after downloading.' );

    return 0;
}

/* ---------------------------------------------------------------------------
 * Tool: wp-info
 * ------------------------------------------------------------------------ */

function wp_info_usage(): void {
    wt_log( 'Usage:' );
    wt_log( '  curl -sSL <url>/wp-tools.php | php -- wp-info' );
    wt_log( '' );
    wt_log( 'Shows the WordPress stack: versions, PHP config, limits, statuses,' );
    wt_log( 'active plugins and themes.' );
}

function tool_wp_info( array $args ): int {
    foreach ( $args as $arg ) {
        if ( in_array( $arg, [ '--help', '-h', '-?' ], true ) ) {
            wp_info_usage();
            return 0;
        }
        wt_err( "Unknown option: {$arg}" );
        wp_info_usage();
        return 2;
    }

    wt_boot();

    echo "\e[1;33m⏳ Загрузка...\e[0m";

    global $wp_version, $wpdb;

    $db_version = $wpdb->get_var( 'SELECT VERSION()' );
    $db_type    = strpos( $db_version, 'MariaDB' ) !== false ? 'MariaDB' : 'MySQL';
    $db_v       = explode( '-', $db_version )[0];
    $php_v      = phpversion();
    $ms         = is_multisite() ? " \e[38;5;244m(Multisite)\e[0m" : '';

    $out = "\e[1;36m🖥️ Стэк:\e[0m\n";
    $out .= "WordPress {$wp_version}{$ms}, PHP {$php_v}, DB {$db_type} {$db_v}\n\n";

    $mem = ini_get( 'memory_limit' );
    $upl = ini_get( 'upload_max_filesize' );
    $src = 'CLI';
    $v   = explode( '.', $php_v );
    $v1  = isset( $v[1] ) ? $v[1] : '';
    $ini_paths = [
        "/usr/local/lsws/lsphp{$v[0]}{$v1}/etc/php/{$v[0]}.{$v1}/litespeed/php.ini",
        "/etc/php/{$v[0]}.{$v1}/fpm/php.ini",
        "/etc/php/{$v[0]}.{$v1}/apache2/php.ini",
    ];
    foreach ( $ini_paths as $path ) {
        if ( file_exists( $path ) ) {
            $ini = file_get_contents( $path );
            if ( preg_match( '/^memory_limit\s*=\s*([^\s;]+)/m', $ini, $m ) ) {
                $mem = trim( $m[1] );
            }
            if ( preg_match( '/^upload_max_filesize\s*=\s*([^\s;]+)/m', $ini, $m ) ) {
                $upl = trim( $m[1] );
            }
            $src = strpos( $path, 'lsws' ) !== false ? 'Web/OLS' : 'Web';
            break;
        }
    }

    $debug   = ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ? "\e[31mOn\e[0m" : "\e[32mOff\e[0m";
    $cache   = wp_using_ext_object_cache() ? "\e[32mActive\e[0m" : "\e[38;5;244mInactive\e[0m";
    $cron    = ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) ? "\e[32mServer\e[0m" : "\e[33mWP-Cron\e[0m";
    $prefix  = $wpdb->prefix;
    $charset = $wpdb->charset;
    $abspath = ABSPATH;
    $php_bin = PHP_BINARY;
    $tz      = wp_timezone_string();
    $loc     = get_locale();

    $out .= "\e[1;35m⚙️ Среди и Конфиг:\e[0m\n";
    $out .= "  Лимиты:  Mem \e[38;5;244m{$mem}\e[0m, Upload \e[38;5;244m{$upl}\e[0m \e[38;5;244m[{$src}]\e[0m\n";
    $out .= "  Статусы: Debug {$debug}, Cache {$cache}, Cron {$cron}\n";
    $out .= "  Локаль:  TZ \e[38;5;244m{$tz}\e[0m, Lang \e[38;5;244m{$loc}\e[0m\n";
    $out .= "  База:    Prefix \e[38;5;244m{$prefix}\e[0m, Charset \e[38;5;244m{$charset}\e[0m\n";
    $out .= "  Пути:    WP \e[38;5;244m{$abspath}\e[0m\n";
    $out .= "           PHP \e[38;5;244m{$php_bin}\e[0m\n\n";

    $out .= "\e[1;32m🔌 Плагины:\e[0m\n";
    if ( ! function_exists( 'get_plugins' ) ) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    $all_plugins    = get_plugins();
    $active_plugins = (array) get_option( 'active_plugins', array() );
    foreach ( $active_plugins as $plugin ) {
        if ( isset( $all_plugins[ $plugin ] ) ) {
            $slug = dirname( $plugin );
            if ( $slug === '.' ) {
                $slug = basename( $plugin, '.php' );
            }
            $ver = $all_plugins[ $plugin ]['Version'];
            $out .= "  {$slug} \e[38;5;244m{$ver}\e[0m\n";
        }
    }

    $out .= "\n\e[1;34m🎨 Темы:\e[0m\n";
    $theme    = wp_get_theme();
    $act_name = $theme->get_stylesheet();
    $act_v    = $theme->get( 'Version' );
    $parent   = $theme->parent();
    if ( $parent ) {
        $par_name = $parent->get_stylesheet();
        $par_v    = $parent->get( 'Version' );
        $out .= "  🟢 {$act_name} \e[38;5;244m{$act_v} (child)\e[0m\n";
        $out .= "     {$par_name} \e[38;5;244m{$par_v} (parent)\e[0m\n";
    } else {
        $out .= "  🟢 {$act_name} \e[38;5;244m{$act_v}\e[0m\n";
    }

    echo "\r\033[K" . $out;

    return 0;
}

/* ---------------------------------------------------------------------------
 * Tool: api
 * ------------------------------------------------------------------------ */

function api_usage(): void {
    wt_log( 'Usage:' );
    wt_log( '  curl -sSL <url>/wp-tools.php | php -- api' );
    wt_log( '  curl -sSL <url>/wp-tools.php | php -- api <search>' );
    wt_log( '' );
    wt_log( 'Without arguments: global API context (locale/time, Bookly addons,' );
    wt_log( 'DB tables, custom REST namespaces). With <search>: schema of all' );
    wt_log( 'tables matching *<search>*.');
}

function tool_api( array $args ): int {
    $search = null;
    foreach ( $args as $arg ) {
        if ( in_array( $arg, [ '--help', '-h', '-?' ], true ) ) {
            api_usage();
            return 0;
        }
        if ( $search === null ) {
            $search = $arg;
        } else {
            wt_err( "Unexpected argument: {$arg}" );
            api_usage();
            return 2;
        }
    }

    wt_boot();

    echo "\e[1;33m⏳ Сбор данных...\e[0m";

    global $wpdb, $wp_rest_server;

    if ( $search !== null ) {
        $out = "\e[1;35m🔍 Поиск таблиц по маске: \e[38;5;244m*{$search}*\e[0m\n\n";

        $like   = '%' . $wpdb->esc_like( $search ) . '%';
        $tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );

        if ( $tables ) {
            foreach ( $tables as $table ) {
                $status = $wpdb->get_row( "SHOW TABLE STATUS LIKE '{$table}'" );
                $rows   = $status ? $status->Rows : 0;
                $out .= "\e[1;36m🗄️ Таблица: \e[1;37m{$table} \e[38;5;244m({$rows} rows)\e[0m\n";

                $columns = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}`" );
                foreach ( $columns as $col ) {
                    $field = str_pad( $col->Field, 25 );
                    $type  = str_pad( $col->Type, 18 );
                    $null  = $col->Null === 'YES' ? 'NULL' : 'NOT NULL';
                    $null  = str_pad( $null, 9 );
                    $key   = str_pad( $col->Key, 4 );
                    $extra = $col->Extra;
                    $color = ( $key === 'PRI ' || $key === 'MUL ' ) ? "\e[1;33m" : "\e[38;5;244m";
                    $out .= "  \e[32m{$field}\e[0m {$color}{$type} {$null} {$key} {$extra}\e[0m\n";
                }
                $out .= "\n";
            }
        } else {
            $out .= "  \e[31mТаблицы не найдены\e[0m\n";
        }
    } else {
        $tz = wp_timezone_string();
        $loc = get_locale();
        $df  = get_option( 'date_format' );
        $tf  = get_option( 'time_format' );

        $out = "\e[1;35m📅 Локаль и Время:\e[0m\n";
        $out .= "  Timezone: \e[38;5;244m{$tz}\e[0m, Locale: \e[38;5;244m{$loc}\e[0m\n";
        $out .= "  Format:   Date [\e[38;5;244m{$df}\e[0m], Time [\e[38;5;244m{$tf}\e[0m]\n\n";

        $out .= "\e[1;34m🧩 Аддоны Bookly:\e[0m\n";
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $all_plugins    = get_plugins();
        $active_plugins = (array) get_option( 'active_plugins', array() );
        $bookly_found   = false;
        foreach ( $active_plugins as $plugin ) {
            if ( stripos( $plugin, 'bookly' ) !== false && isset( $all_plugins[ $plugin ] ) ) {
                $slug = dirname( $plugin );
                if ( $slug === '.' ) {
                    $slug = basename( $plugin, '.php' );
                }
                $ver = $all_plugins[ $plugin ]['Version'];
                $out .= "  {$slug} \e[38;5;244m{$ver}\e[0m\n";
                $bookly_found = true;
            }
        }
        if ( ! $bookly_found ) {
            $out .= "  \e[38;5;244mНе найдено\e[0m\n";
        }

        $out .= "\n\e[1;36m🗄️ Таблицы БД:\e[0m\n";
        $tables = $wpdb->get_results( 'SHOW TABLE STATUS' );
        if ( $tables ) {
            foreach ( $tables as $t ) {
                $name = str_replace( $wpdb->prefix, '', $t->Name );
                $rows = $t->Rows;
                $out .= "  {$name} \e[38;5;244m({$rows} rows)\e[0m\n";
            }
        }

        $out .= "\n\e[1;32m🌐 Кастомные API Namespaces:\e[0m\n";
        if ( empty( $wp_rest_server ) ) {
            $wp_rest_server = new WP_REST_Server();
            do_action( 'rest_api_init', $wp_rest_server );
        }
        $namespaces = $wp_rest_server->get_namespaces();
        $custom_ns  = array_filter( $namespaces, function ( $ns ) {
            return ! in_array( $ns, [ 'oembed/1.0', 'wp/v2', 'wp-site-health/v1', 'wp-block-editor/v1' ], true );
        } );
        if ( $custom_ns ) {
            foreach ( $custom_ns as $ns ) {
                $out .= "  /{$ns}\n";
            }
        } else {
            $out .= "  \e[38;5;244mНет\e[0m\n";
        }
    }

    echo "\r\033[K" . $out;

    return 0;
}

/* ---------------------------------------------------------------------------
 * Dispatcher
 * ------------------------------------------------------------------------ */

$cmd  = $argv[1] ?? null;
$rest = array_slice( $argv, 2 );

if ( $cmd === null || in_array( $cmd, [ 'help', '--help', '-h', '?' ], true ) ) {
    wt_usage();
    exit( 0 );
}
if ( in_array( $cmd, [ 'list', '--list', '-l' ], true ) ) {
    wt_log( 'Available tools:' );
    foreach ( WT_TOOLS as $name => $desc ) {
        wt_log( sprintf( '  %-12s %s', $name, $desc ) );
    }
    exit( 0 );
}
if ( $cmd === 'packager' || $cmd === 'pkg' ) {
    exit( tool_packager( $rest ) );
}
if ( $cmd === 'backupper' || $cmd === 'backup' ) {
    exit( tool_backupper( $rest ) );
}
if ( $cmd === 'wp-info' || $cmd === 'info' || $cmd === 'stack' ) {
    exit( tool_wp_info( $rest ) );
}
if ( $cmd === 'api' ) {
    exit( tool_api( $rest ) );
}
wt_err( "Unknown tool: {$cmd}" );
wt_usage();
exit( 2 );
