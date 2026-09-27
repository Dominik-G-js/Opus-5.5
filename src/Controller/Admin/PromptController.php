<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\Controller;
use App\Form\PromptForm;
use App\Kernel\HttpException;
use App\Kernel\Request;
use App\Kernel\Response;
use App\Support\Clock;
use App\Support\Labels;

/**
 * Knihovna promptů modelky s historií verzí — každá změna textu uloží předchozí verzi,
 * takže se lze vždy vrátit k promptu, který dával „tu správnou“ tvář.
 */
final class PromptController extends Controller
{
    public function create(Request $request): Response
    {
        $model = $this->findOrFail('models', $request->intParam('id'));
        $kind = $request->query('kind');

        return $this->render('prompts/form', [
            'title' => 'Nový prompt — ' . $model['name'],
            'model' => $model,
            'prompt' => null,
            'versions' => [],
            'defaultKind' => in_array($kind, Labels::keys('prompt_kind'), true) ? $kind : 'image',
            'toolOptions' => $this->toolOptions(),
        ]);
    }

    public function store(Request $request): Response
    {
        $model = $this->findOrFail('models', $request->intParam('id'));
        [$data, $validator] = $this->form()->validate($request->form());
        if ($validator->fails()) {
            return $this->backWithErrors($request, $validator, '/models/' . $model['id'] . '/prompts/new');
        }
        $now = Clock::nowUtc();
        $id = $this->app->db->transaction(function () use ($data, $model, $now): int {
            if ($data['is_master'] === 1) {
                $this->clearMaster((int) $model['id'], (string) $data['kind']);
            }

            return $this->app->db->insert('prompts', $data + ['model_id' => $model['id'], 'created_at' => $now, 'updated_at' => $now]);
        });
        $this->flash('success', 'Prompt uložen.');

        return $this->redirect('/prompts/' . $id . '/edit');
    }

    public function edit(Request $request): Response
    {
        $prompt = $this->findOrFail('prompts', $request->intParam('id'));
        $model = $this->findOrFail('models', (int) $prompt['model_id']);

        return $this->render('prompts/form', [
            'title' => 'Prompt: ' . $prompt['title'],
            'model' => $model,
            'prompt' => $prompt,
            'versions' => $this->app->db->all(
                'SELECT * FROM prompt_versions WHERE prompt_id = :p ORDER BY id DESC LIMIT 30',
                ['p' => $prompt['id']]
            ),
            'defaultKind' => $prompt['kind'],
            'toolOptions' => $this->toolOptions(),
        ]);
    }

    public function update(Request $request): Response
    {
        $prompt = $this->findOrFail('prompts', $request->intParam('id'));
        [$data, $validator] = $this->form()->validate($request->form());
        if ($validator->fails()) {
            return $this->backWithErrors($request, $validator, '/prompts/' . $prompt['id'] . '/edit');
        }
        $this->app->db->transaction(function () use ($prompt, $data): void {
            $this->snapshotIfChanged($prompt, $data);
            if ($data['is_master'] === 1) {
                $this->clearMaster((int) $prompt['model_id'], (string) $data['kind'], (int) $prompt['id']);
            }
            $this->app->db->update('prompts', $data + ['updated_at' => Clock::nowUtc()], ['id' => $prompt['id']]);
        });
        $this->flash('success', 'Prompt uložen.');

        return $this->redirect('/prompts/' . $prompt['id'] . '/edit');
    }

    public function duplicate(Request $request): Response
    {
        $prompt = $this->findOrFail('prompts', $request->intParam('id'));
        $now = Clock::nowUtc();
        $copy = $prompt;
        unset($copy['id']);
        $copy['title'] = mb_substr('Kopie: ' . $prompt['title'], 0, 150);
        $copy['is_master'] = 0;
        $copy['created_at'] = $now;
        $copy['updated_at'] = $now;
        $id = $this->app->db->insert('prompts', $copy);
        $this->flash('success', 'Vytvořena kopie promptu.');

        return $this->redirect('/prompts/' . $id . '/edit');
    }

    public function restore(Request $request): Response
    {
        $prompt = $this->findOrFail('prompts', $request->intParam('id'));
        $version = $this->app->db->one(
            'SELECT * FROM prompt_versions WHERE id = :v AND prompt_id = :p',
            ['v' => $request->intParam('versionId'), 'p' => $prompt['id']]
        );
        if ($version === null) {
            throw HttpException::notFound();
        }
        $data = [
            'prompt' => $version['prompt'],
            'negative_prompt' => $version['negative_prompt'],
            'seed' => $version['seed'],
            'settings' => $version['settings'],
        ];
        $this->app->db->transaction(function () use ($prompt, $data): void {
            $this->snapshotIfChanged($prompt, $data);
            $this->app->db->update('prompts', $data + ['updated_at' => Clock::nowUtc()], ['id' => $prompt['id']]);
        });
        $this->flash('success', 'Obnovena starší verze (aktuální text je uložen v historii).');

        return $this->redirect('/prompts/' . $prompt['id'] . '/edit');
    }

    public function deleteVersion(Request $request): Response
    {
        $prompt = $this->findOrFail('prompts', $request->intParam('id'));
        $deleted = $this->app->db->delete('prompt_versions', ['id' => $request->intParam('versionId'), 'prompt_id' => $prompt['id']]);
        if ($deleted === 0) {
            throw HttpException::notFound();
        }
        $this->flash('success', 'Verze smazána z historie.');

        return $this->redirect('/prompts/' . $prompt['id'] . '/edit');
    }

    public function delete(Request $request): Response
    {
        $prompt = $this->findOrFail('prompts', $request->intParam('id'));
        $this->app->db->delete('prompts', ['id' => $prompt['id']]);
        $this->flash('success', 'Prompt smazán.');

        return $this->redirect('/models/' . $prompt['model_id']);
    }

    /**
     * @param array<string, mixed> $prompt
     * @param array<string, mixed> $data
     */
    private function snapshotIfChanged(array $prompt, array $data): void
    {
        foreach (['prompt', 'negative_prompt', 'seed', 'settings'] as $field) {
            if (($prompt[$field] ?? null) !== ($data[$field] ?? null)) {
                $this->app->db->insert('prompt_versions', [
                    'prompt_id' => $prompt['id'],
                    'prompt' => $prompt['prompt'],
                    'negative_prompt' => $prompt['negative_prompt'],
                    'seed' => $prompt['seed'],
                    'settings' => $prompt['settings'],
                    'created_at' => Clock::nowUtc(),
                ]);

                return;
            }
        }
    }

    private function clearMaster(int $modelId, string $kind, int $exceptId = 0): void
    {
        $this->app->db->run(
            'UPDATE prompts SET is_master = 0 WHERE model_id = :m AND kind = :k AND id != :id',
            ['m' => $modelId, 'k' => $kind, 'id' => $exceptId]
        );
    }

    private function form(): PromptForm
    {
        return new PromptForm($this->app->db);
    }
}
