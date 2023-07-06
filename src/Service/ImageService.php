<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

class ImageService
{
    private $parameterBag;
    private $cache;

    public function __construct(ParameterBagInterface $parameterBag, CacheInterface $cache)
    {
        $this->parameterBag = $parameterBag;
        $this->cache = $cache;
    }

    private function loadDataSources(): array
    {
        return [
            [
                'rssUrl' => 'https://www.commitstrip.com/fr/feed/',
                'apiUrl' => 'https://newsapi.org/v2/top-headlines?country=us&apiKey=' . $this->parameterBag->get('NEWSAPI_API_KEY'),
            ],
        ];
    }

    public function getFilteredLinks($rssLink, $apiUrl): array
    {
        $rssLinks = $this->getRssLinks($rssLink);
        $apiLinks = $this->getApiLinks($apiUrl);

        $allLinks = array_merge($rssLinks, $apiLinks);
        $uniqueLinks = array_unique($allLinks);

        return $uniqueLinks;
    }

    private function getRssLinks($rssUrl): array
    {
        $rssContent = $this->fetchUrlContent($rssUrl);
        $rssData = simplexml_load_string($rssContent);

        if (!$rssData || !isset($rssData->channel)) {
            return [];
        }

        $rssItems = $rssData->channel->item;

        $filteredLinks = [];
        foreach ($rssItems as $item) {
            $link = (string) $item->link;
            if ($this->hasImage($item)) {
                $filteredLinks[] = $link;
            }
        }
        return $filteredLinks;
    }

    private function getApiLinks($apiUrl): array
    {
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $apiUrl);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_USERAGENT, 'localhost');

        $apiResponse = curl_exec($curl);
        curl_close($curl);

        $articles = json_decode($apiResponse)->articles;

        $filteredLinks = [];
        foreach ($articles as $article) {
            if (!empty($article->urlToImage)) {
                $filteredLinks[] = $article->url;
            }
        }
        return $filteredLinks;
    }

    private function fetchUrlContent(string $url): string
    {
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
        ]);
        $response = curl_exec($curl);
        curl_close($curl);

        return $response;
    }

    private function hasImage(\SimpleXMLElement $item): bool
    {
        $content = (string) $item->children('content', true);
        $imageExtensions = ['jpg', 'JPG', 'GIF', 'gif', 'PNG', 'png'];

        foreach ($imageExtensions as $extension) {
            if (strpos($content, $extension) !== false) {
                return true;
            }
        }

        return false;
    }

    public function getImageFromPage(string $url): string
    {
        $doc = new \DomDocument();
        @$doc->loadHTMLFile($url);
        $xpath = new \DomXpath($doc);

        if (strpos($url, 'commitstrip.com') !== false) {
            $query = '//img[contains(@class, "size-full")]/@src';
        } else {
            $query = '//img/@src';
        }

        $imageNode = $xpath->query($query)->item(0);
        if ($imageNode) {
            return $imageNode->value;
        }

        return '';
    }

    public function loadImages(): array
    {
        $config = $this->loadDataSources();

        $images = [];
        foreach ($config as $source) {
            try {
                $links = $this->getFilteredLinks($source['rssUrl'], $source['apiUrl']);
                foreach ($links as $link) {
                    $cacheKey = $this->getCacheKey($link);
                    $image = $this->cache->get($cacheKey, function (ItemInterface $item) use ($link) {
                        $item->expiresAfter(3600);
                        return $this->getImageFromPage($link);
                    });
                    $images[] = $image;
                }
            } catch (\Exception $e) {
                die(var_dump($e->getMessage()));
            }
        }
        return $images;
    }

    private function getCacheKey($url): string
    {
        $reservedCharacters = ['{', '}', '(', ')', '/', '\\', '@', ':'];
        $cleanedUrl = preg_replace('/[' . preg_quote(implode('', $reservedCharacters), '/') . ']/', '', $url);
        return 'cache_key_' . $cleanedUrl;
    }
}
