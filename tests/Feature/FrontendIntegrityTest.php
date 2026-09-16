<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  Static checks on the Vue side that nothing else catches.
//
//  Ziggy resolves route names at runtime, so route('member.dashboard')
//  compiles and ships perfectly happily and only throws in the
//  browser, at whatever moment the user reaches that line. Five such
//  calls survived the rename from member.* to app.*.
//
//  The import-casing check guards a different failure with the same
//  shape: `@/composables/...` against a `Composables/` directory
//  works on a case-insensitive filesystem and breaks the production
//  build on Linux.
// ══════════════════════════════════════════════════════════════════
class FrontendIntegrityTest extends TestCase
{
    /**
     * @return list<string> every .vue / .js file under resources/js
     */
    private function frontendFiles(): array
    {
        $files = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(resource_path('js'))
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && in_array($file->getExtension(), ['vue', 'js'], true)) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    private function relative(string $path): string
    {
        return str_replace(base_path().'/', '', $path);
    }

    public function test_every_route_name_used_in_the_frontend_exists(): void
    {
        $known = collect(Route::getRoutes())
            ->map(fn ($route) => $route->getName())
            ->filter()
            ->flip();

        $unknown = [];

        foreach ($this->frontendFiles() as $path) {
            preg_match_all(
                "/\broute\(\s*'([a-zA-Z0-9_.\-]+)'/",
                (string) file_get_contents($path),
                $matches
            );

            foreach ($matches[1] as $name) {
                if (! $known->has($name)) {
                    $unknown[] = sprintf('%s → route(\'%s\')', $this->relative($path), $name);
                }
            }
        }

        $this->assertSame(
            [],
            array_unique($unknown),
            "These route names don't exist — Ziggy throws when the user reaches them:\n".implode("\n", array_unique($unknown))
        );
    }

    public function test_alias_imports_match_the_real_directory_casing(): void
    {
        $mismatches = [];

        foreach ($this->frontendFiles() as $path) {
            preg_match_all(
                "/from\s+'@\/([A-Za-z0-9_\-]+)\//",
                (string) file_get_contents($path),
                $matches
            );

            foreach ($matches[1] as $segment) {
                $onDisk = glob(resource_path('js').'/*', GLOB_ONLYDIR);
                $names  = array_map('basename', $onDisk);

                // Matches case-insensitively but not exactly → the
                // import only works on a case-insensitive filesystem.
                $insensitive = array_filter($names, fn ($n) => strcasecmp($n, $segment) === 0);

                if ($insensitive && ! in_array($segment, $names, true)) {
                    $mismatches[] = sprintf(
                        "%s imports '@/%s/' but the directory is '%s'",
                        $this->relative($path),
                        $segment,
                        reset($insensitive)
                    );
                }
            }
        }

        $this->assertSame(
            [],
            array_unique($mismatches),
            "These imports break a Linux production build:\n".implode("\n", array_unique($mismatches))
        );
    }

    public function test_no_component_imports_a_file_that_no_longer_exists(): void
    {
        $missing = [];

        foreach ($this->frontendFiles() as $path) {
            preg_match_all(
                "/from\s+'@\/([^']+)'/",
                (string) file_get_contents($path),
                $matches
            );

            foreach ($matches[1] as $target) {
                $full = resource_path('js').'/'.$target;

                $exists = file_exists($full)
                    || file_exists($full.'.js')
                    || file_exists($full.'.vue');

                if (! $exists) {
                    $missing[] = sprintf('%s → @/%s', $this->relative($path), $target);
                }
            }
        }

        $this->assertSame(
            [],
            array_unique($missing),
            "These imports point at files that don't exist:\n".implode("\n", array_unique($missing))
        );
    }

    public function test_the_old_systems_files_are_gone(): void
    {
        $shouldNotExist = [
            'resources/js/ziggy.js',
            'app/Models/UserLoginFrequencyService.php',
            'resources/js/Pages/Admin/Users/Index.vue',
            'resources/js/Pages/Onboarding/ThemePicker.vue',
            'resources/js/Components/Auth/AboutInPracticeModal.vue',
            'resources/js/lang/aboutInPractice.js',
            'public/help/inpractice-help.html',
            'app/Enums/CaseStatus.php',
            'app/Http/Requests/Admin/StoreCaseRequest.php',
            'lang/en/forum.php',
            'lang/ar/surveys.php',
        ];

        $stillThere = array_values(array_filter(
            $shouldNotExist,
            fn (string $path) => file_exists(base_path($path))
        ));

        $this->assertSame([], $stillThere, "Left over from the InPractice copy:\n".implode("\n", $stillThere));
    }

    /**
     * Nothing under resources/js should sit there unimported.
     *
     * This is the check that was missing: the earlier cleanup found
     * files that were *referenced but deleted*, and none that were
     * *present but referenced by nothing* — which is how nine dead
     * components, a dead layout and a dead store survived it.
     *
     * Pages are excluded: Inertia resolves them by name at runtime,
     * so they are never imported by another file.
     */
    /**
     * FIXED (QA audit, Sep 2026): this test built file paths with
     * PHP's RecursiveDirectoryIterator, which returns
     * backslash-separated paths on Windows (Layouts\AppLayout.vue).
     * It then compared those directly against import strings from
     * the source code, which always use forward slashes regardless
     * of OS — that's just JavaScript/Vue import syntax, on Windows,
     * Mac, or Linux alike (from '@/Layouts/AppLayout.vue'). On
     * Windows those two never matched, so the test believed nothing
     * imported anything and flagged nearly every real, in-use file
     * as an "orphan". Normalizing every path to forward slashes
     * before comparing fixes it for every OS the suite might run on.
     */
    public function test_no_frontend_file_is_left_unreferenced(): void
    {
        $root = resource_path('js');

        $imported = [];

        foreach ($this->frontendFiles() as $path) {
            $source = (string) file_get_contents($path);

            preg_match_all("/from\s+'@\/([^']+)'/", $source, $alias);
            foreach ($alias[1] as $target) {
                $imported[$target] = true;
            }

            preg_match_all("/from\s+'(\.[^']+)'/", $source, $relative);
            foreach ($relative[1] as $target) {
                $resolved = realpath(dirname($path).'/'.$target)
                    ?: dirname($path).'/'.$target;
                $normalized = str_replace('\\', '/', $resolved);
                $imported[ltrim(str_replace(str_replace('\\', '/', $root), '', $normalized), '/')] = true;
            }
        }

        $entryPoints = ['app.js', 'bootstrap.js'];
        $orphans     = [];

        foreach ($this->frontendFiles() as $path) {
            $relative = str_replace('\\', '/', ltrim(str_replace($root, '', $path), '/\\'));

            if (in_array($relative, $entryPoints, true) || str_starts_with($relative, 'Pages/')) {
                continue;
            }

            $withoutExtension = preg_replace('/\.(vue|js)$/', '', $relative);

            if (! isset($imported[$relative]) && ! isset($imported[$withoutExtension])) {
                $orphans[] = $relative;
            }
        }

        sort($orphans);

        $this->assertSame(
            [],
            $orphans,
            "Nothing imports these — they ship in the bundle for no reason:\n".implode("\n", $orphans)
        );
    }

    public function test_the_two_translation_bundles_define_the_same_keys(): void
    {
        $source = (string) file_get_contents(resource_path('js/lang/appTranslations.js'));

        // Crude but effective: the file is one object literal with an
        // `en:` and an `ar:` block, so split on the ar boundary and
        // compare the key names either side.
        $parts = preg_split('/\n\s{4}ar:\s*\{/', $source, 2);

        $this->assertCount(2, $parts, 'Could not find the ar: block in appTranslations.js.');

        $keysIn = function (string $block): array {
            preg_match_all('/^\s{8}([a-zA-Z0-9_]+):/m', $block, $matches);
            $keys = array_unique($matches[1]);
            sort($keys);

            return $keys;
        };

        $en = $keysIn($parts[0]);
        $ar = $keysIn($parts[1]);

        $this->assertSame(
            [],
            array_values(array_diff($en, $ar)),
            'These keys exist in en but not ar — Arabic users would see the English string.'
        );

        $this->assertSame(
            [],
            array_values(array_diff($ar, $en)),
            'These keys exist in ar but not en — there is no fallback if ar is missing one.'
        );
    }
}
