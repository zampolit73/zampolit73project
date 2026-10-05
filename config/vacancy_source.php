<?php

return [
    'minimum_confidence' => 60,
    'hypothesis_confidence' => 40,

    'company_aliases' => [
        't-bank' => 'Т-Банк',
        't bank' => 'Т-Банк',
        'tinkoff' => 'Т-Банк',
        'tinkoff bank' => 'Т-Банк',
        'тинькофф' => 'Т-Банк',
        'тинькофф банк' => 'Т-Банк',
        'т-банк' => 'Т-Банк',
    ],

    'max_saved_sources' => 28,

    'web' => [
        'max_queries' => 3,
        'max_results_per_query' => 8,
        'max_saved_sources' => 18,
        'max_elapsed_seconds' => 40,
        'connect_timeout' => 2,
        'request_timeout' => 6,
    ],

    'habr' => [
        'max_queries' => 1,
        'max_results_per_query' => 6,
        'max_skill_pages' => 3,
        'max_listing_vacancies' => 30,
        'max_pages' => 3,
        'max_elapsed_seconds' => 50,
        'connect_timeout' => 3,
        'request_timeout' => 6,
        'max_saved_sources' => 12,
        'minimum_source_score' => 30,
    ],

    'telegram' => [
        'max_hits' => 40,
        'max_saved_sources' => 18,
        'minimum_source_score' => 28,
    ],

    'scoring' => [
        'primary_phrase' => 34,
        'secondary_phrase' => 22,
        'technology_each' => 7,
        'technology_cap' => 28,
        'role_each' => 6,
        'role_cap' => 12,
        'token_overlap_multiplier' => 70,
        'token_overlap_cap' => 18,
        'explicit_company_source' => 14,
        'corroborating_domain_each' => 7,
        'corroboration_cap' => 14,
        'explicit_company_candidate' => 8,
        'cross_provider_corroboration' => 10,
        'telegram_provenance_penalty' => 8,
        'telegram_provenance_max_confidence' => 72,
    ],
];
