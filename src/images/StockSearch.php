<?php

namespace nineteenninetyfour\ghostwriter\images;

use Craft;
use InvalidArgumentException;
use nineteenninetyfour\ghostwriter\Plugin;
use Throwable;

/**
 * Finds free-to-use photographs, for sites with no image model to call or
 * that would rather use a real photograph.
 *
 * Unsplash, Pexels and Pixabay are searched when the site has a (free) API
 * key for them. Openverse needs no key and is searched for public-domain and
 * CC0 work only, so nothing found here carries a condition the site must meet.
 */
class StockSearch
{
    private const PER_SOURCE = 9;

    /** Largest file that will be brought into a volume. */
    private const MAX_BYTES = 15 * 1024 * 1024;

    private const EXTENSIONS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    /**
     * @return array<int, string> The sources that can be searched, best first.
     */
    public function sources(): array
    {
        return array_values(array_filter([
            $this->key('unsplash') ? 'unsplash' : null,
            $this->key('pexels') ? 'pexels' : null,
            $this->key('pixabay') ? 'pixabay' : null,
            Plugin::getInstance()->getSettings()->openverse ? 'openverse' : null,
        ]));
    }

    /**
     * @param string $shape landscape, portrait or square
     * @return array<int, array{source: string, id: string, thumb: string, credit: string, credit_url: ?string, licence: string}>
     */
    public function search(string $query, string $shape = 'landscape'): array
    {
        $results = $this->searchAll($query, $shape);
        $words = preg_split('/\s+/u', trim($query)) ?: [];

        // The smaller libraries match every word, so a long search finds
        // nothing. Its first two words are usually the subject.
        if ($results === [] && count($words) > 2) {
            $results = $this->searchAll(implode(' ', array_slice($words, 0, 2)), $shape);
        }

        return $results;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function searchAll(string $query, string $shape): array
    {
        $results = [];

        foreach ($this->sources() as $source) {
            try {
                array_push($results, ...$this->{$source}($query, $shape));
            } catch (Throwable $exception) {
                // One source being down should not hide the others.
                Craft::warning("{$source} search failed: {$exception->getMessage()}", 'ghostwriter');
            }
        }

        return $results;
    }

    /**
     * Download one photograph. The file's address is looked up again from
     * the source by ID, never taken from the browser.
     *
     * @return array{content: string, extension: string, credit: string, credit_url: ?string, licence: string, source: string}
     */
    public function fetch(string $source, string $id): array
    {
        if (!in_array($source, $this->sources(), true) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id)) {
            throw new InvalidArgumentException('That photograph could not be found.');
        }

        $photo = match ($source) {
            'unsplash' => $this->unsplashPhoto($id),
            'pexels' => $this->pexelsPhoto($id),
            'pixabay' => $this->pixabayPhoto($id),
            default => $this->openversePhoto($id),
        };

        if (!preg_match('#^https://#', (string) $photo['file'])) {
            throw new InvalidArgumentException('That photograph has no secure download address.');
        }

        // Secure all the way, redirects included, and read no further than
        // the largest image worth keeping.
        $response = Plugin::getInstance()->providers->http()->request('GET', $photo['file'], [
            'timeout' => 60,
            'stream' => true,
            'allow_redirects' => ['max' => 5, 'protocols' => ['https'], 'strict' => true],
        ]);
        $mime = strtolower(trim(explode(';', $response->getHeaderLine('Content-Type'))[0]));

        if ((int) $response->getHeaderLine('Content-Length') > self::MAX_BYTES) {
            throw new InvalidArgumentException('That photograph is too large to use.');
        }

        $body = $response->getBody();
        $content = '';

        while (!$body->eof() && strlen($content) <= self::MAX_BYTES) {
            $content .= $body->read(65536);
        }

        if (strlen($content) > self::MAX_BYTES) {
            throw new InvalidArgumentException('That photograph is too large to use.');
        }

        if (!isset(self::EXTENSIONS[$mime]) || @getimagesizefromstring($content) === false) {
            throw new InvalidArgumentException('That file is not an image Ghostwriter can use.');
        }

        unset($photo['file']);

        return $photo + ['content' => $content, 'extension' => self::EXTENSIONS[$mime], 'source' => $source];
    }

    private function key(string $source): ?string
    {
        return Plugin::getInstance()->providers->key($source);
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function get(string $url, array $options = []): array
    {
        $response = Plugin::getInstance()->providers->http()->request('GET', $url, $options + ['timeout' => 20]);
        $data = json_decode((string) $response->getBody(), true);

        return is_array($data) ? $data : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function unsplash(string $query, string $shape): array
    {
        $photos = $this->get('https://api.unsplash.com/search/photos', [
            'headers' => ['Authorization' => 'Client-ID ' . $this->key('unsplash'), 'Accept-Version' => 'v1'],
            'query' => [
                'query' => $query,
                'per_page' => self::PER_SOURCE,
                'orientation' => $shape === 'square' ? 'squarish' : $shape,
                'content_filter' => 'high',
            ],
        ])['results'] ?? [];

        return array_map(fn(array $photo) => [
            'source' => 'unsplash',
            'id' => (string) $photo['id'],
            'thumb' => (string) $photo['urls']['small'],
            'credit' => ($photo['user']['name'] ?? 'Unknown') . ' on Unsplash',
            'credit_url' => $photo['links']['html'] ?? null,
            'licence' => 'Unsplash licence',
        ], $photos);
    }

    /**
     * @return array<string, mixed>
     */
    private function unsplashPhoto(string $id): array
    {
        $headers = ['Authorization' => 'Client-ID ' . $this->key('unsplash'), 'Accept-Version' => 'v1'];
        $photo = $this->get("https://api.unsplash.com/photos/{$id}", ['headers' => $headers]);

        // Unsplash asks to be told when a photograph is actually used.
        if (!empty($photo['links']['download_location'])) {
            try {
                $this->get($photo['links']['download_location'], ['headers' => $headers]);
            } catch (Throwable) {
            }
        }

        return [
            'file' => ($photo['urls']['raw'] ?? '') . '&w=2400&fm=jpg&q=82',
            'credit' => ($photo['user']['name'] ?? 'Unknown') . ' on Unsplash',
            'credit_url' => $photo['links']['html'] ?? null,
            'licence' => 'Unsplash licence',
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function pexels(string $query, string $shape): array
    {
        $photos = $this->get('https://api.pexels.com/v1/search', [
            'headers' => ['Authorization' => (string) $this->key('pexels')],
            'query' => ['query' => $query, 'per_page' => self::PER_SOURCE, 'orientation' => $shape],
        ])['photos'] ?? [];

        return array_map(fn(array $photo) => [
            'source' => 'pexels',
            'id' => (string) $photo['id'],
            'thumb' => (string) $photo['src']['medium'],
            'credit' => ($photo['photographer'] ?? 'Unknown') . ' on Pexels',
            'credit_url' => $photo['url'] ?? null,
            'licence' => 'Pexels licence',
        ], $photos);
    }

    /**
     * @return array<string, mixed>
     */
    private function pexelsPhoto(string $id): array
    {
        $photo = $this->get("https://api.pexels.com/v1/photos/{$id}", ['headers' => ['Authorization' => (string) $this->key('pexels')]]);

        return [
            'file' => $photo['src']['large2x'] ?? $photo['src']['original'] ?? '',
            'credit' => ($photo['photographer'] ?? 'Unknown') . ' on Pexels',
            'credit_url' => $photo['url'] ?? null,
            'licence' => 'Pexels licence',
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function pixabay(string $query, string $shape): array
    {
        $photos = $this->get('https://pixabay.com/api/', ['query' => [
            'key' => $this->key('pixabay'),
            'q' => $query,
            'image_type' => 'photo',
            'per_page' => self::PER_SOURCE,
            'orientation' => $shape === 'portrait' ? 'vertical' : ($shape === 'landscape' ? 'horizontal' : 'all'),
            'safesearch' => 'true',
        ]])['hits'] ?? [];

        return array_map(fn(array $photo) => [
            'source' => 'pixabay',
            'id' => (string) $photo['id'],
            'thumb' => (string) $photo['webformatURL'],
            'credit' => ($photo['user'] ?? 'Unknown') . ' on Pixabay',
            'credit_url' => $photo['pageURL'] ?? null,
            'licence' => 'Pixabay licence',
        ], $photos);
    }

    /**
     * @return array<string, mixed>
     */
    private function pixabayPhoto(string $id): array
    {
        $photo = $this->get('https://pixabay.com/api/', ['query' => ['key' => $this->key('pixabay'), 'id' => $id]])['hits'][0]
            ?? throw new InvalidArgumentException('That photograph could not be found.');

        return [
            'file' => $photo['largeImageURL'] ?? $photo['webformatURL'],
            'credit' => ($photo['user'] ?? 'Unknown') . ' on Pixabay',
            'credit_url' => $photo['pageURL'] ?? null,
            'licence' => 'Pixabay licence',
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function openverse(string $query, string $shape): array
    {
        $photos = $this->get('https://api.openverse.org/v1/images/', ['query' => [
            'q' => $query,
            'page_size' => self::PER_SOURCE,
            'license' => 'cc0,pdm',
            'extension' => 'jpg,png',
            'aspect_ratio' => ['landscape' => 'wide', 'portrait' => 'tall', 'square' => 'square'][$shape] ?? 'wide',
            'mature' => 'false',
        ]])['results'] ?? [];

        return array_map(fn(array $photo) => [
            'source' => 'openverse',
            'id' => (string) $photo['id'],
            'thumb' => (string) ($photo['thumbnail'] ?? $photo['url']),
            'credit' => trim(($photo['creator'] ?? '') ?: 'Unknown') . ' via Openverse',
            'credit_url' => $photo['foreign_landing_url'] ?? null,
            'licence' => strtoupper((string) ($photo['license'] ?? 'cc0')) === 'PDM' ? 'Public domain' : 'CC0',
        ], $photos);
    }

    /**
     * @return array<string, mixed>
     */
    private function openversePhoto(string $id): array
    {
        $photo = $this->get("https://api.openverse.org/v1/images/{$id}/");

        if (!in_array($photo['license'] ?? null, ['cc0', 'pdm'], true)) {
            throw new InvalidArgumentException('That photograph is not free of conditions.');
        }

        return [
            'file' => $photo['url'] ?? '',
            'credit' => trim(($photo['creator'] ?? '') ?: 'Unknown') . ' via Openverse',
            'credit_url' => $photo['foreign_landing_url'] ?? null,
            'licence' => $photo['license'] === 'pdm' ? 'Public domain' : 'CC0',
        ];
    }
}
