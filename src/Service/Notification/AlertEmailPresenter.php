<?php

declare(strict_types=1);

namespace App\Service\Notification;

use App\Entity\WazeAlert;

/**
 * Transforma um alerta/evento em texto específico para o e-mail:
 * título claro, frase do que aconteceu, gravidade, ação sugerida e dados de confiança.
 */
final class AlertEmailPresenter
{
    public const SEVERITY_CRITICAL = 'critical';
    public const SEVERITY_HIGH = 'high';
    public const SEVERITY_MEDIUM = 'medium';
    public const SEVERITY_INFO = 'info';

    /** @var array<string, array{label: string, color: string, bg: string}> */
    private const SEVERITIES = [
        self::SEVERITY_CRITICAL => ['label' => 'Crítico', 'color' => '#9f1d12', 'bg' => '#fde8e6'],
        self::SEVERITY_HIGH => ['label' => 'Alto', 'color' => '#a24a00', 'bg' => '#feeedb'],
        self::SEVERITY_MEDIUM => ['label' => 'Atenção', 'color' => '#7a5b00', 'bg' => '#fdf3d1'],
        self::SEVERITY_INFO => ['label' => 'Informativo', 'color' => '#0b5d70', 'bg' => '#e1f2f6'],
    ];

    /**
     * [título, o que aconteceu, gravidade, o que fazer]
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private const SUBTYPES = [
        'ACCIDENT_MAJOR' => ['Acidente grave', 'Motoristas reportaram um acidente de grande porte, com possível bloqueio de faixas e feridos.', self::SEVERITY_CRITICAL, 'Confirme o acionamento de SAMU, bombeiros e PM e organize o desvio do fluxo.'],
        'ACCIDENT_MINOR' => ['Acidente leve', 'Motoristas reportaram um acidente de menor porte, que pode ocupar faixa e reduzir a velocidade da via.', self::SEVERITY_HIGH, 'Verifique se há necessidade de apoio no local e acompanhe a formação de fila.'],

        'ROAD_CLOSED_CONSTRUCTION' => ['Via fechada por obra', 'A via foi reportada como fechada ao tráfego por causa de obra.', self::SEVERITY_HIGH, 'Confirme se o fechamento é programado e sinalize a rota alternativa.'],
        'ROAD_CLOSED_EVENT' => ['Via fechada por evento', 'A via foi reportada como fechada ao tráfego por causa de um evento.', self::SEVERITY_MEDIUM, 'Confirme se o evento é conhecido pela operação e ajuste os desvios.'],
        'ROAD_CLOSED_HAZARD' => ['Via fechada por perigo', 'A via foi reportada como fechada ao tráfego por causa de um perigo no local.', self::SEVERITY_CRITICAL, 'Identifique o perigo, acione a equipe responsável e sinalize o desvio.'],

        'HAZARD_ON_ROAD' => ['Perigo na pista', 'Motoristas reportaram um perigo na pista, sem detalhar o tipo.', self::SEVERITY_HIGH, 'Envie uma equipe para identificar o perigo e sinalizar o trecho.'],
        'HAZARD_ON_ROAD_OBJECT' => ['Objeto na pista', 'Motoristas reportaram um objeto na pista que pode causar acidentes.', self::SEVERITY_HIGH, 'Acione a equipe de limpeza/remoção e sinalize o trecho até a retirada.'],
        'HAZARD_ON_ROAD_CAR_STOPPED' => ['Veículo parado na pista', 'Há um veículo parado na pista, com risco de colisão traseira.', self::SEVERITY_HIGH, 'Verifique a necessidade de guincho ou sinalização.'],
        'HAZARD_ON_ROAD_LANE_CLOSED' => ['Faixa fechada', 'Uma faixa da via foi reportada como fechada, reduzindo a capacidade do trecho.', self::SEVERITY_MEDIUM, 'Confirme o motivo do bloqueio e acompanhe a fila.'],
        'HAZARD_ON_ROAD_POT_HOLE' => ['Buraco na pista', 'Motoristas reportaram buraco na pista, com risco de dano aos veículos.', self::SEVERITY_MEDIUM, 'Encaminhe para a equipe de manutenção viária.'],
        'HAZARD_ON_ROAD_ROAD_KILL' => ['Animal morto na pista', 'Há um animal morto na pista, com risco de desvios bruscos.', self::SEVERITY_MEDIUM, 'Acione a equipe de limpeza urbana ou o órgão responsável.'],
        'HAZARD_ON_ROAD_TRAFFIC_LIGHT_FAULT' => ['Semáforo com defeito', 'Motoristas reportaram semáforo com defeito neste ponto.', self::SEVERITY_HIGH, 'Acione a manutenção semafórica e avalie apoio de agentes de trânsito.'],
        'HAZARD_ON_ROAD_ICE' => ['Pista com gelo', 'Motoristas reportaram gelo na pista.', self::SEVERITY_HIGH, 'Sinalize o trecho e oriente redução de velocidade.'],
        'HAZARD_ON_ROAD_CONSTRUCTION' => ['Obra na pista', 'Há obra em andamento na pista, com possível estreitamento.', self::SEVERITY_INFO, 'Confirme se a obra é programada e se a sinalização está adequada.'],
        'HAZARD_ON_ROAD_EMERGENCY_VEHICLE' => ['Veículo de emergência na pista', 'Há um veículo de emergência atendendo no local.', self::SEVERITY_MEDIUM, 'Verifique se há ocorrência em andamento no trecho.'],
        'HAZARD_ON_ROAD_OIL' => ['Óleo na pista', 'Motoristas reportaram óleo na pista, com risco de derrapagem.', self::SEVERITY_HIGH, 'Sinalize o trecho e acione a equipe de limpeza.'],

        'HAZARD_ON_SHOULDER' => ['Perigo no acostamento', 'Motoristas reportaram um perigo no acostamento.', self::SEVERITY_INFO, 'Acompanhe; só é necessário agir se afetar a pista.'],
        'HAZARD_ON_SHOULDER_CAR_STOPPED' => ['Veículo parado no acostamento', 'Há um veículo parado no acostamento.', self::SEVERITY_INFO, 'Acompanhe; avalie apoio se o veículo invadir a pista.'],
        'HAZARD_ON_SHOULDER_ANIMALS' => ['Animais no acostamento', 'Há animais no acostamento, com risco de invadirem a pista.', self::SEVERITY_MEDIUM, 'Avalie acionar o órgão responsável e sinalizar o trecho.'],
        'HAZARD_ON_SHOULDER_MISSING_SIGN' => ['Sinalização ausente', 'Motoristas reportaram sinalização ausente neste ponto.', self::SEVERITY_MEDIUM, 'Encaminhe para a equipe de sinalização viária.'],

        'HAZARD_WEATHER' => ['Risco climático', 'Motoristas reportaram condição climática de risco na via.', self::SEVERITY_MEDIUM, 'Acompanhe a evolução e alerte as equipes de campo.'],
        'HAZARD_WEATHER_FLOOD' => ['Alagamento', 'Motoristas reportaram alagamento na via, com risco de interdição.', self::SEVERITY_CRITICAL, 'Acione a Defesa Civil e a equipe de drenagem e avalie interditar o trecho.'],
        'HAZARD_WEATHER_FOG' => ['Neblina', 'Motoristas reportaram neblina, com visibilidade reduzida.', self::SEVERITY_MEDIUM, 'Reforce a sinalização e oriente redução de velocidade.'],
        'HAZARD_WEATHER_HEAVY_RAIN' => ['Chuva intensa', 'Motoristas reportaram chuva intensa neste ponto.', self::SEVERITY_MEDIUM, 'Monitore pontos de alagamento próximos.'],
        'HAZARD_WEATHER_FREEZING_RAIN' => ['Chuva congelante', 'Motoristas reportaram chuva congelante, com risco de pista escorregadia.', self::SEVERITY_HIGH, 'Sinalize e oriente redução de velocidade.'],
        'HAZARD_WEATHER_HAIL' => ['Granizo', 'Motoristas reportaram queda de granizo.', self::SEVERITY_MEDIUM, 'Acompanhe possíveis acidentes e danos na via.'],
        'HAZARD_WEATHER_HURRICANE' => ['Furacão', 'Foi reportado um furacão na região.', self::SEVERITY_CRITICAL, 'Acione a Defesa Civil imediatamente.'],
        'HAZARD_WEATHER_TORNADO' => ['Tornado', 'Foi reportado um tornado na região.', self::SEVERITY_CRITICAL, 'Acione a Defesa Civil imediatamente.'],
        'HAZARD_WEATHER_MONSOON' => ['Monção', 'Foi reportada monção na região.', self::SEVERITY_HIGH, 'Acompanhe com a Defesa Civil.'],
        'HAZARD_WEATHER_SNOW' => ['Neve', 'Motoristas reportaram neve na pista.', self::SEVERITY_HIGH, 'Sinalize o trecho e oriente redução de velocidade.'],
        'HAZARD_WEATHER_HEAVY_SNOW' => ['Neve intensa', 'Motoristas reportaram neve intensa na pista.', self::SEVERITY_HIGH, 'Sinalize o trecho e oriente redução de velocidade.'],
        'HAZARD_WEATHER_HEAT_WAVE' => ['Onda de calor', 'Foi reportada onda de calor na região.', self::SEVERITY_INFO, 'Acompanhe os avisos da Defesa Civil.'],
        'HAZARD_WEATHER_VOLCANIC_ERUPTION' => ['Erupção vulcânica', 'Foi reportada erupção vulcânica na região.', self::SEVERITY_CRITICAL, 'Acione a Defesa Civil imediatamente.'],

        'JAM_LIGHT_TRAFFIC' => ['Trânsito leve', 'A via está com trânsito levemente mais lento que o normal.', self::SEVERITY_INFO, 'Sem ação imediata; acompanhe.'],
        'JAM_MODERATE_TRAFFIC' => ['Trânsito moderado', 'A via está com lentidão moderada.', self::SEVERITY_MEDIUM, 'Acompanhe a evolução e verifique a causa.'],
        'JAM_HEAVY_TRAFFIC' => ['Trânsito intenso', 'A via está com lentidão forte.', self::SEVERITY_HIGH, 'Verifique a causa e avalie apoio de agentes de trânsito.'],
        'JAM_STAND_STILL_TRAFFIC' => ['Trânsito parado', 'O trânsito está praticamente parado neste trecho.', self::SEVERITY_HIGH, 'Identifique a causa e avalie apoio de agentes e desvios.'],

        'POLICE_VISIBLE' => ['Polícia visível', 'Motoristas reportaram presença policial visível neste ponto.', self::SEVERITY_INFO, 'Informativo.'],
        'POLICE_HIDING' => ['Radar / polícia oculta', 'Motoristas reportaram policiamento ou radar oculto neste ponto.', self::SEVERITY_INFO, 'Informativo.'],
        'POLICE_WITH_MOBILE_CAMERA' => ['Radar móvel', 'Motoristas reportaram radar móvel neste ponto.', self::SEVERITY_INFO, 'Informativo.'],

        'MISC_ILLEGAL_PARKING' => ['Estacionamento irregular', 'Motoristas reportaram estacionamento irregular atrapalhando a via.', self::SEVERITY_INFO, 'Avalie acionar a fiscalização de trânsito.'],
        'MISC_POTHOLE' => ['Buraco na via', 'Motoristas reportaram buraco na via.', self::SEVERITY_MEDIUM, 'Encaminhe para a equipe de manutenção viária.'],
        'CHIT_CHAT_DISCUSSION' => ['Discussão no mapa', 'Foi aberta uma discussão neste ponto do mapa.', self::SEVERITY_INFO, 'Informativo.'],
    ];

    /** Quando não há subtipo (ou ele é desconhecido), descreve pelo tipo. */
    private const TYPES = [
        'ACCIDENT' => ['Acidente', 'Motoristas reportaram um acidente na via.', self::SEVERITY_HIGH, 'Confirme com a operação se há feridos ou necessidade de apoio.'],
        'ROAD_CLOSED' => ['Via fechada', 'A via foi reportada como fechada ao tráfego.', self::SEVERITY_HIGH, 'Confirme o motivo do fechamento e sinalize a rota alternativa.'],
        'HAZARD' => ['Perigo na via', 'Motoristas reportaram um perigo na via.', self::SEVERITY_MEDIUM, 'Envie uma equipe para identificar o perigo.'],
        'JAM' => ['Congestionamento', 'Motoristas reportaram congestionamento na via.', self::SEVERITY_MEDIUM, 'Verifique a causa da lentidão.'],
        'POLICE' => ['Polícia / radar', 'Motoristas reportaram presença policial ou radar.', self::SEVERITY_INFO, 'Informativo.'],
        'CONSTRUCTION' => ['Obra na via', 'Foi reportada obra na via.', self::SEVERITY_INFO, 'Confirme se a obra é programada.'],
        'MISC' => ['Ocorrência diversa', 'Motoristas reportaram uma ocorrência na via.', self::SEVERITY_INFO, 'Avalie se requer ação.'],
        'CHIT_CHAT' => ['Discussão no mapa', 'Foi aberta uma discussão neste ponto do mapa.', self::SEVERITY_INFO, 'Informativo.'],
    ];

    private const ROAD_TYPES = [
        1 => 'Rua',
        2 => 'Via principal',
        3 => 'Via expressa / rodovia',
        4 => 'Alça de acesso',
        6 => 'Rodovia principal',
        7 => 'Rodovia secundária',
        17 => 'Via privada',
        20 => 'Estacionamento',
        22 => 'Beco',
    ];

    private const HEADINGS = ['Norte', 'Nordeste', 'Leste', 'Sudeste', 'Sul', 'Sudoeste', 'Oeste', 'Noroeste'];

    /** Título em português de um código de tipo ou subtipo, ou null se desconhecido. */
    public static function label(string $code): ?string
    {
        return (self::SUBTYPES[$code] ?? self::TYPES[$code] ?? [null])[0];
    }

    /**
     * Descrição pelo tipo/subtipo já normalizados (ver NotificationCatalog::normalize).
     *
     * @return array{headline: string, summary: string, action: string, severity: array{key: string, label: string, color: string, bg: string}}
     */
    public function describe(string $type, ?string $subtype): array
    {
        $entry = ($subtype !== null ? self::SUBTYPES[$subtype] ?? null : null)
            ?? self::TYPES[$type]
            ?? [ucfirst(mb_strtolower(str_replace('_', ' ', $subtype ?? $type))), 'Foi reportada uma ocorrência na via.', self::SEVERITY_INFO, 'Avalie se requer ação.'];

        [$headline, $summary, $severity, $action] = $entry;

        return [
            'headline' => $headline,
            'summary' => $summary,
            'action' => $action,
            'severity' => ['key' => $severity] + self::SEVERITIES[$severity],
        ];
    }

    /**
     * Fatos que ajudam a julgar o alerta: há quanto tempo, confiabilidade, confirmações, sentido, tipo de via.
     *
     * @return array{age: ?string, reliability: ?array{label: string, score: int}, confirmations: int, heading: ?string, road_type: ?string, municipality: bool}
     */
    public function facts(WazeAlert $alert, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $pub = (int) ($alert->getPubMillis() ?? 0);
        $reliability = (int) $alert->getReliability();

        return [
            'age' => $pub > 0 ? $this->ago((int) floor($pub / 1000), $now->getTimestamp()) : null,
            'reliability' => $reliability > 0
                ? ['label' => $reliability >= 8 ? 'Alta' : ($reliability >= 5 ? 'Média' : 'Baixa'), 'score' => min(10, $reliability)]
                : null,
            'confirmations' => max(0, (int) $alert->getNThumbsUp()),
            'heading' => $this->heading((int) $alert->getMagvar()),
            'road_type' => self::ROAD_TYPES[(int) $alert->getRoadType()] ?? null,
            'municipality' => $alert->isReportByMunicipalityUser(),
        ];
    }

    private function ago(int $publishedAt, int $now): string
    {
        $seconds = max(0, $now - $publishedAt);

        return match (true) {
            $seconds < 60 => 'há instantes',
            $seconds < 3600 => 'há ' . intdiv($seconds, 60) . ' min',
            $seconds < 86400 => 'há ' . intdiv($seconds, 3600) . ' h ' . intdiv($seconds % 3600, 60) . ' min',
            default => 'há ' . ($days = intdiv($seconds, 86400)) . ($days === 1 ? ' dia' : ' dias'),
        };
    }

    private function heading(int $degrees): ?string
    {
        if ($degrees <= 0 || $degrees > 360) {
            return null; // 0 é o valor padrão do feed quando não há direção
        }

        return self::HEADINGS[(int) round(($degrees % 360) / 45) % 8];
    }
}
