<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * Gera o parágrafo narrativo do briefing via Gemini Flash (Google AI Studio).
 *
 * Configurar em .env:
 *   GEMINI_API_KEY=AIza...
 *   GEMINI_MODEL=gemini-1.5-flash-latest   # ou gemini-2.0-flash
 *
 * Fallback gracioso: se a chamada falhar (rede, rate limit, quota),
 * o serviço retorna null e o Command envia o e-mail sem a seção de IA —
 * nunca deixa o envio cair por causa da análise.
 */
final class AiNarrativeService
{
    private const BASE_URL    = 'https://generativelanguage.googleapis.com/v1beta/models';
    private const MAX_RETRIES = 2;
    private const RETRY_DELAY = 3; // segundos entre tentativas

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface     $logger,
        private readonly string              $geminiApiKey,
        private readonly string              $geminiModel = 'gemini-1.5-flash-latest',
    ) {}

    /**
     * Gera a narrativa executiva para os dados de um parceiro.
     *
     * @param array  $dados  Retorno de BriefingDataService::getForCity()
     * @param string $prompt Prompt base (pode vir de config para ser customizável por parceiro)
     *
     * @return string|null HTML com a narrativa, ou null em caso de falha
     */
    public function generate(array $dados, ?string $prompt = null): ?string
    {
        $prompt ??= $this->buildPrompt($dados);

        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            try {
                $response = $this->callGemini($prompt);

                if ($response !== null) {
                    return $this->sanitizeOutput($response);
                }
            } catch (TransportExceptionInterface $e) {
                $this->logger->warning('AiNarrativeService: falha de transporte', [
                    'attempt' => $attempt,
                    'error'   => $e->getMessage(),
                    'cidade'  => $dados['cidade'],
                ]);
            } catch (\Throwable $e) {
                $this->logger->error('AiNarrativeService: erro inesperado', [
                    'attempt' => $attempt,
                    'error'   => $e->getMessage(),
                    'cidade'  => $dados['cidade'],
                ]);
            }

            if ($attempt < self::MAX_RETRIES) {
                sleep(self::RETRY_DELAY);
            }
        }

        $this->logger->error('AiNarrativeService: todas as tentativas falharam — briefing sem narrativa IA', [
            'cidade'  => $dados['cidade'],
            'data'    => $dados['data_ref'],
        ]);

        return null; // fallback gracioso: e-mail vai sem o bloco de IA
    }

    // ─── Chamada à API ────────────────────────────────────────────────────────

    private function callGemini(string $prompt): ?string
    {
        $url = sprintf('%s/%s:generateContent?key=%s',
            self::BASE_URL,
            $this->geminiModel,
            $this->geminiApiKey,
        );

        $response = $this->httpClient->request('POST', $url, [
            'headers' => ['Content-Type' => 'application/json'],
            'json'    => [
                'contents' => [
                    ['parts' => [['text' => $prompt]]],
                ],
                'generationConfig' => [
                    'temperature'     => 0.3,   // menos criatividade, mais factual
                    'maxOutputTokens' => 512,
                    'topP'            => 0.8,
                ],
                'safetySettings' => [
                    // Relaxa filtros de conteúdo para texto técnico/governamental
                    ['category' => 'HARM_CATEGORY_HARASSMENT',        'threshold' => 'BLOCK_NONE'],
                    ['category' => 'HARM_CATEGORY_HATE_SPEECH',       'threshold' => 'BLOCK_NONE'],
                    ['category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT', 'threshold' => 'BLOCK_NONE'],
                    ['category' => 'HARM_CATEGORY_DANGEROUS_CONTENT', 'threshold' => 'BLOCK_NONE'],
                ],
            ],
            'timeout' => 30,
        ]);

        $statusCode = $response->getStatusCode();

        if ($statusCode === 429) {
            $this->logger->warning('AiNarrativeService: rate limit atingido (429)', [
                'retry_after' => $response->getHeaders(false)['retry-after'][0] ?? 'desconhecido',
            ]);
            return null;
        }

        if ($statusCode !== 200) {
            $this->logger->warning('AiNarrativeService: resposta inesperada', [
                'status' => $statusCode,
                'body'   => substr($response->getContent(false), 0, 500),
            ]);
            return null;
        }

        $data = $response->toArray();

        return $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
    }

    // ─── Prompt ───────────────────────────────────────────────────────────────

    private function buildPrompt(array $d): string
    {
        $jams      = $d['jams'];
        $alertas   = $d['alertas'];
        $media7    = $d['media_7dias'];
        $pico      = $d['pico_hora'][0] ?? null;
        $picohora  = $pico ? sprintf('%02dh', (int) $pico['hora_brt']) : 'não identificada';
        $picoTotal = $pico ? $pico['total'] : 0;

        $variacaoJams = '';
        if (!empty($media7['media_jams_dia']) && (int) $jams['total'] > 0) {
            $pct = round((((int) $jams['total'] - (float) $media7['media_jams_dia']) / (float) $media7['media_jams_dia']) * 100);
            $variacaoJams = $pct >= 0
                ? sprintf('%d%% acima da média dos últimos 7 dias', $pct)
                : sprintf('%d%% abaixo da média dos últimos 7 dias', abs($pct));
        }

        $topRuasTexto = implode(', ', array_map(
            fn($r) => sprintf('%s (%d jams)', $r['rua'], $r['total_jams']),
            array_slice($d['top_ruas'], 0, 3)
        ));

        $interdicoesTexto = '';
        if (!empty($d['interdicoes'])) {
            $linhas = array_map(
                fn($i) => sprintf('- %s (desde %s, atraso %s min)',
                    $i['rua'] ?: 'via sem nome', $i['inicio_brt'], $i['atraso_min']),
                array_slice($d['interdicoes'], 0, 5)
            );
            $interdicoesTexto = implode("\n", $linhas);
        }

        $buracosTexto = '';
        if (!empty($alertas['top_ruas_buracos'])) {
            $buracosTexto = implode(', ', array_map(
                fn($b) => sprintf('%s (%d ocorrências)', $b['rua'], $b['qtd']),
                $alertas['top_ruas_buracos']
            ));
        }

        return <<<PROMPT
Você é um analista de mobilidade urbana. Analise os dados de tráfego do dia {$d['data_ref']} para {$d['cidade']} (fuso BRT = UTC-3) e escreva um briefing executivo em português.

DADOS DO DIA:
- Congestionamentos: {$jams['total']} registros ({$variacaoJams})
- Velocidade média: {$jams['velocidade_media']} km/h
- Atraso médio: {$jams['atraso_medio']} min (máximo: {$jams['atraso_max']} min)
- Hora de pico: {$picohora} com {$picoTotal} registros
- Nível alto ou parado: {$jams['nivel_alto_mais']} ocorrências
- Alertas totais: {$alertas['total']} (buracos: {$alertas['buracos']}, acidentes: {$alertas['acidente']}, vias fechadas: {$alertas['via_fechada']})
- Acidentes graves: {$alertas['acidente_grave']}
- Top ruas congestionadas: {$topRuasTexto}
- Principais buracos: {$buracosTexto}

INTERDIÇÕES ATIVAS (nível 5):
{$interdicoesTexto}

MÉDIAS DOS ÚLTIMOS 7 DIAS:
- Média de jams/dia: {$media7['media_jams_dia']}
- Velocidade média: {$media7['media_velocidade']} km/h
- Atraso médio: {$media7['media_atraso']} min

INSTRUÇÕES:
Escreva exatamente 3 parágrafos curtos (máximo 80 palavras cada):
1. Resumo do dia — fluxo geral, pico horário em BRT, comparativo com a média dos 7 dias. Diga se foi um dia melhor ou pior que o normal.
2. Ponto de atenção principal — uma rua, via ou tipo de problema específico com tendência observável.
3. Recomendação objetiva — uma ação concreta direcionada à gestão municipal ou à equipe técnica.

REGRAS:
- Escreva somente os 3 parágrafos, sem títulos, sem marcadores, sem markdown.
- Não repita números que já aparecem nas tabelas do e-mail (eles já estão visíveis).
- Tom: técnico, direto, adequado para gestor público. Máximo 250 palavras no total.
PROMPT;
    }

    // ─── Pós-processamento ────────────────────────────────────────────────────

    /**
     * Converte o texto plano retornado pela IA em parágrafos HTML simples.
     * Remove markdown residual (**, *, #) que o modelo às vezes insere.
     */
    private function sanitizeOutput(string $raw): string
    {
        // Remove markdown
        $clean = preg_replace('/[*#`]+/', '', $raw);
        $clean = trim((string) $clean);

        // Quebra em parágrafos por linha em branco dupla ou \n\n
        $paragraphs = preg_split('/\n{2,}/', $clean);

        return implode('', array_map(
            fn(string $p) => sprintf('<p>%s</p>', nl2br(trim($p))),
            array_filter((array) $paragraphs, fn($p) => trim($p) !== '')
        ));
    }
}
