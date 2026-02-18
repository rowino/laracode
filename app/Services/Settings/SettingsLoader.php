<?php

declare(strict_types=1);

namespace App\Services\Settings;

class SettingsLoader
{
    /**
     * @return array<string, mixed>
     */
    public function loadAll(?string $projectPath = null): array
    {
        $defaults = config('laracode', []);
        $user = $this->loadFile(SettingsPath::user());
        $project = $this->loadFile(SettingsPath::project($projectPath));
        $local = $this->loadFile(SettingsPath::local($projectPath));

        return $this->deepMerge(
            is_array($defaults) ? $defaults : [],
            $user,
            $project,
            $local
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function loadFile(string $path): array
    {
        if (! file_exists($path) || ! is_readable($path)) {
            return [];
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return [];
        }

        $data = json_decode($content, true);

        return is_array($data) ? $data : [];
    }

    /**
     * @return array{default: array<string, mixed>, user: array<string, mixed>, project: array<string, mixed>, local: array<string, mixed>}
     */
    public function loadLayers(?string $projectPath = null): array
    {
        return [
            'default' => is_array($defaults = config('laracode', [])) ? $defaults : [],
            'user' => $this->loadFile(SettingsPath::user()),
            'project' => $this->loadFile(SettingsPath::project($projectPath)),
            'local' => $this->loadFile(SettingsPath::local($projectPath)),
        ];
    }

    /**
     * @param  array<string, mixed>  ...$arrays
     * @return array<string, mixed>
     */
    public function deepMerge(array ...$arrays): array
    {
        $result = [];

        foreach ($arrays as $array) {
            foreach ($array as $key => $value) {
                if (is_array($value) && isset($result[$key]) && is_array($result[$key])) {
                    if (array_is_list($value)) {
                        $result[$key] = $value;
                    } else {
                        $result[$key] = $this->deepMerge($result[$key], $value);
                    }
                } else {
                    $result[$key] = $value;
                }
            }
        }

        return $result;
    }
}
