<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Translations: the English text is the key. Each language has its own file
 * in lang/<code>.php returning ['English text' => 'Translated text'].
 */
final class Lang
{
    private static string $locale = 'en';
    private static array $strings = [];

    public static function available(): array
    {
        $out = [];
        foreach (glob(BASE_PATH . '/lang/*.php') ?: [] as $f) {
            $code = basename($f, '.php');
            $out[$code] = self::names()[$code] ?? strtoupper($code);
        }
        ksort($out);
        return $out;
    }

    private static function names(): array
    {
        return ['en' => 'English', 'it' => 'Italiano', 'de' => 'Deutsch', 'fr' => 'Français', 'es' => 'Español'];
    }

    public static function setLocale(string $code): void
    {
        $code = preg_replace('/[^a-z_]/', '', strtolower($code)) ?: 'en';
        $file = BASE_PATH . '/lang/' . $code . '.php';
        if (!is_file($file)) {
            $code = 'en';
            $file = BASE_PATH . '/lang/en.php';
        }
        self::$locale = $code;
        self::$strings = is_file($file) ? (array) require $file : [];
    }

    public static function locale(): string
    {
        return self::$locale;
    }

    public static function get(string $text, array $params = []): string
    {
        $out = self::$strings[$text] ?? $text;
        if ($out === '') {
            $out = $text;
        }
        foreach ($params as $k => $v) {
            $out = str_replace(':' . $k, (string) $v, $out);
        }
        return $out;
    }
}
