<?php


namespace JTP\Crawler;


use Carbon\Carbon;
use http\Message;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\DomCrawler\Crawler;

class WARCWriter
{

    private $stream;
    private $body;
    private $entry_position;
    private $last_entry;
    private $end_of_file = false;
    private $raw_entry = '';

    private $awaiting_first_consumption = true;

    const HTTP_RESPONSE_TYPE = 'application/http; msgtype=response';

    public function __construct($stream) {
        $this->stream = $stream;
    }

    public function writeStart() {

        flock($this->stream, LOCK_EX);
        fwrite($this->stream, "WARC/1.0\r\n");
        fwrite($this->stream, "WARC-Type: warcinfo\r\n");
        fwrite($this->stream, "WARC-Date: " . Carbon::now()->toIso8601ZuluString() . "\r\n");
        fwrite($this->stream, "WARC-Filename: " . basename(stream_get_meta_data($this->stream)['uri']) . "\r\n");
        fwrite($this->stream, "Content-Type: application/warc-fields\r\n");
        fwrite($this->stream, "Content-Length: 0\r\n\r\n\r\n\r\n");
        flock($this->stream, LOCK_UN);

        return $this;

    }

    public function writeEntry(WARCEntry $entry, $write_http_body = true) {

        $start_pos = ftell($this->stream);
        flock($this->stream, LOCK_EX);

        fwrite($this->stream, "WARC/1.0\r\n");

        foreach ($entry->getHeaders() AS $header_name => $header_value) {
            if ($header_name === 'Content-Length') continue; // We'll compute this ourselves.

            foreach ((array) $header_value AS $value) {
                fwrite($this->stream, "{$header_name}: {$value}\r\n");
            }
        }

        if ($entry->isHttp()) {

            $http = $entry->getBodyAsHTTP();

            if (!$write_http_body) {
                $http->setBody(new \http\Message\Body(fopen('php://memory','r+')));
            }

            $length = strlen($http->toString()); // TODO: memory-friendly version would be nice
            fwrite($this->stream, "Content-Length: $length\r\n\r\n");

            //Log::info("Length is " . $length);

            $http->toStream($this->stream);

        } else {
            throw new \RuntimeException("Not yet implemented.");
        }

        fwrite($this->stream, "\r\n\r\n");
        flock($this->stream, LOCK_UN);

        return ftell($this->stream) - $start_pos;

    }


    protected static $open_target_warcs = [];
    public static function getTargetWARC($target_uri) {

        $too_large = false;

        if (!$host = parse_url(UrlParser::cleanUrl($target_uri))['host']) {
            throw new \RuntimeException("No host from target URI: $host");
        }

        if (strlen($host) > 200) {
            $host = '_overly_long_host_name_';
        }

        /*
         * We want to avoid exceeding the file handle limit.
         * Note that this is not the most optimal decent (that would be to close the LRU handle), but that would ultimately be a very small optimization in general.
         */
        //if (count(self::$open_target_warcs) > 500) {
        if (count(self::$open_target_warcs) > 5000) {
            $key = array_key_first(self::$open_target_warcs);
            Log::info("Too many open file handles. Closing oldest: $key");
            fclose(self::$open_target_warcs[$key]);
            unset(self::$open_target_warcs[$key]);
        }

        /*
         * If a handle already exists, return it if it's below the rollover limit, or otherwise close it and continue.
         */
        if (isset(self::$open_target_warcs[$host])) {
            if (ftell(self::$open_target_warcs[$host]) > Helpers::getGeneralSettings()['warc-write-rollover-size']) {
                Log::info("Previously-opened file is now too large, rolling over");
                fclose(self::$open_target_warcs[$host]);
                unset(self::$open_target_warcs[$host]);
            } else {
                return self::$open_target_warcs[$host];
            }
        }

        /*
         * Find the highest numbered file for the host. If it is below the rollover limit, use it.
         * Otherwise, create a new file that is +1 from the highest numbered file currently existing for the host.
         */
        /*if ($existing_file = collect(scandir(Helpers::getGeneralSettings()['warc-write-directory']))
            ->filter(fn($f) => Str::startsWith($f, $host . "_") && preg_match("#_\d+\.warc(\.br|)$#", $f))
            ->sort('natsort')
            ->reverse()
            ->first()) {
            Log::info("Found existing: $existing_file");
            $existing_file = Helpers::getGeneralSettings()['warc-write-directory'] . "/{$existing_file}";

            $too_large = $too_large || filesize($existing_file) > (int) Helpers::getGeneralSettings()['warc-write-rollover-size'];
            $is_br = Str::endsWith($existing_file, ".br");

            if ($too_large || $is_br) {
                if ($is_br) {
                    Log::info("$existing_file is compressed, skipping");
                } else if ($too_large) {
                    Log::info("$existing_file is larger than limit, rolling over");
                }

                preg_match("#_(\d+)\.warc(\.br|)$#", $existing_file, $matches);

                $next_file = $host . '_' . sprintf("%'.03d", $matches[1] + 1) . '.warc';
                Log::info("Next file: $next_file");

                self::$open_target_warcs[$host] = fopen(Helpers::getGeneralSettings()['warc-write-directory'] . "/{$next_file}", "x"); // Open in create mode for safety
            } else {
                self::$open_target_warcs[$host] = fopen($existing_file, "a"); // Open in append mode for safety
            }

        }*/

        /*
         * Finally, if no file was found, this is the first time we're writing for the host -- create the 1-numbered file.
         */
        self::$open_target_warcs[$host] = self::$open_target_warcs[$host] ?? fopen(Helpers::getGeneralSettings()['warc-write-directory'] . "/{$host}_" . time() . "_" . uniqid("", true) . ".warc", "x"); // Open in create mode for safety

        if (!self::$open_target_warcs[$host]) {
            throw new \RuntimeException("Unable to open WARC file.");
        }

        return self::$open_target_warcs[$host];
    }

}
