<?php

namespace nineteenninetyfour\ghostwriter\migrations;

use Craft;
use craft\db\Migration;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\FileHelper;
use craft\helpers\Json;
use nineteenninetyfour\ghostwriter\Plugin;
use nineteenninetyfour\ghostwriter\Store;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * Brings in what earlier versions kept as files: guides, kinds and ideas
 * from the guides folder (config/ghostwriter by default), and working state
 * and sessions from storage/ghostwriter. Anything already in the database is
 * kept. The files are left where they are; once the import is checked they
 * can be deleted, apart from prompt overrides, which are still read from
 * config/ghostwriter/prompts.
 */
class FileImport
{
    public function __construct(private Migration $migration) {}

    public function run(): void
    {
        $guides = $this->folder('guidesPath', '@config/ghostwriter');
        $storage = $this->folder('storagePath', '@storage/ghostwriter');

        foreach (['voice', 'imagery'] as $guide) {
            if (($body = $this->read("{$guides}/{$guide}.md")) !== null && trim($body) !== '') {
                $this->document('guide', $guide, $body);
            }
        }

        foreach (glob("{$guides}/types/*.yaml") ?: [] as $file) {
            $this->document('type', basename($file, '.yaml'), (string) $this->read($file));
        }

        if (($ideas = $this->read("{$guides}/ideas.yaml")) !== null) {
            try {
                foreach ((array) (((array) Yaml::parse($ideas))['ideas'] ?? []) as $idea) {
                    if (is_array($idea) && !empty($idea['title']) && !empty($idea['section'])) {
                        $idea['id'] = (string) ($idea['id'] ?? bin2hex(random_bytes(8)));
                        $this->document('idea', $idea['id'], Json::encode($idea));
                    }
                }
            } catch (Throwable $exception) {
                Craft::warning("Ghostwriter could not import ideas.yaml: {$exception->getMessage()}", __METHOD__);
            }
        }

        foreach (['voice', 'imagery', 'plan', 'types', 'kinds', 'onboarding'] as $state) {
            if (($json = $this->read("{$storage}/{$state}.json")) !== null && is_array($value = json_decode($json, true))) {
                $this->state($state, $value);
            }
        }

        foreach (glob("{$storage}/sessions/*.json") ?: [] as $file) {
            $data = json_decode((string) $this->read($file), true);

            if (is_array($data) && isset($data['id'], $data['type']) && preg_match('/^[0-9a-f]{26}$/', (string) $data['id'])) {
                $this->session($data);
            }
        }
    }

    private function document(string $kind, string $handle, string $body): void
    {
        if (!(new Query())->from(Store::DOCUMENTS)->where(['kind' => $kind, 'handle' => $handle])->exists($this->migration->db)) {
            Db::insert(Store::DOCUMENTS, ['kind' => $kind, 'handle' => $handle, 'body' => $body], $this->migration->db);
        }
    }

    /**
     * @param array<string, mixed> $value
     */
    private function state(string $name, array $value): void
    {
        if (!(new Query())->from(Store::STATE)->where(['name' => $name])->exists($this->migration->db)) {
            Db::insert(Store::STATE, ['name' => $name, 'value' => Json::encode($value)], $this->migration->db);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function session(array $data): void
    {
        if ((new Query())->from(Store::SESSIONS)->where(['id' => $data['id']])->exists($this->migration->db)) {
            return;
        }

        $userId = isset($data['user_id']) && (new Query())->from('{{%users}}')->where(['id' => (int) $data['user_id']])->exists($this->migration->db) ? (int) $data['user_id'] : null;

        Db::insert(Store::SESSIONS, [
            'id' => $data['id'],
            'userId' => $userId,
            'elementId' => isset($data['element_id']) ? (int) $data['element_id'] : null,
            'data' => Json::encode(['user_id' => $userId] + $data),
        ], $this->migration->db);
    }

    private function folder(string $setting, string $default): string
    {
        $configured = Plugin::getInstance()?->getSettings()->{$setting}
            ?? Craft::$app->getConfig()->getConfigFromFile('ghostwriter')[$setting]
            ?? null;

        return rtrim(FileHelper::normalizePath((string) Craft::getAlias(is_string($configured) && $configured !== '' ? $configured : $default)), '/');
    }

    private function read(string $path): ?string
    {
        return is_file($path) ? (string) file_get_contents($path) : null;
    }
}
