<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\Controller;
use App\Form\ModelForm;
use App\Kernel\Services;
use App\Kernel\Request;
use App\Kernel\Response;
use App\Service\ModelImportException;
use App\Service\ModelImporter;
use App\Service\Stats;
use App\Support\Clock;
use App\Support\Str;

final class ModelController extends Controller
{
    public function index(Request $request): Response
    {
        $models = $this->app->db->all(
            'SELECT m.*,
                (SELECT COUNT(*) FROM prompts p WHERE p.model_id = m.id) AS prompt_count,
                (SELECT COUNT(*) FROM images i WHERE i.model_id = m.id) AS image_count,
                (SELECT COUNT(*) FROM accounts a WHERE a.model_id = m.id) AS account_count,
                (SELECT stored_name FROM images i WHERE i.id = m.avatar_image_id) AS avatar
             FROM models m ORDER BY m.status = \'retired\', m.name'
        );

        return $this->render('models/index', ['title' => 'Modelky', 'models' => $models]);
    }

    public function create(Request $request): Response
    {
        return $this->render('models/form', ['title' => 'Přidat AI modelku', 'model' => null]);
    }

    public function store(Request $request): Response
    {
        [$data, $validator] = $this->form()->validate($request->form(), null);
        if ($validator->fails()) {
            return $this->backWithErrors($request, $validator, '/models/new');
        }
        $now = Clock::nowUtc();
        $id = $this->app->db->insert('models', $data + ['created_at' => $now, 'updated_at' => $now]);
        $this->flash('success', 'AI modelka přidána. Doplň master prompt, nahraj referenční fotky a přidej účty na platformách.');

        return $this->redirect('/models/' . $id);
    }

    public function importForm(Request $request): Response
    {
        return $this->render('models/import', ['title' => 'Import modelky ze souboru', 'maxBytes' => ModelImporter::MAX_BYTES]);
    }

    public function import(Request $request): Response
    {
        $file = $request->file('model_file');
        if ($file === null || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return $this->backWithErrors($request, ['model_file' => 'Vyber soubor .json s modelkou.'], '/models/import');
        }
        $tmp = (string) $file['tmp_name'];
        $extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        if (!is_uploaded_file($tmp) || $extension !== 'json' || !in_array($mime, ['application/json', 'text/plain'], true)) {
            return $this->backWithErrors($request, ['model_file' => 'Soubor musí být JSON (přípona .json).'], '/models/import');
        }
        if ((int) $file['size'] > ModelImporter::MAX_BYTES) {
            return $this->backWithErrors($request, ['model_file' => 'Soubor je větší než 1 MB.'], '/models/import');
        }

        try {
            $result = Services::modelImporter($this->app)->importJson((string) file_get_contents($tmp));
        } catch (ModelImportException $e) {
            $errors = ['model_file' => 'Nic se neuložilo — v souboru je ' . count($e->errors) . ' chyb:'];
            foreach (array_slice($e->errors, 0, 30) as $i => $message) {
                $errors['model_file_' . $i] = $message;
            }

            return $this->backWithErrors($request, $errors, '/models/import');
        }

        $this->app->logger->info('model.imported', ['id' => $result['model_id'], 'name' => $result['name']]);
        $c = $result['counts'];
        $this->flash('success', "Modelka {$result['name']} importována: {$c['prompts']} promptů, {$c['accounts']} účtů, {$c['links']} odkazů, {$c['tools']} AI nástrojů.");
        foreach (array_slice($result['warnings'], 0, 10) as $warning) {
            $this->flash('info', 'Upozornění: ' . $warning);
        }

        return $this->redirect('/models/' . $result['model_id']);
    }

    public function show(Request $request): Response
    {
        $model = $this->findOrFail('models', $request->intParam('id'));
        $id = (int) $model['id'];
        $db = $this->app->db;
        $stats = new Stats($db);
        $month = substr(Clock::todayLocal(), 0, 7);

        return $this->render('models/show', [
            'title' => $model['name'],
            'model' => $model,
            'lifetime' => $stats->modelLifetime($id),
            'monthSummary' => $stats->monthSummary($month, $id),
            'prompts' => $db->all(
                'SELECT p.*, t.name AS tool_name FROM prompts p LEFT JOIN ai_tools t ON t.id = p.tool_id
                 WHERE p.model_id = :m ORDER BY p.is_master DESC, p.kind, p.rating DESC, p.updated_at DESC',
                ['m' => $id]
            ),
            'images' => $db->all('SELECT * FROM images WHERE model_id = :m ORDER BY is_reference DESC, created_at DESC', ['m' => $id]),
            'tools' => $db->all(
                'SELECT t.*, mt.purpose FROM model_tools mt JOIN ai_tools t ON t.id = mt.tool_id WHERE mt.model_id = :m ORDER BY t.category, t.name',
                ['m' => $id]
            ),
            'accounts' => $db->all(
                'SELECT a.*, p.name AS platform, p.ai_policy, p.role FROM accounts a JOIN platforms p ON p.id = a.platform_id
                 WHERE a.model_id = :m ORDER BY p.role, p.name',
                ['m' => $id]
            ),
            'links' => $db->all(
                'SELECT l.*, (SELECT COALESCE(SUM(clicks), 0) FROM link_clicks_daily c WHERE c.link_id = l.id) AS clicks
                 FROM links l WHERE l.model_id = :m ORDER BY l.sort_order, l.id',
                ['m' => $id]
            ),
            'costs' => $db->all(
                'SELECT c.*, t.name AS tool_name FROM costs c LEFT JOIN ai_tools t ON t.id = c.tool_id
                 WHERE c.model_id = :m ORDER BY c.incurred_on DESC, c.id DESC LIMIT 15',
                ['m' => $id]
            ),
            'topFans' => $stats->topFans(null, 5, $id),
            'toolOptions' => $this->toolOptions(),
            'promptOptions' => array_column($db->all('SELECT id, title FROM prompts WHERE model_id = :m ORDER BY title', ['m' => $id]), 'title', 'id'),
            'pageUrl' => $this->app->urls->modelPage((string) $model['slug'], $model['page_domain']),
        ]);
    }

    public function edit(Request $request): Response
    {
        $model = $this->findOrFail('models', $request->intParam('id'));

        return $this->render('models/form', [
            'title' => 'Upravit: ' . $model['name'],
            'model' => $model,
            'imageOptions' => array_column(
                $this->app->db->all('SELECT id, original_name FROM images WHERE model_id = :m ORDER BY created_at DESC', ['m' => $model['id']]),
                'original_name',
                'id'
            ),
        ]);
    }

    public function update(Request $request): Response
    {
        $model = $this->findOrFail('models', $request->intParam('id'));
        [$data, $validator] = $this->form()->validate($request->form(), (int) $model['id']);
        if ($validator->fails()) {
            return $this->backWithErrors($request, $validator, '/models/' . $model['id'] . '/edit');
        }
        $this->app->db->update('models', $data + ['updated_at' => Clock::nowUtc()], ['id' => $model['id']]);
        $this->flash('success', 'Uloženo.');

        return $this->redirect('/models/' . $model['id']);
    }

    public function delete(Request $request): Response
    {
        $model = $this->findOrFail('models', $request->intParam('id'));
        if ($request->input('confirm_name') !== $model['name']) {
            $this->flash('error', 'Pro smazání napiš přesně jméno modelky. Smažou se i prompty, obrázky, účty a příjmy.');

            return $this->redirect('/models/' . $model['id'] . '/edit');
        }
        $images = $this->app->db->all('SELECT * FROM images WHERE model_id = :m', ['m' => $model['id']]);
        $store = $this->imageStore();
        foreach ($images as $image) {
            $store->delete($image);
        }
        $this->app->db->delete('models', ['id' => $model['id']]);
        $this->app->logger->info('model.deleted', ['id' => $model['id'], 'name' => $model['name']]);
        $this->flash('success', 'Modelka smazána.');

        return $this->redirect('/models');
    }

    public function attachTool(Request $request): Response
    {
        $model = $this->findOrFail('models', $request->intParam('id'));
        $toolId = $this->optionalId($request->input('tool_id'), 'ai_tools');
        if ($toolId === null) {
            $this->flash('error', 'Vyber nástroj.');

            return $this->redirect('/models/' . $model['id']);
        }
        $this->app->db->run(
            'INSERT INTO model_tools (model_id, tool_id, purpose) VALUES (:m, :t, :p)
             ON CONFLICT (model_id, tool_id) DO UPDATE SET purpose = excluded.purpose',
            ['m' => $model['id'], 't' => $toolId, 'p' => Str::nullIfEmpty(mb_substr($request->input('purpose'), 0, 255))]
        );
        $this->flash('success', 'Nástroj u modelky uložen.');

        return $this->redirect('/models/' . $model['id']);
    }

    public function detachTool(Request $request): Response
    {
        $model = $this->findOrFail('models', $request->intParam('id'));
        $this->app->db->delete('model_tools', ['model_id' => $model['id'], 'tool_id' => $request->intParam('toolId')]);

        return $this->redirect('/models/' . $model['id']);
    }

    /** „Character bible“ — vše potřebné pro konzistentní generování na jedné stránce (tisk/PDF). */
    public function bible(Request $request): Response
    {
        $model = $this->findOrFail('models', $request->intParam('id'));

        return $this->render('models/bible', [
            'title' => 'Character bible: ' . $model['name'],
            'model' => $model,
            'prompts' => $this->app->db->all(
                'SELECT p.*, t.name AS tool_name FROM prompts p LEFT JOIN ai_tools t ON t.id = p.tool_id
                 WHERE p.model_id = :m AND (p.is_master = 1 OR p.rating >= 4 OR p.kind IN (\'character_base\', \'negative\', \'chat_persona\'))
                 ORDER BY p.is_master DESC, p.kind, p.rating DESC',
                ['m' => $model['id']]
            ),
            'references' => $this->app->db->all('SELECT * FROM images WHERE model_id = :m AND is_reference = 1', ['m' => $model['id']]),
            'tools' => $this->app->db->all(
                'SELECT t.name, t.category, mt.purpose FROM model_tools mt JOIN ai_tools t ON t.id = mt.tool_id WHERE mt.model_id = :m',
                ['m' => $model['id']]
            ),
        ]);
    }

    private function form(): ModelForm
    {
        return new ModelForm($this->app->db, $this->app->urls->host());
    }
}
