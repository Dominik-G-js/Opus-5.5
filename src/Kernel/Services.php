<?php

declare(strict_types=1);

namespace App\Kernel;

use App\Http\CurlHttpClient;
use App\Integration\Fanvue\FanvueClient;
use App\Integration\Fanvue\FanvueSync;
use App\Service\ExchangeRates;
use App\Service\ImageStore;
use App\Service\Ledger;
use App\Service\ModelImporter;

/**
 * Sestavení doménových služeb — sdílí ho webové controllery i CLI.
 */
final class Services
{
    public static function exchangeRates(App $app): ExchangeRates
    {
        return new ExchangeRates(
            $app->db,
            new CurlHttpClient(),
            $app->logger,
            $app->config->string('cnb.api_url', ExchangeRates::API),
        );
    }

    public static function ledger(App $app): Ledger
    {
        return new Ledger($app->db, self::exchangeRates($app));
    }

    public static function imageStore(App $app): ImageStore
    {
        return new ImageStore($app->db, $app->storagePath('uploads'), $app->storagePath('public'));
    }

    public static function fanvueClient(App $app): FanvueClient
    {
        return new FanvueClient(
            new CurlHttpClient(30),
            $app->config->string('fanvue.client_id'),
            $app->config->string('fanvue.client_secret'),
            $app->config->string('fanvue.api_version', FanvueClient::DEFAULT_API_VERSION),
            null,
            $app->config->string('fanvue.auth_base', FanvueClient::AUTH_BASE),
            $app->config->string('fanvue.api_base', FanvueClient::API_BASE),
        );
    }

    public static function modelImporter(App $app): ModelImporter
    {
        return new ModelImporter($app->db, $app->urls);
    }

    public static function fanvueSync(App $app): FanvueSync
    {
        return new FanvueSync($app->db, $app->crypto, self::fanvueClient($app), self::ledger($app), $app->logger);
    }
}
