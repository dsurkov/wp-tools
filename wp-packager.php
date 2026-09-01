#!/usr/bin/env php
<?php
/**
 * wp-packager — WordPress Plugin & Theme Packager
 *
 * Stateless CLI runner: stream it with curl into php directly on a
 * WordPress server. It locates the WP install, lists installed plugins
 * and themes, then packages the requested ones into ZIP archives inside
 * a web-accessible `_packages/` folder, printing download URLs.
 *
 *   curl -sSL https://host/path/wp-packager.php | php -- <slug> [slug...]
 *
 * No installation required: the script is never written to disk.
 */

if ( php_sapi_name() !== 'cli' ) {
    fwrite( STDERR, "Direct access not allowed.\n" );
    exit( 1 );
}

// Minimal $_SERVER so WP doesn't emit notices under CLI.
$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST']   ?? 'localhost';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';

/* ---------------------------------------------------------------------------
 * Helpers
 * ------------------------------------------------------------------------ */

function packager_log( string $msg ): void {
    echo $msg . "\n";
}

function packager_err( string $msg ): void {
    fwrite( STDERR, $msg . "\n" );
}

/** Walk up from cwd to locate the WordPress root. */
function packager_find_wp_root(): string {
    $root = getcwd();
    while ( $root !== '/' && ! file_exists( $root . '/wp-load.php' ) ) {
        $root = dirname( $root );
    }
    return $root;
}

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

    packager_log( '' );
    packager_log( '=== AVAILABLE PLUGINS AND THEMES ===' );
    packager_log( '' );
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
    packager_log( 'Usage:' );
    packager_log( '  curl -sSL <url>/wp-packager.php | php -- <slug1> [slug2 ...]' );
    packager_log( '' );
    packager_log( 'Options:' );
    packager_log( '  --list, -l    Show installed plugins & themes' );
    packager_log( '  --all, -a     Package every plugin and theme' );
    packager_log( '  --help, -h    Show this help' );
    packager_log( '' );
    packager_log( 'Examples:' );
    packager_log( '  php -- woocommerce            # package one plugin' );
    packager_log( '  php -- --list                 # list what is installed' );
    packager_log( '  php -- --all                  # package everything' );
}

/* ---------------------------------------------------------------------------
 * Bootstrap
 * ------------------------------------------------------------------------ */

$wp_root = packager_find_wp_root();
if ( $wp_root === '/' || ! file_exists( $wp_root . '/wp-load.php' ) ) {
    packager_err( 'Error: WordPress root not found (no wp-load.php above cwd).' );
    exit( 1 );
}

require $wp_root . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-includes/theme.php';

if ( ! class_exists( 'ZipArchive' ) ) {
    packager_err( 'Error: PHP ZipArchive extension is not installed.' );
    exit( 1 );
}

$plugins = packager_list_plugins();
$themes  = packager_list_themes();

/* ---------------------------------------------------------------------------
 * Argument parsing
 * ------------------------------------------------------------------------ */

$requested = [];
$all       = false;

foreach ( array_slice( $argv, 1 ) as $arg ) {
    switch ( $arg ) {
        case '--help':
        case '-h':
        case '-?':
            packager_usage();
            exit( 0 );
        case '--list':
        case '-l':
            packager_print_lists( $plugins, $themes );
            exit( 0 );
        case '--all':
        case '-a':
            $all = true;
            break;
        default:
            $requested[] = $arg;
    }
}

if ( $all ) {
    $requested = array_merge( array_keys( $plugins ), array_keys( $themes ) );
}

if ( empty( $requested ) ) {
    packager_print_lists( $plugins, $themes );
    packager_log( '' );
    packager_usage();
    exit( 0 );
}

/* ---------------------------------------------------------------------------
 * Export directory
 * ------------------------------------------------------------------------ */

$folder_name = '_packages';
$export_dir  = rtrim( $wp_root, '/' ) . '/' . $folder_name;

if ( ! is_dir( $export_dir ) && ! mkdir( $export_dir, 0755, true ) ) {
    packager_err( "Error: cannot create export dir '{$export_dir}'." );
    exit( 1 );
}
if ( ! is_writable( $export_dir ) ) {
    packager_err( "Error: export dir '{$export_dir}' is not writable." );
    exit( 1 );
}

/* ---------------------------------------------------------------------------
 * Packaging
 * ------------------------------------------------------------------------ */

$base_url = untrailingslashit( site_url() ) . '/' . $folder_name;

packager_log( '' );
packager_log( "=== GENERATING ARCHIVES ({$folder_name}/) ===" );
packager_log( '' );

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
        packager_log( "[!] Not found: {$slug}" );
        continue;
    }

    $slug_clean   = preg_replace( '/[^A-Za-z0-9._-]/', '-', $slug );
    $archive_name = "{$slug_clean}-{$entry['version']}.zip";
    $archive_file = $export_dir . '/' . $archive_name;

    $zip = new ZipArchive();
    if ( $zip->open( $archive_file, ZipArchive::CREATE | ZipArchive::OVERWRITE ) !== true ) {
        packager_log( "[!] Cannot create archive: {$slug}" );
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

    packager_log( "[+] [{$type}] {$slug} ({$entry['version']}, {$count} files)" );
    packager_log( "      -> " . $base_url . '/' . $archive_name );
    $packed++;
}

packager_log( '' );
packager_log( "Done: {$packed}/{$total} archived in '{$folder_name}/'." );
packager_log( 'Tip: remove the ' . $folder_name . '/ folder after downloading.' );
