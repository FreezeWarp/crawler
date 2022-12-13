<?php

namespace JTP\Crawler;

use Carbon\CarbonInterval;
use \Exception;
use \DOMDocument;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use \RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\UriResolver;
use Symfony\Component\Process\Process;

class Processor {
    /**
     * @var WARCEntry
     */
    private $entry;

    /**
     * @var array A parse of this file's information (from parse_url())
     */
    private $fileParts;

    /**
     * @var string The detected file type for this file; may be automatically detected in getFileType(), or manually set by setFileType().
     */
    private $requestedType = null;

    /**
     * @var string The detected file type for this file; may be automatically detected in getFileType(), or manually set by setFileType().
     */
    private $contentType = null;

    /**
     * @var string A base URL, if any, associated with this object. Typically a result of finding a <base /> tag.
     */
    private $baseUrl = null;

    /**
     * @var string The last error encountered in processing.
     */
    public $error = "";

    /**
     * @var bool True if the passed file appears to be a directory; false if it appears to be a file (or otherwise).
     */
    public $isDir = false;

    /**
     * @var callable A function that will be used to format this object's URL. Typically used with the spider.php program to automatically download URLs encountered by the ArchiveReader.
     */
    public $formatUrlCallback = null;

    /**
     * @var array Configuration data for the current site; this will be a merge of the global configuration data and any domain-specific configuration information found in the $domainConfiguration static class variable.
     */
    public $config;

    /**
     * @var string The file's mime type, for caching purposes.
     */
    public $mimeType = null;

    /**
     * @var string The file's contents, for caching purposes.
     */
    public $contents = null;

    /**
     * @var string The HTTP host.
     */
    public static $host = "";

    /**
     * @var string Where library files are located on the file system.
     */
    public static $store = "";

    /**
     * @return string The web-facing path of the MirrorReader instance.
     */
    public static function getScriptPath() {
        return ;
    }

    /**
     * @var array An array of outlinks detected during parsing.
     */
    protected $outlinks = [];


    function __construct(WARCEntry $entry, $type = null) {
        $this->entry = $entry;

        if ($type) {
            $this->requestedType = $type;
        }
    }


    /**
     * @param $contentType
     */
    public function setContentType($contentType) {
        $this->contentType = $contentType;
    }

    /**
     * @return string {@see Processor#file}
     */
    public function getContentType() {

        // The type can be overridden by the requestedType. For instance, a stylesheet used by a <link rel> will always be interpreted as text/css if you provide the appropriate requested type this way.
        // However, you generally should omit and trust the server in most cases.
        if ($this->requestedType) {
            return [
                'css' => 'text/css',
                'js' => 'text/javascript',
                'html' => 'text/html'
            ][$this->requestedType];
        }

        // This is also basically an override. Ideally, don't use this either.
        if ($this->contentType) {
            return $this->contentType;
        }

        // Normally, we'll have the content type via the WARC.
        if ($this->entry->getContentType()) {
            return $this->entry->getContentType();
        }

        // And if all else fails, we'll detect the content type automatically.
        // This mainly only detects HTML right now.
        try {
            $stream = $this->entry->getDecodedBody();
            fseek($stream, 0);
            $peek = fread($stream, 1024);
        } catch (\Throwable $ex) {
            $peek = '';
        }

        // If the file looks like HTML, _regardless_ of what it says it is, we will treat it as HTML.
        if (preg_match('/^(\s|\xEF|\xBB|\xBF)*(\<\!\-\-|\<\!DOCTYPE|\<html|\<head)/i', $peek)) {
            return 'text/html';
        } else {
            return 'application/octet-stream';
        }

    }


    /**
     * @return string The contents of a file, transformed according to the filetype.
     */
    public function getContents() {

        if ($this->getContentType() === 'directory') {
            $all_files = collect(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->entry->getElasticData()['source_file'])))
                ->map(fn(SplFileInfo $file) => $file->getRealPath());

            if ($this->requestedType === 'image'/* || $this->requestedType === 'video'*/) {
                return response()->file(
                    $all_files
                        ->filter(fn($file) => preg_match(
                            '/^.+\.('
                                . [
                                    'image' => 'jpg|jpeg|png|gif|webp',
                                    'video' => 'mp4|mkv'
                                ][$this->requestedType]
                                . ')/i',
                            $file
                        ))
                        ->first()
                );
            } else {
                return response()->redirectTo($this->entry->getElasticData()['external_view']);
            }
        }

        // If the entry is a raw file, it is too large for us to perform any special processing. We'll return it as-is.
        if (($this->entry->getMode() === WARCEntry::MODE_HTTP_FILE_BACKED || Str::startsWith($this->getContentType(), 'video'))
            && $this->requestedType !== 'image') {
            //fpassthru($response->getBodyAsHTTP()->getBody()->getResource());
            return response()->file($this->entry->getBodyAsPhysicalFile(false));
            //->header('Content-Type', $http_response->getHeader('Content-Type'));*/
        }

        // If the entry is HTML, but video or image was requested, we'll attempt to convert
        else if (Str::startsWith($this->getContentType(), 'text/html') && in_array($this->requestedType, ['video', 'image'])) {

            $conversion_order = [];

            // An image was requested for our HTML document. We'll see if we have an image mapping defined in Elastic.
            if ($this->requestedType === 'image') {
                //$conversion_order = ['image', 'video'];
                $conversion_order = ['video', 'image'];
            } else if ($this->requestedType === 'video') {
                $conversion_order = ['video', 'image'];
            }

            foreach ($conversion_order AS $type) {
                if (!empty($images = $this->entry->getElasticData()[$type] ?? null)) {
                    foreach ((array)$images AS $image) {
                        if (!empty((new IndexResolver($image, true))->getMatches())) {
                            return (new Processor((new IndexResolver($image, true))->getWARCEntry(), $this->requestedType))->getContents();
                        }
                    }
                }
            }

            // Fallback -- no conversion possible, go again with new requested type
            return (new Processor((new IndexResolver($this->entry->getTargetURI(), true))->getWARCEntry(), 'text/html'))->getContents();

        }

        else if ($this->requestedType === 'image' && Str::startsWith($this->getContentType(), 'video/')) {

            return Helpers::getOrGenerateCache('contents_' . $this->entry->getTargetURI(), function() {

                $ffprobe_process = new Process(['ffprobe', $this->entry->getBodyAsPhysicalFile()]);
                $ffprobe_process->run();
                if (!$probey = $ffprobe_process->getErrorOutput()) {
                    throw new \RuntimeException("Failed to probe with ffprobe.");
                }

                $duration = CarbonInterval::createFromFormat(
                    'H:i:s.u',
                    Str::of(
                        collect(explode("\n", $probey))
                            ->map(fn($line) => trim($line))
                            ->first(fn($line) => Str::startsWith($line, "Duration"))
                    )
                        ->replaceMatches("/^Duration: /", "")
                        ->explode(",")
                        ->get(0)
                )
                    ->divide(3)
                    ->format('%H:%I:%S');


                if (Helpers::getGeneralSettings()['video_previews_thumbnail_method'] === 'gif') {
                    $ffmpeg_process = new Process([
                        'ffmpeg',
                        '-i', $this->entry->getBodyAsPhysicalFile(),
                        '-ss', $duration,
                        '-t', 60,
                        '-f', 'gif',
                        '-filter_complex', '[0:v] fps=4,scale=w=240:h=-1,split [a][b];[a] palettegen=stats_mode=single [p];[b][p] paletteuse=new=1',
                        //'-r', 2,
                        '-'
                    ]);
                } else if (Helpers::getGeneralSettings()['video_previews_thumbnail_method'] === 'webp') {
                    $ffmpeg_process = new Process([
                        'ffmpeg',
                        '-i', $this->entry->getBodyAsPhysicalFile(),
                        '-vf', 'fps=12,scale=w=640:h=-1',
                        //'-ss', $duration,
                        '-t', 30,
                        '-f', 'webp',
                        '-compression_level', '0',
                        '-quality', Helpers::getGeneralSettings()['video_previews_thumbnail_quality'],
                        //'-r', 2,
                        '-'
                    ]);
                } else {
                    $ffmpeg_process = new Process(['ffmpeg', '-i', $this->entry->getBodyAsPhysicalFile(), '-ss', $duration, '-vframes', 1, '-f', 'image2', '-c:v', 'png', '-']);
                }

                $ffmpeg_process->run();
                if (!$result = $ffmpeg_process->getOutput()) {
                    throw new \RuntimeException("Failed to generate thumbnail with ffmpeg.");
                }

                return response($result)
                    ->header('Content-Type', 'image/' . Helpers::getGeneralSettings()['video_previews_thumbnail_method']);

            }, 365 * 24 * 3600);

        }

        else {

            return Helpers::getOrGenerateCache('1contents_' . $this->entry->getTargetURI(), function() {

                $contents = stream_get_contents($this->entry->getDecodedBody());

                switch (explode(';', $this->getContentType())[0]) {
                    case 'text/html':
                        $contents = $this->processHtml($contents);
                        break;

                    case 'text/css':
                        $contents = $this->processCSS($contents);
                        break;

                    case 'text/javascript':
                        $contents = $this->processJavascript($contents);
                        break;

                    default:

                }

                return $contents;

            }, 0);

        }

    }



    /**
     * Transform a URL into one passed into our script path as a $_GET['url'] argument.
     *
     * @param $url string The URL to transform.
     * @return string The URL, transformed.
     */
    public function formatUrl($url, $type = null) {

        if (stripos($url, 'data:') === 0) // Do not process data: URIs
            return $url;

        if (stripos($url, 'javascript:') === 0) // Process javascript: URIs as Javascript.
            return 'javascript:' . $this->processJavascript(substr($url, 11), true);

        if (strpos($url, '#') === 0) // Hashes can be left alone, when they are on their own.
            return $url;

        // This is an odd edge case that solves more than two slashes after the protocol, e.g., https:///. May need to be moved elsewhere.
    	$url = preg_replace('#^(https?://)/*#', '$1', $url);

        $resolved_url = UriResolver::resolve($url, $this->entry->getTargetURI());

        $this->outlinks[] = $resolved_url;

        // The URL has passthru mode enabled; return the original path unaltered..
        if (Helpers::getSpecificSiteSettings($resolved_url)['use_passthru'])
            return $url;

        // Normal mode: append the URL to a the $_GET['url'] parameter of our script.
        else {
            if (isset(Helpers::getSpecificSiteSettings($resolved_url)['redirects'])) {
                foreach (Helpers::getSpecificSiteSettings($resolved_url)['redirects'] AS $from => $to) {
                    $resolved_url = preg_replace("#{$from}#", $to, $resolved_url);
                }
            }

            return Helpers::getHost()
                . ($type ? "_$type/" : '')
                . $resolved_url;
                // TODO: I'm sure I had a reason for doing this at one point...
                // . str_replace(['%'], ['%25'], $resolved_url);
        }
    }


    /**
     * In some cases, the URL may be dropped directly in a file. This will find and replace all apparent URLs (those that contain a valid domain and end with a recognised extension), though it is unreliable.
     * Enable this hack with 'suspectDomainAnywhere' in the 'scriptHacks' config.
     *
     * @param $contents string The content to search through.
     * @return string A string containing formatted URLs.
     */
    private function hackFormatUrlAnywhere($contents) {
        if ($this->entry->getSiteConfig()['find_urls_anywhere']) {
            return preg_replace_callback(
                '/((https?\:\/\/)(?!(localhost|(www\.)?youtube\.com))[^ "\']*(\/|\.(' . implode('|', $this->entry->getSiteConfig()['find_urls_with_extensions']) . '))(\?(([^"\'\&\<\>\?= ]+)(=([^"\'\&\<\>\?= ]*))(\&([^"\'\&\<\>\?= ]+)(=([^"\'\&\<\>\?= ]*))*)*))*)/',
                function($m) {
                    return $this->formatUrl($m[0]);
                },
                $contents
            );
        }

        return $contents;
    }


    private function isUrl($url) {

        return
            !Str::startsWith($url, Helpers::getHost())
            && (
                filter_var($url, FILTER_VALIDATE_URL)
                || (
                    !empty(implode('|', $this->entry->getSiteConfig()['find_urls_with_extensions']))
                    && preg_match('/.*(\.(' . implode('|', $this->entry->getSiteConfig()['find_urls_with_extensions']) . ')|\/)$/', $url)
                )
            );

    }



    /**
     * Parses and rewrites HTML. This uses DOMDocument, and can handle most bad HTML.
     *
     * @param string $contents The HTML to parse for archive display.
     * @return string A string containing parsed HTML.
     */
    function processHtml($contents) {

        /* HTML Replacement, if enabled */
        foreach ($this->entry->getSiteConfig()['replacements_html_pre'] AS $find => $replace) {
            $contents = str_replace($find, $replace, $contents);
        }

        /* Base URL Detection */
        preg_match_all('/\<base href="(.+?)"\>/is', $contents, $baseMatch);
        if (isset($baseMatch[1][0])) {
            $this->baseUrl = $baseMatch[1][0];
            $contents = preg_replace('/\<base href="(.+?)"\>/is', '', $contents);
        }


        /* Remove Various Comment Nonsense */
        // The horrible if IE hack.
        $contents = preg_replace('/\<\!--\[if(?:[a-zA-Z0-9 ]+)\\]\>.+?--\>/ism', '', $contents);
        $contents = preg_replace('/\<\!-- ?\<\!\[endif\]--\>/is', '', $contents);

        // Alter Improper Comment Form. This is known to break things.
        $contents = str_replace('--!>', '-->', $contents);
        $contents = str_replace('//-->', '-->', $contents); // This one may not actually break things.

        /* Remove All Scripts Hack, if Enabled
         * (very useful for a small number of sites, like Wikia and many silly news sites) */
        if ($this->entry->getSiteConfig()['deactivate_scripts']) {
            // If any body-level elements are in a <noscript> tag in the head, it will cause problems when we un-noscript them. This has been observed with, e.g., tracking beacons on Wikia.
            $contents = preg_replace('/\<noscript[^\>]*\>(.*?)\<\/noscript\>(.*?)\<\/head\>(.*?)\<body([^\>]*)\>/is', '$2</head>$3<body$4><noscript>$1</noscript>', $contents);

            // Remove the relevant <noscript> tags.
            $contents = preg_replace('/\<noscript[^\>]*\>(.*?)\<\/noscript\>/is', '$1', $contents);

            // Remove all script tags.
            $contents = preg_replace('/\<script[^\>]*\>(.*?)\<\/script\>/is', '', $contents);
        }

        /* Fix Missing HTML Elements */
        // Hack to ensure there's an opening head tag if there's a closing head tag (...yes, that happens).
        if (!preg_match('/<head( [^\>]*]|)>/', $contents))
            $contents = preg_replace('/<html(.*?)>/ism', '<html$1><head>', $contents);

        // Hack to ensure HTML tags are present (they sorta need to be
        /*if (strpos($contents, '<html>') === false)
            $contents = '<html>' . $contents;
        if (strpos($contents, '</html>') === false)
            $contents = $contents . '</html>';*/

        /* Hack to remove HTML comments from <style> tags, for the same reason */
        //$contents = preg_replace('/\<style([^\>]*?)\>(.*?)\<\!\-\-(.*?)\-\-\>(.*?)\<\/style\>/is', '<style$1>$2$3$4</style>', $contents);


        $contents = $this->hackFormatUrlAnywhere($contents);

        // The loadHTML call below is known to mangle Javascript in some rare but significant situations. This is a lazy workaround (make sure to run it after processing scripts fully, of-course).
        $contents = preg_replace_callback('/<script([^\>]*)>(.*?)<\/script>/is', function($m) {
            return '<script' . $m[1] . '>' . ($m[2] ? 'eval(atob("' . base64_encode($this->processJavascript($m[2], true)) . '"))' : '') . '</script>';
        }, $contents);

        // Believe it or not, I have encountered this is in the wild, and it breaks DomDocument. A specific case for now; should be generalised.
        $contents = preg_replace('/\<\!DOCTYPE HTML PUBLIC \\\\"(.+)\\\\"\>/', '<!DOCTYPE HTML PUBLIC "$1">', $contents);

        $contents = "<?xml encoding=\"utf-8\" ?>" . $contents;

        libxml_use_internal_errors(true); // Stop the loadHtml call from spitting out a million errors.
        $doc = new DOMDocument(); // Initiate the PHP DomDocument.
        $doc->preserveWhiteSpace = false; // Don't worry about annoying whitespace.
        $doc->substituteEntities = false;
        $doc->formatOutput = false;
        $doc->recover = true; // We may need to set this behind a flag. Some... incredibly broken websites seem to benefit heavily from it, but I think it is also capable of breaking non-broken websites.
        $doc->loadHTML($contents, LIBXML_HTML_NODEFDTD); // Load the HTML.

        // Process LINK tags
        $linkList = $doc->getElementsByTagName('link');
        for ($i = 0; $i < $linkList->length; $i++) {
            if ($linkList->item($i)->hasAttribute('href')) {
                if ($linkList->item($i)->getAttribute('type') == 'text/css' || $linkList->item($i)->getAttribute('rel') == 'stylesheet') {
                    $linkList->item($i)->setAttribute('href', $this->formatUrl($linkList->item($i)->getAttribute('href'), 'css'));
                }
                else {
                    $linkList->item($i)->setAttribute('href', $this->formatUrl($linkList->item($i)->getAttribute('href')));
                }
            }
        }


        // Process SCRIPT tags.
        $scriptList = $doc->getElementsByTagName('script');
        $scriptDrop = array();
        for ($i = 0; $i < $scriptList->length; $i++) {
            if ($scriptList->item($i)->hasAttribute('src')) {
                $scriptList->item($i)->setAttribute('src', $this->formatUrl($scriptList->item($i)->getAttribute('src'), 'js'));
            }
        }
        foreach ($scriptDrop AS $drop) {
            $drop->parentNode->removeChild($drop);
        }


        // Process STYLE tags.
        $styleList = $doc->getElementsByTagName('style');
        for ($i = 0; $i < $styleList->length; $i++) {
            $styleList->item($i)->nodeValue = htmlentities($this->processCSS($styleList->item($i)->nodeValue, true));
        }

        // Process IMG, VIDEO, AUDIO, IFRAME tags
        foreach (array('img', 'video', 'audio', 'source', 'frame', 'iframe', 'applet') AS $ele) {
            $imgList = $doc->getElementsByTagName($ele);
            for ($i = 0; $i < $imgList->length; $i++) {
                foreach (array_merge(['src', 'data-src', 'poster'], $this->entry->getSiteConfig()['find_urls_additional_src_attributes'] ?? []) AS $srcAttr) {
                    if ($imgList->item($i)->hasAttribute($srcAttr)) {
                        $imgList->item($i)->setAttribute($srcAttr, $this->formatUrl($imgList->item($i)->getAttribute($srcAttr)));
                    }
                }

                foreach (array_merge(['src', 'data-srcset', 'data-expanded-srcset'], $this->entry->getSiteConfig()['find_urls_additional_src_attributes'] ?? []) AS $srcSetAttr) {
                    if ($imgList->item($i)->hasAttribute($srcSetAttr)) {
                        $srcList = explode(',', $imgList->item($i)->getAttribute($srcSetAttr));

                        foreach ($srcList as &$srcPair) {
                            if (strstr(trim($srcPair), ' ') === false) continue;

                            list($srcFile, $srcSize) = explode(' ', trim($srcPair));
                            $srcFile = $this->formatUrl(trim($srcFile));
                            $srcPair = implode(' ', [$srcFile, $srcSize]);
                        }

                        $imgList->item($i)->setAttribute($srcSetAttr, implode(', ', $srcList));
                    }
                }
            }
        }


        // Process A, AREA (image map) tags
        foreach (array('a', 'area') AS $ele) {
            $aList = $doc->getElementsByTagName($ele);
            for ($i = 0; $i < $aList->length; $i++) {
                if ($aList->item($i)->hasAttribute('href')) {
                    $aList->item($i)->setAttribute('href', $this->formatUrl($aList->item($i)->getAttribute('href')));
                }
            }
        }




        /* TODO: form processing
        $formList = $doc->getElementsByTagName('form');
        for ($i = 0; $i < $formList->length; $i++) {
            if ($formList->item($i)->hasAttribute('action')) {
                if (!$formList->item($i)->hasAttribute('method') || strtolower($formList->item($i)->getAttribute('method')) === 'get') {
                    $actionParts = parse_url($this->formatUrl($formList->item($i)->getAttribute('action')));

                    $formList->item($i)->setAttribute('action', $actionParts['scheme'] . '://' . $actionParts['host'] . $actionParts['path']);

                    $queryParts = [];
                    parse_str($actionParts['query'], $queryParts);

                    foreach ($queryParts AS $partName => $partValue) {
                        $partElement = $doc->createElement("input");
                        $partElement->setAttribute("type", "hidden");
                        $partElement->setAttribute("name", $partName);
                        $partElement->setAttribute("value", $partValue);

                        $formList->item($i)->appendChild($partElement);
                    }
                }
            }
        }*/


        // Process meta-refresh headers that may in some cases automatically redirect a page, similar to <a href>.
        // <meta http-equiv="Refresh" content="5; URL=http://www.google.com/index">
        $metaList = $doc->getElementsByTagName('meta');
        for ($i = 0; $i < $metaList->length; $i++) {
            if ($metaList->item($i)->hasAttribute('http-equiv')
                && $metaList->item($i)->hasAttribute('content')
                && strtolower($metaList->item($i)->getAttribute('http-equiv')) == 'refresh') {
                $metaList->item($i)->setAttribute('content', preg_replace_callback('/^(.*)url=([^ ;]+)(.*)$/is', function($m) {
                    return $m[1] . 'url=' . $this->formatUrl($m[2]) . $m[3];
                }, $metaList->item($i)->getAttribute('content')));
            }
        }


        // Process BODY, TABLE, TD, and TH tags w/ backgrounds. TABLE, TD & TH do support the background tag, but it was an extension of both Netscape and IE way back, and today most browsers still recognise it and will add a background image as appropriate, so... we have to support it.
        if ($this->entry->getSiteConfig()['find_urls_in_legacy_background_attributes']) {
            foreach (array('body', 'table', 'td', 'th') AS $ele) {
                $aList = $doc->getElementsByTagName($ele);
                for ($i = 0; $i < $aList->length; $i++) {
                    if ($aList->item($i)->hasAttribute('background')) {
                        $aList->item($i)->setAttribute('background', $this->formatUrl($aList->item($i)->getAttribute('background')));
                    }
                }
            }
        }


        // Process Option Links; some sites will store links in OPTION tags and then use Javascript to link to them. Thus, if the hack is enabled, we will try to cope.
        if ($this->entry->getSiteConfig()['find_urls_in_option_tags']) {
            $optionList = $doc->getElementsByTagName('option');
            for ($i = 0; $i < $optionList->length; $i++) {
                if ($optionList->item($i)->hasAttribute('value')) {
                    if ($this->isUrl($optionValue = $optionList->item($i)->getAttribute('value'))) {
                        $optionList->item($i)->setAttribute('value', $this->formatUrl($optionValue));
                    }
                }
            }
        }


        // Format all XML attributes that appear to be URLs
        // Note: a regex filter inside of the loop would improve accuracy
        if ($this->entry->getSiteConfig()['find_urls_in_all_html_attributes']) {
            $xpath = new \DOMXPath($doc);

            //foreach ($xpath->query('//@*[starts-with(., \'http\')]') as $element) {
            foreach ($xpath->query('//@*') as $element) {
                /** @var $element \DOMAttr */
                if ($this->isUrl($element->nodeValue)) {
                    $element->nodeValue = $this->formatUrl($element->nodeValue);
                }
            }
        }


        // This formats style and javascript attributes; it is safe, but we disable by default for performance reasons.
        // Performance may be reasonable when combined with effective caching, however.
        $docAll = new RecursiveIteratorIterator(
            new RecursiveDOMIterator($doc),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach($docAll as $node) {
            if ($node->nodeType === XML_ELEMENT_NODE) {
                if ($node->hasAttribute('style')) {
                    $node->setAttribute('style', str_replace("\n", '', $this->processCSS($node->getAttribute('style'), true)));
                }

                foreach (array('onclick', 'onmouseover', 'onmouseout', 'onfocus', 'onblur', 'onchange', 'onsubmit') as $att) {
                    if ($node->hasAttribute($att)) {
                        if ($this->entry->getSiteConfig()['deactivate_scripts']) {
                            $node->removeAttribute($att);
                        } else {
                            $node->setAttribute($att, str_replace("\n", '', $this->processJavascript($node->getAttribute($att), true)));
                        }
                    }
                }
            }
        }

        if (isset($this->entry->getSiteConfig()['src_injections'])) {
            foreach ($this->entry->getSiteConfig()['src_injections'] AS $injection_rule) {
                $xpath = new \DOMXPath($doc);

                //foreach ($xpath->query('//@*[starts-with(., \'http\')]') as $element) {
                foreach ($xpath->query($injection_rule['dom_path']) as $element) {
                    preg_match("#{$injection_rule['source_regex']}#", $this->entry->getTargetURI(), $matches);

                    $file = glob($injection_rule['folder'] . str_replace('{{source}}', $matches[0], $injection_rule['destination_glob']));

                    if (count($file) > 0) {
                        foreach (Helpers::getGeneralSettings()['mounts'] as $from => $to) {
                            if (Str::startsWith($file[0], $from)) $file[0] = $to . substr($file[0], strlen($from));
                        }

                        $element->nodeValue = $file[0];
                    } else {
                        //echo $injection_rule['folder'] . str_replace('{{source}}', $matches[0], $injection_rule['destination_glob']); die();
                    }
                }
            }
        }

        if (isset($this->entry->getSiteConfig()['add_attributes'])) {
            foreach ($this->entry->getSiteConfig()['add_attributes'] AS $injection_rule) {
                $xpath = new \DOMXPath($doc);

                //foreach ($xpath->query('//@*[starts-with(., \'http\')]') as $element) {
                foreach ($xpath->query($injection_rule['dom_path']) as $element) {
                    $element->setAttribute($injection_rule['attribute'], $injection_rule['value'] ?: null);
                }
            }
        }


        foreach ($this->entry->getSiteConfig()['delete_nodes'] AS $xpath) {
            foreach ((new \DOMXPath($doc))->query($xpath) as $element) {
                $element->parentNode->removeChild($element);
            }
        }

        foreach ($this->entry->getSiteConfig()['replacements_html_post'] AS $find => $replace) {
            $contents = str_replace($find, $replace, $contents);
        }


        return $doc->saveHTML(); // Return the updated data.
    }


    /**
     * Parsess and rewrites Javascript using the enabled config['scriptHacks']. (If no script hacks are used, Javascript will not typically be altered.)
     *
     * @global string $this->entry->getSiteConfig()
     * @global string $urlParts
     * @param string $contents&&
     * @return string
     */
    function processJavascript($contents, $inline = false) {
        // If enabled, perform find-replace on Javascript as configured.
        foreach ($this->entry->getSiteConfig()['replacements_javascript_pre'] AS $find => $replace) {
            $contents = str_replace($find, $replace, $contents);
        }


        // If removeAll is enabled, return an empty string.
        if ($this->entry->getSiteConfig()['deactivate_scripts'])
            return "";


        // Remove comments.
        if ($this->entry->getSiteConfig()['javascript_strip_comments']) {
            $contents = preg_replace('/\/\*.*?\*\//s', '', $contents);
            $contents = preg_replace('/^\/\/.*\$/', '', $contents);
        }

        // If suspectFileAnywhere is enabled, look for files with known extensions in the Javascript body.
        if ($this->entry->getSiteConfig()['find_urls_suspect_anywhere_in_javascript']) { // Convert anything that appears to be a suspect file. Because of the nature of this, there is a high chance stuff will break if enabled. But, it allows some sites to work properly that otherwise wouldn't.
            $contents = preg_replace_callback('/(([a-zA-Z0-9\_\-\/]+)(\.(' . implode('|', $this->entry->getSiteConfig()['find_urls_with_extensions']) . ')))([^a-zA-Z0-9])/i', function($m) {
                return $this->formatUrl($m[1]) . $m[5];
            }, $contents); // Note that if the extension is followed by a letter or integer, it is possibly a part of a JavaScript property, which we don't want to convert
        }

        // If suspectFileString is enabled instead, look for files with known extensions in Javascript strings.
        elseif ($this->entry->getSiteConfig()['find_urls_suspect_anywhere_in_javascript_strings']) { // Convert strings that contain files ending with suspect extensions.
            $contents = preg_replace_callback('/("|\')(([a-zA-Z0-9\_\-\/]+)\.(' . implode('|', $this->entry->getSiteConfig()['find_urls_with_extensions']) . ')(\?(([^"\&\<\>\?= ]+)(=([^"\&\<\>\? ]*)|)(\&([^"\&\<\>\? ]+)(=([^"\&\<\>\?= ]*))*)*)?)?)\1/i', function($m) {
                return $m[1] . $this->formatUrl($m[2]) . $m[1];
            }, $contents);
        }

        // If this is not inline Javascript, use hackFormatUrlAnywhere. (If it is inline, this would have been run anyway.)
        if (!$inline)
            $contents = $this->hackFormatUrlAnywhere($contents);

        // If suspectDirString is used in script hacks, look for directories in Javascript strings. (There is no good way of doing this globally, due to things like regex.)
        if ($this->entry->getSiteConfig()['find_urls_suspect_directories_in_javascript_strings']) {
            $contents = preg_replace_callback('/("|\')((\/|)((([a-zA-Z0-9\_\-]+)\/)+))\1/i', function($m) {
                return $m[1] . $this->formatUrl($m[2]) . $m[1];
            }, $contents);
        }


        // If enabled, perform find-replace on Javascript as configured.
        foreach ($this->entry->getSiteConfig()['replacements_javascript_post'] AS $find => $replace) {
            $contents = str_replace($find, $replace, $contents);
        }


        // Return the updated data.
        return $contents;
    }


    /**
     * Process CSS.
     * @param string $contents
     * @return string
     */
    function processCSS($contents, $inline = false) {
        // If enabled, perform find-replace on CSS as configured.
        foreach ($this->entry->getSiteConfig()['replacements_css_pre'] AS $find => $replace) {
            $contents = str_replace($find, $replace, $contents);
        }

        //$contents = preg_replace('/\/\*(.*?)\*\//is', '', $contents); // Removes comments.

        // Replace url() tags
        $contents = preg_replace_callback('/url\((\'|"|)(.+?)\\1\)/is', function($m) {
            return 'url(' . $m[1] . $this->formatUrl($m[2]) . $m[1] . ')';
        }, $contents); // CSS images are handled with this.

        // If enabled, perform find-replace on CSS as configured.
        foreach ($this->entry->getSiteConfig()['replacements_css_post'] AS $find => $replace) {
            $contents = str_replace($find, $replace, $contents);
        }

        return $contents; // Return the updated data.
    }


    /**
     * @return array The list of URLs that were detected and processed during content analysis. If content analysis has not yet been performed, it will be first.
     */
    function getOutlinks() {
        if (!count($this->outlinks)) {
            $this->getContents();
        }

        return $this->outlinks;
    }
}
