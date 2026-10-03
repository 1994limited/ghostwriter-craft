<?php

namespace nineteenninetyfour\ghostwriter\gaps;

use Craft;
use craft\elements\Entry;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\LinkTarget;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\LinkTargets;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;

/**
 * The entries a link can point at, for "Link to Contact" and for links to
 * entries that have gone. A link to an entry is a reference tag in Craft:
 * `{entry:41@1:url}` in a Link field, `{entry:41@1:url||https://…}` in
 * CKEditor, or the entry's ID in Hyper. Matching a hint is by title and
 * slug in the site, never a model.
 */
class CraftLinkTargets implements LinkTargets
{
    /** A reference tag to an element: its type and ID. */
    private const REF = '/\{(entry|asset|category):(\d+)(?:@\d+)?[:}]/';

    /** @var array<string, bool> Whether each element looked up exists, by "type:id". */
    private array $known = [];

    public function __construct(private readonly ?int $siteId = null) {}

    public function exists(mixed $target, Field $field): ?bool
    {
        $refs = $this->refs($target);

        if ($refs === []) {
            return null;
        }

        foreach ($refs as [$type, $id]) {
            if (!$this->found($type, $id)) {
                return false;
            }
        }

        return true;
    }

    public function search(string $hint, int $limit = 3): array
    {
        $words = array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($hint)) ?: [], fn(string $word) => mb_strlen($word) > 1 && !in_array($word, ['the', 'page', 'our', 'a', 'an', 'to', 'of', 'link'], true)));

        if ($words === []) {
            return [];
        }

        $site = $this->siteId ?? Craft::$app->getSites()->getCurrentSite()->id;
        $query = Entry::find()->siteId($site)->status('live')->uri(':notempty:')->limit(50);
        $where = ['or'];

        foreach ($words as $word) {
            $where[] = ['like', 'elements_sites.slug', $word];
            $where[] = ['like', 'elements_sites.title', $word];
        }

        $scored = [];

        foreach ($query->andWhere($where)->all() as $entry) {
            $slug = mb_strtolower((string) $entry->slug);
            $title = mb_strtolower((string) $entry->title);
            $score = 0;

            foreach ($words as $word) {
                $score += match (true) {
                    $slug === $word => 4,
                    preg_match('/(^|[^\p{L}\p{N}])' . preg_quote($word, '/') . '($|[^\p{L}\p{N}])/u', $title . ' ' . $slug) === 1 => 2,
                    default => 0,
                };
            }

            if ($score > 0) {
                $scored[] = [$score, -mb_strlen($title), (int) $entry->id, $entry];
            }
        }

        rsort($scored);

        return array_map(fn(array $row) => new LinkTarget(
            "{entry:{$row[3]->id}@{$row[3]->siteId}:url||{$row[3]->getUrl()}}",
            (string) $row[3]->title,
            $row[3]->getUrl(),
        ), array_slice($scored, 0, $limit));
    }

    /**
     * The elements a link holds: reference tags in a string or a Link
     * field's value, or Hyper's element IDs.
     *
     * @return list<array{0: string, 1: int}>
     */
    private function refs(mixed $target): array
    {
        if (is_string($target)) {
            return preg_match_all(self::REF, $target, $matches, PREG_SET_ORDER) ? array_map(fn(array $match) => [$match[1], (int) $match[2]], $matches) : [];
        }

        if (!is_array($target)) {
            return [];
        }

        $refs = [];
        $type = is_string($target['type'] ?? null) ? strtolower((string) $target['type']) : '';

        foreach ($target as $key => $item) {
            if (in_array($key, ['value', 'linkValue'], true) && is_numeric($item) && str_contains($type, 'entry')) {
                $refs[] = ['entry', (int) $item];
            } elseif (is_string($item) || is_array($item)) {
                array_push($refs, ...$this->refs($item));
            }
        }

        return $refs;
    }

    private function found(string $type, int $id): bool
    {
        $class = match ($type) {
            'asset' => \craft\elements\Asset::class,
            'category' => \craft\elements\Category::class,
            default => Entry::class,
        };

        return $this->known["{$type}:{$id}"] ??= $class::find()->id($id)->status(null)->site('*')->unique()->exists();
    }
}
