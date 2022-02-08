<?php


namespace JTP\Crawler;


use App\Code\OldCrawler\Logger;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class UrlParser
{

    private $url;
    private $options;

    const NO_HTTPS_EXPANSION = 1;
    const NO_TRAILING_SLASH = 2;
    const NO_IMAGE_QUERY_REMOVAL = 4;
    const NO_CERTAIN_CANONICALIZE_DUPLICATES = 8;
    const FILETYPE_EQUIVALENTS = 64;
    const CANONICALIZE_DUPLICATES = 128;
    const CANONICALIZE_DUPLICATES_UNCERTAIN = 256;

    function __construct($url, $options = 0) {
        $this->url = $url;
        $this->options = $options; // todo: move
    }

    public static function cleanQueryString($url, $options = 0)
    {

        if (!($options & self::NO_IMAGE_QUERY_REMOVAL)) {
            $url = preg_replace('#(\.mp4|jpg|jpeg|png|gif|webp)\?.*$#', '$1', $url);
        }

        // Remove unhelpful GET parameters completely.
        while (preg_match('/(\?|&)(utm_.*?|jsessionid|PHPSESSID|sid|ASPSESSIONID.*?)($|&|=(.*?)(&|$))/', $url)) {
            $url = preg_replace('/(\?|&)(utm_.*?|jsessionid|PHPSESSID|sid|ASPSESSIONID.*?)($|&|=(.*?)(&|$))/', '$1', $url);
        }

        // Remove double &&s
        $url = preg_replace('/&{2,}/', '&', $url);

        // ?& => ?
        $url = preg_replace('/\?&/', '?', $url);

        // Remove trailing ?
        $url = preg_replace('/\?$/', '', $url);

        // Remove hash
        $url = preg_replace('/#.*/', '', $url);

        return $url;

    }

    public static function cleanUrl($url, $options = 0)
    {

        // Convert all http to https -- this cleans up our index a fair bit, and is generally safe for most purposes; in the rare purpose that it's not, we always store the raw WARC-Target-URI for a reason.
        if (!($options & self::NO_HTTPS_EXPANSION)) {
            $url = preg_replace('#^http:#', 'https:', $url);
        }

        // Force a protocol
        $url = preg_replace('#^([a-z]+:)(?!//)#', '$1//', $url);

        // Remove triple+ slashes from the protocol (TODO: expand beyond HTTP?)
        $url = preg_replace('#^(https?://)/*#', '$1', $url);

        // Remove double+ slashes from after the protocol (TODO: this technically should only be the _first_ : of the string -- would need to apply to parsed URL, then rebuild)
        $url = preg_replace('#(?<!\:)/{2,}#', '/', $url);

        // Multibyte characters shouldn't exist in a URL, replace any found
        $url = preg_replace_callback('/[\x80-\xFF]/', fn($x) => urlencode($x[0]), $url);

        do {
            $host = parse_url($url)['host'] ?? null;

            $rules = array_merge(
                ($options & UrlParser::CANONICALIZE_DUPLICATES) ? (Helpers::getCanonical()['*'] ?? []) : [],
                ($options & UrlParser::CANONICALIZE_DUPLICATES_UNCERTAIN) ? (Helpers::getCanonicalUncertain()['*'] ?? []) : [],
                !($options & UrlParser::NO_CERTAIN_CANONICALIZE_DUPLICATES) ? (Helpers::getCanonicalCertain()['*'] ?? []) : [],
                (isset($host) && ($options & UrlParser::CANONICALIZE_DUPLICATES)) ? Helpers::getCanonical()[$host] ?? [] : [],
                (isset($host) && ($options & UrlParser::CANONICALIZE_DUPLICATES_UNCERTAIN)) ? Helpers::getCanonicalUncertain()[$host] ?? [] : [],
                (isset($host) && !($options & UrlParser::NO_CERTAIN_CANONICALIZE_DUPLICATES)) ? Helpers::getCanonicalCertain()[$host] ?? [] : [],
            );

            foreach ($rules as $key => $value) {
                $url = preg_replace("#$key#", $value, $url);
            }
        } while ($host !== (parse_url($url)['host'] ?? null));

        return $url;

    }

    public static function cleanTrailingFile($url, $options = 0) {

        // This one is a bit more opinionated, though generally safe: treat test, test/, and test/index.* as equivalent.
        if (!($options & self::NO_TRAILING_SLASH)) {

            // Remove any trailing filename in the form index.*****
            $url = preg_replace('#/index\.([^/?]{1,5})(?=\?|$)#', '', $url);

            // Remove trailing slash, if any
            $url = preg_replace('#/(?=\?|$)#', '', $url);

        }

        return $url;

    }

    public static function getFiletypeEquivalents($urls, $options = 0) {
        if ($options & self::FILETYPE_EQUIVALENTS) {
            return array_merge(... array_map(function($url) {
                try {
                    $url_obj = new \http\Url($url, \http\Url::IGNORE_ERRORS);
                    $path_parts = explode('.', $url_obj->path);
                    $extension = Arr::last($path_parts);

                    $image_equivs = ['jpg', 'jpeg', 'gif', 'png', 'webp', 'mp4', 'webm'];
                    $video_equivs = ['mp4', 'webm'];

                    foreach ([$image_equivs, $video_equivs] as $ext_group) {
                        if (in_array($extension, $ext_group)) {
                            return array_map(
                                fn($ext) => $url_obj
                                    ->mod(['path' => implode('.', array_merge(array_slice($path_parts, 0, -1), [$ext]))])
                                    ->toString(),
                                $ext_group
                            );
                        }
                    }
                } catch (\Throwable $ex) {
                    //Log::error("Failed to parse URL -- ignoring: {$ex->getMessage()}", (array) $ex);
                }

                return [$url];
            }, $urls));
        }

        return $urls;
    }

    public function getCanonicalVariants($options = 0)
    {

        $options = $options ?: $this->options;

        $url = $this->url;

        // Apply variations that produce a single equivalent URL.
        $url = self::cleanUrl($url, $options);
        $url = self::cleanQueryString($url, $options);
        $url = self::cleanTrailingFile($url, $options);

        // Apply variations that produce multiple
        $urls = [$url];
        $urls = self::getFiletypeEquivalents($urls, $options);

        return $urls;

    }

    public function getParts()
    {

        return array_merge(
            $parsed = parse_url($this->url),
            [
                'basename' => basename($parsed['path'] ?? ''),
                'directories' => array_values(array_filter(explode('/', $parsed['path'] ?? ''))),
                'tlds' => array_values(array_filter(explode('.', $parsed['host'] ?? '')))
            ]
        );

    }

}
