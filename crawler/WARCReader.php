<?php

namespace JTP\Crawler;

use http\Message;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use Symfony\Component\DomCrawler\Crawler;

class WARCReader
{

    private $stream;
    private $body;
    private $last_entry;
    private $end_of_file = false;
    private $raw_entry = '';
    private $logger;

    private $awaiting_first_consumption = true;

    public function __construct($stream) {
        $this->stream = $stream;
    }

    /**
     * Seek to a position in the stream.
     *
     * @param int $position
     */
    public function seek($position) {
        fseek($this->stream, $position);
        return $this;
    }

    protected function ensureEntry() {
        if ($this->awaiting_first_consumption) {
            $this->parseNextEntry();
        }
    }

    public function readHeaders() {

        Log::debug('WARCReader: reading headers');

        $headers = [];

        while ($header_line = trim($raw_line = fgets($this->stream))) {

            $this->raw_entry .= $raw_line;

            try {

                $parsed_header_line = array_map('trim', explode(':', $header_line, 2));

                $headers[$parsed_header_line[0]] =
                    isset($headers[$parsed_header_line[0]]) && isset($parsed_header_line[1])
                        ? array_merge((array)$headers[$parsed_header_line[0]], [$parsed_header_line[1]])
                        : ($parsed_header_line[1] ?? []);

            } catch (\Throwable $ex) { throw new \RuntimeException("Failed to process header line: $header_line", 0, $ex); }

        }

        return $headers;

    }

    public function parseNextEntry() {

        /** @var bool We set this to false after processing an entry. This lets us know if a consumption method must first call the parser. */
        $this->awaiting_first_consumption = false;

        /** @var int entry_position Position of the start of this entry. */
        $entry_position = ftell($this->stream);

        /**  */
        $warc_name = basename(stream_get_meta_data($this->stream)['uri']);

        /** @var array Headers read from this entry. */
        $warc_headers = [];

        $this->raw_entry = '';

        /** @var string The body of the WARC section. Usually HTTP. */
        $this->body = '';


        ////Log::debug("Parsing entry at {$warc_name}@{$entry_position}");


        // Skip whitespace.
        while (($warc_header_line = $raw_line = fgets($this->stream)) !== false
            && ($warc_header_line = trim($warc_header_line)) === "") {
            Log::debug("WARCReader: Skipping whitespace");
        };

        $this->raw_entry .= $raw_line;

        // If only whitespace/EOF found, return EOF.
        if ($warc_header_line === false) {
            if (feof($this->stream)) {
                Log::debug('WARCReader: At EOF');
                $this->end_of_file = true;
                return false;
            } else {
                throw new \RuntimeException("Failed to read before end-of-file.");
            }
        }

        // In practice, this mostly helps ensure our reading isn't buggy, and our file isn't corrupt.
        if ($warc_header_line !== "WARC/1.0") {
            throw new \RuntimeException("Invalid section header: [$warc_header_line]. Either your file is corrupt or your stream is currently positioned in the middle of an entry.");
        }


        // Read each WARC header sequentially. Perform basic parsing by splitting on :.
        $warc_headers = $this->readHeaders();
        ////Log::debug("Parsed WARC headers: " . print_r($warc_headers, true));

        // These fields are required.
        if (!isset($warc_headers['WARC-Type']))
            throw new \RuntimeException("No WARC-Type header set in block.");
        if (!isset($warc_headers['Content-Length']))
            throw new \RuntimeException("No Content-Length header set in block.");

        if ($warc_headers['WARC-Type'] === 'response' && !isset($warc_headers['Content-Type']))
            throw new \RuntimeException('No Content-Type header set in block.');
        if (in_array($warc_headers['WARC-Type'], ['response', 'revisit']) && !isset($warc_headers['WARC-Target-URI']))
            throw new \RuntimeException('No WARC-Target-URI header set in block.');


        //$this->info("Type is {$warc_headers['WARC-Type']}, length is {$warc_headers['Content-Length']}.");

        $tmp_file = null;
        $bytes_read = 0;

        if ((in_array($warc_headers['WARC-Type'], ['response', 'revisit']))
            && in_array(($warc_headers['Content-Type'] ?? ''), WARCEntry::HTTP_RESPONSE_TYPE)) {

            $before_http_position = ftell($this->stream);
            $mode = WARCEntry::MODE_HTTP_STREAM;

            {
                while (($http_header_line = $raw_http_line = fgets($this->stream)) !== false
                    && ($http_header_line = trim($http_header_line)) === "");

                Log::info("Read HTTP header line: $http_header_line");

                $this->raw_entry .= $raw_http_line;
            }

            // In practice, this mostly helps ensure our reading isn't buggy, and our file isn't corrupt.
            if (!Str::startsWith($http_header_line, "HTTP/")) {
                throw new \RuntimeException("Invalid HTTP section header: [$http_header_line]. Either your file is corrupt or your stream is currently positioned in the middle of an entry.");
            }

            if (!$http_headers = $this->readHeaders()) {
                Log::warning("Failed to read HTTP headers.");
            }


            Log::debug("WARCReader: Open memory space for HTTP read...");
            $tmp_file = fopen('php://temp', 'x+');
            $content_length = $warc_headers['Content-Length'] - (ftell($this->stream) - $before_http_position);

            while ($bytes_read < $content_length) {
                $read = fread($this->stream, $requested_read_size = min($content_length - $bytes_read, 16 * 1024 * 1024));

                if ($read === false || strlen($read) === 0) {
                    throw new \RuntimeException("Failed to read any data from body, requested $requested_read_size, result was " . print_r($read, true) . ".");
                }

                Log::debug('Read ' . strlen($read) . " bytes, out of {$warc_headers['Content-Length']} total.");

                $bytes_read += strlen($read);
                fwrite($tmp_file, $read);
            }

            Log::debug("WARCReader: Wrote memory space...");

            //////Log::info("Obtained body, length: " . $bytes_read);
            //Log::info('Read HTTP status code: ' . $http_header_line);

            $status_code = explode(' ', $http_header_line)[1];
            if ($status_code < 300 && $status_code > 208) $status_code = 200;
            if ($status_code < 400 && $status_code > 308) $status_code = 300;
            if ($status_code < 500 && $status_code > 417) $status_code = 400;
            else if ($status_code > 511 || $status_code == 509) $status_code = 500;

            Log::debug("Creating HTTP entry with status $status_code");

            //$this->raw_entry = new WARCEntry(response($tmp_file, $http_headers), $warc_headers);
            $message = new \http\Message;
            $message->setType(Message::TYPE_RESPONSE);
            $message->setResponseCode($status_code);
            //$message->setBody(new \http\Message\Body($tmp_file));
            $message->addHeaders($http_headers);

            $this->last_entry = new WARCEntry($mode, $warc_headers, $message, $tmp_file, $warc_name, $entry_position, ftell($this->stream) - $entry_position);

        } else {

            Log::debug("WARCReader: Open memory space for non-HTTP read...");
            $tmp_file = fopen('php://temp', 'r+');

            // Read the entire body using the Content-Length parameter's value.
            while ($bytes_read < (int) $warc_headers['Content-Length']) {
                $bytes_to_read = (int) $warc_headers['Content-Length'] - $bytes_read;
                $read = fread($this->stream, (int) $warc_headers['Content-Length'] - $bytes_read);

                Log::debug('Read ' . strlen($read) . " bytes, out of {$warc_headers['Content-Length']} total.");

                if ($read === false || strlen($read) === 0) {
                    throw new \RuntimeException("Failed to read any data. Requested $bytes_to_read, read $bytes_read successfully so far");
                }

                $bytes_read += strlen($read);
                fwrite($tmp_file, $read);
            }

            Log::debug("WARCReader: Wrote memory space...");

            $this->raw_entry .= $this->body;

            if ($bytes_read !== (int)$warc_headers['Content-Length']) {
                throw new \RuntimeException("Read body did not match Content-Length.");
            }

            ////Log::debug("Obtained body, length: " . $bytes_read);

            $this->last_entry = new WARCEntry(WARCEntry::MODE_STRING, $warc_headers, null, $tmp_file, $warc_name, $entry_position, ftell($this->stream) - $entry_position);

        }

        return $this->last_entry;

    }

    public function isEndOfFile() {
        return $this->end_of_file;
    }

    /*public function __call($name, $params)
    {
        $this->ensureEntry();
        return $this->last_entry->$name(... $params);
    }*/

    /**
     * @return WARCEntry[]
     */
    public function getGenerator($combine_consecutive_entries = true): \Generator {
        /** @var WARCEntry $last_entry */
        $last_entry = null;

        while ($entry = $this->parseNextEntry()) {
            if ($combine_consecutive_entries && $last_entry && $last_entry->getTargetURI() === $entry->getTargetURI()) {
                $last_entry->addAttachedEntry($entry);
            } else {
                if ($last_entry) {
                    yield $last_entry;
                }

                $last_entry = $entry;
            }
        }

        if ($last_entry) {
            yield $last_entry;
        }
    }

}
