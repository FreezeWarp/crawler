<?php


namespace JTP\Crawler;


use Carbon\Carbon;
use ESQueryBuilder\ESQueryBuilder;
use ESQueryBuilder\Macros;
use InvalidArgumentException;
use League\Flysystem\Config;
use League\Flysystem\FileExistsException;
use League\Flysystem\FileNotFoundException;
use League\Flysystem\FilesystemInterface;
use League\Flysystem\Handler;
use League\Flysystem\PluginInterface;
use League\Flysystem\RootViolationException;

class FlyAdapter implements \League\Flysystem\AdapterInterface
{

    /**
     * Check whether a file exists.
     *
     * @param string $path
     *
     * @return bool
     */
    public function has($path)
    {
        return true;
        // TODO: Implement has() method.
    }

    /**
     * Read a file.
     *
     * @param string $path The path to the file.
     *
     * @return string|false The file contents or false on failure.
     * @throws FileNotFoundException
     *
     */
    public function read($path)
    {
        return stream_get_contents(
            (new IndexResolver('https://' . $path))
                ->getWARCEntry()
                ->getBody()
        );
        // TODO: Implement read() method.
    }

    /**
     * Retrieves a read-stream for a path.
     *
     * @param string $path The path to the file.
     *
     * @return resource|false The path resource or false on failure.
     * @throws FileNotFoundException
     *
     */
    public function readStream($path)
    {
        return ['type' => 'file', 'path' => $path, 'stream' => (new IndexResolver('https://' . $path))
            ->getWARCEntry()
            ->getBody()];
    }

    /**
     * List contents of a directory.
     *
     * @param string $directory The directory to list.
     * @param bool $recursive Whether to list recursively.
     *
     * @return array A list of file metadata.
     */
    public function listContents($directory = '', $recursive = false)
    {
        if ($directory === '') {
            return (new ESQueryBuilder('crawl-data'))
                ->execAggregationToCollection([
                    'terms' => [
                        'field' => 'url_parts.host',
                        'size' => 1000
                    ]
                ])
                ->map(fn($f) => [
                    'type' => 'dir',
                    'path' => $f['key'] . '/',
                    'size' => $f['doc_count']
                ])
                ->all();
        } else {

            $x = (new ESQueryBuilder('crawl-data'))
                ->where(Macros::wildcard('url', 'https://' . $directory . '/*/*'))
                ->where(Macros::term('warc_headers.WARC-Type', 'response'))
                ->where(Macros::term( 'http_response.status', '200'))
                ->execAggregationToCollection([
                    'terms' => [
                        'script' => [
                            'params' => [
                                'pre' => "https://{$directory}"
                            ],
                            'source' => "doc['url.keyword'].value.replace(params.pre, '').splitOnToken('/')[1]"
                        ],
                        'size' => 1000
                    ]
                ])
                ->map(fn($f) => [
                    'type' => 'dir',
                    'path' => $directory . '/' . $f['key'] . '/'
                ])
                ->merge(
                        (new ESQueryBuilder('crawl-data'))
                        ->where(Macros::wildcard('url', 'https://' . $directory . '/?*'))
                        ->where(Macros::mustNot(Macros::wildcard('url', 'https://' . $directory . '/*/*')))
                        ->where(Macros::term('warc_headers.WARC-Type', 'response'))
                        ->where(Macros::term( 'http_response.status', '200'))
                        ->withCollapse(['field' => 'url'])
                        ->withRange(0, 1000)
                        ->sort(['url'])
                        ->execQueryToCollection()
                        ->map(fn($f) => [
                            'type' => 'file',
                            'path' => preg_replace('#^https?://#', '', ((array)$f['url'])[0]),
                            'timestamp' => Carbon::parse($f['warc_headers']['WARC-Date'])->timestamp,
                            'size' => $f['http_response']['headers']['Content-Length'] ?? 0
                        ])
                )
                ->values()
                ->all();

            //var_dump($x);
                return $x;
        }

        // TODO: Implement listContents() method.
    }

    /**
     * Get a file's metadata.
     *
     * @param string $path The path to the file.
     *
     * @return array|false The file metadata or false on failure.
     * @throws FileNotFoundException
     *
     */
    public function getMetadata($path)
    {
        $entry = (new IndexResolver('https://' . $path))
            ->getMatches();

        if (count($entry)) {
            $entry = array_values($entry)[0];

            return [
                'type' => 'file',
                'path' => preg_replace('#^https://#', '', $entry['url']),
                'timestamp' => $entry['warc_headers']['WARC-Date'],
                'size' => $entry['http_response']['headers']['Content-Length'],
                'mimetype' => $entry['http_response']['headers']['Content-Type']
            ];
        } else {
            return false;
        }
        // TODO: Implement getMetadata() method.
    }

    /**
     * Get a file's size.
     *
     * @param string $path The path to the file.
     *
     * @return int|false The file size or false on failure.
     * @throws FileNotFoundException
     *
     */
    public function getSize($path)
    {
        return $this->getMetadata($path);
        // TODO: Implement getSize() method.
    }

    /**
     * Get a file's mime-type.
     *
     * @param string $path The path to the file.
     *
     * @return string|false The file mime-type or false on failure.
     * @throws FileNotFoundException
     *
     */
    public function getMimetype($path)
    {
        return $this->getMetadata($path);
    }

    /**
     * Get a file's timestamp.
     *
     * @param string $path The path to the file.
     *
     * @return int|false The timestamp or false on failure.
     * @throws FileNotFoundException
     *
     */
    public function getTimestamp($path)
    {
        return $this->getMetadata($path);
    }

    /**
     * Get a file's visibility.
     *
     * @param string $path The path to the file.
     *
     * @return string|false The visibility (public|private) or false on failure.
     * @throws FileNotFoundException
     *
     */
    public function getVisibility($path)
    {
        return 'public';
    }

    /**
     * Write a new file.
     *
     * @param string $path
     * @param string $contents
     * @param Config $config Config object
     *
     * @return array|false false on failure file meta data on success
     */
    public function write($path, $contents, Config $config)
    {
        return false;
        // TODO: Implement write() method.
    }

    /**
     * Write a new file using a stream.
     *
     * @param string $path
     * @param resource $resource
     * @param Config $config Config object
     *
     * @return array|false false on failure file meta data on success
     */
    public function writeStream($path, $resource, Config $config)
    {
        return false;
        // TODO: Implement writeStream() method.
    }

    /**
     * Update a file.
     *
     * @param string $path
     * @param string $contents
     * @param Config $config Config object
     *
     * @return array|false false on failure file meta data on success
     */
    public function update($path, $contents, Config $config)
    {
        return false;
        // TODO: Implement update() method.
    }

    /**
     * Update a file using a stream.
     *
     * @param string $path
     * @param resource $resource
     * @param Config $config Config object
     *
     * @return array|false false on failure file meta data on success
     */
    public function updateStream($path, $resource, Config $config)
    {
        return false;
        // TODO: Implement updateStream() method.
    }

    /**
     * Rename a file.
     *
     * @param string $path
     * @param string $newpath
     *
     * @return bool
     */
    public function rename($path, $newpath)
    {
        return false;
        // TODO: Implement rename() method.
    }

    /**
     * Copy a file.
     *
     * @param string $path
     * @param string $newpath
     *
     * @return bool
     */
    public function copy($path, $newpath)
    {
        return false;
        // TODO: Implement copy() method.
    }

    /**
     * Delete a file.
     *
     * @param string $path
     *
     * @return bool
     */
    public function delete($path)
    {
        return false;
        // TODO: Implement delete() method.
    }

    /**
     * Delete a directory.
     *
     * @param string $dirname
     *
     * @return bool
     */
    public function deleteDir($dirname)
    {
        return false;
        // TODO: Implement deleteDir() method.
    }

    /**
     * Create a directory.
     *
     * @param string $dirname directory name
     * @param Config $config
     *
     * @return array|false
     */
    public function createDir($dirname, Config $config)
    {
        return false;
        // TODO: Implement createDir() method.
    }

    /**
     * Set the visibility for a file.
     *
     * @param string $path
     * @param string $visibility
     *
     * @return array|false file meta data
     */
    public function setVisibility($path, $visibility)
    {
        return false;
        // TODO: Implement setVisibility() method.
    }
}
