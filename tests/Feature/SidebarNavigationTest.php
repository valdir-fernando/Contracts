<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SidebarNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_organization_page_keeps_catalogs_closed_and_its_menu_item_selected(): void
    {
        $xpath = $this->navigation('/catalogs');

        $this->assertSame(0, $xpath->query('//details[@class="nav-catalogs"][@open]')->length);
        $this->assertSame(1, $xpath->query('//nav//a[@data-nav-label="Órgãos e permissões"][@aria-current="page"]')->length);
    }

    public function test_catalog_page_opens_catalogs_without_selecting_organizations(): void
    {
        $xpath = $this->navigation('/catalogs/budgets/create');

        $this->assertSame(1, $xpath->query('//details[@class="nav-catalogs"][@open]')->length);
        $this->assertSame(0, $xpath->query('//nav//a[@data-nav-label="Órgãos e permissões"][@aria-current="page"]')->length);
        $this->assertSame(1, $xpath->query('//details//a[@aria-current="page"]/span[text()="Dotação"]')->length);
    }

    private function navigation(string $url): \DOMXPath
    {
        $this->withoutVite();
        $admin = User::create(['name' => 'Admin', 'username' => 'NAV_ADMIN', 'password' => 'TestPassword12345', 'user_type' => 'Administrador']);
        $response = $this->actingAs($admin)->get($url)->assertOk();
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());

        return new \DOMXPath($document);
    }
}
