<?php

return [
    'minimum_confidence' => 60,

    'web' => [
        'max_queries' => 6,
        'max_results_per_query' => 8,
        'max_saved_sources' => 18,
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
    ],
];
