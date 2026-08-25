<?php

namespace Appylogi\AppyCrud\Crud;

/**
 * Chequeo opcional de nuevas versiones contra la API publica de Packagist
 * (repo.packagist.org, sin autenticacion). Desactivado por defecto — se
 * activa con la opcion 'checkForUpdates' => true en AppyCrud. No envia
 * ningun dato del proyecto que lo usa (ni URL, ni IP a proposito, ni config);
 * solo pregunta "cual es la ultima version publicada de este paquete".
 *
 * Nunca bloquea ni revienta la peticion: cualquier fallo de red, timeout, o
 * respuesta inesperada se ignora en silencio (return null) y el listado se
 * renderiza igual, sin el aviso. El resultado se cachea en disco por 24h
 * para no consultar Packagist en cada peticion.
 */
class UpdateChecker
{
    private const PACKAGE = 'appylogi/appycrud';
    private const API_URL = 'https://repo.packagist.org/p2/appylogi/appycrud.json';
    private const CACHE_TTL_SECONDS = 86400;
    private const TIMEOUT_SECONDS = 2;

    /**
     * @return array{version: string, url: string}|null null si esta
     * desactivado (llamador decide), no hay version mas nueva, o el chequeo
     * fallo por cualquier motivo.
     */
    public static function check(string $currentVersion, ?string $cacheDir = null): ?array
    {
        $cacheFile = ($cacheDir ?? sys_get_temp_dir()) . '/appycrud-update-check.json';

        $latest = self::readCache($cacheFile) ?? self::fetchAndCache($cacheFile);

        if ($latest === null || !self::isNewer($latest, $currentVersion)) {
            return null;
        }

        return ['version' => $latest, 'url' => self::releaseUrl($latest)];
    }

    /** @return string|null version en cache, o null si no hay cache valida (fuerza a re-consultar) */
    private static function readCache(string $file): ?string
    {
        if (!is_file($file)) {
            return null;
        }

        $raw = @file_get_contents($file);

        if ($raw === false) {
            return null;
        }

        $data = json_decode($raw, true);

        if (!is_array($data) || !isset($data['checked_at']) || !array_key_exists('latest', $data)) {
            return null;
        }

        if (time() - (int) $data['checked_at'] > self::CACHE_TTL_SECONDS) {
            return null;
        }

        return $data['latest'];
    }

    private static function fetchAndCache(string $file): ?string
    {
        $latest = self::fetchLatestVersion();

        // Se cachea incluso si $latest es null (fallo de red): evita reintentar
        // en cada peticion durante una caida temporal de Packagist.
        @file_put_contents($file, json_encode(['checked_at' => time(), 'latest' => $latest]));

        return $latest;
    }

    private static function fetchLatestVersion(): ?string
    {
        $json = self::httpGet(self::API_URL);

        if ($json === null) {
            return null;
        }

        $data = json_decode($json, true);
        $versions = $data['packages'][self::PACKAGE] ?? null;

        if (!is_array($versions)) {
            return null;
        }

        $latest = null;

        foreach ($versions as $pkg) {
            $v = $pkg['version'] ?? null;

            // Solo tags semver reales (v1.2.3 o 1.2.3) — ignora dev-master
            // y cualquier otra rama que Packagist tambien liste.
            if (!is_string($v) || !preg_match('/^v?\d+\.\d+\.\d+$/', $v)) {
                continue;
            }

            $normalized = ltrim($v, 'v');

            if ($latest === null || version_compare($normalized, $latest, '>')) {
                $latest = $normalized;
            }
        }

        return $latest;
    }

    private static function httpGet(string $url): ?string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
                CURLOPT_CONNECTTIMEOUT => self::TIMEOUT_SECONDS,
                CURLOPT_USERAGENT => 'AppyCrud-UpdateChecker',
                CURLOPT_FOLLOWLOCATION => true,
            ]);
            $result = curl_exec($ch);
            $ok = curl_errno($ch) === 0;
            curl_close($ch);

            return ($ok && is_string($result)) ? $result : null;
        }

        if ((bool) ini_get('allow_url_fopen')) {
            $context = stream_context_create([
                'http' => ['timeout' => self::TIMEOUT_SECONDS, 'ignore_errors' => true],
            ]);
            $result = @file_get_contents($url, false, $context);

            return is_string($result) ? $result : null;
        }

        return null;
    }

    private static function isNewer(string $latest, string $current): bool
    {
        return version_compare($latest, ltrim($current, 'v'), '>');
    }

    private static function releaseUrl(string $version): string
    {
        return 'https://github.com/appylogi/appycrud/releases/tag/v' . $version;
    }
}
