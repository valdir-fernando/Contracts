<?php

namespace Tests\Feature;

use Tests\TestCase;

class TestEnvironmentIsolationTest extends TestCase
{
    public function test_storage_disks_and_compiled_configuration_are_isolated_from_the_installation(): void
    {
        $root = $_ENV['LARAVEL_STORAGE_PATH'];
        $this->assertStringStartsWith(sys_get_temp_dir().'/contracts-tests-', $root);
        $this->assertNotSame(base_path('storage'), storage_path());
        $this->assertSame($root.'/app/private', config('filesystems.disks.local.root'));
        $this->assertSame($root.'/app/private/contract-documents', config('filesystems.disks.contract_documents.root'));
        $this->assertSame($root.'/bootstrap/cache/config.php', app()->getCachedConfigPath());
        $this->assertSame($root.'/bootstrap/cache/packages.php', app()->getCachedPackagesPath());
        $this->assertFalse(app()->configurationIsCached());
    }
}
