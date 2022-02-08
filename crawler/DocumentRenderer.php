<?php


namespace JTP\Crawler;


use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\UriResolver;

class DocumentRenderer
{
    /**
     * @var WARCEntry The entry this renderer is handling. WARCEnter is used mostly for convenience -- you can create a dummy WARCEntry if needed.
     */
    private $entry;

    public function __construct(WARCEntry $entry, $type_override = null) {
        $this->entry = $entry;
    }
    /**
     * @return string The contents of a file, transformed according to the filetype.
     */
    public function getContents() {

        if ($this->error)
            throw new \Exception('Cannot getContents() when an error has been triggered: ' . $this->error);

        $response = $this->getRawContents();

        if ($response->isRawFile()) {
            //fpassthru($response->getBodyAsHTTP()->getBody()->getResource());
            return response()->file(stream_get_meta_data($response->getBody())['uri']);
            //->header('Content-Type', $http_response->getHeader('Content-Type'));*/
        }

        if (!$response->isHttp()) {
            return response($response->getBody());
        }

        $new_contents = $response->getBodyAsHTTP()->getBody()->toString();

        switch (explode(';', $this->getContentType())[0]) {
            case 'text/html':
                if ($this->requestedType === 'image') {
                    $crawler = new Crawler($new_contents, $this->getFile());

                    if (($image = $crawler->filter('meta[property="og:image"], meta[property="twitter:image"]')->first())->count() > 0) {
                        $this->setFile(UriResolver::resolve($image->attr("content", ''), $this->getFile()));
                        return $this->getContents();
                    }
                }

                $new_contents = $this->processHtml($new_contents);
                break;

            case 'text/css':
                $new_contents = $this->processCSS($new_contents);        break;

            case 'text/javascript':
                $new_contents = $this->processJavascript($new_contents); break;
        }

        $r = response($new_contents)
            ->header('Content-Type', $this->getContentType());

        if ($this->config['cookies']) {
            $r->header('Set-Cookie', $this->config['cookies'] ?? '');
        }

        return $r;
    }
}
