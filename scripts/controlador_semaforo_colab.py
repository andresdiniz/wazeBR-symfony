# ============================================================
# CONTROLADOR ADAPTATIVO DE SEMAFORO - DUAS VIAS
# Google Colab | Contagem regressiva e auditoria de decisoes
# ============================================================

import io
import time
import glob
import numpy as np
import pandas as pd
import matplotlib.pyplot as plt
from matplotlib.patches import Rectangle, Circle, FancyBboxPatch
from IPython.display import display

# -------------------------
# 1) Upload dos CSVs
# -------------------------
try:
    from google.colab import files
    uploaded = files.upload()
except ImportError:
    uploaded = {}
    print('Ambiente fora do Colab: coloque os CSVs na mesma pasta.')


def encontrar_csvs():
    """Encontra os dois CSVs enviados no Colab ou presentes na pasta."""
    arquivos = []

    for nome, conteudo in uploaded.items():
        arquivos.append((nome, io.BytesIO(conteudo)))

    if not arquivos:
        arquivos = [(arquivo, arquivo) for arquivo in glob.glob('*.csv')]

    if len(arquivos) < 2:
        raise FileNotFoundError('Faca upload de pelo menos 2 arquivos CSV.')

    return arquivos[:2]


def ler_csv(caminho):
    """Le CSV separado por ponto e virgula e normaliza os campos usados."""
    df = None
    for encoding in ['utf-8-sig', 'latin-1', 'cp1252']:
        try:
            if isinstance(caminho, io.BytesIO):
                caminho.seek(0)
            df = pd.read_csv(caminho, sep=';', encoding=encoding, engine='python')
            if len(df.columns) > 1:
                break
        except Exception:
            df = None

    if df is None or len(df.columns) <= 1:
        raise ValueError('Nao foi possivel ler o CSV com separador ponto e virgula.')

    df.columns = df.columns.astype(str).str.replace('\ufeff', '', regex=False).str.strip()

    if 'coletado_em' not in df.columns:
        raise ValueError('O CSV precisa ter a coluna coletado_em.')

    df['coletado_em'] = pd.to_datetime(
        df['coletado_em'], format='%Y-%m-%d %H:%M:%S', errors='coerce'
    )

    if 'dia_semana' not in df.columns:
        traducao = {
            'Monday': 'Segunda', 'Tuesday': 'Terca', 'Wednesday': 'Quarta',
            'Thursday': 'Quinta', 'Friday': 'Sexta', 'Saturday': 'Sabado',
            'Sunday': 'Domingo'
        }
        df['dia_semana'] = df['coletado_em'].dt.day_name().map(traducao)

    if 'nome' not in df.columns:
        df['nome'] = 'Via sem nome'

    colunas_numericas = [
        'jam_level', 'tempo_atual_seg', 'tempo_historico_seg',
        'atraso_seg', 'extensao_km'
    ]
    for coluna in colunas_numericas:
        if coluna not in df.columns:
            df[coluna] = 0.0
        df[coluna] = pd.to_numeric(df[coluna], errors='coerce').fillna(0.0)

    return (
        df.dropna(subset=['coletado_em'])
        .sort_values('coletado_em')
        .reset_index(drop=True)
    )


def nome_curto(nome, limite=26):
    nome = str(nome).replace('Cruzamento ', '').strip()
    return nome if len(nome) <= limite else nome[:limite - 3] + '...'


# -------------------------
# 2) Carregar as duas vias
# -------------------------
arquivos = encontrar_csvs()
print(f'Arquivos encontrados: {len(arquivos)}')

dfs_orig = []
nomes_vias = []
for indice, (nome_arquivo, caminho) in enumerate(arquivos):
    df_temp = ler_csv(caminho)
    nome_via = nome_curto(df_temp['nome'].iloc[0])
    dfs_orig.append(df_temp)
    nomes_vias.append(nome_via)
    print(f'Via {indice}: {nome_via} ({len(df_temp):,} registros) - arquivo: {nome_arquivo}')

NOME_VIA0, NOME_VIA1 = nomes_vias

# -------------------------
# 3) Agregacao por minuto
# -------------------------
dfs = []
for indice, df_temp in enumerate(dfs_orig):
    df_temp = df_temp.copy()
    df_temp['instante'] = df_temp['coletado_em'].dt.floor('min')

    agregado = (
        df_temp.groupby('instante', as_index=False)
        .agg(
            jam_level=('jam_level', 'mean'),
            tempo_atual_seg=('tempo_atual_seg', 'mean'),
            tempo_historico_seg=('tempo_historico_seg', 'mean'),
            atraso_seg=('atraso_seg', 'mean'),
            extensao_km=('extensao_km', 'mean'),
            registros=('nome', 'size'),
            dia_semana=('dia_semana', 'first'),
            coletado_em=('coletado_em', 'first')
        )
        .sort_values('instante')
        .reset_index(drop=True)
    )
    dfs.append(agregado)
    print(f'Via {indice} agregada: {len(agregado):,} instantes')

# -------------------------
# 4) Perfil horario para continuidade
# -------------------------
perfis = []
for indice, df_temp in enumerate(dfs_orig):
    temp = df_temp.copy()
    temp['hora'] = temp['coletado_em'].dt.hour

    perfil = (
        temp.groupby('hora')
        .agg(
            jam_level=('jam_level', 'mean'),
            tempo_atual_seg=('tempo_atual_seg', 'mean'),
            tempo_historico_seg=('tempo_historico_seg', 'mean'),
            atraso_seg=('atraso_seg', 'mean'),
            extensao_km=('extensao_km', 'mean')
        )
        .reindex(range(24))
        .interpolate(limit_direction='both')
        .fillna(0)
        .reset_index(names='hora')
    )
    perfis.append(perfil)

# -------------------------
# 5) Controlador auditavel
# -------------------------
class ControladorDuasVias:
    """Controla duas vias mutuamente exclusivas com tempos adaptativos seguros."""

    def __init__(self):
        self.estado_via0 = 'VERDE'
        self.estado_via1 = 'VERMELHO'
        self.tempo_estado = 0.0
        self.ciclo = 0

        # Verde minimo permite partida e passagem de varios veiculos.
        self.limites = {
            'VERDE': (20.0, 60.0),
            'AMARELO': (4.0, 6.0),
            'VERMELHO': (15.0, 60.0),
        }
        self.duracao_fase = 20.0

        self.demanda_suave_via0 = 0.0
        self.demanda_suave_via1 = 0.0
        self.evento = 'Inicializacao: Via 0 abriu com verde de 20 segundos.'
        self.evento_cor = '#8790a3'

    @staticmethod
    def demanda(linha):
        jam = np.clip(float(linha.jam_level), 0, 10) / 10.0
        atraso = max(float(linha.atraso_seg), 0.0)
        tempo_atual = max(float(linha.tempo_atual_seg), 0.0)
        tempo_historico = max(float(linha.tempo_historico_seg), 1.0)
        extensao = max(float(linha.extensao_km), 0.0)

        atraso_relativo = np.clip(atraso / tempo_historico, 0, 4)
        tempo_relativo = np.clip(tempo_atual / tempo_historico, 0, 5)
        extensao_fator = np.clip(extensao / 1.0, 0, 2)

        valor = (
            0.35 * jam
            + 0.30 * np.clip(atraso_relativo / 2, 0, 1)
            + 0.20 * np.clip((tempo_relativo - 1) / 2, 0, 1)
            + 0.15 * np.clip(extensao_fator / 2, 0, 1)
        )
        return float(np.clip(valor, 0, 1))

    def _atualizar_demanda(self, linha0, linha1):
        d0 = self.demanda(linha0)
        d1 = self.demanda(linha1)
        self.demanda_suave_via0 = 0.75 * self.demanda_suave_via0 + 0.25 * d0
        self.demanda_suave_via1 = 0.75 * self.demanda_suave_via1 + 0.25 * d1
        return d0, d1

    def _definir_verde(self, via, linha):
        demanda = self.demanda_suave_via0 if via == 0 else self.demanda_suave_via1
        nome = NOME_VIA0 if via == 0 else NOME_VIA1
        minimo, maximo = self.limites['VERDE']
        anterior = self.duracao_fase
        novo = float(np.clip(minimo + (maximo - minimo) * demanda, minimo, maximo))
        self.duracao_fase = novo

        diferenca = novo - anterior
        if abs(diferenca) >= 0.5:
            acao = 'aumentou' if diferenca > 0 else 'reduziu'
            self.evento = (
                f'{nome} {acao} {abs(diferenca):.1f}s de verde: '
                f'{anterior:.1f}s para {novo:.1f}s. Motivo: atraso {linha.atraso_seg:.1f}s, '
                f'tempo atual {linha.tempo_atual_seg:.1f}s, jam {linha.jam_level:.1f} '
                f'e demanda {demanda * 100:.0f}%.'
            )
            self.evento_cor = '#34c759' if diferenca > 0 else '#ffcc00'
        else:
            self.evento = (
                f'{nome} manteve verde em {novo:.1f}s: atraso {linha.atraso_seg:.1f}s, '
                f'tempo atual {linha.tempo_atual_seg:.1f}s e demanda {demanda * 100:.0f}%.'
            )
            self.evento_cor = '#8790a3'

    def atualizar(self, linha0, linha1, passo=1.0):
        demanda0, demanda1 = self._atualizar_demanda(linha0, linha1)
        self.tempo_estado += passo

        fase_ativa_via0 = self.estado_via0 in ('VERDE', 'AMARELO')
        mudou = False

        if self.tempo_estado >= self.duracao_fase:
            mudou = True

            if self.estado_via0 == 'VERDE':
                self.estado_via0 = 'AMARELO'
                self.estado_via1 = 'VERMELHO'
                self.tempo_estado = 0.0
                self.duracao_fase = self.limites['AMARELO'][0]
                self.evento = (
                    f'{NOME_VIA0}: verde terminou. Amarelo iniciado por '
                    f'{self.duracao_fase:.0f}s para seguranca da transicao.'
                )
                self.evento_cor = '#ffcc00'

            elif self.estado_via0 == 'AMARELO':
                self.estado_via0 = 'VERMELHO'
                self.estado_via1 = 'VERDE'
                self.tempo_estado = 0.0
                self.ciclo += 1
                self._definir_verde(1, linha1)

            elif self.estado_via1 == 'VERDE':
                self.estado_via1 = 'AMARELO'
                self.estado_via0 = 'VERMELHO'
                self.tempo_estado = 0.0
                self.duracao_fase = self.limites['AMARELO'][0]
                self.evento = (
                    f'{NOME_VIA1}: verde terminou. Amarelo iniciado por '
                    f'{self.duracao_fase:.0f}s para seguranca da transicao.'
                )
                self.evento_cor = '#ffcc00'

            else:  # Via 1 estava amarela
                self.estado_via1 = 'VERMELHO'
                self.estado_via0 = 'VERDE'
                self.tempo_estado = 0.0
                self.ciclo += 1
                self._definir_verde(0, linha0)

        restante = max(self.duracao_fase - self.tempo_estado, 0.0)

        return {
            'estado_via0': self.estado_via0,
            'estado_via1': self.estado_via1,
            'demanda_via0': self.demanda_suave_via0,
            'demanda_via1': self.demanda_suave_via1,
            'demanda_inst_via0': demanda0,
            'demanda_inst_via1': demanda1,
            'restante_via0': restante,
            'restante_via1': restante,
            'duracao_fase': self.duracao_fase,
            'ciclo': self.ciclo,
            'mudou_fase': mudou,
            'evento': self.evento,
            'evento_cor': self.evento_cor,
        }


# -------------------------
# 6) Componentes visuais
# -------------------------
CORES = {'VERMELHO': '#ff3b30', 'AMARELO': '#ffcc00', 'VERDE': '#34c759'}
APAGADAS = {'VERMELHO': '#35151a', 'AMARELO': '#39300a', 'VERDE': '#10351d'}


def desenhar_semaforo(ax, x, y, estado):
    caixa = FancyBboxPatch(
        (x - 0.08, y - 0.15), 0.16, 0.32,
        boxstyle='round,pad=0.01,rounding_size=0.02',
        facecolor='#202532', edgecolor='#5b6475', linewidth=2
    )
    ax.add_patch(caixa)

    for indice, cor_nome in enumerate(['VERMELHO', 'AMARELO', 'VERDE']):
        yy = y + 0.12 - indice * 0.12
        ativa = cor_nome == estado
        cor = CORES[cor_nome] if ativa else APAGADAS[cor_nome]
        if ativa:
            ax.add_patch(Circle((x, yy), 0.052, color=cor, alpha=0.20, zorder=1))
        ax.add_patch(Circle((x, yy), 0.035, facecolor=cor, edgecolor='#aeb5c2', linewidth=1.2, zorder=2))

    return CORES[estado]


def desenhar_contagem(ax, x, y, restante, estado):
    cor = CORES[estado]
    caixa = FancyBboxPatch(
        (x - 0.065, y - 0.030), 0.13, 0.06,
        boxstyle='round,pad=0.007,rounding_size=0.01',
        facecolor='#0a0a15', edgecolor=cor, linewidth=2
    )
    ax.add_patch(caixa)
    valor = int(np.ceil(restante))
    ax.text(x, y, f'{valor:02d}', ha='center', va='center', color=cor,
            fontsize=16, fontfamily='monospace', fontweight='bold')


def desenhar_metrica(ax, x, y, titulo, linha, demanda, cor):
    ax.text(x, y, titulo, ha='center', va='center', color=cor, fontsize=8.5, fontweight='bold')
    ax.text(x, y - 0.030, f'Jam {linha.jam_level:.1f} | Tempo {linha.tempo_atual_seg:.0f}s',
            ha='center', va='center', color='#a2aaba', fontsize=7.2)
    ax.text(x, y - 0.057, f'Atraso {linha.atraso_seg:.0f}s | Demanda {demanda * 100:.0f}%',
            ha='center', va='center', color='#a2aaba', fontsize=7.2)


def desenhar_painel(ax, linha0, linha1, status, indice, total, minutos, modo, data_hora, dia_semana):
    ax.clear()
    ax.set_facecolor('#0f111a')
    ax.set_xlim(0, 1)
    ax.set_ylim(0, 1)
    ax.axis('off')

    ax.text(0.5, 0.970, 'CONTROLADOR ADAPTATIVO - DUAS VIAS',
            ha='center', va='center', color='white', fontsize=15, fontweight='bold')
    ax.text(0.5, 0.938, f'{data_hora} | {dia_semana}',
            ha='center', va='center', color='#a2aaba', fontsize=10)
    ax.text(0.5, 0.908, f'Tempo simulado: {minutos:.0f} min | Ciclo: {status["ciclo"]} | {modo}',
            ha='center', va='center', color='#a2aaba', fontsize=8.5)

    cor0 = desenhar_semaforo(ax, 0.35, 0.670, status['estado_via0'])
    cor1 = desenhar_semaforo(ax, 0.65, 0.670, status['estado_via1'])

    desenhar_contagem(ax, 0.35, 0.435, status['restante_via0'], status['estado_via0'])
    desenhar_contagem(ax, 0.65, 0.435, status['restante_via1'], status['estado_via1'])
    ax.text(0.35, 0.398, 'SEGUNDOS PARA A PROXIMA FASE', ha='center', color=cor0, fontsize=6.5)
    ax.text(0.65, 0.398, 'SEGUNDOS PARA A PROXIMA FASE', ha='center', color=cor1, fontsize=6.5)

    ax.text(0.35, 0.355, NOME_VIA0, ha='center', va='center', color='#4488ff', fontsize=8.7, fontweight='bold')
    ax.text(0.35, 0.327, status['estado_via0'], ha='center', va='center', color=cor0, fontsize=12, fontweight='bold')
    ax.text(0.65, 0.355, NOME_VIA1, ha='center', va='center', color='#ff8844', fontsize=8.7, fontweight='bold')
    ax.text(0.65, 0.327, status['estado_via1'], ha='center', va='center', color=cor1, fontsize=12, fontweight='bold')

    # Auditoria: explica a ultima mudanca/duracao adotada.
    evento_caixa = FancyBboxPatch(
        (0.08, 0.210), 0.84, 0.085,
        boxstyle='round,pad=0.010,rounding_size=0.012',
        facecolor='#181c27', edgecolor=status['evento_cor'], linewidth=1.3
    )
    ax.add_patch(evento_caixa)
    ax.text(0.5, 0.274, 'ULTIMA DECISAO DO CONTROLADOR',
            ha='center', va='center', color=status['evento_cor'], fontsize=8.2, fontweight='bold')
    ax.text(0.5, 0.243, status['evento'],
            ha='center', va='center', color='#e1e5ec', fontsize=7.4, wrap=True)

    desenhar_metrica(ax, 0.18, 0.160, NOME_VIA0[:20], linha0, status['demanda_via0'], '#4488ff')
    desenhar_metrica(ax, 0.82, 0.160, NOME_VIA1[:20], linha1, status['demanda_via1'], '#ff8844')

    ax.add_patch(Rectangle((0.27, 0.050), 0.18, 0.014, facecolor='#252b38'))
    ax.add_patch(Rectangle((0.27, 0.050), 0.18 * status['demanda_via0'], 0.014, facecolor=cor0))
    ax.add_patch(Rectangle((0.55, 0.050), 0.18, 0.014, facecolor='#252b38'))
    ax.add_patch(Rectangle((0.55, 0.050), 0.18 * status['demanda_via1'], 0.014, facecolor=cor1))
    ax.text(0.36, 0.072, 'Demanda Via 0', ha='center', color='#a2aaba', fontsize=7)
    ax.text(0.64, 0.072, 'Demanda Via 1', ha='center', color='#a2aaba', fontsize=7)


# -------------------------
# 7) Simulacao infinita
# -------------------------
controlador = ControladorDuasVias()
fig, ax = plt.subplots(figsize=(14, 8), facecolor='#0f111a')
fig.patch.set_facecolor('#0f111a')
handle = display(fig, display_id=True)

min_tempo = min(dfs[0]['instante'].min(), dfs[1]['instante'].min())
max_indice = min(len(dfs[0]), len(dfs[1]))
print('\nConfiguracao: verde adaptativo de 20-60s | amarelo de 4s | simulacao infinita.')
print('O painel explica cada aumento, reducao e troca de prioridade. Use Ctrl+C para parar.\n')

indice = 0
tempo_simulado_min = 0.0
traducoes = {
    'Monday': 'Segunda', 'Tuesday': 'Terca', 'Wednesday': 'Quarta',
    'Thursday': 'Quinta', 'Friday': 'Sexta', 'Saturday': 'Sabado',
    'Sunday': 'Domingo'
}

try:
    while True:
        if indice < max_indice:
            linha0 = dfs[0].iloc[indice]
            linha1 = dfs[1].iloc[indice]
            data_obj = linha0.coletado_em
            data_hora = data_obj.strftime('%d/%m/%Y %H:%M')
            dia_semana = linha0.dia_semana
            modo = f'Dados reais ({indice + 1}/{max_indice})'
        else:
            data_obj = min_tempo + pd.Timedelta(minutes=tempo_simulado_min)
            hora = data_obj.hour
            p0 = perfis[0].iloc[hora]
            p1 = perfis[1].iloc[hora]
            linha0 = pd.Series(p0.to_dict())
            linha1 = pd.Series(p1.to_dict())
            data_hora = data_obj.strftime('%d/%m/%Y %H:%M')
            dia_semana = traducoes[data_obj.day_name()]
            modo = f'Perfil historico (hora {hora:02d})'

        status = controlador.atualizar(linha0, linha1, passo=1.0)
        desenhar_painel(
            ax, linha0, linha1, status, indice, max_indice,
            tempo_simulado_min, modo, data_hora, dia_semana
        )
        handle.update(fig)

        indice += 1
        tempo_simulado_min += 1.0 / 60.0
        time.sleep(0.10)

except KeyboardInterrupt:
    print('\nSimulacao interrompida pelo usuario.')
    print(f'Tempo simulado: {tempo_simulado_min:.0f} min | Ciclos: {controlador.ciclo}')
finally:
    plt.close(fig)
