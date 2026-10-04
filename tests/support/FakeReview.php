<?php

namespace nineteenninetyfour\ghostwriter\tests\support;

use NineteenNinetyFour\Ghostwriter\Core\Ai\TextRequest;

/**
 * A reviewer and a verifier that answer about the page they're shown,
 * whatever its unit and candidate numbers: the alt text and the dated
 * eyebrow kept with words, every other candidate fine in context, and one
 * suggestion of its own on the heading; the verifier keeps everything.
 */
trait FakeReview
{
    protected function fakeReview(): void
    {
        $this->fake->respond('reviewer', function(TextRequest $request) {
            preg_match_all('/<unit id="(u\d+)" field="([^"]+)"/', $request->prompt, $units, PREG_SET_ORDER);
            $unit = function(string $field) use ($units): string {
                foreach ($units as $match) {
                    if (str_contains($match[2], $field)) {
                        return $match[1];
                    }
                }

                return 'u1';
            };
            $answers = [];

            preg_match_all('/^(f\d+) (\S+) (\S+)(?: under "[^"]*")?(?: "([^"]*)")?/m', $request->prompt, $candidates, PREG_SET_ORDER);

            foreach ($candidates as $m) {
                [$number, $category, $where, $quote] = [$m[1], $m[2], $m[3], $m[4] ?? ''];

                $answers[] = match (true) {
                    $category === 'accessibility' => ['finding' => $number, 'category' => $category, 'unit' => $where, 'reason' => 'Describes the photo.', 'source' => ['kind' => 'image'], 'replacement' => 'Stone, gravel and timber samples on a bench'],
                    $category === 'out-of-date' && str_contains($quote, 'winter care visits') => ['finding' => $number, 'category' => $category, 'unit' => $where, 'quote' => $quote, 'reason' => 'Winter visits are not new any more.', 'source' => ['kind' => 'finding'], 'replacement' => 'Winter care visits', 'alternatives' => ['Our winter care visits']],
                    default => ['finding' => $number, 'drop' => 'Fine in context.', 'decline' => 'Fine in context.'],
                };
            }

            $answers[] = ['category' => 'voice', 'unit' => $unit('Heading'), 'quote' => 'We leverage our expertise to deliver bespoke garden solutions', 'reason' => 'Three words the guide rules out.', 'source' => ['kind' => 'voice-guide', 'heading' => 'What this voice never does'], 'replacement' => 'We design gardens and help them grow', 'alternatives' => ['Gardens designed, planted and looked after', 'We design and plant gardens']];

            return "<suggestions>\n" . json_encode(['suggestions' => $answers]) . "\n</suggestions>";
        });

        $this->fake->respond('verifier', function(TextRequest $request) {
            preg_match_all('/<suggestion id="(s\d+)"/', $request->prompt, $ids);

            return "<verdicts>\n" . json_encode(['verdicts' => array_map(fn(string $id) => ['id' => $id, 'verdict' => 'keep', 'reason' => 'Reads well here.'], $ids[1])]) . "\n</verdicts>";
        });
    }
}
