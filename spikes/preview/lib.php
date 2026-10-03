<?php
// Spike only. Shared by the Craft preview spike scripts.
use craft\elements\Asset;
use craft\elements\Entry;
use nineteenninetyfour\ghostwriter\Plugin;
use nineteenninetyfour\ghostwriter\drafts\FieldValues;
use nineteenninetyfour\ghostwriter\layouts\SchemaReader;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use NineteenNinetyFour\Ghostwriter\Core\Text\Draft;

const SPIKE_STATE = __DIR__.'/../../.spike-state.json'; // ids of the spike's own entries (gitignored)

function spike_state(?array $set = null): array
{
    if ($set !== null) file_put_contents(SPIKE_STATE, json_encode($set, JSON_PRETTY_PRINT));
    return is_file(SPIKE_STATE) ? json_decode(file_get_contents(SPIKE_STATE), true) : [];
}

function spike_out(): string
{
    $d = getenv('OUT') ?: sys_get_temp_dir(); @mkdir($d, 0777, true); return $d;
}

function spike_drafts(): array
{
    return [
'page' => <<<'Y'
title: Gardens for places people wait
pageBuilder:
  - type: hero
    heading: Gardens for places people wait
    subheading: Surgeries, schools and care homes, designed to be looked at as much as used.
  - type: text
    text: |
      ## Why waiting rooms

      Most people see a surgery's courtyard through a window. We design for that view first.
  - type: imageBlock
    caption: The courtyard in its first spring.
  - type: quoteBlock
    quote: The patients noticed before the staff did.
    attribution: Practice manager
  - type: text
    text: |
      ## What we planted

      - Hellebores for winter flowers
      - Ferns along the north wall
  - type: cta
    heading: Have a courtyard nobody uses?
    ctaText: Tell us about it and we'll come and look.
Y,
'journal' => <<<'Y'
title: Rain gardens for small yards
excerpt: Where the downpipe goes is where the garden starts.
body: |-
  A rain garden is a shallow dip planted to take water off a roof.

  ## Where to put one

  At least three metres from the house, and downhill of it.

  > Water goes where it wants. A rain garden just agrees with it.
category: planting
Y,
    ];
}

/** The addon's own mapping, as Applier::apply() runs it for a new entry (no save). */
function spike_build(string $kind, Entry $target): array
{
    $type = $target->getType();
    $schema = (new SchemaReader())->read($type);
    $model = Schema::fromSpecs($schema);
    $layouts = Plugin::getInstance()->layouts;
    $draft = Draft::parse(spike_drafts()[$kind]);
    $pattern = $layouts->pattern($target->getSection()->handle, $model, $type->handle, [], []);
    $data = $layouts->build($draft->data, $model, $pattern, [])->data;
    // the panel's chosen images (Applier keeps them / places placeholders)
    $img = fn (string $f) => (int) Asset::find()->filename($f)->one()?->id;
    if ($kind === 'page') {
        foreach ($data['pageBuilder'] as $i => $b) {
            if ($b['type'] === 'hero') $data['pageBuilder'][$i]['image'] = [$img('hero-evening.jpg') ?: $img('*.jpg')];
            if ($b['type'] === 'imageBlock') $data['pageBuilder'][$i]['image'] = [Asset::find()->kind('image')->offset(3)->one()->id];
        }
    } else {
        $data['heroImage'] = [Asset::find()->kind('image')->offset(5)->one()->id];
    }
    return ['title' => $draft->title(), 'data' => $data, 'schema' => $schema];
}

final class SpikeMarkers
{
    public const PATTERN = '/\x{E0067}\x{E0077}([\x{E0020}-\x{E007E}]{1,16})\x{E007F}/u';
    public array $map = [];

    public static function code(string $p): string
    {
        $o = "\u{E0067}\u{E0077}"; foreach (str_split($p) as $c) $o .= mb_chr(0xE0000 + ord($c), 'UTF-8'); return $o."\u{E007F}";
    }

    /** Core data (before FieldValues::forCraft): plain strings prefixed, HTML per top-level element. */
    public function mark(array $data, array $schema): array
    {
        foreach ($schema as $spec) {
            $h = $spec['handle'];
            if (! array_key_exists($h, $data)) continue;
            if (($spec['engine'] ?? null) === 'matrix') {
                foreach ($data[$h] as $i => $block) {
                    $key = 'b'.$i; $n = 0; $assets = [];
                    foreach ($spec['sets'][$block['type']]['fields'] as $f) {
                        $v = $block[$f['handle']] ?? null;
                        if ($f['kind'] === 'richtext' && is_string($v)) { $block[$f['handle']] = $this->html($v, $key, "$h/$i/{$f['handle']}"); $n++; }
                        elseif (in_array($f['kind'], ['text', 'longtext'], true) && is_string($v) && $v !== '') { $block[$f['handle']] = self::code("$key.$n").$v; $n++; }
                        elseif ($f['handle'] === 'image' && $v) $assets = array_map(fn ($id) => Asset::find()->id($id)->one()?->filename, (array) $v);
                    }
                    $this->map[$key] = ['path' => "$h/$i", 'label' => $block['type'], 'assets' => array_values(array_filter($assets))];
                    $data[$h][$i] = $block;
                }
            } elseif ($spec['kind'] === 'richtext' && is_string($data[$h])) {
                $data[$h] = $this->html($data[$h], "f:$h", $h);
            } elseif (in_array($spec['kind'], ['text', 'longtext'], true) && is_string($data[$h])) {
                $data[$h] = self::code("f:$h").$data[$h]; $this->map["f:$h"] = ['path' => $h, 'label' => $spec['display'] ?? $h];
            } elseif ($spec['kind'] === 'reference' && $spec['handle'] === 'heroImage' && $data[$h]) {
                $this->map["f:$h"] = ['path' => $h, 'label' => 'Hero image', 'assets' => [Asset::find()->id($data[$h])->one()?->filename]];
            }
        }
        return $data;
    }

    /** HTML: a unit key per top-level element, after its first opening tag (§8.1). */
    private function html(string $html, string $parent, string $path): string
    {
        $i = 0;
        return preg_replace_callback('#<(p|h[1-6]|li|blockquote)(\s[^>]*)?>#', function ($m) use (&$i, $parent, $path) {
            $key = $parent.'s'.$i; $this->map[$key] = ['path' => "$path/$i", 'label' => $m[1], 'parent' => $parent]; $i++;
            return $m[0].self::code($key);
        }, $html);
    }
}
