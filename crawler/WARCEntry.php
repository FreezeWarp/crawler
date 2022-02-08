<?php


namespace JTP\Crawler;


use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\DomCrawler\Crawler;

class WARCEntry
{

    private $mode;
    private $headers;
    /** @var \http\Message */
    private $subheaders;
    private $body;
    private $elastic_data;
    private $attached_entries = [];
    private $source_file;
    private $source_position;
    private $source_length;
    private $temporary_files = [];

    public const HTTP_RESPONSE_TYPE = ['application/http; msgtype=response', 'application/http;msgtype=response'];
    //const HTTP_RESPONSE_TYPE = 'application/http; msgtype=response';

    /**
     * When in this mode, $body will be a string containing the full payload for non-HTTP requests, and the HTTP body for HTTP requests.
     */
    const MODE_STRING = 0;

    /**
     * When in this mode, $body will be a string to the HTTP body data, while subheaders will be the HTTP headers.
     */
    const MODE_HTTP_STRING = 1;

    /**
     * When in this mode, $body will be a stream resource corresponding to the HTTP body data, while subheaders will be the HTTP headers.
     */
    const MODE_HTTP_STREAM = 2;

    /**
     * Same as MODE_HTTP_STREAM, but the body stream is backed by on-disk storage.
     */
    const MODE_HTTP_FILE_BACKED = 3;



    public function __construct(
        int $mode,
        array $headers,
        $subheaders,
        $body,
        $source_file = null,
        $source_position = null,
        $source_length = null,
        array $elastic_data = []
    ) {

        if ($mode === self::MODE_HTTP_STRING) {
            $body_copy = fopen('php://memory','r+');
            fwrite($body_copy, $body);
            rewind($body_copy);
            $body = $body_copy;

            $mode = self::MODE_HTTP_STREAM;
        }

        $this->mode = $mode;
        $this->headers = $headers;
        $this->subheaders = $subheaders;
        $this->body = $body;
        $this->source_file = $source_file;
        $this->source_position = $source_position;
        $this->source_length = $source_length;
        $this->elastic_data = $elastic_data;

    }

    public function __destruct() {
        foreach ($this->temporary_files AS $temporary_file) {
            //Log::notice("Deleting temporary file: $temporary_file");
            unlink($temporary_file);
        }
    }

    public function addAttachedEntry(WARCEntry $entry): WARCEntry {
        $this->attached_entries[] = $entry;
        return $this;
    }

    /**
     * @return WARCEntry[]
     */
    public function getAttachedEntries(): array {
        return $this->attached_entries;
    }

    public function getOutlinksAttachedEntry(): ?WARCEntry {
        return collect($this->attached_entries)->first(fn(WARCEntry $w) => $w->getWARCType() === 'metadata');
    }


    public function getMode() {
        return $this->mode;
    }

    public function getHeaders() {
        return $this->headers;
    }

    public function getBody() {
        rewind($this->body);
        return $this->body;
    }

    public function getDecodedBody() {
        $body = $this->getBody();

        if ($this->isHttp() && $this->subheaders->getHeader('Transfer-Encoding') === 'chunked') {
            $body2 = fopen('php://memory', 'r+');

            $x = stream_filter_append($body2, 'dechunk', STREAM_FILTER_WRITE);
            stream_copy_to_stream($body, $body2);
            stream_filter_remove($x);

            rewind($body2);
            $body = $body2;
        }

        if ($this->isHttp() && $this->subheaders->getHeader('Content-Encoding') === 'gzip') {
            $body2 = fopen('php://memory', 'r+');

            $contents = stream_get_contents($body);
            fwrite($body2, gzdecode($contents));
            /*$x = stream_filter_append($body2, 'zlib.inflate', STREAM_FILTER_WRITE, ["window" => 15]);
            stream_copy_to_stream($body, $body2);
            stream_filter_remove($x);*/

            rewind($body2);

            $body = $body2;
        }

        if ($this->isHttp() && $this->subheaders->getHeader('Content-Encoding') === 'br') {

            /*$data = stream_get_contents($this->getBody());
            $data2 = (new \http\Encoding\Stream\Dechunk())->decode($data);
            //$data3 = (new \http\Encoding\Stream\Debrotli())->decode($data2);
            $data3 = brotli_uncompress($data2);
            echo $data3;*/

            $decompressed_stream = fopen('php://temp','r+');
            $resource = brotli_uncompress_init();

            while ($chunk = fread($body, 8192)) {
                fwrite($decompressed_stream, brotli_uncompress_add($resource, $chunk, BROTLI_PROCESS));
            }
            fwrite($decompressed_stream, brotli_uncompress_add($resource, '', BROTLI_FINISH));

            rewind($decompressed_stream);

            $body = $decompressed_stream;
        }

        return $body;
    }

    public function getElasticData() {
        return $this->elastic_data;
    }

    public function setElasticData($elastic_data) {
        $this->elastic_data = $elastic_data;
        return $this;
    }

    public function getSourceFile() {
        return $this->source_file;
    }

    public function getSourcePosition() {
        return $this->source_position;
    }

    public function getSourceLength() {
        return $this->source_length;
    }


    public function setHeader($name, $value) {
        $this->headers[$name] = $value;
        return $this;
    }


    public function getBodyAsHTTP(): \http\Message {

        if ($this->subheaders instanceof \http\Message) {
            return $this->subheaders->addBody(new \http\Message\Body($this->getBody()));
        } else {
            throw new \RuntimeException("Invalid state for HTTP usage");
        }

    }

    /**
     * @return string A physical file name that can be used to access the data of this
     */
    public function getBodyAsPhysicalFile($immediate_cleanup = true)
    {
        // We can only reuse the existing stream in MODE_HTTP_STREAM, because it is backed by a file on disk. In other scenarios, we are backed by PHP memory, which cannot be used externally.
        if ($this->mode === self::MODE_HTTP_FILE_BACKED) {
            return stream_get_meta_data($this->getBody())['uri'];
        } else {
            try {
                if ($this->getSourceLength() > Helpers::getGeneralSettings()['stream-file-size']) {
                    throw new \RuntimeException("Too large to write to volatile tmp.");
                }

                $temporary_stream = fopen(tempnam("/tmp/volatile/", "warc"), 'w+');
            } catch (\Throwable $ex) {
                //Log::warning($ex);
                //Log::warning("Unable to allocate volatile file, falling back to normal tmp.");

                try {
                    $temporary_stream = fopen(tempnam("/tmp/", "warc"), 'w+');
                } catch (\Throwable $ex) {
                    throw new \RuntimeException("Unable to allocate temporary file.", 0, $ex);
                }
            }

            stream_copy_to_stream($this->getDecodedBody(), $temporary_stream);
            $temporary_file = stream_get_meta_data($temporary_stream)['uri'];
            fclose($temporary_stream);

            if ($immediate_cleanup)
                $this->temporary_files[] = $temporary_file;
            else register_shutdown_function(fn() => unlink($temporary_file));

            return $temporary_file;
        }

    }

    public function getBodyAsMetadata(): array {

        if ($this->mode !== self::MODE_STRING) {
            throw new \RuntimeException("getBodyAsMetadata: Only supported in string mode.");
        }

        $return = [];

        $records = array_filter(array_map('trim', explode("\n", stream_get_contents($this->getBody()))));

        foreach ($records AS $record) {
            [$name, $value] = explode(":", $record, 2);
            $return[trim($name)][] = trim($value);
        }

        return $return;

    }


    public function getWARCType() {
        return $this->getHeaders()['WARC-Type'];
    }

    public function isWARCResponse() {
        return $this->getWARCType() === 'response';
    }

    public function isSupportedWARCRevisit() {
        return $this->getWARCType() === 'revisit'
            && $this->getHeaders()['WARC-Profile'] === 'mirrorreader2';
    }

    public function getTargetURI() {
        return ((array)(
            $this->getHeaders()['WARC-Target-URI']
            ?? $this->getElasticData()['url']
            ?? [null]
        ))[0];
    }

    public function getWARCContentType() {
        return $this->getHeaders()['Content-Type']
            ?? null;
    }

    public function getContentType() {
        return (
            $this->subheaders
                ? $this->subheaders->getHeader('Content-Type')
                : null
        )
            ?? $this->getElasticData()['content_type']
            ?? null;
    }

    public function isHttp() {
        return in_array($this->getWARCContentType(), self::HTTP_RESPONSE_TYPE);
    }

    //private $cached_crawler;
    public function getHtmlCrawler() {

        if (!$this->isWARCResponse())
            return null;

        if (!$this->isHttp())
            return null;

        if (!Str::startsWith(((array)$this->getBodyAsHTTP()->getHeader('Content-Type'))[0], 'text/html'))
            return null;

        if ($this->getHeaders()['Content-Length'] > Helpers::getGeneralSettings()['stream-file-size']) {
            Log::warning("getHtmlCrawler: Not returning an HTML crawler for a raw/large object.");
            return null;
        }

        return new HTMLMapper(new Crawler(stream_get_contents($this->getDecodedBody()), $this->getTargetURI()));

    }

    private $config_cached;
    public function getSiteConfig() {
        return $this->config_cached = $this->config_cached ?? Helpers::getSpecificSiteSettings($this->getTargetURI());
    }


}
