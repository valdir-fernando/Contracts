<?php

// Execute inside the app container; outputs timings only, never credentials.
$root = dirname(__DIR__);
$files = array_slice(glob($root.'/vendor/laravel/framework/src/Illuminate/Support/*.php'), 0, 40);
$native = sys_get_temp_dir().'/contracts-io-'.bin2hex(random_bytes(6));
mkdir($native);
foreach ($files as $file) {
    copy($file, $native.'/'.basename($file));
}
foreach (['mounted_vendor' => $files, 'linux_native' => glob($native.'/*.php')] as $label => $paths) {
    $start = hrtime(true);
    for ($round = 0; $round < 5; $round++) {
        foreach ($paths as $path) {
            clearstatcache(true, $path);
            is_file($path);
            file_get_contents($path);
        }
    }
    printf("%s: %.1f ms (%d file reads)\n", $label, (hrtime(true) - $start) / 1e6, count($paths) * 5);
}
foreach (glob($native.'/*.php') as $file) {
    unlink($file);
}
rmdir($native);
$start = hrtime(true);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
printf("laravel_bootstrap: %.1f ms\n", (hrtime(true) - $start) / 1e6);
$start = hrtime(true);
Illuminate\Support\Facades\DB::select('SELECT 1');
printf("database_connect_query: %.1f ms\n", (hrtime(true) - $start) / 1e6);
