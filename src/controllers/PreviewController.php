<?php

namespace nineteenninetyfour\ghostwriter\controllers;

use League\CommonMark\GithubFlavoredMarkdownConverter;
use yii\web\Response;

/**
 * Markdown as it reads, for the Preview tab of the guide editors. Craft has
 * no markdown field of its own, so the guides are edited as text and shown
 * rendered on request.
 */
class PreviewController extends Controller
{
    public function actionMarkdown(): Response
    {
        $this->requirePostRequest();

        $markdown = (string) $this->request->getBodyParam('markdown');

        if (mb_strlen($markdown) > 60000) {
            return $this->refuse('Too long to preview.');
        }

        // Raw HTML is escaped: a guide may have been written by a model.
        $html = (string) (new GithubFlavoredMarkdownConverter(['html_input' => 'escape', 'allow_unsafe_links' => false]))->convert($markdown);

        return $this->asJson(['html' => $html]);
    }
}
