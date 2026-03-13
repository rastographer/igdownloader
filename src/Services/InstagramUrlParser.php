<?php

namespace Rastographer\IgDownloader\Services;

class InstagramUrlParser
{
    /**
     * @return array{shortcode: string, kind: string}|null
     */
    public function parse(string $url): ?array
    {
        $url = trim($url);

        if (! preg_match('#^https?://#i', $url)) {
            $url = 'https://'.$url;
        }

        $pattern = '~https?://(?:www\.)?(?:m\.)?instagram\.com/'
            .'(?:[^/]+/)?'
            .'(p|reel|tv|reels)/'
            .'([^/?#]+)/?'
            .'(?:[?#].*)?$~i';

        if (preg_match($pattern, $url, $matches) !== 1) {
            return null;
        }

        return [
            'shortcode' => $matches[2],
            'kind' => strtolower($matches[1]),
        ];
    }
}
