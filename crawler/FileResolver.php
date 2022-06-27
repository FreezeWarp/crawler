<?php


namespace JTP\Crawler;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Iterator;

class FileResolver
{

    private $path;
    private $resolved_path;
    private $uncompressed_path;

    public function __construct($path) {
        $this->path = $path;

        /*if (file_exists($this->path)) {
            $this->resolved_path = $this->path;
        } else if (file_exists($this->path . '.br')) {
            $this->resolved_path = $this->path . '.br';
        } else {
            throw new \RuntimeException("Path not found: " . $this->path);
        }*/

        /*$get_file_names_quickly =
            function($dir) use (&$get_file_names_quickly) {
                return collect(scandir($dir))
                    ->flatMap(function($file) use ($dir, &$get_file_names_quickly) {
                        if ($file === '.' || $file === '..') {
                            return [];
                        }

                        if (is_dir($dir . $file)) {
                            return $get_file_names_quickly($dir . $file . '/');
                        }

                        return [$file];
                    });
            };

        $file_names = $get_file_names_quickly(Helpers::getGeneralSettings()['warc-directory']);
        $file_names->each(fn($file) => print($file . "<br />\n"));
        die();

        $files = Helpers::getOrGenerateCache(
            "warcs",
            fn() => array_keys(iterator_to_array(
                (new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator(Helpers::getGeneralSettings()['warc-directory']),
                    \RecursiveIteratorIterator::CHILD_FIRST,
                    \RecursiveIteratorIterator::CATCH_GET_CHILD | \RecursiveIteratorIterator::CHILD_FIRST
                ))
            ))
        );

        foreach ($files AS $file) {
            echo $file . "<br />\n";
            flush();
        }

        $candidates = collect($files)
            ->filter(fn($file) =>
                basename($file) === $this->path
                    || basename($file) === $this->path . '.br'
            )
            ->toArray();*/

    }

    public function getResolvedFilePath() {

        if (!$this->resolved_path) {

            if (file_exists($this->path)) {

                //Log::info("File exists, skipping glob resolution");
                $this->resolved_path = $this->path;

            } else {

                //Log::info("File does not exist, using glob resolution");

                $candidates = glob(Helpers::getGeneralSettings()['warc-directory'] . '/' . $this->path)
                    ?: glob(Helpers::getGeneralSettings()['warc-directory'] . '/' . $this->path . '.br')
                        ?: glob(Helpers::getGeneralSettings()['warc-directory'] . '/*/' . $this->path)
                            ?: glob(Helpers::getGeneralSettings()['warc-directory'] . '/*/' . $this->path . '.br')
                                ?: glob(Helpers::getGeneralSettings()['warc-directory'] . '/*/*/' . $this->path)
                                    ?: glob(Helpers::getGeneralSettings()['warc-directory'] . '/*/*/' . $this->path . '.br');

                if (empty($candidates)) {
                    throw new \RuntimeException("No WARC found to open: " . $this->path);
                }

                $this->resolved_path = $candidates[0];

            }

        }

        return $this->resolved_path;

    }

    public function getUncompressedFilePath() {

        if (!$this->uncompressed_path) {

            if (Str::endsWith($this->getResolvedFilePath(), '.br')) {

                if (!file_exists($tmp_file = Helpers::getGeneralSettings()['cache-directory'] . '/' . preg_replace("/\.br$/", "", basename($this->getResolvedFilePath())))) {

                    //Log::info("File is br encoded, decompressing...");

                    $brotli = shell_exec("brotli -d -n -o " . escapeshellarg($tmp_file) . " " . escapeshellarg($this->getResolvedFilePath()));

                    if (!file_exists($tmp_file)) {
                        throw new \RuntimeException("Failed to create decompressed temp file: $tmp_file, brotli returned: " . $brotli);
                    }

                    $files = glob(Helpers::getGeneralSettings()['cache-directory'] . '/*.warc');
                    $total_size = collect($files)->map(fn($f) => filesize($f))->sum();
                    //Log::info("Total size of warc files in cache directory: $total_size");
                    if ($total_size > Helpers::getGeneralSettings()['cache-cleanup-size']) {
                        $oldest_files = collect($files)->sort(fn($file1, $file2) => filemtime($file2) - filemtime($file1));
                        Log::info("WARC cache is too large, deleting old files: " . $oldest_files->implode(", "));

                        while ($total_size > Helpers::getGeneralSettings()['cache-cleanup-size']) {
                            $file_to_remove = $oldest_files->pop();
                            $total_size -= filesize($file_to_remove);
                            Log::info("Removing $file_to_remove, size is " . filesize($file_to_remove));
                            unlink($file_to_remove);
                        }
                    }

                }

                $this->uncompressed_path = $tmp_file;

            } else {

                $this->uncompressed_path = $this->getResolvedFilePath();

            }

        }

        return $this->uncompressed_path;

    }

    public function getBaseName() {

        return preg_replace("/\.br$/", "", basename($this->path));

    }

    public function openAsWARC($seek_to = 0) {

        $stream = fopen($this->getUncompressedFilePath(), 'rb');

        /*$stream = \Elastic\APM\ElasticApm::getCurrentTransaction()->captureChildSpan(
            'FileResolver::openAsWARC::fopen', 'filesystem',
            fn($span) => fopen($this->resolved_path, 'rb')
        );*/

        if ($seek_to) {
            fseek($stream, $seek_to);
            /*\Elastic\APM\ElasticApm::getCurrentTransaction()->captureChildSpan(
                'FileResolver::openAsWARC::fseek', 'filesystem',
                fn($span) => fseek($stream, $seek_to)
            );*/
        }

        return $stream;

    }

}
