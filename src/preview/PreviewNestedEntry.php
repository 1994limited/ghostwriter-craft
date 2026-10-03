<?php

namespace nineteenninetyfour\ghostwriter\preview;

use Craft;
use craft\elements\Entry;
use craft\helpers\Html;
use craft\web\View;
use Twig\Markup;

/**
 * A CKEditor nested entry that exists only in a preview. It renders as
 * any nested entry does, through its entry type's partial template; an
 * entry type with none (which Craft would print as just its title) shows
 * as a plain box with its type's name and its writing instead.
 */
class PreviewNestedEntry extends Entry
{
    public function render(array $variables = []): Markup
    {
        $view = Craft::$app->getView();

        if ($this->hasEventHandlers(self::EVENT_RENDER)) {
            return parent::render($variables);
        }

        foreach ($this->partialTemplatePathCandidates() as $template) {
            if ($view->doesTemplateExist($template['template'], View::TEMPLATE_MODE_SITE)) {
                return parent::render($variables);
            }
        }

        $parts = [];

        foreach ($this->getFieldLayout()?->getCustomFields() ?? [] as $field) {
            $value = $this->getFieldValue($field->handle);
            $text = is_object($value) && method_exists($value, '__toString') ? (string) $value : (is_scalar($value) ? (string) $value : '');

            if (trim(strip_tags($text)) !== '') {
                $parts[] = Html::tag('div', str_contains($text, '<') ? $text : Html::encode($text));
            }
        }

        $label = Html::tag('div', Html::encode(Craft::t('site', $this->getType()->name)), ['style' => 'font: 600 11px/1.6 system-ui, sans-serif; text-transform: uppercase; letter-spacing: .06em; opacity: .7']);

        return new Markup(Html::tag('div', $label . implode('', $parts), [
            'class' => 'ghostwriter-preview-nested',
            'style' => 'border: 1px dashed currentColor; border-radius: 6px; padding: .75em 1em; margin: 1em 0',
        ]), Craft::$app->charset);
    }
}
