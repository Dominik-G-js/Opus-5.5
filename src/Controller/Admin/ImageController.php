<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\Controller;
use App\Kernel\HttpException;
use App\Kernel\Request;
use App\Kernel\Response;
use App\Support\Str;
use RuntimeException;

final class ImageController extends Controller
{
    public function upload(Request $request): Response
    {
        $model = $this->findOrFail('models', $request->intParam('id'));
        $file = $request->file('image');
        if ($file === null) {
            $this->flash('error', 'Vyber obrázek k nahrání.');

            return $this->redirect('/models/' . $model['id']);
        }
        try {
            $this->imageStore()->storeUpload((int) $model['id'], $file, [
                'prompt_id' => $this->promptOfModel($request->input('prompt_id'), (int) $model['id']),
                'tool_id' => $this->optionalId($request->input('tool_id'), 'ai_tools'),
                'alt_text' => Str::nullIfEmpty(mb_substr($request->input('alt_text'), 0, 300)),
                'seed' => Str::nullIfEmpty(mb_substr($request->input('seed'), 0, 50)),
                'is_reference' => $request->checkbox('is_reference'),
            ]);
            $this->flash('success', 'Obrázek nahrán.');
        } catch (RuntimeException $e) {
            $this->flash('error', $e->getMessage());
        }

        return Response::redirect($this->app->urls->admin('/models/' . $model['id']) . '#images');
    }

    public function serve(Request $request): Response
    {
        $image = $this->findOrFail('images', $request->intParam('id'));
        $path = $this->imageStore()->privatePath($image);
        if (!is_file($path)) {
            throw HttpException::notFound();
        }

        return Response::file($path, (string) $image['mime'], false);
    }

    public function update(Request $request): Response
    {
        $image = $this->findOrFail('images', $request->intParam('id'));
        $store = $this->imageStore();
        try {
            $wantPublic = $request->checkbox('is_public');
            if ($wantPublic && (int) $image['is_public'] === 0) {
                $store->makePublic($image);
            } elseif (!$wantPublic && (int) $image['is_public'] === 1) {
                $store->makePrivate($image);
            }
            $this->app->db->update('images', [
                'is_reference' => $request->checkbox('is_reference') ? 1 : 0,
                'alt_text' => Str::nullIfEmpty(mb_substr($request->input('alt_text'), 0, 300)),
            ], ['id' => $image['id']]);
            $this->flash('success', 'Obrázek upraven.');
        } catch (RuntimeException $e) {
            $this->flash('error', $e->getMessage());
        }

        return Response::redirect($this->app->urls->admin('/models/' . $image['model_id']) . '#images');
    }

    public function delete(Request $request): Response
    {
        $image = $this->findOrFail('images', $request->intParam('id'));
        $this->imageStore()->delete($image);
        $this->flash('success', 'Obrázek smazán.');

        return Response::redirect($this->app->urls->admin('/models/' . $image['model_id']) . '#images');
    }

    private function promptOfModel(string $value, int $modelId): ?int
    {
        if (preg_match('/^\d+$/', $value) !== 1) {
            return null;
        }
        $found = $this->app->db->scalar('SELECT id FROM prompts WHERE id = :p AND model_id = :m', ['p' => (int) $value, 'm' => $modelId]);

        return $found === null ? null : (int) $found;
    }
}
