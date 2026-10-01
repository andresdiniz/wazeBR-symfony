<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class AiNarrativeService
{
    private const BASE_URL = 'https://generativelanguage.googleapis.com/v1beta/models';
    private const MAX_RETRIES = 2;
    private const RETRY_DELAY = 3;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly string $geminiApiKey,
        private readonly string $geminiModel = 'gemini-1.5-flash-latest',
    ) {
    }

    /**
     * Gera a narrativa executiva para os dados de um parceiro.
     *
     * @param array<string, mixed> $dados
     */
    public function generate(array $dados, ?string $prompt = null): ?string
    {
        $prompt ??= $this->buildPrompt($dados);

        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            try {
                $response = $this->callGemini($prompt, $dados);

                if ($response !== null && trim($response) !== '') {
                    return $this->sanitizeOutput($response);
                }

                $this->logger->warning('AiNarrativeService: resposta sem texto válido', [
                    'attempt' => $attempt,
                    'cidade' => $dados['cidade'] ?? null,
                ]);
            } catch (TransportExceptionInterface $e) {
                $this->logger->warning('AiNarrativeService: falha de transporte', [
                    'attempt' => $attempt,
                    'error' => $e->getMessage(),
                    'cidade' => $dados['cidade'] ?? null,
                ]);
            } catch (\Throwable $e) {
                $this->logger->error('AiNarrativeService: erro inesperado', [
                    'attempt' => $attempt,
                    'error' => $e->getMessage(),
                    'cidade' => $dados['cidade'] ?? null,
                ]);
            }

            if ($attempt < self::MAX_RETRIES) {
                sleep(self::RETRY_DELAY);
            }
        }

        $this->logger->error(
            'AiNarrativeService: todas as tentativas falharam — briefing sem narrativa IA',
            [
                'cidade' => $dados['cidade'] ?? null,
                'data' => $dados['data_ref'] ?? null,
            ]
        );

        return null;
    }

    /**
     * Faz a chamada à API e salva o JSON bruto para inspeção local.
     *
     * @param array<string, mixed> $dados
     */
    private function callGemini(string $prompt, array $dados): ?string
    {
        $url = sprintf(
            '%s/%s:generateContent?key=%s',
            self::BASE_URL,
            $this->geminiModel,
            $this->geminiApiKey
        );

        $response = $this->httpClient->request('POST', $url, [
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'contents' => [
                    [
                        'parts' => [
                            [
                                'text' => $prompt,
                            ],
                        ],
                    ],
                ],
                'generationConfig' => [
                    'temperature' => 0.3,
                    'maxOutputTokens' => 1024,
                    'topP' => 0.8,
                ],
                'safetySettings' => [
                    [
                        'category' => 'HARM_CATEGORY_HARASSMENT',
                        'threshold' => 'BLOCK_NONE',
                    ],
                    [
                        'category' => 'HARM_CATEGORY_HATE_SPEECH',
                        'threshold' => 'BLOCK_NONE',
                    ],
                    [
                        'category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT',
                        'threshold' => 'BLOCK_NONE',
                    ],
                    [
                        'category' => 'HARM_CATEGORY_DANGEROUS_CONTENT',
                        'threshold' => 'BLOCK_NONE',
                    ],
                ],
            ],
            'timeout' => 30,
        ]);

        $statusCode = $response->getStatusCode();
        $rawJson = $response->getContent(false);

        $this->saveRawResponse($rawJson, [
            'status' => $statusCode,
            'cidade' => $dados['cidade'] ?? null,
            'data_ref' => $dados['data_ref'] ?? null,
            'model' => $this->geminiModel,
            'received_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ]);

        if ($statusCode === 429) {
            $this->logger->warning(
                'AiNarrativeService: limite de requisições atingido',
                [
                    'retry_after' => $response
                        ->getHeaders(false)['retry-after'][0]
                        ?? 'desconhecido',
                ]
            );

            return null;
        }

        if ($statusCode !== 200) {
            $this->logger->warning('AiNarrativeService: resposta inesperada', [
                'status' => $statusCode,
                'body' => substr($rawJson, 0, 500),
            ]);

            return null;
        }

        $data = json_decode($rawJson, true);

        if (!is_array($data)) {
            $this->logger->warning(
                'AiNarrativeService: Gemini retornou JSON inválido'
            );

            return null;
        }

        $finishReason = $data['candidates'][0]['finishReason'] ?? null;

        $this->logger->info('AiNarrativeService: resposta recebida', [
            'status' => $statusCode,
            'finish_reason' => $finishReason,
            'cidade' => $dados['cidade'] ?? null,
            'data_ref' => $dados['data_ref'] ?? null,
        ]);

        if ($finishReason === 'MAX_TOKENS') {
            $this->logger->warning(
                'AiNarrativeService: resposta interrompida por limite de tokens'
            );

            return null;
        }

        if (
            isset($data['promptFeedback']['blockReason'])
            || isset($data['candidates'][0]['finishReason'])
                && $data['candidates'][0]['finishReason'] === 'SAFETY'
        ) {
            $this->logger->warning(
                'AiNarrativeService: resposta bloqueada por segurança',
                [
                    'prompt_feedback' => $data['promptFeedback'] ?? null,
                    'finish_reason' => $finishReason,
                ]
            );

            return null;
        }

        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;

        if (!is_string($text) || trim($text) === '') {
            $this->logger->warning(
                'AiNarrativeService: JSON sem candidates.content.parts.text',
                [
                    'response_keys' => array_keys($data),
                ]
            );

            return null;
        }

        return trim($text);
    }

    /**
     * Salva o JSON bruto sem incluir a chave da API.
     *
     * @param array<string, mixed> $metadata
     */
    private function saveRawResponse(string $rawJson, array $metadata): void
    {
        $projectDir = dirname(__DIR__, 2);
        $directory = $projectDir . '/var';

        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $decoded = json_decode($rawJson, true);

        $payload = [
            'metadata' => $metadata,
            'response' => is_array($decoded)
                ? $decoded
                : [
                    'raw' => $rawJson,
                ],
        ];

        file_put_contents(
            $directory . '/gemini-response.json',
            json_encode(
                $payload,
                JSON_PRETTY_PRINT
                | JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
            )
        );
    }

    /**
     * Monta o prompt enviado ao Gemini.
     *
     * @param array<string, mixed> $d
     */
    private function buildPrompt(array $d): string
    {
        $jams = $d['jams'] ?? [];
        $alertas = $d['alertas'] ?? [];
        $media7 = $d['media_7dias'] ?? [];

        $pico = $d['pico_hora'][0] ?? null;
        $picoHora = $pico
            ? sprintf('%02dh', (int) $pico['hora_brt'])
            : 'não identificada';
        $picoTotal = $pico ? (int) $pico['total'] : 0;

        $variacaoJams = '';

        if (
            !empty($media7['media_jams_dia'])
            && (int) ($jams['total'] ?? 0) > 0
        ) {
            $pct = round(
                (
                    (
                        (int) $jams['total']
                        - (float) $media7['media_jams_dia']
                    )
                    / (float) $media7['media_jams_dia']
                ) * 100
            );

            $variacaoJams = $pct >= 0
                ? sprintf(
                    '%d%% acima da média dos últimos 7 dias',
                    $pct
                )
                : sprintf(
                    '%d%% abaixo da média dos últimos 7 dias',
                    abs($pct)
                );
        }

        $topRuasTexto = implode(', ', array_map(
            static fn (array $r): string => sprintf(
                '%s (%d jams)',
                $r['rua'] ?? 'via sem nome',
                (int) ($r['total_jams'] ?? 0)
            ),
            array_slice($d['top_ruas'] ?? [], 0, 3)
        ));

        $interdicoesTexto = '';

        if (!empty($d['interdicoes'])) {
            $linhas = array_map(
                static fn (array $i): string => sprintf(
                    '- %s (desde %s, atraso %s min)',
                    $i['rua'] ?: 'via sem nome',
                    $i['inicio_brt'] ?? 'horário não informado',
                    $i['atraso_min'] ?? 'não informado'
                ),
                array_slice($d['interdicoes'], 0, 5)
            );

            $interdicoesTexto = implode("\n", $linhas);
        }

        $buracosTexto = '';

        if (!empty($alertas['top_ruas_buracos'])) {
            $buracosTexto = implode(', ', array_map(
                static fn (array $b): string => sprintf(
                    '%s (%d ocorrências)',
                    $b['rua'] ?? 'via sem nome',
                    (int) ($b['qtd'] ?? 0)
                ),
                $alertas['top_ruas_buracos']
            ));
        }

        return sprintf(
            <<<'PROMPT'
Você é um analista de mobilidade urbana. Analise os dados
de tráfego do dia %s para %s, no fuso BRT (UTC-3), e escreva
um briefing executivo em português do Brasil.

DADOS DO DIA

- Congestionamentos: %s registros
- Variação: %s
- Velocidade média: %s km/h
- Atraso médio: %s min
- Atraso máximo: %s min
- Hora de pico: %s, com %s registros
- Nível alto ou parado: %s ocorrências
- Alertas totais: %s
- Buracos: %s
- Acidentes: %s
- Vias fechadas: %s
- Acidentes graves: %s
- Ruas mais congestionadas: %s
- Principais buracos: %s

INTERDIÇÕES ATIVAS

%s

MÉDIAS DOS ÚLTIMOS 7 DIAS

- Média de jams por dia: %s
- Velocidade média: %s km/h
- Atraso médio: %s min

INSTRUÇÕES

Escreva exatamente 3 parágrafos curtos, com no máximo
80 palavras cada:

1. Resumo do dia, fluxo geral, pico horário em BRT e
comparativo com a média dos 7 dias. Diga se foi melhor ou
pior que o normal.

2. Principal ponto de atenção, citando uma rua, via ou tipo
de problema específico com tendência observável.

3. Recomendação objetiva, com uma ação concreta direcionada
à gestão municipal ou à equipe técnica.

REGRAS

- Escreva somente os 3 parágrafos.
- Não use títulos, marcadores ou Markdown.
- Não repita todos os números das tabelas do e-mail.
- Use tom técnico e direto, adequado para gestor público.
- Não invente informações que não estejam nos dados.
- Termine cada parágrafo com pontuação.
- Não deixe frases incompletas.
- Máximo de 250 palavras no total.
PROMPT,
            $d['data_ref'] ?? 'data não informada',
            $d['cidade'] ?? 'cidade não informada',
            $jams['total'] ?? 0,
            $variacaoJams ?: 'não calculada',
            $jams['velocidade_media'] ?? 0,
            $jams['atraso_medio'] ?? 0,
            $jams['atraso_max'] ?? 0,
            $picoHora,
            $picoTotal,
            $jams['nivel_alto_mais'] ?? 0,
            $alertas['total'] ?? 0,
            $alertas['buracos'] ?? 0,
            $alertas['acidente'] ?? 0,
            $alertas['via_fechada'] ?? 0,
            $alertas['acidente_grave'] ?? 0,
            $topRuasTexto ?: 'não identificadas',
            $buracosTexto ?: 'não identificados',
            $interdicoesTexto ?: 'nenhuma',
            $media7['media_jams_dia'] ?? 0,
            $media7['media_velocidade'] ?? 0,
            $media7['media_atraso'] ?? 0
        );
    }

    private function sanitizeOutput(string $raw): string
    {
        $clean = trim($raw);

        $clean = preg_replace('/```(?:html|text)?/i', '', $clean) ?? $clean;
        $clean = str_replace('```', '', $clean);
        $clean = preg_replace('/^\s*#+\s*/m', '', $clean) ?? $clean;
        $clean = trim($clean);

        $paragraphs = preg_split(
            '/\R\s*\R+/',
            $clean
        ) ?: [$clean];

        $paragraphs = array_values(array_filter(
            array_map(
                static fn (string $paragraph): string => trim($paragraph),
                $paragraphs
            ),
            static fn (string $paragraph): bool => $paragraph !== ''
        ));

        if (count($paragraphs) !== 3) {
            $this->logger->warning(
                'AiNarrativeService: resposta não contém exatamente 3 parágrafos',
                [
                    'paragraph_count' => count($paragraphs),
                    'preview' => substr($clean, 0, 300),
                ]
            );

            return '';
        }

        foreach ($paragraphs as $paragraph) {
            if (!preg_match('/[.!?…。]$/u', $paragraph)) {
                $this->logger->warning(
                    'AiNarrativeService: parágrafo parece incompleto',
                    [
                        'preview' => substr($paragraph, 0, 300),
                    ]
                );

                return '';
            }
        }

        return implode('', array_map(
            static fn (string $paragraph): string => sprintf(
                '<p>%s</p>',
                nl2br(htmlspecialchars(
                    $paragraph,
                    ENT_QUOTES | ENT_SUBSTITUTE,
                    'UTF-8'
                ))
            ),
            $paragraphs
        ));
    }
}
