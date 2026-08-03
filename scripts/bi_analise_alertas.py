#!/usr/bin/env python3
"""
bi_analise_alertas.py
======================
Script de análise BI para o WazePortalBR (wazeBR-symfony).

Gera análises cruzadas de alertas Waze / eventos CIFS por:
    - Parceiro (Partner)
    - Tipo (WazeAlertType / CifsEventType)
    - Subtipo (sub_type, quando existir)
    - Data (série temporal diária/mensal)

Saída: um arquivo Excel (.xlsx) com múltiplas abas (dados agregados) e
gráficos PNG salvos em ./output/, prontos para apresentação ou dashboard.

Requisitos:
    pip install sqlalchemy pymysql pandas python-dotenv matplotlib openpyxl

Uso:
    python bi_analise_alertas.py

O script lê a variável DATABASE_URL diretamente do arquivo .env do projeto
Symfony (formato Doctrine: mysql://user:pass@host:port/dbname?serverVersion=...),
sem que nenhuma credencial fique hardcoded neste arquivo.
"""

from __future__ import annotations

import os
import re
import sys
from datetime import datetime
from pathlib import Path

import pandas as pd
import matplotlib
matplotlib.use("Agg")
import matplotlib.pyplot as plt
from sqlalchemy import create_engine, inspect, MetaData, Table, select, func

try:
    from dotenv import load_dotenv
except ImportError:
    print("Instale python-dotenv: pip install python-dotenv")
    sys.exit(1)

# --------------------------------------------------------------------------
# 1. Configuração / conexão
# --------------------------------------------------------------------------

BASE_DIR = Path(__file__).resolve().parent
OUTPUT_DIR = BASE_DIR / "output"
OUTPUT_DIR.mkdir(exist_ok=True)


def load_database_url() -> str:
    """Carrega a DATABASE_URL do .env (padrão Symfony/Doctrine) e converte
    para o dialeto aceito pelo SQLAlchemy (mysql+pymysql)."""
    env_path = BASE_DIR / ".env"
    if not env_path.exists():
        env_path = BASE_DIR.parent / ".env"
    load_dotenv(dotenv_path=env_path, override=False)

    raw_url = os.getenv("DATABASE_URL")
    if not raw_url:
        raise RuntimeError(
            "DATABASE_URL não encontrada no .env. "
            "Verifique se o arquivo está presente e no formato Doctrine."
        )

    url = re.sub(r"^mysql://", "mysql+pymysql://", raw_url)
    url = re.sub(r"[?&]serverVersion=[^&]*", "", url)
    url = re.sub(r"[?&]charset=[^&]*", "", url)
    return url


engine = create_engine(load_database_url(), pool_pre_ping=True)
inspector = inspect(engine)
metadata = MetaData()

ALL_TABLES = inspector.get_table_names()


def find_table(*candidates: str) -> str | None:
    lower_map = {t.lower(): t for t in ALL_TABLES}
    for c in candidates:
        if c.lower() in lower_map:
            return lower_map[c.lower()]
    return None


def cols(table_name: str) -> list[str]:
    return [c["name"] for c in inspector.get_columns(table_name)]


def find_column(table_name: str, patterns: list[str]) -> str | None:
    for col in cols(table_name):
        for pat in patterns:
            if re.search(pat, col, re.IGNORECASE):
                return col
    return None


# --------------------------------------------------------------------------
# 2. Descoberta automática do schema (reflection)
# --------------------------------------------------------------------------

TABLE_ALERT = find_table("waze_alert", "alert")
TABLE_ALERT_TYPE = find_table("waze_alert_type", "alert_type")
TABLE_PARTNER = find_table("partner")
TABLE_CIFS_EVENT = find_table("cifs_event", "cifs_events")
TABLE_CIFS_EVENT_TYPE = find_table("cifs_event_type", "cifs_event_types")

if not TABLE_ALERT or not TABLE_PARTNER:
    raise RuntimeError(
        f"Não foi possível localizar as tabelas principais no banco. "
        f"Tabelas encontradas: {ALL_TABLES}"
    )

alert_tbl = Table(TABLE_ALERT, metadata, autoload_with=engine)
partner_tbl = Table(TABLE_PARTNER, metadata, autoload_with=engine)
alert_type_tbl = Table(TABLE_ALERT_TYPE, metadata, autoload_with=engine) if TABLE_ALERT_TYPE else None
cifs_tbl = Table(TABLE_CIFS_EVENT, metadata, autoload_with=engine) if TABLE_CIFS_EVENT else None
cifs_type_tbl = Table(TABLE_CIFS_EVENT_TYPE, metadata, autoload_with=engine) if TABLE_CIFS_EVENT_TYPE else None

COL_ALERT_DATE = find_column(TABLE_ALERT, [r"^pub_?millis$", r"date", r"created_at", r"_at$"])
COL_ALERT_TYPE = find_column(TABLE_ALERT, [r"^type$", r"alert_type", r"type_id"])
COL_ALERT_SUBTYPE = find_column(TABLE_ALERT, [r"sub_?type"])
COL_ALERT_PARTNER_FK = find_column(TABLE_ALERT, [r"partner_id", r"partner"])
COL_ALERT_UUID = find_column(TABLE_ALERT, [r"uuid", r"^id$"])

COL_PARTNER_NAME = find_column(TABLE_PARTNER, [r"^name$", r"nome", r"label", r"slug"])
COL_PARTNER_ID = find_column(TABLE_PARTNER, [r"^id$"])

print("== Schema detectado ==")
print(f"Tabela de alertas       : {TABLE_ALERT}")
print(f"  coluna de data        : {COL_ALERT_DATE}")
print(f"  coluna de tipo        : {COL_ALERT_TYPE}")
print(f"  coluna de subtipo     : {COL_ALERT_SUBTYPE}")
print(f"  FK de parceiro        : {COL_ALERT_PARTNER_FK}")
print(f"Tabela de parceiros     : {TABLE_PARTNER}")
print(f"  coluna de nome        : {COL_PARTNER_NAME}")
print(f"Tabela tipo de alerta   : {TABLE_ALERT_TYPE}")
print(f"Tabela CIFS event       : {TABLE_CIFS_EVENT}")
print(f"Tabela CIFS event type  : {TABLE_CIFS_EVENT_TYPE}")

if not all([COL_ALERT_DATE, COL_ALERT_TYPE, COL_ALERT_PARTNER_FK, COL_PARTNER_NAME, COL_PARTNER_ID]):
    print(
        "\n[AVISO] Uma ou mais colunas-chave não foram detectadas automaticamente.\n"
        "Ajuste manualmente as constantes COL_* no topo do script conforme o "
        "schema real (verifique com `php bin/console doctrine:mapping:info` "
        "ou olhando diretamente as entidades em src/Entity/)."
    )

# --------------------------------------------------------------------------
# 3. Extração dos dados (SQL bruto para performance, com pandas.read_sql)
# --------------------------------------------------------------------------

def build_alert_query() -> str:
    date_expr = COL_ALERT_DATE
    if COL_ALERT_DATE and "millis" in COL_ALERT_DATE.lower():
        date_expr = f"FROM_UNIXTIME(a.{COL_ALERT_DATE}/1000)"
    else:
        date_expr = f"a.{COL_ALERT_DATE}"

    subtype_select = f"a.{COL_ALERT_SUBTYPE} AS subtype" if COL_ALERT_SUBTYPE else "NULL AS subtype"
    type_join = ""
    type_select = f"a.{COL_ALERT_TYPE} AS type_raw"

    if alert_type_tbl is not None and COL_ALERT_TYPE and COL_ALERT_TYPE.lower().endswith("_id"):
        at_name_col = find_column(TABLE_ALERT_TYPE, [r"^name$", r"label", r"description"])
        at_id_col = find_column(TABLE_ALERT_TYPE, [r"^id$"])
        if at_name_col and at_id_col:
            type_join = f"LEFT JOIN {TABLE_ALERT_TYPE} t ON t.{at_id_col} = a.{COL_ALERT_TYPE}"
            type_select = f"COALESCE(t.{at_name_col}, a.{COL_ALERT_TYPE}) AS type_raw"

    query = f"""
        SELECT
            p.{COL_PARTNER_NAME} AS partner_name,
            {type_select},
            {subtype_select},
            {date_expr} AS event_date
        FROM {TABLE_ALERT} a
        LEFT JOIN {TABLE_PARTNER} p ON p.{COL_PARTNER_ID} = a.{COL_ALERT_PARTNER_FK}
        {type_join}
    """
    return query


query = build_alert_query()
print("\n== Query gerada ==")
print(query)

df = pd.read_sql(query, engine)

if df.empty:
    print("\n[AVISO] A consulta não retornou nenhum registro. Encerrando.")
    sys.exit(0)

df["event_date"] = pd.to_datetime(df["event_date"], errors="coerce")
df["partner_name"] = df["partner_name"].fillna("Sem parceiro")
df["type_raw"] = df["type_raw"].fillna("Não informado")
df["subtype"] = df["subtype"].fillna("Não informado")
df["date_only"] = df["event_date"].dt.date
df["month"] = df["event_date"].dt.to_period("M").astype(str)

print(f"\nTotal de registros carregados: {len(df):,}")

# --------------------------------------------------------------------------
# 4. Agregações
# --------------------------------------------------------------------------

agg_partner = (
    df.groupby("partner_name")
    .size()
    .reset_index(name="total_alertas")
    .sort_values("total_alertas", ascending=False)
)

agg_partner_type = (
    df.groupby(["partner_name", "type_raw"])
    .size()
    .reset_index(name="total")
    .sort_values(["partner_name", "total"], ascending=[True, False])
)

agg_type_subtype = (
    df.groupby(["type_raw", "subtype"])
    .size()
    .reset_index(name="total")
    .sort_values("total", ascending=False)
)

agg_partner_type_subtype_date = (
    df.groupby(["partner_name", "type_raw", "subtype", "month"])
    .size()
    .reset_index(name="total")
    .sort_values(["partner_name", "month"])
)

agg_daily = (
    df.groupby("date_only")
    .size()
    .reset_index(name="total_alertas")
    .sort_values("date_only")
)

agg_partner_month = (
    df.groupby(["partner_name", "month"])
    .size()
    .reset_index(name="total")
    .pivot(index="month", columns="partner_name", values="total")
    .fillna(0)
)

pivot_type_by_partner = pd.pivot_table(
    df, index="partner_name", columns="type_raw", values="event_date",
    aggfunc="count", fill_value=0
)

# --------------------------------------------------------------------------
# 5. Exportação para Excel
# --------------------------------------------------------------------------

xlsx_path = OUTPUT_DIR / f"analise_waze_alertas_{datetime.now():%Y%m%d_%H%M}.xlsx"

with pd.ExcelWriter(xlsx_path, engine="openpyxl") as writer:
    df.head(5000).to_excel(writer, sheet_name="dados_brutos_amostra", index=False)
    agg_partner.to_excel(writer, sheet_name="total_por_parceiro", index=False)
    agg_partner_type.to_excel(writer, sheet_name="parceiro_x_tipo", index=False)
    agg_type_subtype.to_excel(writer, sheet_name="tipo_x_subtipo", index=False)
    agg_partner_type_subtype_date.to_excel(writer, sheet_name="parc_tipo_sub_mes", index=False)
    agg_daily.to_excel(writer, sheet_name="serie_diaria", index=False)
    pivot_type_by_partner.to_excel(writer, sheet_name="pivot_tipo_por_parceiro")
    agg_partner_month.to_excel(writer, sheet_name="pivot_parceiro_por_mes")

print(f"\nExcel gerado em: {xlsx_path}")

# --------------------------------------------------------------------------
# 6. Gráficos (PNG)
# --------------------------------------------------------------------------

plt.style.use("seaborn-v0_8-darkgrid" if "seaborn-v0_8-darkgrid" in plt.style.available else "ggplot")

def save_chart(fig, name: str):
    path = OUTPUT_DIR / name
    fig.savefig(path, dpi=150, bbox_inches="tight")
    plt.close(fig)
    print(f"Gráfico salvo: {path}")


top_n = agg_partner.head(15)
fig, ax = plt.subplots(figsize=(10, 6))
ax.barh(top_n["partner_name"], top_n["total_alertas"], color="#1f77b4")
ax.invert_yaxis()
ax.set_xlabel("Total de alertas")
ax.set_title("Top 15 parceiros por volume de alertas")
save_chart(fig, "01_ranking_parceiros.png")

top_types = df["type_raw"].value_counts().head(15)
fig, ax = plt.subplots(figsize=(10, 6))
ax.bar(top_types.index.astype(str), top_types.values, color="#ff7f0e")
ax.set_ylabel("Total de alertas")
ax.set_title("Distribuição por tipo de alerta (Top 15)")
plt.xticks(rotation=45, ha="right")
save_chart(fig, "02_distribuicao_tipo.png")

top_subtypes = df["subtype"].value_counts().head(15)
fig, ax = plt.subplots(figsize=(10, 6))
ax.bar(top_subtypes.index.astype(str), top_subtypes.values, color="#2ca02c")
ax.set_ylabel("Total de alertas")
ax.set_title("Distribuição por subtipo (Top 15)")
plt.xticks(rotation=45, ha="right")
save_chart(fig, "03_distribuicao_subtipo.png")

fig, ax = plt.subplots(figsize=(12, 5))
ax.plot(agg_daily["date_only"], agg_daily["total_alertas"], color="#d62728")
ax.set_title("Série temporal diária de alertas")
ax.set_xlabel("Data")
ax.set_ylabel("Total de alertas")
fig.autofmt_xdate()
save_chart(fig, "04_serie_temporal_diaria.png")

top_partners_list = agg_partner.head(10)["partner_name"]
top_types_list = df["type_raw"].value_counts().head(10).index
heat_data = pivot_type_by_partner.loc[
    pivot_type_by_partner.index.intersection(top_partners_list),
    pivot_type_by_partner.columns.intersection(top_types_list),
]
fig, ax = plt.subplots(figsize=(10, 6))
im = ax.imshow(heat_data.values, cmap="YlOrRd", aspect="auto")
ax.set_xticks(range(len(heat_data.columns)))
ax.set_xticklabels(heat_data.columns, rotation=45, ha="right")
ax.set_yticks(range(len(heat_data.index)))
ax.set_yticklabels(heat_data.index)
ax.set_title("Heatmap: Parceiro x Tipo de alerta")
fig.colorbar(im, ax=ax, label="Total de alertas")
save_chart(fig, "05_heatmap_parceiro_tipo.png")

print("\nAnálise concluída com sucesso.")
