<?php

namespace App\Services;

use Illuminate\Support\Str;

class VacancySignalExtractor
{
    private const TECHNOLOGIES = [
        'JavaScript' => ['javascript', 'js', 'ecmascript', 'es6'],
        'TypeScript' => ['typescript', 'ts'],
        'React' => ['react', 'react.js', 'reactjs'],
        'Redux' => ['redux'],
        'Electron' => ['electron', 'electron.js', 'electronjs'],
        'Vue' => ['vue', 'vue.js', 'vuejs'],
        'Angular' => ['angular'],
        'Node.js' => ['node.js', 'nodejs', 'node js'],
        'HTML' => ['html', 'html5'],
        'CSS' => ['css', 'css3'],
        'Java' => ['java'],
        'Kotlin' => ['kotlin'],
        'Spring' => ['spring', 'spring boot'],
        'Kafka' => ['kafka', 'apache kafka'],
        'Camunda' => ['camunda'],
        'PostgreSQL' => ['postgresql', 'postgres'],
        'Oracle' => ['oracle'],
        'Redis' => ['redis'],
        'Python' => ['python'],
        'Django' => ['django'],
        'FastAPI' => ['fastapi'],
        'Go' => ['golang', 'go'],
        'C#' => ['c#', 'c sharp'],
        '.NET' => ['.net', 'dotnet', 'asp.net'],
        'PHP' => ['php'],
        'Laravel' => ['laravel'],
        'Symfony' => ['symfony'],
        'Ruby' => ['ruby'],
        'Rails' => ['rails', 'ruby on rails'],
        'Kubernetes' => ['kubernetes', 'k8s'],
        'Docker' => ['docker'],
        'RabbitMQ' => ['rabbitmq'],
        'Elasticsearch' => ['elasticsearch', 'elastic search'],
        'ClickHouse' => ['clickhouse'],
        'MongoDB' => ['mongodb', 'mongo db'],
        'GraphQL' => ['graphql'],
        'gRPC' => ['grpc'],
        'REST' => ['rest api', 'restful', 'rest'],
        'SAP' => ['sap'],
        '1C' => ['1c', '1с'],
    ];

    private const ROLE_TERMS = [
        'frontend' => ['frontend', 'front-end', 'фронтенд'],
        'backend' => ['backend', 'back-end', 'бэкенд', 'бекенд'],
        'fullstack' => ['fullstack', 'full-stack', 'фулстек'],
        'developer' => ['developer', 'разработчик', 'программист'],
        'architect' => ['architect', 'архитектор'],
        'analyst' => ['analyst', 'аналитик'],
        'qa' => ['qa', 'quality assurance', 'тестировщик', 'тестирование'],
        'devops' => ['devops', 'sre', 'site reliability'],
        'data' => ['data engineer', 'data scientist', 'ml engineer', 'machine learning', 'дата инженер'],
    ];

    private const PHRASE_NOISE = [
        'локация',
        'гражданство',
        'удален',
        'удалён',
        'ставка',
        'ндс',
        'оплата',
        'зарплата',
        'длительность',
        'срок проекта',
        'требования',
        'обязанности',
        'условия',
        'мы предлагаем',
        'график работы',
    ];

    public function extract(string $text): array
    {
        $normalized = $this->normalize($text);

        return [
            'normalized_text' => $normalized,
            'fingerprint' => hash('sha256', $normalized),
            'technologies' => $this->extractTechnologies($normalized),
            'role_terms' => $this->extractRoleTerms($normalized),
            'phrases' => $this->extractPhrases($text),
            'explicit_company' => $this->extractExplicitCompany($text),
        ];
    }

    public function normalize(string $text): string
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace('ё', 'е', Str::lower($text));
        $text = preg_replace('/[\x{2010}-\x{2015}]/u', '-', $text) ?: $text;
        $text = preg_replace('/[^\p{L}\p{N}#+.\/-]+/u', ' ', $text) ?: $text;

        return trim(preg_replace('/\s+/u', ' ', $text) ?: $text);
    }

    public function significantTokens(string $text): array
    {
        $normalized = $this->normalize($text);
        $tokens = preg_split('/\s+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $tokens = array_filter($tokens, function (string $token): bool {
            if (mb_strlen($token) < 4) {
                return false;
            }

            return ! in_array($token, [
                'работа', 'опыт', 'знание', 'знания', 'требования', 'условия',
                'проект', 'проекта', 'команда', 'команде', 'разработка', 'разработки',
                'работы', 'умение', 'будет', 'нужно', 'важно', 'years', 'experience',
                'with', 'from', 'have', 'work', 'working', 'requirements',
            ], true);
        });

        return array_values(array_unique($tokens));
    }

    private function extractTechnologies(string $normalized): array
    {
        $found = [];

        foreach (self::TECHNOLOGIES as $canonical => $aliases) {
            foreach ($aliases as $alias) {
                if ($this->containsTerm($normalized, $alias)) {
                    $found[] = $canonical;
                    break;
                }
            }
        }

        return $found;
    }

    private function extractRoleTerms(string $normalized): array
    {
        $found = [];

        foreach (self::ROLE_TERMS as $canonical => $aliases) {
            foreach ($aliases as $alias) {
                if ($this->containsTerm($normalized, $alias)) {
                    $found[] = $canonical;
                    break;
                }
            }
        }

        return $found;
    }

    private function extractPhrases(string $text): array
    {
        $lines = preg_split('/\R/u', $text) ?: [];
        $ranked = [];

        foreach ($lines as $line) {
            $line = trim(preg_replace('/^[\s\-—–_•*·]+/u', '', $line) ?: $line);
            $line = trim($line, " \t\n\r\0\x0B;.");

            if (mb_strlen($line) < 24 || mb_strlen($line) > 180) {
                continue;
            }

            $normalized = $this->normalize($line);

            if ($normalized === '') {
                continue;
            }

            foreach (self::PHRASE_NOISE as $noise) {
                if (str_starts_with($normalized, $noise)) {
                    continue 2;
                }
            }

            $tokens = $this->significantTokens($line);

            if (count($tokens) < 3) {
                continue;
            }

            $latinTechWeight = preg_match_all('/[A-Za-z][A-Za-z0-9.+#-]{2,}/u', $line);
            $longWords = count(array_filter($tokens, fn (string $token) => mb_strlen($token) >= 7));
            $score = count($tokens) + ($latinTechWeight * 2) + $longWords;

            $ranked[] = [
                'text' => $line,
                'score' => $score,
            ];
        }

        usort($ranked, fn (array $a, array $b) => $b['score'] <=> $a['score']);

        return array_values(array_map(
            fn (array $item) => $item['text'],
            array_slice($ranked, 0, 4),
        ));
    }

    private function extractExplicitCompany(string $text): ?string
    {
        if (! preg_match('/(?:компания|клиент|заказчик)\s*[:\-]?\s*[«"“]?([\p{L}\p{N}][\p{L}\p{N} .&+_\-]{1,60})/iu', $text, $matches)) {
            return null;
        }

        $candidate = trim(preg_split('/[,;\n\r|]/u', $matches[1])[0] ?? '');
        $candidate = trim($candidate, " .\t\n\r\0\x0B»”\"'");

        if (
            mb_strlen($candidate) < 2
            || preg_match('/\b(ищет|нужен|нужна|предлагает|требуется|предоставляет)\b/iu', $candidate)
        ) {
            return null;
        }

        return Str::limit($candidate, 80, '');
    }

    private function containsTerm(string $haystack, string $needle): bool
    {
        $needle = $this->normalize($needle);

        if ($needle === '') {
            return false;
        }

        return preg_match(
            '/(^|[^\p{L}\p{N}])'.preg_quote($needle, '/').'([^\p{L}\p{N}]|$)/u',
            $haystack,
        ) === 1;
    }
}
