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

        $count = 0;
        foreach ( $files as $file ) {
            if ( $file->isDir() ) {
                continue;
            }
            $file_path     = $file->getRealPath();
            $relative_path = $dir_basename . '/' . substr( $file_path, strlen( $entry['path'] ) + 1 );
            $zip->addFile( $file_path, $relative_path );
            $count++;
        }
        $zip->close();

        wt_log( "[+] [{$type}] {$slug} ({$entry['version']}, {$count} files)" );
        wt_log( "      -> " . $base_url . '/' . $archive_name );
        $packed++;
    }

    wt_log( '' );
    wt_log( "Done: {$packed}/{$total} archived in '{$folder_name}/'." );
    wt_log( 'Tip: remove the ' . $folder_name . '/ folder after downloading.' );

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
wt_err( "Unknown tool: {$cmd}" );
wt_usage();
exit( 2 );
