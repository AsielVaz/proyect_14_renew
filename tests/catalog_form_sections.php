<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/Catalogs.php';

use App\Catalogs;

$catalogs = Catalogs::all();
foreach ($catalogs as $key => $catalog) {
    $sections = $catalog['sections'] ?? [];
    if ($sections === []) {
        throw new RuntimeException('El catálogo ' . $key . ' no define secciones para su formulario.');
    }

    $currentSection = null;
    $usedSections = [];
    foreach ($catalog['fields'] as $fieldName => $field) {
        if (!empty($field['section'])) {
            $currentSection = (string) $field['section'];
            if (!array_key_exists($currentSection, $sections)) {
                throw new RuntimeException('La sección ' . $currentSection . ' de ' . $key . ' no tiene descripción.');
            }
            $usedSections[] = $currentSection;
        }
        if ($currentSection === null) {
            throw new RuntimeException('El campo ' . $fieldName . ' de ' . $key . ' quedó fuera de una sección.');
        }
    }

    if ($usedSections !== array_keys($sections)) {
        throw new RuntimeException('El orden de las secciones de ' . $key . ' no coincide con sus campos.');
    }
}

echo count($catalogs) . " formularios de catálogos tienen secciones completas.\n";
