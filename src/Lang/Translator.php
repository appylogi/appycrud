<?php

namespace Appylogi\AppyCrud\Lang;

use RuntimeException;

/**
 * Traductor minimo basado en arrays PHP. Nuevos idiomas se agregan
 * dejando un archivo lang/{codigo}.php con las mismas llaves.
 */
class Translator
{
    private array $strings;

    public function __construct(private string $locale = 'es', private string $langDir = __DIR__ . '/../../lang')
    {
        $this->strings = $this->loadLocale($this->locale);
    }

    private function loadLocale(string $locale): array
    {
        $file = $this->langDir . '/' . basename($locale) . '.php';

        if (!is_file($file)) {
            throw new RuntimeException("AppyCrud: no existe el archivo de idioma '{$locale}' en {$this->langDir}.");
        }

        return require $file;
    }

    public function t(string $key, array $replace = []): string
    {
        $value = $this->strings[$key] ?? $key;

        foreach ($replace as $search => $val) {
            $value = str_replace(':' . $search, (string) $val, $value);
        }

        return $value;
    }

    public function locale(): string
    {
        return $this->locale;
    }
}
