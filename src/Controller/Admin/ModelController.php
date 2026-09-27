<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\Controller;
use App\Kernel\Request;
use App\Kernel\Response;
use App\Service\Stats;
use App\Support\Clock;
use App\Support\Labels;
use App\Support\Str;
use App\Support\Validator;

final class ModelController extends Controller
{
    public const MIN_PERSONA_AGE = 21;

    /** Textová pole profilu: název sloupce => [popisek, max. délka]. */
    private const TEXT_FIELDS = [
        'niche' => ['Nika', 200],
        'tagline' => ['Slogan', 200],
        'public_bio' => ['Veřejné bio', 2000],
        'backstory' => ['Příběh postavy', 5000],
        'personality' => ['Povaha a styl komunikace', 3000],
        'look_face' => ['Obličej', 1000],
        'look_hair' => ['Vlasy', 1000],
        'look_eyes' => ['Oči', 500],
        'look_body' => ['Postava', 1000],
        'look_skin' => ['Pleť', 500],
        'look_marks' => ['Poznávací znaky', 1000],
        'look_style' => ['Styl a oblečení', 2000],
        'base_model' => ['Základní model', 255],
        'lora_name' => ['Název LoRA', 255],
        'lora_trigger' => ['Trigger slovo', 100],
        'lora_weight' => ['Síla LoRA', 50],
        'lora_location' => ['Umístění LoRA souboru', 500],
        'default_seed' => ['Výchozí seed', 50],
        'default_negative' => ['Výchozí negativní prompt', 3000],
        'seo_title' => ['SEO titulek', 70],
        'seo_description' => ['SEO popis', 170],
        'notes' => ['Poznámky', 5000],
    ];

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
        [$data, $validator] = $this->validate($request, null);
        if ($validator->fails()) {
            return $this->backWithErrors($request, $validator, '/models/new');
        }
        $now = Clock::nowUtc();
        $id = $this->app->db->insert('models', $data + ['created_at' => $now, 'updated_at' => $now]);
        $this->flash('success', 'AI modelka přidána. Doplň master prompt, nahraj referenční fotky a přidej účty na platformách.');

        return $this->redirect('/models/' . $id);
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
        [$data, $validator] = $this->validate($request, (int) $model['id']);
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

    /** @return array{0: array<string, mixed>, 1: Validator} */
    private function validate(Request $request, ?int $modelId): array
    {
        $v = new Validator();
        $data = [
            'name' => $v->required('name', $request->input('name'), 'Jméno', 100),
            'status' => $v->oneOf('status', $request->input('status'), Labels::group('model_status'), 'Stav'),
            'persona_age' => $v->int('persona_age', $request->input('persona_age'), 'Věk postavy', self::MIN_PERSONA_AGE, 99),
            'page_lang' => $v->oneOf('page_lang', $request->input('page_lang', 'en'), Labels::group('page_lang'), 'Jazyk stránky'),
            'page_published' => $request->checkbox('page_published') ? 1 : 0,
            'page_domain' => $v->domain('page_domain', $request->input('page_domain'), 'Vlastní doména'),
        ];
        foreach (self::TEXT_FIELDS as $field => [$label, $max]) {
            $value = in_array($field, ['default_negative', 'public_bio', 'backstory', 'personality'], true)
                ? trim($request->rawInput($field))
                : $request->input($field);
            $data[$field] = $v->optional($field, $value, $label, $max);
        }

        $slug = $request->input('slug');
        $slug = $slug === '' ? Str::slug($data['name']) : $slug;
        if (preg_match('/^[a-z0-9](?:[a-z0-9-]{0,78}[a-z0-9])?$/', $slug) !== 1) {
            $v->addError('slug', 'URL slug: jen malá písmena bez diakritiky, číslice a pomlčky.');
        } elseif ($this->app->db->scalar('SELECT 1 FROM models WHERE slug = :s AND id != :id', ['s' => $slug, 'id' => $modelId ?? 0]) !== null) {
            $v->addError('slug', 'URL slug už používá jiná modelka.');
        }
        $data['slug'] = $slug;

        if ($data['page_domain'] !== null) {
            if ($data['page_domain'] === $this->app->urls->host()) {
                $v->addError('page_domain', 'Doména nesmí být stejná jako doména administrace.');
            } elseif ($this->app->db->scalar('SELECT 1 FROM models WHERE page_domain = :d AND id != :id', ['d' => $data['page_domain'], 'id' => $modelId ?? 0]) !== null) {
                $v->addError('page_domain', 'Doménu už používá jiná modelka.');
            }
        }

        if ($modelId !== null) {
            $avatar = $request->input('avatar_image_id');
            $data['avatar_image_id'] = $avatar === '' ? null : (
                $this->app->db->scalar('SELECT id FROM images WHERE id = :i AND model_id = :m', ['i' => (int) $avatar, 'm' => $modelId]) !== null
                    ? (int) $avatar
                    : null
            );
        }

        return [$data, $v];
    }
}
