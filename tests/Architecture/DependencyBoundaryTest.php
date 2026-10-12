<?php

namespace Tests\Architecture;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class DependencyBoundaryTest extends TestCase
{
    public function test_domain_and_application_only_depend_on_their_inner_layers(): void
    {
        $root = dirname(__DIR__, 2).'/app';
        $violations = [];
        foreach (['Domain' => ['App\\Domain\\'], 'Application' => ['App\\Domain\\', 'App\\Application\\']] as $layer => $allowed) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$layer, FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if ($file->getExtension() === 'php') {
                    foreach ($this->violations(file_get_contents($file->getPathname()), $allowed) as $violation) {
                        $violations[] = $file->getPathname().':'.$violation;
                    }
                }
            }
        }
        $this->assertSame([], $violations, implode("\n", $violations));
    }

    public function test_guard_recognizes_imports_qualified_references_and_framework_helpers(): void
    {
        $source = <<<'CODE'
        <?php
        namespace App\Domain\Example;
        use Illuminate\Support\Facades\DB;
        use App\Models\Contract as Record;
        $model = new \App\Infrastructure\Contracts\ContractMapper;
        $time = now();
        require 'bootstrap.php';
        CODE;
        $this->assertCount(5, $this->violations($source, ['App\\Domain\\']));
        $safe = <<<'CODE'
        <?php
        namespace App\Application\Example;
        use App\Domain\Contracts\ContractState;
        use DateTimeImmutable;
        // now() and Illuminate\Support\Facades\DB in comments are not dependencies.
        $description = 'auth()';
        $date = new DateTimeImmutable('2026-10-12');
        $value = $port->now();
        CODE;
        $this->assertSame([], $this->violations($safe, ['App\\Domain\\', 'App\\Application\\']));
    }

    public function test_infrastructure_does_not_import_controllers_or_livewire(): void
    {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/app/Infrastructure', FilesystemIterator::SKIP_DOTS));
        $violations = [];
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            foreach (token_get_all(file_get_contents($file->getPathname())) as $token) {
                if (is_array($token) && in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
                    $name = ltrim($token[1], '\\');
                    if (str_starts_with($name, 'App\\Http\\Controllers\\') || str_starts_with($name, 'App\\Livewire\\') || str_starts_with($name, 'App\\Providers\\')) {
                        $violations[] = $file->getPathname().':'.$token[2].' '.$name;
                    }
                }
            }
        }
        $this->assertSame([], $violations, implode("\n", $violations));
    }

    private function violations(string $source, array $allowed): array
    {
        $tokens = array_values(array_filter(token_get_all($source), fn ($token) => ! is_array($token) || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])));
        $violations = [];
        foreach ($tokens as $index => $token) {
            if (! is_array($token)) {
                continue;
            }
            if (in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE])) {
                $name = ltrim($token[1], '\\');
                $permitted = false;
                foreach ($allowed as $prefix) {
                    $permitted = $permitted || str_starts_with($name, $prefix);
                }
                if (! $permitted && ! (class_exists($name, false) && (new \ReflectionClass($name))->isInternal())) {
                    $violations[] = $token[2].' dependência externa '.$name;
                }
            }
            if (in_array($token[0], [T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE])) {
                $violations[] = $token[2].' inclusão de arquivo externo';
            }
            if ($token[0] === T_STRING && in_array(strtolower($token[1]), ['app', 'resolve', 'auth', 'request', 'config', 'env', 'now', 'today', 'abort', 'abort_if', 'abort_unless', 'collect', 'validator', 'response', 'redirect', 'view', 'event', 'dispatch', 'logger', 'cache', 'session', 'route']) && ($tokens[$index + 1] ?? null) === '(') {
                $previous = $tokens[$index - 1] ?? null;
                if (! is_array($previous) || ! in_array($previous[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION])) {
                    $violations[] = $token[2].' helper de framework '.$token[1];
                }
            }
        }

        return $violations;
    }
}
