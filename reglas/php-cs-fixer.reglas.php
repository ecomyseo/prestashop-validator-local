<?php
/**
 * Reglas de la pestaña «Standards» del validator oficial.
 *
 * Son las de PrestaShop\CodingStandards\CsFixer\Config de prestashop/php-dev-tools v4.3.0 / v5
 * (idénticas entre sí). La versión de dev-master añadió 'blank_line_after_opening_tag' => false,
 * y el validator oficial SÍ marca esa regla: por eso se fija esta versión y no la última.
 *
 * Medido el 2026-11-02 contra un informe oficial (módulo del generador oficial, 378 resultados):
 * en los index.php el oficial solo marca align_multiline_comment y blank_line_after_opening_tag,
 * es decir, ni concat_space (spacing one) ni phpdoc_summary (false) ni yoda_style (false).
 *
 * Riesgosas permitidas (setRiskyAllowed(true) en Config).
 */
return [
    '@Symfony' => true,
    'concat_space' => [
        'spacing' => 'one',
    ],
    'cast_spaces' => [
        'space' => 'single',
    ],
    'error_suppression' => [
        'mute_deprecation_error' => false,
        'noise_remaining_usages' => false,
        'noise_remaining_usages_exclude' => [],
    ],
    'function_to_constant' => false,
    'visibility_required' => [
        'elements' => ['property', 'method'],
    ],
    'no_alias_functions' => false,
    'phpdoc_summary' => false,
    'phpdoc_align' => [
        'align' => 'left',
    ],
    'protected_to_private' => false,
    'psr_autoloading' => false,
    'self_accessor' => false,
    'yoda_style' => false,
    'non_printable_character' => true,
    'no_superfluous_phpdoc_tags' => false,
];
