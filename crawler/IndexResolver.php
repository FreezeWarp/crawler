<?php


namespace JTP\Crawler;


use ESQueryBuilder\ESUnionBuilder;
use ESQueryBuilder\Macros;
use Illuminate\Support\Arr;
use Symfony\Component\DomCrawler\UriResolver;

class IndexResolver
{
    private $uri;
    private $best_effort = false;

    function __construct($uri, $best_effort = false) {
        $this->uri = $uri;
        $this->best_effort = $best_effort;
    }

    /**
     * @return string {@see Processor#file}
     */
    public function getUri() {
        return $this->uri;
    }




    /**
     * Get indexed matches for given URL.
     */
    public function getMatches() {

        // A protocol is defined.
        if (\Illuminate\Support\Str::contains($this->uri, ':')) {
            $parser = (new UrlParser($this->uri));
            $canonical = (new UrlParser($parser->getCanonicalVariants(UrlParser::CANONICALIZE_DUPLICATES)[0]));

            return iterator_to_array(
                (new ESUnionBuilder(
                    (new ESQueryBuilder('crawl-data'))
                        ->where(Macros::should(
                            Macros::must(
                                Macros::term('warc_headers.WARC-Type', 'response'),
                                Macros::term('http_response.status', 200),
                                Macros::terms('url', $parser->getCanonicalVariants(UrlParser::CANONICALIZE_DUPLICATES | UrlParser::CANONICALIZE_DUPLICATES_UNCERTAIN | UrlParser::FILETYPE_EQUIVALENTS))
                            ),
                            Macros::must(
                                Macros::term('?local_file', true),
                                Macros::term('url', urldecode($this->uri))
                            )
                        ))
                        ->sort(['warc_headers.WARC-Date' => 'desc', 'updated_at' => 'desc']),

                    (new ESQueryBuilder('crawl-data'))
                        ->where(Macros::should(
                            Macros::terms('url',  $parser->getCanonicalVariants(UrlParser::CANONICALIZE_DUPLICATES)),
                            Macros::terms('url', $parser->getCanonicalVariants()),
                            Macros::terms('fully_canonicalized_url', $parser->getCanonicalVariants())
                        ))
                        ->where(Macros::mustNot(Macros::term( 'http_response.status', '307')))
                        ->sort(['warc_headers.WARC-Date' => 'desc', 'updated_at' => 'desc']),

                    $this->best_effort
                        ? (new ESQueryBuilder('crawl-data'))
                            ->where(Macros::must(
                                Macros::terms('url_parts.basename', [
                                    $parser->getParts()['basename'],
                                    $canonical->getParts()['basename']
                                ]),
                                Macros::should(
                                    Macros::terms('url_parts.tlds', $parser->getParts()['tlds']),
                                    Macros::terms('url_parts.tlds', $canonical->getParts()['tlds'])
                                )
                            ))
                            ->where(Macros::term( 'http_response.status', '200'))
                            ->sort(['warc_headers.WARC-Date' => 'desc', 'updated_at' => 'desc'])
                        : null
                ))
                    ->withRange(0, 10)
                    ->execQuery()
            );
        }

        // A protocol is not defined -- use raw index ID
        else {
            return (new ESQueryBuilder('crawl-data'))
                ->where(Macros::term('_id', $this->uri))
                ->execQuery();
        }

    }


    /**
     * Get the contents of a file.
     *
     * @param $file
     * @return bool
     */
    public function getWARCEntry(): WARCEntry {

        $data = Arr::first($this->getMatches() ?? []);

        if (($data['warc_headers']['WARC-Type'] ?? '') === 'revisit') {

            $data = (new ESQueryBuilder('crawl-data'))
                ->where(Macros::terms('http_response.sha256', $data['warc_headers']['HTTP-SHA256-REF']))
                ->sort(['warc_headers.WARC-Date' => 'asc'])
                ->withRange(0, 1)
                ->execQueryToCollection()
                ->first();

        }

        if (!isset($data)) {

            throw new \RuntimeException("File not found yo: {$this->uri}");

        }
        else if (($data['http_response']['status'] ?? '') >= 300 && ($data['http_response']['status'] ?? '') < 400) {

            if (empty($data['http_response']['headers']['Location'])) {
                //throw new \RuntimeException("Redirect status has no Location: " . var_export($data['http_response'], true));

                $entry = (new WARCReader(
                    (new FileResolver($data['source_file']))->openAsWARC($data['position'])
                ))->parseNextEntry();

                $entry->setElasticData($data);

                return $entry;

            } else {
                return (new IndexResolver(UriResolver::resolve($data['http_response']['headers']['Location'], $this->getUri())))
                    ->getWARCEntry();
            }

        }

        else if ($data['local_file'] ?? false) {
            return (new WARCEntry(WARCEntry::MODE_HTTP_FILE_BACKED, [], [], fopen($data['source_file'], 'r')))
                ->setElasticData($data);
        }

        else {

            $entry = (new WARCReader(
                (new FileResolver($data['source_file']))->openAsWARC($data['position'])
            ))->parseNextEntry();

            $entry->setElasticData($data);

            return $entry;

        }

    }

}
