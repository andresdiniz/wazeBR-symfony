# Refatoracao: Separar Estado Atual de Historico de Rotas

## Problema Atual

A tabela `waze_tvt_routes` armazena **~381k registros** com **1.79GB** de dados, incluindo:
- Dados atuais da rota
- Coordenadas completas
- Historico de todas as atualizacoes

Isso causa:
- Queries lentas com JOIN + ORDER antes de LIMIT
- Uso excessivo de tabelas temporarias
- Dificuldade de manutencao

## Nova Estrutura

### Tabela `waze_tvt_routes` (Estado Atual)

```sql
CREATE TABLE waze_tvt_routes (
    id INT PRIMARY KEY AUTOINCREMENT,
    route_id VARCHAR(50) UNIQUE,  -- ID unico da rota
    partner_id INT,
    name VARCHAR(255),
    from_location VARCHAR(255),
    to_location VARCHAR(255),
    current_jam_level INT,
    current_length_meters INT,
    current_delay_seconds INT,
    current_speed_kmh DECIMAL(5,2),
    last_update_at DATETIME,
    created_at DATETIME,
    updated_at DATETIME,
    
    INDEX idx_routes_partner (partner_id),
    INDEX idx_routes_last_update (last_update_at)
);
```

**Apenas o estado atual de cada rota** - sem coordenadas, sem historico.

### Tabela `waze_tvt_route_history` (Historico)

```sql
CREATE TABLE waze_tvt_route_history (
    id BIGINT PRIMARY KEY AUTOINCREMENT,
    route_id INT,  -- FK para waze_tvt_routes.id
    jam_level INT,
    length_meters INT,
    delay_seconds INT,
    speed_kmh DECIMAL(5,2),
    collected_at DATETIME,
    created_at DATETIME,
    
    INDEX idx_history_route (route_id),
    INDEX idx_history_collected (collected_at)
);
```

**Apenas dados historicos + timestamps** - linkado por `route_id`.

### Tabela `waze_tvt_route_history_coords` (Coordenadas - Opcional)

```sql
CREATE TABLE waze_tvt_route_history_coords (
    id BIGINT PRIMARY KEY AUTOINCREMENT,
    history_id BIGINT,  -- FK para waze_tvt_route_history.id
    latitude DECIMAL(10,8),
    longitude DECIMAL(11,8),
    order_index INT,
    
    INDEX idx_coords_history (history_id)
);
```

**Coordenadas separadas** - so salvar se realmente necessario (ex: visualizacao no mapa).

## Beneficios

1. **Tabela principal leve** - `waze_tvt_routes` com ~100-1000 registros (apenas rotas ativas)
2. **Historico escalavel** - `waze_tvt_route_history` pode crescer sem impactar queries principais
3. **Queries otimizadas** - dashboard busca apenas estado atual, sem JOIN pesado
4. **Limpeza facil** - apagar historico antigo sem afetar dados atuais
5. **Flexibilidade** - pode adicionar/ remover coordenadas sem mudar estrutura principal

## Arquivos a Criar/Modificar

### Criar
- [ ] `src/Entity/WazeTvtRouteHistory.php`
- [ ] `src/Entity/WazeTvtRouteHistoryCoords.php` (opcional)
- [ ] `src/Repository/WazeTvtRouteHistoryRepository.php`
- [ ] `migrations/Version20260822220000.php` (nova estrutura)

### Modificar
- [ ] `src/Entity/WazeTvtRoute.php` (remover coordenadas, adicionar relacionamento com history)
- [ ] `src/Repository/WazeTvtRouteRepository.php` (atualizar queries)
- [ ] `src/Command/WazeCollectTvtCommand.php` (salvar historico separadamente)
- [ ] `templates/` que usam rotas (se necessario)

## Ordem de Execucao

1. Criar novas entidades
2. Criar migration
3. Rodar migration
4. Atualizar Command de coleta
5. Atualizar repositories
6. Atualizar templates
7. Testar dashboard
8. Limpar dados antigos da tabela atual

## Migracao de Dados

```sql
-- 1. Criar tabela temporaria com estado atual (ultimo snapshot por rota)
CREATE TEMPORARY TABLE current_routes AS
SELECT 
    r.route_id,
    r.partner_id,
    MAX(s.collected_at) as last_update,
    -- pegar dados do ultimo snapshot
    ...
FROM waze_tvt_routes r
JOIN waze_tvt_snapshots s ON s.id = r.snapshot_id
GROUP BY r.route_id;

-- 2. Mover historico para nova tabela
INSERT INTO waze_tvt_route_history (route_id, jam_level, length_meters, collected_at, ...)
SELECT r.id, h.jam_level, h.length_meters, h.collected_at, ...
FROM waze_tvt_routes_old h
JOIN waze_tvt_routes r ON r.route_id = h.route_id;

-- 3. Apagar tabela antiga
DROP TABLE waze_tvt_routes_old;
```

## Referencias

- https://github.com/andresdiniz/wazeBR-symfony/issues/XXX
