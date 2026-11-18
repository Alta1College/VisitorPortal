<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Tests\Feature\Fork;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Upstream's source text is German; anything without an English entry would show German to
 * English-language organisations. Every literal __('...') in the app and views, and every
 * settings label, must have an English translation.
 */
class EnglishTranslationCoverageTest extends TestCase
{
    public function test_every_text_has_an_english_translation(): void
    {
        $english = json_decode((string) file_get_contents(lang_path('en.json')), true, flags: JSON_THROW_ON_ERROR);
        $missing = [];

        foreach ([app_path(), resource_path('views')] as $directory) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory)) as $file) {
                if (! str_ends_with((string) $file, '.php')) {
                    continue;
                }

                $source = (string) file_get_contents((string) $file);
                preg_match_all('/__\(\s*\'((?:[^\'\\\\]|\\\\.)*)\'/', $source, $calls);
                $keys = $calls[1];

                // Settings labels and help texts are translated when the form is built.
                if (str_ends_with((string) $file, 'SettingFields.php')) {
                    preg_match_all('/=> \[\'([^\']*)\'(?:, \'([^\']*)\')?\]/', $source, $labels);
                    $keys = [...$keys, ...$labels[1], ...array_filter($labels[2])];
                }

                foreach ($keys as $key) {
                    $key = stripslashes($key);

                    // Group keys such as "validation.required" live in lang/en/*.php.
                    if (! isset($english[$key]) && preg_match('/^[a-z0-9_-]+(\.[a-z0-9_-]+)+$/', $key) !== 1) {
                        $missing[$key] = str_replace(base_path().DIRECTORY_SEPARATOR, '', (string) $file);
                    }
                }
            }
        }

        $this->assertSame([], $missing, 'Add these to lang/en.json (and fr.json, cs.json).');
    }
}
