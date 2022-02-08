<?php


namespace JTP\Crawler;


use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\UriResolver;

class HTMLMapper
{

    protected $crawler;
    protected $custom_mappings;

    public function __construct(Crawler $crawler) {
        $this->crawler = $crawler;
        $this->custom_mappings = Helpers::getMappings()[parse_url($this->crawler->getUri())['host']] ?? [];
    }

    public function getArray() {
        $mappings = $this->getCustomMappings();

        // We avoid duplicating computation when a custom mapping has provided for any of these
        if (empty($this->custom_mappings['subsite'])) $mappings['subsite'] = $this->getSubsite();
        if (empty($this->custom_mappings['title'])) $mappings['title'] = $this->getTitle();
        if (empty($this->custom_mappings['description'])) $mappings['description'] = $this->getDescription();
        if (empty($this->custom_mappings['keywords'])) $mappings['keywords'] = $this->getKeywords();
        if (empty($this->custom_mappings['title_nodes'])) $mappings['title_nodes'] = $this->getTitleNodes();
        if (empty($this->custom_mappings['text_summary'])) $mappings['text_summary'] = $this->getTextSummary();
        if (empty($this->custom_mappings['image'])) $mappings['image'] = $this->getImage();
        if (empty($this->custom_mappings['video'])) $mappings['video'] = $this->getVideo();
        if (empty($this->custom_mappings['content_author'])) $mappings['content_author'] = $this->getContentAuthor();
        if (empty($this->custom_mappings['content_date'])) $mappings['content_date'] = $this->getContentDate();
        if (empty($this->custom_mappings['score'])) $mappings['score'] = $this->getScore();
        if (empty($this->custom_mappings['html_outlinks'])) $mappings['html_outlinks'] = [];

        $mappings['schema'] = $this->getSchema();

        return $mappings;
    }

    public function getCrawler() {
        return $this->crawler;
    }

    public static function getNullArray() {
        return [
            'title' => [],
            'description' => [],
            'keywords' => [],
            'title_nodes' => [],
            'text_summary' => [],
            'image' => [],
            'video' => [],
            'content_author' => [],
            'content_date' => [],
            'score' => [],
            'html_outlinks' => []
        ];
    }

    public function getSubsite() {
        return parse_url($this->crawler->getUri())['host'];
    }

    public function getTitle() {
        return $this->crawler->filter('head > title')->first()->text('');
    }

    public function getDescription() {
        // The description gets a moderate boost in querying (TODO: attempt to identify sites where it doesn't vary -- possibly lookup homepage if available?)
        // It also may be displayed in page previews
        if (($description = $this->crawler->filter('meta[name="description"], meta[property="og:description"]')->first())->count() > 0) {
            return $description->attr("content", '');
        }
    }

    public function getKeywords() {
        // The keywords gets a moderate boost in querying (TODO: attempt to identify sites where it doesn't vary -- possibly lookup homepage if available?)
        // TODO: mapping
        if (($keywords = $this->crawler->filter('meta[name="keywords"]')->first())->count() > 0) {
            return $keywords->attr("content", '');
        }
    }

    public function getTitleNodes() {
        $title_nodes = [];

        // These all get a slight boost in searching.
        foreach (['h1', 'h2', 'h3', 'h4', 'h5', 'h6'] as $node_name) {
            $title_nodes[$node_name] = array_values(array_unique(array_map(fn($node) => trim($node->textContent), $this->crawler->filter($node_name)->getIterator()->getArrayCopy())));
        }

        return $title_nodes;
    }

    public function getTextSummary() {
        // We hard-code a couple of common templates:
        // #WikiaMainContent, for wikia
        // #bodyContent, for mediawiki
        // article#main-entry, for pmwiki
        // .showthread-posts, for vBulletin
        if (($wiki_text = $this->crawler->filter('#WikiaMainContent, #bodyContent, article#main-entry, #mw-content-text'))->count() > 0) {
            return $wiki_text->text('', true);
        } else if (($wiki_text = $this->crawler->filterXPath('//div[@class="showthread-posts" or normalize-space(@class)="p-body-main"]'))->count() > 0) {
            return $wiki_text->text('', true);
        } else if (($content_text = $this->crawler->filter('#content'))->count() > 0) {
            return $content_text->text('', true);
        } else {
            // This is a rough heuristic, which is by no means perfect, to extract the content that is *significant* in an HTML page.
            // This data can alternatively be provided by Tika (though we don't run Tika on HTML by default)
            return implode(' ', $this->crawler->filterXPath('//*[not(ancestor::head or self::script or self::style or ancestor-or-self::nav or ancestor-or-self::header or ancestor-or-self::footer or ancestor-or-self::noscript or ancestor-or-self::form or ancestor-or-self::*[@id="header" or @id="footer" or @id="sidebar" or @role="banner" or @role="form" or @role="heading" or @role="navigation" or @role="search" or @role="contentinfo" or @aria-hidden="true" or contains(@class, "header") or contains(@class, "footer") or contains(@class, "sidebar")] or ancestor-or-self::a[@rel="nofollow" or @href="#" or starts-with(@href, "javascript:void")])]/text()[normalize-space()]')->each(fn($x) => $x->text()));
        }

        ////Log::debug("HTML text: " . print_r($index['text_summary'], true));
    }

    public function getImage() {
        // Images may be embedded for previewing.
        if (($image = $this->crawler->filter('meta[property="og:image"], meta[property="twitter:image"]')->first())->count() > 0) {
            return UriResolver::resolve($image->attr("content", '') ?? '', $this->crawler->getUri());
        } else if (($image = $this->crawler->filter('a.thumbnail[href]')->first())->count() > 0) {
            return UriResolver::resolve($image->attr("href", '') ?? '', $this->crawler->getUri());
        }
    }

    public function getVideo() {
        // Videos may be embedded for previewing.
        if (($video = $this->crawler->filter('meta[property="og:video"], meta[property="twitter:video"]')->first())->count() > 0) {
            return UriResolver::resolve($video->attr("content", '') ?? '', $this->crawler->getUri());
        }
    }

    public function getContentDate() {

        if (($author = $this->crawler->filter('meta[name="date"], meta[name="DC.Date"], meta[name="DC.Date.Created"]')->first())->count() > 0) {
            return UriResolver::resolve($author->attr("content", '') ?? '', $this->crawler->getUri());
        }

        else if (
            !empty($this->getSchema()['datePublished'])
            && is_string($this->getSchema()['datePublished'])
        ) {
            try {
                return Carbon::parse($this->getSchema()['datePublished'])->toIso8601ZuluString();
            } catch (\Throwable $ex) {
                //Log::warning('Failed to set article publish time: ' . $ex->getMessage());
            }
        }

    }

    public function getContentAuthor() {
        if (($author = $this->crawler->filter('meta[name="author"], meta[name="DC.Creator"]')->first())->count() > 0) {
            return UriResolver::resolve($author->attr("content", '') ?? '', $this->crawler->getUri());
        } else if (
            !empty($this->getSchema()['author']['name'])
            && is_string($this->getSchema()['author']['name'])
        ) {
            return $this->getSchema()['author']['name'];
        }
    }

    public function getScore() {

        // We'll try and extract score from the ld+json schema, if it has been defined.
        // This is definitely too specific at the moment, though.

        if (isset($this->getSchema()['interactionStatistic'])) {
            if (is_numeric($this->getSchema()['interactionStatistic'])) {
                return (float)$this->getSchema()['interactionStatistic'];
            } else if (is_array($this->getSchema()['interactionStatistic'])) {
                if (
                    ($this->getSchema()['interactionStatistic']['interactionType']) ?? '' === 'http://schema.org/LikeAction'
                    && isset($this->getSchema()['interactionStatistic']['userInteractionCount'])
                ) {
                    return (float)$this->getSchema()['interactionStatistic']['userInteractionCount'];
                } else {
                    //Log::debug("Didn't find any score in ld+json: " . var_export($this->getSchema(), true));
                }
            } else {
                //Log::debug("Didn't find any score in ld+json: " . var_export($this->getSchema(), true));
            }
        } else {
            //Log::debug("Didn't find any score in ld+json: " . var_export($this->getSchema(), true));
        }

    }

    private $cached_schema;
    public function getSchema() {
        if (isset($this->cached_schema)) {
            return $this->cached_schema;
        }

        if (($ld_schema = $this->crawler->filter('script[type="application/ld+json"]')->first())->count() > 0) {
            return $this->cached_schema = json_decode($ld_schema->text(), true, 512, JSON_THROW_ON_ERROR);
        }

        return $this->cached_schema = [];
    }

    public function getOutlinks() {
        return collect($this->crawler->filter('a, link')->links())
            ->map(fn($link) => $link->getUri())
            ->merge(
                collect($this->crawler->filter('img, video, source, script'))
                    ->map(fn($img) => UriResolver::resolve($img->getAttribute('src'), $this->crawler->getUri()))
            )
            ->unique()
            ->values()
            ->all();
    }

    public function getCustomMappings() {
        $mappings = [];

        foreach ($this->custom_mappings as $name => $selector) {
            try {
                if ($mapped = array_values(array_filter(array_map('trim', $this->crawler->filterXPath($selector)->each(fn($x) => $x->text()))))) {
                    if ($name === 'image' || $name === 'video') {
                        $mapped = array_map(fn($v) => UriResolver::resolve($v, $this->crawler->getUri()), $mapped);
                    }

                    $mapped = array_values(array_unique($mapped));

                    if ($name === 'score') {
                        $mapped = (float)array_sum(array_map(function ($v) {
                            if (preg_match("/^[\d\.]+$/", $v)) {
                                return $v;
                            }

                            if (preg_match("/^[\d\.]+k$/i", $v)) {
                                return (((float)substr($v, 0, -1)) * 1000);
                            }

                            if (preg_match("/[\d\.]+m/i", $v)) {
                                return (((float)substr($v, 0, -1)) * 1000000);
                            }

                            //Log::warning("Unable to parse custom score value: $v");
                            return 0;
                        }, $mapped));
                    }

                    if (false && $name === 'highscoring_outlinks') {
                        $mapped = collect($mapped)
                            ->map(fn($uri) => UriResolver::resolve($uri, $this->crawler->getUri()))
                            ->toArray();
                    }

                    if ($name === 'is_invalid' || $name === 'nsfw') {
                        $mapped = !empty($mapped);
                    }

                    if ($name === 'subsite') {
                        $mapped = $mapped[0] . '-' . parse_url($this->crawler->getUri())['host'];
                    }

                    if ($name === 'content_date') {
                        $mapped = Carbon::parse($mapped[0])->toIso8601ZuluString();
                    }

                    Arr::set($mappings, $name, $mapped);
                    //Log::info("Used custom selector '{$selector}' for $name, result is: " . implode(",", (array)$mapped));
                } else {
                    Arr::set($mappings, $name, []);
                    //Log::warning("Empty result for custom selector '{$selector}' for $name");
                }
            } catch (\Throwable $ex) {
                throw new \RuntimeException("Failed to map selector: $selector, {$ex->getMessage()}", 0, $ex);
            }
        }

        return $mappings;
    }


}
