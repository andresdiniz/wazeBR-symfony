# Migraçª£o das Rotas TVT (Definition/Execution/Coord)

## VisŒo geral

Esta migraçª£o reestrutura o armazenamento das rotas TVT para:

- **`waze_tvt_route_definition`**: dados bÆ¡sicos e estÆ¡ticos da rota (route_id, name, bbox, line)
- **`waze_tvt_route_execution`**: histÆ³rico de execuçııes (timestamp, duration, length, irregularities, etc.)
- **`waze_tvt_route_execution_coord`**: coordenadas detalhadas por execuçª£o

## Passo a passo

### 1. Gerar as tabelas novas

No seu ambiente de produçª£o/develop, rode:

```bash
php bin/console doctrine:migrations:migrate
```

Se vocÍª ainda nŒo gerou a migration, crie um arquivo de migration com o seguinte SQL (ajuste o timestamp/nome conforme seu padrŒo):

```sql
-- Exemplo de SQL para criar as tabelas novas
CREATE TABLE waze_tvt_route_definition (
    id INT AUTO_INCREMENT NOT NULL,
    route_id VARCHAR(255) NOT NULL,
    name VARCHAR(255) DEFAULT NULL,
    bbox TEXT DEFAULT NULL,
    line TEXT DEFAULT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE INDEX UNIQ_route_id (route_id),
    PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;

CREATE TABLE waze_tvt_route_execution (
    id INT AUTO_INCREMENT NOT NULL,
    route_definition_id INT NOT NULL,
    timestamp DATETIME DEFAULT NULL,
    duration INT DEFAULT NULL,
    length INT DEFAULT NULL,
    irregularities INT DEFAULT 0 NOT NULL,
    traffic_jams INT DEFAULT 0 NOT NULL,
    avg_speed FLOAT DEFAULT NULL,
    coords TEXT DEFAULT NULL,
    created_at DATETIME NOT NULL,
    INDEX IDX_route_definition (route_definition_id),
    PRIMARY KEY(id),
    FOREIGN KEY (route_definition_id) REFERENCES waze_tvt_route_definition(id) ON DELETE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;

CREATE TABLE waze_tvt_route_execution_coord (
    id INT AUTO_INCREMENT NOT NULL,
    execution_id INT NOT NULL,
    position INT NOT NULL,
    lat FLOAT NOT NULL,
    lng FLOAT NOT NULL,
    speed FLOAT DEFAULT NULL,
    level INT DEFAULT NULL,
    INDEX IDX_execution (execution_id),
    PRIMARY KEY(id),
    FOREIGN KEY (execution_id) REFERENCES waze_tvt_route_execution(id) ON DELETE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;
```

### 2. Migrar os dados existentes

Rode o command que foi criado:

```bash
php bin/console migrate:tvt-routes
```

Ele vai:

1. Criar `WazeTvtRouteDefinition` œnicos a partir de `waze_tvt_routes` (distinct por `route_id`)
2. Criar `WazeTvtRouteExecution` para cada linha de `waze_tvt_routes` ligando à definiçª£o correta
3. Migrar coordenadas de `waze_tvt_route_history_coords` (se houver) para `waze_tvt_route_execution_coord`

### 3. Ajustar o cÆ³digo de coleta

No seu `WazeCollectTvtCommand` (ou similar):

- Ao receber uma rota da API:
  - Primeiro, busque ou crie `WazeTvtRouteDefinition` pelo `route_id` (salvando `bbox`/`line` apenas uma vez)
  - Depois, crie `WazeTvtRouteExecution` para cada execuçª£o (timestamp, duration, etc.)
  - Se quiser guardar coords detalhadas, crie `WazeTvtRouteExecutionCoord` em vez de salvar tudo em um campo `coords` gigante

Exemplo (pseudo-cÆ³digo):

```php
$def = $definitionRepo->findOneByRouteId($routeId);
if (!$def) {
    $def = new WazeTvtRouteDefinition();
    $def->setRouteId($routeId);
    $def->setName($name);
    $def->setBbox($bbox);
    $def->setLine($line);
    $em->persist($def);
}

$exec = new WazeTvtRouteExecution();
$exec->setRouteDefinition($def);
$exec->setTimestamp(new \DateTimeImmutable());
$exec->setDuration($duration);
// ...
$em->persist($exec);
```

### 4. Validar e (opcional) limpar tabelas antigas

Depois de validar que os dados estŒo corretos nas novas tabelas:

- VocÍª pode:
  - Renomear `waze_tvt_routes` para `waze_tvt_routes_old` (backup)
  - Ou truncar `waze_tvt_routes` e reutilizar o nome no futuro
  - Manter `waze_tvt_route_history_coords` como estÆ¡ ou removÍª-la se jÆ¡ migrou tudo

## Notas

- O command `migrate:tvt-routes` nŒo remove dados das tabelas antigas, apenas lÍª e copia para a nova estrutura.
- As novas entidades jÆ¡ estŒo em `src/Entity/` e os repositÆ³rios em `src/Repository/`.
- Se usar Doctrine Migrations, gere uma migration automÆ¡tica apÆ³s adicionar as entidades:

```bash
php bin/console make:migration
php bin/console doctrine:migrations:migrate
```
