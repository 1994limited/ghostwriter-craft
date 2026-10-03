<?php

namespace nineteenninetyfour\ghostwriter\controllers;

use Craft;
use craft\elements\Entry;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Kinds\ContentType;
use NineteenNinetyFour\Ghostwriter\Core\Domain\NotAllowed;
use NineteenNinetyFour\Ghostwriter\Core\Domain\NotFound;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use nineteenninetyfour\ghostwriter\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;

/**
 * The piece of writing, its kind and the entry a request names, each
 * checked as the writing panel's actions check them.
 */
trait FindsPieces
{
    /**
     * The entry the panel is open on, as the form holds it: a draft or the
     * entry itself. It must be in the type's section, and the person must be
     * allowed to save it.
     */
    protected function target(ContentType $type): Entry
    {
        $id = $this->request->getRequiredBodyParam('elementId');
        $siteId = $this->request->getBodyParam('siteId');

        $entry = Entry::find()
            ->id((int) $id)
            ->drafts(null)
            ->provisionalDrafts(null)
            ->siteId($siteId ? (int) $siteId : null)
            ->status(null)
            ->one();

        if (!$entry || $entry->getSection()?->handle !== $type->group) {
            throw new NotFoundHttpException('That entry cannot be written into from here.');
        }

        if (!Craft::$app->getElements()->canSave($entry, Craft::$app->getUser()->getIdentity())) {
            throw new ForbiddenHttpException('You are not allowed to edit that entry.');
        }

        return $entry;
    }

    protected function type(string $handle): ContentType
    {
        $plugin = Plugin::getInstance();
        $type = $plugin->types->find($handle);

        if (!$type || !$plugin->types->enabled($type->group)) {
            throw new NotFoundHttpException('No such kind of content.');
        }

        return $type;
    }

    /**
     * The piece named in the request, if the signed-in person may see it:
     * anyone's when conversations are shared, otherwise only their own.
     * One they may not see is treated as not there at all.
     */
    protected function session(): Session
    {
        $domain = Plugin::getInstance()->domain;

        try {
            return $domain->sessions()->find((string) $this->request->getParam('id'), $domain->viewer());
        } catch (NotFound|NotAllowed) {
            throw new NotFoundHttpException('No such piece of writing.');
        }
    }
}
