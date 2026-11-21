<?php

namespace suprim\speedy\tests\Integration;

use Craft;
use suprim\speedy\tests\TestCase;

/**
 * The control panel in German, Dutch and French: every message Speedy shows is translated, with the same placeholders.
 */
class TranslationsTest extends TestCase
{
    private const LANGUAGES = ['de', 'nl', 'fr'];

    /** Messages translated by a variable, such as the element types in the repeated queries report. */
    private const DYNAMIC = ['Choose sections or groups', 'Choose sections', 'Variants', 'Products', 'Categories', 'Assets', 'Users', 'Tags', 'Addresses', 'Global sets', 'Entries'];

    /**
     * Every message in the source: `Craft::t('speedy', '…')` in PHP and `'…'|t('speedy')` in Twig.
     *
     * @return string[]
     */
    private function sourceMessages(): array
    {
        $root = dirname(__DIR__, 2) . '/src';
        $messages = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $path = $file->getPathname();
            if (str_contains($path, '/translations/')) {
                continue;
            }
            if (str_ends_with($path, '.php')) {
                $tokens = token_get_all((string)file_get_contents($path));
                foreach ($tokens as $i => $token) {
                    if (!is_array($token) || $token[1] !== 't' || !is_array($tokens[$i - 1] ?? null) || $tokens[$i - 1][1] !== '::') {
                        continue;
                    }
                    $args = [];
                    for ($j = $i + 1; isset($tokens[$j]) && count($args) < 2 && $tokens[$j] !== ')'; $j++) {
                        if (is_array($tokens[$j]) && $tokens[$j][0] === T_CONSTANT_ENCAPSED_STRING) {
                            $args[] = stripcslashes(substr($tokens[$j][1], 1, -1));
                        }
                    }
                    if (($args[0] ?? null) === 'speedy' && isset($args[1])) {
                        $messages[] = $args[1];
                    }
                }
            } elseif (str_ends_with($path, '.twig')) {
                preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'\\s*\\|\\s*t\\(\\s*'speedy'/s", (string)file_get_contents($path), $m);
                array_push($messages, ...array_map('stripslashes', $m[1]));
            }
        }

        return array_values(array_unique(array_merge($messages, self::DYNAMIC)));
    }

    public function testEveryMessageIsTranslated(): void
    {
        $messages = $this->sourceMessages();
        $this->assertGreaterThan(400, count($messages), 'The messages were found.');
        $names = fn(string $s) => preg_match_all('/(?<![=\w])\{(\w+)\s*[,}]/', $s, $m) ? array_values(array_unique($m[1])) : [];
        foreach (self::LANGUAGES as $language) {
            $file = dirname(__DIR__, 2) . "/src/translations/$language/speedy.php";
            $translations = require $file;
            $this->assertSame([], array_values(array_diff($messages, array_keys($translations))), "Messages without a $language translation.");
            foreach ($translations as $source => $translation) {
                $this->assertNotSame('', trim($translation), "$language: $source");
                $this->assertEqualsCanonicalizing($names($source), $names($translation), "$language keeps the placeholders of: $source");
            }
        }
    }

    public function testCraftFormatsThem(): void
    {
        $language = Craft::$app->language;
        try {
            Craft::$app->language = 'de';
            $this->assertSame('3 Seiten geleert.', Craft::t('speedy', '{count, plural, =1{# page} other{# pages}} cleared.', ['count' => 3]));
            Craft::$app->language = 'fr';
            $this->assertSame('1 page vidée.', Craft::t('speedy', '{count, plural, =1{# page} other{# pages}} cleared.', ['count' => 1]));
            Craft::$app->language = 'nl';
            $this->assertSame('Nooit cachen', Craft::t('speedy', 'Never cache'));
        } finally {
            Craft::$app->language = $language;
        }
    }
}
