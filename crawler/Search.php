<?php


namespace JTP\Crawler;

use ESQueryBuilder\Macros;

class Search
{

    protected $term;
    protected $offset;
    protected $limit;

    public function __construct($term, $limit = 20, $offset = 0) {
        $this->term = $term;
        $this->limit = $limit;
        $this->offset = $offset;
    }

    public static function getScoreQueries($field = 'subsite') {

        return Helpers::getOrGenerateCache("score-queries-{$field}", function() use ($field) {

            $agg = collect(\App\Code\ElasticSearch::getClient()->search([
                'index' => 'crawl-data',
                'body' => [
                    'size' => 0,
                    'query' => [
                        'bool' => [
                            'must' => [
                                /*[
                                    'filter' => [
                                        'term' => [
                                            'is_canonical' => true
                                        ]
                                    ],
                                ],*/
                                ['exists' => ['field' => 'score']]
                            ]
                        ]
                    ],
                    'aggs' => [
                        'host' => [
                            'terms' => [
                                'field' => $field,
                                'size' => Helpers::getGeneralSettings()['top_host_count']
                            ],
                            'aggs' => [
                                'average_score' => [
                                    'percentiles' => [
                                        'field' => 'score',
                                        'percents' => array_keys(Helpers::getGeneralSettings()['percentile_score_boosts'])
                                    ]
                                ]
                            ]
                        ]
                    ]
                ]
            ])['aggregations']['host']['buckets'])
                ->mapWithKeys(fn($b) => [$b['key'] => collect($b['average_score']['values'])->keyBy(fn($v, $k) => intval($k))]);

            return collect(Helpers::getGeneralSettings()['percentile_score_boosts'])
                ->map(fn($weight, $percentile) => [
                    'filter' => [
                        'bool' => [
                            'should' => $agg
                                ->map(fn($scores) => $scores->get($percentile))
                                ->map(fn($score, $host) =>
                                    $percentile == 0
                                        ? Macros::matchAll()
                                        : [
                                            'bool' => [
                                                'must' => [
                                                    ['range' => ['score' => ['gte' => $score]]],
                                                    ['term' => [$field => $host]]
                                                ]
                                            ]
                                        ]
                                )
                                ->values()
                                ->toArray()
                        ]
                    ],
                    'weight' => $weight
                ])
                ->values()
                ->toArray();

        }, 0);

    }

    public function getFavBoosts() {

        return Helpers::getOrGenerateCache('fav-boosts', function() {

            $total = (new ESQueryBuilder())
                ->withConditions([])
                ->where(Macros::term('?fav', true))
                ->execCount();

            return (new ESQueryBuilder())
                ->withConditions([])
                ->where(Macros::term('?fav', true))
                ->execAggregationToCollection([
                    'terms' => [
                        'field' => 'keywords',
                        'size' => 1000
                    ]
                ])
                ->map(fn($bucket) => [
                    'filter' => Macros::term('keywords', $bucket['key']),
                    'weight' => 1 + ($bucket['doc_count'] / $total / 4)
                ])
                ->values()
                ->toArray();

        }, 0);

    }

    public function getESQueryBuilder() {
        return (new ESQueryBuilder('crawl-data'))
            ->withRange($this->offset, $this->limit)
            ->highlight([
                'pre_tags' => ['<strong>'],
                'post_tags' => ['</strong>'],
                'fields' => [
                    'title' => new \stdClass(),
                    'description' => new \stdClass(),
                    'text_summary' => new \stdClass()
                ]
            ])
            ->where(Macros::must(
                Macros::should(
                    Macros::must(
                        Macros::term('warc_headers.WARC-Type', 'response'),
                        Macros::term('http_response.status', 200)
                    ),
                    Macros::term('?local_file', true)
                ),
                [
                    'function_score' => [
                        'query' => [
                            'bool' => [
                                'should' => [
                                    [
                                        'query_string' => [
                                            'query' => $this->term,
                                            //'minimum_should_match' => '75%',
                                            'phrase_slop' => 5,
                                            'fields' => [
                                                'url_parts.host.keyword^500',
                                                'url_parts.host^50',
                                                'url_parts.basename^50',
                                                'url^10',
                                                'title^10',
                                                'keywords^10',
                                                'description^5',
                                                'warc_headers.WARC-Target-URI^5',
                                                'title_nodes.h1^3',
                                                'title_nodes.h2^2',
                                                'title_nodes.h3',
                                                'title_nodes.h4',
                                                'title_nodes.h5',
                                                'title_nodes.h6',
                                                'text_summary^.3',
                                                'body^.1'
                                            ],
                                            'type' => 'phrase'
                                        ]
                                    ]
                                ]
                            ]
                        ],
                        'functions' => array_merge(
                            [
                                [
                                    'filter' => [
                                        'term' => [
                                            'is_canonical' => false
                                        ]
                                    ],
                                    'weight' => 0
                                ],
                                [
                                    'filter' => [
                                        'term' => [
                                            '?fav' => true
                                        ]
                                    ],
                                    'weight' => 100
                                ],
                                [
                                    'filter' => [
                                        'exists' => [
                                            'field' => 'local_file'
                                        ]
                                    ],
                                    'weight' => 100
                                ],

                                /*
                                 * Deprioritize forum results:
                                 * [
                                    'filter' => [
                                        'terms' => [
                                            // 'directory_parts.keyword' => ['forums', 'forum']
                                            'domain_parts.keyword' => ['forums', 'forum']
                                        ]
                                    ],
                                    'weight' => 0.5
                                ]*/
                            ],
                            collect(Helpers::getGeneralSettings()['domain_boosts'])
                                ->map(fn($weight, $host) => [
                                    'filter' => ['term' => ['url_parts.host' => $host]],
                                    'weight' => $weight
                                ])
                                ->values()
                                ->toArray(),
                            self::getScoreQueries('subsite'),
                            self::getScoreQueries('url_parts.host'),
                            self::getFavBoosts()
                        )
                    ]
                ]
            ));
    }

    public function exec() {
        return $this->getESQueryBuilder()
            ->execQuery();
    }
}
