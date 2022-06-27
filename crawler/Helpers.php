<?php


namespace JTP\Crawler;


use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Yosymfony\Toml\Toml;

class Helpers
{
    public static function getGeneralSettings() {
        return self::getOrGenerateCache('general-settings', fn() => Toml::parseFile(__DIR__ . "/../../../../storage/app/crawler/general-settings.toml"), 0);
    }

    public static function getSiteSettings() {
        return self::getOrGenerateCache('site-settings', fn() => (array) Toml::parseFile(__DIR__ . '/../../../../storage/app/crawler/site-settings.toml'), 0);
    }

    public static function getCanonical() {
        return self::getOrGenerateCache('canonical', fn() => (array) Toml::parseFile(__DIR__ . '/../../../../storage/app/crawler/canonical.toml'), 0);
    }

    public static function getCanonicalCertain() {
        return self::getOrGenerateCache('canonical-certain', fn() => (array) Toml::parseFile(__DIR__ . '/../../../../storage/app/crawler/canonical-certain.toml'), 0);
    }

    public static function getCanonicalUncertain() {
        return self::getOrGenerateCache('canonical-uncertain', fn() => (array) Toml::parseFile(__DIR__ . '/../../../../storage/app/crawler/canonical-uncertain.toml'), 0);
    }

    public static function getMappings() {
        return self::getOrGenerateCache('mappings', fn() => (array) Toml::parseFile(__DIR__ . '/../../../../storage/app/crawler/mappings.toml'), 0);
    }

    public static function getCollections() {
        return self::getOrGenerateCache('collections', fn() => (array) Toml::parseFile(__DIR__ . '/../../../../storage/app/crawler/collections.toml'), 0);
    }

    private static $locally_cached = [];
    public static function getOrGenerateCache($key, $generator, $ttl = 3600) {
        if (class_exists('Cache')) {
            return self::$locally_cached[$key] = self::$locally_cached[$key]
                ?? ($ttl == 0
                    ? $generator()
                    : Cache::get($key, function () use ($key, $generator, $ttl) {
                        $value = $generator();
                        Cache::add($key, $value);
                        return $value;
                    })
                );
        } else {
            return self::$locally_cached[$key] = self::$locally_cached[$key]
                ?? $generator();
        }
    }

    public static function getSpecificSiteSettings($url) {
        return array_merge(
            Helpers::getSiteSettings()['*'] ?? [],
            Helpers::getSiteSettings()[parse_url($url)['host'] ?? null] ?? []
        );
    }

    public static function getHost() {
        return (!empty($_SERVER['HTTPS']) ? 'https' : 'http')
            . '://'
            . ($_SERVER['HTTP_HOST'] ?? 'localhost') // There are command-line scenarios where no HTTP_HOST would be present, so a sensible fallback is useful.
            . rtrim(dirname($_SERVER['PHP_SELF']), '/')
            . '/';
    }
}
