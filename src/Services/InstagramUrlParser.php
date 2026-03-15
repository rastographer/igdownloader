<?php

namespace Rastographer\IgDownloader\Services;

class InstagramUrlParser
{
    /**
     * @return array{shortcode: string, kind: string, username?: string}|null
     */
    public function parse(string $url): ?array
    {
        $url = trim($url);

        if (! preg_match('#^https?://#i', $url)) {
            $url = 'https://'.$url;
        }

        $hostPattern = '~^https?://(?:www\.)?(?:m\.)?instagram\.com/~i';

        if (preg_match($hostPattern, $url) !== 1) {
            return null;
        }

        $storyPattern = '~https?://(?:www\.)?(?:m\.)?instagram\.com/stories/([^/?#]+)/([^/?#]+)/?(?:[?#].*)?$~i';

        if (preg_match($storyPattern, $url, $matches) === 1) {
            return [
                'shortcode' => $matches[2],
                'kind' => strtolower($matches[1]) === 'highlights' ? 'highlight' : 'story',
                'username' => $matches[1],
            ];
        }

        $highlightPattern = '~https?://(?:www\.)?(?:m\.)?instagram\.com/stories/highlights/([^/?#]+)/?(?:[?#].*)?$~i';

        if (preg_match($highlightPattern, $url, $matches) === 1) {
            return [
                'shortcode' => $matches[1],
                'kind' => 'highlight',
            ];
        }

        $mediaPattern = '~https?://(?:www\.)?(?:m\.)?instagram\.com/'
            .'(?:[^/]+/)?'
            .'(p|reel|tv|reels)/'
            .'([^/?#]+)/?'
            .'(?:[?#].*)?$~i';

        if (preg_match($mediaPattern, $url, $matches) === 1) {
            return [
                'shortcode' => $matches[2],
                'kind' => strtolower($matches[1]),
            ];
        }

        $profilePattern = '~https?://(?:www\.)?(?:m\.)?instagram\.com/([A-Za-z0-9._]+)/?(?:[?#].*)?$~';

        if (preg_match($profilePattern, $url, $matches) !== 1) {
            return null;
        }

        $reserved = [
            'accounts',
            'direct',
            'explore',
            'graphql',
            'p',
            'reel',
            'reels',
            'stories',
            'tv',
        ];

        $username = strtolower($matches[1]);

        if (in_array($username, $reserved, true)) {
            return null;
        }

        return [
            'shortcode' => $matches[1],
            'kind' => 'profile',
            'username' => $matches[1],
        ];
    }
}
