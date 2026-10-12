<?php

require dirname(__DIR__).'/vendor/autoload.php';

// A test that prunes exports must never see the installation's private storage.
$testStorage = sys_get_temp_dir().'/contracts-tests-'.bin2hex(random_bytes(12));
foreach (['app/private', 'framework/views', 'framework/cache/data', 'framework/sessions', 'logs', 'bootstrap/cache'] as $directory) {
    if (! mkdir($testStorage.'/'.$directory, 0700, true) && ! is_dir($testStorage.'/'.$directory)) {
        throw new RuntimeException('Não foi possível preparar o armazenamento isolado dos testes.');
    }
}
$testPaths = [
    'LARAVEL_STORAGE_PATH' => $testStorage,
    'APP_CONFIG_CACHE' => $testStorage.'/bootstrap/cache/config.php',
    'APP_SERVICES_CACHE' => $testStorage.'/bootstrap/cache/services.php',
    'APP_PACKAGES_CACHE' => $testStorage.'/bootstrap/cache/packages.php',
    'APP_ROUTES_CACHE' => $testStorage.'/bootstrap/cache/routes.php',
    'APP_EVENTS_CACHE' => $testStorage.'/bootstrap/cache/events.php',
];
foreach ($testPaths as $key => $path) {
    $_ENV[$key] = $_SERVER[$key] = $path;
    putenv($key.'='.$path);
}

// Only the randomly created directory for this test process is removed.
register_shutdown_function(static function () use ($testStorage): void {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($testStorage, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        if ($file->isDir() && ! $file->isLink()) {
            rmdir($file->getPathname());
        } else {
            unlink($file->getPathname());
        }
    }
    rmdir($testStorage);
});
