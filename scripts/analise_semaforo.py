# -*- coding: utf-8 -*-
"""
analise_semaforo.py
====================

Ferramenta genérica para comparar duas aproximações de um mesmo cruzamento
semaforizado, a partir de históricos de tráfego exportados no formato Waze
(as mesmas colunas usadas nos arquivos "historico_rota_*.csv"), e gerar
automaticamente um PDF de diagnóstico + plano de atuação, no mesmo padrão
usado para o cruzamento Av. Telésforo C. Resende x R. Dias de Souza.

------------------------------------------------------------------------
FORMATO DE ENTRADA ESPERADO (csv, separador ";", encoding utf-8-sig)
------------------------------------------------------------------------
coletado_em;dia_semana;nome;de;para;jam_level;tempo_atual_seg;tempo_atual_min;
tempo_historico_seg;tempo_historico_min;atraso_seg;atraso_min;extensao_km

Só as colunas abaixo são realmente usadas pelo script:
    coletado_em          -> data/hora da coleta (qualquer formato que o
                             pandas.to_datetime reconheça)
    tempo_atual_seg       -> tempo de travessia no momento da coleta
    tempo_historico_seg   -> tempo de referência (sem incidente)
    atraso_seg            -> tempo_atual - tempo_historico
    jam_level             -> nível de congestionamento do Waze (0 a 5)
    extensao_km           -> extensão do trecho (para checar comparabilidade)

Qualquer CSV nesse layout serve — não precisa ser especificamente Dias de
Souza / Telésforo. Basta trocar os arquivos e os nomes das vias.

------------------------------------------------------------------------
COORDENADAS (OPCIONAL)
------------------------------------------------------------------------
Se você tiver, para cada via, um CSV com a geometria da rua (lat/lon de
vários pontos ao longo do trecho — ex.: exportado do Google My Maps, de um
GPX convertido, ou do próprio Waze Map Editor), o script desenha um mapa
esquemático de localização (sem depender de internet/tiles) e insere no
PDF, na seção 2 (Localização e Contexto), no lugar do texto genérico.

Formato esperado desse CSV auxiliar (livre, mas precisa ter estas colunas):
    lat;lon
    -20.6624;-43.7867
    -20.6621;-43.7863
    ...
(pontos em sequência, do início ao fim do trecho)

------------------------------------------------------------------------
USO
------------------------------------------------------------------------
Exemplo mínimo:

    python3 analise_semaforo.py rota_secundaria.csv rota_principal.csv \
        --nome1 "R. Dias de Souza" \
        --nome2 "Av. Prefeito Telésforo Cândido Resende" \
        --cidade "Conselheiro Lafaiete/MG"

Exemplo completo, com simulação customizada, contexto e coordenadas:

    python3 analise_semaforo.py rota_secundaria.csv rota_principal.csv \
        --nome1 "R. Dias de Souza" \
        --nome2 "Av. Prefeito Telésforo Cândido Resende" \
        --cidade "Conselheiro Lafaiete/MG" \
        --contexto "A R. Dias de Souza dá acesso a um shopping com cinema." \
        --hora-pico-ini 11 --hora-pico-fim 16 \
        --razao-alvo-pico 1.8 --razao-alvo-fora 2.5 \
        --coords1 coords_dias_de_souza.csv \
        --coords2 coords_avenida.csv \
        --saida plano_semaforico.pdf

Todas as flags de --nome, --cidade, --contexto, razões-alvo, etc. são
opcionais; se omitidas, o script usa nomes genéricos ("Via 1"/"Via 2") e
uma razão-alvo padrão razoável.

Dependências: pandas, matplotlib, reportlab
    pip install pandas matplotlib reportlab --break-system-packages
"""

import argparse
import sys
import pandas as pd
import numpy as np
import matplotlib
matplotlib.use('Agg')
import matplotlib.pyplot as plt

from reportlab.lib.pagesizes import A4
from reportlab.lib.units import cm
from reportlab.lib import colors
from reportlab.lib.styles import getSampleStyleSheet, ParagraphStyle
from reportlab.lib.enums import TA_CENTER, TA_JUSTIFY, TA_LEFT
from reportlab.platypus import (SimpleDocTemplate, Paragraph, Spacer, Table, TableStyle,
                                 Image, PageBreak, HRFlowable, ListFlowable, ListItem)

plt.rcParams['font.family'] = 'DejaVu Sans'

# ==========================================================================
# 1. CARGA E ESTATÍSTICAS
# ==========================================================================

def carregar_csv(path):
    """Lê um histórico de rota no formato Waze e prepara colunas auxiliares."""
    df = pd.read_csv(path, sep=';', encoding='utf-8-sig')
    obrigatorias = ['coletado_em', 'tempo_atual_seg', 'tempo_historico_seg',
                     'atraso_seg', 'jam_level']
    faltando = [c for c in obrigatorias if c not in df.columns]
    if faltando:
        raise ValueError(
            f"O arquivo '{path}' não tem as colunas esperadas: {faltando}. "
            "Confira se o CSV está no formato Waze (separador ';', mesmas colunas "
            "de 'historico_rota_*.csv')."
        )
    df['coletado_em'] = pd.to_datetime(df['coletado_em'])
    df['hora'] = df['coletado_em'].dt.hour
    df['dia_semana_en'] = df['coletado_em'].dt.day_name()
    df['fds'] = df['coletado_em'].dt.dayofweek >= 5
    return df


DIAS_PT = {
    'Monday': 'Segunda-feira', 'Tuesday': 'Terça-feira', 'Wednesday': 'Quarta-feira',
    'Thursday': 'Quinta-feira', 'Friday': 'Sexta-feira', 'Saturday': 'Sábado',
    'Sunday': 'Domingo',
}


def resumo_geral(df1, df2, nome1, nome2):
    linhas = [
        ('Amostras analisadas', len(df1), len(df2), '{:.0f}'),
        ('Extensão do trecho (km)', df1['extensao_km'].iloc[0] if 'extensao_km' in df1 else np.nan,
         df2['extensao_km'].iloc[0] if 'extensao_km' in df2 else np.nan, '{:.3f}'),
        ('Tempo médio atual (s)', df1['tempo_atual_seg'].mean(), df2['tempo_atual_seg'].mean(), '{:.1f}'),
        ('Tempo médio histórico (s)', df1['tempo_historico_seg'].mean(), df2['tempo_historico_seg'].mean(), '{:.1f}'),
        ('Atraso médio (s)', df1['atraso_seg'].mean(), df2['atraso_seg'].mean(), '{:.1f}'),
        ('Tempo máximo observado (s)', df1['tempo_atual_seg'].max(), df2['tempo_atual_seg'].max(), '{:.0f}'),
    ]
    return linhas


def top_eventos(df, n=6):
    cols = ['coletado_em', 'tempo_atual_seg', 'tempo_historico_seg', 'atraso_seg']
    return df.nlargest(n, 'atraso_seg')[cols]


def simular_cenario(g1, g2, hora_pico_ini, hora_pico_fim, razao_pico, razao_fora):
    """
    Modelo simplificado de redistribuição de verde:
    mantém a soma dos dois tempos médios (proxy do "ciclo") praticamente
    constante em cada hora, e redistribui essa soma segundo uma razão-alvo
    (via1 / via2) mais equilibrada que a razão atual.
    """
    soma = g1 + g2
    razao_alvo = pd.Series(razao_fora, index=g1.index, dtype=float)
    horas_pico = [h % 24 for h in range(hora_pico_ini, hora_pico_fim + 1)]
    razao_alvo.loc[razao_alvo.index.isin(horas_pico)] = razao_pico

    novo1 = soma * razao_alvo / (razao_alvo + 1)
    novo2 = soma / (razao_alvo + 1)
    return novo1, novo2, razao_alvo


# ==========================================================================
# 2. GRÁFICOS
# ==========================================================================

COR_1 = '#C0392B'   # via 1 (a mais penalizada, tipicamente a secundária)
COR_2 = '#2874A6'   # via 2 (a mais favorecida, tipicamente a principal)
COR_UTIL = '#B9770E'
COR_FDS = '#7D3C98'
AZUL_ESCURO = colors.HexColor('#1B2A4A')
AZUL = colors.HexColor('#2874A6')
VERMELHO = colors.HexColor('#C0392B')
VERDE = colors.HexColor('#1E8449')
CINZA_TXT = colors.HexColor('#333333')
CINZA_CLARO = colors.HexColor('#F2F3F5')


def grafico_comparativo_hora(g1, g2, nome1, nome2, path):
    fig, ax = plt.subplots(figsize=(9, 4.2), dpi=200)
    ax.plot(g1.index, g1.values, color=COR_1, linewidth=2.2, marker='o', markersize=3, label=nome1)
    ax.plot(g2.index, g2.values, color=COR_2, linewidth=2.2, marker='o', markersize=3, label=nome2)
    ax.set_xlabel('Hora do dia')
    ax.set_ylabel('Tempo médio de espera (segundos)')
    ax.set_title('Tempo médio de travessia por hora', fontsize=12, fontweight='bold')
    ax.set_xticks(range(0, 24, 2))
    ax.grid(alpha=0.25)
    ax.legend(loc='upper left', frameon=False, fontsize=9)
    ax.spines['top'].set_visible(False)
    ax.spines['right'].set_visible(False)
    plt.tight_layout()
    plt.savefig(path)
    plt.close()


def grafico_razao_hora(g1, g2, path):
    razao = (g1 / g2).round(2)
    fig, ax = plt.subplots(figsize=(9, 3.8), dpi=200)
    ax.bar(razao.index, razao.values, color=COR_1, alpha=0.85, width=0.7)
    ax.axhline(1, color='gray', linewidth=1, linestyle='--')
    ax.set_xlabel('Hora do dia')
    ax.set_ylabel('Razão de espera\n(Via 1 ÷ Via 2)')
    ax.set_title('Quantas vezes a espera na Via 1 supera a da Via 2', fontsize=12, fontweight='bold')
    ax.set_xticks(range(0, 24, 2))
    ax.grid(alpha=0.2, axis='y')
    ax.spines['top'].set_visible(False)
    ax.spines['right'].set_visible(False)
    plt.tight_layout()
    plt.savefig(path)
    plt.close()
    return razao


def grafico_util_fds(df, nome, path):
    piv = df.groupby(['hora', 'fds'])['tempo_atual_seg'].mean().unstack()
    piv.columns = ['Dia útil', 'Fim de semana']
    fig, ax = plt.subplots(figsize=(9, 4), dpi=200)
    ax.plot(piv.index, piv['Dia útil'], color=COR_UTIL, linewidth=2.2, marker='o', markersize=3, label='Dia útil')
    ax.plot(piv.index, piv['Fim de semana'], color=COR_FDS, linewidth=2.2, marker='o', markersize=3, label='Fim de semana')
    ax.set_xlabel('Hora do dia')
    ax.set_ylabel('Tempo médio de espera (segundos)')
    ax.set_title(f'{nome} — Dia útil x Fim de semana', fontsize=12, fontweight='bold')
    ax.set_xticks(range(0, 24, 2))
    ax.grid(alpha=0.25)
    ax.legend(loc='upper left', frameon=False, fontsize=9)
    ax.spines['top'].set_visible(False)
    ax.spines['right'].set_visible(False)
    plt.tight_layout()
    plt.savefig(path)
    plt.close()
    return piv


def grafico_simulacao(g1, g2, novo1, novo2, nome1, nome2, path):
    fig, ax = plt.subplots(figsize=(9, 4.6), dpi=200)
    ax.plot(g1.index, g1.values, color=COR_1, linewidth=2.2, marker='o', markersize=3, label=f'{nome1} — atual')
    ax.plot(novo1.index, novo1.values, color=COR_1, linewidth=2.2, linestyle='--', marker='o',
            markersize=3, alpha=0.75, label=f'{nome1} — cenário proposto')
    ax.plot(g2.index, g2.values, color=COR_2, linewidth=2.2, marker='o', markersize=3, label=f'{nome2} — atual')
    ax.plot(novo2.index, novo2.values, color=COR_2, linewidth=2.2, linestyle='--', marker='o',
            markersize=3, alpha=0.75, label=f'{nome2} — cenário proposto')
    ax.set_xlabel('Hora do dia')
    ax.set_ylabel('Tempo médio de espera (segundos)')
    ax.set_title('Simulação: cenário atual x cenário com ciclo reequilibrado', fontsize=11.5, fontweight='bold')
    ax.set_xticks(range(0, 24, 2))
    ax.grid(alpha=0.25)
    ax.legend(loc='upper left', frameon=False, fontsize=8.3)
    ax.spines['top'].set_visible(False)
    ax.spines['right'].set_visible(False)
    plt.tight_layout()
    plt.savefig(path)
    plt.close()


def grafico_razao_simulada(g1, g2, novo1, novo2, path):
    razao_atual = g1 / g2
    razao_proposta = novo1 / novo2
    fig, ax = plt.subplots(figsize=(9, 4), dpi=200)
    width = 0.38
    x = g1.index.values
    ax.bar(x - width / 2, razao_atual, width=width, color=COR_1, alpha=0.85, label='Razão atual')
    ax.bar(x + width / 2, razao_proposta, width=width, color=COR_FDS, alpha=0.85, label='Razão no cenário proposto')
    ax.axhline(1, color='gray', linewidth=1, linestyle=':')
    ax.set_xlabel('Hora do dia')
    ax.set_ylabel('Razão de espera\n(Via 1 ÷ Via 2)')
    ax.set_title('Redução da desproporção entre as vias, por hora do dia', fontsize=11.5, fontweight='bold')
    ax.set_xticks(range(0, 24, 2))
    ax.grid(alpha=0.2, axis='y')
    ax.legend(loc='upper right', frameon=False, fontsize=9)
    ax.spines['top'].set_visible(False)
    ax.spines['right'].set_visible(False)
    plt.tight_layout()
    plt.savefig(path)
    plt.close()


def grafico_mapa(coords1_path, coords2_path, nome1, nome2, path):
    """Desenha um mapa esquemático (sem tiles/internet) a partir de duas
    polilinhas lat/lon, útil para a seção de Localização do PDF."""
    c1 = pd.read_csv(coords1_path, sep=None, engine='python')
    c2 = pd.read_csv(coords2_path, sep=None, engine='python')
    c1.columns = [c.strip().lower() for c in c1.columns]
    c2.columns = [c.strip().lower() for c in c2.columns]

    fig, ax = plt.subplots(figsize=(8, 6), dpi=200)
    ax.plot(c1['lon'], c1['lat'], color=COR_1, linewidth=3, label=nome1, solid_capstyle='round')
    ax.plot(c2['lon'], c2['lat'], color=COR_2, linewidth=3, label=nome2, solid_capstyle='round')
    ax.set_aspect('equal', adjustable='datalim')
    ax.set_xticks([])
    ax.set_yticks([])
    for spine in ax.spines.values():
        spine.set_visible(False)
    ax.legend(loc='upper center', frameon=False, fontsize=9, bbox_to_anchor=(0.5, 1.08), ncol=1)
    ax.set_title('Esquema de localização (não georreferenciado a mapa-base)', fontsize=10)
    plt.tight_layout()
    plt.savefig(path)
    plt.close()


# ==========================================================================
# 3. PDF
# ==========================================================================

def montar_estilos():
    styles = getSampleStyleSheet()
    styles.add(ParagraphStyle(name='TituloCapa', fontName='Helvetica-Bold', fontSize=22, leading=27,
                               textColor=AZUL_ESCURO, alignment=TA_CENTER, spaceAfter=6))
    styles.add(ParagraphStyle(name='SubtituloCapa', fontName='Helvetica', fontSize=13, leading=17,
                               textColor=CINZA_TXT, alignment=TA_CENTER, spaceAfter=4))
    styles.add(ParagraphStyle(name='H1', fontName='Helvetica-Bold', fontSize=14, leading=18,
                               textColor=AZUL_ESCURO, spaceBefore=16, spaceAfter=8))
    styles.add(ParagraphStyle(name='H2', fontName='Helvetica-Bold', fontSize=11.5, leading=15,
                               textColor=AZUL, spaceBefore=10, spaceAfter=6))
    styles.add(ParagraphStyle(name='Corpo', fontName='Helvetica', fontSize=9.7, leading=14.5,
                               textColor=CINZA_TXT, alignment=TA_JUSTIFY, spaceAfter=6))
    styles.add(ParagraphStyle(name='Legenda', fontName='Helvetica-Oblique', fontSize=8.3, leading=11,
                               textColor=colors.HexColor('#666666'), alignment=TA_CENTER, spaceAfter=10))
    styles.add(ParagraphStyle(name='TabelaHeader', fontName='Helvetica-Bold', fontSize=8.7,
                               textColor=colors.white, alignment=TA_CENTER))
    return styles


def tabela_padrao(dados, col_widths, cor_cabecalho=AZUL_ESCURO, align_first_left=True):
    t = Table(dados, colWidths=col_widths)
    estilo = [
        ('BACKGROUND', (0, 0), (-1, 0), cor_cabecalho),
        ('TEXTCOLOR', (0, 0), (-1, 0), colors.white),
        ('FONTSIZE', (0, 0), (-1, -1), 8.7),
        ('ALIGN', (1, 0), (-1, -1), 'CENTER'),
        ('GRID', (0, 0), (-1, -1), 0.5, colors.HexColor('#DDDDDD')),
        ('ROWBACKGROUNDS', (0, 1), (-1, -1), [colors.white, CINZA_CLARO]),
        ('TOPPADDING', (0, 0), (-1, -1), 5),
        ('BOTTOMPADDING', (0, 0), (-1, -1), 5),
    ]
    if align_first_left:
        estilo.append(('ALIGN', (0, 1), (0, -1), 'LEFT'))
    t.setStyle(TableStyle(estilo))
    return t


def gerar_pdf(args, df1, df2, imgs, extras):
    styles = montar_estilos()

    def p(text, style='Corpo'):
        return Paragraph(text, styles[style])

    def header_footer(canvas, doc):
        canvas.saveState()
        canvas.setStrokeColor(AZUL_ESCURO)
        canvas.setLineWidth(1.2)
        canvas.line(2 * cm, A4[1] - 1.5 * cm, A4[0] - 2 * cm, A4[1] - 1.5 * cm)
        canvas.setFont('Helvetica-Bold', 8.5)
        canvas.setFillColor(AZUL_ESCURO)
        canvas.drawString(2 * cm, A4[1] - 1.35 * cm, "PLANO DE ATUAÇÃO SEMAFÓRICA")
        canvas.setFont('Helvetica', 8.5)
        canvas.drawRightString(A4[0] - 2 * cm, A4[1] - 1.35 * cm, f"{args.nome1} x {args.nome2}")
        canvas.setStrokeColor(colors.HexColor('#CCCCCC'))
        canvas.setLineWidth(0.6)
        canvas.line(2 * cm, 1.5 * cm, A4[0] - 2 * cm, 1.5 * cm)
        canvas.setFont('Helvetica', 8)
        canvas.setFillColor(colors.HexColor('#666666'))
        canvas.drawString(2 * cm, 1.15 * cm, args.cidade or "")
        canvas.drawRightString(A4[0] - 2 * cm, 1.15 * cm, f"Página {doc.page}")
        canvas.restoreState()

    def header_footer_capa(canvas, doc):
        canvas.saveState()
        canvas.setFillColor(AZUL_ESCURO)
        canvas.rect(0, A4[1] - 3.2 * cm, A4[0], 3.2 * cm, fill=1, stroke=0)
        canvas.setFillColor(colors.HexColor('#CCCCCC'))
        canvas.setFont('Helvetica', 8)
        canvas.drawString(2 * cm, 1.15 * cm, "Documento técnico elaborado a partir de dados públicos de tráfego (Waze)")
        canvas.restoreState()

    doc = SimpleDocTemplate(args.saida, pagesize=A4, topMargin=2.2 * cm, bottomMargin=2 * cm,
                             leftMargin=2 * cm, rightMargin=2 * cm,
                             title=f"Plano de Atuação Semafórica - {args.nome1} x {args.nome2}")

    periodo_ini = df1['coletado_em'].min().strftime('%d/%m/%Y')
    periodo_fim = df1['coletado_em'].max().strftime('%d/%m/%Y')

    story = []

    # ----- Capa -----
    story.append(Spacer(1, 4.4 * cm))
    story.append(p("PLANO DE ATUAÇÃO SEMAFÓRICA", 'TituloCapa'))
    story.append(p(f"Cruzamento {args.nome2} x {args.nome1}", 'SubtituloCapa'))
    if args.cidade:
        story.append(p(args.cidade, 'SubtituloCapa'))
    story.append(Spacer(1, 1.2 * cm))
    story.append(HRFlowable(width="40%", thickness=1.2, color=AZUL, spaceAfter=14, hAlign='CENTER'))
    story.append(p(f"Período de coleta de dados: {periodo_ini} a {periodo_fim}", 'SubtituloCapa'))
    story.append(p(f"Fonte: dados históricos de tráfego (Waze) — {len(df1)} amostras por via", 'SubtituloCapa'))
    story.append(Spacer(1, 6 * cm))
    story.append(p("Documento técnico para apresentação e avaliação junto à<br/>Prefeitura Municipal / órgão de trânsito local", 'SubtituloCapa'))
    story.append(PageBreak())

    # ----- Sumário executivo -----
    razao_media = (df1['tempo_atual_seg'].mean() / df2['tempo_atual_seg'].mean())
    story.append(p("1. Sumário Executivo", 'H1'))
    story.append(p(
        f"Este documento apresenta um diagnóstico do funcionamento do semáforo no cruzamento entre "
        f"<b>{args.nome2}</b> e <b>{args.nome1}</b>, com base em dados históricos de tráfego coletados "
        f"entre {periodo_ini} e {periodo_fim}.", 'Corpo'))
    story.append(p(
        f"A análise identificou uma <b>assimetria {'relevante' if razao_media > 1.5 else 'moderada'} e "
        f"recorrente</b> no tempo de espera entre as duas vias: em média, veículos em {args.nome1} "
        f"aguardam cerca de <b>{razao_media:.1f} vezes mais tempo</b> no semáforo do que veículos em "
        f"{args.nome2}.", 'Corpo'))
    if args.contexto:
        story.append(p(args.contexto, 'Corpo'))
    story.append(p("Este documento propõe:", 'Corpo'))
    story.append(ListFlowable([
        ListItem(p("Reequilíbrio do tempo de verde do ciclo semafórico atual;", 'Corpo')),
        ListItem(p("Adoção de planos de horário (time-of-day) alinhados aos picos identificados;", 'Corpo')),
        ListItem(p("Avaliação de controle semaforizado atuado/adaptativo a médio prazo;", 'Corpo')),
    ], bulletType='bullet', start='•', leftIndent=14, spaceAfter=8))

    story.append(p("2. Localização e Contexto", 'H1'))
    if imgs.get('mapa'):
        story.append(Image(imgs['mapa'], width=11 * cm, height=8.25 * cm, hAlign='CENTER'))
        story.append(p("Esquema de localização das duas vias analisadas.", 'Legenda'))
    else:
        story.append(p(
            f"O cruzamento avaliado liga {args.nome2} a {args.nome1}. "
            + (args.contexto or "Ver contexto informado no sumário executivo."), 'Corpo'))

    # ----- Metodologia -----
    story.append(p("3. Metodologia", 'H1'))
    story.append(p(
        f"Foram utilizados dados históricos de tempo de percurso, tempo médio histórico e atraso, "
        f"coletados ao longo do período de {periodo_ini} a {periodo_fim}, totalizando "
        f"<b>{len(df1)} amostras</b> para cada um dos dois trechos analisados.", 'Corpo'))

    # ----- Diagnóstico -----
    story.append(p("4. Diagnóstico", 'H1'))
    story.append(p("4.1. Panorama geral comparativo", 'H2'))
    linhas_resumo = resumo_geral(df1, df2, args.nome1, args.nome2)
    tabela_dados = [[p('Indicador', 'TabelaHeader'), p(args.nome1, 'TabelaHeader'), p(args.nome2, 'TabelaHeader')]]
    for nome, v1, v2, fmt in linhas_resumo:
        tabela_dados.append([nome, fmt.format(v1), fmt.format(v2)])
    story.append(tabela_padrao(tabela_dados, [6.5 * cm, 5 * cm, 5.5 * cm]))
    story.append(Spacer(1, 10))

    story.append(p("4.2. Variação ao longo do dia", 'H2'))
    story.append(Image(imgs['comparativo'], width=16 * cm, height=7.47 * cm))
    story.append(p(f"Gráfico 1 – Tempo médio de espera por hora, {args.nome1} x {args.nome2}.", 'Legenda'))

    story.append(Image(imgs['razao'], width=16 * cm, height=6.75 * cm))
    story.append(p("Gráfico 2 – Razão entre o tempo de espera nas duas vias, por hora do dia.", 'Legenda'))

    story.append(PageBreak())
    story.append(p("4.3. Dias úteis x fim de semana", 'H2'))
    story.append(Image(imgs['util_fds'], width=16 * cm, height=7.11 * cm))
    story.append(p(f"Gráfico 3 – {args.nome1}: comparação entre dias úteis e fins de semana.", 'Legenda'))

    story.append(p("4.4. Ranking de dias da semana", 'H2'))
    ranking = df1.groupby('dia_semana_en')['tempo_atual_seg'].mean().sort_values(ascending=False)
    tabela_dias = [[p('Dia da semana', 'TabelaHeader'), p('Tempo médio (s)', 'TabelaHeader')]]
    for dia, val in ranking.items():
        tabela_dias.append([DIAS_PT.get(dia, dia), f'{val:.1f}'])
    story.append(tabela_padrao(tabela_dias, [8 * cm, 6 * cm], align_first_left=True))

    story.append(p("4.5. Eventos pontuais críticos", 'H2'))
    eventos = top_eventos(df1, n=5)
    tabela_ev = [[p('Data/Hora', 'TabelaHeader'), p('Tempo observado', 'TabelaHeader'),
                  p('Tempo de referência', 'TabelaHeader'), p('Atraso', 'TabelaHeader')]]
    for _, row in eventos.iterrows():
        tabela_ev.append([
            row['coletado_em'].strftime('%d/%m/%Y %Hh%M'),
            f"{row['tempo_atual_seg']:.0f} s",
            f"{row['tempo_historico_seg']:.0f} s",
            f"+{row['atraso_seg']:.0f} s",
        ])
    story.append(tabela_padrao(tabela_ev, [4.5 * cm, 3.5 * cm, 3.8 * cm, 3 * cm], cor_cabecalho=VERMELHO))

    story.append(PageBreak())

    # ----- Plano de ação -----
    story.append(p("5. Plano de Atuação Proposto", 'H1'))
    story.append(p("5.1. Curto prazo — reprogramação do ciclo atual (sem obras)", 'H2'))
    story.append(ListFlowable([
        ListItem(p(f"<b>Reequilíbrio do tempo de verde</b>: revisar o split do ciclo, priorizando {args.nome1} nos horários de pico identificados.", 'Corpo')),
        ListItem(p("<b>Planos de horário (time-of-day)</b>: substituir o plano fixo único por múltiplos planos ao longo do dia/semana.", 'Corpo')),
    ], bulletType='bullet', start='•', leftIndent=14, spaceAfter=8))
    story.append(p("5.2. Médio prazo — controle inteligente", 'H2'))
    story.append(ListFlowable([
        ListItem(p("<b>Semáforo atuado por demanda</b>, permitindo abrir o verde de acordo com a presença real de veículos.", 'Corpo')),
        ListItem(p("<b>Monitoramento contínuo</b> com dados de tráfego para acompanhar a efetividade das mudanças.", 'Corpo')),
    ], bulletType='bullet', start='•', leftIndent=14, spaceAfter=8))

    # ----- Simulação -----
    story.append(PageBreak())
    story.append(p("6. Simulação do Cenário Proposto", 'H1'))
    story.append(p(
        f"Estimativa ilustrativa: assume-se que a soma dos tempos médios das duas vias permanece "
        f"aproximadamente constante por horário, redistribuída de uma razão-alvo atual para "
        f"<b>{args.razao_alvo_pico}x</b> no período de pico ({args.hora_pico_ini}h-{args.hora_pico_fim}h) "
        f"e <b>{args.razao_alvo_fora}x</b> nos demais horários.", 'Corpo'))
    story.append(p(
        "<i>Esta é uma estimativa ilustrativa baseada nos dados históricos coletados, e não substitui "
        "uma simulação de tráfego formal. Os valores exatos de tempo de verde devem ser definidos pelo "
        "órgão de trânsito responsável.</i>", 'Legenda'))
    story.append(Image(imgs['simulacao'], width=16 * cm, height=8.18 * cm))
    story.append(p("Gráfico 4 – Cenário atual (linha cheia) x cenário simulado (linha tracejada).", 'Legenda'))
    story.append(Image(imgs['razao_sim'], width=16 * cm, height=7.11 * cm))
    story.append(p("Gráfico 5 – Razão de espera: situação atual x situação simulada.", 'Legenda'))

    reducao_pct = extras['reducao_pct']
    tabela_sim = [
        [p('Indicador (média no pico)', 'TabelaHeader'), p('Atual', 'TabelaHeader'), p('Cenário proposto', 'TabelaHeader')],
        [f'Espera em {args.nome1}', f"{extras['dias_atual_pico']:.1f} s", f"{extras['dias_proposto_pico']:.1f} s  ({reducao_pct:+.1f}%)"],
        [f'Espera em {args.nome2}', f"{extras['aven_atual_pico']:.1f} s", f"{extras['aven_proposto_pico']:.1f} s"],
        ['Razão entre as vias', f"{extras['razao_atual_pico']:.1f}x", f"{extras['razao_proposta_pico']:.1f}x"],
    ]
    story.append(tabela_padrao(tabela_sim, [8.5 * cm, 3.5 * cm, 5 * cm], cor_cabecalho=VERDE))

    # ----- Conclusão -----
    story.append(p("7. Conclusão", 'H1'))
    story.append(p(
        f"Os dados coletados evidenciam um desequilíbrio consistente e mensurável no tempo de espera "
        f"semafórica entre {args.nome2} e {args.nome1}. Trata-se de uma situação corrigível "
        f"principalmente por reprogramação do ciclo semafórico, sem necessidade de obras. Recomenda-se "
        f"que o órgão de trânsito avalie as propostas e considere nova coleta de dados após os ajustes.", 'Corpo'))
    story.append(Spacer(1, 14))
    story.append(p(
        "Documento elaborado a partir de dados públicos de tráfego (Waze). As recomendações têm caráter "
        "técnico preliminar e visam subsidiar a avaliação do órgão competente.", 'Legenda'))

    doc.build(story, onFirstPage=header_footer_capa, onLaterPages=header_footer)


# ==========================================================================
# 4. ORQUESTRAÇÃO / CLI
# ==========================================================================

def main():
    parser = argparse.ArgumentParser(description="Analisa duas rotas (formato Waze) e gera PDF de plano semafórico.")
    parser.add_argument('csv1', help="CSV da via 1 (tipicamente a mais penalizada)")
    parser.add_argument('csv2', help="CSV da via 2 (tipicamente a de maior hierarquia)")
    parser.add_argument('--nome1', default='Via 1')
    parser.add_argument('--nome2', default='Via 2')
    parser.add_argument('--cidade', default='')
    parser.add_argument('--contexto', default='', help="Parágrafo livre de contexto (ex.: polo gerador de viagens)")
    parser.add_argument('--hora-pico-ini', type=int, default=11, dest='hora_pico_ini')
    parser.add_argument('--hora-pico-fim', type=int, default=16, dest='hora_pico_fim')
    parser.add_argument('--razao-alvo-pico', type=float, default=1.8, dest='razao_alvo_pico')
    parser.add_argument('--razao-alvo-fora', type=float, default=2.5, dest='razao_alvo_fora')
    parser.add_argument('--coords1', default=None, help="CSV opcional com lat/lon do trecho da via 1")
    parser.add_argument('--coords2', default=None, help="CSV opcional com lat/lon do trecho da via 2")
    parser.add_argument('--saida', default='plano_semaforico.pdf')
    parser.add_argument('--workdir', default='.', help="Pasta onde salvar os gráficos intermediários (PNG)")
    args = parser.parse_args()

    print(f"Lendo {args.csv1} ...")
    df1 = carregar_csv(args.csv1)
    print(f"Lendo {args.csv2} ...")
    df2 = carregar_csv(args.csv2)

    if len(df1) != len(df2):
        print(f"Aviso: as duas rotas têm números diferentes de amostras "
              f"({len(df1)} x {len(df2)}). A análise segue normalmente.")

    g1 = df1.groupby('hora')['tempo_atual_seg'].mean()
    g2 = df2.groupby('hora')['tempo_atual_seg'].mean()

    wd = args.workdir.rstrip('/')
    imgs = {}
    imgs['comparativo'] = f'{wd}/_g1_comparativo.png'
    imgs['razao'] = f'{wd}/_g2_razao.png'
    imgs['util_fds'] = f'{wd}/_g3_util_fds.png'
    imgs['simulacao'] = f'{wd}/_g4_simulacao.png'
    imgs['razao_sim'] = f'{wd}/_g5_razao_sim.png'
    imgs['mapa'] = None

    print("Gerando gráficos...")
    grafico_comparativo_hora(g1, g2, args.nome1, args.nome2, imgs['comparativo'])
    grafico_razao_hora(g1, g2, imgs['razao'])
    grafico_util_fds(df1, args.nome1, imgs['util_fds'])

    novo1, novo2, _ = simular_cenario(g1, g2, args.hora_pico_ini, args.hora_pico_fim,
                                       args.razao_alvo_pico, args.razao_alvo_fora)
    grafico_simulacao(g1, g2, novo1, novo2, args.nome1, args.nome2, imgs['simulacao'])
    grafico_razao_simulada(g1, g2, novo1, novo2, imgs['razao_sim'])

    if args.coords1 and args.coords2:
        print("Gerando mapa esquemático a partir das coordenadas...")
        imgs['mapa'] = f'{wd}/_g_mapa.png'
        grafico_mapa(args.coords1, args.coords2, args.nome1, args.nome2, imgs['mapa'])

    horas_pico = [h % 24 for h in range(args.hora_pico_ini, args.hora_pico_fim + 1)]
    dias_atual_pico = g1.loc[g1.index.isin(horas_pico)].mean()
    dias_proposto_pico = novo1.loc[novo1.index.isin(horas_pico)].mean()
    aven_atual_pico = g2.loc[g2.index.isin(horas_pico)].mean()
    aven_proposto_pico = novo2.loc[novo2.index.isin(horas_pico)].mean()
    extras = {
        'dias_atual_pico': dias_atual_pico,
        'dias_proposto_pico': dias_proposto_pico,
        'aven_atual_pico': aven_atual_pico,
        'aven_proposto_pico': aven_proposto_pico,
        'razao_atual_pico': dias_atual_pico / aven_atual_pico,
        'razao_proposta_pico': dias_proposto_pico / aven_proposto_pico,
        'reducao_pct': 100 * (dias_proposto_pico - dias_atual_pico) / dias_atual_pico,
    }

    print("Montando PDF...")
    gerar_pdf(args, df1, df2, imgs, extras)
    print(f"Pronto! PDF salvo em: {args.saida}")


if __name__ == '__main__':
    main()
